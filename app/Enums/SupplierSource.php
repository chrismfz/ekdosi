<?php

namespace App\Enums;

/**
 * Where a supplier (προμηθευτής) record came from. Suppliers are net-new
 * (the legacy app had no supplier/expense concept), so unlike customers
 * there is no `legacy_id` / Firebird origin — provenance is tracked here:
 *
 *   - sync   : auto-created from a myDATA RequestDocs issuer AFM we didn't
 *              have yet (the expense-side analog of an "αδέσποτο" doc).
 *   - manual : created by an operator in the panel.
 *   - import : bulk-imported (CSV / future ETL).
 */
enum SupplierSource: string
{
    case Sync = 'sync';
    case Manual = 'manual';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Sync => 'Συγχρονισμός (myDATA)',
            self::Manual => 'Χειροκίνητη καταχώριση',
            self::Import => 'Εισαγωγή',
        };
    }
}
