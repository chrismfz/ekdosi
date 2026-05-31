# Roadmap: Υπηρεσίες/Συμβόλαια (recurring) + Προσφορές (quotes)

> **Status: NOT STARTED — design/roadmap only.** This is a planned future
> capability, captured here so the design isn't lost. Nothing in it is built.
> Implement as small, reviewable PRs.
>
> **BUILD ORDER (revised):** **Quotes first**, then Services. Quotes are the
> easier, higher-reuse feature (fork Invoices/mail) and ship fully functional
> with only `ConvertQuoteToInvoice` — the *single* coupling to Services is
> `ConvertQuoteToServiceContract` (PR 2.5), which we **defer** until Services
> land (its "Μετατροπή σε Υπηρεσία" button stays hidden until then). Services
> are stubbed in the meantime: only the additive `products` recurring columns
> are reserved (inert) — no resource, scheduler, or provisioning yet. The
> "hardcore" recurring/provisioning engine is intentionally last.

## Why

The smaller tenant (Nexon) sells **support contracts / services** that are
either one-time or recurring (monthly … biennial), and sends **quotes
(προσφορές)** before issuing real invoices. Neither concept exists today
(verified by code scan): "services" are just `Product` rows with a description,
and the WHMCS bridge is invoice-only. Two connected, net-new features:

1. **Υπηρεσίες / Συμβόλαια** — WHMCS-like recurring services with customer,
   amount, billing cycle, start/end dates, status (Pending/Active/Suspended/
   Cancelled/Terminated), tracking ("τι λήγει / τι απομένει") and operator-gated
   renewal **staging** (never auto-file at AADE).
2. **Προσφορές** — looks like an invoice (customer, lines, free text) but is
   **NOT** a legal document, **NOT** myDATA-filed, and **NEVER** counts toward
   money/καρτέλα/ΦΠΑ. PDF + email with send-log. States pending →
   accepted/rejected/changes_requested. On accept (two-step), **convert** to a
   real Invoice (Epsilon-Smart-style "μετατροπή σε παραστατικό") **or** to a
   recurring Service.

## Locked decisions

- **Separate tables** for quotes/services — NOT the `invoices` table.
- Recurring → invoice is **operator-gated** (stage a draft, never auto-AADE).
- Renewal invoice type is **per-contract/service**.
- Quote email keeps a **send-history** (like invoices).
- Quote → invoice is **two-step** (Αποδοχή, then a separate Μετατροπή).
- **Provisioning is native ekdosi, fully WHMCS-independent** — see below.

### Why separate tables (verified in code)
`app/Support/InvoiceScope.php::live()` is the single money predicate, keyed
purely on `invoices.mydata_state`/`local_status` (columns that exist *because*
every `invoices` row is legal). `InvoiceBalance`, καρτέλα, dashboard,
reconciliation, `InvoiceVatBreakdown` consume `invoices`/`invoice_lines`
unconditionally. A non-legal quote in that table would need a defensive
`where('is_quote', false)` at *every* call site — exactly the predicate drift
`InvoiceScope` was written to prevent. Separate tables = zero blast radius.
`InvoiceNumberer` is bound to `invoice_types.invcount` (the ΑΑ continuity
counter) — quotes must **never** touch it.

---

> **NOTE (revised order):** Despite the heading numbers below, we now build
> **Phase 2 (Quotes) FIRST**, with PR 2.5 (convert→service) deferred. Phase 1
> (Services) follows. The phase content is unchanged — only the build order is.

## PHASE 1 — Υπηρεσίες / Συμβόλαια (built SECOND)

### Data model
**Migration A — extend catalog `products` (additive)** (precedent: existing
`is_active`/`whmcs_product_id` forward-looking columns):
`is_recurring` bool default false, `default_billing_cycle` varchar(20) null,
`default_recurring_amount` decimal(14,2) null, `recurring_invoice_type_id`
FK→invoice_types null, `provisioning_module` varchar(40) default 'none',
`default_suspend_after_days` / `default_terminate_after_days` unsignedSmallInt null.

