# Backlog / Roadmap — deferred ideas

Cross-cutting TODOs that are **deliberately not built yet**. Captured so they
don't get lost in drift. (Per-feature plans live in their own docs:
`services-quotes-roadmap.md`, `expenses-phase-plan.md`,
`paroxos/` (regulatory-blueprint.md + implementation-plan.md). The myDATA-filing gaps + tech-debt list live in
`CLAUDE.md`.)

---

## UX — customer/product pickers: tags + favourites-first dropdown

> **UPDATE 2026-06-02 — favourites-first SHIPPED (boolean, not tags).** After
> the accountant walkthrough, the core ask landed on the **invoice form**:
> a per-row `is_favorite` boolean on `invoice_types` / `customers` / `products`
> (inline ⭐ ToggleColumn + «Αγαπημένα» filter in each list), and the three
> pickers now show **favourites first, then auto-top (most-used)** on open,
> before falling through to the normal search-on-type (`InvoiceForm::
> {invoiceType,favouriteCustomer,favouriteProduct,searchCustomer,searchProduct}
> Options()`). Also shipped in the same slice: **inline product/service create**
> from the line picker, the **Είδος → Σκοπός/τρόπος-πληρωμής/αποστολής
> auto-fill**, the **«Νέο Παραστατικό» button on the Καρτέλα** (reverse flow,
> `?customer_id=` preset), and **full Greek labels** on the invoice form.
> **Still deferred below:** the «Show all / browse beyond search» affordance.
>
> **UPDATE 2026-06-02 (b) — tags + QuoteForm SHIPPED too.** QuoteForm's
> customer + product pickers now share the favourites-first providers
> (`App\Filament\Support\PickerOptions`, used by both Invoice + Quote forms).
> And the **tags system landed**: tenant-scoped `tags` + `taggables` morph
> pivot (custom, not spatie), `App\Models\Concerns\HasTags` on Customer /
> Supplier / Product / Invoice, a `TagResource` (Setup) to manage the
> vocabulary + pin tags, and one shared `App\Filament\Support\Tags\TagControls`
> giving every list a **multi-select tag filter** + **Έξοδα-style fast-filter
> tabs** for *pinned tags that are actually used on that entity* + a badge
> column, plus a **bulk «Ετικέτες» action** on Invoices (to tag filed
> invoices that can't be edited via the form). **Deploy:** `php artisan
> migrate` then `php artisan shield:generate` + `shield:sync-super-admin` so
> the new `Tag` resource permissions exist and the role maps pick them up.

**Asked for, deferred 2026-05-31.** On the invoice/quote line forms (and the
header customer picker), the operator wants the dropdowns to surface the
common customers/products first instead of only showing results after typing.

Decided shape (NOT a plain `is_favorite` boolean — go with tags so we can also
filter the list tables):

1. **Tags on `customers` and `products`** — a reusable tag/label system (e.g.
   «συχνός», «χονδρική», «hardware»). Must also be **filterable in the
   Customers / Products list tables**, not just used by the pickers.
   - Open question: dedicated `tags` + pivot, or `spatie/laravel-tags`
     (already in the Laravel ecosystem), or a simple per-model JSON column.
     Tags need to be tenant-scoped (`company_id`) either way.

2. **Picker behaviour: «Αγαπημένα/tagged πρώτα + Show all»**
   - On open (before typing): show the tagged/favourite set.
   - Typing: normal `getSearchResultsUsing` search **as today** — keep it
     working unchanged; just bias tagged rows to the top
     (`orderByDesc(<tagged>)`).
   - A «Show all» affordance to browse beyond search (for occasional
     full-catalogue browsing without preloading thousands of rows).
   - Applies to: InvoiceForm + QuoteForm line `product_id`, and the header
     `customer_id` on both.

**Why deferred:** tags are a small feature of their own (schema + tag CRUD UI +
table filters + picker wiring). Not worth bolting on mid-form-redesign;
revisit as a focused slice.

---

---

## 📋 Roadmap snapshot — open items (refreshed 2026-06-10)

**THIS is the single source of truth for «τι μένει». Read it before picking work —
don't trust a pasted copy.** ✅ DONE items live in `CLAUDE.md`; the
«looks-like-a-gap-but-isn't» list below exists so we DON'T re-litigate things that
are already done or deliberately not-built (the VATinfo trap).

### ✅ Done recently (so we don't re-pick them)
- **PEPPOL Phase 1** (PR #253) — provider-independent BIS 3.0 UBL builder + `peppol:test-submit`. (Phase 2 = the Access-Point transport, still open.)
- **DR / «work without APP_KEY»** (PR #254) — `MaybeEncrypted` cast + `secrets:reencrypt`; default plaintext at rest. **Live prod migrated to plaintext.**
- **`$hidden` on secret models** (PR #255) — secrets out of `toArray`/logs.
- **Expense classification → AADE** (PR #256) — `SendExpensesClassification` + **per-line** (εμπορεύματα/πάγια/δαπάνες) + `expenses:test-classify`; **sandbox-validated** (payload/per-line/safety proven; full-accept blocked by the test ΑΦΜ's [323], not our code).
- **FK-aware delete guard** (PR #258) — `GuardedDeleteAction` blocks deleting an in-use lookup on all 8 lookup edit pages.
- Earlier this session: DEMO seeder, export/import without a passphrase, SMTP test button, «Εργαλεία» commands-as-buttons, `ekdosi:install` wizard.

### 🛑 Looks like a gap — but it is NOT (don't re-open without a NEW reason)
- **RequestVatInfo «ΦΠΑ cross-check»** — **deferred, low value, ON PURPOSE.** The
  ΦΠΑ picture ALREADY exists on the dashboard (`VatPictureCache`, from the AADE
  docs). `RequestVatInfo` is AADE's *Φ2 deductible* total — it measures a
  **different thing** than our «sum of VAT on expense docs», so a «ΑΑΔΕ vs εμείς ·
  διαφορά» would show a **permanent fake discrepancy** = ψυχολογικός μπελάς. Build
  ONLY if a real accountant need appears, and ONLY in the Κονσόλα (never the dashboard).
- **E3 overview** — **already done.** `MyDataE3Overview` pulls AADE's `RequestE3Info`
  directly; the numbers ARE AADE's. Nothing to «cross-check».
- **`TenantScopedUnique`** — **redundant.** The DB already has the
  `unique(company_id, …)` constraints, so queue/CLI duplicates are blocked at the DB.
- **Manual «off-the-books» expense entry** — a SEPARATE thing from the (done)
  classification of myDATA-pulled expenses. Deferred (only if a tenant needs to
  record non-myDATA expenses).
- **Stock movements / ΣΔΕΠ / WHMCS `-333/-1000` sentinels** — DEAD code in the
  legacy app; build only if the prod `.fbk` proves real usage.

### 🟢 Finish/verify — built, NOT live-validated (high value, low risk)
- **Sandbox round-trips** (run on the VM): ΔΑ lifecycle, the new taxTypes
  (fees/stamp/other/deductions) + **product-linked taxes**, the **4% override**.
  Runbook: `docs/sandbox-validation-runbook.md`. Once green → mark sandbox-validated.
- **Schedules**: most `EKDOSI_SCHEDULE_*` are ON by default; backups + auto-issue +
  resend-failed + service-renewals are OFF (safe). Flip per-need in `.env`.

### 🟠 myDATA completeness
- **`invoice_taxes` table (Phase-2)** — many categories per taxType on one invoice
  (today: one/type, else throw). Also lets a fee count in `gross_total`/owed.
  **Needs the money-core decision first** (do fees count toward what the customer owes?).
- **§8.13 measurement units** for goods delivery notes (follow-up if a goods tenant).
- **Expenses — λογιστής/`entityVatNumber`** third-party submission (for tenants the
  ΑΑΔΕ blocks from direct classification with [323]); `RequestMyExpenses`; per-row +
  supplier CSV import. (Classification submit + per-line are DONE — see above.)

### 🔵 Big features (when the time comes)
- **PEPPOL Phase 2** — the Access-Point transport (the «send»). Phase 1 (UBL builder)
  is DONE; Phase 2 needs a chosen EE provider + sandbox creds (Billit/Finbite/Telema…).
  Checklist: `docs/paroxos/regulatory-blueprint.md §7`.
- **GR Πάροχος/Ιδιοπάροχος (ΥΠΑΗΕΣ)** — blueprint only (`docs/paroxos/regulatory-blueprint.md`).
- **Bridges/Connectors Phase 1** — a real 2nd source (e.g. WooCommerce) beyond WHMCS.

### 🟣 WHMCS loose ends
- **Multi-party SPLIT write-back** (one WHMCS invoice → many MARKs, one `invoiced` col).
- **«All of a client's third parties» 2nd dropdown** (needs a `contacts-by-userid` bridge endpoint).

### ⚙️ Tech debt / latent (CLAUDE.md «Known latent items»)
- **Strict tenant scope** — flip `CompanyScope` null→throw once every CLI/queue uses `actAs`.
- **Bulk-delete guard** — the single-record lookup delete is now guarded (PR #258), but the
  table `DeleteBulkAction` + `ForceDeleteBulkAction` stay unguarded (force-delete of an in-use
  lookup hard-fails on a `restrictOnDelete` FK — raw error, no data loss).
- **Soft-deleted FK rows render blank** in Filament Selects → now *prevented* for new deletes by
  the guard; remaining = a `withTrashed()` label + «deleted» badge for rows trashed before the guard.
- ~~`TenantScopedUnique`~~ ✅ non-issue (DB constraints — see «not a gap» above).
- ~~FK-aware delete guards~~ ✅ DONE (PR #258). ~~`$hidden` on secret models~~ ✅ DONE (PR #255).

### 🔒 Backup / DR
- **Phase 6 — «work without APP_KEY» — ✅ DONE.** `MaybeEncrypted` cast +
  `EKDOSI_ENCRYPT_SECRETS_AT_REST` (default plaintext) → plain `mysqldump`
  self-sufficient, restore needs no old APP_KEY; `secrets:reencrypt` to switch
  modes. `docs/dr-without-app-key.md`.
- **Backup encryption** — operator prefers «no app-level» → deferred (rely on SFTP/S3 access control).

### 💡 PDF / UX & ideas
- **G10** — one adaptive PDF template vs 8 legacy designs.
  - **Bilingual / English output (asked 2026-06-10).** A GR tenant (myip/nexon) OR
    the Estonian one (nixpal) will eventually invoice a foreign company, so the PDF
    needs an English (or GR+EN bilingual) variant of the field labels — amount /
    total / quantity / unit price / VAT / net / notes / payment terms… Approach:
    a label dictionary keyed by locale, the template picking GR vs EN (vs bilingual)
    from the customer's country / a per-invoice language flag (default GR for
    domestic, EN for a non-GR/foreign recipient). Ties into the PEPPOL work —
    an EE/cross-border invoice is exactly the case that needs the EN labels.
    Deferred with G10.
- **Curated tax-presets expansion** per sector + **%-per-product** (not just €/unit).
- **Tags on invoice/quote lines** + **«Show all / browse» picker** (above, deferred).
- **`clear:right` on single-word doc-types** (PDF review flag) — refine if the QR-then-type
  layout is undesired for short names.

### 🆕 Settings-in-the-UI — single source of truth, visible + audited (asked 2026-06-10)
**Principle:** NO runtime setting lives only in `.env`/`config` where it can be
silently left off and forgotten («θα φταίμε»). Every operator-facing knob —
**admin / super_admin / operator** — is set AND **visible** from the web UI, and
each change is **recorded in the activity log** (win-win audit: who turned what
on/off, when).

**Concretely:**
- A `system_settings` (key→value, typed) store + a settings model that uses our
  existing `TracksActivity`/`LogsActivity` → toggle changes land in the activity
  log automatically (causer = the user) and show in `ActivityFeed`.
- Admin-only Filament Page(s): **«Χρονοπρογραμματιστής»** (the `EKDOSI_SCHEDULE_*`
  flags as Toggles + helperText + ⚠ for the dangerous ones, e.g. resend-failed
  during a mail outage) and a wider **«Ρυθμίσεις συστήματος»** (mailer health
  hint: global vs per-tenant SMTP; cache/queue/cron status via `ops:health`).
- `routes/console.php` (and any code reading these) reads the DB setting with the
  env flag as the DEFAULT — a flip takes effect next `schedule:run` (~1 min), no
  `config:clear`. `.env` stays the deploy-time default/override only.
- **Visibility first:** a read-only «what's on/off right now» panel so nothing is
  silently disabled. `whmcs_auto_issue` stays two-key (UI + `companies.whmcs_auto_issue_immediate`).
- Role-scope: per-company knobs (backups, billing, email) gated to company_admin;
  system/cross-tenant ones to super_admin; the operator-relevant ones visible to operators.
- Migrate the existing scattered env flags + the per-company toggles under this one
  consistent, audited surface over time (not a big-bang rewrite).

### 🆕 Health / observability — in the web UI, not just `artisan` (asked 2026-06-10)
Not everyone on the team has terminal access, so `php artisan ops:health` must also
be a **web page**. An admin/super_admin Filament Page «Υγεία συστήματος» that shows,
read-only:
- **Liveness:** is the queue worker (systemd) up? did the cron `schedule:run` fire
  recently? cache/DB/Redis reachable? mailer (global vs per-tenant) configured?
  disk space. → wrap the existing `OperatorHealth` service (reuse its checks; the
  command and the page render the same source).
- **«Τι έτρεξε / πότε / πόσο»:** per scheduled task — last run, duration, success/fail,
  last error. Needs a unified `scheduled_task_runs` log (task, started_at, finished_at,
  status, summary) written via the scheduler's `->onSuccess()/->onFailure()` hooks in
  `routes/console.php` (today only some tasks record state: CompanyBackupRun,
  VatPictureCache «last fetch», mydata reconcile). Surface e.g. «ΦΠΑ τελευταία λήψη:
  …», «WHMCS fetch: …», «Backup: …».
- **Queue:** pending + failed jobs count, with a «retry/clear» action (admin).
- Ties into the settings-in-UI item above: one «Σύστημα» area = toggles (audited) +
  health + run history, so an operator sees at a glance what's on, what ran, and what's stuck.

### 🆕 Onboarding / operator productivity (asked 2026-06-10)
Operator-pasted ideas. (The big-feature / tech-debt / PDF lists from the same day
are already captured in the sections above — these are the new ones.)

- **Artisan actions → buttons.** Surface the operator-facing commands as Filament
  buttons/actions, no terminal: `mydata:vat-picture` refresh, `mydata:reconcile-sales`,
  `whmcs:fetch-pending`, `invoices:resend-failed-emails`, `mydata:preflight`, the
  sandbox/test-submit dry-runs, etc. — each as a guarded admin action with a result
  notification. (Pairs with the «Health / observability» + «Settings-in-the-UI» items:
  one «Σύστημα» area = status + toggles + run-now buttons, all audited.)
- **DEMO company seeder — «full demo mode».** A `DemoCompanySeeder` that builds ONE
  self-contained «DEMO Α.Ε.»: 2-3 products + 2-3 services (one with withholding, one
  with a bound fee), 2-3 customers, 2-3 issued invoices, 2-3 delivery notes — so a
  fresh install / a reviewer sees a working tenant immediately. Replaces the
  nexon/nixpal/myip dev fixtures for demos (keep those for real ETL/dev).
- **Fresh-install wizard.** From-zero onboarding: if NO admin user exists, a guided
  «create the first super_admin + first company» wizard; if one already exists, a
  safeguard (refuse / require auth) so it can't be re-run to mint an admin. Make
  install-from-scratch turnkey (today it's artisan + manual seeding per INSTALL.md).
- **Seeders for from-zero installs.** We have great `VatCategory` / `InvoiceType`
  (+ income-class, payment-methods, units) seeders — make sure they're wired into the
  install path (a `php artisan ekdosi:bootstrap-company <slug>` or the wizard) so a
  new tenant gets the §8 lookups without copy-paste. Audit which lookups still need a
  from-zero seeder.
- **SMTP test button.** Per-company «Δοκιμή SMTP» (send a test email to a typed
  address) on the Company → PDF & Email tab, using `TenantMailerFactory`; and a
  super_admin-only «test the global .env mailer» so the ops-alert / fallback path can
  be verified from the UI (ties into the mailer-health hint in «Health»).
