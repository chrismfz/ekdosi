# CLAUDE.md — ekdosi modernization

Working guide for this repo. Read this first. Kept lean on purpose — detail lives in the
docs this file points to.

> **Full history** (dated inspection notes, per-PR review logs, review war-stories,
> resolved deferred-findings): **`docs/CLAUDE-history.md`** — not auto-loaded, consult it
> for the "why" behind a past decision.
> **AADE spec — CURRENT: v2.0.2 official (Σεπ 2026)**:
> **`docs/aade/myDATA_API_Documentation_v2.0.2_official_erp.md`** (full ERP text, + `.pdf`; §8 =
> code tables, §7.2 = the 101–280 business-error list); implemented by `firebed/aade-mydata`
> 5.12.0. **Τι άλλαξε vs v2.0.1 + τι πρέπει να wire-άρει το
> ekdosi → `docs/aade/mydata-v2.0.2-changes.md`** (actionable delta). Providers protocol:
> `…_v2.0.2_official_providers.pdf` (πληροφοριακό — τι υλοποιεί ένας πάροχος, όχι εμείς).
> **Ψηφιακή Διακίνηση (DGM) lifecycle — CURRENT: `…_DeliveryNote_v2.0.2_preofficial.md`** (§1.2 =
> state machine, §3.2.7 = ConfirmDeliveryReturn reachable-from states, §4 = schemas, §7.1 =
> InvoiceDeliveryStatus, §7.2 = event types, §6.2 = 800–824 errors) — the authority
> for the movement lifecycle (the ERP md only gives the issue payload).
> **What's built → `FEATURES.md`** · **what's left/ideas → `docs/BACKLOG.md`** ·
> **per-change → `CHANGELOG.md`**.

## What this project is
Porting a legacy **C++Builder (VCL) + Firebird** invoicing app ("ekdosi") to
**Laravel 13 + FilamentPHP 5 + MariaDB**. A homegrown Greek τιμολογιέρα with **myDATA**,
**QR**, a **WHMCS bridge**, and (new) a **customer portal + payment gateways**, that
compiled only on a fragile Windows 7 VM — escaping that toolchain is the whole point.

Scope: customers, stock/products, services, invoices (παραστατικά), payments, credit notes,
quotes (προσφορές), myDATA submission + reconciliation + audit trail, WHMCS bridge, customer
portal + online payments. The old C++Builder/Firebird app becomes a read-only archive after
cutover.

## Goal & end state
- Single multi-tenant MariaDB (`company_id` on every table), one Laravel codebase, one
  Filament panel with tenant switching.
- myDATA via **`firebed/aade-mydata`** (wrapped in `App\Services\MyDataSubmitter`); per-tenant
  credentials on `companies`. We don't re-port the lost legacy `CMyData.cpp` — semantic
  equivalence, not byte-match.
- WHMCS bridge re-implemented **PHP-to-PHP via the WHMCS API** (decision locked — not
  shared-DB), replacing the legacy `AUTO_INVOICE_LOG` polling + `FMysqlSync` push.
- **Multi-country from day one**: 3 tenants — 2 Greek mainland (myDATA) + 1 Estonian.
  `companies.einvoice_provider` selects the submitter (`gr-mydata`, `ee-peppol`, `none`) behind
  a common issue flow. Estonian PEPPOL is a stub until their e-invoicing deadline forces it.

## Stack (locked)
- **PHP 8.4+**, **Laravel 13**, **MariaDB 11.x** (`utf8mb4_unicode_ci`).
- **FilamentPHP 5** as admin panel AND tenancy driver (Company = tenant; no separate
  multi-tenancy package).
- **myDATA**: `firebed/aade-mydata`.
- **Roles/permissions**: `spatie/laravel-permission` + `bezhanSalleh/filament-shield` (teams
  mode; managed roles per tenant: `super_admin`, `company_admin`, `operator` — provisioned by
  `TenantRoleProvisioner`, see latent items).
- **PDF**: `barryvdh/laravel-dompdf`. **Backups**: `spatie/laravel-backup`.
- **Audit log**: `spatie/laravel-activitylog` (wired on invoices/customers/payments via
  `App\Models\Concerns\TracksActivity`; «Ιστορικό» tab per record).
- **Queue/scheduler**: Laravel built-in (DB driver). Scheduler IS wired (`routes/console.php`,
  gated by `config/ekdosi.php`) — needs the OS cron + a queue worker to actually run (Env-prep).
- **Firebird driver on the ETL host**: `pdo_firebird` (only the artisan host needs it).

