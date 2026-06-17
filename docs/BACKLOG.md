# Backlog / Roadmap — what's left + ideas (SINGLE SOURCE)

The **one** place for what is **deliberately not built yet** + new ideas/thoughts.
What IS built lives in **`FEATURES.md`** (the catalogue) and **`CHANGELOG.md`** (per
change) — those two are the cross-check for «τι είναι χτισμένο».

**Lifecycle of a shipped item (keep it tidy — μην χανόμαστε):**
**α)** add/confirm it in `FEATURES.md` → **β)** record it in `CHANGELOG.md` → **γ)**
delete it from here → **δ)** if it was a «known latent» note in `CLAUDE.md`, unmark it.
(Don't backfill old `CHANGELOG` versions for things shipped long ago — just ensure
`FEATURES.md` has them and drop them from here.)

Don't re-pick the **«Done recently»** or **«looks like a gap but isn't»** lists below.

---

## 📚 Kept design / reference docs (indexed here, not deleted)
These are genuine specs / architecture blueprints / ops runbooks / historical records —
kept on their own, with their current status. The forward-looking work in them is
surfaced in the open-items sections further down.

- **`paroxos/regulatory-blueprint.md`** + **`paroxos/implementation-plan.md`** — GR
  ΥΠΑΗΕΣ provider + EU PEPPOL. PEPPOL Phase 1 (UBL builder, `peppol:test-submit`) **DONE**;
  provider P0–P5 built/gated (mode=off); **PEPPOL Phase 2 + live provider = OPEN**.
- **`payment-connectors.md`** — card-POS + IRIS design. **NOT-STARTED** (blueprint).
- **`payments` (AR)** — core **DONE** (cockpit/allocator/bank-accounts/refunds); deferred
  connectors → `payment-connectors.md`.
- **`bridges-connectors.md`** — multi-billing-source. Phase 0 (registry seam) **DONE**;
  Phase 1 (real 2nd source) **OPEN**.
- **`ai-assistant-blueprint.md`** — in-app «Βοηθός» / MCP-style assistant. **NOT-STARTED** (idea).
- **`whmcs-legacy-plugin-map.md`** — legacy WHMCS plugins → `ekdosi_bridge`. T-1/T-2 **DONE**;
  T-3 cutover **OPEN**.
- **`delivery-provider-split-brain.md`** — ΔΑ provider-vs-direct-myDATA routing (architecture
  lock, **DONE/reference**).
- **`operator-health.md`** · **`dr-without-app-key.md`** · **`go-live-usage-checks.sql.md`** —
  ops runbooks (reference).
- **`mydata-sandbox-validation-2026-05-28.md`** — historical validation record.
- **`aade/`** — the AADE myDATA + Delivery-Note specs.

---

