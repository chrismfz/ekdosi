<?php

namespace App\Filament\Support;

use App\Models\BankAccount;
use Filament\Forms\Components\Select;

/**
 * The shared «Τραπεζικός λογαριασμός (κατάθεση)» picker, reused by every payment
 * form (cockpit, ViewInvoice, Καρτέλα, έμβασμα) and the invoice form. Lists the
 * tenant's ACTIVE bank accounts; hidden entirely when the tenant has none, so
 * cash-only tenants see no clutter. Optional — informational only, no money
 * logic. Keep it on the `bank_account_id` column on both payments and invoices.
 */
class BankAccountField
{
    public static function make(?int $companyId, string $helper = 'Σε ποιον λογαριασμό κατατέθηκε (για έμβασμα/τραπεζική πληρωμή).'): Select
    {
        $options = BankAccount::activeOptions($companyId);

        return Select::make('bank_account_id')
            ->label('Τραπεζικός λογαριασμός')
            ->options($options)
            ->searchable()
            ->placeholder('—')
            ->helperText($helper)
            ->visible(fn () => $options !== []);
    }
}
