<?php

namespace Tests\Feature\Services;

use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServiceContract;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\InvoiceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PR-A guarantee: service contracts / servers are NOT money. Creating them must
 * leave InvoiceScope::live() and the dashboard receivables byte-identical — the
 * subscription layer never leaks into the legal/money path. Also exercises the
 * new migrations + scopeDue + encrypted server creds.
 */
class ServiceContractIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contracts_and_servers_do_not_touch_the_money_path(): void
    {
        // Server creds are encrypted at rest only with the flag on (DR default =
        // plaintext) — enable it for the encrypted-at-rest assertion below.
        config(['ekdosi.secrets.encrypt_at_rest' => true]);
        $tenant = Company::create(['name' => 'Rec', 'slug' => 'rec-'.uniqid(), 'country_code' => 'GR']);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        $credit = PaymentMethod::create(['company_id' => $tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30]);

        // A real receivable so the baseline is non-zero.
        $inv = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'ΤΙΜ1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id, 'issued_at' => now(),
            'local_status' => 'active', 'payment_method_id' => $credit->id,
        ]);
        $inv->forceFill(['net_total' => 100, 'gross_total' => 100])->save();

        $metrics = new DashboardMetrics($tenant);
        $receivablesBefore = $metrics->outstandingReceivables();
        $liveBefore = InvoiceScope::live(Invoice::query()->where('company_id', $tenant->id))->count();

        // Now create the whole subscription layer.
        $group = ServerGroup::create([
            'company_id' => $tenant->id, 'name' => 'cPanel group', 'module' => 'cpanel',
            'username' => 'reseller', 'secret_encrypted' => 'super-secret-token',
        ]);
        $server = Server::create([
            'company_id' => $tenant->id, 'server_group_id' => $group->id, 'name' => 'Virgo',
            'hostname' => 'virgo.myip.gr', 'secret_encrypted' => 'per-server-pw',
        ]);
        $contract = ServiceContract::create([
            'company_id' => $tenant->id, 'customer_id' => $customer->id,
            'description' => 'Personal5', 'billing_cycle' => BillingCycle::Biennial->value,
            'amount' => 131, 'vat_percent' => 24, 'status' => ServiceContractStatus::Active->value,
            'start_date' => now()->subYears(2), 'next_due_date' => now()->subDay(),
            'server_id' => $server->id, 'provisioning_module' => 'cpanel',
            'module_meta' => ['cpanel_user' => 'actcongr', 'domain' => 'actcon.gr'],
        ]);

        // Money path is UNCHANGED.
        $this->assertSame($receivablesBefore, $metrics->outstandingReceivables(), 'contracts must not change receivables');
        $this->assertSame($liveBefore, InvoiceScope::live(Invoice::query()->where('company_id', $tenant->id))->count());

        // Secrets are encrypted at rest, decrypted via the cast.
        $this->assertSame('super-secret-token', $group->fresh()->secret_encrypted);
        $this->assertSame('per-server-pw', $server->fresh()->secret_encrypted);
        $this->assertNotSame('super-secret-token', $group->fresh()->getRawOriginal('secret_encrypted'));

        // module_meta round-trips as an array (where a future module reads keys).
        $this->assertSame('actcon.gr', $contract->fresh()->module_meta['domain']);
        $this->assertSame('cpanel', $server->effectiveModule());
    }

    public function test_scope_due_only_returns_active_past_due(): void
    {
        $tenant = Company::create(['name' => 'Due', 'slug' => 'due-'.uniqid(), 'country_code' => 'GR']);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Π', 'afm' => '123456789']);

        $mk = fn (string $status, ?string $due) => ServiceContract::create([
            'company_id' => $tenant->id, 'customer_id' => $customer->id,
            'description' => 'x', 'billing_cycle' => BillingCycle::Monthly->value, 'amount' => 10,
            'status' => $status, 'next_due_date' => $due,
        ]);

        $dueActive = $mk(ServiceContractStatus::Active->value, Carbon::yesterday()->toDateString());
        $mk(ServiceContractStatus::Active->value, Carbon::tomorrow()->toDateString());   // future
        $mk(ServiceContractStatus::Suspended->value, Carbon::yesterday()->toDateString()); // not active
        $mk(ServiceContractStatus::Pending->value, Carbon::yesterday()->toDateString());   // not active

        $due = ServiceContract::query()->where('company_id', $tenant->id)->due()->get();
        $this->assertSame([$dueActive->id], $due->pluck('id')->all());
    }
}
