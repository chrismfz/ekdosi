<?php

namespace App\Services\Support\Inbound;

use App\Models\TicketDepartment;

/**
 * The seam over raw IMAP (Πυλώνας E, Phase 3b). The concrete
 * {@see WebklexImapMailbox} does the untestable network I/O; the poller command
 * depends only on this interface, so its orchestration is unit-testable with a fake.
 * Neither method throws — errors are reported in the result.
 */
interface ImapMailbox
{
    /** Read-only connect + login; report reachability + the INBOX count. */
    public function test(TicketDepartment $department): MailboxTestResult;

    /**
     * Fetch UNSEEN messages and hand each (as a {@see ParsedInboundEmail}) to
     * $handle. When $handle returns true (processed OK) the source message is marked
     * read so it is not re-fetched; a false/throw leaves it unread for the next poll.
     *
     * @param  callable(ParsedInboundEmail):bool  $handle
     */
    public function poll(TicketDepartment $department, callable $handle): MailboxPollSummary;
}
