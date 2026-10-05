<?php

namespace App\Filament\Support;

use App\Support\DocumentTotals;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The «Σύνολα» strip under an invoice/quote lines repeater: net before discounts,
 * discounts, net, VAT, total — recomputed on every round-trip from the UNSAVED form
 * state (DocumentTotals::fromFormLines, the same math the save path uses), so the
 * operator doesn't need a calculator. The line fields it reads must be `live`
 * (onBlur is enough) for it to follow the typing.
 *
 * Withholding / stamp duty / fees (invoices only) are NOT in here — they depend on product-linked
 * myDATA categories and are computed on save (RecomputeInvoiceTaxes).
 */
final class LiveDocumentTotals
{
    /**
     * @param  bool  $hasTaxes  the document carries withholding / stamp duty / fees
     *                          (invoices) → say the total is before them; quotes don't
     */
    public static function make(bool $hasTaxes = true, string $linesPath = 'lines', string $headerDiscountPath = 'header_discount_percent'): Grid
    {
        // Cheap (one pass over the lines) — each entry just recomputes it.
        $totals = fn (Get $get): array => DocumentTotals::fromFormLines($get($linesPath), $get($headerDiscountPath));

        return Grid::make(['default' => 2, 'md' => 5])
            ->columnSpanFull()
            ->schema([
                TextEntry::make('live_totals_list_net')
                    ->label('Αξία προ εκπτώσεων')
                    ->state(fn (Get $get): string => Money::eur($totals($get)['list_net'])),
                TextEntry::make('live_totals_discount')
                    ->label('Εκπτώσεις')
                    ->state(fn (Get $get): string => Money::eur($totals($get)['line_discount'] + $totals($get)['header_discount']))
                    ->helperText(fn (Get $get): ?string => ($t = $totals($get))['header_discount'] > 0
                        ? 'γραμμών '.Money::eur($t['line_discount']).' + παραστατικού '.Money::eur($t['header_discount'])
                        : null),
                TextEntry::make('live_totals_net')
                    ->label('Καθαρή αξία')
                    ->state(fn (Get $get): string => Money::eur($totals($get)['net'])),
                TextEntry::make('live_totals_vat')
                    ->label('ΦΠΑ')
                    ->state(fn (Get $get): string => Money::eur($totals($get)['vat'])),
                TextEntry::make('live_totals_gross')
                    ->label('Σύνολο')
                    ->state(fn (Get $get): string => Money::eur($totals($get)['gross']))
                    ->weight('bold')
                    ->size('lg')
                    ->helperText($hasTaxes ? 'Πριν από παρακρατήσεις / τέλη (υπολογίζονται στην αποθήκευση).' : null),
            ]);
    }
}
