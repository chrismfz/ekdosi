<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Models\PendingWhmcsInvoice;

/**
 * Πόσα προτιμολόγια WHMCS περιμένουν στα «Εισερχόμενα» — the operator's «ξέχασα
 * κάτι;» tool. Counts the tenant's staged WHMCS documents by open status
 * (pending_review / held); filed/rejected/drafted are terminal-ish and excluded
 * from «ανοιχτά». Read-only, tenant-scoped, no WHMCS call (reads our own inbox).
 */
class WhmcsInboxTool implements AssistantTool
{
    public function name(): string
    {
        return 'whmcs_inbox';
    }

    public function description(): string
    {
        return 'Πόσα προτιμολόγια από το WHMCS περιμένουν στα «Εισερχόμενα» για έκδοση παραστατικού. '
            .'`pending_review` = προς έλεγχο (ΤΟ ΙΔΙΟ νούμερο με το σήμα δίπλα στο μενού «Εισερχόμενα»)· '
            .'`held` = σε αναμονή χειριστή (κρατημένα με λόγο, ξεχωριστά). Για «ξέχασα κάτι στο WHMCS '
            .'inbox;», «εκκρεμή προτιμολόγια». Χωρίς παραμέτρους· διαβάζει το τοπικό inbox, δεν καλεί το WHMCS.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
        ];
    }

    public function permission(): ?string
    {
        return 'View:PendingWhmcsInvoice';
    }

    public function run(Company $tenant, array $input): array
    {
        $base = fn (string $status): int => PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->getKey())
            ->where('status', $status)
            ->count();

        // `pending_review` is the headline — it matches the «Εισερχόμενα» nav badge
        // (WhmcsInboxResource::getNavigationBadge counts only this status), so the AI
        // and the panel never disagree. `held` is a distinct parked state, reported
        // separately rather than summed into a total that no panel surface shows.
        return [
            'pending_review' => $base(PendingWhmcsInvoice::STATUS_PENDING_REVIEW),
            'held' => $base(PendingWhmcsInvoice::STATUS_HELD),
        ];
    }
}