## Repo layout
```
/                         # Laravel 13 app at repo root
  app/Console/Commands/MigrateFromFirebird.php   # re-runnable ETL, one tenant per run
  app/                                           # models, Filament panels, services, actions
  database/schema/*-schema.sql                   # SQUASHED baseline (v2.0.2) — sqlite + mariadb
                                                 # ⚠ ΠΟΤΕ DROP TABLE μέσα (SchemaBaselineTest) —
                                                 # το schema:dump τα ξαναβάζει, ξανα-strip πριν commit
  database/migrations/                           # only NEW migrations on top of the baseline
  whmcs-plugin/ekdosi_bridge/                    # OUR WHMCS-side plugin (deployed to tenant's WHMCS)
  docs/aade/*                                    # the AADE specs (submission + ΔΑ lifecycle)
  docs/CLAUDE-history.md                         # archived full project history
```
> **`/legacy/` was REMOVED from the repo (2026-09-06, secrets hygiene** — the legacy `.dfm`/`.cfg`
> files carried hardcoded DB/SMTP/CS-Cart passwords). The C++Builder source, `ekdosi-schema.sql`,
> the `ekdosi.fbk` gbak and the archived WHMCS plugins now live **only in an offline backup** — its
> job (understanding the legacy math + one-time ETL) is done. References below to `FAddInvoice.cpp`
> etc. describe *where the ported logic came from*, not in-repo files. **NOTE:** deleting the folder
> does NOT purge it from git history — the secrets are still in old commits until a history rewrite,
> and the exposed credentials must be ROTATED regardless (`docs/BACKLOG.md` → Security/ops «Legacy
> secrets στο git history»).

## Architectural decisions (do not re-litigate without reason)
- **Multi-tenant, not per-DB.** Superset: can deploy per-DB later; reverse can't.
- **Surrogate PKs + `legacy_id`.** Legacy integer PKs collide across companies (CUST_ID=1 exists
  in myip AND nixpal). Fresh `id` everywhere; `legacy_id` (unique per company) kept for audit +
  re-runnable ETL. FKs rewired via `legacy_id → new_id` maps during import.
- **Numbering = continuous counter per invoice type** (`INVTYPE.INVCOUNT`, from the triggers —
  NOT per fiscal year). Copied as-is to `invoice_types.invcount`; the new app continues from
  there (no fiscal-boundary cutover). myDATA terms: `invoice_types.code` → series,
  `invoices.code` → ΑΑ. **Increment under `lockForUpdate()` in a transaction** (`InvoiceNumberer`).
- **`mydata_marks` is the source of truth** for myDATA (full request/response XML kept, legal
  audit). `invoices.mydata_*` columns are a denormalised cache of the latest state (replaces the
  legacy `MARK_AI0` trigger; written inside the same transaction as the mark row).
- **Two orthogonal statuses.** `invoices.local_status` (`draft`/`active`/`cancelled`, business
  intent) vs `mydata_state` (`null`/`VALID`/`CANCELLED`, the AADE truth). Reconciled, never
  conflated. `App\Support\InvoiceScope::live()` is the one predicate (not-cancelled +
  not-AADE-cancelled) used at all money sites.
- **Money is one service.** `App\Services\InvoiceBalance` is the single source for
  paid/credited/balance/status; cache columns (`paid_total`, `credited_total`, `payment_status`)
  are written ONLY by it (via `forceFill`, not fillable).
- **Charset:** legacy DB is **WIN1253**. ETL connects `charset=UTF8` (FB transliterates on read;
  UTF8 superset → no errors). Fallback: `charset=NONE` + `iconv('Windows-1253','UTF-8//IGNORE',…)`.
- Reserved words renamed; INVDATE+INVTIME → `invoices.issued_at`; Firebird domains → concrete
  decimals.
- **Services = ONE model, evolved additively — never cloned.** `ServiceContract` covers every
  recurring thing (support, licenses, VM, and — όταν έρθει η MyIP off-WHMCS — shared hosting/VPS/
  reseller): same renewal/dunning/money/myDATA core. Grow it via `service_type`/categories +
  `ProvisioningModule` drivers + addons as child rows + progressive disclosure — NOT a second
  "Hosting Services" module (that would duplicate the money-critical core). Full rationale:
  `docs/services-module-evolution.md`.

## Conventions
- Deliver **complete, ready-to-drop files**, not diffs.
- Simplicity over cleverness; no premature abstraction / over-engineering.
- snake_case tables (plural), `id` PK, `timestamps`, `softDeletes` where it makes sense.
  utf8mb4 / utf8mb4_unicode_ci.
