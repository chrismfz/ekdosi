<?php

namespace App\Services\Install;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;

/**
 * The real {@see SmtpProbe}: a Symfony {@see EsmtpTransport} built the way
 * Laravel's own mailer builds it from `.env`, so the installer's test matches
 * runtime behaviour. Encryption maps to the transport exactly like the wizard's
 * three choices:
 *   - `ssl`  → `smtps` scheme = implicit TLS on connect (typically port 465).
 *   - `tls`  → `smtp` scheme + `require_tls` = STARTTLS enforced (typically 587),
 *              so pointing «TLS» at an SSL-only port fails loudly instead of
 *              silently downgrading to plaintext.
 *   - `null` → `smtp` with `auto_tls` off = plaintext (no encryption).
 *
 * A short stream timeout bounds BOTH connect and read (SocketStream feeds it to
 * stream_socket_client), so a wrong host/port can't hang the wizard.
 */
class SymfonySmtpProbe implements SmtpProbe
{
    private const TIMEOUT_SECONDS = 8;

    private readonly EsmtpTransport $transport;

    public function __construct(string $host, int $port, string $encryption, string $username, string $password)
    {
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $options = match ($encryption) {
            'tls' => ['require_tls' => '1'],
            'ssl' => [],
            default => ['auto_tls' => '0'],   // «Καμία» → plaintext, no opportunistic STARTTLS
        };

        $dsn = new Dsn(
            scheme: $scheme,
            host: $host,
            user: $username !== '' ? $username : null,
            password: $password !== '' ? $password : null,
            port: $port > 0 ? $port : null,
            options: $options,
        );

        /** @var EsmtpTransport $transport */
        $transport = (new EsmtpTransportFactory)->create($dsn);
        $this->transport = $transport;

        $stream = $this->transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::TIMEOUT_SECONDS);
        }
    }

    public function start(): void
    {
        $this->transport->start();
    }

    public function send(Email $email): void
    {
        $this->transport->send($email);
    }

    public function stop(): void
    {
        try {
            $this->transport->stop();
        } catch (\Throwable) {
            // Best-effort close — the probe result is already decided.
        }
    }
}
