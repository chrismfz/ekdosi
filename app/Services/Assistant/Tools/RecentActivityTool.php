<?php

namespace App\Services\Assistant\Tools;

use App\Models\Activity;
use App\Models\Company;

/**
 * «Τι άλλαξε πρόσφατα / ιστορικό ενεργειών» — the tenant's business audit trail
 * (spatie/activitylog: invoice / customer / payment created·updated·deleted, who
 * did it, and the per-field diff). Mirrors the «Ιστορικό» / ActivityFeed page,
 * scoped to the ambient company. Useful for "who changed this invoice", "τι
 * έγινε σήμερα", light debugging of a surprising state change — from anywhere,
 * including an external MCP client. Read-only.
 *
 * Gated on View:ActivityFeed (company_admin + super_admin), like the page.
 */
class RecentActivityTool implements AssistantTool
{
    public function name(): string
    {
        return 'recent_activity';
    }

    public function description(): string
    {
        return 'Δείξε τις πρόσφατες καταγεγραμμένες ενέργειες (ιστορικό/audit) της τρέχουσας '
            .'εταιρείας: δημιουργία/τροποποίηση/διαγραφή σε τιμολόγια, πελάτες, πληρωμές — ποιος '
            .'τις έκανε και τι άλλαξε. Για «τι άλλαξε πρόσφατα», «ποιος πείραξε το τιμολόγιο Χ», '
            .'«ιστορικό σήμερα». Προαιρετικό `limit` (προεπιλογή 20, μέγιστο 100).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Πόσες εγγραφές (προεπιλογή 20, μέγιστο 100).'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'View:ActivityFeed';
    }

    public function run(Company $tenant, array $input): array
    {
        $limit = (int) ($input['limit'] ?? 20);
        $limit = max(1, min($limit, 100));

        $rows = Activity::query()
            ->where('company_id', $tenant->getKey())
            ->with('causer')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (Activity $a): array => [
                'at' => $a->created_at?->toIso8601String(),
                'subject' => $a->subjectLabel(),
                'subject_id' => $a->subject_id,
                'event' => $a->event,
                'action' => $a->description,
                'by' => $a->causer?->name ?? 'Σύστημα',
                'changes' => $a->changeLines(),
            ])
            ->all();

        return [
            'count' => count($rows),
            'limit' => $limit,
            'activity' => $rows,
        ];
    }
}
