# Ekdosi — Backlog από σύγκριση με Epsilon Smart (VERIFIED)

> Προέκυψε από live σύγκριση **Ekdosi vs Epsilon Smart** (16/09/2026). Το αρχικό
> προσχέδιο γράφτηκε βλέποντας **μόνο το UI**· αυτή η έκδοση είναι **διασταυρωμένη με
> τον κώδικα** (file:line) από τρεις read-only ελεγκτές, οπότε κάποια ευρήματα του
> προσχεδίου **διορθώθηκαν**. Συμπληρωματικό του `docs/BACKLOG.md` (tech-debt) — εδώ ζει
> ο roadmap «τι μας λείπει vs ο ανταγωνισμός».

**Λεζάντα προτεραιότητας:** 🔴 P0 · 🟠 P1 · 🟡 P2 · ⚪ απορρίφθηκε
**Effort:** S = ώρες · M = 1–3 μέρες · L = εβδομάδα+
**Verdict ελέγχου:** ✅ ισχύει · ⚠️ μερικώς · ❌ λάθος του UI-προσχεδίου · 🤔 υπάρχει αλλιώς

---

## ⚠️ Διορθώσεις στο UI-only προσχέδιο (διαβάστε πρώτα)

Τρία ευρήματα του προσχεδίου ήταν **λάθος** — αλλάζουν προτεραιότητες, όλα υπέρ μας:

1. **Η «Καρτέλα δεν υπολογίζει σωστά το υπόλοιπο» → ❌ ΛΑΘΟΣ.** Το υπόλοιπο υπολογίζεται
   σωστά (αύξουσα, oldest→newest, μετά reverse για εμφάνιση — `CustomerLedgerBuilder.php:756-772,802`).
   Το «−1.160,92» είναι απλώς η λίστα newest-first: το υπόλοιπο μειώνεται προς το παρελθόν.
   Αρνητικό βγαίνει μόνο σε **πραγματικά πιστωτικό** πελάτη. **Δεν υπάρχει money bug** — υποβαθμίζεται από P0.
2. **«Δεν έχουμε tags/κατηγορία προϊόντος» → ❌ ΛΑΘΟΣ.** Τα Products έχουν ήδη `HasTags`
   (`Product.php:39`) **και** business `ProductCategory` (`ProductCategory.php:16-27`). Το data model
   υπάρχει — λείπει μόνο ο **reporting άξονας**. Άρα το «Έσοδα ανά κατηγορία» είναι πολύ φθηνότερο.
3. **«Οι χρεώσεις/συνδρομές είναι μόνο WHMCS + MRR metric» → ❌ ΛΑΘΟΣ.** Υπάρχει first-class
   `ServiceContract` (`app/Models/ServiceContract.php`) με έναρξη/λήξη/τελευταία-χρέωση/renewal history.
   Λείπουν μόνο analytics ανά συμβόλαιο.

---

## 🔴 P0

### 1. Ηλεκτρονική τιμολόγηση B2B (UBL / EN 16931 / PEPPOL BIS 3.0)

**Verdict: ✅ ισχύει τεχνικά — αλλά η βάση είναι ΗΔΗ εδώ. Effort step-1: S–M.**

Επιβεβαιωμένο: το ekdosi κάνει **myDATA filing** (native ή μέσω παρόχου InvoSign — `EInvoiceSubmitterFactory.php:53-60`,
`InvoSignTransport.php`) + προαιρετικό PDF email (`DispatchesAcceptanceEmail.php`). **Δεν** παράγει/στέλνει
δομημένο EN16931/UBL στον παραλήπτη, ούτε δέχεται εισερχόμενα e-invoices. myDATA ≠ ηλεκτρονική τιμολόγηση.

**ΟΜΩΣ — το «εσθονικό» μοντέλο είναι ήδη country-agnostic:** το `PeppolInvoiceDocument.php` χτίζει
**PEPPOL BIS Billing 3.0 (EN 16931)** — πανευρωπαϊκό προφίλ, όχι εσθονικό. Διαβάζει το country_code/ΑΦΜ
του tenant, μαπάρει `EL→GR` (`:208`), και το `'EE'` είναι μόνο fallback. Παράγει έγκυρο UBL για **GR σήμερα**
(build + validate· `xml()` :74, `validate()` :89). Λείπει μόνο ο transport (send) — ε (Phase 2, `ee-peppol`→NullSubmitter).