- Money `decimal(14,2)`, qty `decimal(9,3)`, vat% `decimal(5,2)`.
- Operator **panel** UI text is Greek; code identifiers stay English.
- **Customer-facing i18n (bilingual el/en) — the rule for ANY new customer surface.** The
  operator panel stays Greek (above), but everything a **CUSTOMER** sees is bilingual and MUST go
  through the i18n path — **never hardcode Greek** in a customer-facing blade/email/PDF:
  - **Portal pages** (`resources/views/portal/*`, guard `portal`) → `__('portal.*')` keys in
    `lang/{el,en}/portal.php` (Greek values **verbatim** — existing `assertSee` tests depend on it).
    Locale is per-request via `SetPortalLocale`: `CustomerLanguage::forPortal($user, $hostCompany)` =
    the logged-in `customer_users.locale` → the portal host's tenant `default_language` → app default.
  - **Emails to customers** → resolve the recipient language with `CustomerLanguage::forDocumentMail($doc)`
    (or `forCustomer`) and `->locale()` the Mailable; strings in `lang/{el,en}/*`.
  - **PDF / legal documents** → labels via `App\Support\Pdf\PdfLabels` (`el`/`en`/`both`); the document's
    language is **FROZEN** (`invoices/quotes.language` → snapshotted `country`), never live customer data.
  - **New customer surfaces** (Cart, self-registration, a new document type…): under the `/user`
    route group they inherit `ResolvePortalHost`+`SetPortalLocale` automatically; a customer-facing
    route **outside** that group must add those middlewares. So a future Cart / registration page /
    new παραστατικό ships bilingual **from day one** — add its strings to `lang/`, never inline Greek.
  Full model: `PLAN.md §6.5`.
- **VAT seeding = mainland only.** `MyDataLookupSeeder` seeds 24/13/6 (+ one reasoned 0%); the
  island 17/9/4 and ν.5057 rates are deliberately not seeded (all tenants mainland) — the codes
  still live in `Codes::VAT_CATEGORY_RATES`, add by hand if ever needed.
- **CSS: two worlds (gotcha).** The **Filament PANEL** ships ONLY Filament's `.fi-*` component
  CSS — **no Tailwind utility layer** inside the panel. A utility class used in a custom **panel**
  blade page (`grid`, `gap-3`, `text-sm`, `dark:*`, `md:*`, or a `.fi-*` override) must be
  **hand-defined in `resources/css/panel.css`** (registered via `FilamentAsset::register`,
  republished by `filament:assets` on `composer install`) or it renders **unstyled** — this half
  needs no npm/Vite. **BUT standalone Blade pages OUTSIDE the panel** (the customer portal,
  `resources/views/portal/*`, `welcome.blade.php`) DO use a real **Tailwind v4 + Vite** build:
  `resources/css/app.css` (`@import 'tailwindcss'` + Flux) compiled by `npm run build` →
  `public/build/` (gitignored), pulled in with `@vite`. The deploy runs it (`deploy/update.sh`:
  `npm ci && npm run build`). So full Tailwind + Flux ARE available there — just not in the panel.
- **Pint scope (gotcha).** The tree is **not** fully Pint-clean, so `vendor/bin/pint app/ tests/`
  reformats ~200 **unrelated** pre-existing files and buries your change. **Only Pint the files
  you touched** (pass them explicitly); revert any stray reformats before committing.

## Review discipline — the gate runs until it's GREEN on what matters (not forever)
Every change ends with a **whole-PR adversarial review** (`/code-review`, high effort). The rule
(war-stories that taught it → `docs/CLAUDE-history.md`):

- **Re-run the gate after fixing — until a round comes back with no P0/P1.** A round of fixes is a
  new diff never reviewed (the fix itself often introduces the next bug). The loop ends at **«no
  P0/P1 findings»**, NOT «zero findings» — an adversarial reviewer always finds *something*.
- **Triage + cap the rounds per priority:** **P0** (data loss / legal-document / tenant-leak /
  money wrong) → fix, up to **3** rounds; **P1** (real bug an operator hits) → fix, up to **2**;
  **P2** (edge case, cleanup, perf on data we don't have, docs) → **1** round, then a surviving P2
  goes to `docs/BACKLOG.md` **only if it's costly to rediscover** (a real design gap, a legal/money
  trap, needs the owner/an external party). Small ones stay in the commit message / PR body — if one
  bites later we find it with logs, repro, MCP. Keep the backlog lean. Don't spend a P0-loop on a P2.
