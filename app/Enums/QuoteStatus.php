<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a Προσφορά. Non-legal, so the machine is simple:
 *
 *   Draft ──(send)──▶ Sent ──(accept)──▶ Accepted ──(convert)──▶ [draft invoice]
 *      │                 │
 *      └──(reject)───────┴──▶ Rejected
 *
 * Expired is set manually (or by a future sweep) when valid_until passes.
 * Conversion to an invoice is gated to Accepted (see ConvertQuoteToInvoice).
 */
enum QuoteStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Πρόχειρη',
            self::Sent => 'Απεσταλμένη',
            self::Accepted => 'Αποδεκτή',
            self::Rejected => 'Απορρίφθηκε',
            self::Expired => 'Έληξε',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'info',
            self::Accepted => 'success',
            self::Rejected => 'danger',
            self::Expired => 'warning',
        };
    }
}
