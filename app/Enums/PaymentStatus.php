<?php

namespace App\Enums;

/**
 * Money status of an invoice, derived by App\Services\InvoiceBalance and
 * cached on invoices.payment_status. Greek labels (operator-facing);
 * the enum value is the stored English token. Single home for the
 * label + badge colour so every surface (invoice list/view, customer
 * Καρτέλα, dashboard) stays consistent.
 */
enum PaymentStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';
    case Credited = 'credited';
    case Overpaid = 'overpaid';

    public function label(): string
    {
        return match ($this) {
            self::Paid     => 'Εξοφλημένο',
            self::Partial  => 'Μερική εξόφληση',
            self::Unpaid   => 'Ανεξόφλητο',
            self::Credited => 'Πιστωμένο',
            self::Overpaid => 'Υπερπληρωμή',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid     => 'success',
            self::Partial  => 'warning',
            self::Unpaid   => 'gray',
            self::Credited => 'info',
            self::Overpaid => 'warning',
        };
    }
}
