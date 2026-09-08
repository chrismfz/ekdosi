<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\PaymentMethod;
use App\Services\MyData\MyDataLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Επί Πιστώσει» must seed as a CREDIT term (due_days > 0) — with due_days=0 it
 * is treated as cash and every credit-term invoice shows «Εξοφλημένο» with no
 * payment (the ΤΙΜ385 phantom-paid bug).
 */
class PaymentMethodCreditTermSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_epi_pistosei_is_seeded_as_credit_term_others_as_cash(): void
    {
        $tenant = Company::create(['name' => 'PM', 'slug' => 'pm-'.uniqid(), 'country_code' => 'GR']);

        app(MyDataLookupSeeder::class)->seedPaymentMethods($tenant);

        $dueDays = fn (string $d) => (int) PaymentMethod::query()
            ->where('company_id', $tenant->id)->where('description', $d)->value('due_days');

        $this->assertSame(30, $dueDays('Επί Πιστώσει'), 'credit term must not settle at issue');
        $this->assertSame(0, $dueDays('Μετρητά'));
        $this->assertSame(0, $dueDays('POS/e-POS'));
    }
}
