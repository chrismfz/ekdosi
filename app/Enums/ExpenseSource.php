<?php

namespace App\Enums;

/**
 * Where an expense (έξοδο / παραστατικό εξόδων) record came from —
 * provenance, mirroring SupplierSource. Net-new like the whole Έξοδα phase:
 *
 *   - sync          : pulled from myDATA RequestDocs (a supplier filed it
 *                     against us) and recorded locally — the expense-side
 *                     analog of an "αδέσποτο" sales doc imported from myDATA.
 *   - self_declared : pulled from myDATA RequestTransmittedDocs — a non-income
 *                     document WE declared (αποδείξεις 13.x, ενδοκοινοτικά/VIES
 *                     14.x, μισθοδοσία/πάγια/τακτοποιήσεις 17.x). Same Έξοδα
 *                     table, kept distinct by this source + the `category`
 *                     column so accounting entries don't read as invoices.
 *   - manual        : keyed by an operator in the panel.
 *
 * (An `import` case may join later for bulk ETL/CSV, as with suppliers; kept
 * out until there's a real importer to back it.)
 */
enum ExpenseSource: string
{
    case Sync = 'sync';
    case SelfDeclared = 'self_declared';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Sync => 'Συγχρονισμός (myDATA)',
            self::SelfDeclared => 'Δικό μας (myDATA)',
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
