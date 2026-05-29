<?php

namespace App\Enums;

/**
 * Where an expense (έξοδο / παραστατικό εξόδων) record came from —
 * provenance, mirroring SupplierSource. Net-new like the whole Έξοδα phase:
 *
 *   - sync   : pulled from myDATA RequestDocs (a supplier filed it against us)
 *              and recorded locally — the expense-side analog of an
 *              "αδέσποτο" sales doc imported from myDATA.
 *   - manual : keyed by an operator in the panel.
 *
 * (An `import` case may join later for bulk ETL/CSV, as with suppliers; kept
 * out until there's a real importer to back it.)
 */
enum ExpenseSource: string
{
    case Sync = 'sync';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Sync => 'Συγχρονισμός (myDATA)',
            self::Manual => 'Χειροκίνητη καταχώριση',
        };
    }

    /**
     * value => label map for Filament Selects / filters.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s): array => [$s->value => $s->label()])
            ->all();
    }
}
