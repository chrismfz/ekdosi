<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Taric\CnCatalog;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * «Κωδικοί ΣΟ / TARIC» → «Ενημέρωση από ΕΕ»: downloads the year's Combined Nomenclature
 * from data.europa.eu (≈170 MB) and replaces that year's `cn_codes` — far too heavy for a
 * web request, hence the queue. The requesting user gets a bell notification either way.
 */
class ImportCnCatalog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    /**
     * With retryUntil() in the future a timed-out run would otherwise NOT be marked failed
     * (and its re-reservation is dropped by WithoutOverlapping) → the operator would never
     * hear back. Fail on timeout so failed() rings the bell.
     */
    public bool $failOnTimeout = true;

    /**
     * ONE real attempt: with retryUntil() set, this — not $tries — fails the job on
     * the first thrown exception (same as RunFirebirdImport, OPS-11).
     */
    public int $maxExceptions = 1;

    /**
     * OPS-11 pattern: the DB queue's retry_after (90s) is far below this job's runtime
     * (a ≈170 MB download + parse), so a second worker re-reserves it mid-run. A plain
     * $tries=1 would then fail the duplicate on max-attempts → a FALSE «απέτυχε» bell while
     * the first run is still importing. A future retryUntil lets the duplicate through to
     * the WithoutOverlapping gate below, which drops it cleanly.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addSeconds($this->timeout + 300);
    }

    /** One import per year at a time; a re-reserved duplicate is DROPPED, not re-released. */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('cn-import:'.$this->year))
                ->dontRelease()
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function __construct(public int $year, public ?int $userId = null) {}

    public function handle(CnCatalog $catalog): void
    {
        $result = $catalog->importFromEu($this->year);

        $this->notify(
            Notification::make()
                ->title("Κωδικοί ΣΟ {$result['year']}: ενημερώθηκαν")
                ->body("{$result['count']} κωδικοί από την επίσημη διανομή της ΕΕ. Δες στη σελίδα «Κωδικοί ΣΟ / TARIC» αν κάποιο είδος έχει καταργημένο κωδικό.")
                ->success()
        );
    }

    public function failed(Throwable $e): void
    {
        $this->notify(
            Notification::make()
                ->title("Κωδικοί ΣΟ {$this->year}: η ενημέρωση απέτυχε")
                ->body(mb_substr($e->getMessage(), 0, 300))
                ->danger()
        );
    }

    private function notify(Notification $n): void
    {
        if ($this->userId && ($user = User::query()->find($this->userId))) {
            $n->sendToDatabase($user);
        }
    }
}
