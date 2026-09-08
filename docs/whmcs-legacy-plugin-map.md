# WHMCS legacy plugins → `ekdosi_bridge` — consolidation map

Foundation doc for the WHMCS consolidation phase. Maps the **three legacy
WHMCS plugins** (`/legacy/whmcs/`) to our single successor plugin
(`/whmcs-plugin/ekdosi_bridge/`) and specs the one real gap left to build.

> All three legacy plugins ran *inside the tenant's WHMCS install*. The
> successor is one consolidated plugin deployed the same way. ekdosi (the
> Laravel app) talks to it over HTTP with HMAC-signed requests.

---

## 1. `afm2name` — GSIS AFM lookup  →  **DROP (superseded)**

**What it does.** An admin convenience form: paste a Greek ΑΦΜ, get the
official taxpayer record (name, ΔΟΥ, address, status, activity codes) from
the **GSIS** public registry over SOAP (WS-Security UsernameToken,
`rgWsPublic2AfmMethod`).

**Integration.** Standalone WHMCS addon module (`_config/_output`); no hooks,
no DB, no client area.

**Status: superseded — do not port.** ekdosi already does GSIS natively
(`App\Services\AadeRegistryLookup`, used by the customer form), with
per-tenant credentials. A WHMCS-side lookup is only relevant if an operator
wants AFM autocomplete *inside WHMCS's own client forms* — out of ekdosi's
scope. If ever wanted, expose ekdosi's lookup via a bridge endpoint rather
than re-embedding SOAP in WHMCS.

> ⚠ **Security landmine.** The standalone copy hardcodes live GSIS
> credentials in source (`$gsisUser`/`$gsisPass`). Same rule as the legacy
> `GET_COMB_*` Firebird procs: **never carry these credentials over.** GSIS
> creds live only in ekdosi's per-tenant config now.

---

## 2. `prepare_for_ekdosi` — the "filed" link flag  →  **ABSORBED + extended**

**What it does.** The *linking juice* between WHMCS and ekdosi: an admin
tool to inspect an invoice and **reset `tblinvoices.invoiced`** (the "filed"
semaphore; `0` = not yet filed) so the legacy desktop app would re-pick it
up. Reads `tblinvoices` + `tblinvoiceitems`, writes `tblinvoices.invoiced=0`.
Addon module + `AdminDispatcher`→`Controller` (index/show/reset), `Capsule`,
`logActivity()`.

**Status: ABSORBED into `ekdosi_bridge`, and extended.**
- `lib/Admin/Controller.php::reset()` reproduces the `invoiced=0` reset
  (CSRF-guarded).
- `inbound.php` writes the real **AADE MARK** back into
  `tblinvoices.invoiced` (HMAC-authenticated, column widened to BIGINT, 409
  idempotency guard) — the legacy only ever wrote `0`/`1`.
- `Controller::show()` shows the `invoiced` value + the live ekdosi status.

The `invoiced` column remains the contract between the two systems
(`0`/unfiled ↔ a MARK once filed). Keep it.

---

## 3. `timologia` — third-party (reseller) invoicing  →  **NOT YET (HIGH)**

**What it does — the real-world case.** A WHMCS client who is a **reseller /
middle-man** orders many services, but *specific services* must be invoiced
**directly to the end customer**, not to the reseller. `timologia` lets the
client (or an admin) keep a list of alternate billing identities
("επαφές"/contacts — the end customers / employer / parent company) and
**route each service to a chosen contact**, and pick **τιμολόγιο vs απόδειξη**
(invoice vs receipt) per service.

**Integration.** Addon module with BOTH `timologia_output` (admin) and
`timologia_clientarea` (client self-service, login + SSL forced); a
`ClientAreaPrimaryNavbar` hook adds a "Παραστατικά σε τρίτους" menu item.
Manual `$_GET['act']` routers `include()` sub-scripts; `Capsule` query
builder + raw selects; Smarty `.tpl` views. No external calls.

### Data model (re-implementation spec — use the RUNTIME columns)

> ⚠ **The `CREATE TABLE` DDL is stale.** `timologia.php:21-24` creates Greek
> column names (`onoma/poli/doy/afm/drastiriotita`) and no `isReceipt`, but
> every runtime query uses richer English columns added out-of-band. **Build
> from the runtime columns, and confirm them against the production WHMCS DB
> before migrating.**

