<?php

namespace Tests\Feature\I18n;

use App\Actions\ConvertQuoteToInvoice;
use App\Actions\IssueCreditNote;
use App\Actions\StageServiceRenewal;
use App\Enums\BillingCycle;
use App\Enums\QuoteStatus;
use App\Enums\ServiceContractStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\ServiceContract;
use App\Models\VatCategory;
use App\Services\QuoteNumberer;
use App\Services\RecomputeInvoiceTotals;
use App\Services\WhmcsInbox\WhmcsInvoiceMapper;
use App\Support\CustomerLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * i18n stamp-at-issue: the customer's explicit PDF-language preference is frozen
 * onto documents at issue, and inherited across derived documents. Covers
 * CustomerLanguage::stampForCustomer() and the four issue paths that call it (or
 * mirror an already-frozen language).
 */
class LanguageStampTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(): Company
    {
        return Company::create([
            'name' => 't',
            'slug' => 't-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);
    }

    public function test_stamp_for_customer_returns_explicit_preference_only(): void
    {
        $company = $this->makeCompany();

        $en = Customer::create(['company_id' => $company->id, 'name' => 'EN', 'language' => 'en']);
        $this->assertSame('en', CustomerLanguage::stampForCustomer($en));

        $none = Customer::create(['company_id' => $company->id, 'name' => 'None', 'language' => null]);
        $this->assertNull(CustomerLanguage::stampForCustomer($none));

        $both = Customer::create(['company_id' => $company->id, 'name' => 'Both', 'language' => 'both']);
        $this->assertSame('both', CustomerLanguage::stampForCustomer($both));

        $bad = Customer::create(['company_id' => $company->id, 'name' => 'Bad', 'language' => 'fr']);
        $this->assertNull(CustomerLanguage::stampForCustomer($bad), 'an invalid language is not a valid stamp');

        $this->assertNull(CustomerLanguage::stampForCustomer(null));
    }

    public function test_stamp_for_document_respects_the_operator_override(): void
    {
        $company = $this->makeCompany();
        $enCustomer = Customer::create(['company_id' => $company->id, 'name' => 'EN', 'language' => 'en']);

        // An explicit «Γλώσσα PDF» choice ALWAYS wins over the customer's preference —
        // this is the guard CreateInvoice/CreateQuote rely on (never overwrite it).
        $this->assertSame('el', CustomerLanguage::stampForDocument('el', $enCustomer));
        $this->assertSame('both', CustomerLanguage::stampForDocument('both', $enCustomer));

        // On auto (null / blank / invalid), fall back to the customer's explicit preference.
        $this->assertSame('en', CustomerLanguage::stampForDocument(null, $enCustomer));
        $this->assertSame('en', CustomerLanguage::stampForDocument('', $enCustomer));
        $this->assertSame('en', CustomerLanguage::stampForDocument('fr', $enCustomer));

        // No explicit choice and no customer preference → null (country-auto).
        $noPref = Customer::create(['company_id' => $company->id, 'name' => 'None', 'language' => null]);
        $this->assertNull(CustomerLanguage::stampForDocument(null, $noPref));
        $this->assertNull(CustomerLanguage::stampForDocument(null, null));
    }

    public function test_whmcs_mapper_header_stamps_customer_language(): void
    {
        $tenant = $this->makeCompany();
        VatCategory::create([
            'company_id' => $tenant->id, 'name' => 'ΦΠΑ 24%', 'rate' => 24.00, 'is_default' => true,
        ]);
        $pm = PaymentMethod::create([
            'company_id' => $tenant->id, 'name' => 'Bank', 'due_days' => 0, 'is_active' => true,
        ]);
        $invoiceType = InvoiceType::create([
            'company_id' => $tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ',
            'invcount' => 0, 'payment_method_id' => $pm->id,
        ]);

        $payload = [
            'invoiceid' => 8001,
            'date' => '2026-05-20',
            'total' => '124.00',
            'items' => ['item' => [
                ['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1'],
            ]],
        ];

        // Explicit 'en' → stamped onto the header.
        $enCustomer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'ΑΚΜΕ ΑΕ', 'afm' => '123456789',
            'country' => 'GR', 'language' => 'en',
        ]);
        $pendingEn = PendingWhmcsInvoice::create([
            'company_id' => $tenant->id, 'whmcs_invoice_id' => 8001, 'payload' => $payload,
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
        $headerEn = app(WhmcsInvoiceMapper::class)
            ->map($tenant, $pendingEn, $enCustomer, $invoiceType)['header'];
        $this->assertSame('en', $headerEn['language']);

        // No explicit preference → null (document stays on its country default).
        $noneCustomer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'ΒΗΤΑ ΕΠΕ', 'afm' => '987654321',
            'country' => 'GR', 'language' => null,
        ]);
        $pendingNone = PendingWhmcsInvoice::create([
            'company_id' => $tenant->id, 'whmcs_invoice_id' => 8002,
            'payload' => array_merge($payload, ['invoiceid' => 8002]),
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
        $headerNone = app(WhmcsInvoiceMapper::class)
            ->map($tenant, $pendingNone, $noneCustomer, $invoiceType)['header'];
        $this->assertNull($headerNone['language']);
    }

    public function test_credit_note_inherits_original_language(): void
    {
        $tenant = $this->makeCompany();
        $credit = PaymentMethod::create([
            'company_id' => $tenant->id, 'description' => 'Πίστωση', 'due_days' => 30,
        ]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1,
        ]);
        $creditType = InvoiceType::create([
            'company_id' => $tenant->id, 'name' => 'Πιστωτικό', 'code' => 'ΠΤ',
            'invcount' => 1, 'is_credit' => true,
        ]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'C']);

        $makeOriginal = function () use ($tenant, $type, $customer, $credit): Invoice {
            $inv = Invoice::create([
                'company_id' => $tenant->id, 'invcode' => 'ΤΠΥ'.uniqid(), 'code' => 1,
                'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
                'payment_method_id' => $credit->id, 'issued_at' => '2026-05-10 10:00:00',
                'mydata_state' => 'VALID',
            ]);
            InvoiceLine::create([
                'company_id' => $tenant->id, 'invoice_id' => $inv->id,
                'qty' => 2, 'price_per_item' => 50, 'vat_percent' => 24, 'product_descr' => 'Widget',
            ]);

            return app(RecomputeInvoiceTotals::class)($inv);
        };

        // Original stamped 'en' → the credit note mirrors it.
        $originalEn = $makeOriginal();
        $originalEn->forceFill(['language' => 'en'])->save();
        $lineEn = $originalEn->fresh(['lines'])->lines->first();
        $creditEn = app(IssueCreditNote::class)($originalEn->fresh(['lines']), $creditType, [
            ['line_id' => $lineEn->id, 'qty' => 2],
        ]);
        $this->assertSame('en', $creditEn->language);

        // Original with no frozen language → the credit note has none either.
        $originalNone = $makeOriginal();
        $this->assertNull($originalNone->language);
        $lineNone = $originalNone->fresh(['lines'])->lines->first();
        $creditNone = app(IssueCreditNote::class)($originalNone->fresh(['lines']), $creditType, [
            ['line_id' => $lineNone->id, 'qty' => 2],
        ]);
        $this->assertNull($creditNone->language);
    }

    public function test_converted_quote_invoice_inherits_quote_language(): void
    {
        $tenant = $this->makeCompany();
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '123456789',
        ]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'name' => 'Τιμολόγιο', 'code' => 'TPY',
            'invcount' => 1, 'show_on_menu' => true,
        ]);

        $quote = DB::transaction(fn () => Quote::create([
            'company_id' => $tenant->id,
            'code' => app(QuoteNumberer::class)->allocate($tenant),
            'issued_at' => now(),
            'status' => QuoteStatus::Accepted,
            'customer_id' => $customer->id,
            'company_name' => 'Πελάτης ΑΕ',
            'vat_no' => '123456789',
            'language' => 'en',
        ]));
        QuoteLine::create([
            'quote_id' => $quote->id, 'product_descr' => 'Υπηρεσία υποστήριξης',
            'qty' => 1, 'price_per_item' => 500, 'vat_percent' => 24,
        ]);

        $invoice = app(ConvertQuoteToInvoice::class)($quote->fresh(), $type);

        $this->assertSame('en', $invoice->language);
    }

    public function test_service_renewal_stamps_customer_language(): void
    {
        $tenant = $this->makeCompany();
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τιμολόγιο Παροχής',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        PaymentMethod::create(['company_id' => $tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);

        $makeContract = function (Customer $customer) use ($tenant, $type): ServiceContract {
            return ServiceContract::create([
                'company_id' => $tenant->id,
                'customer_id' => $customer->id,
                'invoice_type_id' => $type->id,
                'description' => 'Hosting Personal5',
                'billing_cycle' => BillingCycle::Annual->value,
                'amount' => 100,
                'vat_percent' => 24,
                'status' => ServiceContractStatus::Active->value,
                'start_date' => Carbon::today()->subYear(),
                'next_due_date' => Carbon::yesterday(),
            ]);
        };

        // Case A: customer prefers 'en' → the staged draft is stamped 'en'.
        $enCustomer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Πελάτης EN', 'afm' => '123456789',
            'address1' => 'Οδός 1', 'city' => 'Αθήνα', 'postcode' => '11111', 'country' => 'GR',
            'language' => 'en',
        ]);
        $invoiceEn = app(StageServiceRenewal::class)($makeContract($enCustomer));
        $this->assertSame('en', $invoiceEn->language);

        // Case B: no explicit preference → the staged draft has none (null → auto).
        $noneCustomer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Πελάτης None', 'afm' => '987654321',
            'address1' => 'Οδός 2', 'city' => 'Αθήνα', 'postcode' => '22222', 'country' => 'GR',
            'language' => null,
        ]);
        $invoiceNone = app(StageServiceRenewal::class)($makeContract($noneCustomer));
        $this->assertNull($invoiceNone->language);
    }
}
