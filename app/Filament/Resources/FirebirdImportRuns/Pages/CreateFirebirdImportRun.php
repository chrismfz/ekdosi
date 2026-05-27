<?php

namespace App\Filament\Resources\FirebirdImportRuns\Pages;

use App\Filament\Resources\FirebirdImportRuns\FirebirdImportRunResource;
use App\Jobs\RunFirebirdImport;
use App\Models\FirebirdImportRun;
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

        if ($priorRun !== null) {
            Notification::make()
                ->warning()
                ->title('Identical backup already imported')
                ->body(sprintf(
                    'A backup with the same SHA256 was successfully imported on %s. Re-running will refresh legacy columns and add any rows new in the source, but is otherwise a no-op (safe by design).',
                    $priorRun->finished_at?->format('Y-m-d H:i') ?? 'an earlier run',
                ))
                ->persistent()
                ->send();
        }

        RunFirebirdImport::dispatch($run->id, $data['fb_password']);

        Notification::make()
            ->success()
            ->title('Import queued')
            ->body('The import job has been dispatched. This page will refresh automatically as the status changes.')
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
