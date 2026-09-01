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

- **`PLAN.md`** (repo root) — master roadmap «Ekdosi ως σταδιακή αντικατάσταση WHMCS»
  (4 πυλώνες: **Domains → Payment gateways → Provisioning → Portal**, strangler-fig).
  Domains **OPEN** (Φάσεις A0–A5, βλ. epic «Αντικατάσταση WHMCS» παρακάτω)· Πυλ. B/C έχουν
  ήδη blueprint/seam (`payment-connectors.md` · `ProvisioningModule`), Πυλ. D = νέο.
- **`domains/README.md`** — Πυλώνας A **αναλυτικό design** (pre-build): data model + `DomainRegistrar`
  contract + Openprovider endpoint mapping + .gr/grEPP rules + rich per-domain View + phase gates
  A0–A5. **DESIGN, no code yet.**
- **`paroxos/regulatory-blueprint.md`** + **`paroxos/implementation-plan.md`** — GR
  ΥΠΑΗΕΣ provider + EU PEPPOL. PEPPOL Phase 1 (UBL builder, `peppol:test-submit`) **DONE**;
  provider P0–P5 built/gated (mode=off); **PEPPOL Phase 2 + live provider = OPEN**.
- **`payment-connectors.md`** — card-POS + IRIS design. **NOT-STARTED** (blueprint).
- **`payments` (AR)** — core **DONE** (cockpit/allocator/bank-accounts/refunds); deferred
  connectors → `payment-connectors.md`.
- **`bridges-connectors.md`** — multi-billing-source. Phase 0 (registry seam) **DONE**;
  Phase 1 (real 2nd source) **OPEN**.
- **`ai-assistant-blueprint.md`** — in-app «Βοηθός» + external MCP. In-app chat **DONE** (§16β);
  **external MCP server DONE** (`MCP.md`, §16γ — same registry, tenant-bound token, propose-only
  writes, ops/debug tools). **OPEN follow-ups:** per-tenant OAuth binding (claude.ai multi-company),
  `connection_health` tool (WHMCS/myDATA freshness), curated-KB `knowledge_search` (item ζ below).
- **`whmcs-legacy-plugin-map.md`** — legacy WHMCS plugins → `ekdosi_bridge`. T-1/T-2 **DONE**;
  T-3 cutover **OPEN**.
- **`delivery-provider-split-brain.md`** — ΔΑ provider-vs-direct-myDATA routing (architecture
  lock, **DONE/reference**).
- **`operator-health.md`** · **`dr-without-app-key.md`** · **`go-live-usage-checks.sql.md`** —
  ops runbooks (reference).
- **`archive/`** — closed historical records (the sandbox-validation reports, the
  2026-07 production audit) — the «what happened / evidence» trail, moved out of the
  live `docs/` tree.
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
- **Onboarding** (2026-06-10): DEMO seeder · `ekdosi:install` wizard (+ lookup seeding via `MyDataLookupSeeder`) · global+per-company «Δοκιμή SMTP» · export/import χωρίς passphrase.
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
- **Reconciliation content compare — net/VAT split** — ο `ReconciliationContentComparator`
  (MYD-017) συγκρίνει **μικτό** (±0,01), τύπο, σειρά/ΑΑ, ημ/νία, ΑΦΜ — **όχι** το καθαρό/ΦΠΑ.
  Ένα παραστατικό με **ίδιο μικτό αλλά διαφορετική ανάλυση ΦΠΑ** (π.χ. λάθος κατηγορία 24%↔13%
  με αντισταθμιζόμενο net) περνά ακόμη ως «συμφωνεί». Πρόσθεσε `net` στο `AadeDocSummary`
  (`summary->getTotalNetValue()`, ήδη σε χρήση από `ExpenseImporter`/`MyDataVatAggregator`) +
  στο `LocalDocSnapshot` (`net_total`) και σύγκρινε με την ίδια ανοχή/routing (conflict vs
  incomplete). _Follow-up από το code-review του PR #389._
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
- **Combined Τιμολόγιο–Δελτίο Αποστολής (ΤΔΑ)** — το ΤΔΑ ΔΕΝ είναι ξεχωριστός τύπος:
  είναι ένα 1.1 με `isDeliveryNote=true` + πλήρη movement header (σκοπός, μεταφορικό,
  διευθύνσεις φόρτωσης/παράδοσης). Ο `AadeInvoiceDocument` δεν εκπέμπει combined payload,
  οπότε το seeded «ΤΔΑ» αφαιρέθηκε (MYD-002). Χτίσε το combined document (payload +
  validation + lifecycle) και ξανα-πρόσφερέ το ως τύπο. **Εξάρτηση itemDescr:** το opt-in
  `mydata_send_item_descr` στον monetary builder (`AadeInvoiceDocument`) εκπέμπει `<itemDescr>`
  μόνο για τύπους που το επιτρέπει η ΑΑΔΕ (9.x) — αλλά υπό MYD-003 ο monetary builder
  απορρίπτει κάθε 9.x, οπότε ο κλάδος είναι πλέον μη-προσβάσιμος (τα καθαρά ΔΑ εκπέμπουν
  itemDescr μέσω `DeliveryNoteSubmitter`). Όταν μπει το `isDeliveryNote`, το
  `Codes::allowsItemDescr()` πρέπει να ελέγχει ΑΥΤΟ το flag (combined 1.1) αντί του 9.x τύπου.
