<?php

namespace App\Services\Assistant\Tools;

use App\Enums\ExpenseSource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Company;
use App\Models\Expense;
use App\Support\MyData\Codes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The company's expenses (Έξοδα) with filters — period, economic category
 * (προμηθευτές / μισθοδοσία / ΕΦΚΑ / ενδοκοινοτικά / ΑΛΠ / …), myDATA type,
 * supplier — plus the filtered totals, and optionally each document's lines with
 * their E3 classification (what answers «are the employer contributions inside the
 * 17.1?»). Tenant-scoped by explicit company_id; local data only.
 */
class ExpenseListTool implements AssistantTool
{
    private const MAX_SPAN_DAYS = 731;

    private const MAX_LIMIT = 200;

    /** `category` values: 'suppliers' / 'manual' by source, else the self-declared bucket key. */
    private const CATEGORIES = ['suppliers', 'manual', 'payroll', 'social_security', 'depreciation', 'intracommunity', 'retail_expense', 'adjustments', 'other'];

    public function name(): string
    {
        return 'expense_list';
    }

    public function description(): string
    {
        return 'Λίστα εξόδων της εταιρείας με φίλτρα: διάστημα, οικονομική κατηγορία (suppliers = τιμολόγια '
            .'προμηθευτών, manual = χειροκίνητα, payroll = μισθοδοσία 17.1, social_security = ΕΦΚΑ 14.5, depreciation = '
            .'αποσβέσεις, intracommunity = ενδοκοινοτικά/τρίτες χώρες 14.x, retail_expense = αποδείξεις λιανικής 13.x, '
            .'adjustments, other), τύπος myDATA (π.χ. "17.1" ή πρόθεμα "14"), προμηθευτής (ΑΦΜ ή μέρος επωνυμίας). '
            .'Επιστρέφει σύνολα (πλήθος/καθαρά/ΦΠΑ/μικτά, πιστωτικά αφαιρούνται) + τις εγγραφές με link· με '
            .'`with_lines`=true και τις γραμμές με τον χαρακτηρισμό Ε3 (π.χ. E3_581_001 αποδοχές / E3_581_002 εισφορές). '
            .'Για «δείξε τα έξοδα μισθοδοσίας», «τι πληρώσαμε στον Χ», «ποια ενδοκοινοτικά έχουμε». Τοπικά δεδομένα.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Αρχή (YYYY-MM-DD). Προεπιλογή: 1/1 του τρέχοντος έτους.'],
                'to' => ['type' => 'string', 'description' => 'Τέλος (YYYY-MM-DD). Προεπιλογή: σήμερα.'],
                'category' => ['type' => 'string', 'enum' => self::CATEGORIES, 'description' => 'Οικονομική κατηγορία.'],
                'invoice_type' => ['type' => 'string', 'description' => 'Τύπος myDATA ακριβώς ("17.1") ή πρόθεμα χωρίς τελεία ("14").'],
                'supplier' => ['type' => 'string', 'description' => 'ΑΦΜ (ακριβώς) ή μέρος της επωνυμίας.'],
                'with_lines' => ['type' => 'boolean', 'description' => 'Και οι γραμμές με χαρακτηρισμό Ε3. Προεπιλογή false.'],
                'include_cancelled' => ['type' => 'boolean', 'description' => 'Και τα ακυρωμένα στην ΑΑΔΕ (δεν μετράνε στα σύνολα). Προεπιλογή false.'],
                'limit' => ['type' => 'integer', 'description' => 'Πόσες εγγραφές (νεότερες πρώτα). Προεπιλογή 50, μέγιστο 200.'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'ViewAny:Expense';
    }

