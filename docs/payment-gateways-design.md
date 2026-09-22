# Payment Gateways (Πυλώνας B) — design + threat model

**Status: DESIGN ONLY — approve before any code.** This is the authoritative
design for the payments pillar of the WHMCS-replace roadmap (`PLAN.md §5`). It
covers **online gateways** (Stripe, PayPal, Eurobank, … + **manual** bank
deposit) reached from the customer portal, the **WHMCS-style per-tenant admin**
(a list of payment methods: enable/disable, settings, display name, order), and
the **modularity** contract so a new gateway is an afternoon's adapter, not a
rewrite (Eurobank this year, Viva next, unknown after — never a core edit).

> **Complements `docs/payment-connectors.md`** (the office rails: card-POS + IRIS
> request-to-pay). Those are the SAME abstraction with a different «how the
> customer pays» (redirect vs QR vs terminal tap vs offline). This doc is the
> superset — the office rails become one connector family under the contract
> in §3. Start reading here; that doc keeps the acquirer/ΑΑΔΕ-mandate detail.

---

## 1. The one principle (never negotiable)

Every rail — hosted card page, PayPal, IRIS QR, POS terminal, bank deposit —
collapses to the same lifecycle, and **money is confirmed only by the provider,
asynchronously, never by the browser**:

```
portal «Πλήρωσε» → initiate(amount, reference) → provider (hosted page / QR / …)
   → customer pays out-of-band
   → provider WEBHOOK (signed) → verify → write Payment → InvoiceBalance recompute
   ↑ the browser "return URL" is only a UX redirect; it NEVER settles money
```

The legal/money core (`Payment` + `PaymentAllocator` + `InvoiceBalance` +
καρτέλα + myDATA MARK) is **rail-agnostic**. A gateway only knows how to (a)
start an out-of-band charge and (b) tell us the truth about its outcome. That
decoupling is what makes the whole pillar tractable and is the thing the
contract protects.

---

## 2. What we reuse UNCHANGED (the money spine already exists)

- **`Payment` model + `PaymentObserver` + `InvoiceBalance`** — a gateway payment
  is just a `Payment` (allocated to an invoice, or on-account = **prepaid
  credit**) with a source tag. `InvoiceBalance` recomputes as today.
