<?php

namespace App\Console\Commands\Concerns;

use App\Models\DeliveryNote;
use App\Services\Delivery\DeliveryNoteRejected;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Shared report buffer for the delivery sandbox commands: every line is echoed
 * to the console AND appended to an in-memory log that gets written to a .txt
 * the operator can hand back for review.
 */
trait WritesDeliveryReport
{
    /** @var list<string> */
    private array $reportLog = [];

    private function logLine(string $line = ''): void
    {
        $this->reportLog[] = $line;
        $this->line($line);
    }

    private function section(string $title): void
    {
        $this->reportLog[] = '';
        $this->reportLog[] = '── '.$title.' '.str_repeat('─', max(0, 60 - mb_strlen($title)));
        $this->newLine();
        $this->info($title);
    }

    private function kv(string $key, string $value): void
    {
        $this->logLine(str_pad($key.':', 16).$value);
    }

    /** Run a lifecycle/submit step, capturing OK/FAIL + the returned mark. */
    private function step(string $name, DeliveryNote $note, callable $fn): bool
    {
        $this->section($name);
        try {
            $result = $fn();
            $mark = is_object($result) && method_exists($result, 'getAttribute') ? ($result->mark ?? null) : null;
            $this->kv('ΑΠΟΤΕΛΕΣΜΑ', 'OK ✓');
            if ($mark) {
                $this->kv('MARK', (string) $mark);
            }
            $this->kv('delivery_state', (string) ($note->fresh()->delivery_state ?? '—'));

            return true;
        } catch (Throwable $e) {
            $this->kv('ΑΠΟΤΕΛΕΣΜΑ', 'FAIL ✗');
            $this->kv('Σφάλμα', $e->getMessage());
            if ($prev = $e->getPrevious()) {
                $this->kv('Αιτία', get_class($prev).' — '.$prev->getMessage());
            }
            // A rejection happens BEFORE any delivery_marks row is persisted, so
            // appendMarkXml() would find nothing. Capture the request/response
            // XML straight off the exception chain instead.
            $this->appendFailureXml($e);

            return false;
        }
    }

    /** Walk the exception chain for a DeliveryNoteRejected and log its XML. */
    private function appendFailureXml(Throwable $e): void
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if (! $cur instanceof DeliveryNoteRejected) {
                continue;
            }
            if ($cur->requestXml !== '') {
                $this->logLine('--- REQUEST (απορρίφθηκε) ---');
                $this->logLine($cur->requestXml);
            }
            if ($cur->responseXml !== '') {
                $this->logLine('--- RESPONSE (AADE) ---');
                $this->logLine($cur->responseXml);
            }

            return;
        }
    }

    /** Append every delivery_marks row's request/response XML for the audit trail. */
    private function appendMarkXml(DeliveryNote $note): void
    {
        $this->section('AUDIT — delivery_marks (request/response XML)');
        foreach ($note->marks()->orderBy('id')->get() as $mark) {
            $this->logLine();
            $this->logLine("[{$mark->mydata_action}] MARK={$mark->mark} url={$mark->invoice_url}");
            $this->logLine('--- REQUEST ---');
            $this->logLine((string) $mark->request);
            $this->logLine('--- RESPONSE ---');
            $this->logLine((string) $mark->response);
        }
    }

    /** Write the buffered report to a file; returns the absolute path. */
    private function writeReport(?string $path): string
    {
        $path ??= 'delivery-sandbox-'.now()->format('Ymd-His').'.txt';
        Storage::disk('local')->put($path, implode("\n", $this->reportLog)."\n");

        return Storage::disk('local')->path($path);
    }
}
