<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Services\Accounting\LedgerBook;
use Illuminate\Support\Carbon;

/**
 * Έσοδα vs έξοδα για ένα διάστημα — the Βιβλίο Εσόδων-Εξόδων totals as a tool.
 * Local data only (issued invoices as income, expenses as expense; no AADE call),
 * tenant-scoped via {@see LedgerBook}. Answers «πόσα έβγαλα/ξόδεψα», «ΦΠΑ προς
 * απόδοση» — the expense side that {@see VatSummaryTool} (sales only) doesn't cover.
 */
class IncomeVsExpenseTool implements AssistantTool
{
    /** Bound the analysed window: LedgerBook materialises the period's invoices +
     *  expenses in PHP, so an unbounded «όλη η ιστορία» would blow memory. Two years
     *  covers year-over-year; wider requests are silently capped (the returned
     *  `from` reflects the real window). */
    private const MAX_SPAN_DAYS = 731;

    public function name(): string
    {
        return 'income_vs_expense';
    }

    public function description(): string
    {
        return 'Σύγκριση εσόδων και εξόδων για ένα διάστημα (Βιβλίο Εσόδων-Εξόδων): καθαρή αξία, '
            .'ΦΠΑ και μικτά για ΕΣΟΔΑ και ΕΞΟΔΑ ξεχωριστά, το υπόλοιπο ΦΠΑ (εκροών − εισροών) και '
            .'πλήθος εγγραφών. Τα ΕΞΟΔΑ και ανά οικονομική κατηγορία (`expense_breakdown`): τιμολόγια '
            .'προμηθευτών, χειροκίνητα, και όσα δηλώνει ο λογιστής — μισθοδοσία (17.1), ΕΦΚΑ (14.5), '
            .'αποσβέσεις (17.2), ενδοκοινοτικά, τακτοποιήσεις — με `last_date` = ως πότε έχει περαστεί η '
            .'κατηγορία (π.χ. μισθοδοσία ανά τρίμηνο). Για «έσοδα vs έξοδα», «πόσα έβγαλα/ξόδεψα», '
            .'«πόση μισθοδοσία/ΕΦΚΑ», «ΦΠΑ προς απόδοση/πιστωτικό». '
            .'Ημερομηνίες προαιρετικές (YYYY-MM-DD, προεπιλογή τρέχων μήνας). Τοπικά δεδομένα, χωρίς κλήση ΑΑΔΕ.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Αρχή (YYYY-MM-DD). Προεπιλογή: αρχή τρέχοντος μήνα.'],
                'to' => ['type' => 'string', 'description' => 'Τέλος (YYYY-MM-DD). Προεπιλογή: σήμερα.'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'View:LedgerBook';
    }

    public function run(Company $tenant, array $input): array
    {
        $from = ! empty($input['from']) ? Carbon::parse($input['from'])->startOfDay() : now()->startOfMonth();
        $to = ! empty($input['to']) ? Carbon::parse($input['to'])->endOfDay() : now()->endOfDay();

        $earliest = $to->copy()->subDays(self::MAX_SPAN_DAYS);
        $capped = $from->lt($earliest);
        if ($capped) {
            $from = $earliest;
        }

        $book = (new LedgerBook($tenant))->forPeriod($from, $to);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'window_capped' => $capped,
            'currency' => 'EUR',
            'income' => [
                'count' => $book->incomeCount(),
                'net' => round($book->incomeNet(), 2),
                'vat' => round($book->incomeVat(), 2),
                'gross' => round($book->incomeGross(), 2),
            ],
            'expense' => [
                'count' => $book->expenseCount(),
                'net' => round($book->expenseNet(), 2),
                'vat' => round($book->expenseVat(), 2),
                'gross' => round($book->expenseGross(), 2),
                'capex_net' => $book->expenseCapex(), // εκ των οποίων αγορές παγίων (E3_882/883) — όχι έξοδο χρήσης
            ],
            'expense_breakdown' => $book->expenseBreakdown(),
            'vat_balance' => $book->vatBalance(),
            'vat_balance_note' => $book->vatBalance() >= 0 ? 'προς απόδοση' : 'πιστωτικό υπόλοιπο',
        ];
    }
}
