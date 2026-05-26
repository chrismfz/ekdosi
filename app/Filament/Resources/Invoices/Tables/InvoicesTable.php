<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Models\Customer;
use App\Models\InvoiceType;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    ->getSearchResultsUsing(fn (string $search) => Customer::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->where('name', 'like', "%{$search}%")
                        ->orderBy('name')
                        ->limit(50)
                        ->pluck('name', 'id')
                        ->toArray())
                    ->getOptionLabelUsing(fn ($value) => Customer::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->whereKey($value)
                        ->value('name')),

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
            ->defaultSort('issued_at', 'desc');
    }
}
