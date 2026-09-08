<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Jobs\SendInvoiceEmail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceMailLog;
use App\Models\InvoiceType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Web visibility for failed invoice emails: the «latest mail status» scope that
 * backs the list filter, and the bulk «Επαναποστολή email» action.
 */
class InvoiceEmailStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Gate::before(fn () => true);

        $this->tenant = Company::create([
            'name' => 'Acme', 'slug' => 'mail-ui-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C', 'email' => 'c@example.com']);

        $this->actingAs(User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }

    private function invoice(string $invcode): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => $invcode, 'code' => 1,
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            // DOC-6: the resend action only emails ISSUED (active) invoices; a
            // «failed mail» scenario implies the invoice was issued in the first
            // place, so these fixtures are active.
            'local_status' => 'active',
            'issued_at' => now(), 'company_name' => 'C', 'net_total' => 100, 'gross_total' => 124,
        ]);
    }

    private function log(Invoice $invoice, string $status): void
    {
        InvoiceMailLog::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'recipient' => 'c@example.com', 'status' => $status, 'trigger' => 'auto',
        ]);
    }

    public function test_latest_mail_status_scope_matches_only_the_latest_attempt(): void
    {
        $failed = $this->invoice('TPY1');
        $this->log($failed, 'failed');

        $recovered = $this->invoice('TPY2');
        $this->log($recovered, 'failed');
        $this->log($recovered, 'sent'); // later success supersedes

        $never = $this->invoice('TPY3'); // no mail log at all

        $this->assertSame([$failed->id], Invoice::query()->whereLatestMailStatus(['failed'])->pluck('id')->all());
        $this->assertSame([$recovered->id], Invoice::query()->whereLatestMailStatus(['sent'])->pluck('id')->all());

        // The list column reads the same latest attempt.
        $this->assertSame('failed', $failed->latestMailLog->status);
        $this->assertSame('sent', $recovered->fresh()->latestMailLog->status);
        $this->assertNull($never->latestMailLog);
    }

    public function test_bulk_resend_queues_mail_for_selected_with_email(): void
    {
        $a = $this->invoice('TPY1'); // uses $this->customer (has email)
        $this->log($a, 'failed');

        // A SEPARATE customer with no email — its invoice must be skipped.
        $noEmailCustomer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'NoMail', 'email' => null]);
        $noEmail = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'TPY2', 'code' => 2,
            'invoice_type_id' => $this->type->id, 'customer_id' => $noEmailCustomer->id,
            'local_status' => 'active',
            'issued_at' => now(), 'company_name' => 'NoMail', 'net_total' => 100, 'gross_total' => 124,
        ]);
        $this->log($noEmail, 'failed');

        Livewire::test(ListInvoices::class)
            ->callTableBulkAction('resend_email', [$a->getKey(), $noEmail->getKey()]);

        Queue::assertPushed(SendInvoiceEmail::class, 1);
        Queue::assertPushed(fn (SendInvoiceEmail $job) => $job->invoice->is($a) && $job->trigger === 'manual');
    }

    public function test_bulk_resend_skips_drafts_and_cancelled(): void
    {
        // DOC-6: a draft and a cancelled invoice must NOT be bulk-emailed — the
        // mail body asserts the document «was issued». Only the active one goes out.
        $active = $this->invoice('TPY1');

        $draft = $this->invoice('TPY2');
        $draft->forceFill(['local_status' => 'draft'])->save();

        $cancelled = $this->invoice('TPY3');
        $cancelled->forceFill(['local_status' => 'cancelled'])->save();

        // Also cancelled-at-AADE (active locally but mydata_state CANCELLED) → skipped.
        $aadeCancelled = $this->invoice('TPY4');
        $aadeCancelled->forceFill(['mydata_state' => 'CANCELLED'])->save();

        Livewire::test(ListInvoices::class)
            ->callTableBulkAction('resend_email', [
                $active->getKey(), $draft->getKey(), $cancelled->getKey(), $aadeCancelled->getKey(),
            ]);

        Queue::assertPushed(SendInvoiceEmail::class, 1);
        Queue::assertPushed(fn (SendInvoiceEmail $job) => $job->invoice->is($active));
    }
}
