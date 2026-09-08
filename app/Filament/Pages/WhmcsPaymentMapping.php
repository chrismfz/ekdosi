<?php

namespace App\Filament\Pages;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Filament\Clusters\SettingsCluster;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\WhmcsPaymentMap;
use App\Services\Whmcs\WhmcsClientFactory;
use App\Support\MyData\Codes;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * «Αντιστοίχιση WHMCS → τρόπος πληρωμής» — the §8.12 payment-means twin of the
 * income-mapping page. Pulls the tenant's active WHMCS gateways (GetPaymentMethods)
 * and lets the operator declare, per gateway, WHICH ekdosi payment method a WHMCS
 * invoice on that gateway is issued with. The ekdosi method carries the §8.12 type
 * (and the due-days term), so a card/bank-paid WHMCS invoice files with the right
 * payment means instead of the invoice-type cash default. Saved to
 * {@see WhmcsPaymentMap}; the inbox mapper resolves gateway→method at ingest.
 *
 * WHMCS setup surface → gated on `update` on the Company (super_admin) AND a
 * configured WHMCS integration. The gateway fetch uses the standard WHMCS
 * `GetPaymentMethods` API (native creds), like the income-mapping page.
 */
class WhmcsPaymentMapping extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Τιμολόγηση & πληρωμές';

    protected static ?int $navigationSort = 83;

    protected string $view = 'filament.pages.whmcs-payment-mapping';

    /** @var list<array{gateway:string, name:string}> */
    public array $gateways = [];

    /** @var array<string, string> gateway => payment_method_id ('' = μη αντιστοιχισμένο) */
    public array $choice = [];

    public bool $fetched = false;

    public function mount(): void
    {
        $saved = WhmcsPaymentMap::query()
            ->where('company_id', $this->tenant()->getKey())
            ->get(['whmcs_gateway', 'payment_method_id', 'label']);

        $this->choice = $saved
            ->mapWithKeys(fn (WhmcsPaymentMap $m) => [
                WhmcsPaymentMap::normaliseGateway($m->whmcs_gateway) => (string) $m->payment_method_id,
            ])
            ->all();

        // Show the already-mapped gateways even before a fetch (so an unfetched
        // visit still lets the operator review/unmap them).
        $this->gateways = $saved
            ->map(fn (WhmcsPaymentMap $m) => [
                'gateway' => WhmcsPaymentMap::normaliseGateway($m->whmcs_gateway),
                'name' => (string) ($m->label ?: $m->whmcs_gateway),
            ])
            ->values()
            ->all();
        $this->fetched = $this->gateways !== [];
    }

    /**
     * The tenant's SETTLED (due_days = 0) payment methods as Select options, each
     * showing its §8.12 type. Only settled methods are offered: a mapped gateway
     * applies to a PAID WHMCS invoice, and a credit-term method would leave a paid
     * invoice reading as an open receivable. '' = leave to the invoice type default.
     *
     * @return array<string, string>
     */
    public function paymentMethodOptions(): array
    {
        $out = ['' => '— κληρονομεί τον τύπο παραστατικού —'];

        $this->settledMethods()
            ->each(function (PaymentMethod $m) use (&$out): void {
                $type = (int) $m->mydata_payment_type;
                $out[(string) $m->id] = (string) $m->description
                    .' — §8.12 τύπος '.$type.' ('.(Codes::PAYMENT_METHODS[$type] ?? '?').')';
            });

        return $out;
    }

    /**
     * The tenant's SETTLED (due_days = 0) payment methods that carry a §8.12 type —
     * the only valid mapping targets. A §8.12-less method would file as cash (type 3),
     * defeating the map's purpose, so it is excluded (set its code in «Τρόποι Πληρωμής»
     * or run mydata:backfill-config first).
     *
     * @return Collection<int, PaymentMethod>
     */
    private function settledMethods()
    {
        return PaymentMethod::query()
            ->where('company_id', $this->tenant()->getKey())
            ->where('due_days', 0)
            ->whereNotNull('mydata_payment_type')
            ->orderBy('description')
            ->get(['id', 'description', 'mydata_payment_type']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetch')
                ->label('Άντληση τρόπων πληρωμής WHMCS')
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
            $methods = app(WhmcsClientFactory::class)->for($this->tenant())->getPaymentMethods();
        } catch (WhmcsNotConfigured|WhmcsApiException $e) {
            Notification::make()->title('Αποτυχία άντλησης από WHMCS')->body($e->getMessage())->danger()->send();

            return;
        }

        // Merge the active gateways over any already-mapped ones (a mapped gateway
        // that is no longer active still shows, so it can be reviewed/unmapped).
        $byGateway = [];
        foreach ($this->gateways as $g) {
            $byGateway[$g['gateway']] = $g; // saved-but-inactive, from mount
        }
        foreach ($methods as $m) {
            $gateway = WhmcsPaymentMap::normaliseGateway($m['gateway']);
            if ($gateway === '') {
                continue;
            }
            $byGateway[$gateway] = ['gateway' => $gateway, 'name' => (string) $m['name']];
        }

        usort($byGateway, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $this->gateways = array_values($byGateway);
        $this->fetched = true;

        foreach ($this->gateways as $g) {
            $this->choice[$g['gateway']] ??= '';
        }

        Notification::make()
            ->title(count($methods).' τρόποι πληρωμής WHMCS αντλήθηκαν')
            ->success()->send();
    }

    public function save(): void
    {
        $companyId = $this->tenant()->getKey();
        // Only accept a SETTLED method of THIS tenant (guards a crafted Livewire value
        // pointing at another tenant's — or a credit-term — method; the latter would
        // make a paid invoice a phantom receivable).
        $validMethodIds = $this->settledMethods()->pluck('id')->map(fn ($v) => (string) $v)->all();

        $labelByGateway = [];
        foreach ($this->gateways as $g) {
            $labelByGateway[$g['gateway']] = $g['name'];
        }

        $set = 0;
        $cleared = 0;
        foreach ($this->choice as $gateway => $pmid) {
            $gateway = WhmcsPaymentMap::normaliseGateway($gateway);
            if ($gateway === '') {
                continue;
            }
            $pmid = (string) $pmid;

            if ($pmid === '') {
                // Explicit unmap.
                $cleared += WhmcsPaymentMap::query()
                    ->where('company_id', $companyId)
                    ->where('whmcs_gateway', $gateway)
                    ->delete();

                continue;
            }
            if (! in_array($pmid, $validMethodIds, true)) {
                // Non-empty but not a valid settled+§8.12 method (e.g. the mapped
                // method later changed term/§8.12): LEAVE the stored row untouched
                // rather than silently dropping a mapping the operator didn't clear.
                // The resolver already ignores an invalid target, so it's inert.
                continue;
            }

            // Only overwrite `label` when we have a genuine friendly name (a fetch
            // this visit); without one, labelByGateway holds the gateway key, so keep
            // the stored label instead of degrading it to the system name.
            $label = $labelByGateway[$gateway] ?? null;
            $attrs = ['payment_method_id' => (int) $pmid];
            if ($label !== null && $label !== $gateway) {
                $attrs['label'] = $label;
            }
            WhmcsPaymentMap::updateOrCreate(
                ['company_id' => $companyId, 'whmcs_gateway' => $gateway],
                $attrs,
            );
            $set++;
        }

        Notification::make()
            ->title("Αποθηκεύτηκαν {$set} αντιστοιχίσεις".($cleared > 0 ? " ({$cleared} καθαρίστηκαν)" : ''))
            ->success()->send();
    }

    public static function getNavigationLabel(): string
    {
        return 'Αντιστοίχιση WHMCS (πληρωμές)';
    }

    public function getTitle(): string
    {
        return 'Αντιστοίχιση WHMCS → τρόπος πληρωμής';
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
