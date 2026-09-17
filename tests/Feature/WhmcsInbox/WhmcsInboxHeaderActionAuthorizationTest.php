<?php

namespace Tests\Feature\WhmcsInbox;

use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression (prod 500): the inbox list page threw on EVERY render for real
 * (non-super-admin) users. The «Συγχρονισμός τώρα» HEADER action authorized
 * against the record-scoped `update` policy ability — but a header action has
 * NO record, so Laravel's Gate::callPolicyMethod() shifted the model
 * class-string off the arguments and invoked
 * PendingWhmcsInvoicePolicy::update($user) with a SINGLE argument:
 * «Too few arguments to function …::update(), 1 passed … and exactly 2
 * expected» (app/Policies/PendingWhmcsInvoicePolicy.php:30), surfaced as a
 * ViewException while rendering filament/tables/resources/views/index.blade.php.
 *
 * Why the existing inbox tests stayed green while production burned: they all
 * short-circuit the gate with `Gate::before(fn () => true)`, which returns
 * before any policy method is called. This test deliberately lets the bare
 * `update` ability fall through to the REAL policy (granting every OTHER
 * ability so the page still renders and the permitted header action shows),
 * reproducing the exact production path.
 */
class WhmcsInboxHeaderActionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_inbox_list_renders_without_the_header_action_arity_crash(): void
    {
        // Grant every ability EXCEPT the record-scoped `update`, which we let
        // reach the real PendingWhmcsInvoicePolicy — the production condition
        // (no super-admin blanket bypass) under which the header action crashed.
        // A header action must therefore authorize via a NO-RECORD permission
        // check, never the bare `update` ability.
        Gate::before(fn ($user, string $ability) => $ability === 'update' ? null : true);

        $company = Company::create([
            'name' => 'Inbox', 'slug' => 'inbox-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        $this->actingAs($user);
        Filament::setTenant($company);

        PendingWhmcsInvoice::create([
            'company_id' => $company->id,
            'whmcs_invoice_id' => random_int(700000, 799999),
            'payload' => ['total' => 10, 'currencycode' => 'EUR', 'status' => 'Paid'],
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
        ]);

        // Pre-fix: this render throws the TypeError above. Post-fix: the header
        // action authorizes via `can('Update:PendingWhmcsInvoice')` (a no-record
        // permission check) and both the table body and the permitted header
        // action render cleanly.
        Livewire::test(ListWhmcsInbox::class)
            ->assertOk()
            ->assertSee('Προς έλεγχο')       // the pending row's status badge (body rendered)
            ->assertSee('Συγχρονισμός τώρα'); // the header action authorized without throwing
    }
}
