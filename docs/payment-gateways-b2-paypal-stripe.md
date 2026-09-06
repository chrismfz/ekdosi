# B2 gateways — PayPal & Stripe (research notes, fill in as we go)

> **Status: research/reference only — NOT implemented.** A place to collect the
> API facts so a future B2 adapter is «one class + one config line», exactly like
> the Eurobank/vPOS one. Verify every field against the live docs + a sandbox run
> before writing code (payment APIs drift). Grounded from the official docs on
> 2026-09 — links at the bottom.

## How it plugs into ekdosi (the seam we already have)

A new gateway = **one class** implementing the existing contracts + **one line** in
`config/ekdosi.php → payments.gateways`. Reuse everything else:

- `App\Contracts\PaymentGateway` — `key()`, `displayName()`, `capabilities()`, `configFields()`, `initiate()`.
- `App\Contracts\HostedRedirectGateway` — `redirectForm()` / a redirect URL to the provider's hosted page.
- `App\Contracts\WebhookGateway` — `handleWebhook(Request, connection): PaymentOutcome`.
- `App\Contracts\HasSecretConfig` — `secretConfigKeys()` (write-only, encrypted in `config`).
- `App\Services\Payments\PaymentIntentService::settle()` — **idempotent** money write (pending/expired → settled), stamps `payment_intent_id`, the channel's myDATA method, txn id.
- `App\Models\PaymentGatewayEvent` («Log πύλης») — record every inbound notification (settled/ignored/rejected + reason). Wire it in the return/webhook controller like `EurobankReturnController`.

**Key difference from Eurobank (and an improvement):** vPOS confirms via the
**browser return** (fragile — customer closes the tab → stuck, the exact bug we hit).
PayPal & Stripe both send a **server-to-server webhook**. So for B2, drive
`settle()` from the **webhook** (`WebhookGateway`), and make the browser
`return_url`/`success_url` **display-only** (reads the intent state, never settles).
That removes the abandoned-tab class of stuck payments.

Mapping of our identifiers:
- `PaymentIntent.id` → the provider's order/session reference (Eurobank orderid = intent id; keep the same idea).
- `PaymentIntent.reference` (`ΠΛ-…`) → our receipt key on the resulting `Payment` rows.
- provider txn id → `Payment.transaction_id` + `PaymentGatewayEvent.transaction_id`.

---

## α) PayPal — simplest ONE-TIME payment (Orders v2, redirect). No recurring, no vault.

Flow: **create order (server) → redirect buyer to PayPal → capture (server) + webhook**.

**Base URLs**
- Sandbox: `https://api-m.sandbox.paypal.com`
- Live: `https://api-m.paypal.com`

**1. OAuth2 token** — `POST /v1/oauth2/token`
- Header `Authorization: Basic base64(CLIENT_ID:CLIENT_SECRET)`, body `grant_type=client_credentials`.
- Returns `access_token` (cache it ~9h; `expires_in` given). All calls below: `Authorization: Bearer {access_token}`.

**2. Create order** — `POST /v2/checkout/orders`
```json
{
  "intent": "CAPTURE",
  "purchase_units": [{
    "amount": { "currency_code": "EUR", "value": "1.00" },
    "custom_id": "<PaymentIntent.id>",          // our reconciliation key
    "invoice_id": "<PaymentIntent.reference>"    // optional, shows on PayPal side
  }],
  "payment_source": {
    "paypal": {
      "experience_context": {
        "user_action": "PAY_NOW",
        "return_url": "https://…/webhooks/payments/paypal/return?intent=<id>",
        "cancel_url": "https://…/user/payment/<id>"
      }
    }
  }
}
```
- Response has `id` (order id) + HATEOAS `links[]`. Redirect the buyer to the link with `rel="payer-action"` (older: `rel="approve"`).
- `experience_context` is where `return_url`/`cancel_url`/`user_action` live in v2 (the legacy `application_context` still works but is deprecated).

**3. Buyer approves → PayPal redirects to `return_url`.** That page is display-only.

**4. Capture** — `POST /v2/checkout/orders/{orderId}/capture` (empty body, `Bearer` token).
- On `status: COMPLETED` → the money is captured; the capture id (`purchase_units[].payments.captures[].id`) is the txn id.
- **Idempotency:** send header `PayPal-Request-Id: <PaymentIntent.id>` on create AND capture so a retried capture is a no-op.

**5. Webhook (the authoritative confirmation)** — configure a webhook in the PayPal app; subscribe to:
- `PAYMENT.CAPTURE.COMPLETED` — the one that settles money.
- (`CHECKOUT.ORDER.APPROVED` — buyer approved but not yet captured; usually we capture on return, so this is informational.)
- **Verify signature:** `POST /v1/notifications/verify-webhook-signature` with the incoming headers
  `transmission_id` (`PAYPAL-TRANSMISSION-ID`), `transmission_time` (`PAYPAL-TRANSMISSION-TIME`),
  `cert_url` (`PAYPAL-CERT-URL`), `auth_algo` (`PAYPAL-AUTH-ALGO`), `transmission_sig` (`PAYPAL-TRANSMISSION-SIG`),
  your configured `webhook_id`, and `webhook_event` = **the raw body posted back byte-for-byte**
  (re-serializing JSON breaks it). Success = `{ "verification_status": "SUCCESS" }`.

