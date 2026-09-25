<?php

namespace Tests\Feature\Hr;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use App\Support\Hr\EmployeeAccountMatcher;
use Livewire\Livewire;

class EmployeeLinkingTest extends HrTestCase
{
    private function named(string $name, string $role = TenantRoleProvisioner::ROLE_ERGANI, ?string $email = null, ?Company $company = null): User
    {
        $u = $this->makeUser($role, $company);
        $u->forceFill(['name' => $name] + ($email ? ['email' => $email] : []))->save();

        return $u;
    }

    private function employee(string $last, string $first, ?string $email = null, ?User $user = null): Employee
    {
        return Employee::create(['company_id' => $this->company->id, 'last_name' => $last, 'first_name' => $first,
            'email' => $email, 'user_id' => $user?->id]);
    }

    public function test_suggests_by_email_then_by_name_and_only_when_unambiguous(): void
    {
        $dimitris = $this->named('ΚΩΣΤΟΠΟΥΛΟΣ δημητρης');
        $ilias = $this->named('Ηλίας Άλλος', email: 'ilias@firm.test');
        $this->named('Μαρία Ίδια');
        $this->named('Μαρία Ίδια');                       // two users, same name → ambiguous
        $outsider = $this->named('Ξένος Χρήστης', company: Company::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'country_code' => 'GR']));
        $linked = $this->named('Ήδη Συνδεδεμένος');

        $eD = $this->employee('Κωστόπουλος', 'Δημήτρης');
        $eI = $this->employee('Παπάς', 'Ηλίας', 'ILIAS@firm.test');   // email wins over (different) name
        $eM = $this->employee('Ίδια', 'Μαρία');
        $eX = $this->employee('Χρήστης', 'Ξένος');                  // user is in ANOTHER tenant
        $this->employee('Συνδεδεμένος', 'Ήδη', user: $linked);
        $eDup = $this->employee('Συνδεδεμένος', 'Ήδη');             // that user is taken

        $gone = $this->named('Πρώην Υπάλληλος');
        $this->employee('Υπάλληλος', 'Πρώην', user: $gone)->delete();   // soft-deleted, still owns the link
        $eGone = $this->employee('Υπάλληλος', 'Πρώην');

        $s = app(EmployeeAccountMatcher::class)->suggestions($this->company);

        $this->assertArrayNotHasKey($eGone->id, $s, 'a user linked to a deleted employee is still taken');
        $this->assertSame($dimitris->id, $s[$eD->id]->id);
        $this->assertSame($ilias->id, $s[$eI->id]->id);
        $this->assertArrayNotHasKey($eM->id, $s);
        $this->assertArrayNotHasKey($eX->id, $s, 'never suggest a user of another company');
        $this->assertArrayNotHasKey($eDup->id, $s, 'never suggest an already-linked user');
        $this->assertNotContains($outsider->id, array_map(fn (User $u) => $u->id, $s));
    }

    public function test_two_employees_claiming_one_user_get_no_suggestion(): void
    {
        $this->named('Νίκος Παπαδόπουλος');
        $a = $this->employee('Παπαδόπουλος', 'Νίκος');
        $b = $this->employee('Παπαδόπουλος', 'Νίκος');

        $s = app(EmployeeAccountMatcher::class)->suggestions($this->company);
        $this->assertArrayNotHasKey($a->id, $s);
        $this->assertArrayNotHasKey($b->id, $s);
    }

    public function test_admin_links_a_suggestion_and_a_stale_one_is_refused(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $dimitris = $this->named('Δημήτρης Κωστόπουλος');
        $e = $this->employee('Κωστόπουλος', 'Δημήτρης');

        Livewire::test(ListEmployees::class)
            ->assertSee('Πρόταση: Δημήτρης Κωστόπουλος')
            ->callTableAction('linkSuggested', $e, data: ['seen_user_id' => $dimitris->id + 999])
            ->assertNotified('Η πρόταση άλλαξε στο μεταξύ — δεν έγινε σύνδεση. Ανανεώστε τη σελίδα.');
        $this->assertNull($e->fresh()->user_id);

        Livewire::test(ListEmployees::class)->callTableAction('linkSuggested', $e);
        $this->assertSame($dimitris->id, $e->fresh()->user_id);
    }

    public function test_bulk_links_only_unambiguous_rows_and_warns_about_missing_role(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $a = $this->named('Άλφα Πρώτος');
        $this->named('Βήτα Δεύτερος');
        $this->named('Βήτα Δεύτερος');
        $ea = $this->employee('Πρώτος', 'Άλφα');
        $eb = $this->employee('Δεύτερος', 'Βήτα');

        Livewire::test(ListEmployees::class)->callTableBulkAction('linkSuggestedBulk', [$ea, $eb])
            ->assertNotified('Συνδέθηκαν 1 · παραλείφθηκαν 1 (χωρίς πρόταση ή ήδη συνδεδεμένοι)');
        $this->assertSame($a->id, $ea->fresh()->user_id);
        $this->assertNull($eb->fresh()->user_id);

        // A linked account without a leave-capable role is flagged.
        $noRole = User::create(['name' => 'Χωρίς Ρόλο', 'email' => 'norole@test.local', 'password' => bcrypt('x')]);
        $noRole->companies()->attach($this->company->id);
        $this->employee('Ρόλο', 'Χωρίς', user: $noRole);
        Livewire::test(ListEmployees::class)->assertSee('norole@test.local')->assertSee('χωρίς ρόλο για άδειες');
    }

    public function test_operators_cannot_link(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        $this->named('Δημήτρης Κωστόπουλος');
        $e = $this->employee('Κωστόπουλος', 'Δημήτρης');

        $this->assertFalse(auth()->user()->can('update', $e));
        $this->assertFalse(EmployeeResource::linkSuggestedAction()->record($e)->isVisible());
    }

    public function test_bulk_never_links_a_row_that_showed_no_suggestion(): void
    {
        // Reviewer's scenario: A has U's email; B has the same name as U and V (ambiguous → «—»).
        // Linking A first frees nothing for B in the operator's eyes — B must stay unlinked.
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $u = $this->named('Νίκος Παπαδόπουλος', email: 'nikos@firm.test');
        $this->named('Νίκος Παπαδόπουλος');
        $a = $this->employee('Παπαδόπουλος', 'Νίκος', 'nikos@firm.test');
        $b = $this->employee('Παπαδόπουλος', 'Νίκος');
        $this->assertNull(app(EmployeeAccountMatcher::class)->suggestionFor($b));

        Livewire::test(ListEmployees::class)->callTableBulkAction('linkSuggestedBulk', [$a, $b]);

        $this->assertSame($u->id, $a->fresh()->user_id);
        $this->assertNull($b->fresh()->user_id);
    }

    public function test_email_of_an_already_linked_user_blocks_the_name_fallback(): void
    {
        $u = $this->named('Νίκος Παπαδόπουλος', email: 'nikos@firm.test');
        $this->employee('Παπαδόπουλος', 'Νίκος', user: $u);
        $this->named('Άλλος Χρήστης');
        $dup = $this->employee('Χρήστης', 'Άλλος', 'nikos@firm.test');   // email → taken U; name → the other user

        $this->assertNull(app(EmployeeAccountMatcher::class)->suggestionFor($dup));
    }
}
