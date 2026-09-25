<?php

namespace App\Filament\Pages;

use App\Jobs\ImportCnCatalog;
use App\Models\CnCode;
use App\Models\Company;
use App\Models\Product;
use App\Services\Taric\CnCatalog;
use App\Services\TenantRoleProvisioner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * «Κωδικοί ΣΟ / TARIC» — the Συνδυασμένη Ονοματολογία reference list behind the products'
 * TARIC (Ενιαία Κωδικοποίηση Ειδών, mandatory 1/1/2027): which year is loaded, the THIS
 * tenant's products whose code no longer exists in it, and — super_admin only, since the
 * list is GLOBAL — «Ενημέρωση από ΕΕ», which queues the yearly download (ImportCnCatalog).
 * Gated on View:CnCatalogPage (shield:generate + re-provision after deploy).
 */
class CnCatalogPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|UnitEnum|null $navigationGroup = 'Είδη & Προμήθειες';

    protected static ?string $navigationLabel = 'Κωδικοί ΣΟ / TARIC';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'cn-codes';

    protected string $view = 'filament.pages.cn-catalog';

    public function getTitle(): string
    {
        return 'Κωδικοί ΣΟ / TARIC';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:CnCatalogPage');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function isSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TenantRoleProvisioner::class)->isSuperAdminAnywhere($user);
    }

    /** @return array{year: ?int, count: int, years: array<int, int>} */
    public function stats(): array
    {
        $catalog = app(CnCatalog::class);
        $year = $catalog->latestYear();

        return [
            'year' => $year,
            'count' => $year ? CnCode::query()->where('year', $year)->count() : 0,
            'years' => CnCode::query()->selectRaw('year, count(*) as n')->groupBy('year')->orderByDesc('year')->pluck('n', 'year')->all(),
        ];
    }

    /** @return Collection<int, Product> */
    public function obsolete()
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return app(CnCatalog::class)->obsoleteProducts($tenant);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh_eu')
                ->label('Ενημέρωση από ΕΕ')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => static::isSuperAdmin())
                ->modalHeading('Ενημέρωση κωδικών ΣΟ από την ΕΕ')
                ->modalDescription('Κατεβάζει την επίσημη Συνδυασμένη Ονοματολογία του έτους (data.europa.eu, ≈170 MB) και αντικαθιστά τους κωδικούς του έτους. Τρέχει στο παρασκήνιο· θα λάβεις ειδοποίηση. Η ΣΟ του επόμενου έτους δημοσιεύεται συνήθως τον Οκτώβριο.')
                ->schema([
                    TextInput::make('year')->label('Έτος')->numeric()->integer()
                        ->minValue(2020)->maxValue(now()->year + 1)->required()
                        ->default(now()->month >= 10 ? now()->year + 1 : now()->year),
                ])
                ->action(function (array $data): void {
                    abort_unless(static::isSuperAdmin(), 403);
                    ImportCnCatalog::dispatch((int) $data['year'], auth()->id());
                    Notification::make()->title('Η ενημέρωση ξεκίνησε')->body('Θα λάβεις ειδοποίηση όταν ολοκληρωθεί.')->success()->send();
                }),
        ];
    }
}
