<?php

namespace App\Services\Mail;

use RuntimeException;
use Throwable;

/**
 * The mail server permanently (5xx) rejected the PRIMARY recipient at RCPT — usually a
 * non-existent address, but a relay/policy refusal (misconfigured tenant SMTP) looks the
 * same. Retrying can't help either way; the message names both likely fixes.
 */
class PrimaryRecipientRejected extends RuntimeException
{
    public function __construct(public readonly string $recipient, Throwable $previous)
    {
        parent::__construct(
            "Ο διακομιστής αλληλογραφίας απέρριψε οριστικά τον παραλήπτη {$recipient} — ελέγξτε ότι η διεύθυνση υπάρχει (ή τις ρυθμίσεις SMTP, αν απορρίπτονται όλοι οι παραλήπτες). [{$previous->getMessage()}]",
            0,
            $previous,
        );
    }
}
