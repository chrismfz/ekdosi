<?php

namespace App\Services\Assistant\Tools;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\AiPendingAction;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Assistant\AiActionExecutor;

/**
 * PREPARE «στείλε ενημερωτικό/καρτέλα» — emailing the customer's statement
 * (Καρτέλα PDF). A WRITE action: it does NOT send. It resolves the customer +
 * their on-file recipients (customer email + contacts, exactly like the manual
 * Καρτέλα «Αποστολή στο email»), stages an {@see AiPendingAction} the operator
 * must CONFIRM, and returns the proposal. Sending happens only on confirm, via
 * {@see AiActionExecutor}.
 */
class SendCustomerStatementTool implements AssistantTool
{
    public function name(): string
    {
        return 'send_customer_statement';
    }

    public function description(): string
    {
        return 'ΠΡΟΕΤΟΙΜΑΣΕ (δεν στέλνει) αποστολή της καρτέλας/ενημερωτικού ενός πελάτη στο email '
            .'του (και στις επαφές του). Για «στείλε ενημερωτικό/καρτέλα στον Χ». Δίνε όνομα ή ΑΦΜ '
            .'στο `customer`. Η αποστολή γίνεται ΜΟΝΟ αφού ο χειριστής πατήσει «Επιβεβαίωση» — '
            .'μην πεις ότι στάλθηκε, πες ότι ετοιμάστηκε και περιμένει επιβεβαίωση.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer' => ['type' => 'string', 'description' => 'Όνομα (μέρος) ή ΑΦΜ του πελάτη.'],
            ],
            'required' => ['customer'],
        ];
    }

    public function permission(): ?string
    {
        // Mirrors the manual Καρτέλα send, which rides on Customer view rights;
        // the operator-confirm step is the real safety gate for the outward email.
        return 'View:Customer';
    }

    public function run(Company $tenant, array $input): array
    {
        $q = trim((string) ($input['customer'] ?? ''));
        if ($q === '') {
            return ['error' => 'Δώσε όνομα ή ΑΦΜ πελάτη.'];
        }

        $matches = Customer::query()
            ->where('company_id', $tenant->getKey())
            ->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('afm', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'afm', 'email']);

        if ($matches->isEmpty()) {
            return ['error' => "Δεν βρέθηκε πελάτης για «{$q}»."];
        }

        if ($matches->count() > 1) {
            // Don't guess which one — ask the model to disambiguate.
            return [
                'ambiguous' => true,
                'note' => 'Βρέθηκαν πολλοί πελάτες — ζήτησε διευκρίνιση (π.χ. ΑΦΜ).',
                'candidates' => $matches->map(fn (Customer $c): array => [
                    'name' => $c->name, 'afm' => $c->afm,
                ])->all(),
            ];
        }

        /** @var Customer $customer */
        $customer = $matches->first();
        $customer->load('contacts');
        $recipients = $this->recipientsFor($customer);

        if ($recipients === []) {
            return [
                'error' => 'Ο πελάτης δεν έχει email ούτε επαφές με email.',
                'kartela_url' => CustomerResource::getUrl('ledger', ['record' => $customer->id, 'tenant' => $tenant]),
                'note' => 'Πρόσθεσε email στην καρτέλα του και ξαναπροσπάθησε.',
            ];
        }

        $summary = 'Αποστολή καρτέλας «'.$customer->name.'» στο '.implode(', ', $recipients);

        $action = AiPendingAction::create([
            'company_id' => $tenant->getKey(),
            'user_id' => auth()->id(),
            'type' => AiPendingAction::TYPE_SEND_STATEMENT,
            'status' => AiPendingAction::STATUS_PENDING,
            'customer_id' => $customer->id,
            'summary' => $summary,
            'payload' => ['recipients' => $recipients],
        ]);

        return [
            'proposed' => true,
            'action_id' => $action->id,
            'customer' => $customer->name,
            'recipients' => $recipients,
            'summary' => $summary,
            'note' => 'Ετοιμάστηκε. Περιμένει «Επιβεβαίωση» από τον χειριστή — δεν έχει σταλεί ακόμη.',
        ];
    }

    /**
     * The customer's email + every contact email (deduped), mirroring the
     * manual Καρτέλα recipient list.
     *
     * @return list<string>
     */
    private function recipientsFor(Customer $customer): array
    {
        $out = [];

        $email = trim((string) $customer->email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[mb_strtolower($email)] = $email;
        }

        foreach ($customer->contacts as $contact) {
            $cEmail = trim((string) $contact->email);
            if ($cEmail !== '' && filter_var($cEmail, FILTER_VALIDATE_EMAIL)) {
                $out[mb_strtolower($cEmail)] ??= $cEmail;
            }
        }

        return array_values($out);
    }
}