- **Πλήρη 9.1 / 9.2 Δελτία Αποστολής** — το 9.1 (συσχετιζόμενο) θέλει payload με
  correlated MARKs (`addCorrelatedInvoice` + επιλογή σχετικών παραστατικών) και το 9.2
  (συγκεντρωτικό) μοντέλο σύνοψης πολλαπλών κινήσεων. Προς το παρόν είναι κρυμμένα από τον
  picker + μπλοκαρισμένα στον submitter (MYD-012, μόνο το 9.3 φιλάρεται μέσω allowlist).
  Ξεμπλόκαρέ τα όταν χτιστεί το μοντέλο (προσθήκη στο `Codes::SUPPORTED_DELIVERY_TYPES`).
- **measurementUnit = 7 (Τεμάχια_Λοιπές Περιπτώσεις) στα ΔΑ** — απαιτεί
  `otherMeasurementUnitQuantity` + `otherMeasurementUnitTitle` (§8.13 note 9, υποχρεωτικά).
  Δεν μοντελοποιούνται ακόμη → το 7 είναι σκόπιμα **μπλοκαρισμένο** (service throw) + κρυμμένο
  από τα line pickers (MYD-016). Full support = νέες στήλες σε `delivery_note_lines` + form fields
  + payload· άνοιξέ το αν το ζητήσει tenant με «λοιπές» μονάδες συσκευασίας (π.χ. παλέτες).
- **CMR (διεθνής φορτωτική)** — _✅ BUILT (Φάσεις 1–4): αυτοτελές `cmr_notes`/`cmr_lines`,
  `CmrResource` (standalone) + action «Δημιουργία CMR» σε Τιμολόγιο/ΔΑ (pre-fill + μεταγραφή
  ΕΛΟΤ-743), editable draft, `CmrPdf` 24-box, αγγλικά στοιχεία εταιρείας._ **Εκκρεμεί Φάση 0**
  (φορολογικά own-gear: move_purpose/ΦΠΑ = λογιστής, ΔΕΝ μπλοκάρει) + προαιρ. Φάση 5 («πακέτο
  εξαγωγής»). **`docs/cmr-international-delivery.md`**. _Μετά deploy: `shield:generate`._

---

## 💶 Ταμειακή εικόνα / cashflow — «τα έξοδα που δεν έρχονται μόνα τους»
_Ιδέα 2026-07-12 (chrismfz). **Θα το δει με τον λογιστή πρώτα.** Επιλέχθηκε ρητά η
προσέγγιση «recurring templates + cashflow view», ΟΧΙ αυτόματη σύλληψη των πάντων._

**Πρόβλημα:** τα **έσοδα** είναι πεντακάθαρα (όλα κόβονται ηλεκτρονικά → «ό,τι κόβεται =
έσοδο»). Τα **έξοδα** ΟΧΙ: ο operator ξέρει τζίρο αλλά όχι μηνιαία εκροή, άρα δεν ξέρει «τι
του περισσεύει», πώς χτίζει αποθεματικό, ποιους μήνες να προσέχει (δώρα/άδειες/13ος-14ος).

