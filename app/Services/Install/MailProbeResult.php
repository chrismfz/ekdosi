<?php

namespace App\Services\Install;

/**
 * Outcome of the installer's «Δοκιμή email» — the SMTP counterpart to
 * {@see MariaDbProbeResult}. Answers: did the SMTP session connect + negotiate
 * encryption + authenticate, and (optionally) did a real test message go out?
 *
 * Unlike the DB probe there is no override/safety dimension — a mail test has no
 * destructive side effect — so the shape is just {ok, reason, message}, and the
 * wizard renders green on `ok`, red otherwise.
 *
 * `reason` classifies the outcome so the message can be actionable:
 *  - connected — connected + authenticated; NO email sent (no test recipient).
 *  - sent      — connected + authenticated AND a test email was delivered to SMTP.
 *  - auth      — connected, but the username/password were rejected.
 *  - tls       — TLS/SSL negotiation failed → almost always the wrong
 *                «Κρυπτογράφηση»/port pairing (587+TLS vs 465+SSL).
 *  - unreachable — host/port did not answer (wrong host/port, firewall, down).
 *  - recipient — a test recipient was given but is not a valid email address.
 *  - config    — SMTP host (or, for a send, sender address) is missing.
 *  - error     — anything else (surfaced with a short raw hint).
 */
class MailProbeResult
{
    public function __construct(
        /** Connected + authenticated (and sent, when a recipient was given). */
        public readonly bool $ok,
        public readonly string $reason,
        public readonly string $message,
    ) {}

    public static function connected(): self
    {
        return new self(
            ok: true,
            reason: 'connected',
            message: 'Επιτυχία: συνδεθήκαμε και αυθεντικοποιηθήκαμε στον SMTP διακομιστή. '
                .'(Δεν στάλθηκε email — συμπλήρωσε διεύθυνση δοκιμής για πραγματική αποστολή.)',
        );
    }

    public static function sent(string $recipient): self
    {
        return new self(
            ok: true,
            reason: 'sent',
            message: "Επιτυχία: συνδεθήκαμε, αυθεντικοποιηθήκαμε και στείλαμε δοκιμαστικό email στο «{$recipient}». "
                .'Έλεγξε το inbox (και τον φάκελο ανεπιθύμητων).',
        );
    }

    public static function auth(): self
    {
        return new self(
            ok: false,
            reason: 'auth',
            message: 'Η σύνδεση στον SMTP έγινε, αλλά ο έλεγχος ταυτότητας απέτυχε — λάθος «SMTP χρήστης» ή «SMTP κωδικός».',
        );
    }

    public static function tls(): self
    {
        return new self(
            ok: false,
            reason: 'tls',
            message: 'Πρόβλημα κρυπτογράφησης TLS/SSL — συνήθως λάθος συνδυασμός «Κρυπτογράφηση»/«port». '
                .'Δοκίμασε port 587 με «TLS», ή port 465 με «SSL».',
        );
    }

    public static function unreachable(): self
    {
        return new self(
            ok: false,
            reason: 'unreachable',
            message: 'Δεν έγινε σύνδεση στον SMTP host/port — έλεγξε «SMTP host», «SMTP port» και τυχόν firewall του διακομιστή.',
        );
    }

    public static function invalidRecipient(): self
    {
        return new self(
            ok: false,
            reason: 'recipient',
            message: 'Η διεύθυνση δοκιμής δεν είναι έγκυρο email.',
        );
    }

    public static function missingConfig(string $message): self
    {
        return new self(ok: false, reason: 'config', message: $message);
    }

    public static function error(string $raw): self
    {
        $hint = trim($raw);
        if (mb_strlen($hint) > 200) {
            $hint = mb_substr($hint, 0, 200).'…';
        }

        return new self(
            ok: false,
            reason: 'error',
            message: 'Αποτυχία δοκιμής SMTP'.($hint !== '' ? ": {$hint}" : '.'),
        );
    }
}
