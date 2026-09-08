<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Services\Assistant\AiUsageReport;
use Carbon\CarbonImmutable;

/**
 * Πόση χρήση/κόστος έκανε ο AI «Βοηθός» ΓΙΑ ΤΗΝ ΕΤΑΙΡΕΙΑ σε έναν μήνα — tokens,
 * εκτ. κόστος (USD), μηνιαίο όριο + % ορίου, και ανά χρήστη. Per-tenant (η
 * τρέχουσα εταιρεία μόνο)· ο cross-tenant «ποιος πληρώνει» παραμένει στη σελίδα
 * «Χρήση & κόστος AI» (super-admin) — αλλά μέσω MCP με `company="all"` ένας
 * super-admin παίρνει ανά-εταιρεία fan-out. Read-only, gated στο View:CompanySettings.
 */
class AiUsageTool implements AssistantTool
{
    public function name(): string
    {
        return 'ai_usage';
    }

    public function description(): string
    {
        return 'Χρήση και κόστος του AI «Βοηθού» για την ΤΡΕΧΟΥΣΑ εταιρεία σε έναν μήνα: πλήθος '
            .'αιτημάτων, tokens (in/out/cache), εκτιμώμενο κόστος σε USD, το μηνιαίο όριο tokens και '
            .'το % του ορίου, καθώς και ανάλυση ανά χρήστη. Για «πόσο κόστισε το AI τον μήνα», '
            .'«πλησιάζουμε το όριο του Βοηθού;», «ποιος χρησιμοποιεί τον Βοηθό». Παράμετρος `month` '
            .'προαιρετική (YYYY-MM, προεπιλογή τρέχων μήνας). Κόστος = εκτίμηση (ανά μοντέλο), όχι τιμολόγιο.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'month' => ['type' => 'string', 'description' => 'Μήνας σε μορφή YYYY-MM. Προεπιλογή: τρέχων μήνας.'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'View:CompanySettings';
    }

    public function run(Company $tenant, array $input): array
    {
        $month = (isset($input['month']) && CarbonImmutable::hasFormat((string) $input['month'], 'Y-m'))
            ? (string) $input['month']
            : CarbonImmutable::now()->format('Y-m');

        return app(AiUsageReport::class)->forTenant($tenant, $month);
    }
}
