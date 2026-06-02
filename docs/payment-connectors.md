# Payment Connectors — card-POS & IRIS integrations

How an online ekdosi (Laravel, cloud-hosted) could take a **local office
payment** — either on a physical **card POS terminal** or via **IRIS** (the
DIAS instant-payment rail) — and turn it into an ekdosi `Payment` linked to the
right invoice / myDATA MARK, **without** the app ever touching card data or
living on the same machine as the terminal.

> **Status:** DESIGN ONLY. Nothing here is built. This is the payments twin of
> `docs/bridges-connectors.md` — it captures the two rails, the cloud-to-cloud
> shape, the ΑΑΔΕ POS↔ERP interconnection mandate, and the contract/registry
> pattern so a future implementer (or a fresh session) can start without
> re-deriving them. **Recommendation: start with IRIS** (online-first, no
> hardware); card-POS only once an acquirer with a cloud API + webhooks + the
> ERP-interconnection flow is picked.
>
> **Caveat:** exact ΑΑΔΕ specs, endpoint names and field shapes **vary by
> acquirer** (Viva.com / Cardlink-Worldline / NBG / Mellon …) and by the
> middleware/aggregator the merchant uses for the interconnection mandate. The
> contract below is deliberately acquirer-agnostic; the concrete payloads get
> nailed down against **one** acquirer's docs when we actually build.

---

## 1. The principle

ekdosi is a cloud app; a card POS is a physical box on a desk, possibly behind
NAT, with no inbound internet route. The two never talk directly. Like the
billing-source seam, only the **edges** are integration-specific — the core
(`Payment` model, `InvoiceBalance`, the καρτέλα ledger, myDATA linkage) is
payment-source-agnostic:

```
Operator on invoice → "Πληρωμή με κάρτα/IRIS" → request to rail (cloud API)
   → customer pays (taps card / scans IRIS QR) → rail webhook → ekdosi
   → write Payment (+ link MARK) → InvoiceBalance recompute
```

A **payment connector** is a thing that:
1. is asked to collect an `amount` for a `reference` (an ekdosi invoice),
2. drives an out-of-band payment (terminal tap, or IRIS request-to-pay),
3. and reports the outcome back **asynchronously** (webhook) — never trusting a
   client-supplied "paid" flag.

The legal/money core never knows which rail the money came in on. That
decoupling is what makes this tractable from a cloud app.

---

## 2. Two rails, very different difficulty

| Rail | What it is | Difficulty from a cloud app |
|---|---|---|
| **Card POS** | Physical terminal taking chip/contactless cards | **Hard** — needs an acquirer with a *cloud* terminal API, or a local bridge agent; plus the ΑΑΔΕ POS↔ERP interconnection mandate |
| **IRIS** | DIAS instant interbank payment (request-to-pay / QR) | **Easy** — purely online, no hardware, webhook-driven; the natural first target |

The instinct ("buy a POS from any bank, plug it into the app") is the hard one;
IRIS gets you cashless local payments with none of the hardware/interconnection
problems.

---

## 3. Card POS — two integration models

A cloud app cannot reach a terminal on the office LAN directly. Two ways round
it:

### 3a. Cloud-to-cloud (preferred — no local component)
The terminal is **internet-connected** and managed by the **acquirer's cloud**.
ekdosi calls the acquirer's REST API ("charge €X on terminal T, reference R");
the acquirer pushes the sale to the physical terminal; the operator taps the
card; the acquirer posts the result back to an ekdosi **webhook**.

```
ekdosi (cloud) ──REST──▶ acquirer cloud ──push──▶ terminal (office)
                                                      │ tap
ekdosi (cloud) ◀──webhook── acquirer cloud ◀──result─┘
```

- ekdosi never sees card data (PCI scope stays with the acquirer) and needs no
  software on the office machine — just the terminal online + API creds.
- **Viva.com** is the most developer-friendly here (clean cloud terminal API +
  webhooks). Cardlink/Worldline, NBG, Mellon also have programmes but the
  ergonomics vary; some route through middleware/aggregators.

### 3b. Local bridge agent (fallback)
If the acquirer only exposes a **LAN/serial** protocol to the terminal, a tiny
**local agent** on the office machine bridges it: ekdosi → agent (the agent
polls ekdosi or holds an outbound websocket, since it has no public IP) → agent
talks to the terminal over LAN → agent posts the result back to ekdosi.

This is the WHMCS-style "we run a small thing on their side" pattern, but for
hardware. More moving parts, an extra deployable — only if no cloud API exists.

---

## 4. The ΑΑΔΕ POS↔ERP interconnection mandate

Since the 2024 Greek mandate, a card transaction on a POS must be **linked to a
myDATA document** — the POS can't just take money in isolation; the
ERP/τιμολογιέρα and the payment terminal are interconnected so the card sale
references the invoice (and its MARK).

For ekdosi this means a card-POS connector is **not** just "take €X" — at file
time it must hand the acquirer/middleware the document reference so the
transaction and the myDATA MARK are stitched together. The exact handshake
(who reports to ΑΑΔΕ, what id travels with the charge) is **acquirer/middleware-
specific** and is the part that needs their concrete docs. IRIS is **not** under
this POS-interconnection regime — another reason it's the easier start.

