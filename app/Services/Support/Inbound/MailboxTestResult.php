<?php

namespace App\Services\Support\Inbound;

/**
 * The outcome of a read-only IMAP connect+login test for a department's mailbox
 * (Πυλώνας E, Phase 3b). Surfaced by the «Test σύνδεσης» panel action and the MCP
 * `support_imap` tool, so «do these credentials work?» is answerable without a
 * deploy. Never carries the password.
 */
final class MailboxTestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?int $messageCount = null,
    ) {}

    public static function ok(int $messageCount, string $folder = 'INBOX'): self
    {
        return new self(true, "Σύνδεση OK — {$messageCount} μηνύματα στον φάκελο «{$folder}».", $messageCount);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }

    /** @return array{ok:bool, message:string, message_count:?int} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'message' => $this->message, 'message_count' => $this->messageCount];
    }
}
