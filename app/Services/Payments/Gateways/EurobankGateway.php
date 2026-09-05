<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\HasSecretConfig;
use App\Contracts\HostedRedirectGateway;
use App\Contracts\PaymentGateway;
use App\Contracts\WebhookGateway;
use App\Models\Customer;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Models\Scopes\CompanyScope;
use App\Support\Payments\ConnectionTestResult;
use App\Support\Payments\HostedRedirectForm;
use App\Support\Payments\PaymentGatewayCapabilities;
use App\Support\Payments\PaymentInitiation;
use App\Support\Payments\PaymentOutcome;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Http\Request;

/**
 * Eurobank / Cardlink-Modirum vPOS (the tenant's primary provider) — card +
 * Apple/Google Pay + IRIS, all through one hosted redirect page (`flow=redirect`,
 * SAQ-A: card data never touches ekdosi). Ported field-for-field from the tenant's
 * open-source WHMCS module (`eurobanklib.php` + `eurobankreturn.php`).
 *
 * Two halves:
 *  - {@see redirectForm()} builds the SIGNED self-submitting POST that sends the
 *    customer to the vPOS hosted page (HostedRedirectGateway).
 *  - {@see handleWebhook()} verifies the vPOS return digest over the raw body and
 *    reports a normalised outcome (WebhookGateway). Settlement itself is the
 *    generic, idempotent PaymentIntentService::settle() — this class only proves
 *    the message is genuine and captured.
 *
 * The digest on both legs is `base64(sha256(concat-of-field-values . sharedSecret))`;
 * the shared secret lives ENCRYPTED in the connection config and never leaves the
 * server (only the resulting digest goes to the browser, which is normal).
 */
class EurobankGateway implements HasSecretConfig, HostedRedirectGateway, PaymentGateway, WebhookGateway
{
    /** Live acquirer endpoint. */
    private const ENDPOINT_LIVE = 'https://vpos.eurocommerce.gr/vpos/shophandlermpi';

    /** Sandbox acquirer endpoint. */
    private const ENDPOINT_TEST = 'https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi';

    /** vPOS return fields that are NOT part of the digest (mirrors the WHMCS module). */
    private const DIGEST_EXCLUDED = ['_charset_', 'digest', 'submitButton'];

    public function key(): string
    {
        return 'eurobank';
    }

    public function secretConfigKeys(): array
    {
        return ['shared_secret'];
    }

    public function displayName(): string
    {
        return 'Κάρτα / Apple-Google Pay / IRIS (Eurobank)';
    }

    public function capabilities(): PaymentGatewayCapabilities
    {
        return new PaymentGatewayCapabilities(
            flow: 'redirect',
            webhook: true,
            refund: false,          // collect-only in B1 (refunds are operator-side, later)
            prepaid: true,          // can fund on-account credit, not just one invoice
            currencies: ['EUR'],
        );
    }

    public function configFields(): array
    {
        return [
            TextInput::make('merchant_id')
                ->label('Merchant ID (mid)')
                ->required()
                ->helperText('Ο κωδικός εμπόρου (mid) από τη Eurobank / Cardlink.'),
            TextInput::make('shared_secret')
                ->label('Shared Secret')
                ->password()
                ->revealable()
                // Required on CREATE (an active method with no secret can never
                // verify a return — both legs fail closed). On EDIT it may be left
                // blank = «keep the stored value»: the write-only «never hydrate,
                // restore-blank-on-save» policy lives in one place, the resource's
                // EditPage mutate hooks (see HasSecretConfig).
                ->required(fn (string $operation): bool => $operation === 'create')
                ->helperText('Το «Shared Secret» του τερματικού. Αποθηκεύεται κρυπτογραφημένο· δεν εμφανίζεται ξανά. Στην επεξεργασία, άφησέ το κενό για να μείνει ως έχει.'),
            Select::make('lang')
                ->label('Γλώσσα σελίδας πληρωμής')
                ->options(['el' => 'Ελληνικά', 'en' => 'English'])
                ->default('el')
                ->native(false),
            Toggle::make('testmode')
                ->label('Δοκιμαστικό περιβάλλον (sandbox)')
                ->helperText('Ενεργό = χτυπάει το test endpoint της Cardlink. Απενεργοποίησέ το στην παραγωγή.')
                ->default(true),
        ];
    }

    /**
     * Send the customer to our own redirect page, which rebuilds + auto-submits the
     * signed vPOS form ({@see redirectForm()}). We DON'T build the form here so the
     * signed payload is never persisted and stays stable across a browser refresh.
     */
    public function initiate(PaymentIntent $intent, PaymentGatewayConnection $connection): PaymentInitiation
    {
        return new PaymentInitiation(
            flow: 'redirect',
            redirectUrl: route('portal.payment.redirect', $intent->id),
        );
    }

    public function redirectForm(PaymentIntent $intent, PaymentGatewayConnection $connection): HostedRedirectForm
    {
        $config = $connection->config ?? [];
        $customer = $this->customerFor($intent);

        // Field ORDER is load-bearing: the acquirer recomputes the request digest by
        // concatenating these values in exactly this order (matches the proven WHMCS
        // module). orderid = the PaymentIntent id (ASCII, stable, unique) — the
        // return maps it straight back; orderDesc stays ASCII to avoid any
        // hosted-page encoding ambiguity (the human ΠΛ-reference lives on our side).
        $fields = [
            'version' => '2',
            'mid' => (string) ($config['merchant_id'] ?? ''),
            'lang' => (string) ($config['lang'] ?? 'el'),
            'deviceCategory' => '0',
            'orderid' => (string) $intent->id,
            'orderDesc' => 'Ekdosi #'.$intent->id,
            'orderAmount' => number_format((float) $intent->amount, 2, '.', ''),
            'currency' => 'EUR',
            'payerEmail' => (string) ($customer?->email ?? ''),
            'billCountry' => (string) ($customer?->country_code ?? ''),
            'billZip' => (string) ($customer?->postcode ?? ''),
            'billCity' => (string) ($customer?->city ?? ''),
            'billAddress' => (string) ($customer?->address1 ?? ''),
            'confirmUrl' => $this->returnUrl('success'),
            'cancelUrl' => $this->returnUrl('failure'),
        ];

        $secret = (string) ($config['shared_secret'] ?? '');
        $fields['digest'] = $this->requestDigest(implode('', array_values($fields)), $secret);

        return new HostedRedirectForm(
            action: $this->endpoint($connection),
            fields: $fields,
        );
    }

