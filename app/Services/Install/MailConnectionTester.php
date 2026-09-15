<?php

namespace App\Services\Install;

use Closure;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * READ-of-config SMTP probe behind the web installer's «Δοκιμή email» button —
 * the mail counterpart of {@see MariaDbConnectionTester}. It opens an SMTP
 * session with the wizard's credentials (connect + encryption + AUTH) and,
 * optionally, sends one real test message, then maps any failure to an
 * actionable reason ({@see MailProbeResult}).
 *
 * The live-network step is behind an injectable {@see SmtpProbe} factory so the
 * probe + its error classification are unit-testable without a real server.
 */
class MailConnectionTester
{
    /** @var (Closure(string, int, string, string, string): SmtpProbe)|null */
    private $probeFactory;

    /**
     * @param  (Closure(string, int, string, string, string): SmtpProbe)|null  $probeFactory
     *                                                                                        (host, port, encryption, username, password) → SmtpProbe
     */
    public function __construct(?Closure $probeFactory = null)
    {
        $this->probeFactory = $probeFactory;
    }

    public function test(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        ?string $recipient = null,
        string $fromAddress = '',
        string $fromName = '',
    ): MailProbeResult {
        $host = trim($host);
        if ($host === '') {
            return MailProbeResult::missingConfig('Συμπλήρωσε «SMTP host» για να γίνει η δοκιμή.');
        }

        $recipient = $recipient !== null ? trim($recipient) : null;
        $wantsSend = $recipient !== null && $recipient !== '';

        if ($wantsSend && ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return MailProbeResult::invalidRecipient();
        }
        if ($wantsSend && trim($fromAddress) === '') {
            return MailProbeResult::missingConfig('Για αποστολή δοκιμαστικού, συμπλήρωσε πρώτα το «Αποστολέας (email)».');
        }

        $probe = ($this->probeFactory ?? fn (string $h, int $p, string $e, string $u, string $pw): SmtpProbe => new SymfonySmtpProbe($h, $p, $e, $u, $pw))(
            $host, $port, $encryption, $username, $password,
        );

        // Connect + encryption + AUTH. No email sent yet.
        try {
            $probe->start();
        } catch (Throwable $e) {
            $probe->stop();   // close a half-opened socket (best-effort, never throws)

            return $this->classify($e);
        }

        if (! $wantsSend) {
            $probe->stop();

            return MailProbeResult::connected();
        }

        // Authenticated — send one real test message.
        try {
            $email = (new Email)
                ->from(new Address(trim($fromAddress), $fromName))
                ->to($recipient)
                ->subject('ekdosi — δοκιμαστικό email')
                ->text(
                    "Δοκιμαστικό μήνυμα από τον οδηγό εγκατάστασης του ekdosi.\n"
                    .'Αν το βλέπεις αυτό, τα στοιχεία SMTP (host, port, κρυπτογράφηση, credentials) δουλεύουν.'
                );

            $probe->send($email);
        } catch (Throwable $e) {
            return $this->classify($e);
        } finally {
            $probe->stop();
        }

        return MailProbeResult::sent($recipient);
    }

    /**
     * Map an SMTP/transport exception to a reason. Order is load-bearing, because
     * Symfony wraps BOTH a dead-port connect AND a TLS-handshake failure in the
     * same «Connection could not be established with host "ssl://host:port": …»
     * text (the URL carries "ssl" even for a plain refused connection). So:
     *   1. auth — unambiguous markers (535 / "authentication").
     *   2. SPECIFIC connection failures (refused / timed out / DNS / reset) — the
     *      host/port, even when the failing URL is ssl://… .
     *   3. TLS-handshake markers — checked BEFORE the broad «could not be
     *      established» wrapper, so «SSL» pointed at a STARTTLS/plaintext port
     *      (→ "wrong version number") reads as an encryption/port mismatch, not a
     *      dead host — the headline case this button exists to diagnose.
     *   4. the broad wrapper with no TLS marker → host/port.
     */
    private function classify(Throwable $e): MailProbeResult
    {
        $m = strtolower($e->getMessage());

        $has = static fn (string ...$needles): bool => array_any(
            $needles,
            static fn (string $n): bool => str_contains($m, $n),
        );

        return match (true) {
            $has('authentication failed', 'authentication', '535', '5.7.8', 'username and password not accepted', 'auth ') => MailProbeResult::auth(),
            $has('connection refused', 'timed out', 'timeout', 'could not connect', 'network is unreachable',
                'no route to host', 'getaddrinfo', 'name or service not known', 'name does not resolve', 'connection reset') => MailProbeResult::unreachable(),
            $has('wrong version number', 'ssl routines', 'starttls', 'certificate', 'handshake',
                'decryption failed', 'sslv', 'tlsv', 'ssl3', 'crypto', 'peer') => MailProbeResult::tls(),
            $has('connection could not be established', 'unable to connect', 'connection to server') => MailProbeResult::unreachable(),
            default => MailProbeResult::error($e->getMessage()),
        };
    }
}
