<?php

namespace Tests\Feature\MyData;

use App\Filament\Widgets\MyDataSyncStats;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The myDATA «Outbox»: live docs that SHOULD be filed but carry no MARK
 * (Invoice/DeliveryNote::scopeAwaitingMyData), surfaced as the «Προς υποβολή»
 * list tabs + the dashboard MyDataSyncStats tile.
 */
class MyDataOutboxTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Outbox OE', 'slug' => 'out-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '801280908', 'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
        ]);
    }

    private function type(Company $c, ?string $mydataType): InvoiceType
    {
        return InvoiceType::create([
            'company_id' => $c->id, 'code' => 'T'.uniqid(), 'name' => 'Τ', 'invcount' => 1,
            'mydata_type' => $mydataType,
        ]);
    }

    private function invoice(Company $c, InvoiceType $type, string $status, ?string $mark): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $c->id, 'invcode' => 'I'.uniqid(), 'code' => 1,
            'invoice_type_id' => $type->id, 'issued_at' => now(),
            'net_total' => 10, 'gross_total' => 12.4, 'header_discount_percent' => 0,
            'local_status' => $status,
        ]);

        // mydata_mark is a guarded cache column (written only by the submitter) —
        // forceFill to simulate a filed invoice in the test.
        if ($mark !== null) {
            $inv->forceFill(['mydata_mark' => $mark])->saveQuietly();
        }

        return $inv;
    }

    public function test_invoice_outbox_is_filable_uncancelled_and_markless(): void
    {
        $c = $this->tenant();
        $filable = $this->type($c, '11.2');
        $internal = $this->type($c, null); // never filed

        $draft = $this->invoice($c, $filable, 'draft', null);        // ✓ outbox
        $unfiled = $this->invoice($c, $filable, 'active', null);     // ✓ outbox (failed/skipped submit)
        $filed = $this->invoice($c, $filable, 'active', '400001');   // ✗ has MARK
        $internalDoc = $this->invoice($c, $internal, 'active', null);// ✗ type never filed
        $cancelled = $this->invoice($c, $filable, 'cancelled', null);// ✗ cancelled

        // ✗ imported pre-myDATA invoice (legacy_id set, MARK-less, active) — belongs
        // to the legacy lifecycle, must NOT flood the Outbox.
        $imported = $this->invoice($c, $filable, 'active', null);
        $imported->forceFill(['legacy_id' => 5001])->saveQuietly();

        $ids = Invoice::query()->where('company_id', $c->id)->awaitingMyData()->pluck('id')->all();

        sort($ids);
        $expected = [$draft->id, $unfiled->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_delivery_note_outbox_mirrors_invoice(): void
    {
        $c = $this->tenant();
        $cust = Customer::create(['company_id' => $c->id, 'name' => 'Π']);
        $dtype = $this->type($c, '9.3');

        $base = function (array $o, ?string $mark = null) use ($c, $dtype, $cust): DeliveryNote {
            $note = DeliveryNote::create(array_merge([
                'company_id' => $c->id, 'invcode' => 'D'.uniqid(), 'code' => 1,
                'delivery_type_id' => $dtype->id, 'customer_id' => $cust->id, 'issued_at' => now(),
                'mydata_type' => '9.3', 'move_purpose' => 8,
            ], $o));
            if ($mark !== null) {
                $note->forceFill(['mydata_mark' => $mark])->saveQuietly(); // guarded cache column
            }

            return $note;
        };

        $needsFiling = $base(['local_status' => 'active']);                       // ✓
        $base(['local_status' => 'active'], '400777');                           // ✗ filed
        $base(['local_status' => 'cancelled']);                                  // ✗ cancelled
        $base(['local_status' => 'active', 'mydata_type' => null]);              // ✗ never filed

        $ids = DeliveryNote::query()->where('company_id', $c->id)->awaitingMyData()->pluck('id')->all();
        $this->assertSame([$needsFiling->id], $ids);
    }

    public function test_dashboard_tile_counts_and_is_gated(): void
    {
        $c = $this->tenant();
        $filable = $this->type($c, '11.2');
        $this->invoice($c, $filable, 'draft', null);
        $this->invoice($c, $filable, 'active', null);

        // TenantSet fires on setTenant and needs an authenticated user.
        $this->actingAs(\App\Models\User::create([
            'name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($c);
        $this->assertTrue(MyDataSyncStats::canView());

        // Hidden for a tenant that can't read myDATA.
        $none = Company::create([
            'name' => 'None', 'slug' => 'none-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'none', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($none);
        $this->assertFalse(MyDataSyncStats::canView());
    }
}
