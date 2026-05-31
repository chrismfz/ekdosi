<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 95;

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

    public function table(Table $table): Table
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
            Customer::class => CustomerResource::getUrl('edit', ['record' => $subject, 'tenant' => $tenant]),
            Payment::class => PaymentResource::getUrl('edit', ['record' => $subject, 'tenant' => $tenant]),
            default => null,
        };
    }
}