**Φάσεις (αποφασισμένη κατεύθυνση 16/09):**
- **Phase 1 — Παραγωγή + κουμπί (S–M):** action «Λήψη UBL (Peppol BIS 3.0)» στο παραστατικό (ViewInvoice) →
  build → validate → download XML. Country-agnostic hardening (fallback = country του tenant, όχι `'EE'`·
  επιβεβαίωση GR VAT category mapping στο `PeppolVatCategory`). Δουλεύει για **όλες** τις εταιρείες (myip/nexon,
  ξεκλειδώνει Nixpal). READ-ONLY, μηδέν αποστολή.
- **Phase 2 — Αποστολή (L):** Access Point / provider transport + delivered/rejected lifecycle + inbound e-invoice.
  **Πρώτα διερεύνηση:** τι υποστηρίζει ο InvoSign σε EN16931-UBL send/receive; (μπορεί να καλυπτόμαστε ήδη downstream).

**Tasks (Phase 1)**
- [ ] Action «Λήψη UBL» στο ViewInvoice → `PeppolInvoiceDocument::xml()` + εμφάνιση `validate()`
- [ ] Fallback country = `company->country_code` αντί σκέτο `'EE'`
- [ ] Έλεγχος GR VAT category mapping (24/13/6 → σωστές EN16931 κατηγορίες) στο `PeppolVatCategory`
- [ ] (προαιρετικό) bulk export + JSON variant

**Tasks (Phase 2 — μετά)**
- [ ] Ερώτηση στον InvoSign: EN16931-UBL έκδοση/αποστολή/λήψη ή μόνο myDATA σύνοψη;
- [ ] Access-Point transport + delivered/rejected ανά παραστατικό · λήψη εισερχομένων · πεδία endpoint/GLN πελάτη
- [ ] Επιβεβαίωση legal deadline με λογιστή/πάροχο (οι ημερομηνίες του προσχεδίου είναι από τρίτες πηγές)

---

## 🟠 P1

### 2. Έσοδα ανά κατηγορία / ετικέτα προϊόντος — **best value/effort**

**Verdict: ✅ core ισχύει (M). — ⏳ Phase 1 DONE 2026-09-16 (forward-only).**

Το Βιβλίο Εσόδων-Εξόδων ομαδοποιεί έσοδα **μόνο** κατά myDATA class του τύπου παραστατικού
(`LedgerBook.php:92`) — γι' αυτό όλα πέφτουν σε ένα «category1_3». Tags + business `ProductCategory` **υπάρχουν ήδη**·
λείπει μόνο η αναφορά/άξονας.

**Tasks**
- [x] **Αναφορά «Έσοδα ανά κατηγορία»** (`RevenueByCategoryReport` + `App\Services\Accounting\RevenueByCategory`):
  ανά ekdosi ProductCategory, έτος, % τζίρου, YoY, export CSV. Οι WHMCS γραμμές παίρνουν κατηγορία μέσω
  νέας στήλης «Κατηγορία ekdosi» στη σελίδα «Αντιστοίχιση WHMCS» (`whmcs_income_maps.product_category_id`),
  σφραγισμένη στη γραμμή στην εισαγωγή (`invoice_lines.product_category_id`). **Forward-only.**
- [ ] **Backfill ιστορικών** WHMCS γραμμών (description→package→group→category) — τα προ-mapping παραστατικά
  δείχνουν «Αταξινόμητα» μέχρι τότε.
