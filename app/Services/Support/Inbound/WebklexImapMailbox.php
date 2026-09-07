<?php

namespace App\Services\Support\Inbound;

use App\Models\TicketDepartment;
use App\Support\HtmlToText;
use App\Support\TicketAttachments;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Support\MessageCollection;

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
     * Hard cap for a whole RFC822 message we will DOWNLOAD + parse. At/over this we do
     * NOT fetch the body at all — we open a header-only stub ticket instead (see
     * poll()), so a giant can neither OOM the poller nor be silently lost. Sized with
     * headroom above our 25 MB decoded-attachment budget on the wire (base64 ~+33% ≈
     * 33 MB, plus the HTML/text body + MIME boundaries + headers), so a message
     * carrying the full attachment budget is still parsed, not stubbed.
     *
     * NOTE (deploy): parsing a message near this cap peaks at a few × its wire size
     * (raw + decoded parts held at once), so the poll process / queue worker wants
     * `memory_limit` ≥ 256M. The cap bounds the worst case to ONE such message at a
     * time (bodies are fetched one-by-one), never the whole 50-message batch.
     */
    private const MAX_MESSAGE_BYTES = 40 * 1024 * 1024; // 40 MB

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
        $client = null;

        try {
            $client = $this->client($department);
            $client->connect();
            $summary->connected = true;

            $folder = $client->getFolder($department->imap_folder ?: 'INBOX');
            if ($folder === null) {
                $summary->addError('Δεν βρέθηκε ο φάκελος.');

                return $summary;
            }

            // Walk the UNSEEN messages ONE AT A TIME (chunk size 1, headers only): each
            // chunk builds a fresh single-message collection and the previous one is
            // released before the next, so webklex never holds more than one message's
            // decoded body/attachments/raw structure at a time — a burst of large mails
            // can't accumulate and OOM the poller. leaveUnread(): WE mark \Seen, never
            // the fetch. Stop after MAX_PER_POLL via a sentinel so one poll can't run
            // unbounded (the rest waits for the next poll).
            try {
                $folder->query()->whereUnseen()->leaveUnread()->fetchBody(false)->chunked(
                    function (MessageCollection $chunk) use ($handle, $summary): void {
                        foreach ($chunk as $message) {
                            if ($summary->fetched >= self::MAX_PER_POLL) {
                                throw new PollBudgetReached;
                            }
                            $summary->fetched++;
                            $this->handleOne($message, $handle, $summary);
                        }
                    },
                    1
                );
            } catch (PollBudgetReached) {
                // hit the per-poll cap — the remaining unseen mail is left for next time
            }
        } catch (Throwable $e) {
            $summary->addError('Σύνδεση: '.$e->getMessage());
        } finally {
            // Always release the IMAP connection — even if the walk threw (e.g. a
            // message webklex couldn't parse) — so a poll never leaks a socket.
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // teardown best-effort
            }
        }

        return $summary;
    }

    /**
     * Route ONE fetched (headers-only) message. Size guard BEFORE downloading the body
     * (RFC822.SIZE is a cheap, header-level IMAP command): over the cap — OR a size we
     * couldn't read — routes a HEADER-ONLY stub (no body download, so no OOM) so the
     * sender's request isn't silently lost and a giant we couldn't measure is never
     * parsed on faith; under the cap → download + parse this one message. \Seen is set
     * only after the handler confirms routing, so nothing wedges the mailbox. Per-
     * message errors are isolated (logged, message left unread for the next poll).
     */
    private function handleOne(Message $message, callable $handle, MailboxPollSummary $summary): void
    {
        try {
            $size = $this->messageSize($message);
            if ($size === null || self::isMessageTooLarge($size)) {
                $parsed = $this->parseHeadersOnly($message, $size ?? 0);
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
        $h = $this->headerFields($message);
        // Prefer the text/plain part; fall back to converting the HTML part to text
        // (many clients send HTML-only) rather than storing raw markup as the body.
        $text = trim((string) $message->getTextBody());
        $body = $text !== '' ? $text : HtmlToText::convert((string) $message->getHTMLBody());

        return new ParsedInboundEmail(
            fromEmail: $h['email'],
            fromName: $h['name'],
            subject: $h['subject'],
            body: $body,
            // Synthesise a stable id when the header is missing (some mailers omit it),
            // so the router's Message-ID idempotency still collapses a redelivery.
            messageId: $h['messageId'] !== '' ? $h['messageId'] : $this->syntheticId($h['email'], $h['subject'], (string) $message->getDate(), $body),
            references: $h['references'],
            to: $h['to'],
            cc: $h['cc'],
            attachments: $this->attachments($message),
        );
    }

    /**
     * The header fields both {@see parse} and {@see parseHeadersOnly} need — the ONE
     * place they're extracted, so the stub path can't drift from the normal one.
     *
     * @return array{email:string, name:?string, subject:string, messageId:string, references:list<string>, to:list<string>, cc:list<string>}
     */
    private function headerFields(Message $message): array
    {
        $from = $message->getFrom()->first();
        $name = trim((string) ($from?->personal ?? ''));

        return [
            'email' => $from?->mail ?? '',
            'name' => $name !== '' ? $name : null,
            'subject' => trim((string) $message->getSubject()),
            'messageId' => trim((string) $message->getMessageId()),
            'references' => array_merge($this->ids($message->getInReplyTo()), $this->ids($message->getReferences())),
            'to' => $this->addresses($message->getTo()),
            'cc' => $this->addresses($message->getCc()),
        ];
    }

    /**
     * Placeholder body for a message we route WITHOUT downloading its body — either
     * over the size cap OR one whose size we couldn't read (so we don't parse it on
     * faith). Wording is honest for both cases.
     */
    private const OVERSIZED_BODY = '⚠ Ένα εισερχόμενο email δεν λήφθηκε αυτόματα (πολύ μεγάλο μέγεθος ή '
        .'αδυναμία λήψης). Επικοινωνήστε με τον αποστολέα για το περιεχόμενο ή τα συνημμένα.';

    /**
     * Build a ParsedInboundEmail from the HEADERS ONLY (no body download), for a
     * message over {@see MAX_MESSAGE_BYTES}. The sender/subject/threading survive so
     * it routes into a ticket like any other mail — the operator sees a stub with a
     * placeholder body and follows up — but nothing giant is ever loaded into memory
     * and the request is never silently lost.
     */
    private function parseHeadersOnly(Message $message, int $size): ParsedInboundEmail
    {
        $h = $this->headerFields($message);
        // When the Message-ID is absent, the fixed placeholder body can't distinguish
        // two DIFFERENT oversized mails (same sender/subject/second) — they'd collapse
        // to one synthetic id and the second would be dropped as a redelivery. Seed
        // with the mailbox UID (stable per message, unique) so distinct mails separate
        // while a true redelivery — same UID — still dedups; size is a further nudge.
        $seed = self::OVERSIZED_BODY.'|'.$size.'|'.$this->messageUid($message);

        return new ParsedInboundEmail(
            fromEmail: $h['email'],
            fromName: $h['name'],
            subject: $h['subject'],
            body: self::OVERSIZED_BODY,
            messageId: $h['messageId'] !== '' ? $h['messageId'] : $this->syntheticId($h['email'], $h['subject'], (string) $message->getDate(), $seed),
            references: $h['references'],
            to: $h['to'],
            cc: $h['cc'],
            attachments: [],
        );
    }

    /**
     * The message's whole RFC822 size (RFC822.SIZE), or NULL if the probe fails. A
     * failed probe must NOT be treated as small — that would send a possibly-giant
     * message to parseBody() and OOM. The caller routes a header-only stub on null,
     * so an unmeasurable message is never downloaded on faith. Retried once so a
     * transient IMAP hiccup doesn't needlessly stub (and lose the body of) an
     * otherwise-normal email.
     */
    private function messageSize(Message $message): ?int
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                return (int) $message->getSize();
            } catch (Throwable $e) {
                // transient hiccup — fall through to retry, then give up (null)
            }
        }

        return null;
    }

    /** The mailbox UID (stable, unique per message), or '' if it can't be read. */
    private function messageUid(Message $message): string
    {
        try {
            return (string) $message->getUid();
        } catch (Throwable $e) {
            return '';
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
