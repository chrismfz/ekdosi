<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Models\InvoiceLine;
use App\Support\MyData\Codes;
use App\Support\MyData\IncomeClassResolver;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Invoice lines, READ-ONLY display. No create/edit/delete actions —
 * mutating an invoice's lines after issuance is forbidden by myDATA
 * rules (frozen on MARK). Even DRAFT invoices' line editing lands in
 * PR #8 alongside the IssueInvoice form, not here.
 *
 * The `product_descr` and `metric_unit` columns are the SNAPSHOTS at
 * issue time — joining through the product relation would show the
 * current product description which may have changed since.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    protected static ?string $recordTitleAttribute = 'product_descr';

    /**
     * The owner invoice's base (E3 class, §8.6 category, business-activity type),
     * computed once — a credit note's base is a DB query, so don't repeat it per row.
     *
     * @var array{0: ?string, 1: ?string, 2: ?string}|null
     */
    private ?array $incomeBaseCache = null;

    public function form(Schema $schema): Schema
    {
        // Required by the RelationManager contract but unused — the
        // resource is read-only at this stage.
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            // product.productCategory feeds the per-line E3 income class (MYD-5).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('product.productCategory'))
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('product_descr')
                    ->label('Description')
                    ->wrap(),

                // The E3 income classification this line FILES at myDATA — the same
                // value the filing path sends (via IncomeClassResolver), so the
                // operator sees «τι στέλνω / αν έχω λάθος» at a glance. Compact: the
                // code only, with the full Greek labels + §8.6 category on hover.
                // Toggleable so it never gets in the way.
                TextColumn::make('income_class')
                    ->label('E3 (ΑΑΔΕ)')
                    ->state(fn (InvoiceLine $record): string => $this->resolveLineClass($record)[0] ?? '—')
                    ->tooltip(fn (InvoiceLine $record): string => $this->incomeClassTooltip($record))
                    ->toggleable(),

                TextColumn::make('qty')
                    ->numeric(decimalPlaces: 3)
                    ->alignRight(),

                TextColumn::make('metric_unit')
                    ->label('Unit')
                    ->placeholder('—'),

                TextColumn::make('price_per_item')
                    ->label('Unit price')
                    ->money('EUR')
                    ->alignRight(),

                TextColumn::make('discount')
                    ->numeric(decimalPlaces: 4)
                    ->alignRight()
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('vat_percent')
                    ->label('VAT')
                    ->suffix('%')
                    ->alignRight(),

                TextColumn::make('net_price')
                    ->label('Net')
                    ->money('EUR')
                    ->alignRight(),

                TextColumn::make('gross_price')
                    ->label('Gross')
                    ->money('EUR')
                    ->alignRight(),

                TextColumn::make('notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // No row / bulk / header actions — read-only.
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('id');
    }

    /**
     * The (E3 class, §8.6 category) this line files — resolved through the SAME
     * IncomeClassResolver the filing path uses, so the column reads exactly what
     * gets sent. The invoice-level base pair is computed once and cached.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveLineClass(InvoiceLine $line): array
    {
        [$baseClass, $baseCat, $businessType] = $this->incomeBase();

        return app(IncomeClassResolver::class)->forLine($line, $baseClass, $baseCat, $businessType);
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [base class, base category, business-activity type]
     */
    private function incomeBase(): array
    {
        if ($this->incomeBaseCache === null) {
            $invoice = $this->getOwnerRecord();
            [$class, $cat] = app(IncomeClassResolver::class)->baseFor($invoice);
            $this->incomeBaseCache = [$class, $cat, $invoice->company?->business_activity_type];
        }

        return $this->incomeBaseCache;
    }

    private function incomeClassTooltip(InvoiceLine $line): string
    {
        [$class, $cat] = $this->resolveLineClass($line);
        if ($class === null || $cat === null) {
            return 'Χωρίς ταξινόμηση εσόδων (π.χ. δελτίο/εσωτερικό — δεν φέρει έσοδο).';
        }

        $typeLabel = Codes::e3TypeLabel($class);
        $catLabel = Codes::e3CategoryLabel($cat);

        return $class.($typeLabel ? ' — '.$typeLabel : '')
            .' · '.$cat.($catLabel ? ' — '.$catLabel : '');
    }
}