**Migration B — `service_contracts`** (the instance/subscription):
`id, legacy_id, company_id` (BelongsToCompany), `customer_id` (FK restrict),
`product_id` (FK null), `invoice_type_id` (FK null — snapshot from product,
override per contract), `description`, `amount` decimal(14,2), `vat_percent`
decimal(5,2), `billing_cycle` varchar(20), `status` varchar(20), `start_date`,
`next_due_date` null, `end_date` null, `last_invoiced_at` null, `cancel_reason`
null, `notes` null, `whmcs_service_id` null (legacy slot only).
Modules/dunning provision: `provisioning_module` varchar(40) default 'none',
`module_meta` json null, `server_id` FK null, `suspended_at` null,
`terminated_at` null, `suspend_after_days` / `terminate_after_days` null.
Indexes: `(company_id, status, next_due_date)`, `(company_id, customer_id)`.
timestamps + softDeletes.

**Migration C — provenance:** `invoices.service_contract_id` FK null restrict
(the staged draft points back; idempotency).

**v1: no `service_contract_lines`** — one recurring charge per contract;
multi-line is an additive follow-up.

### Enums + logic
- **`app/Enums/BillingCycle.php`** (string): `OneTime, Monthly, Quarterly,
  SemiAnnual, Annual, Biennial`; `advance(CarbonInterface): ?Carbon` using
  `addMonthsNoOverflow`/`addYearsNoOverflow` (31 Jan +1m → 28/29 Feb landmine);
  OneTime → null.
- **`app/Enums/ServiceContractStatus.php`**: `Pending, Active, Suspended,
  Cancelled, Terminated`. Machine: Pending→Active (sets next_due_date=start_date
  if null); Active→Suspended (overdue/operator, keeps next_due_date);
  Suspended→Active (after payment); Active/Suspended→Cancelled (clears
  next_due_date, keeps row); Active/Suspended→Terminated (end_date/overdue/
  operator, terminal); Cancelled→Active (revive); Terminated terminal.
  `suspended_at`/`terminated_at` stamped on transition.
- `next_due_date` advancement is owned by the **StageServiceRenewal action**
  (not a model mutator) — same discipline as `InvoiceNumberer` owning ΑΑ.

### Model + Filament resource
`app/Models/ServiceContract.php` (BelongsToCompany, SoftDeletes, casts,
relations, `scopeDue($q,$asOf)`). `app/Filament/Resources/ServiceContracts/` —
top-level nav `Υπηρεσίες`, `navigationSort=2`, `getNavigationBadge()` = count of
`active AND next_due_date <= today()+lead` (warning) **with** the
`canAccess(): bool => auth()->check()` Shield-bypass (the WhmcsInbox 404-storm
fix). Customer/product autofill lifted from `InvoiceForm`. View actions (style of
`ViewInvoice`): Ενεργοποίηση / Ακύρωση / Τερματισμός / Επαναφορά / **Δημιουργία
παραστατικού τώρα**.

### Renewal staging (operator-gated)
`app/Actions/StageServiceRenewal.php` — given a due contract, create a **draft
Invoice** (no AADE submit). Pattern: `WhmcsInvoiceFiler::createDraft()` +
`IssueCreditNote`: `DB::transaction` + `InvoiceNumberer::allocate` under lock →
Invoice + InvoiceLine, `local_status='draft'`, customer snapshot →
`RecomputeInvoiceTotals`; type = `contract->invoice_type_id` (loud Greek error if
missing); provenance `invoices.service_contract_id`; advance `next_due_date` +
stamp `last_invoiced_at` in the same transaction; idempotent per period.
`app/Console/Commands/StageServiceRenewals.php` (`services:stage-renewals`) +
scheduler in `routes/console.php` + `config/ekdosi.php` flag
`EKDOSI_SCHEDULE_SERVICE_RENEWALS` (cron `0 7 * * *`, lead-days default 7,
`withoutOverlapping()`).

