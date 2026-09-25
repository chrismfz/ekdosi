<?php

namespace App\Filament\Resources\Employees;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Hr\EmployeeAccountMatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * «Εργαζόμενοι» — the staff roster (company_admin). Link a panel user so the
 * person can file their own leave (they need the operator or the «Προσωπικό
 * (μόνο άδειες)» role — set in Διαχείριση → Χρήστες). Deactivate, don't delete,
 * someone who left: their leave history stays.
 */
class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Προσωπικό';

    protected static ?string $navigationLabel = 'Εργαζόμενοι';

    protected static ?string $modelLabel = 'εργαζόμενος';

    protected static ?string $pluralModelLabel = 'Εργαζόμενοι';

    protected static ?string $recordTitleAttribute = 'last_name';

    protected static ?int $navigationSort = 30;

    protected static bool $isGloballySearchable = false;

    /** Only for tenants with the Προσωπικό / ΕΡΓΑΝΗ pillar enabled (Company → «ΕΡΓΑΝΗ»). */
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasErgani()
            && parent::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        $tenantScoped = fn (Unique $rule): Unique => $rule->where('company_id', Filament::getTenant()?->getKey());

        return $schema->components([
            Section::make('Στοιχεία')
                ->columns(2)
                ->schema([
                    TextInput::make('last_name')->label('Επώνυμο')->required()->maxLength(100),
                    TextInput::make('first_name')->label('Όνομα')->required()->maxLength(100),
                    TextInput::make('afm')
                        ->label('ΑΦΜ')
                        ->regex('/^\d{9}$/')
                        ->unique(ignoreRecord: true, modifyRuleUsing: $tenantScoped)
                        // ΕΡΓΑΝΗ identifies the employee by ΑΦΜ — a card holder without one can't be declared.
                        ->required(fn (Get $get): bool => (bool) $get('has_work_card'))
                        ->validationMessages(['required' => 'Χρειάζεται ΑΦΜ για ψηφιακή κάρτα.', 'regex' => 'Ο ΑΦΜ έχει 9 ψηφία.', 'unique' => 'Υπάρχει ήδη εργαζόμενος με αυτόν τον ΑΦΜ (ίσως στους διαγραμμένους — φίλτρο «Διαγραμμένα» → Επαναφορά).'])
                        ->helperText('Όπως στο ΕΡΓΑΝΗ — χρειάζεται για τη δήλωση αδειών/κάρτας.'),
                    TextInput::make('email')->label('Email')->email()->maxLength(191),
                    DatePicker::make('hired_at')->label('Ημ/νία πρόσληψης')->native(false)->displayFormat('d/m/Y'),
                    Toggle::make('is_active')->label('Ενεργός')->default(true)->inline(false),
                ]),
            Section::make('Άδειες & πρόσβαση')
                ->columns(2)
                ->schema([
                    TextInput::make('annual_leave_days')
                        ->label('Ημέρες κανονικής άδειας / έτος')
                        ->numeric()->integer()->minValue(0)->maxValue(60)
                        ->default(20)->required()
                        ->helperText('Πενθήμερο: 20 τον 1ο χρόνο, αυξάνεται με την προϋπηρεσία — επιβεβαίωσε με τον λογιστή.'),
                    Select::make('user_id')
                        ->label('Λογαριασμός στο ekdosi')
                        ->options(fn (): array => Filament::getTenant()?->users()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (User $u): array => [$u->id => $u->name.' <'.$u->email.'>'])
                            ->all() ?? [])
                        ->searchable()
                        ->unique(ignoreRecord: true, modifyRuleUsing: $tenantScoped)
                        ->helperText('Για να ζητά ο ίδιος άδειες και να χτυπά κάρτα από το κινητό. Ο λογαριασμός χρειάζεται ρόλο «Operator» ή «Προσωπικό (άδειες & κάρτα)» (Χρήστες → Ρόλος). Χωρίς λογαριασμό: άδειες από τον διαχειριστή, κάρτα μόνο από το tablet.'),
                    Toggle::make('has_work_card')
                        ->label('Ψηφιακή κάρτα εργασίας')
                        ->helperText('Ενεργό μόνο αν ο λογιστής τον έχει δηλώσει στο ΕΡΓΑΝΗ «με ένδειξη κάρτας» — αλλιώς το ΕΡΓΑΝΗ απορρίπτει τις κινήσεις του. Ανενεργό: τα χτυπήματα καταγράφονται μόνο στο ekdosi.')
                        ->live()
                        ->inline(false),
                    // Tablet «ρολόι» PIN: write-only — the field never shows the stored
                    // hash; blank = keep. Hashed before it reaches the model.
                    TextInput::make('card_pin_hash')
                        ->label('PIN ρολογιού (tablet)')
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->regex('/^\d{4,6}$/')
                        ->validationMessages(['regex' => 'Το PIN έχει 4–6 ψηφία.'])
                        ->formatStateUsing(fn (): ?string => null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                        ->helperText(fn (?Employee $record): string => ($record && filled($record->card_pin_hash) ? 'Έχει οριστεί PIN — κενό = το κρατά. ' : 'Δεν έχει PIN — χωρίς αυτό δεν χτυπά κάρτα από το tablet. ')
                            .'4–6 ψηφία, ο εργαζόμενος το δίνει στο tablet του γραφείου. Αποφύγετε 1234, 0000 ή ημερομηνία γέννησης· δώστε το προφορικά, όχι σε κοινόχρηστο chat.'),
                    TextInput::make('ergani_branch')
                        ->label('Α/Α παραρτήματος ΕΡΓΑΝΗ')
                        ->numeric()->integer()->minValue(0)->maxValue(255)->default(0)->required()
                        ->helperText('0 = η έδρα. Αν δουλεύει σε παράρτημα, ο α/α του παραρτήματος όπως φαίνεται στο ΕΡΓΑΝΗ (ρωτήστε τον λογιστή).'),
                    Textarea::make('notes')->label('Σημειώσεις')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $year = (int) now()->format('Y');

        return $table
            // Balance in the same query (no per-row SUM).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user')->withSum(
                ['leaveRequests as annual_taken' => fn (Builder $q) => $q
                    ->where('status', LeaveStatus::Approved->value)
                    ->where('type', LeaveType::Annual->value)
                    ->whereYear('starts_on', $year)],
                'days',
            ))
            ->defaultSort('last_name')
            ->columns([
                TextColumn::make('last_name')
                    ->label('Ονοματεπώνυμο')
                    ->formatStateUsing(fn (Employee $record): string => $record->full_name)
                    ->searchable(['last_name', 'first_name', 'afm'])
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('afm')->label('ΑΦΜ')->placeholder('—')->toggleable(),
                // Who is who: the linked account (name + email), a role warning, or
                // an unambiguous suggestion to confirm with «Σύνδεση».
                TextColumn::make('user.name')
                    ->label('Λογαριασμός')
                    ->state(fn (Employee $record): string => $record->user?->name
                        ?? (($s = app(EmployeeAccountMatcher::class)->suggestionFor($record)) ? 'Πρόταση: '.$s->name : '—'))
                    ->description(fn (Employee $record): ?string => match (true) {
                        $record->user !== null => $record->user->email.(self::canFileLeave($record->user) ? '' : ' · ⚠ χωρίς ρόλο για άδειες'),
                        default => app(EmployeeAccountMatcher::class)->suggestionFor($record)?->email,
                    })
                    ->color(fn (Employee $record): ?string => $record->user === null && app(EmployeeAccountMatcher::class)->suggestionFor($record) ? 'gray' : null)
                    ->tooltip(fn (Employee $record): ?string => $record->user && ! self::canFileLeave($record->user)
                        ? 'Ο λογαριασμός δεν μπορεί να ζητήσει άδεια — δώστε ρόλο «Operator» ή «Προσωπικό (άδειες & κάρτα)» στους Χρήστες.'
                        : null),
                TextColumn::make('balance')
                    ->label('Υπόλοιπο '.$year)
                    ->state(fn (Employee $record): string => ($record->annual_leave_days - (int) $record->annual_taken).' / '.$record->annual_leave_days)
                    ->alignEnd(),
                IconColumn::make('has_work_card')->label('Κάρτα')->boolean()
                    ->trueIcon('heroicon-o-finger-print')->falseIcon('heroicon-o-minus')->falseColor('gray')
                    ->tooltip(fn (Employee $record): string => $record->has_work_card ? 'Δηλώνεται στο ΕΡΓΑΝΗ' : 'Χωρίς ψηφιακή κάρτα'),
                TextColumn::make('pin_state')->label('PIN')->badge()
                    ->state(fn (Employee $record): string => match (true) {
                        (bool) $record->card_pin_locked_until?->isFuture() => 'Κλειδωμένο',
                        filled($record->card_pin_hash) => 'Ναι',
                        default => 'Όχι',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Κλειδωμένο' => 'danger',
                        'Ναι' => 'success',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')->label('Ενεργός')->boolean(),
            ])
            ->emptyStateHeading('Δεν υπάρχουν εργαζόμενοι ακόμη')
            ->emptyStateDescription('Προσθέστε κάθε εργαζόμενο μία φορά (ονοματεπώνυμο, ΑΦΜ όπως στο ΕΡΓΑΝΗ). Μετά μπορεί να ζητά άδειες και να χτυπά κάρτα.')
            ->filters([TrashedFilter::make()])
            ->recordActions([self::linkSuggestedAction(), self::unlockPinAction(), EditAction::make()])
            ->toolbarActions([
                self::linkSuggestedBulkAction(),
                BulkActionGroup::make([RestoreBulkAction::make()]),
            ]);
    }

    /** Can this account file leave in the CURRENT tenant (operator / ergani role / admins)? */
    public static function canFileLeave(User $user): bool
    {
        return $user->can('Create:LeaveRequest');
    }

    /**
     * Confirm the suggested account. The modal shows WHICH user; the action links
     * only if that is still the (unambiguous, unlinked) suggestion at click time.
     */
    public static function linkSuggestedAction(): Action
    {
        return Action::make('linkSuggested')
            ->label('Σύνδεση')
            ->icon('heroicon-o-link')
            ->color('primary')
            ->visible(fn (Employee $record): bool => app(EmployeeAccountMatcher::class)->suggestionFor($record) !== null
                && (auth()->user()?->can('update', $record) ?? false))
            ->authorize(fn (Employee $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading(fn (Employee $record): string => 'Σύνδεση λογαριασμού — '.$record->full_name)
            ->modalDescription(fn (Employee $record): string => ($s = app(EmployeeAccountMatcher::class)->suggestionFor($record))
                ? 'Ο εργαζόμενος θα συνδεθεί με τον λογαριασμό «'.$s->name.'» <'.$s->email.'>. Μετά θα βλέπει και θα ζητά τις δικές του άδειες.'
                : '')
            ->fillForm(fn (Employee $record): array => ['seen_user_id' => app(EmployeeAccountMatcher::class)->suggestionFor($record)?->getKey()])
            ->schema([Hidden::make('seen_user_id')])
            ->action(function (Employee $record, array $data): void {
                $linked = self::linkIfStillSuggested($record, (int) ($data['seen_user_id'] ?? 0));
                $linked
                    ? Notification::make()->title('Συνδέθηκε με «'.$record->fresh()->user?->name.'»')->success()->send()
                    : Notification::make()->title('Η πρόταση άλλαξε στο μεταξύ — δεν έγινε σύνδεση. Ανανεώστε τη σελίδα.')->warning()->send();
            });
    }

    /** Link every selected employee that has an unambiguous suggestion; skip the rest. */
    public static function linkSuggestedBulkAction(): BulkAction
    {
        return BulkAction::make('linkSuggestedBulk')
            ->label('Σύνδεση με τις προτάσεις')
            ->icon('heroicon-o-link')
            ->requiresConfirmation()
            ->modalDescription('Συνδέει κάθε επιλεγμένο εργαζόμενο με τον λογαριασμό που προτείνεται δίπλα του (ίδιο email ή ίδιο ονοματεπώνυμο, μόνο όταν υπάρχει ένα και μοναδικό ταίριασμα). Όσοι δεν έχουν πρόταση παραλείπονται.')
            ->authorize(fn (): bool => auth()->user()?->can('Update:Employee') ?? false)
            ->action(function (EloquentCollection $records): void {
                $linked = 0;
                foreach ($records as $record) {
                    if (! (auth()->user()?->can('update', $record) ?? false)) {
                        continue;
                    }
                    $suggestion = app(EmployeeAccountMatcher::class)->suggestionFor($record);
                    if ($suggestion && self::linkIfStillSuggested($record, (int) $suggestion->getKey())) {
                        $linked++;
                    }
                }
                $skipped = $records->count() - $linked;
                Notification::make()
                    ->title('Συνδέθηκαν '.$linked.($skipped > 0 ? ' · παραλείφθηκαν '.$skipped.' (χωρίς μοναδική πρόταση)' : ''))
                    ->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Re-derive the suggestion NOW (fresh matcher, not the request memo) and link
     * only if it is still exactly $userId — so a stale modal or a concurrent link
     * of the same user can't produce a wrong or duplicate link.
     */
    private static function linkIfStillSuggested(Employee $employee, int $userId): bool
    {
        $employee->refresh();
        $current = (new EmployeeAccountMatcher)->suggestionFor($employee);
        if ($userId === 0 || $current === null || (int) $current->getKey() !== $userId) {
            return false;
        }
        try {
            $employee->forceFill(['user_id' => $userId])->save();
        } catch (UniqueConstraintViolationException) {
            return false; // the same user got linked to someone else a moment ago
        }
        app(EmployeeAccountMatcher::class)->reset();

        return true;
    }

    /** Lift a PIN lockout (wrong tries on the tablet) — shown only while locked. */
    public static function unlockPinAction(): Action
    {
        return Action::make('unlockPin')
            ->label('Ξεκλείδωμα PIN')
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->visible(fn (Employee $record): bool => (bool) $record->card_pin_locked_until?->isFuture())
            ->authorize(fn (Employee $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->modalDescription(fn (Employee $record): string => 'Κλειδωμένο έως '.$record->card_pin_locked_until?->format('d/m H:i').' λόγω λάθος PIN. Ξεκλειδώστε μόνο αν ο εργαζόμενος το ζήτησε — αλλιώς ίσως κάποιος προσπαθεί να μαντέψει το PIN του.')
            ->action(function (Employee $record): void {
                $record->forceFill(['card_pin_failures' => 0, 'card_pin_locked_until' => null])->saveQuietly();
                Notification::make()->title('Το PIN ξεκλειδώθηκε')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }
}