**`configFields()` for the adapter:** `client_id`, `client_secret` (secret, write-only), `webhook_id`, `testmode` (sandbox toggle). `secretConfigKeys() = ['client_secret']`.

---

## β) Stripe — which mode fits us

Stripe has several UIs; for our hosted-redirect model the clear fit is **Checkout Session (Stripe-hosted page)** — the same shape as Eurobank/vPOS (redirect → hosted page → webhook). Not Payment Intents + Elements (embedded, much more client code), not Payment Links (dashboard-created, not per-invoice).

> We use **cards with local vault in WHMCS**. For the ekdosi portal's simplest
> one-time flow, **cards via Checkout is enough — no vaulting.** Saving cards
> (`setup_future_usage` / `saved_payment_method_options`) is a *separate later*
> feature and pulls in `Customer` objects + consent/PII compliance — keep it out
> of the first adapter. SEPA is a one-line add (`payment_method_types[]=sepa_debit`)
> but note it's **delayed-notification** (settles minutes later via webhook, not on
> return) — our webhook-driven settle already handles that correctly.

Flow: **create Checkout Session (server) → redirect to `session.url` → webhook `checkout.session.completed`**.

**Auth:** `Authorization: Bearer sk_live_…` (secret key). Base `https://api.stripe.com`.

**1. Create session** — `POST /v1/checkout/sessions` (form-encoded)
```
mode=payment
line_items[0][price_data][currency]=eur
line_items[0][price_data][product_data][name]=Πληρωμή ΠΛ-…
line_items[0][price_data][unit_amount]=100          # cents!
line_items[0][quantity]=1
success_url=https://…/user/payment/<intent>?session_id={CHECKOUT_SESSION_ID}
cancel_url=https://…/user/payment/<intent>
client_reference_id=<PaymentIntent.id>              # our reconciliation key
# optional: customer_email=…, payment_method_types[0]=card (default enabled), [1]=sepa_debit
```
- Response `url` → redirect the customer (303). `id` (`cs_…`) is the session id.
- **Idempotency:** header `Idempotency-Key: <PaymentIntent.id>` so a double-create returns the same session.
- **Amounts are in the smallest unit** (cents) — `unit_amount = round(euros*100)`. Guard the rounding.

**2. Customer pays on Stripe's page → redirected to `success_url`.** Display-only (don't settle here — «triggering fulfillment only from the success page is unreliable», per Stripe).

**3. Webhook (authoritative)** — subscribe to `checkout.session.completed` (and optionally `payment_intent.succeeded`).
- **Verify signature:** read the `Stripe-Signature` header + the endpoint's **signing secret** (`whsec_…`), call `Webhook.constructEvent(rawBody, sigHeader, secret)` (the PHP SDK) which throws on mismatch. Verify over the **raw body**.
- On `checkout.session.completed`: `payment_status == 'paid'` → settle. The PaymentIntent id is `session.payment_intent` (the acquirer txn) and `session.client_reference_id` is our intent id.

**`configFields()` for the adapter:** `secret_key` (secret, write-only), `webhook_signing_secret` (secret, write-only), `testmode`. `secretConfigKeys() = ['secret_key','webhook_signing_secret']`. SDK: `stripe/stripe-php` (composer).

---

## Recommendation (when we do B2)

- **Both** map onto `HostedRedirectGateway` + `WebhookGateway` with **webhook-driven** `settle()` (browser return = display-only). This is strictly more robust than the vPOS browser-return we have.
- **Stripe Checkout Session** is the lowest-effort, closest-to-Eurobank fit and covers cards + Apple/Google Pay with zero extra code + SEPA with one line.
- **PayPal Orders v2** is a clean second adapter; capture-on-return + `PAYMENT.CAPTURE.COMPLETED` webhook as the safety net.
- Vaulting/saved cards, subscriptions, PayPal reference-txn = **explicitly out of scope** for the first pass.

## To verify in sandbox before coding (checklist)
- [ ] PayPal: token → create order → `payer-action` redirect → capture → `PAYMENT.CAPTURE.COMPLETED` webhook verified. Confirm `custom_id` round-trips as our intent id.
- [ ] Stripe: create session → redirect → pay test card `4242…` → `checkout.session.completed` webhook, signature verified, `client_reference_id` = intent id, `payment_status=paid`.
- [ ] Both: amount/currency match the intent (T3), idempotent replay writes one `Payment` (T2), a forged/mismatched webhook is rejected (T1) and logged in «Log πύλης».
- [ ] Refunds (later): PayPal `POST /v2/payments/captures/{id}/refund`; Stripe `POST /v1/refunds`.

## Sources
- Stripe — Accept a payment (Checkout, Stripe-hosted): https://docs.stripe.com/payments/accept-a-payment?payment-ui=checkout&ui=stripe-hosted
- Stripe — Create a Checkout Session: https://docs.stripe.com/api/checkout/sessions/create
- Stripe — Webhooks / signature verification: https://docs.stripe.com/webhooks
- PayPal — Orders v2 API: https://developer.paypal.com/docs/api/orders/v2/
- PayPal — Standard Checkout (server-side): https://developer.paypal.com/docs/checkout/standard/integrate/
- PayPal — Verify webhook signature: https://developer.paypal.com/api/rest/webhooks/rest/
