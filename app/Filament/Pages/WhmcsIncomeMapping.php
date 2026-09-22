<?php

namespace App\Filament\Pages;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Filament\Clusters\SettingsCluster;
use App\Models\Company;
use App\Models\ProductCategory;
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

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Τιμολόγηση & πληρωμές';

    protected static ?int $navigationSort = 82;

    protected string $view = 'filament.pages.whmcs-income-mapping';

    /** @var list<array{gid:int, name:string, packages:list<string>}> */
    public array $groups = [];

    /** @var array<string, string> gid => §8.6 category ('' = μη αντιστοιχισμένο) */
    public array $choice = [];

    /** @var array<string, string> gid => ekdosi ProductCategory id ('' = καμία) */
    public array $categoryChoice = [];

    public bool $fetched = false;

    public function mount(): void
    {
        // Pre-load the saved group choices so a fetch shows them selected and an
        // unfetched visit still remembers what was mapped (both the §8.6 class and
        // the ekdosi revenue-report category).
        $this->hydrateSavedChoices();
    }

    /**
     * (Re)load choice + categoryChoice from the persisted rows. Called on mount AND
     * after save() so the form always reflects DB truth — otherwise a group unmapped
     * this session keeps its (now orphaned) categoryChoice in Livewire state, and a
     * later save would re-warn «κατηγορία δεν αποθηκεύτηκε» for a group the operator
     * already cleared.
     */
    private function hydrateSavedChoices(): void
    {
        $rows = WhmcsIncomeMap::query()
            ->where('company_id', $this->tenant()->getKey())
            ->where('scope', WhmcsIncomeMap::SCOPE_GROUP)
            ->get(['whmcs_key', 'income_class_category', 'product_category_id']);

        $this->choice = $rows
            ->mapWithKeys(fn (WhmcsIncomeMap $m) => [(string) $m->whmcs_key => (string) $m->income_class_category])
            ->all();
        $this->categoryChoice = $rows
            ->mapWithKeys(fn (WhmcsIncomeMap $m) => [(string) $m->whmcs_key => (string) ($m->product_category_id ?? '')])
            ->all();
    }

    /** @return array<string, string> */
    public function bucketOptions(): array
    {
        return ['' => '— δεν έχει οριστεί (κληρονομεί τύπο) —'] + ClassificationGuidance::bucketOptions();
    }

    /**
     * The tenant's ekdosi ProductCategory list for the «Κατηγορία ekdosi» column —
     * the reporting axis for «Έσοδα ανά κατηγορία». id => label.
     *
     * @return array<string, string>
     */
    public function categoryOptions(): array
    {
        $cats = ProductCategory::query()
            ->where('company_id', $this->tenant()->getKey())
            ->orderBy('description_short')
            ->get(['id', 'description_short', 'description'])
            ->mapWithKeys(fn (ProductCategory $c): array => [
                (string) $c->id => (string) ($c->description_short ?: $c->description ?: ('#'.$c->id)),
            ])
            ->all();

        return ['' => '— καμία —'] + $cats;
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

        // Built lazily on the first discard only (the common save has none).
        $categoryOptions = null;

        $set = 0;
        $cleared = 0;
        // Groups where the operator picked a «Κατηγορία ekdosi» but left §8.6 empty:
        // that category selection is silently discarded (a row can't exist without a
        // §8.6 class — income_class_category is NOT NULL), so we collect + warn.
        $discarded = [];
        foreach ($this->choice as $gid => $category) {
            $gid = (int) $gid;
            if ($gid <= 0) {
                continue;
            }

            if (in_array($category, $valid, true)) {
                // The ekdosi report category rides on the same row (which needs a
                // §8.6 class — income_class_category is NOT NULL). '' → null (καμία).
                $rawCat = $this->categoryChoice[(string) $gid] ?? '';
                $productCategoryId = ctype_digit((string) $rawCat) ? (int) $rawCat : null;

                WhmcsIncomeMap::updateOrCreate(
                    ['company_id' => $companyId, 'scope' => WhmcsIncomeMap::SCOPE_GROUP, 'whmcs_key' => $gid],
                    [
                        'income_class_category' => $category,
                        'product_category_id' => $productCategoryId,
                        'label' => $labelByGid[$gid] ?? null,
                    ],
                );
                $set++;
            } else {
                // Empty / invalid §8.6 → the group will be unmapped. If a «Κατηγορία
                // ekdosi» was ALSO chosen, that choice can't be saved on its own (no
                // row without a §8.6 class) → flag it, UNLESS it merely equals the
                // category already saved (the prehydrated leftover of a deliberate
                // unmap — warning there would be noise). Read the saved category only
                // when a category is chosen, so the common «nothing set» group still
                // costs just the delete below (no extra SELECT).
                $rawCat = $this->categoryChoice[(string) $gid] ?? '';
                $chosenCatId = ctype_digit((string) $rawCat) ? (int) $rawCat : null;
                if ($chosenCatId !== null) {
                    // NB: cast — MariaDB returns int columns as strings under the
                    // default emulated prepares, so a strict `!==` against the int
                    // chosenCatId would false-positive on an unchanged category.
                    $savedCatId = WhmcsIncomeMap::query()
                        ->where('company_id', $companyId)
                        ->where('scope', WhmcsIncomeMap::SCOPE_GROUP)
                        ->where('whmcs_key', $gid)
                        ->value('product_category_id');
                    $savedCatId = $savedCatId === null ? null : (int) $savedCatId;
                    if ($chosenCatId !== $savedCatId) {
                        $categoryOptions ??= $this->categoryOptions();
                        $discarded[] = '«'.($labelByGid[$gid] ?? ('#'.$gid)).'» → '
                            .($categoryOptions[(string) $rawCat] ?? ('#'.$rawCat));
                    }
                }

                $cleared += WhmcsIncomeMap::query()
                    ->where('company_id', $companyId)
                    ->where('scope', WhmcsIncomeMap::SCOPE_GROUP)
                    ->where('whmcs_key', $gid)
                    ->delete();
            }
        }

        // ONE notification: when a category was discarded, the persistent warning is
        // THE signal (a green «saved» toast beside it would imply everything worked,
        // hiding the partial drop) — it folds in what DID save; otherwise the plain
        // success toast.
        if ($discarded !== []) {
            $savedNote = ($set > 0 || $cleared > 0)
                ? ' Αποθηκεύτηκαν κανονικά '.$set.($cleared > 0 ? ", καθαρίστηκαν {$cleared}" : '').'.'
                : '';
            Notification::make()
                ->title(count($discarded).' ομάδα/ες: η «Κατηγορία ekdosi» δεν αποθηκεύτηκε')
                ->body('Όρισες κατηγορία εσόδων χωρίς αντίστοιχη §8.6 — δεν αποθηκεύεται χωρίς τη §8.6. Όρισε και τη §8.6 για: '.implode(' · ', $discarded).'.'.$savedNote)
                ->warning()
                ->persistent()
                ->send();
        } else {
            Notification::make()
                ->title("Αποθηκεύτηκαν {$set} αντιστοιχίσεις".($cleared > 0 ? " ({$cleared} καθαρίστηκαν)" : ''))
                ->success()->send();
        }

        // Re-sync the form to persisted state: a group unmapped this save leaves no
        // row, so its orphaned categoryChoice would otherwise re-trigger the warning
        // on the next save. (Runs AFTER the discard scan, which needs the pre-save state.)
        $this->hydrateSavedChoices();
    }

    public static function getNavigationLabel(): string
    {
        return 'Αντιστοίχιση WHMCS (έσοδα)';
    }

    public function getTitle(): string
    {
        return 'Αντιστοίχιση WHMCS → κατηγορία εσόδων';
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