- [ ] Dashboard widget «Έσοδα ανά κατηγορία» · Bulk-assign κατηγορίας/tags στη λίστα ειδών + φίλτρο
- [ ] Ίδιο για έξοδα · Bonus: MRR/churn ανά κατηγορία · (tags ως εναλλακτικός άξονας)
- [ ] **P2 (review):** στη σελίδα «Αντιστοίχιση WHMCS», αν ο operator βάλει «Κατηγορία ekdosi» αλλά αφήσει
  τη §8.6 κενή, η γραμμή διαγράφεται και η κατηγορία χάνεται (coupling — income_class_category NOT NULL)·
  disclosed στο help text, αλλά θέλει per-row validation notice. Επίσης `RevenueByCategory` `whereYear`
  είναι non-sargable (συνεπές με τις άλλες αναφορές· range θα κρατούσε το index).
- [ ] **P2 (review, surviving):** `RevenueByCategory` — (α) το `total_net` του footer στρογγυλοποιεί το
  άθροισμα των ΜΗ-στρογγυλεμένων per-category nets, ενώ κάθε γραμμή δείχνει `round(per-category net)` → το
  άθροισμα των εμφανιζόμενων γραμμών μπορεί να διαφέρει ±1 λεπτό από το ΣΥΝΟΛΟ (και το % να μην αθροίζει
  ακριβώς 100)· (β) το όνομα κατηγορίας πέφτει σε `#<id>` όταν `description_short` κενό, ενώ το dropdown
  «Αντιστοίχιση WHMCS» δείχνει `description` → δύο ονόματα για την ίδια κατηγορία (ευθυγράμμιση σε
  `description_short ?: description ?: '#id'`)· (γ) το CSV total row τυπώνει σκληρά «100,00» για το % τζίρου
  ακόμη κι όταν `total_net<=0`, ενώ η οθόνη δείχνει «—»· (δ) `header_discount_percent ≥ 100` (anomalous
  data — η φόρμα το κόβει στο 99,99) δίνει factor ≤ 0 → σιωπηλά αρνητικά/μηδενικά έσοδα, ενώ το
  `InvoiceVatBreakdown` πετάει exception στην ίδια τιμή (ασυνεπής χειρισμός· η αναφορά δεν έχει guard).
  **Το header-discount overstatement (P1) διορθώθηκε.**

### 3. Bug: έξοδα «αταξινόμητο» — προαγωγή inbound classification

**Verdict: ✅ ισχύει (S–M, στοχευμένο). — ✅ core DONE 2026-09-17 (Βιβλίο fallback).**

Το auto-classify εξόδων είναι **βάσει ΑΦΜ προμηθευτή** (`ExpenseClassifier.php`), όχι από την κατηγορία του
εισερχόμενου. Το import **πιάνει** την per-line classification του εκδότη σε `expense_lines`
(`ExpenseImporter.php:405-406`) αλλά **δεν την ανέβαζε** στο header που διαβάζει το Βιβλίο
(`LedgerBook.php:136`) → «αταξινόμητο».

**Tasks**
- [x] **Fallback του Βιβλίου στο line-level** όταν λείπει ο χαρακτηρισμός κεφαλίδας:
  `Expense::effectiveClassificationCategory()` (κεφαλίδα → αλλιώς κυρίαρχη γραμμή κατά καθαρή αξία →
  αλλιώς null). Read-only — δεν γράφει την κεφαλίδα, δεν πειράζει το `classification_state`/worklist
  ούτε τον χαρακτηρισμό προς ΑΑΔΕ. Το `LedgerBook` (και ό,τι περνά από αυτό: `income_vs_expense`,
  εξαγωγή) το χρησιμοποιεί.
- [ ] (προαιρετικό) auto-rule πρόταση από επαναλαμβανόμενο προμηθευτή
- [ ] (P2) Το Βιβλίο δίνει **μία** γραμμή/κατηγορία ανά έξοδο (κυρίαρχη) — για doc με μικτές γραμμές
  δεν σπάει τα ποσά ανά κατηγορία (συνεπές με το header path που κι αυτό χαρακτηρίζει όλο το doc σε μία).
  Split ανά κατηγορία = χωριστό, μεγαλύτερο enhancement και για τα δύο paths.

### 4. Ισοζύγιο Πελατών (και Ειδών)