- **"I fixed the findings" ≠ "the review passed."** Say which commit was reviewed, how many
  findings came back, and whether the post-fix state was re-checked.
- **Every finding gets an explicit disposition** — fixed, deferred (reason in the commit/PR; costly
  ones in `docs/BACKLOG.md`), or declined with the reason at the call site — legitimate, not a dodge.
- **Sanity-check a fix against real values** (a cheap `php -r` probe beats a plausible diff) and
  **run the full suite before every commit** (it catches regressions the reviewer didn't see).
- **Don't let a fix widen into a new regression — check BOTH directions**, and **fix at the ROOT,
  not the call site** (a second round finding the same bug by another route = go find the duplicate).

**The merge bar is not a perfection bar.** Merge when: strictly better than `main` · no known
P0/P1 · suite green · reversible (no destructive migration). "Has known P2s" is a normal state.

## Changelog + features discipline (keep these current — we were losing track)
Part of "done", like tests. **Every change updates the right place:**
- **`CHANGELOG.md`** (repo root) — the ekdosi **app**. One-liner under `## [Unreleased]`
  ([Keep a Changelog](https://keepachangelog.com/): `Added`/`Changed`/`Fixed`/`Removed`/`Security`).
- **`FEATURES.md`** (repo root) — the catalogue of WHAT ekdosi does. A NEW feature (not a
  fix/tweak) ALSO gets a line here. The «μην χανόμαστε» file. (`docs/BACKLOG.md` is its twin =
  what's left; move an item BACKLOG → FEATURES when it ships.)
- **`whmcs-plugin/ekdosi_bridge/CHANGELOG.md`** — the **WHMCS plugin**. A plugin change BOTH adds
  a line here AND bumps `'version'` in `ekdosi_bridge.php`.
- Keep entries terse; the deep "why" goes to `docs/CLAUDE-history.md`. Don't backfill old versions.
- **Versioning/release mechanics** (`ekdosi:release`, `tag-release.sh`) → **`docs/release-process.md`**.

## Commands
> **Dev AI tooling:** `laravel/boost` (dev-dep) gives the assistant live DB schema / tinker /
> version-correct docs (`.mcp.json`, only under `APP_ENV=local`). Setup: `docs/boost-setup.md`.
> (NOT the in-app AI «Βοηθός» — that's `docs/ai-assistant-blueprint.md`.)

```bash
php artisan ops:health [--json]                         # one-shot deploy check: queue/scheduler/backup/mail/WHMCS/myDATA/disk
php artisan ekdosi:go-live-check --tenant=SLUG [--json]  # per-tenant cutover-readiness gate (read-only; 0/1/2). docs/go-live-runbook.md
php artisan migrate
php artisan shield:generate                              # (re)sync resource permissions after new resources

# ETL — one tenant per legacy DB, re-runnable (needs pdo_firebird on the artisan host)
php artisan migrate:firebird --company="MyIP" --slug=myip \
    --fdb="/opt/Data/ekdosi-myip.fdb" --host=10.23.22.5 --fbuser=EKDOSI --fbpass=<FB_PASSWORD>
php artisan migrate:firebird --dry-run --fdb=... --fbpass=...    # READ-ONLY preflight: μόνο οι συγκρούσεις ΑΦΜ (0/1)
#   ίδιο ΑΦΜ σε δύο legacy CUST_IDs (legacy «υποκατάστημα» hack) → --afm-keep=CUST_ID ανά ΑΦΜ·
#   ο άλλος μπαίνει parked (afm_key NULL, `customers.afm_key_parked`). Η legacy ΔΕΝ πειράζεται ποτέ.
php artisan invoices:recompute-balances --company=myip   # backfill money cache after import

# myDATA ops (also on the scheduler — routes/console.php; safe to run manually anytime)
php artisan mydata:preflight [--tenant=SLUG]             # READ-ONLY config audit vs AADE code tables
php artisan mydata:set-credentials --tenant=SLUG --test  # set sandbox creds (key = hidden prompt), verify
php artisan mydata:test-submit <invoiceId> [--execute]   # dry-run XML / --execute files to AADE
php artisan mydata:reconcile-sales --tenant=SLUG [--raw] # READ-ONLY local↔AADE cross-check / raw XML dump

# WHMCS
php artisan whmcs:fetch-pending --tenant=SLUG            # stage paid+unfiled WHMCS invoices into the inbox
php artisan whmcs:fetch-unpaid --tenant=SLUG            # stage UNPAID invoices of «invoice-before-pay» customers → inbox (manual επί-πιστώσει)

# Support (Πυλώνας E) — scheduler-gated (EKDOSI_SCHEDULE_TICKETS_POLL_IMAP, default OFF); safe to run manually
php artisan tickets:poll-imap [--tenant=SLUG]           # poll department mailboxes (IMAP) → route inbound email into tickets. Verify a mailbox: «Test σύνδεσης» in the panel or the MCP `support_imap` tool (test=true).
```

## Deliberately dropped (verified absent in new code — do not resurrect)
- **CS-Cart bridge** (`FCSConnect`/`FManageCS*`, `CUSTCS_LINK`, `CUSTOMER_CS_ACCEPTED`, the cipher
  key) — never actually used.
- `EAFDSS_SCRIPT` (pre-myDATA ΕΑΦΔΣΣ), FastReport `.fr3` (→ Blade PDF).
- `FMysqlSync` MySQL mirror push (→ WHMCS API).
- `GET_COMB_*` procedures — cross-DB `EXECUTE STATEMENT` to a hardcoded `.fdb` with SYSDBA/masterkey
  inline. **Security landmine — never carry the creds over.**
- `afm2name` WHMCS-side GSIS plugin — ekdosi does GSIS natively (`AadeRegistryLookup`).

---

## The VAT / discount / rounding math (canonical — we port this exactly)
Lives in legacy `FAddInvoice.cpp::showSums()` (~line 269) + `FAddInvoice2.cpp::calcPrices()`.
```
# Per line:
PRICE     = QTY * PRICE_PER_ITEM
PRICE     = PRICE - PRICE * (DISCOUNT_line / 100)        # line-level discount
PRICEWVAT = PRICE * (1 + VATPERCENT / 100)

# Per invoice header (line sums + invoice-level DISCOUNT %):
priceWOutVat      = SUM(line.PRICE)
priceSumWVat      = SUM(line.PRICEWVAT)
invoice.PRICE     = priceWOutVat - priceWOutVat * (DISCOUNT_inv / 100)
invoice.PRICEWVAT = priceSumWVat - priceSumWVat * (DISCOUNT_inv / 100)
invoice.VATtotal  = invoice.PRICEWVAT - invoice.PRICE    # derived, not stored

# Gross-edit path (operator types a gross unit price):
PRICE_PER_ITEM = PRICE_PER_ITEM_WVAT / (1 + VATPERCENT / 100)   # ✅ wired (G7); MON-7 warns on the 2dp round-trip cent-loss

# Withholding (FAddInvoice.cpp:819): WITHHOLD_AMOUNT = invoice.PRICE * 0.20  (flat 20%, ΠΚ-3)
```
**Rounding subtlety:** legacy used Borland `TCurrency` (4dp) in-form but `DECIMAL(14,2)` columns —
values rounded to 2dp **on write**, not per intermediate. In PHP: high-precision math, `round($x,2)`
ONLY when assigning to the model attribute, never between sub-sums. New home:
`App\Services\RecomputeInvoiceTotals` + `InvoiceVatBreakdown`. Golden-test after import (README
query); expect a few off-by-€0.01 invoice-discount rows.

**Key stored-proc semantics ported:**
- `GET_INV_CODE` = `INVTYPE.code || INVCOUNT` (e.g. `APY423`), no zero-pad.
- `CALCULATE_VAT_FOR_INVOICE` = per-VAT-rate breakdown WITH invoice-level discount applied →
  `InvoiceVatBreakdown` (feeds myDATA per-rate VAT).
- `GET_CUSTOMER_BALANCE` = only `payment_methods.due_days > 0` invoices count toward balance
  (cash-term settled at issue) → `InvoiceBalance` + Καρτέλα.

---

## myDATA (the core integration)
Use `firebed/aade-mydata` (transport + XML + types). We own only the mapping from our
`Invoice`/`InvoiceLine` → its payload; semantic equivalence, validated field-by-field. firebed
uses **static credential state** (`MyDataRequest::init()`) — fine for FPM + sequential queue
workers; a contention point under Octane.

- **SUBMIT payload shape** (the accepted `SendInvoices` field-by-field, each rule tied to the AADE
  error it clears, sandbox-validated) → **`docs/mydata-submit-payload.md`**. Short version: issuer
  = AFM+GR+branch0; GR counterpart present for B2B with NO name/address, omitted for retail; per-line
  net/vatCategory/vatAmount + E3 classification, NO `<quantity>` for services; summary carries the
  five zero tax-total fields; NO `<uid>`, NO `<taxesTotals>`; credit notes correlate only for 5.1.
- **OPEN OPERATOR DECISION:** myip's ΠΙΣ maps to **5.2** (non-correlated) but the new
  `IssueCreditNote` flow always issues *from* an original, so **5.1** (correlated) is the natural,
  sandbox-proven choice. Set the ΠΙΣ `mydata_type` accordingly — the code handles both.
- **Code tables + preflight:** `App\Support\MyData\Codes` bakes the §8 tables (invoice types, VAT
  categories + exemptions, payment methods, income classification, withholding, quantity) with
  validation helpers — the single source to refresh on spec changes. `php artisan mydata:preflight`
  audits each tenant's config against them (read-only, exit 0/1/2).
- **Reconciliation** (Phase 1 local `MyDataReconciliation`; Phase 2 live `SalesReconciler` /
  `MyDataConsole` / `mydata:reconcile-sales`; inbound expense `ExpenseReconciler`) → `FEATURES.md §4`.
  Orphans bucketed by economic type (`Codes::transmittedDocBucket()`), consoles cache last fetch
  (`RemembersLastFetch`, 12h), MARK detail is direction-aware.

---

## Money: payments + credit notes
- **Payments** (`Payment` model, `PaymentObserver`, `Payments` resource): per-invoice or on-account
  (invoice_id null). `InvoiceBalance` derives `owed = gross − credited`, `balance = owed − paid`,
  `payment_status` (`App\Enums\PaymentStatus`); cash-term (`due_days=0`) = paid at issue. **Amount is
  always a positive magnitude — `kind` (payment/refund) carries the sign** (MON-8).
- **Credit notes** (`App\Actions\IssueCreditNote`): mirrors `CreateInvoice` — allocates ΑΑ, creates
  a credit-type invoice with `credited_invoice_id` + POSITIVE lines, writes `return_invoice_extras`,
  recomputes both. Stored with POSITIVE gross (negatives break `InvoiceVatBreakdown`); the reduction
  is the original's `credited_total`. myDATA filing is **opt-in** (modal toggle, default OFF).
- **Cross-surface consistency** is guarded by `MoneyStatusConsistencyTest` (dashboard receivables ==
  Σ ledger balances == per-invoice caches). Re-run it whenever a money surface or cache path changes.

## Invoice lifecycle (`local_status` × `mydata_state`)
`ViewInvoice` actions: Οριστικοποίηση (draft→active), Επαναφορά σε πρόχειρο, Ακύρωση (→cancelled;
detaches payment to on-account credit; works on filed invoices but then surfaces the ⚠
reconciliation row), Επαναφορά (blocked when `mydata_state=CANCELLED` — AADE cancel is terminal).
`MyDataSubmitter` syncs `local_status` at its own persist/cancel choke-point. `EditInvoice` is
editable only while draft.

## Customer portal + payment gateways (Πυλώνας B — BUILT)
Customer-facing portal (`/user`, `resources/views/portal/*`, guard `portal`): «Τα παραστατικά μου»,
«Η καρτέλα μου» (`CustomerLedgerFeed`), profile. Gateway seam: `App\Contracts\PaymentGateway` (+ opt-in
`HostedRedirectGateway`, `WebhookGateway`, `HasSecretConfig`), `PaymentGatewayRegistry` (config-driven,
Null fallback). `EurobankGateway` (Cardlink vPOS `shophandlermpi`, redirect + digest-verified return)
is the live B1 — validated on the real acquirer. `PaymentIntentService::start()/settle()` = the
idempotent money write (pending/expired → settled, stamps intent + channel method + txn id);
`PaymentAllocator` (FIFO + invoice-targeted). Portal payments log to «Log πύλης» (`PaymentGatewayEvent`),
ring the operators' bell on unattended settle, get a receipt PDF. Design/threat model:
`docs/payment-gateways-design.md`; B2 (PayPal/Stripe) research `docs/payment-gateways-b2-paypal-stripe.md`.

## WHMCS bridge (built)
Operator-gated **draft-first inbox** (NOT auto-issuing — invoices are legally significant): WHMCS
push/poll → ekdosi webhook → `pending_whmcs_invoices` → operator «Δημιουργία Παραστατικού» (editable
draft) → issue via the normal lifecycle (→ myDATA) → write-back `invoiced=MARK`. The inbox never
files straight to AADE; the direct `file()` path survives only for `whmcs:auto-issue`. Services:
`WhmcsClient` (+factory), `WhmcsInvoiceIngestor`, `WhmcsInbox\*`, `WhmcsWritebackService`,
`WhmcsBridgeClient`; webhooks in `routes/webhooks.php`. **`whmcs-plugin/ekdosi_bridge/`** is OUR
WHMCS-side plugin. Full feature list `FEATURES.md §11`; third-party design
`docs/whmcs-legacy-plugin-map.md`; connectors `docs/bridges-connectors.md`.

**Locked decisions (don't re-litigate — full list in `docs/whmcs-legacy-plugin-map.md`):**
- **HMAC both sides** (`X-Webhook-Signature: sha256=<hex>` over raw body / `"{slug}:{id}"` string).
  Body is `{"whmcs_invoice_id":N}` only — fetch the canonical payload via our API creds (never trust
  full invoice data over the webhook).
- **The MARK lives in our own `mod_ekdosi_invoice_marks`; the bridge NEVER writes
  `tblinvoices.invoiced` at runtime** (legacy SMALLINT {0,1} flag — widening it broke the legacy app).
- **Deterministic links only.** `tblinvoices.invoiced === invoices.legacy_id` is an EXACT FK →
  `whmcs:backfill-invoice-ids`. The heuristic content-matcher was BUILT then REMOVED — never guess a
  legal link; forward-only `whmcs_pending_id` for new ones.
- **Plugin-API is THE path.** ekdosi → WHMCS via the plugin's `resolve.php` (HMAC, logged to «Bridge
  logs»); native `WhmcsClient` only for plugin-less tenants. `GetInvoices` paginates via
  `limitstart/limitnum` (NOT limit/offset — THE bug that froze the inbox at 16).
- **VAT net-vs-gross is DETECTED** from the payload, not guessed; `whmcs_amount_includes_tax` is only
  the no-breakdown fallback. **Doc-type is PER PARTY** (`WhmcsInvoiceSplitter`), not per WHMCS-invoice.
- **UI term «Άμεση τιμολόγηση»** (ex-«γκρινιάρης»). The role key `griniaris` is RETAINED (tenant
  field-map contract) — rename only operator-facing strings, never the role key or its columns.

---

## Don't-re-port / don't-re-litigate (a few worth keeping inline)
- **Stock movements & ΣΔΕΠ/cumulative were DEAD CODE in legacy** (`CHECK_PROD_AVAILABILITY` empty,
  `findCumInvoiceDate()` returns 0). Net-new IDEAS, not a port we're behind on — build only if the
  prod `.fbk` proves real usage (`docs/go-live-usage-checks.sql.md`).
- **Delivery notes (Ψηφιακό ΔΑ): firebed already implements the whole v2.0.x tracking API** — don't
  re-port the protocol, only wire it. Issue+cancel → provider; movement lifecycle → direct myDATA for
  everyone. Full model: `docs/archive/delivery-provider-split-brain.md`.
- **Credit notes**: legacy `CREATE_RETURN_INVOICE` was an empty stub → `IssueCreditNote` is a clean
  reimplementation, not a risky port.

## Known latent items + key tenant-safety behaviors
Open items → `docs/BACKLOG.md` (roadmap; decided-don't-reopen → «Guardrails»); the «why» →
`docs/CLAUDE-history.md`. The behaviors below are how the system actually works:
- **Global `BelongsToCompany`/`CompanyScope` (no-op mode).** Tenant-owned models carry a
  `CompanyScope` driven by the ambient `CompanyContext` singleton (Filament sets it on `TenantSet`),
  so raw `Invoice::where(...)` in the panel auto-filters. **No-op when no context** (CLI/queue) →
  existing explicit `->where('company_id', …)` paths unchanged. **The CLI/queue rule for ANY new
  command/job/observer/webhook that touches a tenant-owned model: pick ONE of —** (a) explicit
  `->where('company_id', …)`, (b) `CompanyContext::actAs($company, …)`, or (c) a deliberate all-tenant
  sweep → `->withoutGlobalScope(CompanyScope::class)` to DECLARE the intent. A full audit (2026-06-11)
  found **0 live leaks** across ~54 entry points, so the no-op default is load-bearing AND correct.
  Strict null→throw stays deferred — naive flip breaks ~18 safe explicit-where paths, and an
  execution-time tripwire false-positives on relation/eager-load FK queries (guardrail in
  `docs/BACKLOG.md`; full original note: `git show 631078d:docs/BACKLOG.md` «Strict tenant scope»).
- **Activity log** (`TracksActivity` on Invoice/Customer/Payment): `logOnly(loggedAttributes())` —
  business columns only, NEVER the money/myDATA CACHE columns; `logOnlyDirty()` +
  `dontLogEmptyChanges()`. v5 stores the diff in **`attribute_changes`** (not `properties`); causer
  null = «Σύστημα». `activity_log.company_id` powers the tenant-wide `ActivityFeed`.
- **Per-tenant roles** (`super_admin`/`company_admin`/`operator`, `TenantRoleProvisioner`):
  `company_admin` = every tenant permission EXCEPT `ADMIN_FORBIDDEN_RESOURCES` (User/Company/Role);
  `operator` = explicit `OPERATOR_PERMISSION_MAP`. Role management is super_admin-only; screens gate
  via `Gate::can` (missing → false). **After deploy: `shield:sync-super-admin`.** company_admin
  self-service settings = the **`CompanySettings` page** (SAFE whitelisted subset, NEVER raw
  mass-assign); credential/infra knobs stay on the super_admin-only CompanyResource. New page perm →
  `shield:generate` + re-provision post-deploy.
- **GuardedDeleteAction** ✅ blocks deleting an in-use lookup (single-record, withTrashed-aware);
  **customers**' permanent delete is guarded in the model (`Customer::hardDeleteBlockers()`, every
  path) + the bulk «Οριστική διαγραφή» skips in-use rows; other resources' bulk/force-delete
  unguarded. `TenantScopedUnique` = non-issue (DB has the `unique(company_id,…)` constraints).
- **Gotchas:** ETL re-run re-applies legacy values to a soft-deleted row (deleted_at stays —
  force-delete to truly drop). Row-lock tests (`InvoiceNumberer`, `InvoiceBalance::recompute`) are
  MariaDB-only (can't run on sqlite/CI).

## Env-prep (deploy host)
> **Don't re-derive deploy state by hand** — run **`php artisan ops:health [--json]`** (queue,
> scheduler, backups, mail, WHMCS, myDATA, disk in one shot; `docs/operator-health.md`). Full
> provisioning → **`INSTALL.md`**; deploy routine → **`docs/updates-runbook.md`** (or just
> `deploy/update.sh <tag>`, with `deploy/rollback.sh` + `ekdosi:db-snapshot`/`db-restore`).

- **Scheduler + queue worker are PROVISIONED on prod** (systemd + cron): `schedule:run` every minute
  + a `queue:work` service. So the scheduled features (backups + alerting, auto-email, myDATA
  reconcile, WHMCS fetch/auto-issue, VAT picture) DO fire — each gated by its `config/ekdosi.php`
  `EKDOSI_SCHEDULE_*` flag. `withoutOverlapping` needs a working cache driver (DB driver → `cache`
  tables migrated). After editing `config/ekdosi.php` on a config-cached deploy: `config:clear`.
  Local/CI without cron + worker stays inert until both are started.
- **`pdo_firebird`** — only the ETL/artisan host needs it (`ondrej/php` PPA or `firebird-dev`).
- **`ext-soap`** — for the GSIS lookup only; sandbox `composer install` → `--ignore-platform-req=ext-soap`.
- **`gbak`** in PATH — only for `.fbk` restores. **PHP upload limits** (fpm AND cli php.ini) above
  the largest `.fbk`; `TMPDIR` disk-backed (not tmpfs) for big restores.

## Reading the legacy source (now offline-backup only — see repo-layout note)
C++Builder (VCL): `.cpp`/`.h`/`.dfm`, IBX (`TIBQuery`/`TIBTransaction`), cxGrid, JVCL. Grep for
`AsCurrency`/`AsFloat` and `*BeforePost`/`*AfterPost`, not Pascal idioms. Files are **WIN1253** —
`iconv -f WINDOWS-1253 -t UTF-8 FAddInvoice.cpp`. Some shared headers (`CMyData.h` etc.) were never
in this repo — `firebed` replaces all of `CMyData`. File map: `FAddInvoice*`/`FEditInvoice` =
issue/edit + VAT math; `FAutoInvoice` = overnight batch; `FInvoiceReturn` = credit notes; `FShow*` =
lists; `FManage*` = lookup CRUD. (Grab the source from the offline backup when you need it.)

## Sandbox the legacy DB before touching prod
The legacy `ekdosi.fbk` gbak now lives in the **offline backup** (was `/legacy/…`, removed for secrets
hygiene). Restore it as `gbak -r ekdosi.fbk fresh.fdb -user SYSDBA -password <sandbox-password>` (the
Firebird install default on a fresh sandbox) and point the ETL at the restored `.fdb`. Re-runs upsert
on `(company_id, legacy_id)`; Filament-created rows (`legacy_id` null) are never touched; deleted-from-
source rows are left alone (never auto-deleted).
