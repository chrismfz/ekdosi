<?php

namespace App\Services\Support\Inbound;

use App\Models\TicketDepartment;
use App\Support\HtmlToText;
use App\Support\TicketAttachments;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * The real IMAP transport (Πυλώνας E, Phase 3b) — the isolated, network-touching
 * side of {@see ImapMailbox}, built on webklex/php-imap (pure PHP, no ext-imap). It
 * has NO automated tests by design (needs a live server); the «Test σύνδεσης» panel
 * action and the MCP `support_imap` tool are how a real mailbox is verified. Both
 * methods swallow every error into the result — a bad mailbox never throws up the
 * stack and never aborts a multi-tenant sweep.
 */
class WebklexImapMailbox implements ImapMailbox
{
    /** Cap messages handled per poll so a huge backlog can't run unbounded. */
    private const MAX_PER_POLL = 50;

    /**
     * Hard cap for a whole RFC822 message we will DOWNLOAD + parse. Above this we do
     * NOT fetch the body at all — we open a header-only stub ticket instead (see
     * poll()), so a giant can neither OOM the poller nor be silently lost. Sized just
     * above our 25 MB decoded-attachment budget (base64 inflates ~+33% → ~34 MB on the
     * wire), so a message that could still yield storable attachments is always parsed.
     *
     * NOTE (deploy): parsing a message near this cap peaks at a few × its wire size
     * (raw + decoded parts held at once), so the poll process / queue worker wants
     * `memory_limit` ≥ 256M. The cap bounds the worst case to ONE such message at a
     * time (bodies are fetched one-by-one), never the whole 50-message batch.
     */
    private const MAX_MESSAGE_BYTES = 35 * 1024 * 1024; // 35 MB

    /** Is a whole-message RFC822 size over the download cap? (Testable in isolation.) */
    public static function isMessageTooLarge(int $bytes): bool
    {
        return $bytes > self::MAX_MESSAGE_BYTES;
    }

    public function test(TicketDepartment $department): MailboxTestResult
    {
        $folderName = $department->imap_folder ?: 'INBOX';
        try {
            $client = $this->client($department);
            $client->connect();
            $folder = $client->getFolder($folderName);
            if ($folder === null) {
                $client->disconnect();

                return MailboxTestResult::fail('Δεν βρέθηκε ο φάκελος «'.$folderName.'».');
            }
            $status = $folder->examine();
            $client->disconnect();

            return MailboxTestResult::ok((int) ($status['exists'] ?? 0), $folderName);
        } catch (Throwable $e) {
            return MailboxTestResult::fail('Αποτυχία σύνδεσης: '.$e->getMessage());
        }
    }

    public function poll(TicketDepartment $department, callable $handle): MailboxPollSummary
    {
        $summary = new MailboxPollSummary;

        try {
            $client = $this->client($department);
            $client->connect();
            $summary->connected = true;

            $folder = $client->getFolder($department->imap_folder ?: 'INBOX');
            if ($folder === null) {
                $summary->addError('Δεν βρέθηκε ο φάκελος.');
                $client->disconnect();

                return $summary;
            }

            // Fetch HEADERS ONLY (fetchBody(false)); each body is downloaded one at a
            // time in the loop, so a burst of large mails can't materialise every body
            // at once and OOM the poller. leaveUnread(): WE mark \Seen, never the fetch.
            $messages = $folder->query()->whereUnseen()->leaveUnread()->fetchBody(false)
                ->limit(self::MAX_PER_POLL)->get();
            $summary->fetched = $messages->count();

            foreach ($messages as $message) {
                try {
                    // Size guard BEFORE downloading the body (RFC822.SIZE is a cheap,
                    // header-level IMAP command). Over the cap → route a HEADER-ONLY
                    // stub (no body download, so no OOM) so the sender's request isn't
                    // silently lost; the operator sees it and follows up. Under the cap
                    // → download + parse this ONE message. Either way \Seen is set only
                    // after the handler confirms routing, so nothing wedges the mailbox.
                    if (self::isMessageTooLarge($this->messageSize($message))) {
                        $parsed = $this->parseHeadersOnly($message);
                    } else {
                        $message->parseBody(); // download + parse THIS message only (bounded memory)
                        $parsed = $this->parse($message);
                    }

                    if ($handle($parsed) === true) {
                        $message->setFlag('Seen');
                        $summary->processed++;
                    }
                } catch (Throwable $e) {
                    $summary->addError('Μήνυμα: '.$e->getMessage());
                }
            }

            $client->disconnect();
        } catch (Throwable $e) {
            $summary->addError('Σύνδεση: '.$e->getMessage());
        }

        return $summary;
    }

