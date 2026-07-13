<?php

namespace App\Services\Whmcs;

/**
 * Outcome of a single outbound push (ekdosi settlement → WHMCS mark-paid):
 *  - PUSHED       — we recorded a payment in WHMCS (it was Unpaid there).
 *  - ALREADY_PAID — WHMCS already showed it Paid; nothing sent, marker stamped.
 *  - SKIPPED      — not eligible (opt-out, not settled, no real payment, already
 *                   pushed, WHMCS unreachable…); nothing sent, marker untouched.
 *  - FAILED       — a WHMCS-side rejection while pushing; marker untouched so a
 *                   later run/click retries.
 */
enum PaymentPushResult: string
{
    case Pushed = 'pushed';
    case AlreadyPaid = 'already_paid';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
