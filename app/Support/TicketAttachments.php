<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Support\Inbound\InboundEmailAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /**
     * Cap for the attachment set of a SINGLE email (PR B), both directions: inbound,
     * how many bytes we'll ingest+store from one message; outbound, above which we
     * send the reply WITHOUT attachments rather than generate an undeliverable giant.
     */
    public const MAX_EMAIL_TOTAL_KB = 25600; // 25 MB total per email

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
                'mime_type' => self::safeMime($file->getClientMimeType()),
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
     * SECURITY (defence in depth): the FileUpload is the primary guard
     * (`preventFilePathTampering()` ties the accepted paths to files uploaded in
     * THIS session, and `acceptedFileTypes()` enforces the mime allowlist). This
     * choke-point re-checks INDEPENDENTLY — the path must live under our own
     * {@see DIRECTORY} AND carry an allowlisted extension — so a tampered Livewire
     * submit can never make us record (and later, on delete, destroy) an arbitrary
     * file on the shared private disk (another feature's backup/scan, another
     * tenant's ticket file).
     *
     * @param  list<string>  $paths
     * @param  array<string, string>  $names  path => original filename
     * @return list<Attachment>
     */
    public static function fromStoredPaths(TicketMessage $message, array $paths, array $names, ?int $uploaderId = null): array
    {
        $out = [];
        foreach (array_slice($paths, 0, self::MAX_COUNT) as $path) {
            if (! is_string($path) || ! self::isOwnedPath($path) || ! self::hasAllowedExtension($path)) {
                continue;
            }
            if (! Storage::disk(self::DISK)->exists($path)) {
                continue;
            }
            $out[] = $message->attachments()->create([
                'company_id' => $message->company_id,
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => self::safeName($names[$path] ?? basename($path)),
                'mime_type' => self::safeMime(Storage::disk(self::DISK)->mimeType($path) ?: null),
                'size' => Storage::disk(self::DISK)->size($path),
                'uploaded_by_user_id' => $uploaderId,
            ]);
        }

        return $out;
    }

    /**
     * Store the attachments of an INBOUND email onto its ticket message (PR B). The
     * sender is fully untrusted (anyone who can email the department), so every guard
     * is independent of what the email declared:
     *   - EXTENSION allowlist on the (sanitised) filename — the real gate. We do NOT
     *     trust the Content-Type header, and we do NOT reject on the content-sniffed
     *     mime either (OOXML docx/xlsx sniff as application/zip, which would drop
     *     legitimate office files); the download-only disposition (never inline) is
     *     what neutralises a mislabelled file, so the extension gate is sufficient.
     *   - per-file size cap ({@see MAX_SIZE_KB}), count cap ({@see MAX_COUNT}), and a
     *     per-email byte budget ({@see MAX_EMAIL_TOTAL_KB}) so one message can't fill
     *     the disk. We never decompress, so a zip-bomb just sits inert within the cap.
     * Stored on the private disk with a RANDOM name (+ the allowlisted extension);
     * `uploaded_by_user_id` is null (it came from the customer/sender, not an operator).
     *
     * @param  list<InboundEmailAttachment>  $attachments
     * @return list<Attachment>
     */
    public static function storeInbound(TicketMessage $message, array $attachments): array
    {
        $out = [];
        $totalBytes = 0;
        $budget = self::MAX_EMAIL_TOTAL_KB * 1024;

        foreach ($attachments as $attachment) {
            if (count($out) >= self::MAX_COUNT || ! $attachment instanceof InboundEmailAttachment) {
                continue;
            }
            $name = self::safeName($attachment->filename);
            $size = strlen($attachment->content);
            // Per-item gate (allowlist + per-file cap) shared with the IMAP pre-filter,
            // plus the stateful per-email budget.
            if (! self::inboundItemAllowed($name, $size) || $totalBytes + $size > $budget) {
                continue;
            }

            $path = self::DIRECTORY.'/'.Str::random(40).'.'.strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! Storage::disk(self::DISK)->put($path, $attachment->content)) {
                continue;
            }
            try {
                $row = $message->attachments()->create([
                    'company_id' => $message->company_id,
                    'disk' => self::DISK,
                    'path' => $path,
                    'original_name' => $name,
                    // Normalise the stored mime to the allowlist (a crafted part can sniff
                    // as text/html / image/svg+xml) so a future inline-serving surface can
                    // never be tricked into rendering it. Prefer the disk sniff over the
                    // sender's declared type; both pass through safeMime.
                    'mime_type' => self::safeMime(Storage::disk(self::DISK)->mimeType($path) ?: $attachment->mimeType),
                    'size' => $size,
                    'uploaded_by_user_id' => null,
                ]);
            } catch (\Throwable $e) {
                // Don't orphan the bytes if the row insert fails (deadlock, etc.).
                Storage::disk(self::DISK)->delete($path);

                continue;
            }
            $totalBytes += $size;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Build the mail-attachment descriptor list for an outbound reply (PR B) from a
     * message's stored attachments. All-or-nothing on the per-email budget: if the
     * set is too large for one email, returns [] (the caller sends the reply text
     * WITHOUT files rather than a giant that bounces and delivers nothing). Reads
     * only our own already-validated rows, so no re-check of type is needed.
     *
     * @param  iterable<Attachment>  $attachments
     * @return list<array{disk:string, path:string, name:string, mime:?string}>
     */
    public static function outboundPayload(iterable $attachments): array
    {
        $files = [];
        $totalBytes = 0;

        foreach ($attachments as $attachment) {
            $disk = $attachment->disk ?: self::DISK;
            $path = (string) $attachment->path;
            // Skip a row whose bytes are gone — attaching a missing path would make the
            // mailer throw and the whole reply (text included) would never be delivered.
            if (! Storage::disk($disk)->exists($path)) {
                continue;
            }
            $totalBytes += (int) $attachment->size;
            $files[] = [
                'disk' => $disk,
                'path' => $path,
                'name' => self::safeName($attachment->original_name),
                'mime' => $attachment->mime_type ?: null,
            ];
        }

        return $totalBytes > self::MAX_EMAIL_TOTAL_KB * 1024 ? [] : $files;
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

    /**
     * Is this path one WE would have written — i.e. directly under {@see DIRECTORY}
     * on the private disk (no traversal, no nesting into other features' folders)?
     * Guards the operator record path against a tampered submit pointing elsewhere.
     */
    private static function isOwnedPath(string $path): bool
    {
        $path = ltrim($path, '/');
        if (str_contains($path, '..')) {
            return false; // no traversal
        }
        $prefix = self::DIRECTORY.'/';

        // Exactly one segment under the directory: "ticket-attachments/<file>".
        return str_starts_with($path, $prefix)
            && ! str_contains(substr($path, strlen($prefix)), '/');
    }

    /** Does the path carry an allowlisted extension (case-insensitive)? */
    private static function hasAllowedExtension(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * Public allowlist check for an inbound (untrusted) filename, run on the SANITISED
     * name so it agrees with what {@see storeInbound} would store. Lets the IMAP layer
     * drop a bad-type part BEFORE copying its bytes into memory.
     */
    public static function isAllowedFilename(?string $filename): bool
    {
        return self::hasAllowedExtension(self::safeName($filename));
    }

    /**
     * The single «would we store this inbound item?» per-item predicate (allowlisted
     * type + non-empty + within the per-file cap), shared by {@see storeInbound} and
     * the IMAP pre-filter so the memory-bounding pre-filter can never drift from the
     * authoritative store. The stateful per-email budget/count stay in each loop.
     */
    public static function inboundItemAllowed(string $safeName, int $size): bool
    {
        return $size > 0 && $size <= self::MAX_SIZE_KB * 1024 && self::hasAllowedExtension($safeName);
    }

    /**
     * Normalise a stored mime to the allowlist. A crafted attachment can sniff as
     * text/html or image/svg+xml; anything not on the allowlist is stored as the inert
     * application/octet-stream, so no download surface can be tricked into rendering
     * active content from the stored type. Metadata only — never the security gate.
     */
    public static function safeMime(?string $mime): string
    {
        $mime = mb_strtolower(trim((string) explode(';', (string) $mime)[0]));

        return in_array($mime, self::MIME_TYPES, true) ? $mime : 'application/octet-stream';
    }

    /**
     * A display/download filename with any path separators + control chars stripped,
     * capped at 200 chars — but the EXTENSION is preserved when truncating a very long
     * name, so a legitimate «<200 chars>.pdf» never loses its «.pdf» (which would make
     * the allowlist reject it).
     */
    public static function safeName(?string $name): string
    {
        $name = basename(trim((string) $name)); // drop any directory components
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name); // control chars

        if ($name === '') {
            return 'αρχείο';
        }
        if (mb_strlen($name) <= 200) {
            return $name;
        }

        // Keep the extension on the tail; truncate the stem to fit within 200 chars.
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if ($ext === '' || mb_strlen($ext) > 20) {
            return mb_substr($name, 0, 200); // no (sane) extension → plain truncate
        }
        $stem = mb_substr($name, 0, mb_strlen($name) - mb_strlen($ext) - 1);

        return mb_substr($stem, 0, 200 - mb_strlen($ext) - 1).'.'.$ext;
    }
}
