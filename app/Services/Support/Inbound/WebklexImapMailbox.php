<?php

namespace App\Services\Support\Inbound;

use App\Models\TicketDepartment;
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

    public function test(TicketDepartment $department): MailboxTestResult
    {
        try {
            $client = $this->client($department);
            $client->connect();
            $folder = $client->getFolder($department->imap_folder ?: 'INBOX');
            if ($folder === null) {
                $client->disconnect();

                return MailboxTestResult::fail('Δεν βρέθηκε ο φάκελος «'.($department->imap_folder ?: 'INBOX').'».');
            }
            $status = $folder->examine();
            $client->disconnect();

            return MailboxTestResult::ok((int) ($status['exists'] ?? 0));
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

            // leaveUnread(): don't let webklex auto-flag on fetch — WE mark \Seen only
            // once the handler confirms the message was routed (idempotent + safe).
            $messages = $folder->query()->whereUnseen()->leaveUnread()->limit(self::MAX_PER_POLL)->get();
            $summary->fetched = $messages->count();

            foreach ($messages as $message) {
                try {
                    if ($handle($this->parse($message)) === true) {
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
        return (new ClientManager)->make([
            'host' => (string) $department->imap_host,
            'port' => (int) ($department->imap_port ?: 993),
            'encryption' => $this->encryption($department->imap_encryption),
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

        return new ParsedInboundEmail(
            fromEmail: $from?->mail ?? '',
            fromName: ($name = trim((string) ($from?->personal ?? ''))) !== '' ? $name : null,
            subject: trim((string) $message->getSubject()),
            body: (string) ($message->getTextBody() ?: $message->getHTMLBody() ?: ''),
            messageId: ($mid = trim((string) $message->getMessageId())) !== '' ? $mid : null,
            references: array_merge($this->ids($message->getInReplyTo()), $this->ids($message->getReferences())),
        );
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
