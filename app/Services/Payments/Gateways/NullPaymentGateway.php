<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Support\Payments\ConnectionTestResult;
use App\Support\Payments\PaymentGatewayCapabilities;
use App\Support\Payments\PaymentInitiation;
use RuntimeException;

/**
 * The always-present no-op gateway for an unknown/empty/unconfigured key. It can
 * never charge (flow='none'), so the registry can hand callers a safe object
 * instead of null and a misconfigured tenant fails loudly at charge-time rather
 * than silently. Mirrors NullProviderTransport.
 */
class NullPaymentGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'none';
    }

    public function displayName(): string
    {
        return '—';
    }

    public function capabilities(): PaymentGatewayCapabilities
    {
        return new PaymentGatewayCapabilities(flow: 'none', webhook: false, refund: false, prepaid: false);
    }

    public function configFields(): array
    {
        return [];
    }

    public function initiate(PaymentIntent $intent, PaymentGatewayConnection $connection): PaymentInitiation
    {
        // Fail loudly — an unknown/unconfigured gateway must never silently "start" a charge.
        throw new RuntimeException('Αυτός ο τρόπος πληρωμής δεν είναι διαθέσιμος.');
    }

    public function testConnection(PaymentGatewayConnection $connection): ConnectionTestResult
    {
        return ConnectionTestResult::fail('Άγνωστος ή μη ρυθμισμένος τρόπος πληρωμής.');
    }
}