    public function handleWebhook(Request $request, PaymentGatewayConnection $connection): PaymentOutcome
    {
        $secret = (string) (($connection->config ?? [])['shared_secret'] ?? '');
        if ($secret === '') {
            return PaymentOutcome::unverified('no shared secret configured');
        }

        // Parse the RAW body ourselves to preserve field ORDER — the digest is a
        // positional concatenation, and $request->all() gives no order guarantee.
        $fields = [];
        parse_str($request->getContent(), $fields);

        $sent = (string) ($fields['digest'] ?? '');
        if ($sent === '') {
            return PaymentOutcome::unverified('no digest on the return');
        }

        // Concatenate every returned value in received order EXCEPT the excluded
        // keys, append the shared secret, and compare in constant time (T1).
        $signString = '';
        foreach ($fields as $fieldName => $value) {
            if (in_array($fieldName, self::DIGEST_EXCLUDED, true)) {
                continue;
            }
            $signString .= is_scalar($value) ? (string) $value : '';
        }
        $computed = $this->returnDigest($signString, $secret);

        $verified = hash_equals($computed, $sent);

        return new PaymentOutcome(
            verified: $verified,
            status: $this->normaliseStatus((string) ($fields['status'] ?? '')),
            reference: isset($fields['orderid']) ? (string) $fields['orderid'] : null,
            amount: isset($fields['orderAmount']) ? (float) $fields['orderAmount'] : null,
            currency: isset($fields['currency']) ? (string) $fields['currency'] : null,
            providerTxnId: $this->providerTxnId($fields),
            message: isset($fields['message']) ? (string) $fields['message'] : null,
        );
    }

    public function testConnection(PaymentGatewayConnection $connection): ConnectionTestResult
    {
        $config = $connection->config ?? [];
        if (blank($config['merchant_id'] ?? null)) {
            return ConnectionTestResult::fail('Λείπει το Merchant ID (mid).');
        }
        if (blank($config['shared_secret'] ?? null)) {
            return ConnectionTestResult::fail('Λείπει το Shared Secret.');
        }

        // vPOS has no ping — a genuine check needs a real transaction. Confirm the
        // creds are present + which endpoint we'd hit.
        $env = ($config['testmode'] ?? true) ? 'δοκιμαστικό (sandbox)' : 'παραγωγής';

        return ConnectionTestResult::ok("Ρυθμισμένο. Οι πληρωμές θα σταλούν στο περιβάλλον {$env}.");
    }

    /** vPOS status → our normalised outcome status. Only CAPTURED writes money. */
    private function normaliseStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'CAPTURED' => PaymentOutcome::STATUS_SETTLED,
            'AUTHORIZED' => PaymentOutcome::STATUS_PENDING,   // auth-only, not captured
            'CANCELED', 'CANCELLED' => PaymentOutcome::STATUS_CANCELLED,
            'REFUSED', 'ERROR' => PaymentOutcome::STATUS_FAILED,
            default => PaymentOutcome::STATUS_UNKNOWN,
        };
    }

    /** The acquirer's transaction id, for the money trail (several field names seen). */
    private function providerTxnId(array $fields): ?string
    {
        foreach (['txId', 'paymentRef', 'transactionId'] as $k) {
            if (filled($fields[$k] ?? null)) {
                return (string) $fields[$k];
            }
        }

        return null;
    }

    /**
     * OUTBOUND (request) digest — eurobanklib.php: iconv//IGNORE over the FIELDS,
     * THEN append the secret (secret stays outside the transliteration). A no-op
     * for our valid-UTF-8 fields, but kept faithful to the acquirer-validated module.
     */
    private function requestDigest(string $input, string $secret): string
    {
        $normalised = iconv('utf-8', 'utf-8//IGNORE', $input);
        if ($normalised === false) {
            $normalised = $input;
        }

        return base64_encode(hash('sha256', $normalised.$secret, true));
    }

    /**
     * INBOUND (return) digest — eurobankreturn.php hashes the RAW concatenation of
     * the returned values + secret with NO iconv. We must hash exactly the bytes
     * vPOS hashed, so a legitimate return with an unusual byte in (say) `message`
     * never mis-verifies and strands a real payment.
     */
    private function returnDigest(string $input, string $secret): string
    {
        return base64_encode(hash('sha256', $input.$secret, true));
    }

    private function endpoint(PaymentGatewayConnection $connection): string
    {
        $test = (bool) (($connection->config ?? [])['testmode'] ?? true);

        return $test ? self::ENDPOINT_TEST : self::ENDPOINT_LIVE;
    }

    private function returnUrl(string $result): string
    {
        return route('webhooks.payments.eurobank.return').'?result='.$result;
    }

    private function customerFor(PaymentIntent $intent): ?Customer
    {
        return Customer::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereKey($intent->customer_id)
            ->first();
    }
}
