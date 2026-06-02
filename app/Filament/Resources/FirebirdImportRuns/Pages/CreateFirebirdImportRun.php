<?php

namespace App\Filament\Resources\FirebirdImportRuns\Pages;

use App\Filament\Resources\FirebirdImportRuns\FirebirdImportRunResource;
use App\Jobs\RunFirebirdImport;
use App\Models\Company;
use App\Models\FirebirdImportRun;
use App\Services\Etl\EpsilonImporter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * PR #30 — Upload + dispatch flow.
 *
 * Filament's default CreateRecord persists the form payload directly
 * onto a model row, then redirects. We override the mutation pipeline
 * to:
 *   1. Move the uploaded file to a per-run subfolder so concurrent
 *      uploads can't collide on filename.
 *   2. Compute its SHA256 (cheap; the file is already on disk).
 *   3. Persist the run row with snapshot of the connection params
 *      (host/user — NOT password).
 *   4. Dispatch `RunFirebirdImport` with the in-memory password.
 *   5. Redirect to the View page where the operator watches status.
 *
 * The `fb_password` field is intentionally NOT in the model's
 * fillable — it leaves the form via $data, never touches the DB.
 */
class CreateFirebirdImportRun extends CreateRecord
{
    protected static string $resource = FirebirdImportRunResource::class;

    public function form(Schema $schema): Schema
    {
        return FirebirdImportRunResource::form($schema);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            throw new \RuntimeException('No tenant in context — cannot create import run.');
        }

        // Epsilon Smart tab: any JSON file staged → run the (fast) JSON import
        // synchronously and record a completed run. No queue worker / gbak
        // needed; the files are tiny so the request handles it inline.
        if (! empty($data['customers_json']) || ! empty($data['items_json']) || ! empty($data['services_json'])) {
            return $this->handleEpsilon($data, $tenant);
        }

        $uploadedPath = $data['upload'];
        $absolutePath = Storage::disk('local')->path($uploadedPath);

        if (! is_file($absolutePath)) {
            throw new \RuntimeException("Upload did not land on disk: {$uploadedPath}");
        }

        $fileSize   = filesize($absolutePath);
        $fileSha256 = hash_file('sha256', $absolutePath);
        $fileName   = $data['original_file_name'] ?? basename($uploadedPath);

        $run = FirebirdImportRun::create([
            'company_id'          => $tenant->id,
            'source'              => FirebirdImportRun::SOURCE_FIREBIRD,
            'uploaded_by_user_id' => auth()->id(),
            'file_name'           => $fileName,
            'file_size'           => $fileSize,
            'file_sha256'         => $fileSha256,
            'uploaded_path'       => $uploadedPath,
            'status'              => FirebirdImportRun::STATUS_UPLOADED,
            'fb_host'             => $data['fb_host'],
            'fb_user'             => $data['fb_user'],
        ]);

        // Surface the SHA dedup hint as a notification (non-blocking)
        // BEFORE the job starts. Operator can decide to cancel — but
        // re-running the same backup is safe by design (PR #29's
        // upsert semantics) so the hint is purely informational.
        $priorRun = FirebirdImportRun::query()
            ->where('company_id', $tenant->id)
            ->where('file_sha256', $fileSha256)
            ->where('status', FirebirdImportRun::STATUS_COMPLETED)
            ->where('id', '!=', $run->id)
            ->latest('finished_at')
            ->first();

        // Single combined notification. Earlier shape had TWO
        // notifications (dedup warning persistent + success toast);
        // they stacked, and the auto-dismissing success was hidden
        // by the persistent warning. One notification with both
        // pieces of context — and a warning level when dedup hit
        // (so the operator sees the colour difference) — reads
        // cleaner.
        //
        // NB on body formatting: Filament 5's notification body is
        // run through HtmlSanitizer, which permits `<br>` but
        // strips raw `\n` (browsers collapse to a space). The
        // double-review caught a "\n\n" → wall-of-text regression
        // here; using <br><br> instead gets a real paragraph break.
        $body = 'The import job has been dispatched. This page will refresh automatically as the status changes.';
        if ($priorRun !== null) {
            $body = sprintf(
                'Identical backup was already imported on %s — re-running will refresh legacy columns and add any rows new in the source, but is otherwise a no-op (safe by design).<br><br>%s',
                $priorRun->finished_at?->format('Y-m-d H:i') ?? 'an earlier date',
                $body,
            );
        }

        RunFirebirdImport::dispatch($run->id, $data['fb_password']);

        Notification::make()
            ->{$priorRun !== null ? 'warning' : 'success'}()
            ->title($priorRun !== null ? 'Import queued (duplicate backup)' : 'Import queued')
            ->body($body)
            ->send();

        return $run;
    }

    /**
     * Epsilon Smart JSON import — synchronous (the exports are tiny). Reads each
     * staged JSON file, runs EpsilonImporter (re-runnable upsert), records a
     * completed run with per-entity counts, and tidies the uploads.
     */
    private function handleEpsilon(array $data, Company $tenant): Model
    {
        $files = array_filter([
            'customers' => $data['customers_json'] ?? null,
            'items' => $data['items_json'] ?? null,
            'services' => $data['services_json'] ?? null,
        ]);

        $payload = [];
        $totalBytes = 0;
        foreach ($files as $key => $path) {
            $raw = (string) Storage::disk('local')->get($path);
            $totalBytes += strlen($raw);
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                throw new \RuntimeException("Το αρχείο «{$key}» δεν είναι έγκυρο JSON.");
            }
            $payload[$key] = $decoded;
        }

        $counts = (new EpsilonImporter($tenant))->import($payload);

        $run = FirebirdImportRun::create([
            'company_id'          => $tenant->id,
            'source'              => FirebirdImportRun::SOURCE_EPSILON,
            'uploaded_by_user_id' => auth()->id(),
            'file_name'           => 'Epsilon: '.implode(', ', array_keys($files)),
            'file_size'           => $totalBytes,
            'file_sha256'         => hash('sha256', implode('|', array_values($files))),
            'source_files_json'   => $files,
            'status'              => FirebirdImportRun::STATUS_COMPLETED,
            'started_at'          => now(),
            'finished_at'         => now(),
            'counts_json'         => $counts,
        ]);

        // The JSON uploads are transient — drop them after a successful import.
        foreach ($files as $path) {
            Storage::disk('local')->delete($path);
        }

        $summary = collect($counts)
            ->map(fn (array $c, string $k): string => "{$k}: +{$c['created']} νέα · ~{$c['updated']} ενημ. · {$c['skipped']} παράλειψη")
            ->implode(' — ');

        Notification::make()
            ->success()
            ->title('Η εισαγωγή Epsilon ολοκληρώθηκε')
            ->body($summary !== '' ? $summary : 'Δεν δόθηκαν εγγραφές.')
            ->send();

        return $run;
    }

    protected function getRedirectUrl(): string
    {
        return FirebirdImportRunResource::getUrl('view', ['record' => $this->record]);
    }

    /**
     * No save-and-create-another for imports — operators submit one
     * file at a time, watch it run, then submit the next.
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Start import'),
            $this->getCancelFormAction(),
        ];
    }
}
