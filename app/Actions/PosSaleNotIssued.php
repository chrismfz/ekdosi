<?php

namespace App\Actions;

use RuntimeException;
use Throwable;

/**
 * A till sale whose DRAFT was created but whose issue (number + activate, or the
 * e-invoice filing) failed. Carries the draft so the till never rings the same
 * cart again as a NEW document — a timed-out filing may already be at AADE
 * (in-doubt), and AADE doesn't dedup a resubmit.
 */
class PosSaleNotIssued extends RuntimeException
{
    public function __construct(public readonly int $invoiceId, Throwable $previous)
    {
        parent::__construct(
            'Η απόδειξη δεν εκδόθηκε: '.$previous->getMessage().' — έμεινε στο πρόχειρο #'.$invoiceId
            .'. Μην την ξαναχτυπήσεις στο ταμείο: άνοιξέ τη στα «Παραστατικά» και ξαναστείλ\' την από εκεί.',
            previous: $previous,
        );
    }
}