**Verdict: ✅ όντως λείπει (M). — ✅ Πελατών DONE 2026-09-17.** Έχουμε «Πελάτες με υπόλοιπο» + ηλικίωση
(`AgedReceivablesReport.php`)· τώρα και ισοζύγιο περιόδου πελατών.

**Tasks**
- [x] **Ισοζύγιο Πελατών** (`CustomerTrialBalance` page + `App\Services\Accounting\CustomerTrialBalanceReport`):
  ανά πελάτη `Υπόλοιπο μεταφοράς | Χρέωση | Πίστωση | Τελικό`, ελεύθερη περίοδος, σύνολα, drill Καρτέλα, CSV.
  Χτισμένο πάνω στο `CustomerLedgerBuilder::periodBalances` (reuse όπως το AgedReceivables) → το «Τελικό»
  ισοσκελίζει με το υπόλοιπο Καρτέλας + τα receivables (test-guarded). Perm `View:CustomerTrialBalance`
  (shield:generate μετά το deploy). Per-customer iteration (consistency > raw speed, όπως AgedReceivables).
- [ ] **(P2 review) Perf σε μεγάλο tenant:** candidate set = ΚΑΘΕ πελάτης με κίνηση (όχι narrowed σε
  `onlyDebtors` όπως η ηλικίωση) — απαραίτητο για ιστορική περίοδο (χρειάζεται και opening-balance πελάτες,
  που το «now»-debtors θα έχανε). 2 queries/πελάτη, re-run σε κάθε αλλαγή ημερομηνίας. Αν χρειαστεί:
  debounce ή pre-agg SQL (με reconciliation test φύλακα). Αποδεκτό tradeoff προς το παρόν.