**Framing (μη το ξεχάσουμε):** αυτό είναι **διοικητική/ταμειακή εικόνα, ΟΧΙ τα βιβλία του
λογιστή** (μισθοδοσία/ΕΦΚΑ/αποσβέσεις = δικά του). Σκοπός = cash-visibility, όχι δεύτερο
λογιστήριο — αλλιώς φουσκώνει σε κάτι που δεν συντηρείται.

**3 κουβάδες εξόδων** (κατά «το ξέρει το σύστημα μόνο του;»):
- **Α. myDATA GR e-invoiced** (Synapsecom, Alfanet, ΔΕΗ/ΟΤΕ/Vodafone, ΓΡ προμηθευτές) → **λυμένο**,
  `ExpenseImporter`/`ExpenseReconciler` τα τραβάει ήδη.
- **Β. Foreign B2B** (AWS, Hetzner, cPanel, WHMCS, Vultr, DirectAdmin, CloudLinux, JetBackup…) →
  **μόνο αν αυτο-δηλώνονται** (τύπος 14.x)· αλλιώς αόρατα.
- **Γ. Μη-τιμολόγια** (μισθοδοσία, ΕΦΚΑ ιδίων+παιδιών, δάνειο Εθνικής/στεγαστικό γραφείου, δώρα/
  έκτακτα/βλάβες) → **ποτέ** στο myDATA· πρέπει να δηλωθούν χειροκίνητα.

Το κλειδί (το είπε ο operator): **τα περισσότερα Β+Γ είναι recurring με μικρές αυξομειώσεις**
(π.χ. cPanel licenses που παίζουν λόγω accounts). Άρα: «όρισέ το μία φορά, ξαναγίνεται μόνο του».

**Φάσεις:**
1. **Μητρώο «Πάγια / Επαναλαμβανόμενα έξοδα».** Πρότυπο: όνομα, κατηγορία (Μισθοδοσία/ΕΦΚΑ/ΔΕΚΟ/
   Δάνειο/Foreign subs…), ποσό (+flag «κυμαινόμενο»), συχνότητα (μηνιαίο/τριμηνιαίο/ετήσιο), ημέρα,
   περίοδος ισχύος. Ο **scheduler (ήδη live)** φτιάχνει **πρόχειρο `Expense`** ανά περίοδο →
   operator επιβεβαιώνει/διορθώνει ποσό. Γράφεται σαν κανονικό `Expense` (νέο `source` ή `manual`)
   → μπαίνει «τζάμπα» στο `VatPeriodReport` + στο cashflow view.
2. **Ημερολόγιο εποχικών/έκτακτων.** Δώρα Χριστ./Πάσχα, επίδομα αδείας, 13ος/14ος, ετήσιες
   ασφάλειες = πρότυπα ετήσια/εξαμηνιαία αγκυρωμένα σε μήνα → οι «βαριοί μήνες».
3. **Widget «Τι μου περισσεύει»** (Αναφορές, δίπλα στα Έσοδα/μήνα + Εισπράξεις YoY): ανά μήνα
   **Έσοδα − Έξοδα (τιμολογημένα + πάγια) = καθαρή ροή**, γραμμή σωρευτικού **αποθεματικού**,
   σημαδεμένες οι κορυφές. Το payoff.

**⚠️ Ο ένας πραγματικός κίνδυνος — διπλομέτρημα:** πρότυπο «ΔΕΗ» **+** myDATA τιμολόγιο ΔΕΗ = 2×.
Λύση: match/reconcile βήμα, ή κανόνας «το πρότυπο μετράει μόνο αν ΔΕΝ βρεθεί myDATA παραστατικό
τον μήνα». Ίδιο για foreign που ήδη δηλώνονται (14.x). **Ερώτημα για τον λογιστή:** ποια foreign
δηλώνονται ήδη (καθορίζει πόσα μπαίνουν χειροκίνητα). **Υποδομή έτοιμη:** `Expense`+CRUD+import
υπάρχουν· λείπουν μόνο (α) recurring-templates, (β) cashflow widget, (γ) το anti-double-count.

---

## 🔵 Big features (blueprints kept — see index above)
- **PEPPOL Phase 2** — Access-Point transport («send»). Phase 1 (UBL) DONE· θέλει EE provider +
  sandbox creds (Billit/Finbite/Telema…). `paroxos/regulatory-blueprint.md §7`.
- **GR Πάροχος live** — P2–P5 built/gated (mode=off)· θέλει πραγματικά provider creds + sandbox
  (InvoSign/SBZ). `paroxos/`.
