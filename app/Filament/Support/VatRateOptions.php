<?php

namespace App\Filament\Support;

use App\Models\VatCategory;
use Filament\Facades\Filament;

/**
 * Builds the VAT-rate dropdown for invoice/quote line items from the tenant's
 * configured VatCategory rows (Setup → VAT Categories) — so the operator picks
 * a LEGAL, pre-configured rate instead of free-typing a number.
 *
 * The line stores vat_percent as a PERCENTAGE (not a FK), and the money math
 * (InvoiceLine::saving, InvoiceVatBreakdown, the gross/net mirror) keys off
 * that number. So this only changes the INPUT (TextInput → Select); the value
 * is still the rate. Option keys and any $set('vat_percent', …) MUST use the
 * same 2-decimal string (via normalize()) or the Select renders blank on edit.
 *
 * Fallback: if a tenant hasn't configured any VatCategory yet, offer the
 * AADE-valid Greek rates so the form still works (matches the rates
 * MyDataSubmitter::vatCategoryFor accepts).
 */
class VatRateOptions
{
    /** AADE-valid Greek VAT rates (see MyDataSubmitter::vatCategoryFor). */
    private const FALLBACK_RATES = [24, 13, 6, 17, 9, 4, 0];

    /**
     * @return array<string, string>  '24.00' => '24% — Κανονικός'
     */
    public static function options(): array
    {
        $tenantId = Filament::getTenant()?->getKey();

        $categories = VatCategory::query()
            ->when($tenantId, fn ($q) => $q->where('company_id', $tenantId))
            ->orderBy('rate')
            ->get();

        if ($categories->isNotEmpty()) {
            return $categories
                ->mapWithKeys(fn (VatCategory $v) => [
                    self::normalize($v->rate) => self::label($v->rate, $v->description),
                ])
                ->all();
        }

        $out = [];
        foreach (self::FALLBACK_RATES as $rate) {
            $out[self::normalize($rate)] = self::label($rate, null);
        }

        return $out;
    }

    /**
     * Canonical 2-decimal string for a rate, used for BOTH the option keys and
     * every $set('vat_percent', …) so the Select state matches an option.
     */
    public static function normalize(int|float|string|null $rate): string
    {
        return number_format((float) ($rate ?? 0), 2, '.', '');
    }

    private static function label(int|float $rate, ?string $description): string
    {
        $pct = rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');

        return $description ? "{$pct}% — {$description}" : "{$pct}%";
    }
}
