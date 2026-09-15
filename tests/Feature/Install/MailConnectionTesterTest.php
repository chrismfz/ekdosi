<?php

namespace Tests\Feature\Install;

use App\Services\Install\MailConnectionTester;
use App\Services\Install\SmtpProbe;
use App\Services\Install\SymfonySmtpProbe;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use Throwable;

/**
 * The installer's SMTP probe: connect + auth (and an optional real send),
 * classified into an actionable reason. Driven by a fake {@see SmtpProbe} (the
 * injectable live-network seam) so every branch runs without a mail server.
 */
class MailConnectionTesterTest extends TestCase
{
    private function tester(FakeSmtpProbe $probe): MailConnectionTester
    {
        return new MailConnectionTester(fn (string $h, int $p, string $e, string $u, string $pw): SmtpProbe => $probe);
    }

    public function test_connects_and_authenticates_without_sending_when_no_recipient(): void
    {
        $probe = new FakeSmtpProbe;
        $result = $this->tester($probe)->test('mail.example.gr', 587, 'tls', 'user', 'pass');

        $this->assertTrue($result->ok);
        $this->assertSame('connected', $result->reason);
        $this->assertTrue($probe->started);
        $this->assertNull($probe->sent, 'no test recipient → no email is sent');
        $this->assertTrue($probe->stopped);
    }

    public function test_sends_a_real_test_message_when_a_recipient_is_given(): void
    {
        $probe = new FakeSmtpProbe;
        $result = $this->tester($probe)->test(
            'mail.example.gr', 587, 'tls', 'user', 'pass',
            recipient: 'me@example.gr', fromAddress: 'no-reply@example.gr', fromName: 'Sender',
        );

        $this->assertTrue($result->ok);
        $this->assertSame('sent', $result->reason);
        $this->assertNotNull($probe->sent);
        $this->assertSame('me@example.gr', $probe->sent->getTo()[0]->getAddress());
        $this->assertSame('no-reply@example.gr', $probe->sent->getFrom()[0]->getAddress());
        $this->assertStringContainsString('me@example.gr', $result->message);
        $this->assertTrue($probe->stopped);
    }

    public function test_auth_failure_is_classified(): void
    {
        $probe = new FakeSmtpProbe(startError: new \RuntimeException('Expected response code "235" but got code "535", Authentication failed'));
        $result = $this->tester($probe)->test('h', 587, 'tls', 'user', 'wrong');

        $this->assertFalse($result->ok);
        $this->assertSame('auth', $result->reason);
    }

    public function test_connection_refused_is_unreachable_even_with_ssl_in_the_url(): void
    {
        // The transport URL for an SSL port is ssl://host:465, so the exception
        // text carries "ssl" — the connection-phrase check must win over the TLS
        // one, or a plain refused connection would be mislabelled a TLS problem.
        $probe = new FakeSmtpProbe(startError: new \RuntimeException('Connection could not be established with host "ssl://mail.example.gr:465": Connection refused'));
        $result = $this->tester($probe)->test('mail.example.gr', 465, 'ssl', 'user', 'pass');

        $this->assertSame('unreachable', $result->reason);
    }

    public function test_ssl_pointed_at_a_starttls_port_is_classified_as_tls_not_unreachable(): void
    {
        // The headline case: «SSL» chosen but pointed at a 587/plaintext port.
        // Symfony connects ssl://host:587 and the handshake fails INSIDE the
        // «Connection could not be established» wrapper — so the classifier must
        // see the TLS marker before the broad connection-failed phrase, or the
        // operator is wrongly told «host not answering» instead of «wrong
        // Κρυπτογράφηση/port». This is the exact ordering the feature depends on.
        $probe = new FakeSmtpProbe(startError: new \RuntimeException(
            'Connection could not be established with host "ssl://mail.example.gr:587": '
            .'stream_socket_client(): SSL operation failed with code 1. '
            .'OpenSSL Error messages: error:0A00010B:SSL routines::wrong version number'
        ));
        $result = $this->tester($probe)->test('mail.example.gr', 587, 'ssl', 'user', 'pass');

        $this->assertSame('tls', $result->reason);
    }

    public function test_starttls_required_but_unsupported_is_classified_as_tls(): void
    {
        $probe = new FakeSmtpProbe(startError: new \RuntimeException('Unable to connect with STARTTLS: the SMTP server does not support TLS.'));
        $result = $this->tester($probe)->test('mail.example.gr', 587, 'tls', 'user', 'pass');

        $this->assertSame('tls', $result->reason);
    }

    public function test_invalid_recipient_short_circuits_before_connecting(): void
    {
        $probe = new FakeSmtpProbe;
        $result = $this->tester($probe)->test('h', 587, 'tls', 'user', 'pass', recipient: 'not-an-email', fromAddress: 'from@x.gr');

        $this->assertSame('recipient', $result->reason);
        $this->assertFalse($probe->started, 'a bad recipient must not open a connection');
    }

    public function test_missing_host_is_a_config_error(): void
    {
        $probe = new FakeSmtpProbe;
        $result = $this->tester($probe)->test('   ', 587, 'tls', 'user', 'pass');

        $this->assertSame('config', $result->reason);
        $this->assertFalse($probe->started);
    }

    public function test_send_without_a_from_address_is_a_config_error(): void
    {
        $probe = new FakeSmtpProbe;
        $result = $this->tester($probe)->test('h', 587, 'tls', 'user', 'pass', recipient: 'me@example.gr', fromAddress: '');

        $this->assertSame('config', $result->reason);
        $this->assertFalse($probe->started, 'never connect when a send was asked for but the sender is missing');
    }

    public function test_send_failure_is_classified_and_the_session_is_closed(): void
    {
        $probe = new FakeSmtpProbe(sendError: new \RuntimeException('Expected response code "250" but got code "550", relay access denied'));
        $result = $this->tester($probe)->test('h', 587, 'tls', 'user', 'pass', recipient: 'me@example.gr', fromAddress: 'from@x.gr');

        $this->assertFalse($result->ok);
        $this->assertSame('error', $result->reason);   // 550 relay denied ≠ auth/tls/unreachable
        $this->assertTrue($probe->started);
        $this->assertTrue($probe->stopped, 'stop() runs in finally even when send throws');
    }

    /**
     * Smoke-test the REAL probe (the unit tests above use the fake): building the
     * Symfony transport + stream + timeout must not throw for any of the wizard's
     * three encryption choices. Construction opens no socket, so this stays
     * offline — it guards the production wiring against an API typo.
     */
    public function test_symfony_probe_builds_offline_for_each_encryption_mode(): void
    {
        foreach (['tls', 'ssl', 'null'] as $encryption) {
            $probe = new SymfonySmtpProbe('mail.example.gr', 587, $encryption, 'user', 'pass');
            $this->assertInstanceOf(SmtpProbe::class, $probe);
        }
    }
}

/** In-memory {@see SmtpProbe} for the tester's unit tests — no socket, no server. */
class FakeSmtpProbe implements SmtpProbe
{
    public bool $started = false;

    public bool $stopped = false;

    public ?Email $sent = null;

    public function __construct(
        private ?Throwable $startError = null,
        private ?Throwable $sendError = null,
    ) {}

    public function start(): void
    {
        if ($this->startError !== null) {
            throw $this->startError;
        }
        $this->started = true;
    }

    public function send(Email $email): void
    {
        if ($this->sendError !== null) {
            throw $this->sendError;
        }
        $this->sent = $email;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
