<?php

namespace Tests\Feature\I18n;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\InvoiceType;
use App\Support\CustomerLanguage;
use App\Support\Pdf\PdfLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * i18n PDF slice: the statement / receipt / delivery-note PDFs route their chrome
 * labels through PdfLabels. The statement + receipt follow the LIVE customer
 * language (regenerated documents); the delivery note is FROZEN on its snapshotted
 * recipient country (a legal ΔΑ, like the invoice). el values stay byte-verbatim.
 */
class PdfLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'pdf-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
    }

    public function test_statement_pdf_localizes_to_the_customer_language(): void
    {
        $tenant = $this->tenant();
        $en = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme']);
        $en->forceFill(['language' => 'en'])->save();

        $html = $this->renderStatement($en, $tenant);
        $this->assertStringContainsString('Account statement', $html);
        $this->assertStringContainsString('Account activity', $html);
        $this->assertStringNotContainsString('Καρτέλα Πελάτη', $html);

        // A GR customer with no override → Greek (byte-verbatim), unchanged.
        $gr = Customer::create(['company_id' => $tenant->id, 'name' => 'Πελάτης', 'country' => 'Ελλάδα']);
        $elHtml = $this->renderStatement($gr, $tenant);
        $this->assertStringContainsString('Καρτέλα Πελάτη', $elHtml);
        $this->assertStringNotContainsString('Account statement', $elHtml);
    }

    private function renderStatement(Customer $customer, Company $tenant): string
    {
        return view('customers.statement-pdf', [
            'customer' => $customer,
            'company' => $tenant,
            'stats' => ['balance' => 0],
            'aging' => [],
            'yearly' => [],
            'ledger' => [],
            'generatedAt' => now(),
            'L' => PdfLabels::for(CustomerLanguage::forCustomer($customer)),
        ])->render();
    }

    public function test_statement_and_receipt_blades_render_without_an_explicit_L(): void
    {
        // The defensive `$L ?? …` default: a direct view() render with no 'L' must not
        // fatal on $L->lang(), and must resolve a sensible language on its own.
        $tenant = $this->tenant();
        $en = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme']);
        $en->forceFill(['language' => 'en'])->save();

        $statement = view('customers.statement-pdf', [
            'customer' => $en, 'company' => $tenant, 'stats' => ['balance' => 0],
            'aging' => [], 'yearly' => [], 'ledger' => [], 'generatedAt' => now(),
        ])->render(); // NOTE: no 'L'
        $this->assertStringContainsString('Account statement', $statement);

        $receipt = view('receipts.pdf', [
            'tenant' => $tenant, 'logoDataUri' => null, 'customerName' => 'Acme', 'customerAfm' => null,
            'reference' => null, 'date' => '01/01/2026', 'channel' => 'Μετρητά', 'method' => null,
            'transactionId' => null, 'lines' => [], 'total' => 0,
        ])->render(); // NOTE: no 'L' — GR tenant → el default
        $this->assertStringContainsString('ΑΠΟΔΕΙΞΗ ΕΙΣΠΡΑΞΗΣ', $receipt);
    }

    public function test_receipt_pdf_localizes_labels(): void
    {
        $tenant = $this->tenant();

        $en = view('receipts.pdf', [
            'tenant' => $tenant, 'logoDataUri' => null, 'customerName' => 'Acme', 'customerAfm' => null,
            'reference' => 'X1', 'date' => '01/01/2026', 'channel' => 'Cash', 'method' => null,
            'transactionId' => null, 'lines' => [], 'total' => 0, 'L' => PdfLabels::for('en'),
        ])->render();
        $this->assertStringContainsString('PAYMENT RECEIPT', $en);
        $this->assertStringContainsString('Total received', $en);
        $this->assertStringNotContainsString('ΑΠΟΔΕΙΞΗ ΕΙΣΠΡΑΞΗΣ', $en);

        $el = view('receipts.pdf', [
            'tenant' => $tenant, 'logoDataUri' => null, 'customerName' => 'Acme', 'customerAfm' => null,
            'reference' => 'X1', 'date' => '01/01/2026', 'channel' => 'Μετρητά', 'method' => null,
            'transactionId' => null, 'lines' => [], 'total' => 0, 'L' => PdfLabels::for('el'),
        ])->render();
        $this->assertStringContainsString('ΑΠΟΔΕΙΞΗ ΕΙΣΠΡΑΞΗΣ', $el);
        $this->assertStringNotContainsString('PAYMENT RECEIPT', $el);
    }

    public function test_delivery_note_language_is_frozen_on_the_recipient_country(): void
    {
        $tenant = $this->tenant();
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'DA', 'name' => 'Δελτίο Αποστολής',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);

        // Foreign recipient country → bilingual «EL / EN» (frozen on the note). The
        // view is rendered WITHOUT $L, so the blade's defensive default resolves the
        // SAME frozen language from recipient_country — proving the renderer path.
        $foreign = $this->makeNote($tenant, $type, ['recipient_country' => 'DE']);
        $bi = view('delivery-notes.pdf', ['note' => $foreign, 'tenant' => $tenant, 'qrDataUri' => null, 'logoDataUri' => null])->render();
        $this->assertStringContainsString('DRAFT — NOT SUBMITTED TO myDATA', $bi); // en half
        $this->assertStringContainsString('ΠΡΟΧΕΙΡΟ', $bi);                          // el half

        // GR recipient → Greek only, no English label leaks in.
        $gr = $this->makeNote($tenant, $type, ['recipient_country' => 'GR', 'invcode' => 'DA2', 'code' => 2]);
        $el = view('delivery-notes.pdf', ['note' => $gr, 'tenant' => $tenant, 'qrDataUri' => null, 'logoDataUri' => null])->render();
        $this->assertStringContainsString('ΠΡΟΧΕΙΡΟ', $el);
        $this->assertStringNotContainsString('DRAFT — NOT SUBMITTED TO myDATA', $el);
    }

    private function makeNote(Company $tenant, InvoiceType $type, array $overrides = []): DeliveryNote
    {
        $note = DeliveryNote::create(array_merge([
            'company_id' => $tenant->id, 'invcode' => 'DA1', 'code' => 1,
            'delivery_type_id' => $type->id, 'issued_at' => now(), 'mydata_type' => '9.3',
            'move_purpose' => 5, 'transport_type' => 1,
            'recipient_name' => 'Müller GmbH', 'recipient_afm' => 'DE811234567',
            'local_status' => 'draft',
        ], $overrides));

        DeliveryNoteLine::create([
            'company_id' => $tenant->id, 'delivery_note_id' => $note->id,
            'qty' => 3, 'measurement_unit' => 1, 'product_descr' => 'Κιβώτια',
        ]);

        return $note->fresh('lines');
    }
}
