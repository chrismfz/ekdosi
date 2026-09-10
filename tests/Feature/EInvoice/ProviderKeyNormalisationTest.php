<?php

namespace Tests\Feature\EInvoice;

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Support\SendChannelFormBridge;
use App\Models\Company;
use App\Models\User;
use App\Support\EInvoice\SendChannel;
use App\Support\GoLive\GoLiveCheckReport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `companies.einvoice_provider_key` is normalised ON WRITE (Company mutator), because
 * ProviderTransportRegistry::for() trims before the transport stamps
 * `mydata_marks.provider_key` — so an untrimmed key FILES fine while every raw-column
 * comparison disagrees with it.
 *
 * Measured symptoms of a `' invosign '` row (all reproduced below before the fix):
 * the Company form becomes unsaveable, the go-live gate reports a false «pass», and
 * the composed send-channel falls outside the dropdown's own options.
 */
class ProviderKeyNormalisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
    }

    private function provider(array $attrs = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Πάροχος', 'slug' => 'prov-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox',
        ], $attrs));
    }

    public function test_the_key_is_trimmed_on_write(): void
    {
        $c = $this->provider(['einvoice_provider_key' => '  invosign  ']);

        // Assert the COLUMN, not the accessor — the point is what lands on disk.
        $this->assertSame('invosign', DB::table('companies')->where('id', $c->id)->value('einvoice_provider_key'));
    }

    public function test_a_whitespace_only_key_becomes_null_not_a_configured_provider(): void
    {
        $c = $this->provider(['einvoice_provider_key' => '   ']);

        $this->assertNull(DB::table('companies')->where('id', $c->id)->value('einvoice_provider_key'));
    }

    public function test_the_composed_send_channel_stays_inside_the_dropdown_options(): void
    {
        // The regression that made the Company form unsaveable: ' invosign -sandbox'
        // is not an option, so the Select rendered blank and failed validation.
        $c = $this->provider(['einvoice_provider_key' => ' invosign ']);

        $channel = SendChannel::fromCompany($c);
        $this->assertSame('invosign-sandbox', $channel);
        $this->assertArrayHasKey($channel, SendChannel::options(['invosign' => 'InvoSign']));
    }

    public function test_a_row_written_before_the_mutator_makes_the_form_unsaveable(): void
    {
        // Documents the ACTUAL damage, so the migration below has something to fix.
        // Seeded with a raw UPDATE because Company::create() now trims.
        $c = $this->provider();
        DB::table('companies')->where('id', $c->id)->update(['einvoice_provider_key' => ' invosign ']);
        Filament::setTenant($c->fresh());

        Livewire::test(EditCompany::class, ['record' => $c->getRouteKey()])
            ->call('save')
            ->assertHasFormErrors(['send_channel']);
    }

    public function test_after_the_migration_that_same_row_saves_untouched(): void
    {
        $c = $this->provider([
            'einvoice_provider_config' => ['token' => 'SECRET-TOKEN', 'base_url' => 'https://api.invosign.gr'],
        ]);
        DB::table('companies')->where('id', $c->id)->update(['einvoice_provider_key' => ' invosign ']);

        (require base_path('database/migrations/2026_09_18_000001_normalise_einvoice_provider_key.php'))->up();

        Filament::setTenant($c->fresh());
        Livewire::test(EditCompany::class, ['record' => $c->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        // …and the stored credentials survived the save untouched.
        $this->assertSame('SECRET-TOKEN', $c->fresh()->einvoice_provider_config['token'] ?? null);
    }

    public function test_the_go_live_gate_fails_a_key_that_resolves_to_no_transport(): void
    {
        // Non-empty but unregistered → NullProviderTransport → cannot file at all.
        // `empty($key)` was false here, so the gate used to report a green «pass».
        $c = $this->provider(['einvoice_provider_key' => 'not-a-real-provider']);

        $gate = collect(app(GoLiveCheckReport::class)->build($c)['gates'] ?? [])
            ->firstWhere('key', 'provider_live');

        $this->assertSame('fail', $gate['status'] ?? null);
    }

    public function test_the_go_live_gate_passes_a_real_provider_in_production(): void
    {
        $c = $this->provider(['einvoice_provider_mode' => 'production']);

        $gate = collect(app(GoLiveCheckReport::class)->build($c)['gates'] ?? [])
            ->firstWhere('key', 'provider_live');

        $this->assertSame('pass', $gate['status'] ?? null);
    }

    public function test_the_go_live_gate_warns_for_a_provider_still_on_sandbox(): void
    {
        // Sandbox files into the provider's DEMO environment — the documents never
        // reach the real ΑΑΔΕ, so a cutover gate must not call that ready.
        $gate = collect(app(GoLiveCheckReport::class)->build($this->provider())['gates'] ?? [])
            ->firstWhere('key', 'provider_live');

        $this->assertSame('warn', $gate['status'] ?? null);
    }

    public function test_the_data_fix_migration_repairs_rows_written_before_the_mutator(): void
    {
        // The migration is the half that fixes PRODUCTION rows; the mutator only
        // guards new writes. Seed past the mutator with a raw UPDATE, then run it.
        $dirty = $this->provider();
        $blank = $this->provider();
        $clean = $this->provider();
        DB::table('companies')->where('id', $dirty->id)->update(['einvoice_provider_key' => '  invosign  ']);
        DB::table('companies')->where('id', $blank->id)->update(['einvoice_provider_key' => '   ']);

        (require base_path('database/migrations/2026_09_18_000001_normalise_einvoice_provider_key.php'))->up();

        $key = fn (Company $c) => DB::table('companies')->where('id', $c->id)->value('einvoice_provider_key');
        $this->assertSame('invosign', $key($dirty), 'το κλειδί με κενά δεν καθαρίστηκε');
        $this->assertNull($key($blank), 'το κενό κλειδί δεν έγινε NULL');
        $this->assertSame('invosign', $key($clean), 'το ήδη καθαρό κλειδί δεν πρέπει να πειραχτεί');
    }

    public function test_the_bridge_keeps_credentials_when_the_stored_key_needed_trimming(): void
    {
        // Belt for the bridge itself: even handed a record whose column was written
        // before the mutator existed, the seed that carries stored credentials across
        // a save must still recognise «same provider».
        $c = $this->provider(['einvoice_provider_config' => ['token' => 'SECRET-TOKEN']]);
        DB::table('companies')->where('id', $c->id)->update(['einvoice_provider_key' => ' invosign ']);

        $out = SendChannelFormBridge::dehydrate(
            ['send_channel' => 'invosign-sandbox'],   // credential inputs absent
            $c->fresh()
        );

        $this->assertSame('SECRET-TOKEN', $out['einvoice_provider_config']['token'] ?? null);
    }
}
