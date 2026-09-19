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
use Illuminate\Support\Facades\Log;

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

    /**
     * vPOS return fields that are NOT part of the digest (mirrors the WHMCS module).
     *
     * Public so the return controller can strip exactly the same noise when it
     * records a field list from a path that never reaches this class — the two
     * lists end up side by side in «Log πύλης», so they must agree.
     */
    public const DIGEST_EXCLUDED = ['_charset_', 'digest', 'submitButton'];

    /**
     * The CANONICAL vPOS return field order — the backbone of return verification.
     *
     * The digest signs a delimiter-less concatenation of the field VALUES, so the
     * field boundaries are NOT signed: only the resulting byte string is. Hashing
     * "whatever arrived, in whatever order" therefore accepts any body that
     * re-partitions the same bytes — including inserting a filler field between
     * `mid` and `orderid` to re-aim a genuine, correctly-signed capture at a
     * different PaymentIntent (one payment, two settlements).
     *
     * So we do NOT hash the received order. We reject unknown fields outright and
     * rebuild the sign-string in THIS fixed order, keeping only the keys actually
     * present.
     *
     * Note what this does and does NOT do. Reordering is *normalised away* — a
     * shuffled body rebuilds to the same string and still verifies (by design; the
     * acquirer's transmission order is not a security property). What closes the
     * re-partition attack is that the attacker can no longer choose where a byte
     * lands: every value is re-anchored to a FIXED position, unknown fields (the
     * classic filler) are refused, and the surrounding slots are pinned — `mid` to
     * the configured terminal, `status` to exactly CAPTURED, `currency` required.
     * `orderid` therefore sits between two fixed anchors and can neither absorb nor
     * donate bytes; `orderAmount` is likewise boxed in between `status` and
     * `currency`.
     *
     * ⚠ UNVALIDATED AGAINST A LIVE RETURN. This list is derived from the vPOS
     * request fields + the documented response shape; no captured production return
     * has been checked against it. If the acquirer posts a field we don't list (an
     * `authCode`, an `eci`/`xid`, an echoed `lang`) or omits one, verification fails
     * and EVERY capture is refused — the bank charges the customer and ekdosi
     * records nothing.
     *
     * It is FAIL-CLOSED and LOUD on purpose: a mismatch logs the posted key order
     * (`eurobank.return.digest_mismatch` / `eurobank.return.unknown_fields`), writes
     * a «Log πύλης» row, and rings the operators' bell (see
     * EurobankReturnController::reject()). Confirm this list against ONE real
     * sandbox capture before going live — the log line hands you the exact order.
     */
    private const RETURN_FIELD_ORDER = [
        'version', 'mid', 'orderid', 'status', 'orderAmount', 'currency',
        'paymentTotal', 'message', 'riskScore', 'payMethod', 'txId', 'paymentRef',
        // Probed by providerTxnId(); allowlisted so a return using this name is
        // tolerated instead of refused as an unknown field.
        'transactionId',
    ];

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
                // Sync + re-validate on blur: these live in a reactively-revealed
                // section, and a plain deferred field would keep showing a stale
                // «required» from an earlier empty submit even after being filled
                // (and could miss the submit). onBlur clears it as the operator types.
                ->live(onBlur: true)
                ->helperText('Ο κωδικός εμπόρου (mid) από τη Eurobank / Cardlink.'),
            TextInput::make('shared_secret')
                ->label('Shared Secret')
                ->password()
                ->revealable()
                ->live(onBlur: true)
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
            // Trimmed here as well as at the return comparison: an operator-typed
            // stray space would otherwise go out on the wire, come back intact, and
            // lose against the trimmed configured value on every capture.
            'mid' => trim((string) ($config['merchant_id'] ?? '')),
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

        // Parse the RAW body ourselves — $request->all() gives no order guarantee and
        // we need to see exactly which keys were posted.
        $fields = [];
        parse_str($request->getContent(), $fields);

        // Record the acquirer's actual field NAMES + order on EVERY return, success
        // or failure. RETURN_FIELD_ORDER is derived from documentation, not from a
        // captured production return, and this is the line that settles the question
        // from the first real transaction instead of only when something breaks.
        // Keys only — never the values, which carry the customer's order data.
        // Computed ONCE, before anything reads it — every branch below reports it.
        $posted = array_diff_key($fields, array_flip(self::DIGEST_EXCLUDED));
        $postedOrder = self::clampFieldNames(array_keys($posted));

        Log::info('eurobank.return.fields', ['posted_order' => $postedOrder]);

        $sent = (string) ($fields['digest'] ?? '');
        if ($sent === '') {
            return PaymentOutcome::unverified('no digest on the return', [
                'code' => 'no_digest',
                'posted_order' => $postedOrder,
                'expected_order' => self::RETURN_FIELD_ORDER,
            ]);
        }

        // Any key we don't know is refused before hashing: an unrecognised field is
        // how a replayer smuggles the bytes that let the same digest describe a
        // different orderid. (Refusing is also the honest response to genuine
        // protocol drift — see RETURN_FIELD_ORDER.)
        $unknown = self::clampFieldNames(array_values(array_diff(array_keys($posted), self::RETURN_FIELD_ORDER)));
        if ($unknown !== []) {
            Log::warning('eurobank.return.unknown_fields', [
                'unknown' => $unknown,
                'posted_order' => $postedOrder,
            ]);

            return PaymentOutcome::unverified('unexpected field(s) on the return: '.implode(', ', $unknown), [
                'code' => 'unknown_fields',
                'posted_order' => $postedOrder,
                'expected_order' => self::RETURN_FIELD_ORDER,
                'unknown_fields' => $unknown,
            ]);
        }

        // Rebuild the sign-string in CANONICAL order (not the posted order), keeping
        // only the keys present, then compare in constant time (T1).
        $signString = '';
        foreach (self::RETURN_FIELD_ORDER as $fieldName) {
            if (! array_key_exists($fieldName, $posted)) {
                continue;
            }
            $value = $posted[$fieldName];
            $signString .= is_scalar($value) ? (string) $value : '';
        }
        $computed = $this->returnDigest($signString, $secret);

        $verified = hash_equals($computed, $sent);

        if (! $verified) {
            // A bare mismatch is ambiguous: it means EITHER our canonical order is
            // wrong OR the shared secret is. Disambiguate it here, because that is
            // the whole question a sandbox validation run needs answered.
            //
            // Recompute the digest the OLD way — values in the order the acquirer
            // posted them. If THAT matches, the secret is right and only our
            // ordering is wrong, and `posted_order` is literally the list to paste
            // into RETURN_FIELD_ORDER. If it doesn't match either, look at the
            // secret. Diagnostic only: this value never authorises anything.
            $receivedOrder = '';
            foreach ($posted as $value) {
                $receivedOrder .= is_scalar($value) ? (string) $value : '';
            }
            $receivedMatches = hash_equals($this->returnDigest($receivedOrder, $secret), $sent);

            $diagnostics = [
                'code' => $receivedMatches ? 'order_mismatch' : 'secret_or_payload_mismatch',
                'posted_order' => $postedOrder,
                'expected_order' => self::RETURN_FIELD_ORDER,
                'received_order_matches' => $receivedMatches,
            ];

            Log::warning('eurobank.return.digest_mismatch', $diagnostics);
        }

        return new PaymentOutcome(
            verified: $verified,
            status: $this->normaliseStatus((string) ($fields['status'] ?? '')),
            reference: isset($fields['orderid']) ? (string) $fields['orderid'] : null,
            amount: isset($fields['orderAmount']) ? (float) $fields['orderAmount'] : null,
            currency: isset($fields['currency']) ? (string) $fields['currency'] : null,
            providerTxnId: $this->providerTxnId($fields),
            message: isset($fields['message']) ? (string) $fields['message'] : null,
            // Reported so the controller can pin it to the connection's configured
            // terminal. The digest concatenates values with NO delimiters, so an
            // unchecked `mid` lets a replayer shift the mid|orderid boundary and
            // re-aim a genuine, correctly-signed return at another intent.
            merchantId: isset($fields['mid']) ? (string) $fields['mid'] : null,
            // On success this still records what the acquirer actually sent, so the
            // «Log πύλης» row doubles as the evidence that RETURN_FIELD_ORDER is
            // correct — the sandbox validation gate, readable from the panel.
            diagnostics: $diagnostics ?? [
                'code' => 'ok',
                'posted_order' => $postedOrder,
                'expected_order' => self::RETURN_FIELD_ORDER,
                // The acquirer's own reference, kept for reconciliation when it is
                // the only identifier a return carries. It is a bank-side code, not
                // customer data — the one VALUE this structure holds, deliberately.
                'provider_reference' => isset($fields['paymentRef']) ? (string) $fields['paymentRef'] : null,
            ],
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
    /**
     * The acquirer's TRANSACTION id — the value the settle dedup keys on, so it must
     * be a per-transaction identifier and nothing else.
     *
     * `paymentRef` is deliberately NOT consulted: it is a payment/approval reference
     * with no documented per-transaction uniqueness, and feeding it to the dedup
     * would let it collide with an earlier genuine payment and REFUSE a second,
     * perfectly legitimate capture (money taken, nothing recorded). It is instead
     * kept verbatim on the event's `diagnostics.provider_reference`, so a return
     * that carries only a `paymentRef` is still reconcilable against the bank.
     */
    /**
     * Bound a field-name list before it is logged or persisted. The return endpoint
     * needs no credential, so both the names and how many there are are chosen by
     * whoever posts — and they land in a JSON column and the log.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function clampFieldNames(array $names): array
    {
        return array_map(
            static fn (string $n): string => mb_substr($n, 0, 40),
            array_slice($names, 0, 40),
        );
    }

    private function providerTxnId(array $fields): ?string
    {
        foreach (['txId', 'transactionId'] as $k) {
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
