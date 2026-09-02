<?php

namespace App\Filament\Pages;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Models\Company;
use App\Models\WhmcsIncomeMap;
use App\Services\Whmcs\WhmcsClientFactory;
use App\Support\MyData\ClassificationGuidance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * «Αντιστοίχιση WHMCS → κατηγορία εσόδων» — the MYD-006 bridge mapping. Pulls the
 * tenant's WHMCS product catalogue and lets the operator declare, PER GROUP, what
 * its lines are (υπηρεσία / εμπόρευμα / δικό μας προϊόν = §8.6 category1_3/1/2).
 * New packages in a mapped group inherit the choice, so nothing is re-mapped per
 * package. Saved to {@see WhmcsIncomeMap} (scope=group); the inbox mapper stamps
 * each ingested line's classification from it.
 *
 * WHMCS setup surface → gated on `update` on the Company (super_admin, like the
 * rest of WHMCS configuration) AND a configured WHMCS integration. The catalogue
 * fetch uses the standard WHMCS `GetProducts` API, so it works for bridge and
 * native tenants alike.
 */
class WhmcsIncomeMapping extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-square-3-stack-3d';

    protected static ?int $navigationSort = 82;

    protected string $view = 'filament.pages.whmcs-income-mapping';

    /** @var list<array{gid:int, name:string, packages:list<string>}> */
    public array $groups = [];

    /** @var array<string, string> gid => §8.6 category ('' = μη αντιστοιχισμένο) */
    public array $choice = [];

    public bool $fetched = false;

    public function mount(): void
    {
        // Pre-load the saved group choices so a fetch shows them selected and an
        // unfetched visit still remembers what was mapped.
        $this->choice = WhmcsIncomeMap::query()
            ->where('company_id', $this->tenant()->getKey())
            ->where('scope', WhmcsIncomeMap::SCOPE_GROUP)
            ->get(['whmcs_key', 'income_class_category'])
            ->mapWithKeys(fn (WhmcsIncomeMap $m) => [(string) $m->whmcs_key => (string) $m->income_class_category])
            ->all();
    }

    /** @return array<string, string> */
    public function bucketOptions(): array
    {
        return ['' => '— δεν έχει οριστεί (κληρονομεί τύπο) —'] + ClassificationGuidance::bucketOptions();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetch')
                ->label('Άντληση προϊόντων WHMCS')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->fetch()),
            Action::make('save')
                ->label('Αποθήκευση')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->visible(fn (): bool => $this->fetched)
                ->action(fn () => $this->save()),
        ];
    }

    public function fetch(): void
    {
        try {
            $products = app(WhmcsClientFactory::class)->for($this->tenant())->getProducts();
        } catch (WhmcsNotConfigured|WhmcsApiException $e) {
            Notification::make()->title('Αποτυχία άντλησης από WHMCS')->body($e->getMessage())->danger()->send();

            return;
        }

        // Group the flat product list by WHMCS group (gid + groupname), collecting
        // each group's package names for display.
        $byGid = [];
        foreach ($products as $p) {
            $gid = (int) $p['gid'];
            if ($gid <= 0) {
                continue; // ungrouped products can't be group-mapped; skip.
            }
            $byGid[$gid] ??= ['gid' => $gid, 'name' => (string) $p['groupname'], 'packages' => []];
            if ($byGid[$gid]['name'] === '' && $p['groupname'] !== '') {
                $byGid[$gid]['name'] = (string) $p['groupname'];
            }
            if ($p['name'] !== '') {
                $byGid[$gid]['packages'][] = (string) $p['name'];
            }
        }

        usort($byGid, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $this->groups = array_values($byGid);
        $this->fetched = true;

        // Keep saved choices; default any newly-seen group to «not set».
        foreach ($this->groups as $g) {
            $this->choice[(string) $g['gid']] ??= '';
        }

        Notification::make()
            ->title(count($this->groups).' ομάδες προϊόντων WHMCS αντλήθηκαν')
            ->success()->send();
    }

    public function save(): void
    {
        $companyId = $this->tenant()->getKey();
        $valid = array_keys(ClassificationGuidance::BUCKET_LABELS); // category1_1/1_2/1_3
        $labelByGid = [];
        foreach ($this->groups as $g) {
            $labelByGid[(int) $g['gid']] = $g['name'];
        }

        $set = 0;
        $cleared = 0;
        foreach ($this->choice as $gid => $category) {
            $gid = (int) $gid;
            if ($gid <= 0) {
                continue;
            }

            if (in_array($category, $valid, true)) {
                WhmcsIncomeMap::updateOrCreate(
                    ['company_id' => $companyId, 'scope' => WhmcsIncomeMap::SCOPE_GROUP, 'whmcs_key' => $gid],
                    ['income_class_category' => $category, 'label' => $labelByGid[$gid] ?? null],
                );
                $set++;
            } else {
                // Empty / invalid → remove any existing mapping (unmap the group).
                $deleted = WhmcsIncomeMap::query()
                    ->where('company_id', $companyId)
                    ->where('scope', WhmcsIncomeMap::SCOPE_GROUP)
                    ->where('whmcs_key', $gid)
                    ->delete();
                $cleared += $deleted;
            }
        }

        Notification::make()
            ->title("Αποθηκεύτηκαν {$set} αντιστοιχίσεις".($cleared > 0 ? " ({$cleared} καθαρίστηκαν)" : ''))
            ->success()->send();
    }

    public static function getNavigationLabel(): string
    {
        return 'Αντιστοίχιση WHMCS (έσοδα)';
    }

    public function getTitle(): string
    {
        return 'Αντιστοίχιση WHMCS → κατηγορία εσόδων';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Setup';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->hasWhmcsIntegration()
            && (bool) auth()->user()?->can('update', $tenant);
    }

    private function tenant(): Company
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            abort(403);
        }

        return $tenant;
    }
}