- [ ] Ισοζύγιο Ειδών/Υπηρεσιών (κουμπώνει με #2)

### 5. Εργασίες Είσπραξης (dunning) — 3 φάσεις

**Verdict: ✅ όντως λείπει για εισπρακτέα (M). — ✅ Φάση A DONE 2026-09-17.** Η ηλικίωση ήταν read-only.
Υπάρχουν leads follow-ups (`Lead.php`) + service-contract suspension (`ServiceContract.php:73-81`) αλλά **άλλο domain**.

- [x] **Φάση A:** κουμπί «Εργασία είσπραξης» πάνω στην ηλικίωση (ανάθεση/επόμενο βήμα+ημ/νία/σημείωση/καταγραφή
  επαφής, ένα modal) + στήλες «Επόμενο βήμα»/«Τελ. επαφή». Κατάσταση σε `customers.collection_*` (record-keeping,
  tenant-scoped, ίδιο gate `View:AgedReceivables`). Οι 4 «κουμπιά» ενοποιήθηκαν σε ένα modal-editor (λιγότερο
  clutter στη φαρδιά γραμμή)· καμία ειδοποίηση/κλιμάκωση (Φάση Γ), κανένα per-event ιστορικό (Φάση B).
- [ ] **Φάση B (M):** ενέργεια → task με ημερομηνία/υπεύθυνο στο ημερολόγιο + ιστορικό επαφών στην Καρτέλα
- [ ] **Φάση Γ (S):** κλιμάκωση +7/+15/+30 με το υπάρχον `send_customer_statement`
- [ ] **(P2 review, accepted για Φάση A):** η ενέργεια γράφει `customers.collection_*` κάτω από το gate
  `View:AgedReceivables` (χωρίς ξεχωριστό `Update`-ability). Μόνο collection metadata (ποτέ money/ταυτότητα/
  myDATA)· ok για Φάση A, αλλά ίσως δικό του ability αργότερα. Επίσης: ο assignee ελέγχεται και write-time
  (πέρα από το Select-options validation του Filament) — το ίδιο latent pattern (options-only) υπάρχει στο
  `LeadForm::assigned_user_id`· καλυμμένο από το ίδιο Filament Select validation, explicit guard αν χρειαστεί.

### 6. Ενοποίηση ρυθμίσεων + Wizard νέας εταιρείας

**Verdict: ⚠️ μερικώς (M).** Υπάρχει `SettingsCluster` (οργανωμένα, όχι «loose») αλλά **όχι** ενιαία tabbed σελίδα
ούτε onboarding wizard (`CreateCompany.php:12` = απλό CreateRecord + auto-seed lookups).

**Tasks**
- [ ] Ενιαίο `/settings` με tabs (Εταιρεία · Παραστατικά/Σειρές · ΦΠΑ/myDATA · Πάροχος · Email · Γέφυρες · Πύλη · AI)
- [ ] Wizard νέου tenant (multi-tenant — κάθε νέα εταιρεία το χρειάζεται) + δείκτης πληρότητας στο dashboard

### 7. Γραφήματα / Αναφορές — πέρασμα ποιότητας

**Verdict: ⚠️ επιβεβαιωμένο στον κώδικα (S–M). — ✅ core DONE 2026-09-17 (cache + € + Ανανέωση).**
9 widgets (`Reports.php`), lazy per-widget **χωρίς cache** (κάθε ένα `new DashboardMetrics` ξεχωριστά) → «~25s».

**Tasks**
- [x] **Cache ανά κομμάτι** (`DashboardMetricsCache`, version-keyed ανά tenant) — τα widgets διαβάζουν
  warm cache αντί να ξανα-τρέχουν τα aggregates. `dashboard:warm-metrics` (scheduler, default ON)
  προθερμαίνει ανά tenant (τρέχον + προηγούμενο έτος)· κουμπί «Ανανέωση» = άμεσος version bump. TTL
  config-driven. `DashboardMetrics` έμεινε άθικτος (μηδέν αλλαγή στις μετρήσεις).
- [x] **Μορφοποίηση €** στους άξονες/tooltips όλων των γραφημάτων (`FormatsReportChart`) + **κοινή παλέτα**
  (`ReportPalette`, ίδια χρώματα, μία πηγή).
- [x] **Empty states** στα dashboard γραφήματα (2026-09-17) — Filament built-in empty state (κρυμμένο canvas +
  κεντραρισμένο μήνυμα) στα `TopProductsChart`/`RevenueByCategoryChart`/`TopSuppliersChart` μέσω κοινού trait
  `HasChartEmptyNote` (override `isEmpty()`) + `$emptyStateHeading`. Dark/mobile-safe by construction.
- [ ] **(declined ως over-engineering)** custom skeleton loaders (το built-in lazy indicator του Filament αρκεί)
  + sparse-<3-σημεία-→-πίνακας/sparkline (πολυπλοκότητα για overview γράφημα χωρίς αντίστοιχη αξία). Dark/mobile:
  safe-by-construction (Chart.js responsive + `ReportPalette` dark-safe + € via `Intl`). Οι Reports charts (μηνιαία/
  ετήσια σειρά) σπάνια είναι κενές· empty-state εκεί = μικρό follow-up αν ποτέ ζητηθεί.

### 8. Dashboard — widgets που λείπουν

**Verdict: ✅ ισχύει (S ανά widget). — ✅ PR-1+PR-2 DONE 2026-09-17.**
Υπάρχουν: receivables € (`OutstandingCustomersTable`/`OverdueInvoicesTable`),
Top **Πελάτες** (`TopCustomersTable`), renewals, myDATA/WHMCS stats. Λείπουν:

- [x] Top **είδη/υπηρεσίες** — `TopProductsChart` (top-N κατά καθαρή αξία, reuse `CustomerTopProducts::forCompany`,
  cached 30' — υλοποιεί γραμμές σε PHP).
- [x] **Έσοδα ανά κατηγορία** — `RevenueByCategoryChart` (reuse `RevenueByCategory`, ίδια πηγή με την αναφορά #2).
- [x] **Top προμηθευτές** — `TopSuppliersChart` (top-N ανά καθαρή αξία εξόδων, SQL groupBy, gated `ViewAny:Expense`).
- [x] Αξία **pipeline** leads σε € — stat στο `LeadsStats` (μικτή αξία προσφορών ανοιχτών leads· quote-derived,
  μηδέν schema change).
- [x] Ημερολόγιο επόμενων 7 ημερών — **δεν χτίστηκε (redundant):** καλύπτεται από `UpcomingRenewalsTable`
  (ανανεώσεις 30μ) + `LeadsCalendar` (επόμενα βήματα leads) + `OverdueInvoicesTable`. Μόνο τα collection
  next-steps (#5) δεν είναι σε dashboard widget (ζουν στην AgedReceivables) — μικρό follow-up αν χρειαστεί.
- [ ] **Έξοδα** ανά κατηγορία widget (κουμπώνει με το «ίδιο για έξοδα» του #2).
- [x] Empty-state στα νέα γραφήματα — DONE 2026-09-17 (βλ. #7). Skeleton/sparse-table: declined (over-engineering).
- [ ] (P2 review, accepted) τα τρία νέα γραφήματα cache-άρονται per tenant+year (TTL 30', PHP materialisation)
  χωρίς invalidation σε invoice write → έως 30' staleness. Overview surfaces (όχι money-consistency invariant),
  αποδεκτό· proper version-keyed invalidation + scheduled warm = #7-polish follow-up (όπως `DashboardMetricsCache`).
  **Το empty-state το κάνει πιο ορατό** (νέος tenant βλέπει «προς εμφάνιση φέτος» ~30' μετά την 1η εγγραφή)· φθηνή
  ενδιάμεση λύση = να ΜΗΝ cache-άρεται το άδειο αποτέλεσμα (empty compute είναι φθηνό) μέχρι να μπει το invalidation.
- [ ] (P2 review round-2, deferred — root-fixes εκτός του «2 widgets» scope):
  - **Header-discount reconciliation:** το `TopProductsChart` αθροίζει `net_price` γραμμών (χωρίς invoice-level
    header discount), όπως ήδη κάνει το `CustomerTopProducts::forCompany` (Καρτέλα + MCP top_products), ενώ το
    `RevenueByCategoryChart` εφαρμόζει τον header-discount factor. Διαφέρουν ΜΟΝΟ όταν υπάρχει header discount
    (σπάνιο). Root-fix = αλλαγή του shared service (επηρεάζει 3 surfaces) → χωριστό PR.
  - **Perf:** `RevenueByCategory::build` υπολογίζει ΚΑΙ το prior year (για YoY) που το γράφημα πετά· το
    `forCompany(PHP_INT_MAX)` υλοποιεί όλες τις γραμμές του έτους σε PHP· και το `TopSuppliersChart` υλοποιεί
    τα έξοδα του έτους σε PHP (χρειάζεται per-row credit-note sign, άρα όχι σκέτο SQL `SUM`). Όλα cached 30',
    αλλά current-year-only / SQL-aggregated (CASE sign + `GROUP BY COALESCE(supplier_id, name)`) variants θα τα
    έκοβαν → με το ίδιο caching follow-up.
  - **Label collisions:** `Str::limit` μπορεί να κάνει δύο μακριά ονόματα ίδια στο γράφημα → μαζί με το
    shared-base cleanup (μικρό nicety· π.χ. tooltip με πλήρες όνομα).
  - **Pipeline heuristic (PR-2):** το «Αξία pipeline» παίρνει την ΤΕΛΕΥΤΑΙΑ ζωντανή προσφορά ανά ανοιχτό lead
    (created_at, tiebreak id)· ένα φρέσκο Draft revision μπορεί να υπερκεράσει ένα ζωντανό Accepted. Το quote-derived
    pipeline είναι εξ ορισμού προσεγγιστικό (τα leads δεν έχουν πεδίο αξίας)· status-priority (Accepted>Sent>Draft)
    = μελλοντική βελτίωση. Επίσης uncached (τρέχει στο 60s poll όπως τα COUNTs του `LeadsStats`) και δείχνει
    quote-value πίσω μόνο από `ViewAny:Lead` (χωρίς Quote-gate) — συνεπές με το leads-card, dashboard-wide αν αλλάξει.
  - **Shared base (PR-2):** `TopSuppliersChart`/`TopProductsChart`/`RevenueByCategoryChart` μοιράζονται σχεδόν όλο
    το scaffold (cache-30', sort-by-net, TOP_N, Str::limit, FormatsReportChart, empty-guard) → abstract `TopNMoneyChart`
    base σε cleanup PR (μαζί με το #7 polish), όχι τώρα (θα άγγιζε 3 widgets).
  - **Dashboard revenue visibility:** τα widgets (όπως τα αδελφά `IncomeStatsOverview`/`TopCustomersTable`) δεν
    έχουν `canView()` → όποιος βλέπει το panel βλέπει company-wide έσοδα. Συνεπές με το υπάρχον dashboard· αν
    θέλουμε role-gate, είναι dashboard-wide απόφαση (όχι μόνο αυτών των δύο).
- [ ] (προαιρετικό) user-customizable dashboard

---

## 🟡 P2

### 9. «Ψίχουλα» Καρτέλας (από το ξεκαθαρισμένο P0.2)

**Verdict: ✅ μικρά αλλά πραγματικά (S). — ✅ DONE 2026-09-16 («Καρτέλα polish v2»).**
- [x] **Πληρωμή με κενό ποσό (#377):** οι 0/NULL-amount πληρωμές (ETL raw insert που παρακάμπτει τον MON-8
  guard του μοντέλου — ο guard ήδη μπλοκάρει την app-δημιουργία) **παραλείπονται στο render** της καρτέλας
  (0 στο υπόλοιπο/σύνολα → καμία κενή γραμμή, καμία αλλαγή ποσού).
- [x] **Ρητό tiebreak** στο sort της Καρτέλας (`(ημ/νία, created_at, τύπος, id, αναφορά)`) — ντετερμινιστικό.
- [x] Γραμμή «Υπόλοιπο από μεταφορά» σε φιλτραρισμένη περίοδο (= κλείσιμο προηγούμενου έτους).

### 10. Ταμειακό ημερολόγιο (Εισπράξεων–Πληρωμών)

**Verdict: ✅ όντως λείπει (S).** Το Βιβλίο Εσόδων-Εξόδων καλύπτει τα Ημερολόγια Πωλήσεων/Αγορών (καλύτερα).
Λείπει το **ταμειακό**: οι πληρωμές/περίοδο ομαδοποιημένες ανά τρόπο πληρωμής + λογαριασμό με σύνολα
(η λίστα `Πληρωμές` είναι flat — `PaymentsTable.php`, χωρίς groups/summarize).

### 11. Χρεώσεις — analytics ανά συμβόλαιο

**Verdict: 🤔 η οντότητα υπάρχει (`ServiceContract`)· λείπουν analytics (M). — ✅ DONE 2026-09-17.**
- [x] **Στατιστικά χρέωσης στην προβολή** (`ServiceContractBilling`, tenant-scoped/reusable για μελλοντικό
  `/user` portal): φορές τιμολογήθηκε · **συνολικό έσοδο** (καθαρό, live, μείον πιστωτικά — μέσω `InvoiceScope`,
  ισοσκελίζει με τζίρο) · μικτό · πρώτη/τελευταία χρέωση · εκκρεμή πρόχειρα · **ιστορικό τιμής καταλόγου** (audit
  log). Tab **«Ανανεώσεις»** (`RenewalsRelationManager`) = read-only λίστα των invoices του συμβολαίου.
- [x] **«Σύνδεση υπάρχοντος παραστατικού» (retro-link)** — «κουμπώνει» ένα ήδη-εκδομένο παραστατικό του πελάτη
  σε σύμβαση (θέτει μόνο `service_contract_id`· tenant+customer scoped, re-checked στο write· mirror του
  `ConvertQuoteToServiceContract`). Για χειροκίνητες πωλήσεις πριν φτιαχτεί το συμβόλαιο (π.χ. ΤΠΥ VM στο nexon).
- [ ] **(config, όχι κώδικας)** «βγάλε προσχέδιο N μέρες πριν τη λήξη» = υπάρχει ήδη: `services:stage-renewals
  --lead-days=N` (`EKDOSI_SERVICE_RENEWALS_LEAD_DAYS`, scheduler `EKDOSI_SCHEDULE_SERVICE_RENEWALS`). Set στο deploy.
- [ ] **(ιδέα, επόμενο PR)** proactive «κουδούνι»/email για συμβόλαια που λήγουν σε N μέρες (τώρα: nav badge 7μ +
  dashboard widget 30μ + το auto-staged προσχέδιο).
- [ ] **(P2 review, deferred)** το tab «Ανανεώσεις» δείχνει μόνο τα invoices του συμβολαίου (`service_contract_id`)·
  τα **πιστωτικά δεν φέρουν `service_contract_id`** (τα βρίσκουμε μέσω `credited_invoice_id`), οπότε δεν εμφανίζονται
  inline — τα σύνολα όμως τα αφαιρούν (disclosed με helper στα tiles + description στο tab). Inline εμφάνιση πιστωτικών
  θέλει custom query εκτός της `invoices()` relation (fragile OR/soft-delete precedence) → ξεχωριστό enhancement.

> **🔒 Locked architectural decision — Services module για MyIP/hosting:** κρατάμε **ΕΝΑ** «Υπηρεσίες»
> και το εξελίσσουμε προσθετικά (service_type/κατηγορίες + ProvisioningModule drivers + addons ως child
> rows + progressive disclosure), **ΟΧΙ** clone σε ξεχωριστό «Hosting Services». Πλήρες σκεπτικό +
> evolution path → **`docs/services-module-evolution.md`**.

### 12. Έργα (projects) — **χαμηλή προτεραιότητα**

**Verdict: ✅ absent (L).** Νόημα μόνο αν ένα έργο μαζεύει έσοδα+έξοδα από πολλά παραστατικά (μικτό περιθώριο).
Για καθαρά recurring δεν χρειάζεται. Μένει backlog χωρίς χρονοδιάγραμμα.

### 13. Πεδία χρήστη (custom fields)

**Verdict: ✅ absent (S).** Υπάρχουν εσωτερικά notes που καλύπτουν σήμερα. Ιδέα για όταν προκύψει συγκεκριμένη ανάγκη.

---

## ⚪ Απορρίπτονται

| Τι | Γιατί |
|---|---|
| Αξιόγραφα / επιταγές (+ widgets) | Καμία επιταγή από το 2007 — online & μετρητά μόνο |
| Αποθήκη / απογραφή | Δεν κάνουμε εμπορία φυσικών αγαθών |
| Λιανική (Χ/Ζ, Ευκαιρίες/Εργασίες Λιανικής) | Δεν υπάρχει λιανική· στο Epsilon απλώς διπλασιάζει το μενού |
| ~120 items ρυθμίσεων του Epsilon | Εκεί γίνεται δυσκίνητο — βλ. #6 για τη σωστή εκδοχή |

---

## Τι έχουμε και ΔΕΝ έχει το Epsilon (να μη χαλάσουν)

Πύλη πελατών · Γέφυρα WHMCS (+ Εισερχόμενα + duplicate audit) · MRR/ανανεώσεις/`ServiceContract` ·
Διασταύρωση ΑΑΔΕ + Έλεγχος ΜΑΡΚ + αδέσποτα · **E3 income classification ορατό (ό,τι βλέπεις = ό,τι υποβάλλεις)** ·
Βοηθός AI με κοστολόγηση · Υγεία συστήματος + Ενημερώσεις · Multi-tenant (Εταιρείες/Χρήστες/Ρόλοι) ·
Αναφορές (heatmap/εποχικότητα/πρόβλεψη/DSO/ΦΠΑ ανά συντελεστή) · CMR/φορτωτικές · **MCP interface** (κανένας ανταγωνιστής).

---

## Πηγές (legal — προς επιβεβαίωση με λογιστή/πάροχο)

- ΑΑΔΕ / Forin.gr / Taxheaven / lido.app: υποχρεωτική Η/Τ B2B σε δύο κύματα (2/2/2026 τζίρος >1εκ · 1/10/2026 λοιποί).
  Οι ημερομηνίες είναι από τρίτες πηγές του προσχεδίου — **επιβεβαίωσέ τις πριν χρονοπρογραμματίσεις το Phase 2.**
