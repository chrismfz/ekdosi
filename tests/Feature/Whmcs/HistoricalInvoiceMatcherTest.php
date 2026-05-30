<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Services\Whmcs\HistoricalInvoiceMatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The historical WHMCS#→ekdosi-ΤΠΥ matcher. No customer link survives the
 * legacy import (empty tax_id / null whmcs_client_id), so it matches tenant-
 * wide on line text (HIGH) with an amount cross-check, falling back to
 * amount+date±1 (MEDIUM). Ambiguous/unmatched → null (operator review).
 */
class HistoricalInvoiceMatcherTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private HistoricalInvoiceMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Match', 'slug' => 'match-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
        $this->matcher = new HistoricalInvoiceMatcher();
    }

    private function invoice(string $invcode, int $code, float $gross, string $issued, string $descr, string $local = 'active', ?string $state = 'VALID'): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $this->type->id,
            'invcode' => $invcode, 'code' => $code, 'issued_at' => $issued,
            'net_total' => $gross, 'gross_total' => $gross,
        ]);
        $inv->forceFill(['local_status' => $local, 'mydata_state' => $state])->save();
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => $gross, 'vat_percent' => 0,
            'net_price' => $gross, 'gross_price' => $gross, 'product_descr' => $descr,
        ]);

        return $inv;
    }

    private function index(): array
    {
        return $this->matcher->buildIndex($this->tenant, Carbon::parse('2026-01-01'));
    }

    public function test_line_text_match_is_high_confidence(): void
    {
        $inv = $this->invoice('ΤΠΥ100', 100, 44.64, '2026-05-27', 'MyMail 100 - ktima-pavlidis.gr (25/05/2026 - 24/06/2026)');

        $m = $this->matcher->match([
            'total' => 44.64, 'date' => '2026-05-15',  // date irrelevant for text tier
            'descriptions' => ['MyMail 100 - ktima-pavlidis.gr (25/05/2026 - 24/06/2026)'],
        ], $this->index());

        $this->assertSame(['invoice_id' => $inv->id, 'method' => 'line_text', 'confidence' => 'high'], $m);
    }

    public function test_line_text_whitespace_and_case_insensitive(): void
    {
        $inv = $this->invoice('ΤΠΥ101', 101, 36.00, '2026-05-27', "Business10 - threpsis.com   (01/06/2026 - 30/06/2026)");

        $m = $this->matcher->match([
            'total' => 36.00, 'date' => '2026-05-27',
            'descriptions' => ["business10 - threpsis.com (01/06/2026 - 30/06/2026)\n"],  // newline + case + spacing
        ], $this->index());

        $this->assertNotNull($m);
        $this->assertSame($inv->id, $m['invoice_id']);
        $this->assertSame('line_text', $m['method']);
    }

    public function test_line_text_with_wrong_amount_falls_back_not_high(): void
    {
        // Same text on one invoice, but the WHMCS total disagrees → the text
        // HIGH match is rejected; with no amount_date hit either, → null.
        $this->invoice('ΤΠΥ102', 102, 36.00, '2026-05-27', 'Personal2 - example.com (2026)');

        $m = $this->matcher->match([
            'total' => 99.99, 'date' => '2026-05-27',
            'descriptions' => ['Personal2 - example.com (2026)'],
        ], $this->index());

        $this->assertNull($m);
    }

    public function test_amount_date_match_is_medium_within_one_day(): void
    {
        $inv = $this->invoice('ΤΠΥ103', 103, 23.56, '2026-05-27', 'Κατοχύρωση Domain - createidea.gr');

        // No line-text overlap → amount + date±1.
        $m = $this->matcher->match([
            'total' => 23.56, 'date' => '2026-05-28',   // +1 day
            'descriptions' => ['something totally different'],
        ], $this->index());

        $this->assertSame(['invoice_id' => $inv->id, 'method' => 'amount_date', 'confidence' => 'medium'], $m);
    }

    public function test_amount_date_collision_is_not_matched(): void
    {
        // Two invoices, same amount + same day (bulk renewal) → ambiguous.
        $this->invoice('ΤΠΥ104', 104, 23.56, '2026-05-27', 'Domain A');
        $this->invoice('ΤΠΥ105', 105, 23.56, '2026-05-27', 'Domain B');

        $m = $this->matcher->match([
            'total' => 23.56, 'date' => '2026-05-27', 'descriptions' => ['unrelated'],
        ], $this->index());

        $this->assertNull($m);   // left for operator review
    }

    public function test_zero_total_never_matches(): void
    {
        $this->invoice('ΤΠΥ106', 106, 0.00, '2026-05-27', 'Free renewal');

        $m = $this->matcher->match([
            'total' => 0.00, 'date' => '2026-05-27', 'descriptions' => ['Free renewal'],
        ], $this->index());

        $this->assertNull($m);
    }

    public function test_cancelled_invoices_are_not_index_targets(): void
    {
        $this->invoice('ΤΠΥ107', 107, 50.00, '2026-05-27', 'Cancelled thing', 'cancelled', null);

        $m = $this->matcher->match([
            'total' => 50.00, 'date' => '2026-05-27', 'descriptions' => ['Cancelled thing'],
        ], $this->index());

        $this->assertNull($m);
    }

    public function test_persist_is_idempotent_and_preserves_manual(): void
    {
        $inv = $this->invoice('ΤΠΥ108', 108, 12.00, '2026-05-27', 'Thing');
        $match = ['invoice_id' => $inv->id, 'method' => 'line_text', 'confidence' => 'high'];

        $this->assertTrue($this->matcher->persist($this->tenant, 31535, $match));
        $this->assertTrue($this->matcher->persist($this->tenant, 31535, $match));   // re-run → update, still ok

        $rows = DB::table('whmcs_invoice_log')
            ->where('company_id', $this->tenant->id)->where('whmcs_invoice_id', 31535)->get();
        $this->assertCount(1, $rows);                          // idempotent: one row
        $this->assertSame($inv->id, (int) $rows->first()->invoice_id);
        $this->assertSame('line_text', $rows->first()->match_method);

        // A manual link must NOT be overwritten by the matcher.
        DB::table('whmcs_invoice_log')->where('whmcs_invoice_id', 31535)
            ->update(['match_method' => 'manual']);
        $this->assertFalse($this->matcher->persist($this->tenant, 31535, $match));
        $this->assertSame('manual', DB::table('whmcs_invoice_log')
            ->where('whmcs_invoice_id', 31535)->value('match_method'));
    }
}