- **Provider endpoint hardening (PROV-017 follow-ups)** — το core URL guard (public-https-only,
  no userinfo/query/port≠443, no private/loopback/link-local/CGNAT host, no credentialed redirects)
  ✅ SHIPPED. Είναι **best-effort accident-prevention** (το URL το βάζει έμπιστος operator). Deferred
  hardening για πλήρη anti-SSRF: (α) **resolver-consistency + IP-pin** — ανάλυση με τον ΙΔΙΟ resolver
  (getaddrinfo/`/etc/hosts`, όχι μόνο `dns_get_record`) και POST στην ήδη-ελεγμένη IP (CURLOPT_RESOLVE),
  ώστε να κλείσει το fail-open (κενή ανάλυση = δεν μπλοκάρει) και το TOCTOU/DNS-rebinding· (β) parse_url
  host-confusion — ο έλεγχος γίνεται με `parse_url`, ο connect με curl (πιθανή απόκλιση σε crafted URLs)·
  (γ) provider-managed endpoint-profile registry αντί ελεύθερου URL (vendor-confirmed hosts)· (δ)
  μη-blocking DNS (το `dns_get_record` είναι σύγχρονο στο hot path/form-save).
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

## 🌐 Αντικατάσταση WHMCS (σταδιακή) — master epic → βλ. **`PLAN.md`** (root)
Στόχος: το ekdosi να αντικαταστήσει σταδιακά το WHMCS (**strangler-fig**, όχι big-bang·
`billing_connections` επιτρέπει συνύπαρξη). Σειρά: **Domains → Payment gateways →
Provisioning → Portal**. Το «δύσκολο» (invoices/myDATA/recurring/υπόλοιπα) ήδη γίνεται·
κάθε πυλώνας = 6η/7η υλοποίηση του υπάρχοντος contract+registry pattern. Πλήρες σχέδιο +
data model + phase gates: **`PLAN.md`**.
- **Πυλώνας A — Domains** _(OPEN, πρώτο)_ — **αναλυτικό design: `docs/domains/README.md`** (data
  model, `DomainRegistrar` contract + OP endpoint mapping, .gr/grEPP rules, rich per-domain View,
  «Μεταφορά ιδιοκτησίας», API history). Dedicated `Domain` ↔ `ServiceContract` billing clock·
  registrar modules à la `EInvoiceProviderTransport`: **Openprovider** (gTLDs) + **grEPP** (.gr,
  direct EPP), routing ανά TLD. Φάσεις (stop σε κάθε gate):
  - **A0** θεμέλιο — `companies.enable_domain_management` flag + nav-gating trait +
    `DomainRegistrar` contract/registry/creds/Null + `config('ekdosi.domains.registrars')` +
    `domain_registrar_connections` (super_admin creds).
  - **A1** data model + manual CRUD (`domains`/`domain_tlds`/`domain_tld_prices`/
    `domain_nameservers`/`domain_contacts`) — καταχώριση υπάρχοντος portfolio, μηδέν API.
  - **A2** Openprovider read-only — availability/WHOIS/`domains:sync` (expiry pull).
  - **A3** Openprovider write — register/renew/transfer/NS/DNSSEC/privacy/lock + renewal
    billing (reuse `StageServiceRenewal`) + grace/redemption.
  - **A4** 2ος registrar **grEPP** (.gr/.ελ direct EPP· 2ετία min, no privacy/lock) — αποδεικνύει το abstraction.
  - **A5** polish — bulk availability search, portfolio dashboard, **registrar↔local
    reconciliation** (mirror myDATA reconcile).
- **Πυλώνας B — Payment gateways** → `payment-connectors.md` (IRIS πρώτα· card-POS/Stripe μετά)· πριν το portal.
- **Πυλώνας C — Provisioning modules** → seam `app/Contracts/ProvisioningModule.php` ήδη (βλ. «Services / Provisioning» κάτω).
- **Πυλώνας D — Customer portal** — custom blades / 2ο panel· **τελευταίο** (θέλει A+B έτοιμα).