    private function client(TicketDepartment $department): Client
    {
        $encryption = $this->encryption($department->imap_encryption);
        $port = (int) $department->imap_port ?: 993;
        // A non-SSL mailbox left on the 993 default (the migration default) almost
        // certainly means the port wasn't set for STARTTLS/plain — use 143. An
        // explicit non-993 port is always honoured.
        if ($encryption !== 'ssl' && $port === 993) {
            $port = 143;
        }

        return (new ClientManager)->make([
            'host' => (string) $department->imap_host,
            'port' => $port,
            'encryption' => $encryption,
            'validate_cert' => true,
            'username' => (string) $department->imap_username,
            'password' => (string) $department->imap_password,
            'protocol' => 'imap',
            'authentication' => null,
        ]);
    }

    private function encryption(?string $encryption): string|bool
    {
        return match (mb_strtolower((string) $encryption)) {
            'ssl' => 'ssl',
            'tls', 'starttls' => 'tls',
            default => false, // «none»
        };
    }

    private function parse(Message $message): ParsedInboundEmail
    {
        $from = $message->getFrom()->first();
        $fromEmail = $from?->mail ?? '';
        $subject = trim((string) $message->getSubject());
        // Prefer the text/plain part; fall back to converting the HTML part to text
        // (many clients send HTML-only) rather than storing raw markup as the body.
        $text = trim((string) $message->getTextBody());
        $body = $text !== '' ? $text : HtmlToText::convert((string) $message->getHTMLBody());
        $mid = trim((string) $message->getMessageId());

        return new ParsedInboundEmail(
            fromEmail: $fromEmail,
            fromName: ($name = trim((string) ($from?->personal ?? ''))) !== '' ? $name : null,
            subject: $subject,
            body: $body,
            // Synthesise a stable id when the header is missing (some mailers omit it),
            // so the router's Message-ID idempotency still collapses a redelivery.
            messageId: $mid !== '' ? $mid : $this->syntheticId($fromEmail, $subject, (string) $message->getDate(), $body),
            references: array_merge($this->ids($message->getInReplyTo()), $this->ids($message->getReferences())),
            to: $this->addresses($message->getTo()),
            cc: $this->addresses($message->getCc()),
            attachments: $this->attachments($message),
        );
    }

    /** Placeholder body for an over-cap message we route WITHOUT downloading its body. */
    private const OVERSIZED_BODY = '⚠ Ο αποστολέας έστειλε ένα πολύ μεγάλο email που δεν λήφθηκε αυτόματα '
        .'(πάνω από το όριο μεγέθους). Επικοινωνήστε μαζί του για το περιεχόμενο ή τα συνημμένα.';

    /**
     * Build a ParsedInboundEmail from the HEADERS ONLY (no body download), for a
     * message over {@see MAX_MESSAGE_BYTES}. The sender/subject/threading survive so
     * it routes into a ticket like any other mail — the operator sees a stub with a
     * placeholder body and follows up — but nothing giant is ever loaded into memory
     * and the request is never silently lost.
     */
    private function parseHeadersOnly(Message $message): ParsedInboundEmail
    {
        $from = $message->getFrom()->first();
        $fromEmail = $from?->mail ?? '';
        $subject = trim((string) $message->getSubject());
        $mid = trim((string) $message->getMessageId());

        return new ParsedInboundEmail(
            fromEmail: $fromEmail,
            fromName: ($name = trim((string) ($from?->personal ?? ''))) !== '' ? $name : null,
            subject: $subject,
            body: self::OVERSIZED_BODY,
            messageId: $mid !== '' ? $mid : $this->syntheticId($fromEmail, $subject, (string) $message->getDate(), self::OVERSIZED_BODY),
            references: array_merge($this->ids($message->getInReplyTo()), $this->ids($message->getReferences())),
            to: $this->addresses($message->getTo()),
            cc: $this->addresses($message->getCc()),
            attachments: [],
        );
    }