### Provisioning + Dunning — native, **WHMCS-INDEPENDENT** (logic now, modules later)
Architecture: provisioning is **ours**, fully independent of WHMCS. We will
later connect **directly** to servers/APIs (mailcow, cPanel, DirectAdmin,
antivirus reseller, **license_server** — Nexon sells software → our own
license module instead of WHMCS+license addon) with our own credentials. The
WHMCS bridge stays strictly a **transitional invoice source**; no part of
Services/Modules depends on it. `whmcs_service_id` is a legacy-correlation slot,
not the provisioning mechanism.

- **Fully modular, open (not an enum):** `provisioning_module` is a **free-form
  key + registry**, so a new module is one config line, no migration/enum change.
  Empty at first — only `none`/`custom` wired.
- `app/Contracts/ProvisioningModule.php` (interface):
  `create/suspend/unsuspend/terminate(ServiceContract)`, `key()`. Takes our
  **Server** (below), never WHMCS. Modules declare their own config via `meta`
  json so no schema change per module.
- **Servers as reserved schema (now, used later):**
  - `server_groups`: `id, company_id, name, module` (group default) `, notes`,
    group-level credentials (`username`, `secret_encrypted` Laravel `encrypted`
    cast, `meta` json), timestamps+softDeletes.
  - `servers`: `id, company_id, server_group_id` (FK null) `, name, module`
    (override), `hostname, api_endpoint, username, secret_encrypted` (encrypted),
    `is_active, meta` json, timestamps+softDeletes.
  - Credentials resolve **per-server with fallback to group** (WHMCS-style,
    without WHMCS). `service_contracts.server_id` → which of our servers hosts it.
  - v1: plain CRUD lookups (empty); no API calls.
- `app/Services/Provisioning/NullProvisioningModule.php` (`key()='none'`): no-op
  (local state change only). The only wired module in v1. `'custom'` = operator
  handles manually.
- `app/Services/Provisioning/ProvisioningModuleRegistry.php`: `for($key)` → Null
  for none/custom/unknown; config-driven map (`config/ekdosi.php →
  provisioning.modules`). Same shape as the existing `EInvoiceSubmitter` factory.
  Unknown key = Null + log warning, never crash (forward-compatible).
- **Dunning as design (not built in v1):**
  `app/Console/Commands/RunServiceDunning.php` (`services:run-dunning`): for each
  active/suspended contract with an overdue linked invoice (via
  `invoices.service_contract_id` + `InvoiceBalance`/`payment_status`): overdue >
  `suspend_after_days` → Suspended + `module->suspend()`; > `terminate_after_days`
  → Terminated + `module->terminate()`. Flag `EKDOSI_SCHEDULE_SERVICE_DUNNING`
  **default OFF**. With the Null module the transitions are local/no-op.
- **v1 deliverable here:** interface + Null module + registry + servers schema +
  columns/states + state machine with Suspended. **No** real module, **no** live
  server API, **no** active dunning in production.

### Phase 1 PRs
- **1.1** Migrations A+B+C, enums (incl. Suspended), ServiceContract model+factory,
  unit tests (`advance()` overflow + full state machine). No UI.
- **1.2** ServiceContractResource (CRUD+view), autofill, table/filters, nav badge.
- **1.3** StageServiceRenewal + provenance + "Δημιουργία παραστατικού τώρα";
  feature test (due→draft, next_due advanced, idempotent).
- **1.4** StageServiceRenewals command + scheduler/config; feature test (no AADE).
- **1.5** ProvisioningModule interface + Null module + registry +
  `server_groups`/`servers` migrations + `service_contracts.server_id` + simple
  Server/ServerGroup resources (encrypted creds) + Suspend/Unsuspend/Terminate
  actions calling the (Null) module; `RunServiceDunning` (flag-OFF) + unit test.

---

