<?php

namespace App\Actions;

use RuntimeException;
use Throwable;

/**
 * An exchange whose RETURN credit note was issued but whose NEW sale was not. The
 * credit note stands (it is a filed legal document): the till prints it, empties the
 * cart and tells the cashier to refund — or re-ring the new sale (its draft, if any,
 * is saleDraftId, never to be rung again as a new document).
 */
class PosExchangeIncomplete extends RuntimeException
{
    public function __construct(
        public readonly int $creditId,
        public readonly string $creditCode,
        public readonly ?int $saleDraftId,
        Throwable $previous,
    ) {
        parent::__construct(
            'Η επιστροφή εκδόθηκε ('.$creditCode.'), αλλά η νέα πώληση όχι: '.$previous->getMessage()
            .' — δώσε πίσω τα χρήματα της επιστροφής ή ξαναχτύπησε τη νέα πώληση.',
            previous: $previous,
        );
    }
}
