<?php

namespace Tests\Support;

use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * A mail transport that behaves like a real SMTP relay on `RCPT TO`: if ANY envelope
 * recipient is in $rejected, the whole message fails with the exact exception Symfony's
 * SmtpTransport throws (550 «User unknown»), and nothing is delivered. Otherwise the
 * message is recorded in $delivered. $code/$command let a test fake a 4xx or a
 * non-RCPT (DATA) rejection instead.
 */
class RejectingSmtpTransport extends AbstractTransport
{
    /** @var list<SentMessage> */
    public array $delivered = [];

    public int $attempts = 0;

    /** @param list<string> $rejected */
    public function __construct(
        public array $rejected = [],
        public int $code = 550,
        public string $expected = '250/251/252',
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $this->attempts++;

        foreach ($message->getEnvelope()->getRecipients() as $recipient) {
            if (in_array($recipient->getAddress(), $this->rejected, true)) {
                throw new UnexpectedResponseException(sprintf(
                    'Expected response code "%s" but got code "%d", with message "%d 5.1.1 <%s>: Recipient address rejected: User unknown in virtual mailbox table".',
                    $this->expected, $this->code, $this->code, $recipient->getAddress(),
                ), $this->code);
            }
        }

        $this->delivered[] = $message;
    }

    /** @return list<string> every envelope recipient of the Nth delivered message */
    public function recipientsOf(int $index = 0): array
    {
        return array_map(
            static fn ($a): string => $a->getAddress(),
            $this->delivered[$index]->getEnvelope()->getRecipients(),
        );
    }

    public function __toString(): string
    {
        return 'rejecting://';
    }
}
