<?php

namespace App\Services\EInvoice\Transports;

use App\Contracts\EInvoiceProviderTransport;
use App\Models\Invoice;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderResult;
use RuntimeException;

/**
 * The safe "no provider wired" default returned by ProviderTransportRegistry for
 * an unknown/empty/unconfigured key. Unlike the no-op NullProvisioningModule, a
 * submit transport must NEVER fake a success (that would silently not-file a legal
 * document), so send/cancel/status throw loudly and ping() reports unreachable.
 *
 * P1 ships ONLY this transport — no real provider until P5 (InvoSign / SBZ), which
 * land as their own classes registered in config/ekdosi.php → einvoice.providers.
 */
class NullProviderTransport implements EInvoiceProviderTransport
{
    public function key(): string
    {
        return 'none';
    }

    public function send(Invoice $invoice, string $documentXml, ProviderCredentials $credentials): ProviderResult
    {
        throw $this->notConfigured();
    }

    public function cancel(string $mark, ProviderCredentials $credentials, string $reason = ''): ProviderResult
    {
        throw $this->notConfigured();
    }

    public function status(Invoice $invoice, ProviderCredentials $credentials): ProviderResult
    {
        throw $this->notConfigured();
    }

    public function ping(ProviderCredentials $credentials): bool
    {
        return false;
    }

    private function notConfigured(): RuntimeException
    {
        return new RuntimeException(
            'No e-invoice provider transport is configured for this tenant. '
            .'Set companies.einvoice_provider_key to a provider registered in '
            .'config/ekdosi.php → einvoice.providers.'
        );
    }
}
