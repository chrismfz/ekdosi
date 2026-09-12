<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Activity;
use App\Models\AuthEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Tenant-wide «Πρόσφατη δραστηριότητα» — every audited change (invoices,
 * customers, payments) for the current tenant in one chronological feed. The
 * per-record «Ιστορικό» tab answers "what happened to THIS invoice"; this page
 * answers "what happened in this company".
 *
 * Admin territory (gated on View:ActivityFeed — company_admin + super_admin,
 * operators excluded). Scoped by activity_log.company_id, stamped on write by
 * App\Models\Activity.
 */
class ActivityFeed extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Which tab's table is showing: 'records' (business audit, tenant-scoped) or
     * 'security' (auth log, system-level, super-admin only). URL-bound so a
     * refresh / deep-link keeps the tab. The security tab is access-guarded in
     * table() regardless of this value.
     */
    #[Url]
    public string $activeTab = 'records';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Σύστημα';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.activity-feed';

    public static function getNavigationLabel(): string
    {
        return 'Δραστηριότητα';
    }

    public function getTitle(): string
    {
        return 'Πρόσφατη δραστηριότητα';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:ActivityFeed');
    }

    /**
     * The «Συνδέσεις & ασφάλεια» tab is super-admin only: the auth log is
     * system-level (spans every tenant, incl. failed attempts on non-existent
     * usernames that belong to no company), so a per-tenant company_admin must
     * not see it.
     */
    public function canSeeSecurityTab(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSystemSuperAdmin();
    }

    public function updatedActiveTab(): void
    {
        // Never let a non-super-admin land on the security table by forcing the
        // property (e.g. a hand-edited ?activeTab=security).
        if ($this->activeTab === 'security' && ! $this->canSeeSecurityTab()) {
            $this->activeTab = 'records';
        }

        // Different columns/filters per tab — clear carried-over filter/search
        // state so a records-tab filter can't apply to the security query.
        $this->resetTable();
        $this->resetTableSearch();
    }

    public function table(Table $table): Table
    {
        return ($this->activeTab === 'security' && $this->canSeeSecurityTab())
            ? $this->securityTable($table)
            : $this->recordsTable($table);
    }

    /** The auth/security log (system-level, super-admin only). */
    private function securityTable(Table $table): Table
    {
        return $table
            ->query(AuthEvent::query())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Πότε')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                TextColumn::make('guard')
                    ->label('Panel')
                    ->badge()
                    ->color(fn (AuthEvent $record): string => $record->guard === 'web' ? 'primary' : 'gray')
                    ->formatStateUsing(fn (AuthEvent $record): string => $record->panelLabel()),

                TextColumn::make('event')
                    ->label('Ενέργεια')
                    ->badge()
                    ->color(fn (AuthEvent $record): string => match ($record->event) {
                        'login' => 'success',
                        'failed' => 'danger',
                        'logout' => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (AuthEvent $record): string => match ($record->event) {
                        'login' => 'Σύνδεση',
                        'logout' => 'Αποσύνδεση',
                        'failed' => 'Αποτυχία',
                        default => $record->event,
                    }),

                TextColumn::make('email')
                    ->label('Ταυτότητα')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('ip_address')
                    ->label('IP')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('user_agent')
                    ->label('User agent')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Ενέργεια')
                    ->options([
                        'login' => 'Σύνδεση',
                        'logout' => 'Αποσύνδεση',
                        'failed' => 'Αποτυχία',
                    ]),
                SelectFilter::make('guard')
                    ->label('Panel')
                    ->options([
                        'web' => '/admin (χειριστές)',
                        'portal' => '/user (πελάτες)',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->deferLoading();
    }

    /** The business audit log (tenant-scoped) — the original feed. */
    private function recordsTable(Table $table): Table
    {
        return $table
            ->query(
                Activity::query()
                    ->where('company_id', Filament::getTenant()?->getKey())
                    ->with(['subject', 'causer'])
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Πότε')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                TextColumn::make('subject_type')
                    ->label('Είδος')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Activity $record): string => $record->subjectLabel()),

                TextColumn::make('subject_id')
                    ->label('Εγγραφή')
                    ->state(fn (Activity $record): string => $this->subjectName($record))
                    ->url(fn (Activity $record): ?string => $this->subjectUrl($record))
                    ->color('primary'),

                TextColumn::make('description')
                    ->label('Ενέργεια')
                    ->badge()
                    ->color(fn (Activity $record): string => match ($record->event) {
                        'created' => 'success',
                        'updated' => 'info',
                        'deleted' => 'danger',
                        'restored' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('causer.name')
                    ->label('Χρήστης')
                    ->placeholder('Σύστημα'),

                TextColumn::make('changes')
                    ->label('Μεταβολές')
                    ->state(fn (Activity $record): array => $record->changeLines())
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('subject_type')
                    ->label('Είδος')
                    ->options([
                        Invoice::class => 'Τιμολόγια',
                        Customer::class => 'Πελάτες',
                        Payment::class => 'Πληρωμές',
                        DeliveryNote::class => 'Δελτία αποστολής',
                    ]),
                SelectFilter::make('event')
                    ->label('Ενέργεια')
                    ->options([
                        'created' => 'Δημιουργία',
                        'updated' => 'Τροποποίηση',
                        'deleted' => 'Διαγραφή',
                        'restored' => 'Επαναφορά',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->deferLoading();
    }

    private function subjectName(Activity $record): string
    {
        $subject = $record->subject;

        return match ($record->subject_type) {
            Invoice::class => $subject?->invcode ?? '#'.$record->subject_id,
            DeliveryNote::class => $subject?->invcode ?? '#'.$record->subject_id,
            Customer::class => $subject?->name ?? '#'.$record->subject_id,
            Payment::class => 'Πληρωμή #'.$record->subject_id,
            default => '#'.$record->subject_id,
        };
    }

    private function subjectUrl(Activity $record): ?string
    {
        $subject = $record->subject;
        if ($subject === null) {
            return null;
        }

        $tenant = Filament::getTenant();

        return match ($record->subject_type) {
            Invoice::class => InvoiceResource::getUrl('view', ['record' => $subject, 'tenant' => $tenant]),
            DeliveryNote::class => DeliveryNoteResource::getUrl('view', ['record' => $subject, 'tenant' => $tenant]),
            Customer::class => CustomerResource::getUrl('edit', ['record' => $subject, 'tenant' => $tenant]),
            Payment::class => PaymentResource::getUrl('edit', ['record' => $subject, 'tenant' => $tenant]),
            default => null,
        };
    }
}
