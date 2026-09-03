<?php

namespace Tests\Feature\MyData;

use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\VatCategory;
use App\Services\MyData\ConfigBackfiller;
use App\Support\MyData\Codes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigBackfillerTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private ConfigBackfiller $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Imported', 'slug' => 'imp-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->svc = app(ConfigBackfiller::class);
    }

    public function test_reasonless_zero_rate_category_is_backfilled_to_code_4(): void
    {
        $cat = VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => 'Άνευ ΦΠΑ 0%', 'rate' => 0,
        ]);
        $this->assertNull($cat->vat_exemption_category);

        $plan = $this->svc->plan($this->tenant);
        $this->assertCount(1, $plan['vat']);
        $this->assertSame(4, $plan['vat'][0]['to']);

        $this->svc->apply($this->tenant, writeExemption: true);
        $this->assertSame(4, (int) $cat->refresh()->vat_exemption_category);
    }

    public function test_exemption_default_is_opt_in(): void
    {
        // A legal §8.3 code is written ONLY with the explicit opt-in — a plain apply
        // reports the candidate but leaves the category NULL (the operator decides).
        $cat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '0%', 'rate' => 0]);

        $plan = $this->svc->apply($this->tenant); // no opt-in
        $this->assertCount(1, $plan['vat']);      // still reported
        $this->assertNull($cat->refresh()->vat_exemption_category); // but not written

        $this->svc->apply($this->tenant, writeExemption: true);
        $this->assertSame(4, (int) $cat->refresh()->vat_exemption_category);
    }

    public function test_existing_exemption_reason_is_never_overwritten(): void
    {
        $cat = VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => 'Εξαγωγή 0%', 'rate' => 0,
            'vat_exemption_category' => 8,
        ]);

        $plan = $this->svc->plan($this->tenant);
        $this->assertSame([], $plan['vat']);

        $this->svc->apply($this->tenant);
        $this->assertSame(8, (int) $cat->refresh()->vat_exemption_category); // untouched
    }

    public function test_positive_rate_categories_are_not_touched(): void
    {
        VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24]);

        $this->assertSame([], $this->svc->plan($this->tenant)['vat']);
    }

    public function test_payment_keyword_suggestions_map_to_the_right_type(): void
    {
        $cases = [
            'Μετρητοίς' => 3,
            'Πίστωση 10 ημερών' => 5,
            'Τραπεζική Κατάθεση' => 1,
            'Κάρτα (Stripe)' => 7,
            'PayPal' => 7,
            'Άμεσες Πληρωμές IRIS' => 8,
            'Επιταγή' => 4,
        ];
        $methods = [];
        foreach ($cases as $name => $_) {
            $methods[$name] = PaymentMethod::create([
                'company_id' => $this->tenant->id, 'description' => $name, 'due_days' => 0,
            ]);
        }

        $this->svc->apply($this->tenant);

        foreach ($cases as $name => $expected) {
            $this->assertSame(
                $expected,
                (int) $methods[$name]->refresh()->mydata_payment_type,
                "«{$name}» should map to §8.12 type {$expected}",
            );
        }
    }

    public function test_unmatched_payment_method_is_left_null_and_reported(): void
    {
        $method = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Συμψηφισμός', 'due_days' => 0,
        ]);

        $plan = $this->svc->apply($this->tenant);

        $this->assertSame([], $plan['payments']);
        $this->assertCount(1, $plan['payments_unmatched']);
        $this->assertNull($method->refresh()->mydata_payment_type); // never a blind type-3
    }

    public function test_apply_is_idempotent(): void
    {
        VatCategory::create(['company_id' => $this->tenant->id, 'description' => '0%', 'rate' => 0]);
        PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητοίς', 'due_days' => 0]);

        $first = $this->svc->apply($this->tenant, writeExemption: true);
        $this->assertCount(1, $first['vat']);
        $this->assertCount(1, $first['payments']);

        $second = $this->svc->apply($this->tenant, writeExemption: true); // re-run
        $this->assertSame([], $second['vat']);
        $this->assertSame([], $second['payments']);
    }

    public function test_backfill_only_touches_the_target_tenant(): void
    {
        $other = Company::create([
            'name' => 'Other', 'slug' => 'oth-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $otherCat = VatCategory::create(['company_id' => $other->id, 'description' => '0%', 'rate' => 0]);
        VatCategory::create(['company_id' => $this->tenant->id, 'description' => '0%', 'rate' => 0]);

        $this->svc->apply($this->tenant, writeExemption: true);

        $this->assertNull($otherCat->refresh()->vat_exemption_category); // other tenant untouched
    }

    public function test_non_aade_tenant_is_a_noop(): void
    {
        $ee = Company::create([
            'name' => 'Eesti', 'slug' => 'ee-'.uniqid(),
            'country_code' => 'EE', 'einvoice_provider' => 'ee-peppol', 'mydata_mode' => 'off',
        ]);
        $cat = VatCategory::create(['company_id' => $ee->id, 'description' => '0%', 'rate' => 0]);

        $plan = $this->svc->apply($ee);

        $this->assertSame([], $plan['vat']);
        $this->assertNull($cat->refresh()->vat_exemption_category); // no Greek §8.3 code on a PEPPOL tenant
    }

    public function test_multiple_zero_rate_categories_are_reported_ambiguous_not_set(): void
    {
        $a = VatCategory::create(['company_id' => $this->tenant->id, 'description' => 'Ενδοκοινοτικό 0%', 'rate' => 0]);
        $b = VatCategory::create(['company_id' => $this->tenant->id, 'description' => 'Εξαγωγή 0%', 'rate' => 0]);

        $plan = $this->svc->apply($this->tenant);

        $this->assertSame([], $plan['vat']);              // nothing auto-set
        $this->assertCount(2, $plan['vat_ambiguous']);    // both reported for the operator
        $this->assertNull($a->refresh()->vat_exemption_category);
        $this->assertNull($b->refresh()->vat_exemption_category);
    }

    public function test_word_matching_rejects_false_positive_substrings(): void
    {
        // 'pos' must not match inside «deposit» / «postal» (whole-word Latin match).
        $this->assertNull($this->svc->suggestPaymentType('Deposit'));
        $this->assertNull($this->svc->suggestPaymentType('Postal order'));
    }

    public function test_home_banking_maps_to_web_banking_not_generic_bank(): void
    {
        // 'banking' must win over the generic 'bank' → type 6, not 1.
        $this->assertSame(6, $this->svc->suggestPaymentType('Home Banking')['code']);
        $this->assertSame(1, $this->svc->suggestPaymentType('Τραπεζική Κατάθεση')['code']);
    }

    public function test_credit_card_maps_to_card_not_on_credit_terms(): void
    {
        // 'card' is ordered before 'credit', so «Credit Card» → type 7, not 5.
        $this->assertSame(7, $this->svc->suggestPaymentType('Credit Card')['code']);
    }

    public function test_greek_card_stem_matches_inflected_forms(): void
    {
        // The stem «καρτ» catches the plural/genitive, not just «Κάρτα».
        $this->assertSame(7, $this->svc->suggestPaymentType('Κάρτες')['code']);
        $this->assertSame(7, $this->svc->suggestPaymentType('Πληρωμή με κάρτα')['code']);
    }

    public function test_vat_category_8_records_without_vat_are_skipped(): void
    {
        // myDATA vatCategory 8 (εγγραφές χωρίς ΦΠΑ) legitimately needs no §8.3 reason.
        $cat = VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => 'Χωρίς ΦΠΑ', 'rate' => 0,
            'mydata_vat_category' => 8,
        ]);

        $plan = $this->svc->plan($this->tenant);
        $this->assertSame([], $plan['vat']);
        $this->assertSame([], $plan['vat_ambiguous']);

        $this->svc->apply($this->tenant, writeExemption: true);
        $this->assertNull($cat->refresh()->vat_exemption_category);
    }

    public function test_zero_rate_default_is_derived_from_the_seed(): void
    {
        $this->assertSame(
            (int) Codes::ZERO_RATE_SEED[0]['code'],
            ConfigBackfiller::zeroRateDefaultExemption(),
        );
    }
}
