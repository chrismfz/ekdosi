<?php

namespace App\Filament\Resources\FirebirdImportRuns\Pages;

use App\Filament\Resources\FirebirdImportRuns\FirebirdImportRunResource;
use App\Jobs\RunFirebirdImport;
use App\Models\Company;
use App\Models\FirebirdImportRun;
use App\Services\Etl\EpsilonImporter;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
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
        $epsilonFiles = array_filter([
            'customers' => $data['customers_json'] ?? null,
            'items' => $data['items_json'] ?? null,
            'services' => $data['services_json'] ?? null,
            'sales' => $data['sales_json'] ?? null,
        ], static fn ($path): bool => filled($path));

        if ($epsilonFiles !== []) {
            return $this->handleEpsilon($epsilonFiles, $tenant);
        }

        // Live-connection tab: connect straight to a remote Firebird (no file).
        if (filled($data['fb_live_database'] ?? null)) {
            return $this->handleLive($data, $tenant);
        }

        $uploadedPath = $data['upload'] ?? null;

        // Defensive: with no Epsilon file AND no Firebird file, the upload never
        // landed — FileUpload silently drops a temporary file that vanished
        // before `saveUploadedFiles()` ran (temp-dir pruning, storage perms, or
        // a request that blew past php.ini's upload/post limits), leaving the
        // field empty. Without this guard execution falls through to
        // `Storage::disk('local')->path(null)` and the operator gets an opaque
        // flysystem 500 («null given»). Surface an actionable message instead.
        if (blank($uploadedPath)) {
            Notification::make()
                ->danger()
                ->title('Δεν ελήφθη κανένα αρχείο')
                ->body('Η μεταφόρτωση δεν ολοκληρώθηκε — το αρχείο δεν αποθηκεύτηκε στον διακομιστή. Δοκίμασε ξανά· αν επιμένει, έλεγξε τα όρια PHP (upload_max_filesize/post_max_size) και τα δικαιώματα εγγραφής στο storage.')
                ->persistent()
                ->send();

            throw new Halt;
        }

        $absolutePath = Storage::disk('local')->path($uploadedPath);

        if (! is_file($absolutePath)) {
            throw new \RuntimeException("Upload did not land on disk: {$uploadedPath}");
        }

        $fileSize = filesize($absolutePath);
        $fileSha256 = hash_file('sha256', $absolutePath);
        $fileName = $data['original_file_name'] ?? basename($uploadedPath);

        $run = FirebirdImportRun::create([
            'company_id' => $tenant->id,
            'source' => FirebirdImportRun::SOURCE_FIREBIRD,
            'uploaded_by_user_id' => auth()->id(),
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'file_sha256' => $fileSha256,
            'uploaded_path' => $uploadedPath,
            'status' => FirebirdImportRun::STATUS_UPLOADED,
            'fb_host' => $data['fb_host'],
            'fb_user' => $data['fb_user'],
            // Which CUST_ID keeps the ΑΦΜ when two legacy customers share one.
            'afm_keep' => $data['afm_keep'] ?? null,
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
     * Live-connection Firebird import: no upload/gbak. Records a run row keyed to
     * the remote (host[/port] + .fdb path; NEVER the password) and dispatches the
     * job, which detects live mode (uploaded_path null + fb_database set) and
     * drains straight from the remote via migrate:firebird.
     *
     * @param  array<string, mixed>  $data
     */
    private function handleLive(array $data, Company $tenant): Model
    {
        $host = trim((string) ($data['fb_live_host'] ?? ''));
        $port = (int) ($data['fb_live_port'] ?? 3050);
        // Fold a non-default port into the host (Firebird DSN: HOST/PORT:path).
        $fbHost = ($port > 0 && $port !== 3050) ? "{$host}/{$port}" : $host;

        $run = FirebirdImportRun::create([
            'company_id' => $tenant->id,
            'source' => FirebirdImportRun::SOURCE_FIREBIRD,
            'uploaded_by_user_id' => auth()->id(),
            'file_name' => 'Live: '.$host.':'.$data['fb_live_database'],
            // No file for a live run — 0 bytes; sha keyed to the remote target
            // (stable so an identical re-run still surfaces the dedup hint).
            'file_size' => 0,
            'file_sha256' => hash('sha256', 'live:'.$fbHost.':'.$data['fb_live_database']),
            'status' => FirebirdImportRun::STATUS_UPLOADED,
            'fb_host' => $fbHost,
            'fb_user' => trim((string) ($data['fb_live_user'] ?? '')) ?: 'EKDOSI',
            'fb_database' => (string) $data['fb_live_database'],
            // The field lives on the Firebird tab but applies to a live run too.
            'afm_keep' => $data['afm_keep'] ?? null,
        ]);

        RunFirebirdImport::dispatch($run->id, (string) ($data['fb_live_password'] ?? ''));

        Notification::make()
            ->success()
            ->title('Import queued (ζωντανή σύνδεση)')
            ->body('Σύνδεση στο '.$host.' — η σελίδα ανανεώνεται με την κατάσταση.')
            ->send();

        return $run;
    }

    /**
     * Epsilon Smart JSON import — synchronous (the exports are tiny). Reads each
     * staged JSON file, runs EpsilonImporter (re-runnable upsert), records a
     * completed run with per-entity counts, and tidies the uploads.
     *
     * @param  array<string, string>  $files  entity => stored disk path (already filtered to non-empty)
     */
    private function handleEpsilon(array $files, Company $tenant): Model
    {
        $baseRow = [
            'company_id' => $tenant->id,
            'source' => FirebirdImportRun::SOURCE_EPSILON,
            'uploaded_by_user_id' => auth()->id(),
            'file_name' => 'Epsilon: '.implode(', ', array_keys($files)),
            'file_sha256' => hash('sha256', implode('|', array_values($files))),
            'source_files_json' => $files,
            'started_at' => now(),
        ];

        $importer = new EpsilonImporter($tenant);
        $totalBytes = 0;

        // Any failure (bad JSON, a throw mid-import) records a FAILED run for the
        // audit trail and re-throws so the operator sees the error. Re-running is
        // idempotent, so retrying after a fix is safe.
        try {
            $payload = [];
            foreach ($files as $key => $path) {
                if (! Storage::disk('local')->exists($path)) {
                    throw new \RuntimeException("Το αρχείο «{$key}» δεν βρέθηκε στον χώρο αποθήκευσης — η μεταφόρτωση πιθανώς δεν ολοκληρώθηκε. Δοκίμασε ξανά.");
                }
                $raw = (string) Storage::disk('local')->get($path);
                $totalBytes += strlen($raw);
                $decoded = json_decode($raw, true);
                if (! is_array($decoded)) {
                    throw new \RuntimeException("Το αρχείο «{$key}» δεν είναι έγκυρο JSON.");
                }
                $payload[$key] = $decoded;
            }
            $counts = $importer->import($payload);
        } catch (\Throwable $e) {
            FirebirdImportRun::create($baseRow + [
                'file_size' => $totalBytes,
                'status' => FirebirdImportRun::STATUS_FAILED,
                'failed_step' => 'epsilon',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        $warnings = $importer->warnings();

        $run = FirebirdImportRun::create($baseRow + [
            'file_size' => $totalBytes,
            'status' => FirebirdImportRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'counts_json' => $counts,
            'error_message' => $warnings !== [] ? implode("\n", $warnings) : null,
        ]);

        // The JSON uploads are transient — drop them after a successful import.
        foreach ($files as $path) {
            Storage::disk('local')->delete($path);
        }

        $summary = collect($counts)
            ->map(fn (array $c, string $k): string => "{$k}: +{$c['created']} νέα · ~{$c['updated']} ενημ. · {$c['skipped']} παράλειψη")
            ->implode(' — ');
        if ($warnings !== []) {
            $summary .= ' · ⚠ '.count($warnings).' προειδοποιήσεις (δες το run).';
        }

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
