<?php

namespace App\Support;

use App\Models\Ticket;
use Illuminate\Support\Carbon;

/**
 * Generates a ticket reference `TK-YYYY-MM-DD-xxxxxx` (Πυλώνας E): the open date
 * (readable at a glance) + a 6-char random tail from an unambiguous alphabet. The
 * random tail is what makes the reference — which doubles as the email subject
 * token `[TK-…]` and the threading key — unguessable, so a crafted reply can't
 * land on someone else's ticket. Unique per company (retries on collision;
 * withTrashed so a soft-deleted ref is never reused).
 */
class TicketReference
{
    /** Unambiguous 31-char alphabet (no 0/1/I/O/L). */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const TAIL_LENGTH = 6;

    public static function generate(int $companyId, ?Carbon $on = null): string
    {
        $date = ($on ?? Carbon::now())->format('Y-m-d');

        do {
            $reference = 'TK-'.$date.'-'.self::tail();
        } while (
            Ticket::withTrashed()
                ->where('company_id', $companyId)
                ->where('reference', $reference)
                ->exists()
        );

        return $reference;
    }

    private static function tail(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < self::TAIL_LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