- **`PaymentAllocator`** — one «έμβασμα» settling N invoices + an on-account
  remainder is already modelled (the καρτέλα's `is_receipt_group`). A gateway
  charge for a balance flows straight through it.
- **«Η καρτέλα μου» (portal) + operator Καρτέλα + reconciliation + dashboard** —
  all consume `Payment`s; a negative balance already renders as «πιστωτικό
  υπόλοιπο», the seat prepaid credit funds. **No change** to any of these.
- **Signed-webhook idiom** — `routes/webhooks.php` (WHMCS bridge) already does
  «verify signature → fetch canonical, never trust the body». Payment webhooks
  reuse the pattern.

So Πυλώνας B adds **edges** (adapters + admin + webhooks), not a new money model.

---

## 3. The contract (modularity lives here)

Mirrors `EInvoiceProviderTransport` / `BillingSource` / `ProvisioningModule`:
a thin per-gateway adapter behind one interface. **Adding a gateway = one class
implementing this + one line in `config/ekdosi.php` → `payments.gateways`.**

```php
interface App\Contracts\PaymentGateway
{
    /** Stable key, matches the registry + connection row: 'stripe'|'paypal'|'eurobank'|'manual'|'viva'… */
    public function key(): string;

    /** What this gateway can do — drives the UI and the flow (see §3a). */
    public function capabilities(): PaymentGatewayCapabilities;

    /**
     * Start an out-of-band charge for an amount + reference (an ekdosi invoice
     * id or a «top up my credit» intent). Returns where to send the customer
     * (a hosted-page URL / a QR / offline instructions) + the provider intent
     * id — NEVER a success flag. Reads per-tenant creds from $connection->config.
     */
    public function initiate(PaymentIntent $intent, PaymentGatewayConnection $connection): PaymentInitiation;

    /**
     * Verify + parse an inbound webhook into a normalised outcome
     * {provider_txn_id, status, amount, currency, reference}. MUST authenticate
     * the payload (provider signature / HMAC) and MUST be safe to call twice
     * (idempotency is enforced by the caller on provider_txn_id).
     */
    public function handleWebhook(Request $request, PaymentGatewayConnection $connection): PaymentOutcome;

    /** Optional: refund a settled charge (capability-gated). Collect-only gateways omit it. */
    public function refund(Payment $payment, Money $amount, PaymentGatewayConnection $connection): PaymentOutcome;

    /** Smoke-test creds + reachability (the admin «Test connection» action). */
    public function testConnection(PaymentGatewayConnection $connection): ConnectionTestResult;
}
```

### 3a. Capabilities (so the flow + UI adapt, not branch on key)

```php
final readonly class PaymentGatewayCapabilities
{
    public string $flow;         // 'redirect' (Stripe/PayPal/Eurobank hosted) | 'request_to_pay' (IRIS) | 'terminal' (POS) | 'offline' (manual)
    public bool   $webhook;      // settles via webhook (true) vs operator-confirmed (manual=false)
    public bool   $refund;       // supports online refund
    public bool   $prepaid;      // can fund on-account credit (top-up), not just pay one invoice
    public array  $currencies;   // ['EUR', …]
}
```

The customer flow reads `flow`, never the key: `redirect` → send to hosted URL;
`request_to_pay` → show QR; `offline` → show bank details + «θα επιβεβαιωθεί από
το λογιστήριο»; `terminal` → operator-side only. A new gateway that fits an
existing `flow` needs **zero** flow code.

### 3b. Registry + config (the «add/swap» seam)

`App\Services\Payments\PaymentGatewayRegistry::for($key)` — config-driven via
`config/ekdosi.php → payments.gateways` (`['stripe' => StripeGateway::class, …]`),
**Null fallback** on unknown/empty key (log + a Null gateway that refuses to
charge), exactly like `ProviderTransportRegistry`. Adding **Viva**:

1. `app/Services/Payments/Gateways/VivaGateway.php implements PaymentGateway`.
2. one line: `'viva' => VivaGateway::class` in `config/ekdosi.php`.
3. the operator adds a **connection row** (creds) in the UI and toggles it on.

No migration, no core edit, no touching any other gateway. **Removing/swapping**
= toggle the connection `is_active` off (data, not code). That is the whole
modularity requirement, satisfied by the same pattern the e-invoice providers
already use.

---

## 4. Per-tenant configuration — the WHMCS-style admin

One row per (company × gateway), **mirroring `BillingConnection` exactly** — the
proven registry shape:

**`payment_gateway_connections`** (`BelongsToCompany`, SoftDeletes):

| column | meaning |
|---|---|
| `company_id` | tenant |
| `gateway` | key → `PaymentGatewayRegistry` (`stripe`/`paypal`/`eurobank`/`manual`/…) |
| `label` | **display name shown to the customer** («Κάρτα», «PayPal», «Κατάθεση σε τράπεζα») |
| `is_active` | **the enable/disable toggle** |
| `sort` | display order in the portal picker |
| `config` | **encrypted** JSON: creds (api key/secret/webhook secret) + display settings (bank IBANs for manual, instructions text, …) |

Operator surface = a Filament resource **«Τρόποι πληρωμής»** (super-admin, per
tenant), WHMCS-like:

- **List** every configured gateway with its `label`, `is_active` badge, `gateway` type.
- **Enable/disable** toggle (`is_active`) — the customer only ever sees active ones.
- **Settings form per gateway** — fields declared BY the gateway
  (`configSchema()` on the adapter → the resource renders them), so a new gateway
  brings its own fields with no resource edit. Secrets are `password`-type,
  write-only, stored encrypted (never echoed back, never logged — same discipline
  as `CompanySettings` / provider creds).
- **Display name** (`label`) + **order** (`sort`) — what/how the customer sees it.
- **«Test connection»** action → `testConnection()`.

The `config` is per-tenant so each company uses **its own** Stripe/PayPal/… account
(super-admin-only, like the other credential knobs — kept off the company_admin
`CompanySettings` page).

---

## 5. The customer flow (portal)

On «Η καρτέλα μου» / a document: a **«Πλήρωσε»** action (attaches to the existing
statement — the seat is already there). Steps:

1. Customer picks an amount (one invoice, the whole balance, or a top-up) and a
   **method** — only the tenant's `is_active` gateways, by `sort`, shown by `label`.
2. `PaymentGatewayRegistry::for($gateway)->initiate($intent, $connection)` →
   we persist a **`payment_intents`** row (status `pending`, our own id +
   provider intent id + amount + currency + reference + gateway) and send the
   customer per `flow` (redirect URL / QR / offline instructions).
3. Customer pays out-of-band.
4. Provider **webhook** → verify → look up the intent → write a `Payment`
   (allocated to the invoice, or on-account = prepaid credit) → mark the intent
   `settled` → `InvoiceBalance` recompute. The browser return URL only shows
   «ευχαριστούμε / εκκρεμεί» by reading the intent status — it never writes money.

**Amount is server-authoritative**: the customer never posts the amount to the
provider directly; we set it on the intent, and the webhook outcome's amount MUST
equal the intent's (else flag, don't settle — see §6).

