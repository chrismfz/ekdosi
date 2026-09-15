<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Services\Whmcs\ImmediateInvoiceBell;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The «Άμεσο παραστατικό προς έκδοση» operator bell auto-resolves once its WHMCS
 * row is handled (issued / drafted / rejected / merged / split), so the desk
 * stops seeing stale «unread» badges for παραστατικά that are already out.
 */
class ImmediateInvoiceBellTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug = 'bell'): Company
    {
        return Company::create([
            'name' => 'Bell', 'slug' => $slug.'-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function operatorFor(Company $tenant): User
    {
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $tenant->users()->attach($user->id);

        return $user;
    }

    /** Stage a real immediate-invoice bell (tagged) via the ingestor, return [user, row]. */
    private function stageImmediate(Company $tenant, int $whmcsId, int $userId = 555): array
    {
        $user = $this->operatorFor($tenant);
        Customer::create([
            'company_id' => $tenant->id, 'name' => 'Άμεσος', 'whmcs_client_id' => $userId,
            'needs_immediate_invoice' => true,
        ]);
        app(WhmcsInvoiceIngestor::class)->ingest($tenant, ['invoiceid' => $whmcsId, 'userid' => $userId, 'total' => '10.00']);

        $row = PendingWhmcsInvoice::where('company_id', $tenant->id)->where('whmcs_invoice_id', $whmcsId)->firstOrFail();

        return [$user, $row];
    }

    public function test_the_bell_is_tagged_with_kind_company_and_whmcs_id(): void
    {
        $tenant = $this->tenant();
        [$user] = $this->stageImmediate($tenant, 9101);

        $view = $user->fresh()->notifications()->first()->data['viewData'] ?? [];
        $this->assertSame('immediate_invoice', $view['kind'] ?? null);
        $this->assertSame((string) $tenant->id, $view['company_id'] ?? null);
        $this->assertSame('9101', $view['whmcs_invoice_id'] ?? null);
    }

    public function test_issuing_the_row_auto_clears_the_bell(): void
    {
        $tenant = $this->tenant();
        [$user, $row] = $this->stageImmediate($tenant, 9102);
        $this->assertSame(1, $user->fresh()->unreadNotifications()->count(), 'bell starts unread');

        $row->update(['status' => PendingWhmcsInvoice::STATUS_FILED]);

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count(), 'issuing clears the bell');
        $this->assertSame(1, $user->fresh()->notifications()->count(), 'the notification is kept, just marked read');
    }

    public function test_any_handled_transition_clears_the_bell(): void
    {
        $statuses = [
            PendingWhmcsInvoice::STATUS_DRAFTED,
            PendingWhmcsInvoice::STATUS_REJECTED,
            PendingWhmcsInvoice::STATUS_RESOLVED,
            PendingWhmcsInvoice::STATUS_SPLIT,
        ];
        foreach ($statuses as $i => $status) {
            $tenant = $this->tenant('h'.$i);
            [$user, $row] = $this->stageImmediate($tenant, 9200 + $i, 600 + $i);

            $row->update(['status' => $status]);

            $this->assertSame(0, $user->fresh()->unreadNotifications()->count(), "status={$status} clears the bell");
        }
    }

    public function test_moving_to_held_keeps_the_bell_lit(): void
    {
        $tenant = $this->tenant();
        [$user, $row] = $this->stageImmediate($tenant, 9104);

        $row->update(['status' => PendingWhmcsInvoice::STATUS_HELD]);

        $this->assertSame(1, $user->fresh()->unreadNotifications()->count(), 'held is still waiting → bell stays');
    }

    public function test_resolve_is_tenant_precise_same_whmcs_id_two_tenants(): void
    {
        $whmcsId = 9105;
        $tenantA = $this->tenant('a');
        $tenantB = $this->tenant('b');
        [$userA, $rowA] = $this->stageImmediate($tenantA, $whmcsId, 111);
        [$userB] = $this->stageImmediate($tenantB, $whmcsId, 222);

        // Handle only tenant A's row.
        $rowA->update(['status' => PendingWhmcsInvoice::STATUS_FILED]);

        $this->assertSame(0, $userA->fresh()->unreadNotifications()->count(), 'A cleared');
        $this->assertSame(1, $userB->fresh()->unreadNotifications()->count(), 'B untouched — same WHMCS id, different tenant');
    }

    public function test_sweep_clears_a_legacy_untagged_bell_whose_row_is_handled(): void
    {
        $tenant = $this->tenant();
        $user = $this->operatorFor($tenant);
        // A legacy bell: title + body, NO viewData tag (pre-feature shape).
        $this->legacyBell($user, 'WHMCS #7777 — Πελάτης ζητά άμεση τιμολόγηση.');
        // Its WHMCS row is already issued.
        PendingWhmcsInvoice::create([
            'company_id' => $tenant->id, 'whmcs_invoice_id' => 7777,
            'status' => PendingWhmcsInvoice::STATUS_FILED,
            'match_reason' => PendingWhmcsInvoice::REASON_AFM, 'payload' => ['invoiceid' => 7777],
        ]);

        $this->assertSame(1, ImmediateInvoiceBell::sweep(), 'one legacy bell cleared');
        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_sweep_keeps_a_legacy_bell_whose_row_is_still_waiting(): void
    {
        $tenant = $this->tenant();
        $user = $this->operatorFor($tenant);
        $this->legacyBell($user, 'WHMCS #8888 — Πελάτης ζητά άμεση τιμολόγηση.');
        PendingWhmcsInvoice::create([
            'company_id' => $tenant->id, 'whmcs_invoice_id' => 8888,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'match_reason' => PendingWhmcsInvoice::REASON_AFM, 'payload' => ['invoiceid' => 8888],
        ]);

        $this->assertSame(0, ImmediateInvoiceBell::sweep(), 'still waiting → not cleared');
        $this->assertSame(1, $user->fresh()->unreadNotifications()->count());
    }

    public function test_is_handled_predicate(): void
    {
        $this->assertFalse(ImmediateInvoiceBell::isHandled(PendingWhmcsInvoice::STATUS_PENDING_REVIEW));
        $this->assertFalse(ImmediateInvoiceBell::isHandled(PendingWhmcsInvoice::STATUS_HELD));
        $this->assertFalse(ImmediateInvoiceBell::isHandled(null));
        $this->assertTrue(ImmediateInvoiceBell::isHandled(PendingWhmcsInvoice::STATUS_FILED));
        $this->assertTrue(ImmediateInvoiceBell::isHandled(PendingWhmcsInvoice::STATUS_DRAFTED));
        $this->assertTrue(ImmediateInvoiceBell::isHandled(PendingWhmcsInvoice::STATUS_REJECTED));
    }

    private function legacyBell(User $user, string $body): void
    {
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => \Filament\Notifications\DatabaseNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['title' => ImmediateInvoiceBell::TITLE, 'body' => $body],
            'read_at' => null,
        ]);
    }
}
