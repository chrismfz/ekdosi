<?php

namespace App\Filament\Resources\Domains\Tables;

use App\Actions\Domains\AssignDomainToCustomer;
use App\Actions\Domains\TransferDomainOwnership;
use App\Filament\Support\PickerOptions;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use RuntimeException;

class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'customer:id,name',
                'contacts:id,domain_id,type,name,email',
                'registrarConnection:id,registrar,label',
            ]))
            ->columns([
                TextColumn::make('fqdn')
                    ->label('Domain')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('customer.name')
                    ->label('Πελάτης')
                    ->placeholder('— αδέσποτο —')
                    ->searchable()
                    // The manual-assign aid (registrar-first bootstrap): with no
                    // customer, show WHO the registrant contact says owns it.
                    ->description(function (Domain $record): ?string {
                        if ($record->customer_id !== null) {
                            return null;
                        }
                        $reg = $record->contacts->firstWhere('type', 'registrant');

                        return $reg === null ? null : trim(($reg->name ?? '').' '.($reg->email ? '<'.$reg->email.'>' : ''));
                    }),

                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge(),

                TextColumn::make('expires_at')
                    ->label('Λήξη')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn (Domain $record): ?string => $record->expires_at === null ? null
                        : ($record->expires_at->isPast() ? 'danger'
                            : ($record->expires_at->lte(Carbon::today()->addDays(45)) ? 'warning' : null))),

                IconColumn::make('auto_renew')
                    ->label('Auto-renew')
                    ->boolean(),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('assign')
                    ->label('Ανάθεση σε πελάτη')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (Domain $record): bool => $record->customer_id === null && $record->deleted_at === null)
                    ->schema([
                        Select::make('customer_id')
                            ->label('Πελάτης')
                            ->required()
                            ->searchable()
                            ->options(fn () => PickerOptions::favouriteCustomerOptions())
                            ->getSearchResultsUsing(fn (string $search) => PickerOptions::searchCustomerOptions($search))
                            ->getOptionLabelUsing(fn ($value) => optional(Customer::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->find($value))->name),
                        Select::make('invoice_type_id')
                            ->label('Τύπος παραστατικού ανανέωσης')
                            ->options(fn (): array => InvoiceType::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->orderBy('description')
                                ->pluck('description', 'id')
                                ->all())
                            ->native(false)
                            ->helperText('Με ποιόν τύπο θα κόβεται το πρόχειρο ανανέωσης. Ορίζεται και αργότερα στην υπηρεσία.'),
                        Select::make('payment_method_id')
                            ->label('Τρόπος πληρωμής')
                            ->options(fn (): array => PaymentMethod::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->orderBy('description')
                                ->pluck('description', 'id')
                                ->all())
                            ->native(false)
                            ->placeholder('— προαιρετικό —'),
                        TextInput::make('vat_percent')
                            ->label('ΦΠΑ %')
                            ->numeric()
                            ->default(24)
                            ->required()
                            ->minValue(0)
                            ->maxValue(100),
                    ])
                    ->action(function (Domain $record, array $data): void {
                        $customer = Customer::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->findOrFail($data['customer_id']);
                        try {
                            $domain = app(AssignDomainToCustomer::class)(
                                $record,
                                $customer,
                                isset($data['invoice_type_id']) ? (int) $data['invoice_type_id'] : null,
                                isset($data['payment_method_id']) ? (int) $data['payment_method_id'] : null,
                                (float) $data['vat_percent'],
                            );
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Η ανάθεση απέτυχε.')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Ανατέθηκε στον πελάτη.')
                            ->body('Δημιουργήθηκε υπηρεσία ανανέωσης «'.$domain->serviceContract?->description.'» ('.$domain->serviceContract?->amount.'€ / '.$domain->serviceContract?->billing_cycle.').')
                            ->success()
                            ->send();
                    }),
                Action::make('transferOwnership')
                    ->label('Μεταφορά ιδιοκτησίας')
                    ->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (Domain $record): bool => $record->customer_id !== null && $record->deleted_at === null)
                    ->schema([
                        Select::make('customer_id')
                            ->label('Νέος πελάτης')
                            ->required()
                            ->searchable()
                            ->options(fn () => PickerOptions::favouriteCustomerOptions())
                            ->getSearchResultsUsing(fn (string $search) => PickerOptions::searchCustomerOptions($search))
                            ->getOptionLabelUsing(fn ($value) => optional(Customer::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->find($value))->name),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Οι μελλοντικές ανανεώσεις θα χρεώνουν τον νέο πελάτη. Τα ιστορικά παραστατικά ΜΕΝΟΥΝ στον προηγούμενο (νομικό αρχείο). ΔΕΝ είναι μεταφορά μητρώου/registrar.')
                    ->action(function (Domain $record, array $data): void {
                        $customer = Customer::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->findOrFail($data['customer_id']);
                        try {
                            app(TransferDomainOwnership::class)($record, $customer);
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Η μεταφορά απέτυχε.')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Μεταφέρθηκε στον νέο πελάτη.')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('fqdn');
    }
}
