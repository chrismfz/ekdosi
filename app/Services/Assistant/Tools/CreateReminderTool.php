<?php

namespace App\Services\Assistant\Tools;

use App\Models\AiPendingAction;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Support\Carbon;

/**
 * PREPARE «άσε μου notification να το θυμηθώ» — a reminder for the operator. A
 * WRITE action: it stages an {@see AiPendingAction} the operator must CONFIRM;
 * only then does it become a live reminder. When `remind_at` falls due,
 * `ai:dispatch-reminders` delivers it as a Filament database notification (the
 * bell). Optionally links a customer so the notification deep-links their
 * Καρτέλα.
 */
class CreateReminderTool implements AssistantTool
{
    public function name(): string
    {
        return 'create_reminder';
    }

    public function description(): string
    {
        return 'ΠΡΟΕΤΟΙΜΑΣΕ (δεν δημιουργεί ακόμη) μια υπενθύμιση/notification για τον χειριστή. '
            .'Για «θύμισέ μου …», «άσε μου notification να το θυμηθώ». Δώσε `note` (τι να θυμηθεί), '
            .'προαιρετικά `remind_at` (πότε, π.χ. «2026-06-20 09:00» ή «αύριο») και `customer` '
            .'(αν αφορά πελάτη). Ενεργοποιείται ΜΟΝΟ μετά από «Επιβεβαίωση» — πες ότι ετοιμάστηκε.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'note' => ['type' => 'string', 'description' => 'Το κείμενο της υπενθύμισης.'],
                'remind_at' => ['type' => 'string', 'description' => 'Πότε (ISO/ημερομηνία-ώρα). Προεπιλογή: αύριο 09:00.'],
                'customer' => ['type' => 'string', 'description' => 'Προαιρετικό — όνομα/ΑΦΜ πελάτη που αφορά.'],
            ],
            'required' => ['note'],
        ];
    }

    public function permission(): ?string
    {
        // A reminder is self-scoped (the operator's own bell) — no extra gate
        // beyond having the assistant; the confirm step still guards it.
        return null;
    }

    public function run(Company $tenant, array $input): array
    {
        $note = trim((string) ($input['note'] ?? ''));
        if ($note === '') {
            return ['error' => 'Δώσε τι να θυμηθώ (note).'];
        }

        $remindAt = $this->parseWhen($input['remind_at'] ?? null);

        $customer = $this->resolveCustomer($tenant, $input['customer'] ?? null);

        $summary = 'Υπενθύμιση: «'.$note.'» στις '.$remindAt->format('d/m/Y H:i')
            .($customer ? ' (πελάτης: '.$customer->name.')' : '');

        $action = AiPendingAction::create([
            'company_id' => $tenant->getKey(),
            'user_id' => auth()->id(),
            'type' => AiPendingAction::TYPE_REMINDER,
            'status' => AiPendingAction::STATUS_PENDING,
            'customer_id' => $customer?->id,
            'summary' => $summary,
            'payload' => ['note' => $note],
            'remind_at' => $remindAt,
        ]);

        return [
            'proposed' => true,
            'action_id' => $action->id,
            'note' => $note,
            'remind_at' => $remindAt->format('Y-m-d H:i'),
            'summary' => $summary,
            'hint' => 'Ετοιμάστηκε. Περιμένει «Επιβεβαίωση» από τον χειριστή — δεν έχει οριστεί ακόμη.',
        ];
    }

    private function parseWhen(mixed $raw): Carbon
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return now()->addDay()->setTime(9, 0);
        }

        try {
            $when = Carbon::parse($raw);
        } catch (\Throwable) {
            return now()->addDay()->setTime(9, 0);
        }

        // A reminder in the past is useless — floor it to a few minutes out so it
        // still fires on the next sweep rather than never.
        return $when->isPast() ? now()->addMinutes(5) : $when;
    }

    private function resolveCustomer(Company $tenant, mixed $raw): ?Customer
    {
        $q = trim((string) $raw);
        if ($q === '') {
            return null;
        }

        // Best-effort link only: a single unambiguous match, else leave unlinked.
        $matches = Customer::query()
            ->where('company_id', $tenant->getKey())
            ->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('afm', 'like', "%{$q}%"))
            ->limit(2)
            ->get(['id', 'name']);

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