### 5a. Tie-in: ΠΡΟΤ / proforma «convert on pay» (phase 2)

When the non-fiscal **ΠΡΟΤ** series (`PLAN.md §6`, BACKLOG) exists: a recurring
auto-issued ΠΡΟΤ is the portal «λογαριασμός σου»; paying it triggers **convert →
legal invoice** with the chosen payment method → mark paid → money trail. The
gateway doesn't know about this — it just settles a `reference`; the
convert-on-settle is an ekdosi listener on the intent. Prepaid credit likewise
just funds future intents.

---

## 6. Threat model (the reason to design before coding)

| # | Threat | Mitigation (build-time non-negotiable) |
|---|---|---|
| T1 | **Webhook forgery** — attacker POSTs «paid» | Verify provider **signature/HMAC** on the raw body; reject unsigned. Never trust a browser return as settlement. |
| T2 | **Replay / double-settle** — same webhook twice, retries | **Idempotency** on `provider_txn_id` (unique); a second delivery is a no-op. One intent → at most one `Payment`. |
| T3 | **Amount/currency tampering** | Settle only if webhook `amount`+`currency` == the stored **intent**'s. Mismatch → quarantine + alert, never a `Payment`. |
| T4 | **Secret leakage** | Per-tenant creds **encrypted** in `config`; write-only in UI; **never logged** (redact webhook bodies). Webhook-signing secret separate from API key. |
| T5 | **PCI scope creep** | **Hosted/redirect only (SAQ-A)** — card data NEVER touches ekdosi. No raw PAN fields, ever. (Eurobank/Stripe hosted pages; the contract forbids a `flow` that collects PAN.) |
| T6 | **Cross-tenant settlement** | The intent carries `company_id`; the webhook's connection resolves the tenant; verify the intent, invoice, and connection are the **same** company before writing. |
| T7 | **Race: pay while operator cancels/edits invoice** | Settlement writes a `Payment` against the invoice id regardless of local edits; `InvoiceBalance` reconciles. A cancelled invoice that gets paid surfaces as an over-credit on the καρτέλα (visible), never a silent loss. |
| T8 | **Refund abuse / customer-initiated refunds** | Refunds are **operator-only** (never portal), capability-gated, and go through `Payment` (kind=refund) so the money trail stays consistent. |
| T9 | **Intent/QR harvesting** | Intents are single-use, short-TTL, bound to (company, customer, amount); a settled/expired intent can't be reused. |
| T10 | **Reconciliation drift** | A gateway↔local reconciler (twin of `mydata:reconcile-sales`): pull the provider's settlement report, diff vs `Payment`s, list matched/missing/mismatch. Read-only worklist first. |

