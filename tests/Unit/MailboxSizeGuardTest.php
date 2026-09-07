<?php

namespace Tests\Unit;

use App\Services\Support\Inbound\WebklexImapMailbox;
use PHPUnit\Framework\TestCase;

/**
 * Πυλώνας E — the IMAP poller's whole-message size guard (memory hardening). A
 * message over the hard RFC822 cap is skipped before its body is ever downloaded,
 * so a giant/burst can neither OOM the poller nor wedge it as a poison message.
 */
class MailboxSizeGuardTest extends TestCase
{
    public function test_a_message_over_the_cap_is_too_large(): void
    {
        $cap = 35 * 1024 * 1024; // keep in sync with WebklexImapMailbox::MAX_MESSAGE_BYTES

        $this->assertFalse(WebklexImapMailbox::isMessageTooLarge(0), 'a failed size probe (0) parses normally');
        // A message big enough to carry the full 25MB decoded-attachment budget
        // (~34MB on the wire) is still parsed, not stubbed.
        $this->assertFalse(WebklexImapMailbox::isMessageTooLarge(34 * 1024 * 1024));
        $this->assertFalse(WebklexImapMailbox::isMessageTooLarge($cap), 'exactly the cap is parsed');
        $this->assertTrue(WebklexImapMailbox::isMessageTooLarge($cap + 1), 'one byte over → header-only stub');
    }
}
