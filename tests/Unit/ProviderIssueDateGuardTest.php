<?php

namespace Tests\Unit;

use App\Support\EInvoice\ProviderIssueDateGuard;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * PROV-020: the provider issue-date guard compares the Greece-local (Europe/Athens)
 * date, not the raw process/UTC date. Tested directly on the guard (no Eloquent /
 * app-timezone reinterpretation) so the Athens conversion is genuinely exercised.
 */
class ProviderIssueDateGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_in_athens_passes_even_when_utc_is_still_yesterday(): void
    {
        // 22:30 UTC on 2026-06-15 is already 01:30 on the 16th in Athens (UTC+3).
        Carbon::setTestNow(Carbon::parse('2026-06-15 22:30:00', 'UTC'));

        // Issued on the 16th (Athens) = today in Athens, though UTC still reads the
        // 15th. A UTC-based guard would wrongly reject this; the Athens guard accepts.
        ProviderIssueDateGuard::assertIssuedToday(
            Carbon::parse('2026-06-16 09:00:00', 'Europe/Athens'),
            'INV-1',
        );

        $this->addToAssertionCount(1); // reached here → no throw
    }

    public function test_yesterday_in_athens_throws(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 22:30:00', 'UTC')); // Athens: 16th

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ημερομηνία έκδοσης/u');

        ProviderIssueDateGuard::assertIssuedToday(
            Carbon::parse('2026-06-15 09:00:00', 'Europe/Athens'), // Athens 15th = yesterday
            'INV-2',
        );
    }

    public function test_tomorrow_throws(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00', 'Europe/Athens'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ημερομηνία έκδοσης/u');

        ProviderIssueDateGuard::assertIssuedToday(
            Carbon::parse('2026-06-17 10:00:00', 'Europe/Athens'),
            'INV-3',
        );
    }

    public function test_null_issue_date_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ημερομηνία έκδοσης/u');

        ProviderIssueDateGuard::assertIssuedToday(null, 'INV-4');
    }

    public function test_blank_doc_code_still_produces_a_readable_message(): void
    {
        try {
            ProviderIssueDateGuard::assertIssuedToday(null, '');
            $this->fail('Expected a throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(χωρίς κωδικό)', $e->getMessage());
        }
    }
}