**Out of scope for v1** (explicit): storing card data, partial captures/pre-auth,
customer-initiated refunds, multi-currency FX. Collect-first.

---

## 7. Phase gates (stop at each; each is independently shippable)

- **B0 — contract + manual + intents (no external API).** `PaymentGateway`
  contract + registry + Null; `payment_gateway_connections` + the «Τρόποι
  πληρωμής» admin; `payment_intents`; the **manual** gateway (`flow=offline`:
  show bank details, operator confirms → existing manual `Payment`). Proves the
  seam + the whole admin/portal UX with zero money risk. The portal «Πλήρωσε»
  button ships here (manual only).
- **B1 — first hosted gateway = Eurobank / Cardlink vPOS.** ✅ DONE. Re-sequenced
  to the tenant's PRIMARY provider (card + Apple/Google Pay + IRIS through one
  hosted redirect). One real `flow=redirect` adapter end-to-end: initiate →
  auto-submitted signed vPOS form → hosted page → **signed return** (vPOS digest
  over the raw body) → the idempotent `settle()` → `Payment` → balance. Locks the
  security spine (T1–T3, T6) against a real provider. Ported field-for-field from
  the tenant's open-source WHMCS module. **Capability seams** added here:
  `HostedRedirectGateway` (builds the signed form), `WebhookGateway`
  (`handleWebhook` → normalised `PaymentOutcome`), `HasSecretConfig` (write-only
  config keys) — opt-in interfaces, so the offline/manual gateway stubs none.
  **Known limitation:** this module has NO separate server-to-server webhook —
  settlement rides the browser-return POST; a customer who closes the tab leaves
  the intent pending (safety net: operator manual-settle + reconciliation +
  stale-intent expiry, BACKLOG).
- **B2 — PayPal / Stripe** (second `redirect` adapter — proves «new gateway =
  adapter, not rewrite»).
- **B3 — office rails** (physical card-POS + the ΑΑΔΕ POS↔ERP mandate when a
  terminal is in play — see `payment-connectors.md §4`).
- **B4 — reconciliation** (T10) + **prepaid credit top-up** UX + **refund**
  (operator) + the **ΠΡΟΤ convert-on-pay** tie-in (needs the ΠΡΟΤ series).