## 🟢 Services / Provisioning
- **Real provisioning modules** (cPanel/Mailcow/license server) — σήμερα μόνο `NullProvisioningModule`. _(= Πυλώνας C του `PLAN.md`.)_
- **Multi-line service contracts** — v1 = single-line.
- **Pro-forma numbering (draft = προτιμολόγιο)** — σήμερα ο ΑΑ εκχωρείται στη ΔΗΜΙΟΥΡΓΙΑ (`InvoiceNumberer`, `CreateInvoice`), οπότε κάθε draft «καίει» έναν αριθμό της νόμιμης σειράς τιμολογίων → gap αν διαγραφεί/δεν πληρωθεί. Για service-manager pro-forma ροή (στέλνεις πολλά προτιμολόγια, πληρώνονται κάποια) χρειάζεται **δικό τους reference**: είτε ξεχωριστός μετρητής «ΠΡΟΤ-N» (ο πραγματικός ΑΑ μπαίνει στην έκδοση/πληρωμή), είτε το σταθερό `id`. Σημαίνει μετακίνηση εκχώρησης ΑΑ create→issue (ο `InvoiceNumberer` συνειδητά το απέφυγε — τεκμηρίωση εκεί). Το immediate «ο πελάτης χρειάζεται αριθμό-αναφορά» ΗΔΗ καλύπτεται (το draft έχει `invcode` + banner «ΠΡΟΧΕΙΡΟ» στο PDF). Surfaced από το MON-5.

## 🟣 WHMCS loose ends (βλ. `whmcs-legacy-plugin-map.md`)
- **T-3 cutover** — legacy timologia → ekdosi Customers (match `gr_vatno`, upsert, back-ref).
- **Multi-party SPLIT write-back** στο WHMCS (ένα MARK ≠ N invoices).
- **T-4 manual split tools** (transfer_invoice / relid_remover) — χαμηλή προτεραιότητα.
- **«All of a client's third parties» 2ο dropdown** (θέλει `contacts-by-userid` bridge endpoint).

## 💳 Paid/unpaid-aware WHMCS γέφυρα (αμφίδρομη) — epic
_Ιδέα 2026-07-13 (chrismfz). Money-sensitive· Phase 2 γράφει χρήμα στο WHMCS → design-first._

**Πρόβλημα:** σήμερα ο όρος πληρωμής του εκδοθέντος τιμολογίου βγαίνει **αποκλειστικά από τον
τύπο** (`WhmcsInvoiceMapper` → `payment_method_id = invoiceType->payment_method_id`)· το **WHMCS
paid/unpaid status αγνοείται** και **δεν καταγράφεται Payment**. Άρα ένα ΑΠΛΗΡΩΤΟ WHMCS τιμολόγιο
(π.χ. Α.Ε./Δημόσιο/Δημοτική που θέλει «πρώτα τιμολόγιο, μετά πληρωμή») που εκδίδεται κάτω από τον
(cash-term) default τύπο φαίνεται λανθασμένα **εξοφλημένο**, ενώ είναι πραγματική ανοιχτή οφειλή.
Το payload **έχει ήδη** `status`/`datepaid`/`balance` (τα διαβάζει το `CustomerWhmcsLedger`).

**Phase 1 — inbound — ✅ SHIPPED (v1.9.x):** WHMCS `status` από το payload → badge «Πληρωμή WHMCS»
στο inbox· νέα ρύθμιση **«Προεπιλεγμένος τύπος για ΑΠΛΗΡΩΤΑ (επί πιστώσει)»** (`whmcs_default_unpaid_type_id`)·
το «Δημιουργία Παραστατικού» προ-επιλέγει τύπο βάσει status+πρόθεσης (`PendingWhmcsInvoice::suggestedInvoiceTypeId`),
override πάντα· tripwire status-aware (warn και για cash-term unpaid-slot). Auto-issue paid-only.
_(Το `datepaid`/`balance` snapshot δεν χρειάστηκε — το `status` αρκεί· read-on-demand από το payload.)_

**Inbound payment sync (WHMCS → ekdosi) — ✅ SHIPPED (v1.11.x):** `whmcs:sync-payments` (opt-in
scheduled) κλείνει την οφειλή στο ekdosi όταν ένα επί-πιστώσει WHMCS τιμολόγιο πληρωθεί στο WHMCS —
poll-based, money-write μόνο στο ekdosi, only-if-open + lockForUpdate re-read + `transaction_id` dedup
+ AADE-cancel-safe (`WhmcsPaymentSyncer`). **Γνωστά όρια** (από το review): (α) σερβίρει μόνο tenants
που φτάνουν σε `FILED` (myDATA-filing· off-mode drafts μένουν `DRAFTED` → follow-up)· (β) πριν το enable
σε tenant με legacy on-account πληρωμές, επιβεβαίωσε ότι κανένα legacy τιμολόγιο δεν έχει `FILED` pending
row (default OFF = συνειδητό opt-in)· (γ) bridge-only tenant χωρίς native creds εξαιρείται από το loop.

