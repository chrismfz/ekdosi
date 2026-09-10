<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\MyDataPictureStats;
use App\Filament\Widgets\ProviderQuotaStats;
use App\Models\Company;
use App\Models\MyDataMark;
use App\Models\User;
use App\Services\MyData\MyDataVatPicture;
use App\Support\MyData\VatPictureCache;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PROV-009: the provider-quota card — the running provider quota read off the
 * latest provider mark's remaining_invoices, colour-banded by the low-quota
 * threshold. Provider tenants only.
 *
 * It renders in ONE of two places: as the 4th card of the myDATA ΦΠΑ row
 * (MyDataPictureStats) when the tenant can read myDATA, else on its own
 * «Πάροχος ΥΠΑΗΕΣ» widget. Never both, never neither.
 */
class ProviderQuotaStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Filament::setTenant() fires TenantSet($tenant, $user) — needs an auth user.
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x'),
        ]));
    }

    private function providerTenant(): Company
    {
        return Company::create([
            'name' => 'Prov', 'slug' => 'prov-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox',
        ]);
    }

    private function mark(Company $c, ?int $remaining): void
    {
        MyDataMark::create([
            'company_id' => $c->id, 'mark' => (string) random_int(1, 999999999),
            'mydata_action' => 'PROVIDER_INSERT', 'provider_key' => 'invosign',
            'remaining_invoices' => $remaining,
            'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);
    }

    public function test_visible_only_for_provider_tenants(): void
    {
        Filament::setTenant($this->providerTenant());
        $this->assertTrue(ProviderQuotaStats::canView());

        Filament::setTenant(Company::create([
            'name' => 'MD', 'slug' => 'md-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata',
        ]));
        $this->assertFalse(ProviderQuotaStats::canView());
    }

    public function test_shows_the_latest_quota_reading(): void
    {
        $c = $this->providerTenant();
        $this->mark($c, 500);   // older
        $this->mark($c, 988);   // latest
        Filament::setTenant($c);

        Livewire::test(ProviderQuotaStats::class)
            ->assertSee('988')
            ->assertSee('Επαρκές υπόλοιπο')
            ->assertDontSee('500');
    }

    public function test_low_quota_reads_as_a_warning_band(): void
    {
        $c = $this->providerTenant();
        $this->mark($c, 7);     // ≤ default threshold (50)
        Filament::setTenant($c);

        Livewire::test(ProviderQuotaStats::class)
            ->assertSee('7')
            ->assertSee('Χαμηλό');
    }

    /** A provider tenant that ALSO reads myDATA — the normal production setup. */
    private function readingProviderTenant(): Company
    {
        $c = $this->providerTenant();
        $c->update([
            'mydata_aade_id_sandbox' => 'SANDUSER',
            'mydata_subscription_key_sandbox' => 'SANDKEY',
        ]);

        return $c;
    }

    public function test_the_vat_row_carries_the_card_and_the_standalone_widget_stands_down(): void
    {
        // The ΦΠΑ row is visible, so the quota rides it instead of a lonely widget
        // lower down. Cache populated → this exercises the MAIN path (the card
        // appended after the three ΦΠΑ stats), not the not-yet-synced placeholder.
        $c = $this->readingProviderTenant();
        VatPictureCache::put($c, 'quarter', new MyDataVatPicture(
            outputNet: 1000, outputVat: 240, outputGross: 1240, outputCount: 4,
            inputNet: 500, inputVat: 120, inputGross: 620, inputCount: 2,
            fetchedAt: now()->toIso8601String(),
        ));
        $this->mark($c, 983);
        Filament::setTenant($c);

        $this->assertTrue(MyDataPictureStats::canView());
        $this->assertFalse(ProviderQuotaStats::canView());

        Livewire::test(MyDataPictureStats::class)
            ->assertSee('Τρίμηνο — Καθαρό ΦΠΑ')   // the ΦΠΑ stats really rendered…
            ->assertSee('983')                     // …and the quota came along as the 4th
            ->assertSee('Υπόλοιπο εκδόσεων')
            ->assertSee('Επαρκές υπόλοιπο');
    }

    public function test_the_card_keeps_the_headline_row_even_with_breakdown_extras(): void
    {
        // The PLACEMENT is the whole point of the change, so assert it: the quota card
        // must come before the «Τρίμηνο — …» breakdown extras, and the grid must stay
        // 4-across so the three ΦΠΑ cards + the quota really share the top row (and so
        // the ΦΠΑ cards don't re-flow just because a breakdown line exists).
        $c = $this->readingProviderTenant();
        VatPictureCache::put($c, 'quarter', new MyDataVatPicture(
            outputNet: 1000, outputVat: 240, outputGross: 1240, outputCount: 4,
            fetchedAt: now()->toIso8601String(),
            breakdown: ['payroll' => ['label' => 'Μισθοδοσία', 'net' => 800.0, 'vat' => 0.0, 'count' => 2]],
        ));
        $this->mark($c, 983);
        Filament::setTenant($c);

        $html = Livewire::test(MyDataPictureStats::class)->html();

        $quota = strpos($html, 'Πάροχος — Υπόλοιπο εκδόσεων');
        $breakdown = strpos($html, 'Τρίμηνο — Μισθοδοσία');
        $this->assertNotFalse($quota, 'Η κάρτα υπολοίπου παρόχου λείπει από τη σειρά ΦΠΑ.');
        $this->assertNotFalse($breakdown, 'Η αναλυτική κάρτα (μισθοδοσία) λείπει.');
        $this->assertLessThan($breakdown, $quota, 'Το υπόλοιπο παρόχου πρέπει να προηγείται των αναλυτικών καρτών.');

        $this->assertMatchesRegularExpression(
            '/--cols-cxl: repeat\(4,/', $html,
            'Το πλέγμα πρέπει να μένει 4 στηλών ώστε οι 3 κάρτες ΦΠΑ + το υπόλοιπο να είναι σε μία γραμμή.'
        );
    }

    public function test_the_card_shows_even_before_the_first_vat_snapshot(): void
    {
        // No VatPictureCache yet (scheduler never ran): the ΦΠΑ row falls back to its
        // «δεν συγχρονίστηκε» placeholder, but the provider quota is unrelated to the
        // AADE snapshot — so it must NOT disappear with it.
        $c = $this->readingProviderTenant();
        $this->mark($c, 983);
        Filament::setTenant($c);

        Livewire::test(MyDataPictureStats::class)
            ->assertSee('Δεν έχει συγχρονιστεί ακόμη')
            ->assertSee('983')
            ->assertSee('Υπόλοιπο εκδόσεων');
    }

    public function test_the_vat_row_has_no_quota_card_for_a_direct_mydata_tenant(): void
    {
        $c = Company::create([
            'name' => 'MD', 'slug' => 'md-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'mydata_aade_id_sandbox' => 'SANDUSER',
            'mydata_subscription_key_sandbox' => 'SANDKEY',
        ]);
        // Cache populated so this exercises the MAIN getStats() path, not the
        // placeholder branch — otherwise the absence proves nothing about the append.
        VatPictureCache::put($c, 'quarter', new MyDataVatPicture(
            outputNet: 1000, outputVat: 240, outputGross: 1240, outputCount: 4,
            fetchedAt: now()->toIso8601String(),
        ));
        Filament::setTenant($c);

        $this->assertTrue(MyDataPictureStats::canView());

        Livewire::test(MyDataPictureStats::class)
            ->assertSee('Τρίμηνο — Καθαρό ΦΠΑ')   // the ΦΠΑ stats really rendered…
            ->assertDontSee('Υπόλοιπο εκδόσεων'); // …and no provider card came with them
    }

    public function test_placeholder_before_the_first_provider_filing(): void
    {
        $c = $this->providerTenant();
        Filament::setTenant($c);

        Livewire::test(ProviderQuotaStats::class)
            ->assertSee('Καμία υποβολή μέσω παρόχου ακόμη');
    }
}