## PHASE 2 — Προσφορές (built FIRST; only PR 2.5 depends on Phase 1)

### Data model
**Migration D — `quotes`:** `id, legacy_id, company_id` (BelongsToCompany),
`customer_id` null, `code` (own counter), `status` varchar(20)
(pending/accepted/rejected/changes_requested), `issued_at`/`valid_until` date,
`header_discount_percent` decimal(5,2) default 0, customer-snapshot block
(company_name, vat_no, vies_vat, occupation, address1/2, city, postcode,
country), `net_total`/`gross_total` decimal(14,2) (cache from QuoteTotals),
`notes`, `converted_invoice_id` FK→invoices null,
`converted_service_contract_id` FK→service_contracts null, `rejection_reason`
null, timestamps+softDeletes.

**Migration E — `quote_lines`:** mirror the editable subset of `invoice_lines`
(`quote_id, company_id, product_id` null, qty, price_per_item, discount,
vat_percent, product_descr, metric_unit, notes, net_price, gross_price). A
`QuoteLine::saving` hook computes net/gross by copying InvoiceLine's ~15-line
formula (NOT a shared class).

**Quote numbering — NOT InvoiceNumberer.** `app/Services/QuoteNumberer.php`: same
technique (transaction + lockForUpdate + raw increment) on its OWN counter
(`companies.quote_counter` or a dedicated row) — never `invoice_types.invcount`.

### Reuse vs fork
| Existing | Action | Why |
|---|---|---|
| `InvoiceForm` repeater + customer autofill | **Fork** → `QuoteForm` | drop withholding/myDATA/delivery/type |
| `RecomputeInvoiceTotals` | **New `QuoteTotals`** | no balance/myDATA coupling; same rounding |
| `InvoiceVatBreakdown` | **Fold** into QuoteTotals footer | per-rate for PDF only |
| `InvoiceNumberer` | **New `QuoteNumberer`** | must not touch ΑΑ |
| `InvoicePdfRenderer` + `invoices/pdf.blade.php` | **New `QuotePdfRenderer` + `quotes/pdf.blade.php`** | reuse logo/ini technique; "ΠΡΟΣΦΟΡΑ" header, no QR/MARK |
| Mail stack | **Parallel** `SendQuoteEmail` + `QuoteOfferMail` + `quote_mail_logs` + relation manager | reuse `TenantMailerFactory`+`MailTemplateRenderer` unchanged |
| `IssueCreditNote` shape | **Template** for `ConvertQuoteToInvoice` | same lock→allocate→create→recompute |

**No Document-interface generalization** of totals/PDF/VAT in v1 — they're deeply
welded to Invoice semantics (InvoiceBalance, header-discount legal range, AADE
golden-parity rounding). Forking is cheaper and safer.

### Conversion actions (two-step)
- `app/Actions/ConvertQuoteToInvoice.php` (template: IssueCreditNote +
  WhmcsInvoiceFiler::createDraft): guard `status='accepted'`, refuse if
  `converted_invoice_id` set (idempotency); `DB::transaction` → lockForUpdate
  quote → `InvoiceNumberer::allocate` → Invoice (snapshot party + header_discount,
  `local_status='draft'`) → InvoiceLines from quote_lines → set
  `converted_invoice_id` → RecomputeInvoiceTotals. **No AADE** — operator issues
  via the existing lifecycle. Invoice type chosen in the convert modal.
- `app/Actions/ConvertQuoteToServiceContract.php` — create a ServiceContract
  (pending/active) from the quote (amount + cycle in modal), set
  `converted_service_contract_id`; feeds Phase 1 staging.

### Filament resource
`app/Filament/Resources/Quotes/` — top-level `Προσφορές`, `navigationSort=3`,
`canAccess` Shield-bypass. Pages List/Create/Edit(gated pending)/View +
`QuoteLinesRelationManager` (fork). View actions: Αποδοχή / Απόρριψη(reason) /
Αίτημα αλλαγών / **Μετατροπή σε Παραστατικό** (accepted & not converted) /
**Μετατροπή σε Υπηρεσία** / Download PDF / Email.

