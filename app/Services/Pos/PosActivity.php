<?php

namespace App\Services\Pos;

use App\Models\Company;
use App\Models\PosEvent;
use App\Models\Scopes\CompanyScope;
use Throwable;

/**
 * Writes the till's activity log («Ιστορικό ενεργειών ταμία»). Best-effort: a failure
 * to log is reported, never thrown — the till must keep selling.
 */
class PosActivity
{
    public function __construct(private readonly TillSessions $tills) {}

    public function log(Company $company, string $type, ?int $productId = null, ?float $qty = null, ?float $amount = null, ?string $note = null, ?int $invoiceId = null): void
    {
        try {
            PosEvent::query()->withoutGlobalScope(CompanyScope::class)->create([
                'company_id' => $company->getKey(),
                'pos_session_id' => $this->tills->current($company)?->getKey(),
                'user_id' => auth()->id(),
                'type' => $type,
                'invoice_id' => $invoiceId,
                'product_id' => $productId,
                'qty' => $qty === null ? null : round($qty, 3),
                'amount' => $amount === null ? null : round($amount, 2),
                'note' => $note === null ? null : mb_substr($note, 0, 255),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
