<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Services\CustomerLedger\CustomerTopProducts;
use Illuminate\Support\Carbon;

/**
 * Κορυφαία προϊόντα/υπηρεσίες της εταιρείας ανά διάστημα — «τι πουλάμε πιο πολύ».
 * Reuses {@see CustomerTopProducts::forCompany()} (live sales only, credit notes
 * and unissued drafts excluded), tenant-scoped, no AADE call.
 */
class TopProductsTool implements AssistantTool
{
    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 50;

    /** Cap the analysed window: forCompany() materialises the period's lines in PHP
     *  before ranking, so an unbounded «top from 2015» would blow memory. Two years
     *  covers «this year vs last year»; wider requests are silently capped (the
     *  returned `from` reflects the real window). A SQL-aggregated variant could lift
     *  this later. */
    private const MAX_SPAN_DAYS = 731;

    public function name(): string
    {
        return 'top_products';
    }

    public function description(): string
    {
        return 'Κορυφαία προϊόντα/υπηρεσίες που πούλησε η εταιρεία σε ένα διάστημα, ταξινομημένα κατά '
            .'συχνότητα: ονομασία, πόσες φορές, συνολική ποσότητα και καθαρή αξία, τελευταία πώληση. '
            .'Για «τι πουλάμε πιο πολύ», «top υπηρεσίες του μήνα», «δημοφιλέστερα είδη». Ημερομηνίες '
            .'προαιρετικές (YYYY-MM-DD, προεπιλογή τρέχων μήνας)· limit προαιρετικό (προεπιλογή 10).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Αρχή (YYYY-MM-DD). Προεπιλογή: αρχή τρέχοντος μήνα.'],
                'to' => ['type' => 'string', 'description' => 'Τέλος (YYYY-MM-DD). Προεπιλογή: σήμερα.'],
                'limit' => ['type' => 'integer', 'description' => 'Πλήθος αποτελεσμάτων (1-50, προεπιλογή 10).'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'View:Invoice';
    }

    public function run(Company $tenant, array $input): array
    {
        $from = ! empty($input['from']) ? Carbon::parse($input['from'])->startOfDay() : now()->startOfMonth();
        $to = ! empty($input['to']) ? Carbon::parse($input['to'])->endOfDay() : now()->endOfDay();
        $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)));

        $earliest = $to->copy()->subDays(self::MAX_SPAN_DAYS);
        $capped = $from->lt($earliest);
        if ($capped) {
            $from = $earliest;
        }

        $rows = (new CustomerTopProducts)->forCompany($tenant, $from, $to, $limit);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'window_capped' => $capped,
            'limit' => $limit,
            'count' => count($rows),
            'products' => array_map(static fn (array $r): array => [
                'label' => $r['label'],
                'sku' => $r['sku'],
                'times' => $r['times'],
                'qty' => $r['qty'],
                'unit' => $r['unit'],
                'net' => $r['net'],
                'last_at' => $r['last_at'],
            ], $rows),
        ];
    }
}
