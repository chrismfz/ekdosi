<?php

namespace App\Services\Support\Inbound;

/**
 * A transport-agnostic view of ONE attachment on an inbound email (Πυλώνας E,
 * Phase 4 follow-up · PR B). {@see WebklexImapMailbox} builds these from a webklex
 * message (the untestable network side); {@see TicketAttachments::storeInbound}
 * validates + persists them. Kept free of any IMAP type so the storage policy is
 * unit-testable with plain fixtures.
 *
 * `content` is the RAW decoded bytes. `mimeType` is webklex's content-sniffed type
 * (finfo on the bytes, NOT the attacker-controlled Content-Type header) — stored as
 * metadata only; the extension allowlist is the real gate (see storeInbound).
 */
final class InboundEmailAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly ?string $mimeType,
        public readonly string $content,
    ) {}
}
