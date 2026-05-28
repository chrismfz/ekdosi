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

## Consolidation matrix

| Legacy capability | Status in `ekdosi_bridge` |
|---|---|
| `prepare_for_ekdosi`: reset `invoiced=0` | ✅ absorbed (`Controller::reset`) |
| `prepare_for_ekdosi`: inspect invoice + lines | ✅ absorbed + live ekdosi status |
| write filing result into `invoiced` | ✅ extended — writes the real MARK (`inbound.php`, HMAC, BIGINT, 409 guard) |
| `afm2name`: GSIS AFM lookup | 🗑️ superseded — ekdosi does GSIS natively (drop; never carry the hardcoded creds) |
| admin module shell (dispatcher/controller) | ✅ pattern reused |
| outbound push + status query (HMAC) | 🆕 new in bridge (legacy ekdosi *polled*) |
| `timologia`: client manages alternate contacts (CRUD) | ❌ not yet |
| `timologia`: route a service → contact (`mod_timologia`) | ❌ **NOT YET — HIGH** |
| `timologia`: invoice-vs-receipt per service (`isReceipt`) | ❌ not yet (no carry-through to ekdosi's invoice-type pick) |
| `timologia`: admin contact/routing UI | ❌ not yet |

---

## Re-implementation plan for the timologia gap (T2/T3)

Bill **per service line**, not per client. Proposed shape (matches CLAUDE.md
"option (a)" — keep the resolution on the WHMCS side, ekdosi consumes it):

1. **WHMCS side (`ekdosi_bridge`):** add an endpoint that, for a given WHMCS
   invoice, resolves each line's `serviceid`+`service_type`, `LEFT JOIN
   mod_timologia` → `mod_timologia_contacts`, and returns either the resolved
   contact (the end customer) or null per line, plus `isReceipt`.
2. **ekdosi side:** `WhmcsInvoiceMapper` consumes that — when a line has a
   resolved contact, snapshot it as the invoice counterpart (a `Customer` keyed
   by `gr_vatno`, created/matched on the fly) instead of the reseller; map
   `isReceipt` to the τιμολόγιο/απόδειξη invoice type.
3. **Edge:** a single WHMCS invoice could mix lines billed to different
   parties → may need to split into multiple ekdosi invoices (one per billing
   party). Confirm against real reseller invoices before deciding split-vs-block.

**Pre-build data check:** dump the live `mod_timologia_contacts` /
`mod_timologia` schemas from the production WHMCS DB (the DDL lies) and a few
real reseller rows, so the column list + `isReceipt` type are confirmed.
