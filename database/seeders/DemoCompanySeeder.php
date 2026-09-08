<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MetricUnit;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\InvoiceNumberer;
use App\Services\MyData\MyDataLookupSeeder;
use App\Services\RecomputeInvoiceTotals;
use App\Services\TenantRoleProvisioner;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * «DEMO Α.Ε.» — ONE self-contained tenant in full demo mode, so a fresh install
 * (or a reviewer) sees a working company immediately: lookups, a small catalogue
 * (products + services, incl. a withholding service and a product with a bound
 * per-unit fee), customers, a few issued invoices (one with withholding, one with
 * a product-linked fee) and delivery notes. Exercises the myDATA tax pipeline
 * locally (`mydata_mode=off` → NO AADE calls; nothing is filed).
 *
 * Idempotent: skips entirely if a «demo» company already exists. Run standalone:
 *   php artisan db:seed --class=Database\\Seeders\\DemoCompanySeeder
 * It attaches the first existing admin user (or admin@ekdosi.local) to the demo
 * company so you can tenant-switch into it. Never touches the real tenants.
 */
class DemoCompanySeeder extends Seeder
{
    public function run(): void
    {
        // SET-1: same opt-in gate as DatabaseSeeder, so a direct
        // `db:seed --class=DemoCompanySeeder` can't bypass it — building the DEMO
        // tenant on a real host (or escalating the first user to super_admin via
        // attachAdmin) must ALSO require the explicit flag + a non-prod host.
        if (! config('ekdosi.seed_demo')) {
            $this->command?->warn('DemoCompanySeeder: skipped — set EKDOSI_SEED_DEMO=true to enable the demo tenant.');

            return;
        }
        if (app()->isProduction()) {
            $this->command?->warn('DemoCompanySeeder: refusing to seed the DEMO tenant on a production host.');

            return;
        }

        if (Company::query()->where('slug', 'demo')->exists()) {
            $this->command?->warn('DEMO company already exists — skipping (delete it to reseed).');

            return;
        }

        $demo = Company::create([
            'name' => 'DEMO Α.Ε.',
            'slug' => 'demo',
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'afm' => '800561849',
            'tax_office' => 'Ξάνθης',
            'address' => 'Δοκιμαστική 1',
            'city' => 'Ξάνθη',
            'postcode' => '67100',
            'phone' => '2100000000',
            'email' => 'demo@example.gr',
            'mydata_mode' => 'off',           // demo — never files at AADE
            'auto_email_on_issue' => false,
        ]);

        // Roles + lookups (reuse the canonical seeders).
        $provisioner = app(TenantRoleProvisioner::class);
        $provisioner->ensureSuperAdminRole($demo);
        $provisioner->ensureStandardRoles($demo);
        $this->attachAdmin($demo);

        $lookups = app(MyDataLookupSeeder::class);
        $lookups->seedVatCategories($demo);
        $lookups->seedInvoiceTypes($demo);
        $lookups->seedPaymentMethods($demo);
        $lookups->seedDistributionAims($demo);
        $lookups->seedMetricUnits($demo);
        $lookups->seedDeliveryMethods($demo);
        $lookups->seedProductCategories($demo);

        $cid = $demo->getKey();
        $vat24 = VatCategory::where('company_id', $cid)->where('rate', 24)->firstOrFail()->id;
        $vat13 = VatCategory::where('company_id', $cid)->where('rate', 13)->first()?->id ?? $vat24;
        $cat = ProductCategory::where('company_id', $cid)->first()
            ?? ProductCategory::create(['company_id' => $cid, 'description_short' => 'Γενικά']);
        $unit = MetricUnit::where('company_id', $cid)->first()?->id;
        $pm = PaymentMethod::where('company_id', $cid)->first()?->id;

        // ── Catalogue: products + services ──
        $router = $this->product($cid, $cat->id, $vat24, $unit, 'Δρομολογητής WiFi (DEMO)', 45.00);
        $cable = $this->product($cid, $cat->id, $vat24, $unit, 'Καλώδιο UTP cat6 (DEMO)', 0.80);
        $hosting = $this->product($cid, $cat->id, $vat24, null, 'Φιλοξενία ιστοσελίδας (DEMO)', 120.00);
        $consult = $this->product($cid, $cat->id, $vat24, null, 'Συμβουλευτικές υπηρεσίες (DEMO)', 300.00);
        // A SERVICE with a bound per-unit fee (myDATA taxType 2 / §8.7 cat 18, τέλος διαμονής):
        $night = $this->product($cid, $cat->id, $vat13, $unit, 'Διανυκτέρευση (DEMO)', 70.00, [
            'mydata_tax_type' => 2, 'mydata_tax_category' => 18, 'mydata_tax_per_unit' => 1.50,
        ]);

        // ── Customers ──
        $acme = Customer::create(['company_id' => $cid, 'type' => 'company', 'name' => 'ACME Δοκιμαστική ΕΠΕ', 'afm' => '094000045', 'city' => 'Αθήνα', 'postcode' => '11528', 'occupation' => 'Εμπόριο', 'tax_office' => 'ΦΑΕ Αθηνών']);
        $beta = Customer::create(['company_id' => $cid, 'type' => 'company', 'name' => 'Βήτα Σύμβουλοι ΙΚΕ', 'afm' => '800000001', 'city' => 'Θεσσαλονίκη', 'postcode' => '54624', 'occupation' => 'Υπηρεσίες']);
        $idiotis = Customer::create(['company_id' => $cid, 'type' => 'person', 'name' => 'Ιδιώτης Πελάτης (DEMO)', 'city' => 'Ξάνθη', 'postcode' => '67100']);

        // ── Invoices ──
        $tpy = InvoiceType::where('company_id', $cid)->where('code', 'ΤΠΥ')->firstOrFail();
        $apy = InvoiceType::where('company_id', $cid)->where('code', 'ΑΠΥ')->firstOrFail();

        // A) plain ΤΠΥ — hosting + consulting.
        $this->invoice($demo, $tpy, $acme, $pm, [[$hosting, 1], [$consult, 1]]);
        // B) ΤΠΥ with 20% withholding (συμβουλευτικές) — exercises the taxesTotals path.
        $this->invoice($demo, $tpy, $beta, $pm, [[$consult, 2]], ['withhold_rate' => 20, 'withhold_category' => 3]);
        // C) ΑΠΥ retail with the product-linked fee (3 διανυκτερεύσεις → τέλος auto).
        $this->invoice($demo, $apy, $idiotis, $pm, [[$night, 3]]);

        // ── Delivery notes (ΔΑΠ) ──
        $this->deliveryNote($demo, $acme, [[$router, 2], [$cable, 5]]);
        $this->deliveryNote($demo, $beta, [[$router, 1]]);

        $this->command?->info('DEMO Α.Ε. seeded: 5 catalogue items, 3 customers, 3 invoices, 2 delivery notes.');
    }