**Phase 2 — outbound (money-write· opt-in· design-first):** Ekdosi payment (σε WHMCS-sourced
τιμολόγιο) → WHMCS `AddInvoicePayment`/mark-paid, ΜΟΝΟ αν όχι-ήδη-πληρωμένο. Κίνδυνοι + δικλείδες:
- **Διπλή πληρωμή** (ο πελάτης πλήρωσε και μέσω WHMCS gateway) → `GetInvoice` status=Unpaid **πριν** το push.
- **Feedback loop** (WHMCS InvoicePaid hook → πίσω στο Ekdosi) → absorb από το **audit-freeze** (filed row → `touch`, όχι δεύτερη εγγραφή).
- **Μερική πληρωμή** → push **ακριβούς ποσού**· < balance μένει Unpaid (σωστό).
- **Retry διπλασιασμός** → **transaction_id** από το Ekdosi Payment για dedup WHMCS-side.
- Νέο plugin op (`add_payment` σε `inbound.php`, HMAC-guarded)· native `AddInvoicePayment` για plugin-less.
- **Opt-in per tenant** + ίσως χειροκίνητο κουμπί «Δήλωση πληρωμής στο WHMCS» αντί πλήρως αυτόματο.

**Υποδομή έτοιμη:** payload έχει status· mapper/draft/CompanyForm/writeback υπάρχουν. Λείπουν: (Φ1)
status-capture + inbox badge + unpaid-default-type + status-aware draft· (Φ2) το outbound payment op.

## 🆕 Settings-in-UI — widen (scheduler + global pages shipped)
- **Role-scoped per-company knobs:** _✅ SHIPPED (trimmed) — «Ρυθμίσεις εταιρείας»
  (`CompanySettings`, `View:CompanySettings`): company_admin self-serves the SAFE subset
  (PDF branding · invoice-mail templates/from · auto-email toggles · backup enable+cadence),
  audited, explicit-whitelist save._ **Still deferred (deliberately super_admin):** the
  credential/infra knobs (myDATA/GSIS/WHMCS/SMTP secrets, e-invoice provider, backup
  passphrase/destinations/retention, tenant identity). `whmcs_auto_issue` stays two-key
  (UI + `companies.whmcs_auto_issue_immediate`). Widen only if a real per-tenant admin
  needs a specific credential delegated — don't bulk-move secrets into company_admin reach.

