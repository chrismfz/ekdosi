<?php

namespace Tests\Unit;

use App\Support\EInvoice\SendChannel;
use PHPUnit\Framework\TestCase;

/**
 * The flat «Τρόπος αποστολής» channel ↔ 4-column mapping. Pure logic — the brain of
 * the P3 operator dropdown. A round-trip (columns → channel → columns) must be
 * stable, and decomposition must be fail-safe (unknown → PDF only, never live).
 */
class SendChannelTest extends TestCase
{
    public function test_decompose_mydata_channels(): void
    {
        $this->assertSame(
            ['einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'production', 'einvoice_provider_key' => null, 'einvoice_provider_mode' => 'off'],
            SendChannel::decompose('mydata-production')
        );
        $this->assertSame('sandbox', SendChannel::decompose('mydata-sandbox')['mydata_mode']);
        $this->assertSame('off', SendChannel::decompose('mydata-off')['mydata_mode']);
    }

    public function test_decompose_provider_channels(): void
    {
        $sandbox = SendChannel::decompose('invosign-sandbox');
        $this->assertSame('gr-provider', $sandbox['einvoice_provider']);
        $this->assertSame('invosign', $sandbox['einvoice_provider_key']);
        $this->assertSame('sandbox', $sandbox['einvoice_provider_mode']);
        $this->assertSame('off', $sandbox['mydata_mode']);

        $prod = SendChannel::decompose('sbz-production');
        $this->assertSame('sbz', $prod['einvoice_provider_key']);
        $this->assertSame('production', $prod['einvoice_provider_mode']);
    }

    public function test_decompose_peppol_and_off(): void
    {
        $this->assertSame('ee-peppol', SendChannel::decompose('peppol')['einvoice_provider']);
        $this->assertSame('none', SendChannel::decompose('off')['einvoice_provider']);
    }

    public function test_unknown_channel_is_failsafe_pdf_only(): void
    {
        foreach (['', 'garbage', 'invosign', 'invosign-', '-sandbox', 'mydata-live'] as $bad) {
            $cols = SendChannel::decompose($bad);
            $this->assertSame('gr-mydata', $cols['einvoice_provider'], "[$bad] must not pick a provider");
            $this->assertSame('off', $cols['mydata_mode'], "[$bad] must NOT be a live filing path");
        }
    }

    public function test_from_columns_reverse_mapping(): void
    {
        $this->assertSame('mydata-production', SendChannel::fromColumns('gr-mydata', 'production', null, 'off'));
        $this->assertSame('mydata-sandbox', SendChannel::fromColumns('gr-mydata', 'sandbox', null, 'off'));
        $this->assertSame('mydata-off', SendChannel::fromColumns('gr-mydata', 'off', null, 'off'));
        $this->assertSame('invosign-sandbox', SendChannel::fromColumns('gr-provider', 'off', 'invosign', 'sandbox'));
        $this->assertSame('invosign-production', SendChannel::fromColumns('gr-provider', 'off', 'invosign', 'production'));
        $this->assertSame('peppol', SendChannel::fromColumns('ee-peppol', 'off', null, 'off'));
        $this->assertSame('off', SendChannel::fromColumns('none', 'off', null, 'off'));
    }

    public function test_provider_row_without_a_key_falls_back(): void
    {
        // Out-of-band data (gr-provider but no key) must not yield a non-existent
        // 'none-sandbox' option — it maps to the fail-safe channel.
        $this->assertSame('mydata-off', SendChannel::fromColumns('gr-provider', 'off', null, 'sandbox'));
        $this->assertSame('mydata-off', SendChannel::fromColumns('gr-provider', 'off', '', 'production'));
    }

    public function test_round_trip_is_stable_for_every_channel(): void
    {
        $channels = array_keys(SendChannel::options(['invosign' => 'InvoSign', 'sbz' => 'SBZ']));
        foreach ($channels as $channel) {
            $cols = SendChannel::decompose($channel);
            $back = SendChannel::fromColumns(
                $cols['einvoice_provider'],
                $cols['mydata_mode'],
                $cols['einvoice_provider_key'],
                $cols['einvoice_provider_mode'],
            );
            $this->assertSame($channel, $back, "round-trip drifted for channel [$channel]");
        }
    }

    public function test_options_include_provider_pairs(): void
    {
        $opts = SendChannel::options(['invosign' => 'InvoSign']);
        $this->assertArrayHasKey('mydata-production', $opts);
        $this->assertArrayHasKey('invosign-sandbox', $opts);
        $this->assertArrayHasKey('invosign-production', $opts);
        $this->assertSame('InvoSign — Δοκιμαστικό', $opts['invosign-sandbox']);
        $this->assertArrayHasKey('peppol', $opts);
    }

    public function test_predicates(): void
    {
        $this->assertTrue(SendChannel::isProvider('invosign-production'));
        $this->assertFalse(SendChannel::isProvider('mydata-production'));
        $this->assertTrue(SendChannel::isDirectMyData('mydata-production'));
        $this->assertFalse(SendChannel::isDirectMyData('mydata-off'));   // off ≠ filing
        $this->assertFalse(SendChannel::isDirectMyData('invosign-sandbox'));
        $this->assertSame('invosign', SendChannel::providerKey('invosign-sandbox'));
        $this->assertNull(SendChannel::providerKey('mydata-production'));
    }
}
