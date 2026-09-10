<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «Να ξέρουμε ότι είναι εκεί»: a pre-filled masked input is visually identical to an
 * empty one, so the provider-credential fields state in words whether something is
 * actually stored — and, for a secret, WHICH one (a last-4 fingerprint).
 *
 * NOTE on what these tests do NOT assert: the form pre-fills the credential inputs with
 * the real values (that is what ->revealable() reveals), so the full secret IS in the
 * page by design. The fingerprint is an identification aid, not a confidentiality
 * control — do not add `assertDontSee($plaintext)` here and read it as proof of
 * non-disclosure: Livewire's assertDontSee strips the initial-data snapshot before
 * matching, so it would pass while the secret sits in `wire:snapshot`. Confidentiality
 * rests on CompanyResource being super_admin-only, which TenantStandardRolesTest pins
 * (company_admin/operator get no ViewAny:Company); Gate::before below is only so these
 * tests can reach the form at all.
 */
class ProviderCredentialVisibilityTest extends TestCase
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

    protected function tearDown(): void
    {
        // provider() sets the ambient tenant; don't leak it into later tests.
        Filament::setTenant(null);
        parent::tearDown();
    }

    private function provider(array $config = []): Company
    {
        $c = Company::create([
            'name' => 'Πάροχος', 'slug' => 'prov-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox',
            'einvoice_provider_config' => $config,
        ]);
        Filament::setTenant($c);

        return $c;
    }

    public function test_a_stored_secret_is_announced_and_fingerprinted(): void
    {
        $c = $this->provider(['token' => 'SECRET-TOKEN', 'base_url' => 'https://api.invosign.gr']);

        Livewire::test(EditCompany::class, ['record' => $c->getRouteKey()])
            ->assertSee('✓ Αποθηκευμένο')
            ->assertSee('••••OKEN');   // last 4 of SECRET-TOKEN, nothing more
    }

    public function test_a_missing_secret_says_so_instead_of_looking_identical(): void
    {
        $c = $this->provider([]);   // provider selected, no credentials yet

        Livewire::test(EditCompany::class, ['record' => $c->getRouteKey()])
            ->assertSee('⚠ Δεν έχει αποθηκευτεί');
    }

    /** @return array<string, array{0: string}> */
    public static function shortSecrets(): array
    {
        return [
            'four chars' => ['abcd'],        // "last 4" would be all of it
            'seven chars' => ['test123'],    // "last 4" would be 4 of 7
        ];
    }

    #[DataProvider('shortSecrets')]
    public function test_a_short_secret_is_fully_masked_rather_than_part_revealed(string $secret): void
    {
        $c = $this->provider(['token' => $secret]);

        Livewire::test(EditCompany::class, ['record' => $c->getRouteKey()])
            ->assertSee('(••••)')                             // masked outright…
            ->assertDontSee('••••'.mb_substr($secret, -4));   // …no tail emitted by the helper
    }

    public function test_it_does_not_vouch_for_another_providers_stored_value(): void
    {
        // base_url exists on more than one provider. After switching the channel, the
        // record still holds the OLD provider's blob — and dehydrate() starts the new
        // provider's blob empty, so announcing «αποθηκευμένο» here would be a lie.
        $c = $this->provider(['token' => 'SECRET-TOKEN', 'base_url' => 'https://old-provider.gr']);

        Livewire::test(EditCompany::class, ['record' => $c->getRouteKey()])
            ->set('data.send_channel', 'sbz-sandbox')
            ->assertSee('Δεν έχει αποθηκευτεί για αυτόν τον πάροχο')
            // …and it warns that saving DELETES the old provider's credentials, rather
            // than the softer «δεν μεταφέρονται» which understated an irreversible write.
            ->assertSee('διαγράφονται');
    }

    public function test_parking_on_a_mydata_channel_and_returning_keeps_the_credentials(): void
    {
        // REGRESSION GUARD (both directions). Blanking the non-current provider's
        // pre-fill fixed the carry-over below, but «Καθόλου» clears the provider key
        // while deliberately KEEPING the blob — so on the way back there was no owner
        // to match, the pre-fill came up empty and the save wrote `[]`, destroying an
        // encrypted token with no way to recover it.
        $c = $this->provider(['token' => 'SECRET-TOKEN', 'base_url' => 'https://api.invosign.gr']);

        Livewire::test(EditCompany::class, ['record' => $c->getRouteKey()])
            ->set('data.send_channel', 'mydata-off')
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditCompany::class, ['record' => $c->fresh()->getRouteKey()])
            ->set('data.send_channel', 'invosign-sandbox')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('SECRET-TOKEN', $c->fresh()->einvoice_provider_config['token'] ?? null);
    }

    public function test_the_parked_route_still_carries_shared_fields_across_known_residual(): void
    {
        // PINS TODAY'S BEHAVIOUR, not desired behaviour. Parking on a myDATA channel
        // clears the provider key while keeping the blob, and nothing records whose
        // blob it is — so on the way out to a DIFFERENT provider the shared field name
        // still carries. Fixing it needs the blob to record its owner (docs/BACKLOG.md);
        // pre-filling anyway is the deliberate trade, because the alternative destroys
        // tokens on the far more common «πάροχος → Καθόλου → ίδιος πάροχος» route.
        $c = $this->provider(['token' => 'SECRET-TOKEN', 'base_url' => 'https://old-provider.gr']);

        Livewire::test(EditCompany::class, ['record' => $c->getRouteKey()])
            ->set('data.send_channel', 'mydata-off')->call('save');
        Livewire::test(EditCompany::class, ['record' => $c->fresh()->getRouteKey()])
            ->set('data.send_channel', 'sbz-sandbox')->call('save');

        $this->assertSame(
            'https://old-provider.gr',
            $c->fresh()->einvoice_provider_config['base_url'] ?? null,
            'Αν αυτό ΑΛΛΑΞΕ, το residual έκλεισε — ενημέρωσε BACKLOG + σχόλιο στο bridge.'
        );
    }

    public function test_switching_provider_does_not_carry_the_old_endpoint_across(): void
    {
        // …and the SAVE must match what the helper promised. `base_url` exists on both
        // providers, so a flat pre-fill would silently point SBZ at InvoSign's endpoint.
        $c = $this->provider(['token' => 'SECRET-TOKEN', 'base_url' => 'https://old-provider.gr']);

        Livewire::test(EditCompany::class, ['record' => $c->getRouteKey()])
            ->set('data.send_channel', 'sbz-sandbox')
            ->call('save')
            ->assertHasNoFormErrors();

        $config = $c->fresh()->einvoice_provider_config ?? [];
        $this->assertArrayNotHasKey('base_url', $config, 'το endpoint του παλιού παρόχου μεταφέρθηκε');
        $this->assertArrayNotHasKey('token', $config, 'το μυστικό του παλιού παρόχου επέζησε της αλλαγής');
    }
}
