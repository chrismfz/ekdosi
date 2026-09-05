<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\PaymentGatewayConnection;
use App\Support\Payments\ConnectionTestResult;
use App\Support\Payments\PaymentGatewayCapabilities;
use Filament\Forms\Components\Textarea;

/**
 * Manual / offline payment (bank deposit) — the B0 gateway with NO external API:
 * the customer is shown bank details + instructions, pays out-of-band, and the
 * operator confirms the deposit (which writes the existing manual `Payment`).
 * Because there is no online charge there is no webhook — settlement is
 * operator-driven (capabilities.webhook = false). A recorded deposit can fund
 * on-account credit (prepaid = true).
 */
class ManualPaymentGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'manual';
    }

    public function displayName(): string
    {
        return 'Κατάθεση σε τραπεζικό λογαριασμό';
    }

    public function capabilities(): PaymentGatewayCapabilities
    {
        return new PaymentGatewayCapabilities(flow: 'offline', webhook: false, refund: false, prepaid: true);
    }

    public function configFields(): array
    {
        return [
            Textarea::make('bank_details')
                ->label('Τραπεζικοί λογαριασμοί')
                ->rows(4)
                ->helperText('Εμφανίζονται στον πελάτη (τράπεζα · IBAN · δικαιούχος). Μία γραμμή ανά λογαριασμό.'),
            Textarea::make('instructions')
                ->label('Οδηγίες προς τον πελάτη')
                ->rows(3)
                ->helperText('π.χ. «Στην αιτιολογία γράψτε τον αριθμό παραστατικού».'),
        ];
    }

    public function testConnection(PaymentGatewayConnection $connection): ConnectionTestResult
    {
        // Nothing to reach — an offline method is always «connected».
        return ConnectionTestResult::ok('Χειροκίνητος τρόπος — δεν απαιτείται σύνδεση.');
    }
}
