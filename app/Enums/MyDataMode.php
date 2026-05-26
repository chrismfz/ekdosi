<?php

namespace App\Enums;

/**
 * Per-tenant myDATA submission mode. Replaces the older
 * `companies.mydata_production` boolean which couldn't express
 * "PDF-only, no AADE submission attempt at all" — a legitimate state
 * for training tenants, the WHMCS-bridge testing window, and any
 * period where AADE is offline but operators still need to issue
 * invoices locally.
 *
 * The submitter factory (App\Services\EInvoiceSubmitterFactory) routes
 * to the right concrete EInvoiceSubmitter based on this value:
 *
 *   Off        → NullSubmitter         (no AADE call)
 *   Sandbox    → MyDataSubmitter(dev)  (AADE test endpoint, synthetic MARKs)
 *   Production → MyDataSubmitter(prod) (real submission, real tax record)
 *
 * The Filament Company form renders this as a 3-way Select with a
 * confirm modal when switching INTO Production (to prevent accidental
 * activation) and another when switching OUT of Production (to
 * prevent silent disabling of legally-required reporting).
 */
enum MyDataMode: string
{
    case Off = 'off';
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off (no AADE submission, PDF only)',
            self::Sandbox => 'Sandbox (AADE test endpoint)',
            self::Production => 'Production (LIVE submissions)',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
