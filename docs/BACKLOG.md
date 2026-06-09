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

## 📋 Roadmap snapshot — open items (2026-06-10)

Consolidated «what's left» after the myDATA-payload + product-linked-taxes work.
Grouped by theme; ✅ done items live in CLAUDE.md.

### 🟢 Finish/verify — built, NOT live-validated (high value, low risk)
- **Sandbox round-trips** (run on the VM): ΔΑ lifecycle, the new taxTypes
  (fees/stamp/other/deductions) + **product-linked taxes**, the **4% override**.
  Runbook: `docs/sandbox-validation-runbook.md`. Once green → mark sandbox-validated.
- **Schedules**: most `EKDOSI_SCHEDULE_*` are ON by default; backups + auto-issue +
  resend-failed + service-renewals are OFF (safe). Flip per-need in `.env`.

### 🟠 myDATA completeness
- **`invoice_taxes` table (Phase-2)** — many categories per taxType on one invoice
  (today: one/type, else throw). Also lets a fee count in `gross_total`/owed.
- **Product-linked taxes — money-core decision**: fees are filed in the AADE gross +
  payment but NOT yet in `invoices.gross_total` / the money cache (Καρτέλα/owed). If
  fees should count toward what the customer owes → a money-core follow-up.
- **§8.13 measurement units** for goods delivery notes (follow-up if a goods tenant).
- **Expenses (Έξοδα)**: `SendExpensesClassification` AADE submit, `RequestVatInfo`/E3
  cross-checks, `RequestMyExpenses`, manual expense entry, per-row + supplier CSV import.

### 🔵 Big features (when the time comes)
- **Estonian PEPPOL submitter** — the last big ❌ (stub; `EInvoiceSubmitter` slot ready).
- **GR Πάροχος/Ιδιοπάροχος (ΥΠΑΗΕΣ)** — blueprint only (`docs/paroxos/regulatory-blueprint.md`).
- **Bridges/Connectors Phase 1** — a real 2nd source (e.g. WooCommerce) beyond WHMCS.

### 🟣 WHMCS loose ends
- **Multi-party SPLIT write-back** (one WHMCS invoice → many MARKs, one `invoiced` col).
- **«All of a client's third parties» 2nd dropdown** (needs a `contacts-by-userid` bridge endpoint).

### ⚙️ Tech debt / latent (CLAUDE.md «Known latent items»)
- **Strict tenant scope** — flip `CompanyScope` null→throw once every CLI/queue uses `actAs`.
- **Soft-deleted FK rows render blank** in Filament Selects → `withTrashed()` label lookups + a «deleted» badge.
- **`TenantScopedUnique`** helper — `Rule::unique(...)->where('company_id', …)` degrades to
  `IS NULL` outside panel context (duplicates can pass in queue/CLI).
- **FK-aware delete guards** (`GuardedDeleteAction`) — friendly count-and-block + «Deactivate».

### 🔒 Backup / DR
- **Phase 6 — «work without APP_KEY»** (plain `mysqldump` self-sufficient) — deferred
  (`docs/company-portability-plan.md`).
- **Backup encryption** — operator prefers «no app-level» → deferred (rely on SFTP/S3 access control).

### 💡 PDF / UX & ideas
- **G10** — one adaptive PDF template vs 8 legacy designs.
- **Curated tax-presets expansion** per sector + **%-per-product** (not just €/unit).
- **Tags on invoice/quote lines** + **«Show all / browse» picker** (above, deferred).
- **`clear:right` on single-word doc-types** (PDF review flag) — refine if the QR-then-type
  layout is undesired for short names.

### 🆕 Operator UX — Scheduler/system toggles in the Filament UI (asked 2026-06-10)
The `EKDOSI_SCHEDULE_*` flags (and the system mailer note) should be **UI knobs**,
not `.env` edits: an admin-only «Χρονοπρογραμματιστής / Ρυθμίσεις συστήματος» page
with a Toggle + helperText per task (keep the ⚠ warnings, e.g. resend-failed during
a mail outage). Store in a `system_settings` (key→value) table / singleton; have
`routes/console.php` read the DB setting with the env flag as the default — so a
flip takes effect next `schedule:run` (~1 min), no `config:clear`. `whmcs_auto_issue`
stays two-key (UI + `companies.whmcs_auto_issue_immediate`); per-company backups
already have a UI. Also surface a «mailer health» hint (global vs per-tenant SMTP).
