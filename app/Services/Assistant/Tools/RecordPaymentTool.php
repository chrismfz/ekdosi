<?php

namespace App\Services\Assistant\Tools;

use App\Models\AiPendingAction;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Assistant\AiActionExecutor;
use App\Services\Payments\PaymentAllocator;
use Illuminate\Support\Carbon;

/**
 * PREPARE «καταχώρισε είσπραξη Χ€ από τον πελάτη Υ» — a money WRITE action. It only
 * STAGES an {@see AiPendingAction}; the Payment is created ONLY after the operator
 * presses «Επιβεβαίωση» (in {@see AiActionExecutor}, which
 * re-validates and runs {@see PaymentAllocator::allocate}
 * — FIFO onto the customer's open invoices, remainder on-account). The tool never
 * touches money itself. The customer must resolve to exactly ONE match, else it
 * asks the operator to disambiguate rather than guessing.
 */
class RecordPaymentTool implements AssistantTool
{
    public function name(): string
    {
        return 'record_payment';
    }

    public function description(): string
    {
        return 'ΠΡΟΕΤΟΙΜΑΣΕ (δεν καταχωρεί ακόμη) μια είσπραξη από πελάτη. Για «καταχώρισε/πήρα '
            .'είσπραξη 100 από τον Χ», «εισέπραξα …». Δώσε `customer` (όνομα ή ΑΦΜ — πρέπει να '
            .'ταιριάζει σε ΕΝΑΝ πελάτη) και `amount` (θετικό ποσό €)· προαιρετικά `date` (YYYY-MM-DD, '
            .'προεπιλογή σήμερα) και `note`. Η είσπραξη κατανέμεται FIFO στα ανοιχτά τιμολόγια του '
            .'πελάτη, το υπόλοιπο μένει έναντι. Καταχωρείται ΜΟΝΟ μετά από «Επιβεβαίωση» — πες ότι ετοιμάστηκε.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer' => ['type' => 'string', 'description' => 'Όνομα ή ΑΦΜ πελάτη (πρέπει να ταιριάζει μονοσήμαντα).'],
                'amount' => ['type' => 'number', 'description' => 'Ποσό είσπραξης σε € (θετικό).'],
                'date' => ['type' => 'string', 'description' => 'Ημερομηνία είσπραξης (YYYY-MM-DD). Προεπιλογή: σήμερα.'],
                'note' => ['type' => 'string', 'description' => 'Προαιρετική σημείωση.'],
            ],
            'required' => ['customer', 'amount'],
        ];
    }

    public function permission(): ?string
    {
        return 'Create:Payment';
    }

    public function run(Company $tenant, array $input): array
    {
        $amount = round((float) ($input['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return ['error' => 'Το ποσό της είσπραξης πρέπει να είναι θετικό.'];
        }

        [$customer, $error] = $this->resolveCustomer($tenant, $input['customer'] ?? null);
        if ($error !== null) {
            return ['error' => $error];
        }

        $date = $this->parseDate($input['date'] ?? null);
        $note = trim((string) ($input['note'] ?? '')) ?: null;

        $money = number_format($amount, 2, ',', '.');
        $summary = 'Είσπραξη '.$money.'€ από '.$customer->name.' ('.$date->format('d/m/Y').', FIFO σε ανοιχτά τιμολόγια)';

        $action = AiPendingAction::create([
            'company_id' => $tenant->getKey(),
            'user_id' => auth()->id(),
            'type' => AiPendingAction::TYPE_RECORD_PAYMENT,
            'status' => AiPendingAction::STATUS_PENDING,
            'customer_id' => $customer->id,
            'summary' => $summary,
            'payload' => ['amount' => $amount, 'date' => $date->toDateString(), 'note' => $note],
        ]);

        return [
            'proposed' => true,
            'action_id' => $action->id,
            'customer' => $customer->name,
            'amount' => $amount,
            'date' => $date->toDateString(),
            'summary' => $summary,
            'hint' => 'Ετοιμάστηκε. Περιμένει «Επιβεβαίωση» από τον χειριστή — καμία είσπραξη δεν έχει καταχωρηθεί ακόμη.',
        ];
    }

    /**
     * Parse the date UNAMBIGUOUSLY. `Carbon::parse` reads `06/09/2026` as MM/DD
     * (US) — but a Greek operator means 6 Sep — and silently returns today() on an
     * impossible day. So accept only the documented `Y-m-d` and the Greek
     * `d/m/Y`/`d-m-Y`, exact-match; anything else falls back to today (the summary
     * shows the resolved date, so the operator still sees it before confirming).
     */
    private function parseDate(mixed $raw): Carbon
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return now();
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $d = Carbon::createFromFormat('!'.$format, $raw);
            } catch (\Throwable) {
                continue; // not this format (createFromFormat throws on a mismatch)
            }
            if ($d !== false && $d->format($format) === $raw) {
                return $d;
            }
        }

        return now();
    }

    /**
     * Strict resolve: exactly one customer, else an operator-facing error (never
     * a guess — money must land on the right party).
     *
     * @return array{0: ?Customer, 1: ?string}
     */
    private function resolveCustomer(Company $tenant, mixed $raw): array
    {
        $q = trim((string) $raw);
        if ($q === '') {
            return [null, 'Δώσε τον πελάτη (όνομα ή ΑΦΜ).'];
        }

        $matches = Customer::query()
            ->where('company_id', $tenant->getKey())
            ->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('afm', 'like', "%{$q}%"))
            ->limit(6)
            ->get(['id', 'name', 'afm']);

        if ($matches->isEmpty()) {
            return [null, 'Δεν βρέθηκε πελάτης «'.$q.'».'];
        }
        if ($matches->count() > 1) {
            $names = $matches->take(5)->map(fn ($c): string => $c->name.($c->afm ? ' (ΑΦΜ '.$c->afm.')' : ''))->implode(', ');

            return [null, 'Πολλοί πελάτες ταιριάζουν στο «'.$q.'»: '.$names.'. Διευκρίνισε ποιον.'];
        }

        return [$matches->first(), null];
    }
}