**`mod_timologia_contacts`** — an alternate billing identity owned by a client:
`id, userid (→tblclients.id), company_name, gr_vatno (Greek ΑΦΜ),
vies_vatno (EU VAT), tax_office (ΔΟΥ), description (δραστηριότητα),
address1, address2, postal_code, city, country, email, telephone, comments`.

**`mod_timologia`** — the routing map (service → contact):
`id, userid, contactid (→mod_timologia_contacts.id), serviceid,
service_type ∈ {hosting, domain}, isReceipt (0=τιμολόγιο / 1=απόδειξη)`.

### Routing logic (from `client_search.php` / `logged.php`)
- `serviceid` = `tblhosting.id` (when `service_type='hosting'`) or
  `tbldomains.id` (when `'domain'`).
- **One contact per service** — upsert enforced (`checkIfExists()`): 0 rows →
  insert, 1 → update `contactid`, >1 → error.
- **"προκαθορισμένο" (default) = NO row** → the service bills the client's own
  WHMCS identity. `reset-service` / `set-default` just DELETE the row.
- **Resolve who to bill:** for each WHMCS invoice line, derive its
  `serviceid` + `service_type` (`tblinvoiceitems.relid` → hosting/domain), then
  `LEFT JOIN mod_timologia` on `(userid, serviceid, service_type)`. Matched →
  snapshot the joined `mod_timologia_contacts` row as the invoice's billing
  party (AFM=`gr_vatno`, name=`company_name`, ΔΟΥ=`tax_office`, address…);
  `isReceipt` picks the document type. Unmatched → bill the WHMCS client
  (today's behavior).
- Deleting a contact cascades (deletes) its routing rows.

**Status: NOT YET — the known high-impact gap.** `WhmcsInvoiceMapper` always
bills the resolved WHMCS *client*; `WhmcsCustomerMatcher` only *documents*
this routing in a comment. Nothing on either side reads `mod_timologia*`.

---

## 4. `transfer_invoice` — whole-invoice reassignment  →  **ABSORB (manual split tool)**

**What it does.** Admin-only tool to move an **entire** WHMCS invoice from one
client to another: search/validate/confirm modal, then updates BOTH
`tblinvoices.userid` and `tblinvoiceitems.userid`, with a `logActivity()` audit
line. Whole-invoice, not per-line. (Hook: `AdminInvoicesControlsOutput` adds a
"Transfer invoice" button next to "View as client" — same hook the bridge
already uses.)

## 5. `relid_remover` — detach a line from its service  →  **ABSORB (manual split tool)**

**What it does.** Admin-only tool that sets `tblinvoiceitems.relid = 0` on
selected line items, i.e. **detaches a line from its service**
(`tblhosting`/`tbldomains`). Search invoice → checkbox lines → "Set relid to 0".

### Why they matter: the legacy "split" was MANUAL, not automatic
Together these two are the operator toolkit the legacy system used **instead of**
an auto-split. A reseller's single WHMCS invoice CAN mix billing parties —
confirmed in production: client 793 routes 4 services to 4 distinct contacts, so
one invoice covering several of their services bills multiple end customers. The
legacy answer was hand-surgery: `relid_remover` to detach lines, `transfer_invoice`
to reassign a whole invoice. **There is no automatic per-line split anywhere in
the legacy app.**

**Decision (multi-party split): block + flag for the operator, not auto-split.**
T-1 detects a multi-party invoice (>1 distinct `contactid` across its lines) and
stages it as a flagged item the operator resolves, rather than us silently
splitting one WHMCS invoice into N ekdosi invoices. Equivalent manual
split/transfer tooling can be absorbed into the bridge later if operators want
it; auto-split is explicitly out of scope (too risky for legally-significant
docs). Mirrors the legacy workflow.

## 6. Flag resellers in ekdosi (operator double-check)  →  **PLANNED (T-1 by-product)**

Operator request: in ekdosi, **flag Customers who have ≥1 entry in "Παραστατικά
σε τρίτους"** so an operator can double-check whether that customer's invoices
are really theirs (vs routed to a third party).

Feasible and cheap — the join key already exists (`customers.whmcs_client_id`,
the operator-set direct link). The reseller in `mod_timologia` is `userid →
tblclients.id`. Implementation, as a by-product of T-1:
- Bridge: a **read-only** endpoint returning the set of `userid`s with ≥1
  routing row (optionally a per-userid count) — sibling to the T-1 resolution
  endpoint, same `mod_timologia` read.
- ekdosi: badge any `Customer` whose `whmcs_client_id` is in that set (a
  "Παραστατικά σε τρίτους" tag on the Customer list/page). Read-only signal; no
  writes.

## 7. End customer → reseller link via `referred_by` (later)

Reuse the existing self-referential `customers.referred_by_customer_id`
(`Customer::referredBy()` / `referrals()`, already in the form) to record that
an end customer came in via a reseller. At T-1 match / T-3 import, when Haris is
created/matched from a Chris-routed `mod_timologia` contact, set
`Haris.referred_by_customer_id = Chris`. Result: Haris shows "Referred by Chris";
`Chris.referrals()` is his whole third-party book; pairs with the reseller flag
(#6). Each `mod_timologia_contacts` row is owned by exactly one `userid`, so the
referrer is unambiguous in the common case.
- **Edge (decide at build):** same ΑΦΜ as a contact under two resellers, or also
  a direct customer → dedup-by-`gr_vatno` must not silently overwrite an existing
  `referred_by`. Pick a precedence rule then.
- **Status:** later (operator parked it); not part of T-1's critical path.

## Consolidation matrix

| Legacy capability | Status in `ekdosi_bridge` |
|---|---|
| `prepare_for_ekdosi`: reset `invoiced=0` | ✅ absorbed (`Controller::reset`) |
| `prepare_for_ekdosi`: inspect invoice + lines | ✅ absorbed + live ekdosi status |
| write filing result into `invoiced` | ✅ extended — writes the real MARK (`inbound.php`, HMAC, BIGINT, 409 guard) |
| `afm2name`: GSIS AFM lookup | 🗑️ superseded — ekdosi does GSIS natively (drop; never carry the hardcoded creds) |
| admin module shell (dispatcher/controller) | ✅ pattern reused |
| outbound push + status query (HMAC) | 🆕 new in bridge (legacy ekdosi *polled*) |
| `timologia`: client manages alternate contacts (CRUD) | ✅ T-2 (v2 client page → own `mod_ekdosi_contacts`) |
| `timologia`: route a service → contact | ✅ T-2 (per-service routing → own `mod_ekdosi_routing`); resolution T-1 |
| `timologia`: invoice-vs-receipt per service (`isReceipt`) | ✅ T-2 captures it; T-1c split routes receipt groups to a receipt type |
| `timologia`: admin contact/routing UI | ✅ admin "Sync from legacy" (T-1b-2); client CRUD is the v2 page (T-2) |
| `transfer_invoice`: reassign whole invoice to another client | 🔜 absorb as manual split tool (low priority) |
| `relid_remover`: detach a line from its service (`relid=0`) | 🔜 absorb as manual split tool (low priority) |
| multi-party invoice handling | 🆕 T-1: **block + flag for operator** (not auto-split) |
| flag resellers (≥1 `mod_timologia` row) in ekdosi | 🆕 T-1 by-product (read-only badge via `whmcs_client_id`) |

---

## Re-implementation plan — timologia v2 inside `ekdosi_bridge`

**Decision: expand `ekdosi_bridge`, not a new plugin.** The bridge is
admin-only today (one `AdminInvoicesControlsOutput` hook); timologia adds
new client-facing surface to it. Bill **per service line, not per client**.

Phased so we can test against **real WHMCS data + sandbox AADE** without
customers noticing anything, and cut over from the legacy plugin cleanly.

### Phase T‑1 — resolution + billing (backend only; no client UI)
The only path we actually need to validate. No customer-visible change.

> **T‑1a — DONE (read-only resolution foundation).** Built + tested:
> - **Bridge `resolve.php`** — read-only HMAC endpoint (sibling of
>   `inbound.php`, same auth). `op=resolve` returns per-line routing for an
>   invoice (joins `mod_timologia → mod_timologia_contacts`); `op=resellers`
>   lists WHMCS clients with ≥1 routing row. Resilient when the legacy tables
>   are absent (`timologia_present=false`, not an error).
> - **ekdosi `WhmcsBridgeClient::resolveThirdParty()` / `listResellers()`** +
>   the `ThirdPartyResolution` value object (single source of the party /
>   multi-party / single-contact logic).
> - **`php artisan whmcs:resolve-third-party <inv> --tenant=` / `--resellers`**
>   — read-only diagnostic to validate against live WHMCS data with zero risk
>   to filing. Tests: 17 (value object + client HMAC/URL + command).
>
> **T‑1b‑1 — DONE (single-party billing + flag + kill-switch).** Built + tested:
> - **Per-tenant kill-switch** `companies.whmcs_third_party_enabled` (default
>   OFF). Off → ingestor never calls the bridge, behaviour identical to today.
> - **Ingestor wiring** (`WhmcsInvoiceIngestor::thirdPartyDecision`): resolves
>   at ingest (outside the row tx), then — single contact → bills the contact
>   (find-or-create Customer via `ContactCustomerResolver`, by `gr_vatno`,
>   entity-decoded); multi-party → `held` for the operator split (T-1c);
>   no-ΑΦΜ / ambiguous → held with a note; no routing → `none`, bills the
>   client. **Graceful degradation**: resolve.php not deployed / bridge down /
>   not configured → no-op, bills the client (so enabling the flag before
>   deploying the endpoint can't break ingestion).
> - **`pending_whmcs_invoices.third_party_state` + `_resolution`** persisted;
>   inbox shows a "Τρίτος" badge (Σε τρίτο / Διαχωρισμός / Όχι).
> - **Paid-at-issue** needs NO new code — WHMCS invoices file with a cash-term
>   invoice type, which `InvoiceBalance` already settles at issue and keeps off
>   any balance; billing the contact inherits this. (Money rule confirmed
>   2026-05-28.)
> - **Reseller flag (#6):** `customers.whmcs_reseller_routes` +
>   `php artisan whmcs:sync-resellers` (read-only mirror) + a Customer-list
>   badge. Tests: 14 new (resolver + ingestor paths + sync); full suite green.
>
> **T‑1b‑2 — DONE (bridge's own tables + sync).** Storage decision (supersedes
> "shared vs own"): **OWN tables, synced.** Built (WHMCS-side only; ekdosi
> contract unchanged):
> - **`mod_ekdosi_contacts` + `mod_ekdosi_routing`** — created at addon
>   activation (`ThirdPartyStore::ensureTables`). The bridge never writes the
>   legacy `mod_timologia*`.
> - **Sync** (admin page → "Sync from legacy timologia",
>   `ThirdPartyStore::syncFromLegacy`) — re-runnable, idempotent, keyed on the
>   legacy id; rows created on the v2 side (legacy id NULL) are never touched;
>   legacy tables only READ. Orphan routing (deleted contact) skipped + counted.
> - **`resolve.php` reads the own tables** now (`timologia_present` ⇒ "own
>   tables exist"). Response shape identical → no ekdosi change.
> - Decoupling confirmed: the `ThirdPartyResolution` contract is unchanged, so
>   all T-1a/T-1b-1 ekdosi tests still pass untouched.
>
> **T‑1c — DONE (guided multi-party split).** Built + tested:
> - **`invoices.whmcs_pending_id`** (many invoices ↔ one pending row,
>   `nullOnDelete`); `PendingWhmcsInvoice::STATUS_SPLIT` + `splitInvoices()`.
> - **`WhmcsInvoiceMapper::map(..., ?array $onlyWhmcsItemIds)`** — maps a line
>   subset (one billing party).
> - **`WhmcsInvoiceSplitter`** — groups the stored resolution by party, resolves
>   each party's Customer (contacts via `ContactCustomerResolver`, reseller via
>   the pending row's match), and creates one **DRAFT** invoice per party in a
>   single all-or-nothing transaction. **Deliberately drafts, not batch-AADE** —
>   each is filed individually via the proven per-invoice myDATA path, avoiding
>   the partial-failure hazard of multi-MARK batch filing. Refuses to half-apply
>   (any unresolvable party aborts the whole split).
> - **Inbox "Διαχωρισμός σε προσχέδια"** action (multi-party rows only) with a
>   per-party preview; `split` status badge + filter. 4 new tests; full suite
>   329 passed / 12 skipped.
> - **Independent review (post-build):** no HIGH issues; HMAC, tenant scoping,
>   ΑΑ-counter, graceful-degradation cores sound. Fixed: split now routes
>   `is_receipt` (απόδειξη) groups to a separate receipt type and **refuses**
>   rather than file a receipt routing as an invoice; contact-resolution
>   failures in the ingestor are caught (park held, never 500 the webhook);
>   empty-line groups refuse rather than silently under-bill; sync wrapped in a
>   transaction; stale diagnostic wording fixed.
> - **Deferred follow-ups:** single-party path still relies on the operator
>   picking the doc type at file time (preview shows the routing); WHMCS
>   write-back for split invoices (the legacy `invoiced` column holds one MARK,
>   not N).
1. **Bridge (WHMCS side):** add an endpoint (extend `inbound.php` +
   `EkdosiClient`) that, for a WHMCS invoice, resolves each line's
   `serviceid`+`service_type`, `LEFT JOIN mod_timologia → mod_timologia_contacts`,
   and returns per-line `{contact | null, isReceipt}`. **Read-only** against
   the existing live tables.
2. **ekdosi side:** `WhmcsInvoiceMapper` consumes it — a resolved contact
   becomes the invoice counterpart (match/create a `Customer` by `gr_vatno`)
   instead of the reseller; `isReceipt` selects τιμολόγιο vs απόδειξη.
3. **Multi-party edge:** a single WHMCS invoice can mix lines for different
   end customers → split into multiple ekdosi invoices, or block+flag.
   **Decide from real data** (see dump SQL below).

### Phase T‑2 — client-area "v2" page (gated, hidden by default)

> **T‑2 — DONE.** Built (WHMCS-side only; ekdosi untouched):
> - `ClientAreaPrimaryNavbar` hook + `ekdosi_bridge_clientarea` page
>   **"Παραστατικά σε τρίτους (v2)"** — contacts CRUD + per-service routing
>   (`lib/Client/Controller.php`, `templates/clientpage.tpl`).
> - **Gate** (`lib/Client/Gate.php`): admin `show_client_v2` switch (default
>   OFF) + optional `v2_pilot_clients` allowlist. Same gate guards the navbar
>   link AND the page handler (no URL-guessing a hidden page).
> - **Writes own tables only** (`mod_ekdosi_*`, `source='v2'`, legacy id NULL →
>   sync never touches them). Per the locked decision; NOT mirrored to legacy.
> - All CRUD client-id-scoped (ownership-guarded) + CSRF on POST.
> - **Superseded** the earlier "writes shared `mod_timologia*`" note below.

- Bridge addon gains a `ClientAreaPrimaryNavbar` hook + a client page
  (contacts CRUD + per-service routing), labelled **`Παραστατικά σε τρίτους (v2)`**
  (distinct from the legacy link so testers tell them apart).
- **Admin config checkbox** "Show client-area v2 link" — **default OFF**;
  the hook only adds the menu item when checked, so customers see nothing.
- **Optional pilot allowlist** (client IDs) — when set, only those clients
  see/use v2. Lets a single real reseller pilot it while everyone else sees
  nothing.
- **Write-safety:** the v2 page writes the shared live `mod_timologia*`
  tables (same rows the legacy plugin + desktop app read). Keep write access
  limited to the pilot allowlist until cutover.

### Phase T‑3 — import / sync + cutover  ⭐ (operator requirement)
When v2 is ready it must **read and import/sync the legacy "παραστατικά
τρίτων" data** so ekdosi becomes the system of record and the legacy
timologia plugin can be retired. Same philosophy as the Firebird ETL —
**re-runnable, keyed, idempotent**:
- **Contacts** (`mod_timologia_contacts`) → ekdosi **Customers**
  (match/create by `gr_vatno`; dedupe; keep a `whmcs_timologia_contact_id`
  back-reference for re-sync). These end customers then exist natively in
  ekdosi.
- **Routing** (`mod_timologia`: service → contact) → adopt into the bridge's
  own store (or keep reading the legacy table until the final sync), so the
  legacy plugin can be uninstalled.
- Re-run during transition (upsert by the legacy id); final sync at cutover,
  then legacy timologia → read-only archive (mirrors the desktop-app cutover).

### Pre-build data check (run on the production WHMCS DB) — the gating step
The `CREATE TABLE` DDL is stale; confirm the REAL columns + answer the
split question before building T‑1:
```sql
-- (a) the REAL columns (DDL lies — runtime adds English cols + isReceipt)
SHOW CREATE TABLE mod_timologia_contacts;
SHOW CREATE TABLE mod_timologia;

-- (b) volume
SELECT COUNT(*) AS contacts FROM mod_timologia_contacts;
SELECT service_type, COUNT(*) AS routes, COUNT(DISTINCT userid) AS clients
  FROM mod_timologia GROUP BY service_type;

-- (c) sample routing joined to contact + the reseller (WHMCS client)
SELECT t.id, t.userid AS reseller_id, c.companyname AS reseller,
       t.serviceid, t.service_type, t.isReceipt,
       k.id AS contact_id, k.company_name, k.gr_vatno, k.tax_office, k.city
FROM mod_timologia t
JOIN tblclients c ON c.id = t.userid
LEFT JOIN mod_timologia_contacts k ON k.id = t.contactid
ORDER BY t.userid
LIMIT 50;

-- (d) do single invoices mix billing parties? (decides split-vs-block)
--     relid→service join differs by item type; refine per 'Hosting'/'Domain*'.
SELECT i.id AS invoice_id, i.userid,
       COUNT(DISTINCT COALESCE(t.contactid, 0)) AS distinct_parties
FROM tblinvoices i
JOIN tblinvoiceitems ii ON ii.invoiceid = i.id
LEFT JOIN mod_timologia t ON t.serviceid = ii.relid
WHERE i.status = 'Paid'
GROUP BY i.id
HAVING distinct_parties > 1
LIMIT 50;
```
Paste the (a) column lists + (d) result back and we lock the schema + the
split decision, then build T‑1.

---

## Verified legacy semantics (forensic sweep, 2026‑05‑31)

Reference only — the legacy was a DB‑hack; we keep the *intent*, not the method.

- **Auto‑select criteria** (`FAutoInvoice.dfm:215`): `status='Paid' AND
  invoiced=0 AND gkriniaris='on'`. The new inbox is **broader on purpose**:
  it stages ALL paid+unfiled for operator review; only γκρινιάρηδες auto‑file
  (G8). `invoiced` is now owned by us (bridge write‑back), not the desktop app.
- **€0,00**: legacy skipped zero‑amount lines + suppressed empty invoices
  (`-1000`). **We deliberately DON'T skip** — operator decision: show all "για
  να ξέρουμε τι μας γίνεται".
- **Sentinels**: `-333` = operator re‑queue (manual), `-1000` = auto‑suppressed
  /error. New equivalents: `status` + `third_party_state` + `hold_reason`.
- **timologia routing** runtime columns (DDL is stale): `mod_ekdosi_contacts`
  (company_name, gr_vatno, tax_office, occupation, address…); `mod_ekdosi_routing`
  (userid, contactid, serviceid, service_type, **isReceipt** 0=τιμολόγιο/1=απόδειξη).
- **Multi‑party**: legacy SILENTLY mixed beneficiaries in one doc (a legal
  bug). We **block + flag** (TP_MULTI → guided split) — better than legacy.

## Operator‑visibility TODO (WHMCS‑side — "easier for staff")

Make the AADE state visible to a WHMCS operator WITHOUT opening ekdosi. The
single‑invoice admin badge already exists (`hooks.php`
`AdminInvoicesControlsOutput` → "Στο AADE · ΜΑΡΚ {value}"). Requested extras:

- **Invoice LIST column** (Billing → Invoices): a "filed/MARK" column/badge
  across the whole list, not just the single invoice page. WHMCS hook:
  `AdminAreaPage`/invoice‑list output (or a small JS/Capsule decorator reading
  `tblinvoices.invoiced`). High value, low risk (read‑only).
- **Client‑profile tab** (à la ModulesGarden CRM): a custom admin tab on the
  client showing the full mapping per invoice — **WHMCS #33333 → ΜΑΡΚ xxxx →
  ΤΠΥ‑129 (ekdosi)**. Needs an ekdosi bridge read endpoint (HMAC) returning
  the tenant's WHMCS↔MARK↔invcode rows for a client, rendered in an
  `AdminClientProfileTabFields`/custom‑tab hook.
- (Optional) client‑facing: show "Καταχωρήθηκε στην ΑΑΔΕ" + ΜΑΡΚ on the
  customer's invoice/PDF.

Guiding principle: anything that makes it easier for staff is welcome.
