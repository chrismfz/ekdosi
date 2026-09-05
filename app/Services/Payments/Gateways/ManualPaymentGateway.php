<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\BankAccount;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Models\Scopes\CompanyScope;
use App\Support\Payments\ConnectionTestResult;
use App\Support\Payments\PaymentGatewayCapabilities;
use App\Support\Payments\PaymentInitiation;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manual / offline payment (τραπεζική κατάθεση / έμβασμα) — the B0 gateway with
 * NO external API. It SURFACES the tenant's existing `bank_accounts` (already in
 * ekdosi, printed on invoices) rather than re-typing IBANs: the operator just
 * toggles WHICH accounts to show (empty = all active). The customer sees them +
 * a reference; the operator confirms the deposit, which writes the existing
 * manual `Payment`. No webhook (settlement is operator-driven).
 */
class ManualPaymentGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'manual';
    }

    public function displayName(): string
    {
        return 'Τραπεζική κατάθεση';
    }

    public function capabilities(): PaymentGatewayCapabilities
    {
        return new PaymentGatewayCapabilities(flow: 'offline', webhook: false, refund: false, prepaid: true);
    }

    public function configFields(): array
    {
        return [
            CheckboxList::make('bank_account_ids')
                ->label('Λογαριασμοί προς εμφάνιση')
                ->options(fn (): array => $this->tenantAccounts()
                    ->mapWithKeys(fn (BankAccount $a): array => [$a->id => $this->accountLabel($a)])
                    ->all())
                ->helperText('Ποιοι από τους τραπεζικούς λογαριασμούς σου εμφανίζονται στον πελάτη. Άφησέ το κενό για ΟΛΟΥΣ τους ενεργούς. (Διαχείριση: «Τραπεζικοί λογαριασμοί».)')
                ->columns(1),
            Textarea::make('instructions')
                ->label('Οδηγίες προς τον πελάτη')
                ->rows(3)
                ->helperText('π.χ. «Στην αιτιολογία γράψτε τον κωδικό αναφοράς».'),
        ];
    }

    public function initiate(PaymentIntent $intent, PaymentGatewayConnection $connection): PaymentInitiation
    {
        $config = $connection->config ?? [];
        $ids = array_values(array_filter((array) ($config['bank_account_ids'] ?? [])));

        $query = BankAccount::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $connection->company_id)
            ->where('is_active', true);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $bankDetails = $query->orderBy('bank_name')->get()
            ->map(fn (BankAccount $a): string => $this->accountLine($a))
            ->implode("\n");

        return PaymentInitiation::offline(
            bankDetails: $bankDetails !== '' ? $bankDetails : null,
            instructions: $config['instructions'] ?? null,
        );
    }

    public function testConnection(PaymentGatewayConnection $connection): ConnectionTestResult
    {
        // Nothing to reach — an offline method is always «connected».
        return ConnectionTestResult::ok('Χειροκίνητος τρόπος — δεν απαιτείται σύνδεση.');
    }

    /** The active bank accounts of the Filament-active tenant (for the config picker). */
    private function tenantAccounts(): Collection
    {
        $companyId = Filament::getTenant()?->getKey();
        if ($companyId === null) {
            return new Collection;
        }

        return BankAccount::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('bank_name')
            ->get();
    }

    private function accountLabel(BankAccount $a): string
    {
        return trim(($a->bank_name ?: 'Τράπεζα').' · '.($a->iban ?: '—'));
    }

    /** One display line for the customer: bank · IBAN · δικαιούχος. */
    private function accountLine(BankAccount $a): string
    {
        $parts = array_filter([$a->bank_name, $a->iban, $a->account_name]);

        return implode(' · ', $parts);
    }
}