    public function run(Company $tenant, array $input): array
    {
        $to = ! empty($input['to']) ? Carbon::parse($input['to'])->endOfDay() : now()->endOfDay();
        $from = ! empty($input['from']) ? Carbon::parse($input['from'])->startOfDay() : now()->startOfYear();
        $earliest = $to->copy()->subDays(self::MAX_SPAN_DAYS);
        $capped = $from->lt($earliest);
        if ($capped) {
            $from = $earliest;
        }

        $category = $input['category'] ?? null;
        if ($category !== null && ! in_array($category, self::CATEGORIES, true)) {
            return ['error' => 'Άγνωστη κατηγορία. Επιτρέπονται: '.implode(', ', self::CATEGORIES).'.'];
        }
        $includeCancelled = (bool) ($input['include_cancelled'] ?? false);
        $withLines = (bool) ($input['with_lines'] ?? false);
        $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limit'] ?? 50)));

        $query = Expense::query()
            ->where('company_id', $tenant->getKey())
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->when(! $includeCancelled, fn (Builder $q) => $q->where(fn ($w) => $w
                ->whereNull('mydata_state')->orWhere('mydata_state', '!=', 'CANCELLED')))
            ->when($category !== null, fn (Builder $q) => $this->applyCategory($q, $category))
            ->when(filled($input['invoice_type'] ?? null), function (Builder $q) use ($input) {
                $type = trim((string) $input['invoice_type']);
                str_contains($type, '.') ? $q->where('invoice_type', $type) : $q->where('invoice_type', 'like', $type.'.%');
            })
            ->when(filled($input['supplier'] ?? null), function (Builder $q) use ($input) {
                $s = trim((string) $input['supplier']);
                ctype_digit($s)
                    ? $q->where('supplier_afm', $s)
                    : $q->where(fn ($w) => $w->where('supplier_name', 'like', "%{$s}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$s}%")));
            });

        // Totals over the whole filtered set (credit notes subtract; AADE-cancelled never count).
        $totals = ['count' => 0, 'net' => 0.0, 'vat' => 0.0, 'gross' => 0.0, 'cancelled_not_counted' => 0];
        foreach ((clone $query)->get(['invoice_type', 'net_total', 'vat_total', 'gross_total', 'mydata_state']) as $e) {
            if ($e->mydata_state === 'CANCELLED') {
                $totals['cancelled_not_counted']++;

                continue;
            }
            $sign = in_array($e->invoice_type, Codes::CREDIT_NOTE_TYPES, true) ? -1 : 1;
            $totals['count']++;
            $totals['net'] += $sign * (float) $e->net_total;
            $totals['vat'] += $sign * (float) $e->vat_total;
            $totals['gross'] += $sign * (float) $e->gross_total;
        }

        $rows = $query
            ->with(['supplier:id,name,afm', ...($withLines ? ['lines'] : [])])
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Expense $e) => [
                'id' => $e->id,
                'date' => $e->issue_date?->toDateString(),
                'invoice_type' => $e->invoice_type,
                'doc' => trim(($e->series ?? '').' '.($e->aa ?? '')) ?: null,
                'supplier' => $e->supplier?->name ?? $e->supplier_name,
                'supplier_afm' => $e->supplier?->afm ?? $e->supplier_afm,
                'category' => $this->categoryOf($e),
                'net' => (float) $e->net_total,
                'vat' => (float) $e->vat_total,
                'gross' => (float) $e->gross_total,
                'is_credit' => in_array($e->invoice_type, Codes::CREDIT_NOTE_TYPES, true),
                'mydata_state' => $e->mydata_state,
                'mark' => $e->mydata_mark,
                'url' => ExpenseResource::getUrl('view', ['record' => $e->id, 'tenant' => $tenant]),
            ] + ($withLines ? ['lines' => $e->lines->sortBy('line_number')->map(fn ($l) => [
                'line' => $l->line_number,
                'description' => $l->item_descr,
                'net' => (float) $l->net_value,
                'vat' => (float) $l->vat_amount,
                'classification_type' => $l->classification_type,
                'classification_type_label' => $l->classification_type ? Codes::e3TypeLabel($l->classification_type) : null,
                'classification_category' => $l->classification_category,
            ])->values()->all()] : []))
            ->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'window_capped' => $capped,
            'currency' => 'EUR',
            'totals' => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $totals),
            'shown' => count($rows),
            'rows' => $rows,
        ];
    }

    private function applyCategory(Builder $q, string $category): void
    {
        match ($category) {
            'suppliers' => $q->where('source', ExpenseSource::Sync->value),
            'manual' => $q->where('source', ExpenseSource::Manual->value),
            'other' => $q->where('source', ExpenseSource::SelfDeclared->value)
                ->where(fn ($w) => $w->whereNull('category')->orWhere('category', 'other')),
            default => $q->where('source', ExpenseSource::SelfDeclared->value)->where('category', $category),
        };
    }

    private function categoryOf(Expense $e): string
    {
        return match ($e->source) {
            ExpenseSource::Sync => 'suppliers',
            ExpenseSource::SelfDeclared => $e->category ?: 'other',
            default => 'manual',
        };
    }
}
