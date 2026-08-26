<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Services\Updates\UpdateChecker;

/**
 * «Τι έκδοση τρέχουμε / υπάρχει ενημέρωση;» — reports the deployed ekdosi build
 * and whether a newer release exists, from the read-only {@see UpdateChecker}.
 * Uses the NON-BLOCKING cached read (never hits GitHub inline), so it answers
 * instantly; the actual check is refreshed by the System page / scheduler. This
 * NEVER downloads or applies anything — upgrades stay with deploy/update.sh.
 *
 * Version info is global (not tenant data), so no permission gate.
 */
class AppVersionTool implements AssistantTool
{
    public function name(): string
    {
        return 'app_version';
    }

    public function description(): string
    {
        return 'Δείξε την τρέχουσα έκδοση/build του ekdosi και αν υπάρχει διαθέσιμη ενημέρωση '
            .'(τελευταία έκδοση, πόσα commits πίσω). Για ερωτήσεις «τι έκδοση τρέχουμε», «υπάρχει '
            .'update;», «είμαστε up to date;». Μόνο ανάγνωση — δεν κατεβάζει/εγκαθιστά τίποτα.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function permission(): ?string
    {
        return null;
    }

    public function run(Company $tenant, array $input): array
    {
        $status = app(UpdateChecker::class)->cached();

        return [
            'app' => config('app.name'),
            'current_version' => $status['current_version'] ?? config('app.version'),
            'current_build' => $status['current_build'] ?? null,
            'current_sha' => $status['current_sha'] ?? null,
            'update_available' => (bool) ($status['update_available'] ?? false),
            'latest_version' => $status['latest_version'] ?? null,
            'commits_behind' => $status['commits_behind'] ?? null,
            'checked_at' => $status['checked_at'] ?? null,
            'note' => ($status['error'] ?? null)
                ?: 'Ο έλεγχος είναι μόνο ενημερωτικός· η αναβάθμιση γίνεται με deploy/update.sh.',
        ];
    }
}
