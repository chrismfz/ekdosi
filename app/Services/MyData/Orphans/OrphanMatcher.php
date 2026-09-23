<?php

namespace App\Services\MyData\Orphans;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use Carbon\CarbonImmutable;

/**
 * «Ποιο τοπικό παραστατικό μπορεί να είναι;» — for a myDATA orphan (a document
 * filed under our ΑΦΜ with no local invoice carrying its MARK), the ISSUED local
 * invoices WITHOUT a MARK that resemble it, strongest first.
 *
 * myDATA carries no line descriptions, but it does carry what identifies a
 * document: series/ΑΑ, issue date, net/VAT/gross and the counterpart's ΑΦΜ. Each
 * matching signal scores. Only the same series/ΑΑ + counterpart + amount can be
 * LINKED (OrphanLinker decides, the operator confirms — a legal link is never
 * guessed); a lookalike under another number is shown as a possible double issue.
 */
final class OrphanMatcher
{
    /** How far around the issue date a candidate may sit (unless the ΑΑ matches). */
    public const DATE_WINDOW_DAYS = 10;

    /** Below this a «candidate» is noise, not a suggestion. */
    public const MIN_SCORE = 40;

    public const MAX_CANDIDATES = 5;

    /**
     * @param  array<string, mixed>  $doc  MarkDetail::fromAadeDoc shape
     * @return list<array{invoice: Invoice, score: int, reasons: list<string>}>
     */
    public function candidates(Company $company, array $doc): array
    {
        $date = self::issueDate($doc);
        $series = self::str($doc['series'] ?? null);
        $aa = self::str($doc['aa'] ?? null);

        $pool = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where(fn ($q) => $q->whereNull('mydata_mark')->orWhere('mydata_mark', ''))
            // A draft was never issued — it can't be a document myDATA holds.
            ->where('local_status', '!=', 'draft')
            ->where(function ($q) use ($date, $aa): void {
                if ($date !== null) {
                    $q->whereBetween('issued_at', [
                        $date->subDays(self::DATE_WINDOW_DAYS)->startOfDay(),
                        $date->addDays(self::DATE_WINDOW_DAYS)->endOfDay(),
                    ]);
                }
                if ($aa !== null && ctype_digit($aa)) {
                    // The same ΑΑ matches whatever the date (a mistyped date is
                    // exactly how an orphan happens).
                    $q->orWhere('code', (int) $aa);
                }
            })
            ->with(['invoiceType:id,code,mydata_type,is_credit', 'customer:id,afm,name'])
            ->limit(500)
            ->get();

        $scored = [];
        foreach ($pool as $invoice) {
            [$score, $reasons] = $this->score($invoice, $doc, $date, $series, $aa);
            if ($score >= self::MIN_SCORE) {
                $scored[] = ['invoice' => $invoice, 'score' => $score, 'reasons' => $reasons];
            }
        }

        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['invoice']->getKey()] <=> [$a['score'], $b['invoice']->getKey()]);

        return array_slice($scored, 0, self::MAX_CANDIDATES);
    }

    /**
     * A local invoice with the SAME series/ΑΑ that already carries ANOTHER MARK —
     * the orphan is then a second filing of that number (e.g. filed, cancelled
     * and re-filed elsewhere), not a missing local record.
     */
    public function sameNumberWithOtherMark(Company $company, array $doc): ?Invoice
    {
        $series = self::str($doc['series'] ?? null);
        $aa = self::str($doc['aa'] ?? null);
        if ($aa === null || ! ctype_digit($aa)) {
            return null;
        }

        return Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('code', (int) $aa)
            ->whereNotNull('mydata_mark')->where('mydata_mark', '!=', '')
            ->where('mydata_mark', '!=', (string) ($doc['mark'] ?? ''))
            ->with('invoiceType:id,code')
            ->get()
            ->first(fn (Invoice $i): bool => $series === null || self::str($i->filedSeries()) === $series);
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function score(Invoice $invoice, array $doc, ?CarbonImmutable $date, ?string $series, ?string $aa): array
    {
        $score = 0;
        $reasons = [];

        if ($aa !== null && (string) $invoice->code === $aa) {
            if ($series !== null && self::str($invoice->filedSeries()) === $series) {
                $score += 100;
                $reasons[] = 'ίδια σειρά & ΑΑ';
            } else {
                $score += 30;
                $reasons[] = 'ίδιος ΑΑ';
            }
        }

        $gross = self::num($doc['grossTotal'] ?? null);
        $net = self::num($doc['netTotal'] ?? null);
        if ($gross !== null && abs((float) $invoice->gross_total - $gross) <= 0.01) {
            $score += 40;
            $reasons[] = 'ίδιο σύνολο';
        } elseif ($net !== null && abs((float) $invoice->net_total - $net) <= 0.01) {
            $score += 25;
            $reasons[] = 'ίδια καθαρή αξία';
        }

        if (OrphanParty::isCounterpart($doc, $invoice->vat_no) || OrphanParty::isCounterpart($doc, $invoice->customer?->afm)) {
            $score += 30;
            $reasons[] = 'ίδιο ΑΦΜ αντισυμβαλλόμενου';
        }

        if ($date !== null && $invoice->issued_at !== null) {
            $days = abs((int) CarbonImmutable::parse($invoice->issued_at)->startOfDay()->diffInDays($date));
            if ($days === 0) {
                $score += 20;
                $reasons[] = 'ίδια ημερομηνία';
            } elseif ($days <= 3) {
                $score += 10;
                $reasons[] = "±{$days} ημ.";
            }
        }

        $type = self::str($doc['invoiceType'] ?? null);
        if ($type !== null && self::str($invoice->invoiceType?->mydata_type) === $type) {
            $score += 10;
            $reasons[] = 'ίδιος τύπος ('.$type.')';
        }

        return [$score, $reasons];
    }

    public static function issueDate(array $doc): ?CarbonImmutable
    {
        $raw = self::str($doc['issueDate'] ?? null);

        try {
            return $raw !== null ? CarbonImmutable::parse($raw)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function str(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? round((float) $v, 2) : null;
    }
}
