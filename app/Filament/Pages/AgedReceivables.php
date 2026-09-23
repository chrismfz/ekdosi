<?php

namespace App\Filament\Pages;

use App\Filament\Resources\InvoiceReminders\InvoiceReminderResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceReminder;
use App\Services\Accounting\AgedReceivablesReport;
use App\Services\Accounting\AgedReceivablesResult;
use App\Services\Reminders\ReminderInsights;
use App\Services\Reminders\ReminderPlanner;
use App\Services\Reminders\ReminderRunner;
use App\Services\Reminders\ReminderSettings;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * «Ηλικίωση οφειλών» — aged-receivables report: per-customer outstanding balance
 * bucketed 0-30 / 31-60 / 61-90 / 90+ days, biggest debtors first, with footed
 * column totals and a drill to each customer's Καρτέλα. Plus the collections
 * picture: reminder insights (sent, waiting, paid after a reminder), the blind
 * spots the reminders don't cover, the last reminder per customer, and a manual
 * «Υπενθύμιση τώρα» per customer (Update:InvoiceReminder).
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

    /** @var array{summary: array<string, mixed>, gaps: array<string, array{count: int, amount: float}>}|null */
    private ?array $insights = null;

    /** @var array<int, array{sent_at: CarbonImmutable, stage: string}>|null */
    private ?array $lastReminders = null;

    /** @var array<int, array<int, array{label: string, blocker: ?string, overdue: bool}>> per customer, per request */
    private array $remindable = [];

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
     * Reminder insights for the top of the page: how the reminders are doing and
     * what they don't cover. Memoised per request.
     *
     * @return array{summary: array<string, mixed>, gaps: array<string, array{count: int, amount: float}>}
     */
    public function getInsights(): array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        $insights = app(ReminderInsights::class);

        return $this->insights ??= [
            'summary' => $insights->summary($tenant),
            'gaps' => $insights->gaps($tenant),
        ];
    }

    /** @return array{sent_at: CarbonImmutable, stage: string}|null the last reminder sent to this customer */
    public function lastReminder(int $customerId): ?array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        $this->lastReminders ??= app(ReminderInsights::class)->lastSentByCustomer(
            $tenant,
            array_map(fn ($row): int => $row->customerId, $this->getResult()->rows),
        );

        return $this->lastReminders[$customerId] ?? null;
    }

    public function canRemind(): bool
    {
        return (bool) auth()->user()?->can('Update:InvoiceReminder');
    }

    /**
     * «Υπενθύμιση τώρα» — pick which of the customer's open documents get a
     * payment reminder email right away (one per document). Overdue ones come
     * pre-ticked; documents that can't take one are listed, greyed, with why.
     */
    public function remindAction(): Action
    {
        return Action::make('remind')
            ->label('Υπενθύμιση τώρα')
            ->icon('heroicon-o-bell-alert')
            ->authorize(fn (): bool => $this->canRemind())
            ->modalHeading(fn (array $arguments): string => 'Υπενθύμιση πληρωμής — '.($this->customer((int) ($arguments['customer'] ?? 0))?->name ?? ''))
            ->modalDescription('Ένα email ανά παραστατικό, στη γλώσσα του πελάτη, με το κείμενο «Χειροκίνητη» (Ρυθμίσεις εταιρείας). Η αυτόματη υπενθύμιση του ίδιου παραστατικού περιμένει '.ReminderPlanner::MANUAL_GAP_DAYS.' ημέρες.')
            ->modalSubmitActionLabel('Αποστολή')
            ->schema(fn (array $arguments): array => [
                CheckboxList::make('invoices')
                    ->label('Παραστατικά')
                    ->options(fn (): array => array_map(fn (array $d): string => $d['label'], $this->remindable((int) ($arguments['customer'] ?? 0))))
                    ->disableOptionWhen(fn (string $value): bool => ($this->remindable((int) ($arguments['customer'] ?? 0))[(int) $value]['blocker'] ?? null) !== null)
                    ->default(fn (): array => array_keys(array_filter(
                        $this->remindable((int) ($arguments['customer'] ?? 0)),
                        fn (array $d): bool => $d['blocker'] === null && $d['overdue'],
                    )))
                    ->required()
                    ->validationMessages(['required' => 'Διάλεξε τουλάχιστον ένα παραστατικό.'])
                    ->helperText(fn (): ?string => $this->remindable((int) ($arguments['customer'] ?? 0)) === []
                        ? 'Κανένα δικό μας ανοιχτό παραστατικό (τα WHMCS / παλιά δεν υπενθυμίζονται από εδώ).'
                        : null),
            ])
            ->action(function (array $arguments, array $data): void {
                $customer = $this->customer((int) ($arguments['customer'] ?? 0));
                if ($customer === null) {
                    return;
                }
                /** @var Company $tenant */
                $tenant = Filament::getTenant();
                // Only this customer's own candidates — a forged id can't reach
                // another customer's (or tenant's) document.
                $chosen = app(ReminderPlanner::class)->candidates($tenant, (int) $customer->getKey())
                    ->whereIn('id', array_map('intval', (array) ($data['invoices'] ?? [])));

                $r = app(ReminderRunner::class)->sendManual($tenant, $chosen, auth()->id());
                $this->insights = $this->lastReminders = null;
                $this->remindable = [];

                $skipped = collect($r['skipped'])->map(fn (string $why, string $code): string => "{$code}: {$why}")->implode(' · ');
                $note = Notification::make()
                    ->title($r['queued'] > 0 ? "Μπήκαν στην αποστολή {$r['queued']} υπενθυμίσεις" : 'Δεν στάλθηκε υπενθύμιση')
                    ->body($skipped !== '' ? 'Παραλείφθηκαν — '.$skipped : null);
                ($r['queued'] > 0 ? $note->success() : $note->warning())->send();
            });
    }

    /**
     * The customer's own open documents for the «Υπενθύμιση» modal, keyed by id.
     *
     * @return array<int, array{label: string, blocker: ?string, overdue: bool}>
     */
    private function remindable(int $customerId): array
    {
        return $this->remindable[$customerId] ??= $this->buildRemindable($customerId);
    }

    /** @return array<int, array{label: string, blocker: ?string, overdue: bool}> */
    private function buildRemindable(int $customerId): array
    {
        $customer = $this->customer($customerId);
        if ($customer === null) {
            return [];
        }
        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        $planner = app(ReminderPlanner::class);
        $settings = ReminderSettings::for($tenant);
        $docs = $planner->candidates($tenant, (int) $customer->getKey());
        $lastSent = InvoiceReminder::query()
            ->whereIn('invoice_id', $docs->modelKeys())
            ->where('status', InvoiceReminder::STATUS_SENT)
            ->orderBy('sent_at')
            ->pluck('sent_at', 'invoice_id');
        $today = CarbonImmutable::today();

        $out = [];
        foreach ($docs as $invoice) {
            $due = ReminderPlanner::dueDateOf($invoice);
            $balance = $invoice->balanceData()->balance;
            $blocker = $planner->blocker($invoice, $settings, $balance, manual: true);
            if ($due === null || $balance <= 0.005) {
                continue;   // cash-term / settled — nothing to chase
            }
            $days = (int) $due->diffInDays($today, false);
            $label = $invoice->invcode
                .(ReminderPlanner::kindOf($invoice) === InvoiceReminder::KIND_PROFORMA ? ' (προτιμολόγιο)' : '')
                .' · λήξη '.$due->format('d/m/Y').($days > 0 ? " ({$days} ημ. εκπρόθεσμο)" : '')
                .' · '.Money::eur($balance)
                .(isset($lastSent[$invoice->getKey()]) ? ' · τελ. υπενθύμιση '.CarbonImmutable::parse($lastSent[$invoice->getKey()])->format('d/m') : '')
                .($blocker !== null ? ' — '.$blocker : '');
            $out[(int) $invoice->getKey()] = ['label' => $label, 'blocker' => $blocker, 'overdue' => $days > 0];
        }

        return $out;
    }

    /**
     * The documents behind one «δεν υπενθυμίζονται» card (drafts never sent,
     * WHMCS, legacy, blocked customers) — so the operator sees WHICH, not just
     * how many.
     */
    public function gapAction(): Action
    {
        return Action::make('gap')
            ->modalHeading(fn (array $arguments): string => ReminderInsights::GAP_LABELS[$arguments['kind'] ?? ''] ?? '')
            ->modalDescription(fn (array $arguments): string => ReminderInsights::GAP_HELP[$arguments['kind'] ?? ''] ?? '')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Κλείσιμο')
            ->modalContent(fn (array $arguments): HtmlString => $this->gapHtml((string) ($arguments['kind'] ?? '')));
    }

    private function gapHtml(string $gap): HtmlString
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        if (! array_key_exists($gap, ReminderInsights::GAP_LABELS)) {
            return new HtmlString('');
        }
        $rows = app(ReminderInsights::class)->gapList($tenant, $gap);
        if ($rows === []) {
            return new HtmlString('<p>Τίποτα εδώ.</p>');
        }

        $cell = 'padding:.25rem .5rem';
        $html = '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            .'<th style="text-align:left;'.$cell.'">Παραστατικό</th><th style="text-align:left;'.$cell.'">Πελάτης</th>'
            .'<th style="text-align:right;'.$cell.'">Ποσό</th><th style="text-align:right;'.$cell.'">Ηλικία</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $invoice = $r['invoice'];
            $url = InvoiceResource::getUrl('view', ['record' => $invoice, 'tenant' => $tenant]);
            $html .= '<tr>'
                .'<td style="'.$cell.'"><a href="'.e($url).'" style="text-decoration:underline">'.e((string) ($invoice->invcode ?: '#'.$invoice->getKey())).'</a></td>'
                .'<td style="'.$cell.'">'.e((string) $invoice->customer?->name).'</td>'
                .'<td style="text-align:right;'.$cell.'">'.e(Money::eur($r['amount'])).'</td>'
                .'<td style="text-align:right;'.$cell.'">'.($r['age'] !== null ? e($r['age'].' ημ.') : '—').'</td>'
                .'</tr>';
        }
        $total = app(ReminderInsights::class)->gaps($tenant)[$gap] ?? ['count' => 0];
        $html .= '</tbody></table>';
        if ($total['count'] > count($rows)) {
            $html .= '<p style="margin-top:.5rem;opacity:.75">Εμφανίζονται τα '.count($rows).' μεγαλύτερα από '.$total['count'].'.</p>';
        }

        return new HtmlString($html);
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

                // Validate the assignee at the WRITE, not just the dropdown: the
                // Select's options() restricts the UI, but a crafted request can
                // POST any id and `users` is a GLOBAL table — so re-check membership
                // in the tenant's operator set (null out anything foreign/missing).
                // This blocks assigning a debt to another tenant's user (whose name
                // would then leak into the «Ανάθεση» column) AND avoids an FK-
                // violation 500 on a non-existent id.
                $assignee = (int) ($data['collection_assigned_to'] ?? 0);
                $c->collection_assigned_to = ($assignee > 0 && array_key_exists($assignee, $this->operatorOptions()))
                    ? $assignee
                    : null;
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
            Action::make('reminders')
                ->label('Υπενθυμίσεις')
                ->icon('heroicon-o-bell-alert')
                ->color('gray')
                ->badge(fn (): ?int => ($n = $this->getInsights()['summary']['awaiting']) > 0 ? $n : null)
                ->badgeColor('warning')
                ->url(fn (): string => InvoiceReminderResource::getUrl('index'))
                ->visible(fn (): bool => (bool) auth()->user()?->can('viewAny', InvoiceReminder::class)),
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
