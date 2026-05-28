<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\LocalStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Services\EInvoiceSubmitterFactory;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invcode')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('issued_at')
                    ->label('Issued')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('invoiceType.code')
                    ->label('Series')
                    ->toggleable(),

                TextColumn::make('invoiceType.name')
                    ->label('Type')
                    ->toggleable()
                    ->limit(40),

                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('gross_total')
                    ->label('Total')
                    ->money('EUR')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('local_status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? LocalStatus::from($state)->label() : '—')
                    ->color(fn (?string $state) => $state ? LocalStatus::from($state)->color() : 'gray')
                    ->sortable(),

                TextColumn::make('payment_status')
                    ->label('Πληρωμή')
                    ->badge()
                    ->placeholder('—')
                    // A credit note isn't a receivable — show a neutral
                    // "Πιστωτικό" badge, not the (misleading) unpaid/paid
                    // status its own row would otherwise compute.
                    ->formatStateUsing(fn (?string $state, $record) => $record->credited_invoice_id !== null
                        ? 'Πιστωτικό'
                        : ($state ? PaymentStatus::from($state)->label() : '—'))
                    ->color(fn (?string $state, $record) => $record->credited_invoice_id !== null
                        ? 'info'
                        : ($state ? PaymentStatus::from($state)->color() : 'gray'))
                    ->toggleable(),

                TextColumn::make('mydata_state')
                    ->label('myDATA')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'VALID' => 'success',
                        'CANCELLED' => 'danger',
                        null => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (?string $state) => $state ?? 'pending')
                    ->toggleable(),

                TextColumn::make('mydata_mark')
                    ->label('MARK')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),

                IconColumn::make('mailed')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('printed')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('paymentMethod.description')
                    ->label('Payment')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('invoice_type_id')
                    ->label('Invoice type')
                    ->options(fn () => InvoiceType::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($t) => [$t->id => $t->code.' — '.$t->name])
                        ->toArray())
                    ->searchable(),

                SelectFilter::make('customer_id')
                    ->label('Customer')
                    ->searchable()
                    // withTrashed() so the filter can resolve labels for
                    // invoices that reference a soft-deleted customer
                    // — otherwise historical invoices for departed
                    // customers become unfilterable.
                    ->getSearchResultsUsing(fn (string $search) => Customer::query()
                        ->withTrashed()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->where('name', 'like', "%{$search}%")
                        ->orderBy('name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->id => $c->trashed() ? $c->name.' (deleted)' : $c->name])
                        ->toArray())
                    ->getOptionLabelUsing(fn ($value) => (function () use ($value) {
                        $c = Customer::query()
                            ->withTrashed()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->whereKey($value)
                            ->first();
                        return $c ? ($c->trashed() ? $c->name.' (deleted)' : $c->name) : null;
                    })()),

                SelectFilter::make('payment_status')
                    ->label('Κατάσταση πληρωμής')
                    ->options(collect(PaymentStatus::cases())
                        ->mapWithKeys(fn (PaymentStatus $s) => [$s->value => $s->label()])
                        ->toArray())
                    ->placeholder('All'),

                SelectFilter::make('local_status')
                    ->label('Κατάσταση')
                    ->options(collect(LocalStatus::cases())
                        ->mapWithKeys(fn (LocalStatus $s) => [$s->value => $s->label()])
                        ->toArray())
                    ->placeholder('Όλες'),

                SelectFilter::make('mydata_state')
                    ->label('myDATA state')
                    ->options([
                        'VALID' => 'VALID (filed)',
                        'CANCELLED' => 'CANCELLED',
                    ])
                    ->placeholder('All'),

                TernaryFilter::make('mydata_sent')
                    ->label('Submitted to myDATA')
                    ->placeholder('All')
                    ->trueLabel('Submitted only')
                    ->falseLabel('Not submitted only'),

                // Quick period presets so nothing unsent slips past the
                // weekly review (e.g. "Αυτό το τρίμηνο" + "Μη υποβληθέντα").
                SelectFilter::make('period')
                    ->label('Περίοδος')
                    ->options([
                        'week' => 'Αυτή την εβδομάδα',
                        'month' => 'Αυτόν τον μήνα',
                        'last_month' => 'Προηγούμενος μήνας',
                        'quarter' => 'Αυτό το τρίμηνο',
                        'year' => 'Φέτος',
                    ])
                    ->query(function (Builder $q, array $data) {
                        $v = $data['value'] ?? null;
                        if (! $v) {
                            return $q;
                        }
                        $now = now();
                        [$from, $to] = match ($v) {
                            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
                            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
                            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
                            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
                            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
                            default => [null, null],
                        };

                        return $q->when($from, fn ($q) => $q->whereBetween('issued_at', [$from, $to]));
                    }),

                Filter::make('issued_at_range')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('Issued from'),
                        \Filament\Forms\Components\DatePicker::make('to')
                            ->label('Issued to'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('issued_at', '>=', $d))
                            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('issued_at', '<=', $d));
                    }),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Weekly batch-file: filter (e.g. Ενεργό + Μη
                    // υποβληθέντα + period) → select → submit. Only
                    // un-filed, non-credit-note rows are sent; the rest
                    // are skipped. Each submit is an independent AADE
                    // call (per-row error handling, partial success OK).
                    BulkAction::make('submit_mydata')
                        ->label('Υποβολή επιλεγμένων στο myDATA')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->visible(fn () => in_array(
                            Filament::getTenant()?->mydata_mode,
                            ['sandbox', 'production'],
                            true,
                        ))
                        ->requiresConfirmation()
                        ->modalHeading('Μαζική υποβολή στο myDATA')
                        ->modalDescription('Υποβάλλονται μόνο τα μη υποβληθέντα (χωρίς MARK). Πιστωτικά και ήδη υποβληθέντα παραλείπονται.')
                        ->action(function (Collection $records) {
                            $ok = 0;
                            $skip = 0;
                            $fail = 0;
                            $submitter = app(EInvoiceSubmitterFactory::class)->for(Filament::getTenant());

                            foreach ($records as $record) {
                                if ($record->mydata_state !== null || $record->credited_invoice_id !== null) {
                                    $skip++;

                                    continue;
                                }
                                try {
                                    $submitter->submit($record);
                                    if ($record->local_status === 'draft') {
                                        $record->update(['local_status' => 'active']);
                                    }
                                    $ok++;
                                } catch (Throwable $e) {
                                    $fail++;
                                }
                            }

                            Notification::make()
                                ->title("Υποβλήθηκαν: {$ok} · Παραλείφθηκαν: {$skip} · Απέτυχαν: {$fail}")
                                ->{$fail > 0 ? 'warning' : 'success'}()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('issued_at', 'desc');
    }
}
