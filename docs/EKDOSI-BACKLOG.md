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

### 3. Bug: έξοδα «αταξινόμητο» — προαγωγή inbound classification

**Verdict: ✅ ισχύει (S–M, στοχευμένο).**

Το auto-classify εξόδων είναι **βάσει ΑΦΜ προμηθευτή** (`ExpenseClassifier.php`), όχι από την κατηγορία του
εισερχόμενου. Το import **πιάνει** την per-line classification του εκδότη σε `expense_lines`
(`ExpenseImporter.php:405-406`) αλλά **δεν την ανεβάζει** στο header που διαβάζει το Βιβλίο
(`LedgerBook.php:136`) → «αταξινόμητο».

**Tasks**
- [ ] Προαγωγή inbound per-line classification στο header (ή fallback του Βιβλίου στο line-level) όταν δεν υπάρχει rule
- [ ] (προαιρετικό) auto-rule πρόταση από επαναλαμβανόμενο προμηθευτή

### 4. Ισοζύγιο Πελατών (και Ειδών)

**Verdict: ✅ όντως λείπει (M).** Έχουμε «Πελάτες με υπόλοιπο» + ηλικίωση (`AgedReceivablesReport.php`), όχι
ισοζύγιο περιόδου.

**Tasks**
- [ ] Ισοζύγιο Πελατών: `Υπόλοιπο μεταφοράς | Χρέωση περιόδου | Πίστωση περιόδου | Τελικό` + σύνολα + περίοδος + export
- [ ] Ισοζύγιο Ειδών/Υπηρεσιών (κουμπώνει με #2)

### 5. Εργασίες Είσπραξης (dunning) — 3 φάσεις

**Verdict: ✅ όντως λείπει για εισπρακτέα (M).** Η ηλικίωση είναι read-only. Υπάρχουν leads follow-ups
(`Lead.php`) + service-contract suspension (`ServiceContract.php:73-81`) αλλά **άλλο domain**.

- **Φάση A (S):** κουμπιά πάνω στην ηλικίωση — υπενθύμιση/ανάθεση/αναβολή/σημείωση + στήλες «τελ. επαφή»/«επόμενο βήμα»
- **Φάση B (M):** ενέργεια → task με ημερομηνία/υπεύθυνο στο ημερολόγιο + ιστορικό επαφών στην Καρτέλα
- **Φάση Γ (S):** κλιμάκωση +7/+15/+30 με το υπάρχον `send_customer_statement`

### 6. Ενοποίηση ρυθμίσεων + Wizard νέας εταιρείας

**Verdict: ⚠️ μερικώς (M).** Υπάρχει `SettingsCluster` (οργανωμένα, όχι «loose») αλλά **όχι** ενιαία tabbed σελίδα
ούτε onboarding wizard (`CreateCompany.php:12` = απλό CreateRecord + auto-seed lookups).

**Tasks**
- [ ] Ενιαίο `/settings` με tabs (Εταιρεία · Παραστατικά/Σειρές · ΦΠΑ/myDATA · Πάροχος · Email · Γέφυρες · Πύλη · AI)
- [ ] Wizard νέου tenant (multi-tenant — κάθε νέα εταιρεία το χρειάζεται) + δείκτης πληρότητας στο dashboard

### 7. Γραφήματα / Αναφορές — πέρασμα ποιότητας

**Verdict: ⚠️ επιβεβαιωμένο στον κώδικα (S–M).** 9 widgets (`Reports.php:82-97`), lazy per-widget **αλλά χωρίς cache**
(κάθε ένα `new DashboardMetrics` ξεχωριστά, καμία memoization) → αξιόπιστο το «~25s»· **κανένα skeleton**·
sparse-guard μόνο στο `ProjectionChart.php:43`.

**Tasks**
- [ ] Shared/cached metrics για τα report widgets (να μη ξανα-τρέχει το ίδιο aggregate 9 φορές)
- [ ] Skeleton loaders · empty/sparse states (<3 σημεία → πίνακας/sparkline) · κοινή παλέτα · μορφοποίηση € · dark/mobile

### 8. Dashboard — widgets που λείπουν

**Verdict: ✅ ισχύει (S ανά widget).** Υπάρχουν: receivables € (`OutstandingCustomersTable`/`OverdueInvoicesTable`),
Top **Πελάτες** (`TopCustomersTable`), renewals, myDATA/WHMCS stats. Λείπουν:

- [ ] Top **είδη/υπηρεσίες** (υπάρχει `top_products` service — per-customer ήδη στην Καρτέλα — λείπει company-wide widget)
- [ ] Έσοδα/Έξοδα ανά κατηγορία (βλ. #2)
- [ ] Αξία **pipeline** leads σε € (τώρα `LeadsStats` δείχνει μόνο πλήθος)
- [ ] Top προμηθευτές · Ημερολόγιο επόμενων 7 ημερών (widget)
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

**Verdict: 🤔 η οντότητα υπάρχει (`ServiceContract`)· λείπουν analytics (M).**
- [ ] Στην προβολή συμβολαίου: «πόσες φορές τιμολογήθηκε / συνολικό έσοδο» (derivable από `invoices()`) + price history

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
