<?php

namespace Tests\Feature\Assistant;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Services\Assistant\Tools\InvoiceGetTool;
use App\Services\Assistant\Tools\SearchInvoicesTool;
use App\Services\Assistant\Tools\WhmcsInboxListTool;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The invoice line/notes + inbox-detail MCP/assistant tools (invoice_get,
 * search_invoices, whmcs_inbox_list). Dual-surface — the MCP adapter is enforced
 * by McpAssistantParityTest; here we exercise the tenant-scoped tool logic,
 * especially the cut-over duplicate audit that prevents double-issuing.
 */
class InvoiceInboxToolsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->tenant = Company::create([
            'name' => 'Tools OE', 'slug' => 'tools-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);

        $this->method = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);
    }

    private function customer(string $name = 'Πελ'): Customer
    {
        return Customer::create([
            'company_id' => $this->tenant->id, 'name' => $name.' '.uniqid(),
            'afm' => (string) random_int(100000000, 999999999),
        ]);
    }

    private function invoice(Customer $c, float $gross, ?int $whmcsId = null): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'TPY'.random_int(1000, 9999).uniqid(),
            'code' => random_int(1, 99999), 'invoice_type_id' => $this->type->id,
            'payment_method_id' => $this->method->id, 'customer_id' => $c->id,
            'issued_at' => now(), 'local_status' => 'active',
            'net_total' => round($gross / 1.24, 2), 'gross_total' => $gross, 'header_discount_percent' => 0,
        ]);
        if ($whmcsId !== null) {
            // whmcs_invoice_id is guarded (set by the writeback/backfill, not
            // fillable) — assign it directly, as the bridge does via forceFill.
            $inv->whmcs_invoice_id = $whmcsId;
            $inv->save();
        }

        return $inv;
    }

    private function line(Invoice $inv, string $descr, float $price): InvoiceLine
    {
        return InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => $price, 'vat_percent' => 24, 'product_descr' => $descr,
        ]);
    }

    private function pending(int $whmcsId, Customer $c, float $total, string $datepaid, string $descr, string $status = PendingWhmcsInvoice::STATUS_PENDING_REVIEW): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'source' => PendingWhmcsInvoice::SOURCE_WHMCS,
            'whmcs_invoice_id' => $whmcsId, 'whmcs_userid' => 1, 'customer_id' => $c->id, 'status' => $status,
            'match_reason' => 'test-match',
            'payload' => [
                'invoiceid' => $whmcsId, 'total' => (string) $total, 'datepaid' => $datepaid, 'status' => 'Paid',
                'items' => ['item' => [['description' => $descr, 'amount' => (string) $total]]],
            ],
        ]);
    }

    // ---- invoice_get -------------------------------------------------------

    public function test_invoice_get_returns_lines_notes_and_whmcs_id(): void
    {
        $c = $this->customer('Σωτήρης');
        $inv = $this->invoice($c, 360.84, whmcsId: 32144);
        $this->line($inv, 'Business20 - redmonkey.gr', 291.00);
        $inv->internalNotes()->create(['company_id' => $this->tenant->id, 'body' => 'Δεμένο με WHMCS #32144', 'is_pinned' => false]);

        $byCode = (new InvoiceGetTool)->run($this->tenant, ['invoice' => $inv->invcode]);
        $this->assertTrue($byCode['found']);
        $this->assertSame(32144, $byCode['whmcs_invoice_id']);
        $this->assertCount(1, $byCode['lines']);
        $this->assertSame('Business20 - redmonkey.gr', $byCode['lines'][0]['description']);
        $this->assertSame('Δεμένο με WHMCS #32144', $byCode['internal_notes'][0]['body']);
        $this->assertEqualsWithDelta(360.84, $byCode['totals']['gross'], 0.001);

        // Same invoice resolvable by its WHMCS id.
        $byWhmcs = (new InvoiceGetTool)->run($this->tenant, ['whmcs_invoice_id' => 32144]);
        $this->assertSame($inv->id, $byWhmcs['id']);
    }

    public function test_invoice_get_reports_not_found(): void
    {
        $res = (new InvoiceGetTool)->run($this->tenant, ['invoice' => 'ΔΕΝ-ΥΠΑΡΧΕΙ']);
        $this->assertFalse($res['found']);
    }

    public function test_invoice_get_prefers_invcode_over_a_colliding_surrogate_id(): void
    {
        $a = $this->invoice($this->customer('A'), 124);
        // B's PRINTED invcode equals A's surrogate id — a lookup of that value must
        // return B (invcode match), never A (id match).
        $b = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => (string) $a->id, 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'payment_method_id' => $this->method->id,
            'customer_id' => $this->customer('B')->id, 'issued_at' => now(), 'local_status' => 'active',
            'net_total' => 100, 'gross_total' => 124, 'header_discount_percent' => 0,
        ]);

        $res = (new InvoiceGetTool)->run($this->tenant, ['invoice' => (string) $a->id]);
        $this->assertSame($b->id, $res['id']);
    }

    // ---- search_invoices ---------------------------------------------------

    public function test_search_invoices_matches_line_text_and_counts(): void
    {
        $red = $this->invoice($this->customer('A'), 124);
        $this->line($red, 'Ανανέωση redmonkey.gr', 100);
        $other = $this->invoice($this->customer('B'), 124);
        $this->line($other, 'Ανανέωση example.com', 100);

        $res = (new SearchInvoicesTool)->run($this->tenant, ['query' => 'redmonkey.gr', 'in' => 'lines']);
        $this->assertSame(1, $res['count']);
        $this->assertSame($red->invcode, $res['invoices'][0]['code']);
        $this->assertStringContainsString('redmonkey.gr', $res['invoices'][0]['matched'][0]);
    }

    public function test_search_invoices_matches_note_text(): void
    {
        $inv = $this->invoice($this->customer('C'), 124);
        $this->line($inv, 'Υπηρεσία', 100);
        $inv->internalNotes()->create(['company_id' => $this->tenant->id, 'body' => 'Πηγή: WHMCS #98765', 'is_pinned' => false]);

        $res = (new SearchInvoicesTool)->run($this->tenant, ['query' => '98765', 'in' => 'notes']);
        $this->assertSame(1, $res['count']);
        $this->assertSame($inv->invcode, $res['invoices'][0]['code']);
    }

    public function test_search_invoices_defaults_to_lines_and_does_not_match_everything(): void
    {
        $hit = $this->invoice($this->customer('Hit'), 124);
        $this->line($hit, 'Ανανέωση redmonkey.gr', 100);
        $miss = $this->invoice($this->customer('Miss'), 124);
        $this->line($miss, 'Άσχετη υπηρεσία', 100);

        // `in` omitted → must default to lines, NOT return every live invoice.
        $res = (new SearchInvoicesTool)->run($this->tenant, ['query' => 'redmonkey.gr']);
        $this->assertSame(1, $res['count']);
        $this->assertSame($hit->invcode, $res['invoices'][0]['code']);
    }

    public function test_search_invoices_escapes_like_wildcards(): void
    {
        $lit = $this->invoice($this->customer('Lit'), 124);
        $this->line($lit, 'Plan 100% off', 100);
        $other = $this->invoice($this->customer('Oth'), 124);
        $this->line($other, 'Plan 100 200', 100);

        // '100%' must match LITERALLY (the % is data, not a wildcard) → only the
        // first line, not everything containing '100'.
        $res = (new SearchInvoicesTool)->run($this->tenant, ['query' => '100%', 'in' => 'lines']);
        $this->assertSame(1, $res['count']);
        $this->assertSame($lit->invcode, $res['invoices'][0]['code']);
    }

    public function test_search_invoices_by_whmcs_id_finds_a_cancelled_invoice(): void
    {
        $inv = $this->invoice($this->customer('Cancel'), 124, whmcsId: 71000);
        $inv->forceFill(['local_status' => 'cancelled'])->save();

        // Deterministic whmcs-id lookup ignores the live() filter (parity with
        // invoice_get) — a cancelled bound invoice must still surface.
        $res = (new SearchInvoicesTool)->run($this->tenant, ['whmcs_invoice_id' => 71000]);
        $this->assertSame(1, $res['count']);
        $this->assertSame($inv->invcode, $res['invoices'][0]['code']);
    }

    public function test_search_invoices_whmcs_id_lookup_ignores_a_stray_query(): void
    {
        $inv = $this->invoice($this->customer('W'), 124, whmcsId: 92000);
        $this->line($inv, 'Κάτι άσχετο', 100);

        // whmcs id + a query absent from the lines → the deterministic link still
        // resolves (the id is the filter; the text is ignored).
        $res = (new SearchInvoicesTool)->run($this->tenant, ['whmcs_invoice_id' => 92000, 'query' => 'ΔΕΝ-ΥΠΑΡΧΕΙ-ΠΟΥΘΕΝΑ']);
        $this->assertSame(1, $res['count']);
        $this->assertSame($inv->invcode, $res['invoices'][0]['code']);
    }

    // ---- whmcs_inbox_list --------------------------------------------------

    public function test_inbox_list_suggests_file_for_new_and_archive_for_duplicates(): void
    {
        $this->tenant->update(['whmcs_invoice_min_date' => '2026-09-14']);

        // A) paid after cut-over, no duplicate → file
        $new = $this->pending(40001, $this->customer('New'), 100.00, '2026-09-15 10:00:00', 'Νέα υπηρεσία');

        // B) an ekdosi invoice already carries this whmcs id → archive
        $dupCust = $this->customer('Dup');
        $this->invoice($dupCust, 124, whmcsId: 40002);
        $this->pending(40002, $dupCust, 124.00, '2026-08-20 10:00:00', 'Παλιά υπηρεσία');

        // C) legacy AUTO_INVOICE_LOG has a row for this whmcs id → archive
        $legCust = $this->customer('Legacy');
        DB::table('whmcs_invoice_log')->insert([
            'company_id' => $this->tenant->id, 'legacy_id' => 777, 'whmcs_invoice_id' => 40003,
            'invoice_id' => null, 'message' => 'Legacy invoiced APY123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->pending(40003, $legCust, 50.00, '2026-08-10 10:00:00', 'Legacy υπηρεσία');

        $res = (new WhmcsInboxListTool)->run($this->tenant, ['status' => 'open', 'limit' => 50]);
        $this->assertSame('2026-09-14', $res['cutover']);
        $rows = collect($res['rows'])->keyBy('whmcs_invoice_id');

        $this->assertSame('file', $rows[40001]['suggestion']);
        $this->assertSame('archive', $rows[40002]['suggestion']);
        $this->assertNotNull($rows[40002]['duplicate']['existing_ekdosi_invoices']);
        $this->assertSame('archive', $rows[40003]['suggestion']);
        $this->assertSame(1, $rows[40003]['duplicate']['legacy_log_hits']);
    }

    public function test_inbox_list_ignores_a_cancelled_existing_invoice(): void
    {
        $this->tenant->update(['whmcs_invoice_min_date' => '2026-09-14']);
        $cust = $this->customer('Recut');
        // An ekdosi invoice for this whmcs id exists but was CANCELLED (void) —
        // it must NOT read as «already invoiced»; the row still needs re-filing.
        $this->invoice($cust, 90, whmcsId: 80001)->forceFill(['local_status' => 'cancelled'])->save();
        $this->pending(80001, $cust, 90.00, '2026-09-15 10:00:00', 'Χρειάζεται επανέκδοση');

        $res = (new WhmcsInboxListTool)->run($this->tenant, ['status' => 'open']);
        $row = collect($res['rows'])->firstWhere('whmcs_invoice_id', 80001);
        $this->assertNull($row['duplicate']['existing_ekdosi_invoices']);
        $this->assertSame('file', $row['suggestion']);
    }

    public function test_inbox_list_flags_mis_archived_rows(): void
    {
        $this->tenant->update(['whmcs_invoice_min_date' => '2026-09-14']);
        // Archived, but paid AFTER cut-over and no duplicate → should have been filed.
        $this->pending(50001, $this->customer('Oops'), 80.00, '2026-09-16 09:00:00', 'Κατά λάθος αρχειοθετημένο',
            status: PendingWhmcsInvoice::STATUS_REJECTED);

        $res = (new WhmcsInboxListTool)->run($this->tenant, ['status' => 'archived']);
        $this->assertCount(1, $res['rows']);
        $this->assertTrue($res['rows'][0]['mis_archived']);
        $this->assertSame('file', $res['rows'][0]['suggestion']);
    }

    public function test_inbox_list_treats_unpaid_placeholder_date_as_review(): void
    {
        $this->tenant->update(['whmcs_invoice_min_date' => '2026-09-14']);
        // whmcs:fetch-unpaid rows carry '0000-00-00 00:00:00' — not a real
        // pre-cut-over date, so must NOT be mis-suggested check_legacy.
        $this->pending(72000, $this->customer('Unpaid'), 40.00, '0000-00-00 00:00:00', 'Επί πιστώσει');

        $res = (new WhmcsInboxListTool)->run($this->tenant, ['status' => 'open']);
        $this->assertSame('review', $res['rows'][0]['suggestion']);
    }

    public function test_inbox_list_reports_total_and_truncation(): void
    {
        foreach ([90001, 90002, 90003] as $i => $id) {
            $this->pending($id, $this->customer('R'.$i), 10.00, '2026-09-15 10:00:00', 'Row');
        }

        $res = (new WhmcsInboxListTool)->run($this->tenant, ['status' => 'open', 'limit' => 2]);
        $this->assertSame(3, $res['total']);
        $this->assertSame(2, $res['count']);
        $this->assertTrue($res['truncated']);
    }

    public function test_inbox_list_does_not_double_report_existing_as_same_amount(): void
    {
        $cust = $this->customer('Same');
        // One LIVE invoice carrying the whmcs id AND the same gross — it's the
        // existing link, so it must not ALSO appear under same_amount.
        $this->invoice($cust, 124, whmcsId: 91000);
        $this->pending(91000, $cust, 124.00, '2026-09-15 10:00:00', 'Ίδιο');

        $res = (new WhmcsInboxListTool)->run($this->tenant, ['status' => 'open']);
        $row = collect($res['rows'])->firstWhere('whmcs_invoice_id', 91000);
        $this->assertNotNull($row['duplicate']['existing_ekdosi_invoices']);
        $this->assertSame([], $row['duplicate']['same_amount_invoices']);
    }

    public function test_inbox_list_exposes_payment_transactions(): void
    {
        $cust = $this->customer('Txn');
        PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'source' => PendingWhmcsInvoice::SOURCE_WHMCS,
            'whmcs_invoice_id' => 93000, 'whmcs_userid' => 1, 'customer_id' => $cust->id,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW, 'match_reason' => 'test',
            'payload' => [
                'total' => '100', 'datepaid' => '2026-09-15 10:00:00', 'status' => 'Paid',
                'items' => ['item' => [['description' => 'X', 'amount' => '100']]],
                'transactions' => [['transid' => 'TXN123', 'gateway' => 'eurobank', 'date' => '2026-09-15 10:00:00', 'amount' => '100']],
            ],
        ]);

        $res = (new WhmcsInboxListTool)->run($this->tenant, ['status' => 'open']);
        $row = collect($res['rows'])->firstWhere('whmcs_invoice_id', 93000);
        $this->assertSame('TXN123', $row['transactions'][0]['transid']);
        $this->assertSame('eurobank', $row['transactions'][0]['gateway']);
        $this->assertSame('2026-09-15 10:00:00', $row['datepaid']);
    }

    public function test_inbox_list_duplicates_only_filters(): void
    {
        $this->tenant->update(['whmcs_invoice_min_date' => '2026-09-14']);
        $this->pending(60001, $this->customer('Clean'), 30.00, '2026-09-15 10:00:00', 'Καθαρό');
        $dupCust = $this->customer('Dirty');
        $this->invoice($dupCust, 55, whmcsId: 60002);
        $this->pending(60002, $dupCust, 55.00, '2026-09-15 10:00:00', 'Διπλό');

        $res = (new WhmcsInboxListTool)->run($this->tenant, ['duplicates_only' => true]);
        $this->assertSame(1, $res['count']);
        $this->assertSame(60002, $res['rows'][0]['whmcs_invoice_id']);
    }
}