---

## 5. IRIS — the easy, online-first rail (recommended first)

IRIS is DIAS's instant interbank payment. For a business it works as
**request-to-pay / QR**: ekdosi asks IRIS to create a payment request for an
amount + reference; the customer pays from their banking app (scan the QR or
approve the request); IRIS confirms via **webhook**. No terminal, no PCI, no
local agent, no POS-interconnection mandate — exactly what a cloud app wants.

```
ekdosi ──create request(amount, reference)──▶ IRIS
        ◀── QR / payment link ──
   (customer scans & approves in their bank app)
ekdosi ◀── webhook: paid ── IRIS  → write Payment + link MARK
```

This is the closest thing to "card payment without the card hardware" and the
sane place to begin.

---

## 6. Architecture (mirrors the provider/registry pattern)

Same shape as `EInvoiceSubmitterFactory` (gr-mydata/ee-peppol/none) and
`BillingSourceRegistry` — a config-driven registry of payment connectors,
selected per tenant, all behind a thin contract; the core writes the existing
`Payment` model.

### 6a. Contracts

```php
// A request to collect money out-of-band; the result arrives later by webhook.
interface App\Contracts\PaymentTerminal      // card POS
{
    public function requestCardPayment(Money $amount, string $reference): PaymentRequestResult;
    public function key(): string;            // 'viva' | 'cardlink' | 'none' …
}

interface App\Contracts\IrisRequest          // IRIS request-to-pay
{
    public function createRequest(Money $amount, string $reference): IrisRequestResult;
    public function key(): string;            // 'iris' | 'none'
}
```

`PaymentRequestResult` / `IrisRequestResult` carry the rail's transaction id +
(for IRIS) the QR/payment-link — **not** a success flag. Success is confirmed
**only** by the webhook, never synchronously, never by the client.

### 6b. Registry + config

`App\Services\Payments\PaymentConnectorRegistry` resolves a connector by key,
config-driven via `config/ekdosi.php → payments.connectors` (mirrors
`billing.sources`). A null/`none` connector is the always-present no-op for
tenants that don't take card/IRIS — same forward-compatible discipline as the
provisioning-module registry in the services plan (unknown key → null + log
warning, never crash).

Per-tenant selection lives on `companies` (or a `payment_connections` registry
row if a tenant needs several — same reasoning as `billing_connections`: a
superset that can collapse to a column later, not the other way round).

### 6c. Webhooks

A signed inbound route per rail (HMAC, like the WHMCS bridge webhooks in
`routes/webhooks.php`): the rail posts `{reference, transactionId, status,
amount}`; ekdosi verifies the signature, looks up the invoice by `reference`,
and on success writes a `Payment` (existing model, existing `InvoiceBalance`
recompute) and — for card POS — records the POS↔myDATA link. Idempotent on the
rail transaction id (a webhook can fire twice).

### 6d. What we reuse unchanged
- **`Payment` model + `PaymentObserver` + `InvoiceBalance`** — the money core
  is rail-agnostic; a card/IRIS payment is just a `Payment` with a source tag.
- **myDATA linkage** — `invoices.mydata_*` / `mydata_marks` already hold the
  MARK; the connector references it, doesn't reinvent it.
- **The καρτέλα ledger / dashboard / reconciliation** — consume `Payment`s;
  no change.

---

## 7. Recommendation

1. **Start with IRIS** — online-first, no hardware, no PCI, no
   POS-interconnection mandate. Biggest payoff for the least integration risk.
2. **Card POS only after** picking an acquirer that offers a **cloud terminal
   API + webhooks + the ΑΑΔΕ ERP-interconnection flow** (Viva.com is the
   front-runner on ergonomics). Prefer cloud-to-cloud (§3a); fall back to a
   local bridge agent (§3b) only if the acquirer is LAN/serial-only.
3. Build both behind the §6 contract + registry so a second acquirer is a new
   adapter, not a rewrite — exactly how the e-invoice submitters and billing
   sources are structured.

---

## 8. Out of scope (explicitly, for v1 of any future build)
- Storing or transmitting card data (PCI) — always the acquirer's job.
- A specific acquirer's payload shapes — pinned down against **one** acquirer's
  docs at build time; the contract here stays acquirer-agnostic.
- Refunds / partial captures / pre-auth — collect-only first.
- Reconciliation of rail settlement reports vs ekdosi `Payment`s (a later
  twin of the myDATA reconciliation).

---

## 9. Open questions (resolve against the chosen acquirer)
- Which acquirer / which cloud terminal API (Viva.com vs Cardlink-Worldline vs
  NBG vs Mellon)?
- Exact POS↔ERP interconnection handshake under the ΑΑΔΕ mandate — who reports
  to ΑΑΔΕ, what document id travels with the charge, via which middleware?
- IRIS onboarding path for the merchant (direct via their bank vs an aggregator)
  and the request-to-pay webhook contract.
- Per-tenant single connector (column on `companies`) vs many
  (`payment_connections` registry) — decide when a real second rail arrives,
  same as `billing_connections`.
