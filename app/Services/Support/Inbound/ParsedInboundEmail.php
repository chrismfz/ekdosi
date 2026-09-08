<?php

namespace App\Services\Support\Inbound;

/**
 * A transport-agnostic view of one inbound email (Πυλώνας E, Phase 3). The IMAP
 * poller (Phase 3b) builds this from a webklex message; {@see InboundTicketRouter}
 * consumes it. Kept free of any IMAP type so the routing logic is unit-testable
 * with plain fixtures.
 */
final class ParsedInboundEmail
{
    /**
     * @param  list<string>  $references  Message-IDs from the References + In-Reply-To
     *                                    headers (for threading a reply to a message we sent)
     * @param  list<string>  $to  To-header addresses (incl. the department mailbox)
     * @param  list<string>  $cc  Cc-header addresses — the other parties the sender
     *                            looped in; captured as watchers/CC by the router
     * @param  list<InboundEmailAttachment>  $attachments  the email's real (non-inline)
     *                                                     attachments; validated + stored by the router (PR B)
     */
    public function __construct(
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $messageId = null,
        public readonly array $references = [],
        public readonly array $to = [],
        public readonly array $cc = [],
        public readonly array $attachments = [],
    ) {}
}
