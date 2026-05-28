<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceMailLog;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SweepOrphanMailLogsTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Company::create([
            'name' => 'Sweep test',
            'slug' => 'sweep-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
        ]);
        $customer = Customer::create([
            'company_id' => $tenant->id,
            'name' => 'C',
        ]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id,
            'code' => 'TPY',
            'name' => 'Τ',
            'invcount' => 1,
        ]);
        $this->invoice = Invoice::create([
            'company_id' => $tenant->id,
            'invcode' => 'TPY1',
            'code' => 1,
            'invoice_type_id' => $type->id,
            'customer_id' => $customer->id,
            'issued_at' => now(),
            'header_discount_percent' => 0,
        ]);
    }

    private function log(string $status, int $ageMinutes): InvoiceMailLog
    {
        return InvoiceMailLog::create([
            'company_id' => $this->invoice->company_id,
            'invoice_id' => $this->invoice->id,
            'recipient' => 'a@b.gr',
            'trigger' => 'manual',
            'status' => $status,
            'queued_at' => now()->subMinutes($ageMinutes),
        ]);
    }

    public function test_sweeps_stuck_rows_past_threshold_only(): void
    {
        $stuckQueued = $this->log('queued', 30);
        $stuckSending = $this->log('sending', 30);
        $recent = $this->log('queued', 2);
        $sent = $this->log('sent', 30);

        $this->artisan('mail-log:sweep-orphans', ['--minutes' => 15])
            ->assertExitCode(0);

        $this->assertSame('failed', $stuckQueued->fresh()->status);
        $this->assertSame('failed', $stuckSending->fresh()->status);
        $this->assertNotNull($stuckQueued->fresh()->failed_at);
        $this->assertStringContainsString('worker crash', $stuckQueued->fresh()->error_message);

        // Recent in-flight row and already-terminal 'sent' row untouched.
        $this->assertSame('queued', $recent->fresh()->status);
        $this->assertSame('sent', $sent->fresh()->status);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $stuck = $this->log('queued', 30);

        $this->artisan('mail-log:sweep-orphans', ['--minutes' => 15, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame('queued', $stuck->fresh()->status);
    }

    public function test_threshold_defaults_from_config(): void
    {
        config(['ekdosi.schedule.mail_sweep_threshold_minutes' => 10]);
        $stuck = $this->log('queued', 20);

        $this->artisan('mail-log:sweep-orphans')->assertExitCode(0);

        $this->assertSame('failed', $stuck->fresh()->status);
    }
}
