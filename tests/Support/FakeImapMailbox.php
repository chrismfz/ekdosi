<?php

namespace Tests\Support;

use App\Models\TicketDepartment;
use App\Services\Support\Inbound\ImapMailbox;
use App\Services\Support\Inbound\MailboxPollSummary;
use App\Services\Support\Inbound\MailboxTestResult;
use App\Services\Support\Inbound\ParsedInboundEmail;

/**
 * In-memory {@see ImapMailbox} for tests — the seam that lets the poller
 * orchestration be tested without a live IMAP server (Πυλώνας E, Phase 3b).
 */
class FakeImapMailbox implements ImapMailbox
{
    /** @var array<int, list<ParsedInboundEmail>> department id => queued messages */
    public array $inbox = [];

    public bool $connects = true;

    /** @var list<ParsedInboundEmail> messages the poller confirmed (marked \Seen) */
    public array $marked = [];

    public function queue(TicketDepartment $department, ParsedInboundEmail ...$emails): void
    {
        $this->inbox[$department->id] = array_merge($this->inbox[$department->id] ?? [], $emails);
    }

    public function test(TicketDepartment $department): MailboxTestResult
    {
        return $this->connects
            ? MailboxTestResult::ok(count($this->inbox[$department->id] ?? []))
            : MailboxTestResult::fail('fake mailbox down');
    }

    public function poll(TicketDepartment $department, callable $handle): MailboxPollSummary
    {
        $summary = new MailboxPollSummary;
        if (! $this->connects) {
            $summary->addError('fake mailbox down');

            return $summary;
        }

        $summary->connected = true;
        $emails = $this->inbox[$department->id] ?? [];
        $summary->fetched = count($emails);

        foreach ($emails as $email) {
            if ($handle($email) === true) {
                $this->marked[] = $email;
                $summary->processed++;
            }
        }
        $this->inbox[$department->id] = []; // marked seen → not re-fetched

        return $summary;
    }
}
