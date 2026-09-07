<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attachment policy + storage for support tickets (Πυλώνας E, Phase 4 follow-up).
 *
 * SECURITY: an attachment is untrusted customer/operator input. It is only ever
 * DOWNLOADED (Content-Disposition: attachment), never executed or rendered inline,
 * lives on the PRIVATE disk, and its type is checked against an allowlist — no
 * executables/scripts/HTML/SVG (which could carry active content). The stored path
 * is a random name; `original_name` is metadata only, always escaped on render.
 */
class TicketAttachments
{
    public const DISK = 'local';

    public const DIRECTORY = 'ticket-attachments';

    public const MAX_SIZE_KB = 20480; // 20 MB per file

    public const MAX_COUNT = 5; // per message

    /** Allowed extensions — documents/images/archives only, never active content. */
    public const EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp',
        'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip',
    ];

    /** Mime allowlist for the Filament FileUpload accept filter. */
    public const MIME_TYPES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv', 'text/plain', 'application/zip',
    ];

    /**
     * Laravel validation rules for one uploaded file (portal path).
     *
     * @return list<string>
     */
    public static function fileRules(): array
    {
        return ['file', 'mimes:'.implode(',', self::EXTENSIONS), 'max:'.self::MAX_SIZE_KB];
    }

    /**
     * Store uploaded files as attachments on a ticket message (portal + future
     * inbound). Assumes the caller already validated types/size; stores each on the
     * private disk with a random name and records metadata. Returns the rows made.
     *
     * @param  array<int, UploadedFile>  $files
     * @return list<Attachment>
     */
    public static function storeUploaded(TicketMessage $message, array $files, ?int $uploaderId = null): array
    {
        $out = [];
        foreach (array_slice($files, 0, self::MAX_COUNT) as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $path = $file->store(self::DIRECTORY, self::DISK); // random name on the private disk
            if ($path === false) {
                continue;
            }
            $out[] = $message->attachments()->create([
                'company_id' => $message->company_id,
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => self::safeName($file->getClientOriginalName()),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by_user_id' => $uploaderId,
            ]);
        }

        return $out;
    }

    /**
     * Record attachments from files ALREADY stored on the private disk by a Filament
     * FileUpload (operator path): the component wrote the bytes and gave us paths +
     * a path→original-name map.
     *
     * @param  list<string>  $paths
     * @param  array<string, string>  $names  path => original filename
     * @return list<Attachment>
     */
    public static function fromStoredPaths(TicketMessage $message, array $paths, array $names, ?int $uploaderId = null): array
    {
        $out = [];
        foreach (array_slice($paths, 0, self::MAX_COUNT) as $path) {
            if (! is_string($path) || ! Storage::disk(self::DISK)->exists($path)) {
                continue;
            }
            $out[] = $message->attachments()->create([
                'company_id' => $message->company_id,
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => self::safeName($names[$path] ?? basename($path)),
                'mime_type' => Storage::disk(self::DISK)->mimeType($path) ?: null,
                'size' => Storage::disk(self::DISK)->size($path),
                'uploaded_by_user_id' => $uploaderId,
            ]);
        }

        return $out;
    }

    /**
     * The attachment with this id that genuinely belongs to a message of THIS ticket
     * (and its company), or null. The caller has already authorised access to the
     * ticket; this stops the download route from serving another record's file, or
     * an attachment from a different ticket/tenant.
     */
    public static function forTicket(Ticket $ticket, int $attachmentId, bool $publicOnly = false): ?Attachment
    {
        // The customer (portal) may only reach attachments on PUBLIC messages — an
        // internal-note attachment must never be downloadable through the portal.
        $messages = $publicOnly ? $ticket->publicMessages() : $ticket->messages();

        return Attachment::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereKey($attachmentId)
            ->where('company_id', $ticket->company_id)
            ->where('attachable_type', (new TicketMessage)->getMorphClass())
            ->whereIn('attachable_id', $messages->select('id'))
            ->first();
    }

    /** Stream an attachment as a forced download (never inline). 404 if the bytes are gone. */
    public static function download(Attachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, self::safeName($attachment->original_name));
    }

    /** A display/download filename with any path separators + control chars stripped. */
    public static function safeName(?string $name): string
    {
        $name = basename(trim((string) $name)); // drop any directory components
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name); // control chars

        return $name !== '' ? mb_substr($name, 0, 200) : 'αρχείο';
    }
}
