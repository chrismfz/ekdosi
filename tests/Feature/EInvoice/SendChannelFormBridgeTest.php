<?php

namespace Tests\Feature\EInvoice;

use App\Filament\Support\SendChannelFormBridge;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The synthetic-field ↔ columns bridge for the P3 operator form: a flat channel +
 * labeled provider creds decompose into the real columns + encrypted config, and
 * secrets follow the "blank = keep stored" rule.
 */
class SendChannelFormBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dehydrate_provider_channel_sets_columns_and_config(): void
    {
        $data = SendChannelFormBridge::dehydrate([
            'send_channel' => 'invosign-production',
            'cfg_invosign_base_url' => 'https://api.invosign/x',
            'cfg_invosign_token' => 'secret-123',
        ], null);

        $this->assertSame('gr-provider', $data['einvoice_provider']);
        $this->assertSame('off', $data['mydata_mode']);
        $this->assertSame('invosign', $data['einvoice_provider_key']);
        $this->assertSame('production', $data['einvoice_provider_mode']);
        $this->assertSame(['base_url' => 'https://api.invosign/x', 'token' => 'secret-123'], $data['einvoice_provider_config']);
        // synthetics stripped
        $this->assertArrayNotHasKey('send_channel', $data);
        $this->assertArrayNotHasKey('cfg_invosign_token', $data);
    }

    public function test_dehydrate_mydata_channel_sets_mode_and_leaves_provider_columns(): void
    {
        $data = SendChannelFormBridge::dehydrate(['send_channel' => 'mydata-sandbox'], null);

        $this->assertSame('gr-mydata', $data['einvoice_provider']);
        $this->assertSame('sandbox', $data['mydata_mode']);
        $this->assertNull($data['einvoice_provider_key']);
        $this->assertArrayNotHasKey('einvoice_provider_config', $data); // untouched for myDATA
    }

    public function test_blank_secret_keeps_the_stored_value(): void
    {
        $record = Company::create([
            'name' => 't', 'slug' => 't-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox',
            'einvoice_provider_config' => ['base_url' => 'https://old', 'token' => 'OLD-SECRET'],
        ]);

        // Operator edits base_url, leaves token blank.
        $data = SendChannelFormBridge::dehydrate([
            'send_channel' => 'invosign-sandbox',
            'cfg_invosign_base_url' => 'https://new',
            'cfg_invosign_token' => '', // blank → keep
        ], $record);

        $this->assertSame('https://new', $data['einvoice_provider_config']['base_url']);
        $this->assertSame('OLD-SECRET', $data['einvoice_provider_config']['token']); // preserved
    }

    public function test_hydrate_round_trips_channel_and_nonsecret_fields_only(): void
    {
        $record = Company::create([
            'name' => 't', 'slug' => 't-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'production',
            'einvoice_provider_config' => ['base_url' => 'https://x', 'token' => 'SECRET'],
        ]);

        $data = SendChannelFormBridge::hydrate([], $record);

        $this->assertSame('invosign-production', $data['send_channel']);
        $this->assertSame('https://x', $data['cfg_invosign_base_url']); // non-secret pre-filled
        $this->assertNull($data['cfg_invosign_token']);                  // secret NOT pre-filled
    }

    public function test_hydrate_defaults_for_a_new_record(): void
    {
        $data = SendChannelFormBridge::hydrate([], null);
        $this->assertSame('mydata-off', $data['send_channel']);
    }
}
