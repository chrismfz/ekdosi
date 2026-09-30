<?php

namespace App\Services\Mail;

use Closure;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/**
 * Sends a customer mail so that ONE bad extra recipient can't sink the whole send.
 *
 * SMTP is all-or-nothing per message: Symfony issues one `RCPT TO` per recipient
 * (To + Cc + Bcc) and aborts the transaction on the first rejection — so a dead
 * `customer.secondary_email` CC (550 «User unknown») meant the PRIMARY address got
 * nothing either, and the job retried the same permanent error 3×. Real incident
 * 2026-09-29: an επί πιστώσει ΤΠΥ never reached the customer because the alt-email
 * mailbox had been deleted.
 *
 * Rule: a permanent (5xx) RCPT rejection of an extra (Cc/Bcc) → resend ONCE to the
 * To only and report which extras were dropped (the caller records it on its mail
 * log, so the operator can fix the card). A permanent RCPT rejection of the To
 * itself → PrimaryRecipientRejected (retrying can't help — callers fail without
 * retries). Anything else (4xx, connection, auth, DATA rejection) is re-thrown
 * untouched so the normal retry machinery applies.
 */
class RecipientFallbackSender
{
    /**
     * @param  Closure(bool $primaryOnly): Mailable  $makeMail  builds a FRESH mailable
     *                                                          each call (a sent Mailable keeps its hydrated cc/bcc, so it can't be reused);
     *                                                          $primaryOnly=true must omit every Cc/Bcc
     * @return list<string> the extra recipients dropped by the fallback ([] = delivered to everyone)
     *
     * @throws PrimaryRecipientRejected
     */
    public function send(MailerContract $mailer, string $to, Closure $makeMail): array
    {
        try {
            $mailer->to($to)->send($makeMail(false));

            return [];
        } catch (UnexpectedResponseException $e) {
            if (! self::isPermanentRecipientRejection($e)) {
                throw $e;
            }

            $rejected = self::rejectedAddress($e);
            if ($rejected !== null && strcasecmp($rejected, $to) === 0) {
                throw new PrimaryRecipientRejected($to, $e);
            }

            $mail = $makeMail(false);
            $extras = self::extraRecipients($mail);
            if ($extras === []) {
                // Only the To was on the envelope → it must be the one rejected.
                throw new PrimaryRecipientRejected($to, $e);
            }

            try {
                $mailer->to($to)->send($makeMail(true));
            } catch (UnexpectedResponseException $retry) {
                // To-only and still a permanent RCPT reject → the To is bad too.
                if (self::isPermanentRecipientRejection($retry)) {
                    throw new PrimaryRecipientRejected($to, $retry);
                }

                throw $retry;
            }

            // Name the culprit when the server told us; otherwise every extra was dropped.
            $dropped = $rejected !== null ? [$rejected] : $extras;
            Log::warning('Mail: extra recipient rejected — sent to the primary only', [
                'mailable' => $mail::class,
                'to' => $to,
                'dropped' => $dropped,
            ]);

            return $dropped;
        }
    }

    /**
     * Log-row fields for a send that went out To-only because the server rejected
     * extra recipient(s): the cc/bcc lists are cleared (they did NOT get it) and
     * error_message names the dropped address(es) so the operator fixes the card.
     *
     * @param  list<string>  $dropped
     * @return array<string, mixed>
     */
    public static function droppedLogFields(array $dropped): array
    {
        if ($dropped === []) {
            return [];
        }

        return [
            'cc_list' => null,
            'bcc_list' => null,
            'error_message' => 'Στάλθηκε ΜΟΝΟ στον κύριο παραλήπτη — ο διακομιστής απέρριψε: '
                .implode(', ', $dropped).'. Διορθώστε/αφαιρέστε τη διεύθυνση («Email 2» του πελάτη ή audit BCC της εταιρείας).',
        ];
    }

    /**
     * A 5xx answer to `RCPT TO` — the only command Symfony checks against 250/251/252.
     * (A 5xx on DATA/MAIL FROM is a content/sender problem, not a recipient one.)
     */
    public static function isPermanentRecipientRejection(\Throwable $e): bool
    {
        return $e instanceof UnexpectedResponseException
            && $e->getCode() >= 500 && $e->getCode() < 600
            && str_contains($e->getMessage(), '"250/251/252"');
    }

    /** The `<address>` the server quoted in its rejection, if any. */
    public static function rejectedAddress(\Throwable $e): ?string
    {
        return preg_match('/<([^<>\s]+@[^<>\s]+)>/', $e->getMessage(), $m) ? $m[1] : null;
    }

    /** @return list<string> */
    private static function extraRecipients(Mailable $mail): array
    {
        if (! method_exists($mail, 'envelope')) {
            return [];
        }

        $envelope = $mail->envelope();
        $all = array_merge($envelope->cc, $envelope->bcc);

        return array_values(array_map(
            static fn (Address|string $a): string => $a instanceof Address ? $a->address : $a,
            $all,
        ));
    }
}