## 🔐 `UpdateRun` authorization boundary (ΜΗ shield-generated policy)
- Το `UpdateRun` (ιστορικό deploy updates) είναι **global, cross-tenant, super-admin-only,
  immutable** resource — αλλά **δεν** έχει policy και **δεν** είναι στο `ADMIN_FORBIDDEN_RESOURCES`.
  Σήμερα το προστατεύουν μόνο τα Filament overrides της σελίδας (δεν βρέθηκε εκμεταλλεύσιμο route),
  όμως **οποιοδήποτε `Gate::authorize()` πάνω στο model θα εγκρίνει λάθος τους company admins**.
  ⚠️ **ΜΗΝ** πέσει σκέτο `shield:generate` policy εδώ: το stock template δίνει όλα τα CRUD βάσει
  `*:UpdateRun` permissions, που ο `TenantRoleProvisioner` μοιράζει στον `company_admin` (ακριβώς
  αυτό απορρίφθηκε στο review του PR #389). Σωστή λύση: policy που **απαιτεί super admin**,
  επιστρέφει `false` σε κάθε mutation (create/update/delete/restore/forceDelete/replicate/reorder),
  **+ προσθήκη του `UpdateRun` στο `ADMIN_FORBIDDEN_RESOURCES`**, με Gate-level tests (company_admin
  → denied). Ίδιος έλεγχος αξίζει και για τα υπόλοιπα global ops resources.
  **Σημείωση για το «γιατί ξαναεμφανίζεται»:** το `shield:generate` τρέχει μέσα στον seeder
  (βλ. `DatabaseSeederTest`), οπότε **κάθε run της σουίτας ξαναγράφει** το
  `app/Policies/UpdateRunPolicy.php` ως untracked αρχείο. Θα επανεμφανίζεται μέχρι να κλείσει
  το παραπάνω boundary (ή να μπει το resource στο shield exclusion list) — μη το commit-άρεις
  ως έχει επειδή «εμφανίστηκε ξανά».

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
- **WHMCS outbound push — «claimed-but-lost» recovery** _(from the 2-way payment-sync double review, M1)._
  `WhmcsPaymentPusher` claims the `whmcs_payment_pushed_at` marker **before** the WHMCS write (prevents a
  double mark-paid under a manual-vs-auto race) and releases it on a caught failure. If the worker is
  **hard-killed** (OOM/deploy) in the µs window between the claim and the HTTP send, the marker stays set
  but WHMCS was never paid → the invoice drops off every worklist and no UI can re-drive it (fix today =
  manual `whmcs_payment_pushed_at = null`). Very low probability (the WHMCS `transid` dedup already makes a
  re-push double-pay-safe). Options if it ever bites: an operator «Επανάληψη push» action that clears the
  marker, or a reconcile pass that resets a stale-claimed row still Unpaid at WHMCS.
- **Bulk-delete guard** — single-record guarded (PR #258)· `DeleteBulkAction`/`ForceDeleteBulkAction` αφύλακτα.
- **Soft-deleted FK rows render blank** — `withTrashed()` label + «deleted» badge για rows πριν τον guard.
- _**`GrProviderSubmitter::cancel()` non-9.3 guard** — ✅ SHIPPED 2026-07-07: service-level hard-refuse με μήνυμα «έκδοσε πιστωτικό (5.1)» για κάθε τύπο ≠ 9.3, ώστε μη-UI callers (automation/bulk) να μη χτυπούν opaque `[283]`. (Το UI ήδη γκρεϊτάρει το `cancel_at_mydata` σε 9.3-only.) Βλ. `mydata-sandbox-myd2-retry-2026-07-07.md`._

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
  ανακύκλωσης €0,08 / διαμονής, προ-ρυθμισμένα με myDATA Τέλη §8.7· idempotent._
- **`clear:right`** σε single-word doc-types (PDF tweak).

## 🧰 Setup / onboarding helpers (from-zero — sweep 2026-06-17)
_Ήδη: **web installer `/install`** (from-zero σε φρέσκο host: `.env`+`APP_KEY`+`migrate`+super-admin,
fail-closed/self-disabling, filesystem-token gate — βλ. FEATURES §17) · `ekdosi:install` wizard (CLI) ·
`MyDataLookupSeeder` (VAT/invoice types/payment-delivery methods/aims/units, με one-click
`StandardLookupSeedAction` ανά resource) · `DemoCompanySeeder` · GSIS/VIES lookup · `suppliers:sync` ·
«Πρότυπα τελών». Ιδέες για ευκολότερο στήσιμο από το 0:_
- **Web installer — follow-ups:** (α) προαιρετικό `CREATE DATABASE` όταν ο DB χρήστης έχει δικαίωμα (τώρα
  απαιτεί προ-δημιουργημένη κενή βάση — το σωστό default σε shared hosting)· (β) auto-detect writable
  dirs / PHP extensions ως preflight βήμα με πράσινο/κόκκινο πριν το submit· (γ) optional «γράψε το cron
  line / systemd unit» helper αντί για απλή λίστα ελέγχου.
- **Generic CSV importer (προϊόντα / πελάτες)** — bulk onboarding από άλλο σύστημα (έχουμε CSV *export*
  `CsvEntityExporter`· λείπει το *import*). Column-map + dry-run preview + tenant-scope. _Το μεγαλύτερο
  win για μεταφορά καταλόγου/πελατολογίου._
- **Setup profiles ανά κλάδο** (λιανική / εστίαση / ξενοδοχείο / υπηρεσίες) — bundle σε ένα κλικ: invoice
  types + default ΦΠΑ + σχετικά «πρότυπα τελών» (ξενοδοχείο → διαμονής· λιανική → σακούλα/ανακύκλωσης) +
  payment methods. Πάνω στο υπάρχον seeding.
- **Curated tax-presets** (βλ. PDF/UX ideas) — withholding/Ψηφιακό Τέλος Συναλλαγής presets ανά κλάδο για το per-invoice
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