    /**
     * The message's whole RFC822 size (RFC822.SIZE), or 0 if the probe fails — a
     * failed size probe must not become a poison point (leave-unread + re-fetch
     * forever), so we degrade to «treat as small» and let the bounded parse proceed.
     */
    private function messageSize(Message $message): int
    {
        try {
            return (int) $message->getSize();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * The email's REAL attachments as transport-agnostic DTOs (PR B). Skips INLINE
     * parts (embedded signature/logo images referenced by the HTML body via a
     * Content-ID) — only genuine file attachments become ticket attachments.
     *
     * The allowlist + size/count/total caps are enforced HERE, during extraction, so
     * a bad-type or oversized part is never copied into the returned list — bounding
     * this method's own memory to at most the per-email budget regardless of how much
     * an untrusted sender crams into one message. {@see TicketAttachments::storeInbound}
     * re-checks the same, authoritatively, when it persists.
     *
     * @return list<InboundEmailAttachment>
     */
    private function attachments(Message $message): array
    {
        $out = [];
        $totalBytes = 0;
        $budget = TicketAttachments::MAX_EMAIL_TOTAL_KB * 1024;

        foreach ($message->getAttachments() as $attachment) {
            if (count($out) >= TicketAttachments::MAX_COUNT) {
                break; // never build more DTOs than we'd ever store
            }
            // Inline parts are page furniture (logos in a signature), not files the
            // sender meant to attach — leave them out of the ticket.
            if (mb_strtolower((string) $attachment->getDisposition()) === 'inline') {
                continue;
            }
            $name = trim((string) $attachment->getName());
            // Drop unnamed or non-allowlisted parts BEFORE copying their bytes (the
            // memory-bounding pre-filter).
            if ($name === '' || ! TicketAttachments::isAllowedFilename($name)) {
                continue;
            }
            $content = (string) $attachment->getContent();
            $size = strlen($content);
            // Authoritative per-item gate — the SAME predicate storeInbound applies —
            // plus the per-email budget, so this list can never drift from the store.
            if (! TicketAttachments::inboundItemAllowed(TicketAttachments::safeName($name), $size)
                || $totalBytes + $size > $budget) {
                continue;
            }
            $totalBytes += $size;
            $out[] = new InboundEmailAttachment(
                filename: $name,
                mimeType: $attachment->getMimeType() ?: null,
                content: $content,
            );
        }

        return $out;
    }

    /**
     * A webklex address header (To/Cc) → a flat list of email addresses.
     *
     * @return list<string>
     */
    private function addresses(mixed $attribute): array
    {
        $items = is_object($attribute) && method_exists($attribute, 'all') ? $attribute->all() : [];

        $out = [];
        foreach ($items as $address) {
            $mail = trim((string) ($address->mail ?? ''));
            if ($mail !== '') {
                $out[] = $mail;
            }
        }

        return $out;
    }

    private function syntheticId(string $from, string $subject, string $date, string $body): string
    {
        return 'gen-'.sha1($from.'|'.$subject.'|'.$date.'|'.mb_substr($body, 0, 300));
    }

    /**
     * A webklex header Attribute → a flat list of Message-IDs (a References header
     * can hold several, space/comma separated).
     *
     * @return list<string>
     */
    private function ids(mixed $attribute): array
    {
        $values = is_object($attribute) && method_exists($attribute, 'all') ? $attribute->all() : [(string) $attribute];

        $out = [];
        foreach ($values as $value) {
            foreach (preg_split('/[\s,]+/', trim((string) $value)) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $out[] = $part;
                }
            }
        }

        return $out;
    }
}
