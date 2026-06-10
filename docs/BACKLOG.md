# Backlog / Roadmap — deferred ideas

Cross-cutting TODOs that are **deliberately not built yet**, + new ideas. Captured
so they don't get lost in drift. (What IS built → **`FEATURES.md`** at the root.)

**Standalone idea / plan docs** (the bigger ones live on their own — index here so
they're not lost):
- `paroxos/regulatory-blueprint.md` + `implementation-plan.md` — GR ΥΠΑΗΕΣ provider + EU PEPPOL.
- `ai-assistant-blueprint.md` — in-app «Βοηθός» / **MCP-style assistant** (idea, nothing built).
- `payment-connectors.md` + `payments-ar-roadmap.md` — payment gateways / AR next steps.
- `bridges-connectors.md` — 2nd billing source beyond WHMCS (WooCommerce…).
- `services-quotes-roadmap.md`, `expenses-phase-plan.md` — built; remaining-polish lists.

The myDATA-filing gaps + tech-debt latent list also live in `CLAUDE.md`.

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
- **«Σύστημα» area — 3 slices (2026-06-10)** — closes the «Health / observability» +
  «Settings-in-the-UI (scheduler)» + «Onboarding» asks below:
  - **«Υγεία συστήματος»** page (super_admin) — read-only `ops:health` in the web UI
    (worker heartbeat, scheduler, backups, mail, WHMCS + myDATA per tenant, disk),
    short-TTL cached.
  - **Durable `scheduled_task_runs` log** + queue **pending/failed** count + a
    «Επανάληψη αποτυχημένων» (`queue:retry all`) action.
  - **«Ρυθμίσεις χρονοπρογραμματιστή»** (super_admin) — audited per-task toggles in a
    `system_settings` typed store; `routes/console.php` reads them at run-time
    (`->when()`), env = default. See `FEATURES.md §15`.
- **Onboarding / operator productivity (2026-06-10)** — DEMO seeder (`DemoCompanySeeder`),
  `ekdosi:install` first-run wizard (incl. standard ΦΠΑ/τύποι/πληρωμές/μονάδες lookup
  seeding via `MyDataLookupSeeder` + a re-run safeguard), per-company **+ global** «Δοκιμή
  SMTP», «Εργαλεία» (artisan commands as guarded admin buttons), export/import without a
  passphrase.

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
  resend-failed + service-renewals are OFF (safe). Flip per-need from the
  **«Ρυθμίσεις χρονοπρογραμματιστή»** UI page (audited, env = default) or `.env`.

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

### 🆕 Settings-in-the-UI — widen the audited surface (scheduler page SHIPPED 2026-06-10)
**Principle (still the goal):** NO runtime setting lives only in `.env`/`config` where
it can be silently left off and forgotten («θα φταίμε») — every operator-facing knob is
set AND **visible** from the web UI, each change **recorded in the activity log**.

**Shipped (slice 3):** the `system_settings` typed store + the super_admin
**«Ρυθμίσεις χρονοπρογραμματιστή»** page (audited per-task toggles; `routes/console.php`
reads them run-time with env as default). The «Σύστημα» area also has the read-only
**«Υγεία συστήματος»** «what's on/what ran» panel (slices 1-2).

**Still open — broaden it beyond the scheduler (not a big-bang rewrite):**
- A wider **«Ρυθμίσεις συστήματος»** page for the remaining global knobs (e.g. `require_2fa`,
  `encrypt_secrets_at_rest`, backup-alert email) + a mailer-health hint (global vs per-tenant SMTP).
- **Role-scoped knobs:** per-company settings (backups, billing, email) gated to
  company_admin; the operator-relevant ones visible to operators — today the «Σύστημα»
  area is super_admin-only (deploy-wide flags). When per-company settings move into the
  store, scope the page accordingly.
- Migrate the existing scattered per-company toggles (on `companies`) under this one
  consistent, audited surface over time. `whmcs_auto_issue` stays two-key
  (UI + `companies.whmcs_auto_issue_immediate`).
