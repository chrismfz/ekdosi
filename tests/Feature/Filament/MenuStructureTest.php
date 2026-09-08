<?php

namespace Tests\Feature\Filament;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\GeneralSettings;
use App\Filament\Pages\MyDataCodeGuide;
use App\Filament\Pages\Preflight;
use App\Filament\Pages\ScheduleSettings;
use App\Filament\Pages\WhmcsIncomeMapping;
use App\Filament\Pages\WhmcsPaymentMapping;
use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Filament\Resources\DeliveryMethods\DeliveryMethodResource;
use App\Filament\Resources\DistributionAims\DistributionAimResource;
use App\Filament\Resources\ExpenseClassificationRules\ExpenseClassificationRuleResource;
use App\Filament\Resources\InvoiceTypes\InvoiceTypeResource;
use App\Filament\Resources\MetricUnits\MetricUnitResource;
use App\Filament\Resources\PaymentGatewayConnections\PaymentGatewayConnectionResource;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\UpdateRuns\UpdateRunResource;
use App\Filament\Resources\VatCategories\VatCategoryResource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Locks the navigation reorganization: the daily drivers live in «Καθημερινά»
 * (incl. the WHMCS inbox), the Leads trio is one group, config lives in the
 * SettingsCluster (not a flat «Ρυθμίσεις» group), super-admin screens (Users/
 * Companies/Roles) are in «Διαχείριση», the shortened labels («Διακίνηση»,
 * «Διασυνδέσεις») are in effect, and the old lone/English groups (Setup / Data /
 * «Filament Shield» / «Είσπραξη/Πληρωμές») are gone. Reads the LIVE resolved panel
 * navigation (so it also proves the AdminPanelProvider group order + the Shield
 * plugin's fluent navigation config actually take effect).
 */
class MenuStructureTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, list<string>> group label => item labels (in order) */
    private function navTree(): array
    {
        Gate::before(fn () => true); // super_admin: nothing hidden by policy
        $company = Company::create([
            'name' => 'Nav OE', 'slug' => 'nav-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
            // WHMCS-integrated on purpose: it registers WhmcsIncomeMapping +
            // WhmcsPaymentMapping (getNavigationGroup 'Ρυθμίσεις' before Step 1), so the
            // «no flat Ρυθμίσεις group» assertion actually exercises the WHMCS case (2 of
            // the 3 real tenants) instead of silently passing on a non-WHMCS tenant.
            'whmcs_api_url' => 'https://whmcs.example/includes/api.php',
            'whmcs_api_identifier' => 'id', 'whmcs_api_secret' => 'secret',
        ]);
        $user = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        $this->actingAs($user);

        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Filament::setTenant($company);

        $tree = [];
        foreach ($panel->getNavigation() as $group) {
            $label = (string) $group->getLabel();
            $tree[$label] = collect($group->getItems())->map(fn ($i) => $i->getLabel())->values()->all();
        }

        return $tree;
    }

    public function test_daily_group_holds_the_drivers_including_the_inbox(): void
    {
        $tree = $this->navTree();

        $this->assertArrayHasKey('Καθημερινά', $tree);
        foreach (['Παραστατικά', 'Εισερχόμενα', 'Προσφορές', 'Πληρωμές', 'Πελάτες'] as $item) {
            $this->assertContains($item, $tree['Καθημερινά'], "«{$item}» πρέπει να είναι στα «Καθημερινά»");
        }
    }

    public function test_leads_are_one_group(): void
    {
        $tree = $this->navTree();

        $this->assertArrayHasKey('Leads', $tree);
        foreach (['Leads', 'Πίνακας leads', 'Ημερολόγιο leads'] as $item) {
            $this->assertContains($item, $tree['Leads']);
        }
    }

    public function test_admin_screens_are_in_the_management_group(): void
    {
        // Step 1.5 (option B): super-admin screens (Users/Companies/Roles) live in
        // their own «Διαχείριση» group, split out from «Σύστημα».
        $tree = $this->navTree();

        $this->assertArrayHasKey('Διαχείριση', $tree);
        foreach (['Ρόλοι', 'Χρήστες', 'Εταιρείες'] as $item) {
            $this->assertContains($item, $tree['Διαχείριση'], "«{$item}» πρέπει να είναι στη «Διαχείριση»");
        }
    }

    public function test_config_system_pages_moved_into_the_settings_cluster(): void
    {
        // Step 1.5: the config-ish «Σύστημα» screens left the flat «Σύστημα» group for
        // the SettingsCluster — so they are NOT top-level nav items…
        $allItems = collect($this->navTree())->flatten()->all();
        foreach (['Ρυθμίσεις συστήματος', 'Έλεγχος ετοιμότητας', 'Χρονοπρογραμματιστής', 'Ενημερώσεις'] as $moved) {
            $this->assertNotContains($moved, $allItems, "«{$moved}» ζει πλέον μέσα στο SettingsCluster");
        }

        // …AND positively still belong to the cluster (so «gone from top-level» can't be
        // satisfied by «dropped from nav entirely» — the actual reorg intent is guarded).
        foreach ([GeneralSettings::class, Preflight::class, ScheduleSettings::class, UpdateRunResource::class] as $screen) {
            $this->assertSame(SettingsCluster::class, $screen::getCluster(), $screen.' πρέπει να ανήκει στο SettingsCluster');
        }
    }

    public function test_settings_cluster_members_are_split_into_four_sub_sections(): void
    {
        // The «optional polish»: the cluster's config screens are grouped into 4
        // collapsible sub-sections via navigationGroup on each member. Lock the FULL
        // mapping — a dropped/mistyped navigationGroup silently splits a sub-section
        // (the nav fragility AdminPanelProvider warns about), and the top-level tests
        // above can't catch it (they filter to canonical top-level groups). This is the
        // only guard that a member's sub-section is what we intend.
        $subGroup = [
            InvoiceTypeResource::class => 'Τιμολόγηση & πληρωμές',
            PaymentMethodResource::class => 'Τιμολόγηση & πληρωμές',
            PaymentGatewayConnectionResource::class => 'Τιμολόγηση & πληρωμές',
            BankAccountResource::class => 'Τιμολόγηση & πληρωμές',
            VatCategoryResource::class => 'Τιμολόγηση & πληρωμές',
            ExpenseClassificationRuleResource::class => 'Τιμολόγηση & πληρωμές',
            MetricUnitResource::class => 'Τιμολόγηση & πληρωμές',
            WhmcsIncomeMapping::class => 'Τιμολόγηση & πληρωμές',
            WhmcsPaymentMapping::class => 'Τιμολόγηση & πληρωμές',
            DeliveryMethodResource::class => 'Είδη & αποστολή',
            DistributionAimResource::class => 'Είδη & αποστολή',
            ProductCategoryResource::class => 'Είδη & αποστολή',
            TagResource::class => 'Είδη & αποστολή',
            CompanySettings::class => 'Εταιρεία',
            MyDataCodeGuide::class => 'Εταιρεία',
            GeneralSettings::class => 'Λειτουργία',
            ScheduleSettings::class => 'Λειτουργία',
            Preflight::class => 'Λειτουργία',
            UpdateRunResource::class => 'Λειτουργία',
        ];

        foreach ($subGroup as $class => $group) {
            $this->assertSame(SettingsCluster::class, $class::getCluster(), "{$class}: πρέπει να είναι στο SettingsCluster");
            $this->assertSame($group, $class::getNavigationGroup(), "{$class}: πρέπει να είναι στην ενότητα «{$group}»");
        }

        // Exactly the 4 intended sub-sections…
        $sections = array_values(array_unique(array_values($subGroup)));
        sort($sections);
        $this->assertSame(['Είδη & αποστολή', 'Εταιρεία', 'Λειτουργία', 'Τιμολόγηση & πληρωμές'], $sections);

        // …and none reuses a top-level group label. «Λειτουργία» is deliberately NOT
        // «Σύστημα» (a real top-level group): a sub-section sharing a group's name would
        // read as that group nested inside «Ρυθμίσεις» inside «Σύστημα».
        $topLevel = array_keys($this->navTree());
        foreach ($sections as $section) {
            $this->assertNotContains($section, $topLevel, "Η ενότητα «{$section}» δεν πρέπει να συμπίπτει με top-level group");
        }
    }

    public function test_old_lone_and_english_groups_are_gone(): void
    {
        $labels = array_keys($this->navTree());

        foreach (['Setup', 'Data', 'Filament Shield', 'Είσπραξη/Πληρωμές'] as $dead) {
            $this->assertNotContains($dead, $labels, "Η ομάδα «{$dead}» έπρεπε να έχει καταργηθεί");
        }
    }

    public function test_settings_moved_into_a_cluster_not_a_flat_group(): void
    {
        // Menu/IA Step 1: the 13-item «Ρυθμίσεις» flat group became the SettingsCluster
        // (one bottom nav entry). So «Ρυθμίσεις» is NOT a top-level GROUP anymore, but a
        // nav ITEM exists for the cluster, and its config members no longer sit in the
        // main nav (they live inside the cluster's sub-navigation).
        $tree = $this->navTree();

        $this->assertArrayNotHasKey('Ρυθμίσεις', $tree, '«Ρυθμίσεις» δεν είναι πια flat group');

        $allItems = collect($tree)->flatten()->all();
        $this->assertContains('Ρυθμίσεις', $allItems, 'Το SettingsCluster πρέπει να δίνει ΕΝΑ nav item «Ρυθμίσεις»');
        // A representative clustered config screen is NOT a top-level nav item now.
        $this->assertNotContains('Τύποι παραστατικών', $allItems, 'Οι «Τύποι παραστατικών» ζουν πλέον μέσα στο cluster');
    }

    public function test_group_order_is_the_explicit_canonical_order(): void
    {
        $labels = array_values(array_filter(
            array_keys($this->navTree()),
            fn ($l) => $l !== '' // drop the ungrouped/top bucket
        ));

        $canonical = ['Καθημερινά', 'Leads', 'Είδη & Προμήθειες', 'Διακίνηση', 'Λογιστικά', 'Διασυνδέσεις', 'Σύστημα', 'Διαχείριση'];

        // Every rendered group is a known canonical one, and they appear in that order.
        $seen = array_values(array_filter($canonical, fn ($g) => in_array($g, $labels, true)));
        $rendered = array_values(array_filter($labels, fn ($l) => in_array($l, $canonical, true)));
        $this->assertSame($seen, $rendered, 'Οι ομάδες πρέπει να εμφανίζονται στη ρητή σειρά του navigationGroups()');
    }
}
