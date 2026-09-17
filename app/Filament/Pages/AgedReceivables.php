<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Accounting\AgedReceivablesReport;
use App\Services\Accounting\AgedReceivablesResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * «Ηλικίωση οφειλών» — aged-receivables report. Read-only: per-customer
 * outstanding balance bucketed 0-30 / 31-60 / 61-90 / 90+ days, biggest debtors
 * first, with footed column totals and a drill to each customer's Καρτέλα.
 *
 * Built on AgedReceivablesReport (which reuses the Καρτέλα FIFO ageing), so the
 * numbers agree with the per-customer view. Admin-gated on View:AgedReceivables.
 */
class AgedReceivables extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.aged-receivables';

    private ?AgedReceivablesResult $result = null;

    public static function getNavigationLabel(): string
    {
        return 'Ηλικίωση οφειλών';
    }

    public function getTitle(): string
    {
        return 'Ηλικίωση οφειλών';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:AgedReceivables');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Memoised per request (no filters; one build per page load / action). */
    public function getResult(): AgedReceivablesResult
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $this->result ??= app(AgedReceivablesReport::class)->build($tenant);
    }

    /**
     * #5 dunning Φάση A: per-row «Εργασία είσπραξης» — record who's chasing the
     * debt, the next step + date, a note, and (optionally) stamp today's contact.
     * Mounted from the aged-receivables row with the customer id in $arguments;
     * record-keeping only (no notifications/escalation — Φάση Γ). Tenant-scoped:
     * the customer is loaded WHERE company_id = tenant, so a forged id can't
     * touch another tenant's customer.
     */
    public function collectionAction(): Action
    {
        return Action::make('collection')
            ->label('Εργασία είσπραξης')
            ->icon('heroicon-o-phone-arrow-up-right')
            ->modalHeading('Εργασία είσπραξης')
            ->modalSubmitActionLabel('Αποθήκευση')
            ->schema([
                Select::make('collection_assigned_to')
                    ->label('Ανάθεση σε')
                    ->options(fn (): array => $this->operatorOptions())
                    ->searchable()
                    ->placeholder('— κανείς —'),
                DatePicker::make('collection_next_step_at')
                    ->label('Επόμενο βήμα (ημ/νία)')
                    ->native(false),
                TextInput::make('collection_next_step_note')
                    ->label('Επόμενο βήμα (τι)')
                    ->maxLength(255),
                Textarea::make('collection_note')
                    ->label('Σημείωση')
                    ->rows(3),
                Toggle::make('log_contact_today')
                    ->label('Καταγραφή επαφής σήμερα')
                    ->helperText('Ενημερώνει το «Τελ. επαφή» στη σημερινή ημερομηνία.'),
            ])
            ->fillForm(function (array $arguments): array {
                $c = $this->customer((int) ($arguments['customer'] ?? 0));

                return $c === null ? [] : [
                    'collection_assigned_to' => $c->collection_assigned_to,
                    'collection_next_step_at' => $c->collection_next_step_at,
                    'collection_next_step_note' => $c->collection_next_step_note,
                    'collection_note' => $c->collection_note,
                    'log_contact_today' => false,
                ];
            })
            ->action(function (array $arguments, array $data): void {
                $c = $this->customer((int) ($arguments['customer'] ?? 0));
                if ($c === null) {
                    return;
                }

                $c->collection_assigned_to = $data['collection_assigned_to'] ?: null;
                $c->collection_next_step_at = $data['collection_next_step_at'] ?: null;
                $c->collection_next_step_note = $data['collection_next_step_note'] ?: null;
                $c->collection_note = $data['collection_note'] ?: null;
                if (! empty($data['log_contact_today'])) {
                    $c->collection_last_contact_at = now()->toDateString();
                }
                $c->save();

                $this->result = null; // rebuild the report so the row reflects the change

                Notification::make()
                    ->title('Ενημερώθηκε η εργασία είσπραξης — '.$c->name)
                    ->success()
                    ->send();
            });
    }

    /** Tenant users, id => name, for the «Ανάθεση σε» select. */
    private function operatorOptions(): array
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            ? $tenant->users()->orderBy('name')->pluck('name', 'users.id')->all()
            : [];
    }

    /** Load one customer scoped to the current tenant (null = not this tenant / not found). */
    private function customer(int $id): ?Customer
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company || $id <= 0) {
            return null;
        }

        return Customer::query()
            ->where('company_id', $tenant->getKey())
            ->whereKey($id)
            ->first();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Εξαγωγή CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->button()
                ->action(fn () => $this->exportCsv()),
        ];
    }

    private function exportCsv(): StreamedResponse
    {
        $result = $this->getResult();
        $name = 'ilikiosi-ofeilon-'.now()->format('Y-m-d').'.csv';
        $num = fn ($v): string => number_format((float) $v, 2, ',', '');
        // Neutralise CSV formula injection in the free-text name (=,+,-,@ → quote).
        $safe = fn (string $v): string => ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) ? "'".$v : $v;

        return response()->streamDownload(function () use ($result, $num, $safe): void {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($h, ['Πελάτης', 'ΑΦΜ', '0-30', '31-60', '61-90', '90+', 'Σύνολο', 'Παλαιότερο (ημέρες)'], ';', escape: '');
            foreach ($result->rows as $row) {
                fputcsv($h, [
                    $safe($row->customerName), $safe($row->afm ?? ''),
                    $num($row->b0_30), $num($row->b31_60), $num($row->b61_90), $num($row->b90plus),
                    $num($row->total), $row->oldestDays ?? '',
                ], ';', escape: '');
            }
            fputcsv($h, [], ';', escape: '');
            fputcsv($h, [
                'Σύνολα', '',
                $num($result->total0_30()), $num($result->total31_60()), $num($result->total61_90()),
                $num($result->total90plus()), $num($result->grandTotal()), '',
            ], ';', escape: '');
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