## ✅ Done recently (so we don't re-pick them)
- **PEPPOL Phase 1** (PR #253) — provider-independent BIS 3.0 UBL builder + `peppol:test-submit`.
- **DR / «work without APP_KEY»** (PR #254) — `MaybeEncrypted` cast + `secrets:reencrypt`; default plaintext.
- **`$hidden` on secret models** (PR #255).
- **Expense classification → AADE** (PR #256) — `SendExpensesClassification` + per-line + `expenses:test-classify`.
- **FK-aware delete guard** (PR #258) — `GuardedDeleteAction`.
- **«Σύστημα» area — 3 slices** (2026-06-10): «Υγεία συστήματος» page · durable `scheduled_task_runs` + queue retry · «Ρυθμίσεις χρονοπρογραμματιστή» (audited toggles, `system_settings`).
- **«Ρυθμίσεις συστήματος» page** — global knobs (`require_2fa`, backup-alert on/off + email) ως audited live toggles· at-rest encryption + mailer status read-only.
- **Expenses polish** (2026-06-10): χειροκίνητη καταχώριση εξόδου (`source=manual`, γραμμές, tab «Χειροκίνητα», edit μόνο για manual) + ιδιωτικό PDF/scan attachment με signed download.
- **Δίγλωσσο/EN PDF** (2026-06-10): γλώσσα ανά invoice/quote (GR/EN/δίγλωσσο, default από χώρα πελάτη)· `PdfLabels` dictionary· localizes μόνο ετικέτες.
- **Withholding/fees count toward owed** (2026-06-11): `invoices.payable_total` (= gross + AADE [208] adjustment)· owed/balance/Καρτέλα/receivables/dashboard + PDF «Πληρωτέο» = payable· `invoices:backfill-payable-total`· money-consistency proven με τιμολόγιο παρακράτησης.
- **«Υπόλοιπο πελάτη» στο PDF** (2026-06-11): legacy «ΝΕΟ ΥΠΟΛΟΙΠΟ» — snapshot-at-issue (`invoices.customer_balance_snapshot`, capture στον `InvoiceObserver`), block Προηγούμενο+παραστατικό=Νέο, opt-in per-tenant (`show_customer_balance_on_pdf`) + override per-customer (`show_balance_on_pdf`)· μόνο επί πιστώσει/πιστωτικά.
- **Καρτέλα «αναλυτική παρακράτηση»** (2026-06-11): στο AR-ledger row, όταν το εισπρακτέο διαφέρει από την αξία εγγράφου (παρακράτηση/τέλη), εμφανίζεται detail «Αξία εγγράφου 1.240 · Παρακράτηση φόρου 200» κάτω από την αναφορά (page + statement PDF). Display-only — debit/credit/υπόλοιπο μένουν = payable (chose inline-detail αντί synthetic rows ώστε running-balance + paid/unpaid filters να μη χαλάνε).
- **Onboarding** (2026-06-10): DEMO seeder · `ekdosi:install` wizard (+ lookup seeding via `MyDataLookupSeeder`) · global+per-company «Δοκιμή SMTP» · «Εργαλεία» · export/import χωρίς passphrase.
- **myDATA console unification** (PR #272) — one «Κονσόλα myDATA» cluster (Πωλήσεις/Έξοδα/Ε3) + redirects.
- **Expenses fetch** (PR #272) — «Άντληση από myDATA» κουμπί στη λίστα Έξοδα + tip + read-only `mydata:refresh-expenses` cron (UI toggle, default OFF).
- **Already shipped earlier — docs were stale, now corrected:** ΔΑ **movement lifecycle**
  (έναρξη/παράδοση/έλεγχος μέσω `DeliveryLifecycleService`: `registerTransfer`/`confirmDelivery`/`refreshStatus`) ·
  **§8.13 μονάδες μέτρησης** + seeder (`MyDataLookupSeeder::seedMetricUnits`, `MetricUnit`) ·
  **enrich/QR από MARK** (`EnrichInvoiceFromAade`).
- **Sandbox round 2 ✅** (2026-06-10) — ΔΑ lifecycle + νέοι taxTypes (fees/stamp/deductions) +
  product-linked taxes + 4% override, όλα AADE-accepted (`sandbox-results.txt`).
- **Ηλικίωση οφειλών** (PR #308) — aged-receivables page (0-30/31-60/61-90/90+ ανά πελάτη, σύνολα,
  drill στην Καρτέλα, CSV· reuse Καρτέλα FIFO aging). FEATURES §12.
- **Βιβλίο Εσόδων-Εξόδων → myDATA period report** — ΜΑΡΚ+κατάσταση στήλες, Έσοδα/Έξοδα+σύνολα,
  period presets, **PDF οριζόντιο A4**, exports CSV/XLSX/JSON. FEATURES §12. (Πλήρως κλεισμένο.)
- **Panel utility CSS (no-build)** — `resources/css/panel.css` μέσω `FilamentAsset::register` →
  `filament:assets`· όλα τα custom blade utilities πλέον styled, χωρίς npm/Vite/theme.
  _(Maintenance: νέο utility σε blade → πρόσθεσέ το εκεί.)_
- **Sendable customer statement (επαφή-aware)** (2026-06-17) — Καρτέλα → PDF/email σε πελάτη +
  τις επαφές του (role-labelled) + ελεύθερα extras (validate/dedupe). FEATURES §7.
- **Καρτέλα — όψη περιόδου + ομαδοποίηση header actions** (2026-06-17) — φίλτρα περιόδου πάνω από
  τον πίνακα + **σύνολα έτους** (τζίρος/εισπράξεις/υπόλοιπο)· header actions σε dropdowns + **global
  fix** στο overflow (`.fi-header-actions-ctn` wrap, αφορά όλες τις σελίδες με πολλά actions). FEATURES §7.

---

## 🛑 Looks like a gap — but it is NOT (don't re-open without a NEW reason)
- **RequestVatInfo «ΦΠΑ cross-check»** — **deferred ON PURPOSE.** Μετράει το Φ2 *deductible*
  της ΑΑΔΕ — **άλλο πράγμα** από το δικό μας «άθροισμα ΦΠΑ εξόδων» → μόνιμη ψεύτικη διαφορά.
  **NB:** η **ΦΠΑ picture** (dashboard «Εικόνα από myDATA» + box στην Ε3) **ΕΙΝΑΙ χτισμένη**
  (sum-of-docs, `MyDataVatAggregator`) — μην τη μπερδεύεις με το RequestVatInfo cross-check.
- **E3 ↔ local classification diff** — ίδια οικογένεια· deferred μέχρι να υπάρξει πραγματική ανάγκη.
- **E3 overview** — **already done** (`MyDataE3Overview`, νούμερα της ΑΑΔΕ).
- **`TenantScopedUnique`** — redundant (DB `unique(company_id,…)` constraints).
- **Manual «off-the-books» expense entry** — βλ. «myDATA/expenses completeness» (deferred, όχι «gap»).
- **Stock movements / ΣΔΕΠ / WHMCS `-333/-1000` sentinels** — DEAD legacy code· build μόνο αν το prod `.fbk` δείξει πραγματική χρήση.

---

## 🟠 myDATA / expenses completeness
- **`invoice_taxes` table** — πολλές κατηγορίες ανά taxType σε **ΕΝΑ** τιμολόγιο (σήμερα μία/τύπο
  αλλιώς throw). **Χαμηλή προτεραιότητα/σπάνιο** — το ΦΠΑ ανά γραμμή παίζει ήδη· αυτό αφορά
  μόνο 2+ διαφορετικές κατηγορίες **ειδικού τέλους** (§8.x) στο ίδιο παραστατικό. _(Η money-core
  απόφαση «μετράνε τα τέλη/παρακράτηση στο οφειλόμενο;» **λύθηκε ✅** = `payable_total` — βλ. «Done recently».)_
- **Expenses — λογιστής/`entityVatNumber`** third-party submission (για tenants που μπλοκάρει
  η ΑΑΔΕ με [323]) + `RequestMyExpenses` (sanity totals) + **supplier CSV import** (`source=import`).
- **`SalesOrphanImporter`** — νέο τοπικό τιμολόγιο **πώλησης** από sales-orphan MARK + **line/E3
  backfill** στο enrich όταν το τοπικό δεν έχει γραμμές. _Χαμηλή αξία — πώληση εκδομένη από
  άλλο πρόγραμμα συνήθως απλώς αναγνωρίζεται. (Το expense-orphan import υπάρχει.)_
- **Expenses polish (remaining):** per-row import action (+ «held/needs-review» state) στην
  κονσόλα-Έξοδα. _(Χειροκίνητη καταχώριση + PDF/scan attachment: ✅ shipped — βλ. «Done recently».)_
- **§8.13 quantity/units για ΔΑ αγαθών** — οι μονάδες υπάρχουν· τυχόν goods-tenant ειδικά
  (π.χ. `<quantity>` per-line σε goods invoice types) ανοίγουν μόνο αν έρθει goods tenant.

---

## 🔵 Big features (blueprints kept — see index above)
- **PEPPOL Phase 2** — Access-Point transport («send»). Phase 1 (UBL) DONE· θέλει EE provider +
  sandbox creds (Billit/Finbite/Telema…). `paroxos/regulatory-blueprint.md §7`.
- **GR Πάροχος live** — P2–P5 built/gated (mode=off)· θέλει πραγματικά provider creds + sandbox
  (InvoSign/SBZ). `paroxos/`.
- **Bridges/Connectors Phase 1** — πραγματική 2η πηγή (WooCommerce/Blesta…). `bridges-connectors.md`.
  _Phase 0.5 ✅ (presentation-only): source-neutral «Εισερχόμενα» + source badge · «Γέφυρες» page
  (honest status, no fake toggle). Phase 1 = move `companies.whmcs_*` → `billing_connections.config`,
  ExternalDocument DTO, generic ingest dispatcher, real is_active gating, + the 2nd connector —
  build WHEN a real 2nd source exists (designing the contract against WHMCS+guesswork bakes in WHMCS-isms)._
- **AI «Βοηθός»** — _✅ Phase 1 SHIPPED 2026-06-17: read-only chat (σελίδα + floating widget, κοινό
  `AssistantRunner`), tool layer isolation + per-tool permission, governance web/DB (on/off · model · token
  cap · per-company key) + `ai_usage_log` metering + caps. ✅ Phase 2a SHIPPED 2026-06-17: 6 read-only
  insight tools (`count_sales`/`outstanding_receivables`/`list_top_debtors`/`find_customer`/`recent_invoices`/
  `vat_summary`) + **clickable same-origin links** (Καρτέλα/view/νέο παραστατικό) μέσω `ChatMarkup` +
  prompt-caching toggle. ✅ Phase 2b SHIPPED 2026-06-17: **WRITE tools με operator-confirm** (ποτέ
  αυτόματα) — `send_customer_statement` (επαφή-aware) + `create_reminder`· staging σε `ai_pending_actions`,
  confirm/cancel κάρτες, `AiActionExecutor` (re-validate, scoped tenant+user), reminders → Filament DB
  notifications μέσω `ai:dispatch-reminders`._
  **Phase 2c (open) — ιδέες/σημειώσεις (καμία δέσμευση, χαμηλή προτεραιότητα):**
  - **(α) Περισσότερα read tools** — σύγκριση εσόδων/εξόδων (income vs expense),
    κατάσταση backups (`OperatorHealth`), WHMCS inbox (εκκρεμή `pending_whmcs_invoices`),
    top προϊόντα/υπηρεσίες ανά περίοδο (`CustomerTopProducts`-style αλλά εταιρείας).
  - **(β) Περισσότερα write tools με confirm** — π.χ. «καταχώρισε είσπραξη/έμβασμα»
    (reuse `PaymentAllocator`), «κόψε πρόχειρο παραστατικό» (το `find_customer` ήδη δίνει
    link· εδώ θα στηνόταν draft μέσω `CreateInvoice`). Πάντα operator-confirm στο
    `ai_pending_actions` — ίδιο pattern με 2b.
  - **(γ) Per-company κλειδί/βοηθός ξεχωριστά** — η στήλη `companies.ai_api_key` υπάρχει
    (στο `$hidden`)· λείπει το UI exposure (στο `CompanySettings` ή super-admin only) +
    per-key billing separation (κάθε εταιρεία δικός της Anthropic account/DPA).
  - **(δ) Persistence συνομιλιών** — `ai_conversations` table (ιστορικό + πολλές
    συνομιλίες ανά χρήστη, αντί session) — απαιτεί και UI επιλογής συνομιλίας.
  - **(ε) Usage dashboard — tokens/κόστος ανά εταιρεία.** Τα ΔΕΔΟΜΕΝΑ ΥΠΑΡΧΟΥΝ ΗΔΗ:
    το `ai_usage_log` κρατά input/output/cache tokens + `cost_estimate` ανά
    εταιρεία/χρήστη/συνομιλία/μοντέλο (είναι το source of truth για τα caps, βλ.
    `AiUsageMeter`). Λείπει ΜΟΝΟ το surface: Filament page/widget με
    `sum(tokens)`/`sum(cost)` group-by μήνα × εταιρεία (ποιος πληρώνει, ποιος κοντά
    στο όριο), προαιρετικά export CSV. Καθαρά read-only πάνω σε υπάρχοντα πίνακα.
  - **(στ) Streaming απαντήσεων** — τώρα είναι «σκέφτομαι…» μέχρι να ολοκληρωθεί το
    tool-loop· streaming θα ήθελε SSE/Livewire polling (μεγαλύτερη αλλαγή στο surface).
  - **(ζ) Helper / «βοήθεια & συμβουλή» με curated knowledge base.** Δύο ΞΕΧΩΡΙΣΤΑ
    πράγματα: **(i) app how-to** («πού βλέπω τι μου χρωστάνε;», «πώς κόβω πιστωτικό;») —
    ασφαλές, γνώση της εφαρμογής· **(ii) domain advisory** («τι ΦΠΑ για Σκόπελο;», «τι
    παραστατικό για αποστολή δικού μου εξοπλισμού στο datacenter;», «ποιον τύπο να
    διαλέξω;») — ΕΠΙΚΙΝΔΥΝΟ αν απαντηθεί από γενική γνώση του μοντέλου (μειωμένα νησιά
    άλλαξαν πολλές φορές· λάθος = λάθος ΦΠΑ/ΑΑΔΕ). **Σχέδιο:** curated KB σε markdown
    (`docs/assistant-kb/`) που γράφεις εσύ/ο λογιστής + νέο tool `knowledge_search`
    (RAG-lite: επιστρέφει σχετικά αποσπάσματα) → ο βοηθός στηρίζεται ΑΥΣΤΗΡΑ σε αυτό,
    «δεν καλύπτεται → ρώτα λογιστή», ΠΟΤΕ εφευρεμένος φορολογικός κανόνας + πάντα
    disclaimer για φορολογικά. **Κουμπώνει με τα έτοιμα:** links (π.χ. «πώς στέλνω
    εξοπλισμό» → εξήγηση ΔΑ + link «Νέο Δελτίο Αποστολής»), `vat_categories` της
    εταιρείας (δείξε τις ρυθμισμένες, μη μαντεύεις). Ίδιο grounding-discipline με τα
    tools — απλώς προστίθεται μία ΕΓΚΕΚΡΙΜΕΝΗ πηγή δίπλα τους.
  - _Σχεδιαστικά κλειδωμένα ήδη (μην ξανασυζητηθούν): tool-layer isolation (κανένα `company`
    param), per-tool Shield permission, `#[Locked]` messages/transcript, `ChatMarkup`
    same-origin links, writes ΠΟΤΕ auto (operator-confirm). Engine = Laravel HTTP/Messages
    API χωρίς SDK. prompt-caching ✅ έγινε (2a)._ `ai-assistant-blueprint.md`
  (πλέον καλύπτει: **«δεν χρειάζεται Console agent»** για το in-app chat — μόνο API key +
  Messages API tool-loop· **abuse/resource safeguards** = no-code-execution + per-request
  max_tokens/tool-loop/timeout/history caps + per-tenant/user rate-limit + monthly token caps +
  audit· **grounding** system prompt (ξέρει ότι είναι ekdosi, ποια εταιρεία, off-task refusal)·
  **υποψήφιο μοντέλο = Sonnet 4.6 default**, Haiku 4.5 cheap tier, Opus 4.8 για βαριά ανάλυση).
- **Payment connectors** — IRIS + card-POS. `payment-connectors.md`.

---

## 🟢 Services / Provisioning
- **Real provisioning modules** (cPanel/Mailcow/license server) — σήμερα μόνο `NullProvisioningModule`.
- **Multi-line service contracts** — v1 = single-line.

## 🟣 WHMCS loose ends (βλ. `whmcs-legacy-plugin-map.md`)
- **T-3 cutover** — legacy timologia → ekdosi Customers (match `gr_vatno`, upsert, back-ref).
- **Multi-party SPLIT write-back** στο WHMCS (ένα MARK ≠ N invoices).
- **T-4 manual split tools** (transfer_invoice / relid_remover) — χαμηλή προτεραιότητα.
- **«All of a client's third parties» 2ο dropdown** (θέλει `contacts-by-userid` bridge endpoint).

## 🆕 Settings-in-UI — widen (scheduler + global pages shipped)
- **Role-scoped per-company knobs:** _✅ SHIPPED (trimmed) — «Ρυθμίσεις εταιρείας»
  (`CompanySettings`, `View:CompanySettings`): company_admin self-serves the SAFE subset
  (PDF branding · invoice-mail templates/from · auto-email toggles · backup enable+cadence),
  audited, explicit-whitelist save._ **Still deferred (deliberately super_admin):** the
  credential/infra knobs (myDATA/GSIS/WHMCS/SMTP secrets, e-invoice provider, backup
  passphrase/destinations/retention, tenant identity). `whmcs_auto_issue` stays two-key
  (UI + `companies.whmcs_auto_issue_immediate`). Widen only if a real per-tenant admin
  needs a specific credential delegated — don't bulk-move secrets into company_admin reach.

## 🔒 Backup / DR / Portability
- **Durable native portable key (μετά το legacy_id sunset).** Ο `CompanyImporter` κλειδώνει
  το idempotent matching σε `legacy_id` (+ content-signature fallback). Όταν σβήσει το legacy
  (Delphi/Firebird), τα native rows (legacy_id NULL) δεν συγκλίνουν αξιόπιστα σε re-import-πάνω-
  σε-υπάρχουσα-εταιρία (το `--new`/fresh-copy ΟΚ — FK rewiring μέσω surrogate `id`). Λύση: ένα
  `uuid`/`public_id` ανά portable πίνακα, παραγόμενο στο create, ως ΤΟ idempotency key (uuid→
  legacy_id→signature)· + ΑΦΜ-dedup για πελάτες. Χρειάζεται μόνο για sync/merge μεταξύ ζωντανών
  ekdosi — όχι για μεταφορά-σε-VM.
- **Portability Phase 5** — envelope-key (option 4) — optional future (το plaintext-at-rest
  καλύπτει cross-VM σήμερα).
- **Backup encryption** (app-level) — deferred (βασιζόμαστε σε SFTP/S3 access control).
- **No-password (un-encrypted) exports/backups — συνεπές & εμφανές παντού.** _✅ SHIPPED 2026-06-17:
  per-company `secrets_mode` «Χωρίς κρυπτογράφηση» toggle στο export ΚΑΙ στα αυτόματα αντίγραφα, με
  σαφή plaintext προειδοποίηση και στα δύο· global spatie encryption status (read-only, env
  `BACKUP_ARCHIVE_PASSWORD`) εμφανές με «⚠ χωρίς κωδικό» στις «Ρυθμίσεις συστήματος»._ (Live global
  toggle = .env edit, σκόπιμα read-only — όχι νέα μηχανική.)

## ⚙️ Tech debt / latent (also `CLAUDE.md` «Known latent items»)
- **Strict tenant scope** — _audited 2026-06-11: **0 live leaks** σε ~54 entry points· το no-op default είναι σωστό/load-bearing. Έγινε το φθηνό hardening (StockService explicit company_id· SweepOrphanMailLogs explicit withoutGlobalScope· CLAUDE.md rule). Το enforcement (null→throw) **deferred**: naive flip σπάει ~18 ασφαλή explicit-where paths· execution-time tripwire false-positives σε relation/eager-load FK queries. Re-open μόνο αν εμφανιστεί πραγματικό leak ή μεγαλώσει πολύ το CLI surface._
- **Bulk-delete guard** — single-record guarded (PR #258)· `DeleteBulkAction`/`ForceDeleteBulkAction` αφύλακτα.
- **Soft-deleted FK rows render blank** — `withTrashed()` label + «deleted» badge για rows πριν τον guard.

## 💡 PDF / UX & ideas
- _(G10 «ένα template αντί 8»: **non-issue** — τα 8 legacy FastReport δεν πορτάρονται· έχουμε
  ήδη καθαρά Blade ανά τύπο. **Δίγλωσσο/EN output: ✅ shipped** — βλ. «Done recently».)_
  Προαιρετικό μελλοντικό cleanup: κοινό layout partial στα 4 PDF templates (χαμηλή αξία).
- **«Show all / browse» picker** (search-beyond-typing) στους product/customer pickers —
  να ξεφυλλίζεις όλον τον κατάλογο χωρίς πληκτρολόγηση. _(Tags σε **γραμμές**: dropped — δεν
  έχει use case· μια γραμμή δεν είναι οντότητα που ταξινομείς. Tags σε **πελάτες/προϊόντα**
  ήδη υπάρχουν.)_
- **Curated tax-presets** expansion ανά κλάδο + **%-ανά-προϊόν** (όχι μόνο €/τεμ).
- **Seeder «προϊόντα με θεσμικό τέλος»** — _✅ SHIPPED 2026-06-17: «Πρότυπα τελών» selective-import στη
  λίστα Προϊόντων (`LeviedProductTemplates` + `ImportLeviedProducts`) — σακούλα €0,07 / πλαστικά €0,04 /
  ανακύκλωσης €0,08 / διαμονής, προ-ρυθμισμένα με myDATA Fees §8.5· idempotent._
- **`clear:right`** σε single-word doc-types (PDF tweak).

## 🧰 Setup / onboarding helpers (from-zero — sweep 2026-06-17)
_Ήδη: `ekdosi:install` wizard · `MyDataLookupSeeder` (VAT/invoice types/payment-delivery methods/aims/
units, με one-click `StandardLookupSeedAction` ανά resource) · `DemoCompanySeeder` · GSIS/VIES lookup ·
`suppliers:sync` · «Πρότυπα τελών». Ιδέες για ευκολότερο στήσιμο από το 0:_
- **Generic CSV importer (προϊόντα / πελάτες)** — bulk onboarding από άλλο σύστημα (έχουμε CSV *export*
  `CsvEntityExporter`· λείπει το *import*). Column-map + dry-run preview + tenant-scope. _Το μεγαλύτερο
  win για μεταφορά καταλόγου/πελατολογίου._
- **Setup profiles ανά κλάδο** (λιανική / εστίαση / ξενοδοχείο / υπηρεσίες) — bundle σε ένα κλικ: invoice
  types + default ΦΠΑ + σχετικά «πρότυπα τελών» (ξενοδοχείο → διαμονής· λιανική → σακούλα/ανακύκλωσης) +
  payment methods. Πάνω στο υπάρχον seeding.
- **Curated tax-presets** (βλ. PDF/UX ideas) — withholding/χαρτόσημο presets ανά κλάδο για το per-invoice
  «Τυπικά τέλη/φόροι».
- **Κατάλογος συνήθων υπηρεσιών** (hosting/domain/SSL…) για WHMCS-style tenants — προαιρετικό template.

## 📡 myDATA sync — insights & next (sweep 2026-06-16)
_Από το interface sweep. Το **#1 Outbox** + **dashboard tiles** + **#2 διερεύνηση** πιάνονται
ΤΩΡΑ (βλ. `claude/polish-touches`)· τα παρακάτω είναι το follow-up._
- **#2 console split — «εκτός τρέχοντος καναλιού» vs «πραγματικά ανεπιβεβαίωτο».** _✅ SHIPPED (ήταν
  ήδη χτισμένο, η σημείωση ήταν stale): το `missingAtAade` σπάει mode-aware σε **imported legacy ΜΑΡΚ**
  (`legacy_id` set → prod MARK που το sandbox δεν επιστρέφει = ενημερωτικό) vs **native** (πραγματική
  ασυμφωνία). `SalesReconciliationResult::{imported,real,noise}MissingAtAade()` + `discrepancyCount()`
  εξαιρεί το sandbox-noise· η κονσόλα δείχνει banner + «Λείπουν από AADE» (μόνο real) + collapsed
  «Εισαγμένα (ΜΑΡΚ άλλου καναλιού)»· 3 unit tests (`SalesReconciliationResultTest`)._
- **#5 χαρακτηρισμός εισροών — rules-engine ✅ SHIPPED** («Κανόνες χαρακτηρισμού» + `ExpenseClassifier`:
  auto-apply στο import + bulk «Εφαρμογή κανόνων» + worklist «Προς χαρακτηρισμό» + «Δημιουργία κανόνα»).
  **Μένει deferred:** η **υποβολή για λογαριασμό τρίτου** (λογιστής) που θέλει `entityVatNumber` [323]
  (βλ. «Expenses — λογιστής/`entityVatNumber`»). Ιδέα: ekdosi **ετοιμάζει** τους χαρακτηρισμούς, ο
  λογιστής (δικό του login + ΑΦΜ + έγκριση) τους **στέλνει** — θέλει διερεύνηση ρόλων/δικαιωμάτων.
  _(#6 Βιβλίο→period report + Panel utility CSS: ✅ SHIPPED — βλ. «Done recently».)_

## 🖥️ Console/interface polish (B — sweep 2026-06-16)
- _(**Auto-refresh-on-stale** στην Κονσόλα myDATA: ✅ SHIPPED 2026-06-17 — stale banner >6h + opt-in
  `mydata:refresh-console` scheduled warmer (όλα τα snapshots, default OFF, σαν το VAT picture). FEATURES §3.)_
- **Per-row import + «held/needs-review» state** στην κονσόλα-Έξοδα (ήδη στο «myDATA/expenses completeness»)
  — τώρα που υπάρχει το selective picker στη λίστα Έξοδα, το ίδιο μοτίβο ταιριάζει και στην κονσόλα.
- _(**MARK lifecycle chip** — DROPPED 2026-06-17: η πληροφορία ήδη φαίνεται (badge `mydata_state` + ΜΑΡΚ
  + link σε MARK detail/XML + κουμπιά lifecycle + «Ιστορικό»)· ένα γραμμικό chip θα **αντέφασκε** με το
  μοντέλο των δύο ορθογώνιων καταστάσεων `local_status` × `mydata_state` — π.χ. ακυρωμένο τοπικά αλλά
  ακόμα VALID στην ΑΑΔΕ δεν χωράει σε ευθεία ακολουθία. Χαμηλή αξία + κίνδυνος σύγχυσης.)_

## 🧾 ERP-parity ideas (C — sweep 2026-06-16)
_Έχουμε ήδη: balances/Καρτέλα, τραπεζικοί λογαριασμοί, κανάλια είσπραξης (IRIS/vPOS/μετρητά), πληρωμές,
πιστωτικά, προσφορές, recurring services (v1)._
- **Dunning ladder** — κλιμακωτές αυτόματες υπενθυμίσεις ληξιπρόθεσμων (3/7/15/30 ημ.) πάνω στο υπάρχον
  auto-email + `InvoiceBalance` (σήμερα: single resend). Templates ανά σκαλί + opt-out ανά πελάτη.
- **Bank-statement import → match πληρωμών** — ανέβασμα κίνησης (CSV/MT940) → auto-match σε ανοιχτά
  τιμολόγια (ποσό/ημερομηνία/ΑΦΜ) → προτεινόμενες `Payment` εγγραφές προς έγκριση.
- **Per-customer εκπτώσεις/τιμές** — _✅ SHIPPED 2026-06-17 (έκπτωση + τρόπος-πληρωμής ανά πελάτη
  εφαρμόζονται στην έκδοση· τύπος-wins fallback)._ Το **per-product τιμοκατάλογο** (default τιμή
  μονάδας ανά προϊόν×πελάτη) **DROPPED** σκόπιμα: η **per-line έκπτωση** τη στιγμή της έκδοσης +
  το per-customer default discount καλύπτουν την ανάγκη· πίνακας `customer_product_prices` =
  over-engineering χωρίς πραγματικό use case (re-open μόνο αν εμφανιστεί).
- **Multi-currency invoicing** — `currency` υπάρχει στο payload (EUR hardcoded)· πραγματικό FX +
  στρογγυλοποίηση + εμφάνιση. (myDATA θέλει EUR ισοτιμία — προσοχή.)
  _(Aged-receivables + Sendable customer statement: ✅ SHIPPED — βλ. «Done recently».)_
- **Επαφές (shared CRM)** — κοινή οντότητα `Contact` ↔ many customers με ρόλους (π.χ. ένας λογιστής/
  γραφείο που εξυπηρετεί πολλούς πελάτες-πελάτη), αντί για τις σημερινές per-customer `customer_contacts`.
  Σκόπιμα DEFERRED («κρατάμε τις επαφές per customer να μην μπλέξουμε») — future CRM phase· να μη σπάσει
  το per-customer μοντέλο που χρησιμοποιεί ήδη ο Sendable statement.
