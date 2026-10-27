# Bridges / Connectors — billing-source integrations

How ekdosi ingests billing documents from external systems (WHMCS today;
WooCommerce / Blesta / OpenCart / PrestaShop tomorrow) and turns them into
ekdosi invoices, **without** entangling those systems with the legal core
(invoices / myDATA / money / PDF / customers).

> **Status:** Phase 0 landed (the seam + the multi-source registry). **Phase 0.5
> landed** (presentation-only): the inbox is the source-neutral «Εισερχόμενα» with
> a per-row source badge, and a «Γέφυρες» page (`Bridges`, `View:Bridges`) lists
> the registered sources with TRUTHFUL status (no fake on/off toggle — the live
> pipeline keys off `companies.whmcs_*`, not `billing_connections.is_active`).
> Phase 1 (a real second source) is deferred until one actually arrives — that's
> when the data contract is finalised from *two* shapes, not guessed from one, and
> when `companies.whmcs_*` → `billing_connections.config` + real is_active gating
> become worth doing.
> **§8–§10 capture the candidate sources + concrete Phase-1 pickup notes** so a
> future implementer (or a fresh session) can start without re-deriving them.

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

## 8. Candidate sources at a glance

The shape splits cleanly into **billing apps** (invoice/B2B — close to what we
already do) and **e-commerce** (order/B2C — receipts, often no ΑΦΜ, webhooks
need a module). This split is exactly why the `ExternalDocument` contract is
finalised against *two* shapes, not guessed from WHMCS alone.

| Source | Kind | Doc (`docNoun`) | Typical party | Connect / auth | Inbound (fetch/webhook) | Write-back | Third-party |
|---|---|---|---|---|---|---|---|
| **WHMCS** | billing | invoice (ΤΠΥ/ΑΠΥ) | B2B (ΑΦΜ via custom field) | WHMCS API (identifier+secret) **+** our addon plugin (HMAC) | API poll (`whmcs:fetch-pending`) + plugin push | `mod_ekdosi_invoice_marks` (our table) | yes (timologia) |
| **Blesta** | billing | invoice | B2B | Blesta API (user+key) **+** a Blesta plugin (HMAC) | API poll + plugin push | Blesta custom field / our table via plugin | no |
| **WooCommerce** | e-commerce | order → ΑΠΥ (ΤΠΥ if ΑΦΜ given) | B2C (EU-VAT plugin for B2B) | WP REST API (consumer key/secret) **+** WC webhooks (HMAC secret) | WC webhook on `order.paid` + REST poll | order meta / order note | no |
| **PrestaShop** | e-commerce | order | B2C/B2B | Webservice API (API key, basic auth) **+** a module | module hook push (native webhooks thin → module or poll) | order message / custom field via module | no |
| **OpenCart** | e-commerce | order | B2C | API / custom module | custom module push or poll | via custom module | no |

**Read-off for the contract:** billing apps slot in with minimal mapping
(Blesta is the cheapest 2nd source — almost a rename of the WHMCS adapter).
E-commerce forces the real generalisation: `docNoun='παραγγελία'`, default to
**ΑΠΥ/receipt** and only emit a **τιμολόγιο** when the order carries an ΑΦΜ,
no third-party concept, and webhooks usually need our own platform module
(same HMAC protocol as `ekdosi_bridge`).

## 8b. The bridge IS the inbox feed (in progress)

Decision (locked): the WHMCS source's **own plugin is the source of truth** for
the inbox feed — ekdosi fetches invoices FROM the plugin, not from WHMCS's native
API. The plugin already knows the routing (who/whom/which product → which third
party) and the ΑΠΥ/ΤΠΥ kind; re-deriving that on the ekdosi side (native API +
the often-unconfigured custom-field map) is strictly worse. This is the concrete
realisation of `BillingSource::fetchPending()` → `ExternalDocument`.

- **Slice 1 — DONE:** `resolve.php` op `invoices` (`InvoiceFeed`) serves a
  paginated, server-side-filtered (paid+unfiled) page of FULL payloads
  (invoice + client identity + customfields + line items), **shape-compatible**
  with the native `getInvoiceWithClient` so `WhmcsInvoiceIngestor` consumes each
  UNCHANGED. `WhmcsBridgeClient::fetchPendingInvoices` + `whmcs:fetch-pending
  --via-bridge` opt into it. One HMAC call replaces the native API's 1+2N
  round-trips + the limit/offset pagination quirk. The native `WhmcsClient` stays
  for the customer-ledger comparison. (plugin v0.19.0)