### Phase 2 PRs
- **2.1** Migrations D+E, Quote+QuoteLine models (+saving math)+factories,
  QuoteTotals+QuoteNumberer, unit tests (line math parity, numberer lock test).
- **2.2** QuoteResource CRUD + QuoteForm + QuoteLinesRelationManager + status actions.
- **2.3** QuotePdfRenderer + blade + download; SendQuoteEmail + QuoteOfferMail +
  quote_mail_logs + relation manager.
- **2.4** ConvertQuoteToInvoice + provenance + view action. **Critical regression
  test:** accepted quote → draft invoice, idempotent, **quote totals NEVER in
  `InvoiceScope::live()`/balance/dashboard/καρτέλα**.
- **2.5** ConvertQuoteToServiceContract + view action (depends on Phase 1).

---

## Risks / blast radius
- **Near-zero on the money path:** only additive columns on `invoices`
  (`service_contract_id`) and `products` (recurring metadata). No change to
  InvoiceScope/InvoiceBalance/RecomputeInvoiceTotals/InvoiceVatBreakdown/dashboard.
- **ΑΑ-counter contamination** (biggest landmine): QuoteNumberer must never touch
  `invoice_types.invcount` — a test asserts it.
- **next_due_date double-advance:** advance inside the same tx + `last_invoiced_at`.
- **month overflow:** `...NoOverflow` + explicit tests.
- **Conversion idempotency:** refuse if `converted_invoice_id` set.
- **Missing renewal invoice type:** loud Greek error at stage time.

## Explicitly OUT of v1
Real native modules (Mailcow/cPanel/DirectAdmin/antivirus/license — direct API)
and live server API calls (keep interface + Null module + registry +
servers/server_groups schema + columns + Suspended state, but no live API);
active production dunning (command exists, flag default OFF, Null module only);
auto-issue of renewals to AADE (staging only); WHMCS service/recurring ingestion
(bridge stays invoice-only); multi-line service contracts; polymorphic mail log;
Document-interface generalization.

## Verification (when built)
- Unit: BillingCycle::advance (month-end overflow), state machines, QuoteLine/
  QuoteTotals rounding parity (cf. `tests/Unit/GrossPriceConversionTest.php`,
  `tests/Feature/InvoiceVatBreakdownTest.php`).
- Conversion Feature tests: quote→draft-invoice (correct lines+snapshot,
  idempotent, no 2nd ΑΑ — cf. `IssueCreditNoteTest`); renewal staging.
- **Critical isolation test** (names the separate-tables guarantee): a pending
  quote + a service_contract leave `InvoiceScope::live()`/`InvoiceBalance`/
  dashboard/καρτέλα **identical** to without them (cf. `MoneyStatusConsistencyTest`).
- MariaDB-only lock tests for QuoteNumberer + StageServiceRenewal/
  ConvertQuoteToInvoice lockForUpdate (gate like `InvoiceNumbererTest`).
- Filament smoke + **Livewire-level** filter/tab tests (cf.
  `ExpensesListFiltersTest` — the closure-param `$query` lesson).

## Reference / fork sources
- `app/Actions/IssueCreditNote.php` — canonical lock→allocate→create→recompute
- `app/Services/WhmcsInbox/WhmcsInvoiceFiler.php` — stage→draft+provenance+idempotency
- `app/Services/InvoiceNumberer.php` — ΑΑ allocator (reuse for invoices; NOT quotes)
- `app/Support/InvoiceScope.php` — money predicate (the blast-radius boundary)
- `app/Filament/Resources/Invoices/Pages/ViewInvoice.php` + `Schemas/InvoiceForm.php`
- `routes/console.php` + `config/ekdosi.php` — scheduler/flag pattern
- `app/Services/EInvoice/*` (EInvoiceSubmitter factory) — registry pattern for modules
