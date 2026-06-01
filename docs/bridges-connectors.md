# Bridges / Connectors — billing-source integrations

How ekdosi ingests billing documents from external systems (WHMCS today;
WooCommerce / Blesta / OpenCart / PrestaShop tomorrow) and turns them into
ekdosi invoices, **without** entangling those systems with the legal core
(invoices / myDATA / money / PDF / customers).

> **Status:** Phase 0 landed (the seam + the multi-source registry). Phase 1
> (a real second source) is deferred until one actually arrives — that's when
> the data contract is finalised from *two* shapes, not guessed from one.

---

## 1. The principle

The core pipeline is already platform-agnostic — only the **edges** are
platform-specific:

```
Source → fetch / webhook → Ingest → match customer → INBOX → operator review
       → create draft → issue (lifecycle → myDATA) → write-back MARK
```

A **billing source** is a thing that:
1. emits external billing documents (WHMCS *invoices*, WooCommerce *orders*, …),
2. which become **draft** ekdosi invoices via an operator-reviewed inbox,
3. and (optionally) receives **write-back** of the issued AADE MARK.

The legal core never knows which source a document came from. That decoupling
is what makes this tractable — and why we will **never** fork "EkdosiWP": the
core (90% of the app) is shared; only a thin source adapter differs.

## 2. One company → many sources (important)

A single tenant can run **several** billing systems at once — e.g. a hosting
company with WHMCS **and** a WooCommerce shop, or two WooCommerce shops for two
brands. So the source is **not** a single column on `companies`. It is a
registry table:

**`billing_connections`** — one row per (company × connected system):

| column | meaning |
|---|---|
| `id` | PK |
| `company_id` | tenant |
| `source` | source key: `whmcs` \| `woocommerce` \| `blesta` … |
| `label` | operator-facing name, e.g. «Κύριο WHMCS», «Shop EU» (two of the same source are fine) |
| `is_active` | the on/off toggle |
| `config` | json — per-connection settings (Phase 1; today WHMCS creds still live on `companies.whmcs_*`) |
| timestamps, softDeletes | |

> **Future UX (Phase 1):**
> - a Company → **«Γέφυρες»** tab listing the company's connections with an
>   on/off switch each — exactly the `is_active` flag per `billing_connections`
>   row. Adding a connection = add a row + (later) fill its `config`.
> - **inbox(es)** — either one unified inbox with a `source` column + filter, or
>   **separate per-source inboxes** in the nav («WHMCS Inbox», and below it «WP
>   Inbox» …), each shown only when that connection is `is_active`. Both are
>   supported by the same data model (the staged row carries its `source`); the
>   per-source-nav variant is the more intuitive one and matches the per-connection
>   toggle.

Each staged document records which source it came from
(`pending_whmcs_invoices.source`; Phase 1 also a `billing_connection_id` FK so
two same-source shops are distinguishable — deferred until there are two).

## 3. The contract

`App\Contracts\BillingSource` (Phase 0 scope — **identity + capabilities only**,
deliberately NOT the data-movement methods yet; see §5):

```php
interface BillingSource
{
    public function key(): string;                 // 'whmcs'
    public function label(): string;               // 'WHMCS'  (drives UI labels/badges)
    public function capabilities(): SourceCapabilities;
}
```

`App\Support\Billing\SourceCapabilities` — lets the inbox/UI adapt with **no**
`if ($source === 'whmcs')` chains:

| capability | WHMCS | a WooCommerce shop (illustrative) |
|---|---|---|
| `docNoun` | «τιμολόγιο» | «παραγγελία» |
| `externalIdLabel` | «WHMCS #» | «WooCommerce #» |
| `supportsWriteBack` | yes (MARK → `mod_ekdosi_invoice_marks`) | maybe (order note) |
| `supportsThirdParty` | yes (timologia / Παραστατικά σε τρίτους) | no |

Resolution mirrors the proven `EInvoiceSubmitterFactory`
(`gr-mydata`/`ee-peppol`/`none`): `App\Services\Billing\BillingSourceRegistry`
maps a source key → implementation, config-driven via
`config/ekdosi.php → billing.sources`, so a new source is one config line + one
class — no core edit. Unknown key → `null` + a logged warning (forward-compatible).

## 4. What is WHMCS-specific (does NOT generalise)

These live inside `WhmcsBillingSource` + the per-document `metadata` bag, gated
by capability flags — they must not leak into the generic interface:

- **Third-party invoicing / timologia** (`mod_ekdosi_*`, resolve.php routing) —
  `supportsThirdParty`. An e-shop has no such concept.
- **The custom-field map** (`companies.whmcs_custom_field_map`: vatno / taxoffice
  / wantsinvoice / griniaris) — how WHMCS carries billing intent; another
  platform expresses it differently.
- **The `invoiced` flag + the deterministic historical link**
  (`tblinvoices.invoiced === invoices.legacy_id`, → `invoices.whmcs_invoice_id`).
  In a generic world these become `external_invoice_id` + `source`; the WHMCS
  columns stay as the historical archive (like Firebird's `legacy_id`).

## 5. Why the data methods are NOT in the interface yet

Locking `fetchPending()` / `fetchDocument()` / `writeBackMark()` + the
normalised `ExternalDocument` DTO from the **single** WHMCS example would bake
WHMCS-isms into the "generic" contract — WHMCS is invoice/B2B/AFM-shaped, a
WooCommerce shop is order/B2C-shaped. The honest rule: **you need two real
implementations to design the right abstraction.** So Phase 0 ships only the
identity/capabilities seam (zero risk, drives the UI + the multi-source
registry); the data contract is finalised in Phase 1 against two shapes.

The intended Phase-1 shape (for reference, not yet code):

```php
// Phase 1 additions to BillingSource:
public function fetchPending(Company $c, FetchWindow $w): iterable;  // → ExternalDocument[]
public function fetchDocument(Company $c, string $externalId): ?ExternalDocument;
public function writeBackMark(Company $c, string $externalId, MarkResult $r): void;
```

`ExternalDocument` (Phase 1) = normalised: `externalId`, `displayNumber`,
`issueDate`, `currency`, customer (name/ΑΦΜ/address/email/country), `lines[]`
(desc/qty/unitPrice/vat/net/gross), totals, `rawPayload` (audit), `metadata`
(the non-generalisable bits). Ingest / inbox / filer would consume *this*, not
WHMCS shapes.

## 6. Phasing

**Phase 0 — DONE (this branch):** `billing_connections` registry (multi-source);
`pending_whmcs_invoices.source`; `BillingSource` interface + `SourceCapabilities`
+ `BillingSourceRegistry` + `WhmcsBillingSource`; config-driven map; existing
WHMCS-configured companies seeded with a `whmcs` connection. **No behaviour
change** — the live WHMCS pipeline is untouched; the seam is laid alongside it.

**Phase 1 — when a real 2nd source arrives:** finalise the `ExternalDocument`
DTO + data methods from two shapes; add `billing_connection_id` to staged docs;
generalise `pending_whmcs_invoices` → `pending_external_invoices` (or add
`source`/`external_invoice_id`); rename `App\Services\Whmcs\*` →
`App\Services\Billing\Whmcs\*`; neutral webhook routes
`/webhooks/billing/{slug}/{source}/…` (keep `/whmcs/…` as an alias for the
already-deployed plugin); the Company «Γέφυρες» tab.

**Phase 2:** each new platform = a new `BillingSource` + its own platform-side
plugin speaking the same HMAC protocol.

## 7. "We leave WebPros (WHMCS) for Blesta"

With this design: add `BlestaBillingSource`, register a `blesta`
`billing_connection`, flip the WHMCS one to `is_active = false` (or keep both
during the parallel run). The inbox / labels / pipeline just work. All
historical WHMCS data + links (`whmcs_invoice_id`, `invoiced === legacy_id`)
remain as a **read-only archive** — exactly like the Firebird `legacy_id` today.
No fork, no data loss.

## 8. Files (Phase 0)

| File | Purpose |
|---|---|
| `app/Contracts/BillingSource.php` | the contract (identity + capabilities) |
| `app/Support/Billing/SourceCapabilities.php` | UI/behaviour capability flags |
| `app/Services/Billing/BillingSourceRegistry.php` | source key → implementation (config-driven) |
| `app/Services/Billing/Sources/WhmcsBillingSource.php` | the WHMCS adapter |
| `app/Models/BillingConnection.php` | the multi-source registry row |
| `database/migrations/*_create_billing_connections_table.php` | registry table |
| `database/migrations/*_add_source_to_pending_whmcs_invoices.php` | per-doc source |
| `database/migrations/*_seed_whmcs_billing_connections.php` | seed existing tenants |
| `config/ekdosi.php → billing.sources` | the source map |