- **Slice 2 — DONE:** the feed is self-contained. `InvoiceFeed` embeds the
  third-party **routing** per invoice (`with_routing`, requested only when the
  tenant's `whmcs_third_party_enabled` is on), so `WhmcsInvoiceIngestor` builds
  its `ThirdPartyResolution` from the payload — **no separate `resolve` call**.
  Per-tenant `companies.whmcs_fetch_via_bridge` switch (default OFF) makes the
  scheduled fetch use the bridge; `--via-bridge`/`--native` override for ad-hoc
  runs. (plugin v0.20.0) The routing block is stripped from the stored payload
  (kept in `third_party_resolution`) so the snapshot matches the native shape.
- **Slice 3 — next:** "create the ekdosi customer from the bridge payload" when
  the ΑΦΜ isn't yet in ekdosi (the feed already carries the full party details).
- **Slice 4 — next:** move the customer-ledger comparison (`CustomerWhmcsLedger`)
  to a bridge op too — the last native-API consumer — then the native API
  identifier/secret can be retired (the `whmcs_api_url` stays: it derives the
  bridge endpoint).

## 9. Phase 1 implementation notes

Concrete pickup notes (known now; write the code against *two* sources).

### 9.1 Refactor blast-radius (what moves behind the interface)
Today these consume WHMCS shapes directly; Phase 1 routes them through
`BillingSource` + `ExternalDocument`:
- `app/Services/Whmcs/WhmcsClient.php` (+`WhmcsClientFactory`) — fetch.
- `app/Services/Whmcs/WhmcsInvoiceIngestor.php` — stage → `pending_*`.
- `app/Services/Whmcs/WhmcsCustomerMatcher.php` — identity match (see §9.4).
- `app/Services/WhmcsInbox/WhmcsInvoiceMapper.php` — doc → ekdosi invoice/lines.
- `app/Services/WhmcsInbox/WhmcsInvoiceFiler.php` — draft/file.
- `app/Services/Whmcs/WhmcsWritebackService.php` + `WhmcsBridgeClient.php` — MARK write-back.
- `app/Services/WhmcsInbox/WhmcsInvoiceSplitter.php` — **WHMCS-only** (third-party); stays gated by `supportsThirdParty`.
- `app/Http/Controllers/Webhooks/Whmcs*Controller.php` + `routes/webhooks.php` — neutral routes (§9.5).
- `app/Filament/Resources/WhmcsInbox/*` + `app/Models/PendingWhmcsInvoice.php` — inbox UI/model.
The **legal core** (`Invoice`/`InvoiceLine`/`MyDataSubmitter`/`InvoiceBalance`/
PDF) is NOT in this list — it must stay source-agnostic.

### 9.2 `companies.whmcs_*` → `billing_connections.config`
Phase 1 moves per-connection settings off `companies` into the registry row's
`config` json (a company can have two WHMCS connections with different creds):
`api_url`, `api_identifier`, `api_secret`, `webhook_secret`, `custom_field_map`,
`third_party_enabled`, `default_invoice_type_id`, `auto_issue_immediate`,
`invoice_min_date`, `amount_includes_tax`. Keep reading the `companies.whmcs_*`
columns as a fallback during the migration (don't break the live tenant), then
drop them once `config` is populated.

### 9.3 Connection/auth is per-source-type
The `config` schema must carry a `connection` block whose fields depend on the
source (see §8): WHMCS/Blesta = `{api_url, identifier/user, secret/key,
webhook_secret}`; WooCommerce = `{base_url, consumer_key, consumer_secret,
webhook_secret}`; PrestaShop = `{base_url, ws_key}`. Validate per source in the
«Γέφυρες» tab form (driven by `capabilities()` / a per-source config schema).

### 9.4 Customer-identity normalisation (the genuinely tricky bit)
`ExternalDocument` carries a normalised `customer` (name/ΑΦΜ/email/address/
country). The matcher generalises to: ΑΦΜ (B2B) → email → name. The
**doc-type intent** also generalises: WHMCS reads `wantsinvoice`/`needsAfm`
from the custom-field map; an e-shop infers it from "order has an ΑΦΜ field
filled" → τιμολόγιο, else ΑΠΥ. Keep the existing `needsAfm()` "wants invoice
but no ΑΦΜ → hold" guard — it's source-agnostic once intent is normalised.

### 9.5 `pending_whmcs_invoices` migration + back-compat
Add `billing_connection_id` (FK) and a generic `external_invoice_id`
(`whmcs_invoice_id` becomes its alias/back-fill), then either rename the table
to `pending_external_invoices` or keep it (the `source` column already
distinguishes). Keep `/webhooks/whmcs/{slug}/…` as a **permanent alias** of the
neutral `/webhooks/billing/{slug}/whmcs/…` — the deployed `ekdosi_bridge`
plugin hardcodes the old path (`Company::WHMCS_BRIDGE_PATH`).

## 10. Locked decisions (do not re-litigate)
- **Registry, not a column.** Source lives in `billing_connections` (N per
  company, `is_active` toggle), never a single `companies.billing_source`.
- **Data methods deferred to Phase 1.** The interface stays identity+capabilities
  until a 2nd real source exists — finalise the DTO from two shapes.
- **Never fork.** One core, thin per-source adapters. No "EkdosiWP".
- **Both inbox variants are valid** (unified-with-filter OR per-source nav);
  the data model (`source` on each staged row) supports either.
- **WHMCS-specifics stay WHMCS-specific** (timologia, custom-field map, the
  `invoiced`/`legacy_id` link) — gated by capabilities, never in the generic
  contract.

## 11. Files (Phase 0)

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

## 12. MyCS Bridge (the second real source, 2026-10-25)

MyCS (`chrismfz/mycs`, the billing system that follows WHMCS for myip) is the second source. It was built to serve
**the WHMCS plugin's feed shape** (`InvoiceFeed`, `op=resolve`), so it rides the existing bridge path instead of a
new `ExternalDocument` contract. The Phase 1 data contract (§5) stays open: MyCS is WHMCS-shaped on purpose, so it
doesn't count as the second *shape* that contract needs. The MyCS side is documented in its `docs/ekdosi-bridge.md`.

**What differs from the WHMCS bridge, and where:**

| | WHMCS bridge | MyCS bridge |
|---|---|---|
| Settings | API url/identifier/secret, webhook secret, custom field map, «amounts include VAT», third-party switch, via-bridge switch | `mycs_enabled`, `mycs_url`, `mycs_secret` (Company → «MyCS Bridge») |
| Endpoint | `…/ekdosi_bridge/resolve.php` + `inbound.php` | one: `{mycs_url}/api/ekdosi`, every op (`ping`, `invoices`, `invoice`, `resolve`, `mark`, `add_payment`) |
| ΑΦΜ / ΔΟΥ / activity / invoice-or-receipt / γκρινιάρης | client custom fields via `whmcs_custom_field_map` | `payload.mycs.recipient` + `payload.mycs.immediate_invoicing` (`App\Support\Billing\MycsPayload`) |
| VAT | Tax Type / toggle | `taxtype` = `Exclusive` always; each line has net, VAT and total |
| Recipient per line | `mod_ekdosi_routing` (opt-in) | always embedded (`third_party`), from MyCS's contacts |
| MARK write-back | `inbound.php {whmcs_invoice_id, mark, …}` | `op=mark {invoice_id, mark, …}`, **no fingerprint**: what ekdosi issued is final, whatever changed in MyCS since (owner, 2026-10-25: «αν κόπηκε, πάει κόπηκε»; a credit note if ever needed). MyCS refuses only an invoice merged or split there after it was read (409 `invoice_changed`): write-back state **`changed`** («Άλλαξε στη MyCS»), shown with that note, never retried. The row stays filed. A cancellation is never sent to MyCS for a MARK it didn't take |
| Payments billing → ekdosi | `whmcs:sync-payments` / reconcile read the invoice's status | the same, through `op=invoice` on MyCS (same ids, same shape): a credit-term invoice paid in MyCS closes its receivable here |
| Payments ekdosi → billing | `add_payment` | the same `op=add_payment` (MyCS E3, K2): once per transid, cut to what is left (never credit), `already_settled` on an invoice already paid there |
| Unpaid feed («τιμολόγιο πριν την πληρωμή») | `whmcs:fetch-unpaid` (native API, one call per customer) | `whmcs:fetch-unpaid` reads MyCS (K2): one `op=invoices status=Unpaid userids=[…]` a page for all the flagged customers; never the frozen WHMCS |
| Split rows | not written back (one MARK per WHMCS invoice) | each document of the split goes back on its own MARK (K2), and so does its cancellation; a failure is retried (row action, `whmcs:retry-writebacks`) by pushing every document again in its state |
| Staff «send to ekdosi» (also unpaid, also «ignore invoicing») | the plugin's button above the invoices pushes `invoice-paid` | MyCS E5/K3: `whmcs:fetch-pending` also reads `op=invoices status=requested` (when MyCS's `ping` lists it in `statuses`), all of it, no `since`; staged for a human (an unpaid one on credit), never issued unattended (auto-issue skips it); a NEW request (its `requested_at`) brings back a row archived here, once |
| Sale reversed in the billing system | — | `mycs:watch-reversals` (K2, every 15′, read only): a few issued rows a run, in turn (`op=invoice` each, within MyCS's 120/min); refunded, cancelled or unpaid again → the staff bell «Χρειάζεται πιστωτικό (MyCS)», once each |
| Credit/debit notes | — | `mycs.adjustments` ≠ 0 → the row is held («το σύνολο διαφέρει από τις γραμμές»), because a document built from the lines would be for the wrong amount. It is held when first seen, or when the amount changes; once the operator has sent it back to review, the same notes don't hold it again |

**One source per company.** `mycs_enabled` wins:
- `whmcs:fetch-pending` reads MyCS, whatever the WHMCS flags (`--native`/`--preview` ignored);
- a WHMCS paid-webhook push is refused with 409 `source_is_mycs`. MyCS keeps WHMCS's invoice ids and continues from
  them, so a late WHMCS push would land on an id MyCS uses.

Because the ids are the same, a row staged from WHMCS before the cut-over is the same row MyCS refreshes after it
(its `source` becomes `mycs`). Its MARK goes to MyCS. A WHMCS mass-pay container is the exception: one document for N invoices, while MyCS keeps
one MARK per invoice. Its MARK is not sent (`skipped`, with a note); its invoices were paid before the cut-over, so
MyCS never offers them again.

**Decided is decided.** As with WHMCS:
- an archived (rejected) or issued row is frozen: MyCS sending the invoice again only touches it;
- an issued row's MARK takes the invoice out of MyCS's feed.

The WHMCS filing guards apply unchanged: 0% VAT held for a human, totals and VAT rate reconcile, no negative lines,
EUR only. MyCS removes the inclusive/exclusive ambiguity at the source, since every line is net with its rate and VAT.

The ingestor guards the shared id space at the root:
- a WHMCS payload never replaces a row MyCS staged;
- a row refreshed from the other source for **another client** (WHMCS issued past the last import) is held once with
  a note, its payload untouched, and flagged `source_conflict`. Once the operator releases it, it is not held again,
  nothing of the other payload is mirrored onto a customer, and its MARK never goes to MyCS (`skipped`: it would
  document the other client's invoice);
- payment sync and reconcile take «Paid» only for the row's own client (`userid`);
- on a decided row the same collision is logged once a day, since it can't be re-staged.

**Kept on WHMCS's side:** `WhmcsBridgeClientFactory::for()` stays the WHMCS plugin's, for its legacy ops (resellers,
legacy links, custom fields). Only `forSource()` (fetch, single invoice, routing fallback, write-back) follows the
company's source.

**Shared with the WHMCS tab:** the cut-over date (`whmcs_invoice_min_date`, sent as `since`), auto-issue for
γκρινιάρηδες and the default document types. These run for any company with a source (`Company::hasBillingSource()`):
- `whmcs:auto-issue`, and its banner in the inbox;
- payment sync and reconcile;
- `whmcs:retry-writebacks`;
- OperatorHealth's fetch health;
- the readiness report.

A missing `immediate_invoicing` in a MyCS payload is «unknown»: it leaves the customer's flag alone.

**Known limits:**
- MyCS allows 120 requests a minute per address. Payment sync and reconcile make one `op=invoice` per open
  credit-term receivable, so beyond ~120 of them some are checked only on a later run.
- These are still WHMCS's for a MyCS company:
  - the gateway and income mapping pages (`WhmcsPaymentMapping`, `WhmcsIncomeMapping`): they list WHMCS's gateways
    and products, and the MyCS gateway names (`cardlink`, `paypal_ipn`, `mollie` …) are mapped at the cut-over;
  - `CustomerWhmcsLedger`, «link WHMCS client»: read-only.
- Payments go to MyCS under the same switch as WHMCS's («Σήμανση Paid», `whmcs_push_payments`, on the WHMCS tab). Never
  for a `source_conflict` row or another client's invoice, nor to a cancelled, refunded or draft one in MyCS (skipped).
  A permanent 409 shows as a failed push in the outbound list.
- `mycs:watch-reversals` makes a full round of a few thousand issued rows in about a day; a refund is belled within it.

**Live test before the cut-over** (owner, 2026-10-25): real invoices on `ekdosi.myip.gr` (provider with DEV
credentials) against MyCS, with Chrome. The checklist is in MyCS's `docs/ekdosi-bridge.md` → «Live δοκιμή».

**Done in K3:** «Send to Ekdosi» from MyCS (`requested`), the source's name in the inbox modals and the sync action
(and a MyCS-only company, with no WHMCS URL, can «Συγχρονισμός τώρα»).

**Done in K2:** the unpaid feed from MyCS, payments to MyCS, split write-back, the reversal watch, and the operator's
labels («MyCS #», «Κατάσταση MyCS», «Πελάτης (MyCS)» on MyCS rows and companies).

**Files:**
- `database/migrations/2026_10_25_100000_add_mycs_bridge_to_companies.php`
- `app/Support/Billing/MycsPayload.php`
- `app/Services/Billing/Sources/MycsBillingSource.php`
- `WhmcsBridgeClient` (`$mycs` mode, `ping()`, `op=mark`)
- `WhmcsBridgeClientFactory::forSource()`
- `tests/Feature/Whmcs/MycsBridgeTest.php`, with its fixture `tests/Fixtures/mycs/feed-invoices.json`, made by
  MyCS's own code from test data.

