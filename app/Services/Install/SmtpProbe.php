<?php

namespace App\Services\Install;

use Symfony\Component\Mime\Email;

/**
 * The one live-network seam {@see MailConnectionTester} depends on, so the tester
 * + its error classification are unit-testable without a real SMTP server (mirror
 * of the injectable PDO factory in {@see MariaDbConnectionTester}). The default
 * implementation is {@see SymfonySmtpProbe}; tests bind a fake.
 */
interface SmtpProbe
{
    /** Open the session: connect + EHLO + (STARTTLS) + AUTH. Throws on failure. */
    public function start(): void;

    /** Send one message over the open session. Throws on failure. */
    public function send(Email $email): void;

    /** Close the session. Best-effort — never throws. */
    public function stop(): void;
}