Card-POS + IRIS office rails follow `payment-connectors.md` under the same
contract (they're `terminal` / `request_to_pay` capabilities).

---

## 8. Reuse / new — the ledger

| Reused unchanged | New (this pillar) |
|---|---|
| `Payment`, `PaymentObserver`, `PaymentAllocator`, `InvoiceBalance` | `PaymentGateway` contract + `…Capabilities` |
| καρτέλα (operator + portal), dashboard, reconcile shape | `PaymentGatewayRegistry` + `config/ekdosi.php → payments.gateways` |
| Signed-webhook idiom (`routes/webhooks.php`) | `payment_gateway_connections` (mirrors `BillingConnection`) + admin resource |
| myDATA MARK linkage (`invoices.mydata_*`) | `payment_intents` (pending→settled/expired, idempotency key) |
| CompanySettings credential-hygiene pattern | per-gateway `configSchema()` + encrypted creds |
| `billing_connections` multi-source reasoning | webhook routes per gateway + reconciler (B4) |

---

## 9. Open questions (resolve per provider, at build time)

- Stripe: Checkout (hosted) vs Payment Intents + Elements? → **Checkout** (SAQ-A).
- PayPal: Orders v2 hosted approval + webhook signature verification path.
- Eurobank: which product (Redirect/e-Commerce), test creds, webhook contract,
  and whether a physical terminal (⇒ ΑΑΔΕ interconnection) is in scope.
- IRIS: onboarding (direct bank vs aggregator) + request-to-pay webhook shape.
- Per-tenant single vs many connections per gateway (a company with two Stripe
  accounts?) — the row model already allows many; UI decides when it's real.
- Prepaid credit: cap / expiry / refundability policy (operator decision).

---

## 10. Eurobank vPOS — return verification runbook

**Επαληθεύτηκε σε production** (myip, `payment_intents` #5, 2026-09-20 03:02, €1.00): το return πέρασε την
canonical επαλήθευση digest (#607 — άγνωστο πεδίο ή λάθος σειρά θα το είχαν απορρίψει), έφερε `currency`
(υποχρεωτικό pin, #608) και `txId` 12ψήφιο (`320281706477` — από `txId`/`transactionId`, όχι `paymentRef`).
Η μοναδικότητα `txId` θεωρείται δεδομένη (αύξων μετρητής: `320255868967` στις 09-06 → `320281706477` στις 09-20)·
αν ποτέ φανεί ψευδές `duplicate_transaction` → N-day window στο `transactionAlreadySettled()`.
Το runbook παρακάτω μένει για διάγνωση αν αλλάξει κάτι στο πρωτόκολλο της τράπεζας.

**RUNBOOK — μία χρέωση απαντά και στα τρία.** Κάνε μία πληρωμή από την πύλη
(`/user/pay/{customer}`) — sandbox (`testmode`) ή μια μικρή πραγματική.

**Από το panel (χωρίς SSH):** «Log πύλης» → «Λεπτομέρειες» στη γραμμή της συναλλαγής. Δείχνει τη
σειρά πεδίων της τράπεζας δίπλα σε αυτή που περιμέναμε, μαρκάρει ό,τι δεν αναγνωρίσαμε, και σε
αποτυχία υπογραφής λέει αν φταίει η σειρά ή το shared secret. Το φίλτρο «Διάγνωση» απομονώνει τα
πρωτοκολλικά προβλήματα.

**Από το shell** (ίδια πληροφορία):
```bash
grep -E 'eurobank\.return\.(fields|unknown_fields|digest_mismatch)' storage/logs/laravel.log | tail -5
```
Διάβασέ το έτσι:
- **Μόνο `eurobank.return.fields` + το intent έγινε settled** → όλα σωστά. Σύγκρινε το `posted_order`
  με το `RETURN_FIELD_ORDER`· αν ταυτίζονται, το gate έκλεισε. Τσέκαρε ότι στη λίστα υπάρχουν
  **`currency`** (είναι υποχρεωτικό) και **`txId`** (αν λείπει και υπάρχει μόνο `paymentRef`, βλ. πιο
  κάτω).
- **`unknown_fields`** → το log ονομάζει ακριβώς το πεδίο που λείπει από τη λίστα· πρόσθεσέ το στο
  `RETURN_FIELD_ORDER` στη θέση που δείχνει το `posted_order`.
- **`digest_mismatch` με `received_order_matches: true`** → το shared secret είναι **σωστό**, μόνο η
  σειρά μας είναι λάθος. Αντέγραψε το `posted_order` αυτούσιο στο `RETURN_FIELD_ORDER`.
- **`digest_mismatch` με `received_order_matches: false`** → δεν είναι θέμα σειράς· κοίτα πρώτα το
  shared secret της σύνδεσης.

Το `eurobank.return.fields` γράφεται σε **κάθε** return — επιτυχία, αποτυχία, ακόμη και όταν το
`orderid` δεν αντιστοιχεί σε intent — και περιέχει **μόνο ονόματα πεδίων, ποτέ τιμές**.
**Ιστορικό:** τα δύο πειράματα της 2026-09-06 (`payment_intents` #1/#2, co=4) ΔΕΝ είναι ανακτήσιμα —
το «Log πύλης» δεν υπήρχε ακόμα, το `laravel.log` έχει rotate-αριστεί και τα nginx logs ξεκινούν
2026-09-10. Ό,τι επιβιώνει: το return επαληθεύτηκε (`settled_by=webhook:eurobank`) και έφερε
transaction id `320255868967` (12ψήφιος — μορφή `txId` της Cardlink).
