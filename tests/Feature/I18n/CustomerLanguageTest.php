<?php

namespace Tests\Feature\I18n;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\Invoice;
use App\Models\Quote;
use App\Support\CustomerLanguage;
use Tests\TestCase;

/**
 * The one language resolver (i18n Slice 0). Pure attribute/relation logic — no DB
 * (models are `make()`d and relations set in-memory), so it also runs on CI/sqlite.
 * Chain: explicit choice → recipient country (GR→el, foreign→both) → company default
 * → el. The PDF is NOT resolved here (it stays frozen on the document); the resolver
 * drives the portal chrome (forUi) and the email language (forDocumentMail).
 */
class CustomerLanguageTest extends TestCase
{
    private function customer(array $attrs = [], ?Company $company = null): Customer
    {
        $customer = Customer::make($attrs);
        $customer->setRelation('company', $company);

        return $customer;
    }

    private function invoice(array $attrs, ?Customer $customer, ?Company $company = null): Invoice
    {
        $invoice = Invoice::make($attrs);
        $invoice->setRelation('customer', $customer);
        $invoice->setRelation('company', $company);

        return $invoice;
    }

    // ---- forUi (portal chrome: el/en only) --------------------------------

    public function test_for_ui_uses_stored_locale_when_valid(): void
    {
        $this->assertSame('en', CustomerLanguage::forUi(new CustomerUser(['locale' => 'en'])));
        $this->assertSame('el', CustomerLanguage::forUi(new CustomerUser(['locale' => 'el'])));
    }

    public function test_for_ui_falls_back_to_app_locale_when_missing_or_invalid(): void
    {
        config(['app.locale' => 'el']);

        // null user, null locale, and 'both' (not a UI language) all fall back.
        $this->assertSame('el', CustomerLanguage::forUi(null));
        $this->assertSame('el', CustomerLanguage::forUi(new CustomerUser([])));
        $this->assertSame('el', CustomerLanguage::forUi(new CustomerUser(['locale' => 'both'])));
    }

    // ---- forCustomer -------------------------------------------------------

    public function test_for_customer_explicit_language_wins_over_country(): void
    {
        // A Greek-country customer explicitly flagged English → English.
        $c = $this->customer(['language' => 'en', 'country_code' => 'GR']);
        $this->assertSame('en', CustomerLanguage::forCustomer($c));
    }

    public function test_for_customer_derives_from_country_when_no_explicit_choice(): void
    {
        $this->assertSame('el', CustomerLanguage::forCustomer($this->customer(['country_code' => 'GR'])));
        $this->assertSame('both', CustomerLanguage::forCustomer($this->customer(['country_code' => 'DE'])));
    }

    public function test_for_customer_uses_free_text_country_when_code_cache_is_null(): void
    {
        // A pre-MYD-011 customer: country_code cache null, free-text country set.
        // isoCountryCode() normalises it live, so we must NOT fall through to the
        // company default as if the country were unknown.
        $tenant = Company::make(['default_language' => 'en']);
        $c = $this->customer(['country_code' => null, 'country' => 'DE'], $tenant);
        $this->assertSame('both', CustomerLanguage::forCustomer($c));
    }

    public function test_for_customer_falls_back_to_company_default_when_country_unknown(): void
    {
        $tenant = Company::make(['default_language' => 'en']);
        $c = $this->customer(['country_code' => null], $tenant);
        $this->assertSame('en', CustomerLanguage::forCustomer($c));
    }

    public function test_for_customer_falls_back_to_greek_when_nothing_usable(): void
    {
        // No language, no country, company default null/invalid → el.
        $this->assertSame('el', CustomerLanguage::forCustomer($this->customer([], Company::make([]))));
        $this->assertSame('el', CustomerLanguage::forCustomer($this->customer([])));
    }

    // ---- forDocumentMail (tracks the recipient customer; both → en) -------

    public function test_for_document_mail_tracks_the_customer_not_the_pdf_override(): void
    {
        // The «Γλώσσα PDF» override is PDF-only: the email tracks the recipient's
        // own communication preference. Customer wants Greek → Greek email, even
        // though the operator set the PDF to English.
        $c = $this->customer(['language' => 'el', 'country_code' => 'GR']);
        $inv = $this->invoice(['language' => 'en', 'country' => 'GR'], $c);
        $this->assertSame('el', CustomerLanguage::forDocumentMail($inv));
    }

    public function test_for_document_mail_collapses_bilingual_to_english(): void
    {
        // Foreign customer → forCustomer yields 'both'; an email body is single-language.
        $c = $this->customer(['country_code' => 'FR']);
        $inv = $this->invoice(['language' => null, 'country' => 'FR'], $c);
        $this->assertSame('en', CustomerLanguage::forDocumentMail($inv));
    }

    public function test_for_document_mail_passes_through_single_language(): void
    {
        $c = $this->customer(['country_code' => 'GR']);
        $inv = $this->invoice(['language' => null, 'country' => 'GR'], $c);
        $this->assertSame('el', CustomerLanguage::forDocumentMail($inv));
    }

    public function test_for_document_mail_falls_back_to_snapshot_without_a_customer(): void
    {
        // No linked customer → use the document's own snapshotted country.
        $inv = $this->invoice(['language' => null, 'country' => 'DE'], null);
        $this->assertSame('en', CustomerLanguage::forDocumentMail($inv));  // both → en

        $invGr = $this->invoice(['language' => null, 'country' => 'GR'], null);
        $this->assertSame('el', CustomerLanguage::forDocumentMail($invGr));
    }

    public function test_for_document_mail_works_for_quotes_too(): void
    {
        $c = $this->customer(['language' => 'en']);
        $quote = Quote::make(['language' => null, 'country' => 'GR']);
        $quote->setRelation('customer', $c);
        $quote->setRelation('company', null);
        $this->assertSame('en', CustomerLanguage::forDocumentMail($quote));
    }
}