    private function attachAdmin(Company $demo): void
    {
        $admin = User::query()->where('email', 'admin@ekdosi.local')->first()
            ?? User::query()->orderBy('id')->first();
        if ($admin === null) {
            $this->command?->warn('No admin user found — DEMO company seeded without a user attached.');

            return;
        }
        $admin->companies()->syncWithoutDetaching([$demo->id]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($demo->id);
        $registrar->forgetCachedPermissions();
        $role = Role::query()
            ->where('name', ShieldUtils::getSuperAdminName())
            ->where('guard_name', 'web')
            ->where('company_id', $demo->id)
            ->first();
        if ($role) {
            $admin->assignRole($role);
        }
    }

    /** @param array<string,mixed> $tax */
    private function product(int $cid, int $catId, int $vatId, ?int $unitId, string $name, float $price, array $tax = []): Product
    {
        return Product::create(array_merge([
            'company_id' => $cid,
            'product_category_id' => $catId,
            'vat_category_id' => $vatId,
            'metric_unit_id' => $unitId,
            'description_short' => $name,
            'sell_price' => $price,
            'is_active' => true,
        ], $tax));
    }

    /**
     * @param  array<int,array{0:Product,1:float}>  $lines  [product, qty]
     * @param  array<string,mixed>  $extra  invoice-level overrides (e.g. withhold_rate)
     */
    private function invoice(Company $demo, InvoiceType $type, Customer $customer, ?int $pmId, array $lines, array $extra = []): Invoice
    {
        // InvoiceNumberer::allocate() asserts an ambient DB transaction (its
        // lockForUpdate invariant) — `php artisan db:seed` provides none, so the
        // allocate + insert MUST be wrapped here (tests get one free from
        // RefreshDatabase, but a real seed run would otherwise throw on line 1).
        $invoice = DB::transaction(function () use ($demo, $type, $customer, $pmId, $lines, $extra): Invoice {
            $alloc = app(InvoiceNumberer::class)->allocate($demo, $type->code);

            $invoice = Invoice::create(array_merge([
                'company_id' => $demo->id,
                'invoice_type_id' => $type->id,
                'customer_id' => $customer->id,
                'payment_method_id' => $pmId,
                'code' => $alloc->code,
                'invcode' => $alloc->invcode,
                'issued_at' => now()->subDays(random_int(1, 20)),
                'local_status' => 'active',
                // Party snapshot from the customer.
                'company_name' => $customer->name,
                'vat_no' => $customer->afm,
                'occupation' => $customer->occupation,
                'city' => $customer->city,
                'postcode' => $customer->postcode,
            ], $extra));

            foreach ($lines as [$product, $qty]) {
                InvoiceLine::create([
                    'company_id' => $demo->id,
                    'invoice_id' => $invoice->id,
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'price_per_item' => (float) $product->sell_price,
                    'vat_percent' => (float) $product->vatCategory->rate,
                    'product_descr' => $product->description_short,
                ]);
            }

            return $invoice;
        });

        // Computes net/gross + the myDATA taxesTotals (withholding / product fees).
        return app(RecomputeInvoiceTotals::class)($invoice->fresh('lines'));
    }

    /** @param array<int,array{0:Product,1:float}> $lines */
    private function deliveryNote(Company $demo, Customer $customer, array $lines): DeliveryNote
    {
        $type = InvoiceType::where('company_id', $demo->id)->where('code', 'ΔΑΠ')->firstOrFail();

        // Same transaction invariant as invoice() — allocate + insert atomically.
        return DB::transaction(function () use ($demo, $type, $customer, $lines): DeliveryNote {
            // ΔΑΠ is a 9.x movement type — opt out of the monetary 9.x guard (MYD-003).
            $alloc = app(InvoiceNumberer::class)->allocate($demo, $type->code, allowMovementType: true);

            $note = DeliveryNote::create([
                'company_id' => $demo->id,
                'delivery_type_id' => $type->id,
                'customer_id' => $customer->id,
                'invcode' => $alloc->invcode,
                'code' => $alloc->code,
                'issued_at' => now()->subDays(random_int(1, 10)),
                'mydata_type' => '9.3',
                'move_purpose' => 1,                       // Πώληση
                'dispatch_at' => now(),
                'vehicle_number' => 'ΑΑΑ1234',
                'transport_type' => 1,
                'loading_street' => 'Δοκιμαστική', 'loading_number' => '1', 'loading_postcode' => '67100', 'loading_city' => 'Ξάνθη',
                'delivery_street' => 'Παράδοσης', 'delivery_number' => '5', 'delivery_postcode' => (string) $customer->postcode, 'delivery_city' => (string) $customer->city,
                'recipient_name' => $customer->name,
                'recipient_afm' => $customer->afm ?: '000000000',
                // MYD-011: an external recipient with no resolvable country is
                // REFUSED at issue, so a seeded note without this is dead on
                // arrival — the demo tenant is exactly where someone clicks
                // «Έκδοση» to see what happens.
                'recipient_country' => 'GR',
                'local_status' => 'active',
            ]);

            foreach ($lines as [$product, $qty]) {
                DeliveryNoteLine::create([
                    'company_id' => $demo->id,
                    'delivery_note_id' => $note->id,
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'measurement_unit' => 1,
                    'product_descr' => $product->description_short,
                ]);
            }

            return $note;
        });
    }
}
