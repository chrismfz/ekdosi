# AUDIT.md — Έλεγχος ετοιμότητας παραγωγής (production-readiness)

**Ημερομηνία ελέγχου:** 2026-07-03 · **Commit βάσης:** `0fee5fc`
**Μέθοδος:** 7 ανεξάρτητοι τομεακοί έλεγχοι πάνω στον **πραγματικό κώδικα** (όχι στα
docs) — myDATA/ΑΑΔΕ συμμόρφωση, χρηματικά/στρογγυλοποιήσεις, tenant isolation &
security, backups/ops/deploy, PDF/QR/email, seeders/onboarding, WHMCS bridge — συν
πλήρες τρέξιμο του test suite. Τα ευρήματα με τη μεγαλύτερη βαρύτητα
επαληθεύτηκαν χειροκίνητα στον κώδικα (file:line) πριν καταγραφούν.

**Test suite:** ✅ **1452/1453 passed** (5.424 assertions, 1 skipped, 1 risky, exit 0)
σε sqlite — όπως και το CI (`.github/workflows/laravel.yml`). Τα MariaDB-only
row-lock tests ΔΕΝ τρέχουν σε CI (βλ. SET-3).

> **Πώς δουλεύουμε το αρχείο:** κάθε εύρημα έχει ID + checkbox. Όταν διορθώνεται,
> τσεκάρεται εδώ + γράφεται στο `CHANGELOG.md`. Νέος επανέλεγχος τομέα → νέα
> ενότητα με ημερομηνία, δεν σβήνουμε την ιστορία.

---

## Ετυμηγορία (TL;DR)

**Όχι ακόμη — αλλά κοντά.** Ο πυρήνας είναι αντικειμενικά production-grade:
μαθηματικά ΦΠΑ/εκπτώσεων πιστό port του legacy, αρίθμηση ατομική (lock σε
transaction, αδύνατο διπλό ΑΑ), mark-persistence transactional, tenant isolation
χωρίς κανένα ευρεθέν leak, HMAC σωστά και στις δύο πλευρές του bridge, ο
`MyDataLookupSeeder` σπέρνει σωστά AADE defaults, και 1.452 tests περνάνε.

Ό,τι κόβει το go-live είναι **λίγο και συγκεκριμένο** — εκτίμηση ~1–2 εβδομάδες
στοχευμένης δουλειάς:

| # | Blocker | Γιατί |
|---|---------|-------|
| 1 | **MYD-1** — έκπτωση κεφαλίδας → βέβαιη απόρριψη ΑΑΔΕ [207]/[209] | Προσυμπληρώνεται από την έκπτωση πελάτη· ρουτίνα, όχι edge case. Το παραστατικό μένει άδηλωτο ενώ το ΑΑ έχει καεί. |
| 2 | **OPS-1/OPS-2** — δεν υπάρχει λειτουργικό whole-DB backup εκτός VM + οι ειδοποιήσεις αποτυχίας πάνε σε `your@example.com` | Απώλεια δίσκου = απώλεια των βιβλίων και των 3 tenants. |
| 3 | **DOC-1** — δεν τυπώνεται ΠΟΤΕ η αιτία απαλλαγής ΦΠΑ στο PDF | Νομική απαίτηση (ΕΛΠ ν.4308/2014 αρ.9) για κάθε 0% παραστατικό (π.χ. ενδοκοινοτικά). |
| 4 | **DOC-2 + MYD-3** — ακυρωμένο τοπικά παραστατικό τυπώνεται/στέλνεται σαν έγκυρο, και μπορεί και να υποβληθεί στην ΑΑΔΕ | Δήλωση εσόδου που η επιχείρηση έχει ακυρώσει. |
| 5 | **WH-1..4** — guards στο auto-issue ΠΡΙΝ οπλιστεί το `whmcs_auto_issue_immediate` | Μη-EUR, λάθος συντελεστής ΦΠΑ, αρνητικές γραμμές, διπλή υποβολή στο dual-run. (Αν το auto-issue μείνει OFF, υποβιβάζονται σε HIGH.) |
| 6 | **SEC-1** — απόφαση: `EKDOSI_ENCRYPT_SECRETS_AT_REST=true` + `secrets:reencrypt`, ή ρητή αποδοχή plaintext-at-rest | Τα backups/dumps κουβαλούν myDATA/SMTP/WHMCS credentials σε cleartext. |

Αμέσως μετά (πρώτες εβδομάδες): exception reporting (OPS-3), το «κάψιμο»
qty_returned στην ακύρωση πιστωτικού (MON-1), πιστωτικά στο VAT report (MON-2),
ΓΕΜΗ στο PDF (DOC-4), guard στον `DatabaseSeeder` (SET-1).

---

## A. myDATA / ΑΑΔΕ (MYD)

### Blockers / High

- [x] **MYD-1 · BLOCKER · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-03** (κατανομή έκπτωσης στις γραμμές, `AadeInvoiceDocument::allocateDiscountedLineAmounts` + tests· ⚠ εκκρεμεί sandbox run με discount>0) — **Παραστατικό με έκπτωση κεφαλίδας απορρίπτεται από την ΑΑΔΕ με [207]/[209].**
  `app/Services/EInvoice/AadeInvoiceDocument.php:138` στέλνει per-line `netValue`
  από το `invoice_lines.net_price`, που **δεν** περιέχει την έκπτωση κεφαλίδας
  (βλ. `InvoiceLine` saving hook), ενώ το summary (`:221`) παίρνει
  `InvoiceVatBreakdown::totalNet()` που **την εφαρμόζει**. Σ(γραμμών) ≠ σύνολο →
  ValidationError [207] (net) + [209] (ΦΠΑ). Το `header_discount_percent`
  **προσυμπληρώνεται** από την έκπτωση πελάτη
  (`app/Filament/Resources/Invoices/Pages/CreateInvoice.php:99`) — δηλ. αρκεί ένας
  πελάτης με default discount. Όλα τα fixtures/sandbox runs είχαν discount=0, γι'
  αυτό δεν πιάστηκε. Στο PEPPOL ο φάκελος της έκπτωσης στις γραμμές ΕΓΙΝΕ
  (`tests/Feature/Peppol/PeppolInvoiceDocumentTest.php:141`) — στο AADE document όχι.
  **Fix:** κατανομή της έκπτωσης κεφαλίδας στα per-line netValue/vatAmount (όπως
  στο PEPPOL) ή hard-refuse υποβολής όταν `header_discount_percent > 0`, + έλεγχος
  στο `MyDataConfigAudit`/preflight. Sandbox validation μετά.

- [ ] **MYD-2 · HIGH · ΠΙΘΑΝΟ (μηχανισμός επιβεβαιωμένος)** — **Retry μετά από timeout μπορεί να διπλο-υποβάλει (2 MARKs = διπλά δηλωμένο έσοδο).**
  `app/Services/MyDataSubmitter.php:140-142`: σε transport exception το
  `mydata_state` μένει null → το retry ξαναστέλνει `SendInvoices`. Η υπόθεση του
  κώδικα (`AadeInvoiceDocument.php:290-296`) ότι η ΑΑΔΕ κάνει server-side dedup με
  δικό της uid **δεν τεκμηριώνεται από το spec για ERP** (το [233] «Αφορά μόνο τους
  παρόχους»). Επίσης ΔΕΝ υπάρχει lock/re-fetch μέσα στο `submit()` — δύο χειριστές
  που πατούν «Υποβολή» ταυτόχρονα POSTάρουν και οι δύο. Mitigation σήμερα: το
  ημερήσιο reconcile εμφανίζει το ορφανό MARK. **Fix:** (α) sandbox πείραμα
  resubmit-after-timeout για να γίνει η υπόθεση γεγονός, (β) `lockForUpdate` +
  re-check state μέσα στο `submit()`, (γ) σε transport failure σήμανση «in-doubt»
  που απαιτεί reconcile/`RequestTransmittedDocs` πριν επιτραπεί ξανά υποβολή.

- [ ] **MYD-3 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — **«Υποβολή στο myDATA» ορατή σε τοπικά ακυρωμένα παραστατικά.**
  `app/Filament/Resources/Invoices/Pages/ViewInvoice.php:384` — visibility ελέγχει
  μόνο `mydata_state === null`, όχι `local_status`. Το bulk submit το κάνει σωστά
  (`InvoicesTable.php:333-345` σκιπάρει cancelled). Σενάριο: ακύρωση ενεργού
  αδήλωτου παραστατικού → το κουμπί μένει → υποβολή ακυρωμένης πώλησης ως έσοδο.
  **Fix (one-liner):** `&& $record->local_status !== 'cancelled'`.

### Medium

- [ ] **MYD-4 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Χωρίς mapping, κάθε τρόπος πληρωμής δηλώνεται «Μετρητά» (type 3): `AadeInvoiceDocument.php:321-326` fallback σιωπηλό· το `MyDataConfigAudit` ΔΕΝ ελέγχει payment methods. Με POS-interconnection/IRIS καθεστώς, συστηματική δήλωση «μετρητά» για κάρτες = audit-relevant. **Fix:** preflight warning + hard-fail σε production mode.
- [ ] **MYD-5 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ (design limit)** — Ένας χαρακτηρισμός εσόδου ανά ΤΥΠΟ παραστατικού: μικτό τιμολόγιο αγαθών+υπηρεσιών παίρνει λάθος E3 σε όλες τις γραμμές. ΟΚ για τους σημερινούς services-only tenants· να κλείσει πριν μπει μικτός tenant.
- [ ] **MYD-6 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Καμία διασταύρωση τύπου↔χώρας αντισυμβαλλόμενου (1.2/1.3/2.2/2.3 σε GR πελάτη ή 1.1/2.1 σε ξένο) → opaque απόρριψη [242]-[244] στην υποβολή. Επίσης ξένος counterpart με κενή διεύθυνση παίρνει fabricated `'Unknown'/'00000'` (`AadeInvoiceDocument.php:414-416`) — ασυνεπές με το hard-fail του `DeliveryNoteSubmitter::requireAddress`. **Fix:** preflight check + hard-fail αντί για placeholder.
- [ ] **MYD-7 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Cancel πέτυχε στην ΑΑΔΕ / τοπικό write απέτυχε → μένει VALID τοπικά και το retry ΔΕΝ αυτο-θεραπεύεται (το [251] «already cancelled» γράφεται ως CANCEL_REJECTED και throw, `MyDataSubmitter.php:322-327`). Πιάνεται από το ημερήσιο reconcile (≤1 μέρα έκθεση). **Fix:** αναγνώριση [251] → sync τοπικού state.

### Low

- [ ] **MYD-8 · LOW** — `Codes::FILEABLE_VAT_RATES` (`Codes.php:400`) δεν περιέχει το 3.0 ενώ το override-path το υποβάλλει (κατ. 9) → ψευδο-warning στο preflight/ETL, ασυμφωνία μεταξύ των δύο audit surfaces.
- [ ] **MYD-9 · LOW** — Το config audit δεν ελέγχει `mydata_requires_quantity` vs goods/services τύπο ([204]/[205] rejection στο πρώτο filing χειροποίητου τύπου)· ο seeder το βάζει σωστά.

**Στέρεα (ελέγχθηκαν αντιπαραθετικά, βρέθηκαν σωστά):** transactional mark+mirror
persist (state αλλάζει ΜΟΝΟ σε επιβεβαιωμένο Success)· AADE-HTTP αυστηρά εκτός
transaction (κανένα orphan-MARK window)· triple already-filed guard· cancel:
πρώτα ΑΑΔΕ μετά τοπικά, AADE-cancel terminal· σχήμα payload πλήρες vs spec για
μη-εκπτωτικά (counterpart rules [219]/[220], πέντε μηδενικά tax totals [101], όχι
uid [273], όχι ΦΠΑ σε taxesTotals [226], conditional quantity [205], withholding
[208] με εξαίρεση §8.4 8/9/10, 5.1/5.2 πιστωτικά)· `Codes.php` σωστό vs §8
(spot-checked 8.1/8.2/8.3/8.4/8.9/8.12 — τίποτα stale/εφευρεμένο)· reconciler
(pagination, cancellation folding, duplicate-MARK detection, bucketing)· πλήρες
ΔΑ lifecycle με blocked purposes και σωστό value-less σχήμα.

---

## B. Χρηματικά / Υπόλοιπα / Αρίθμηση (MON)

- [ ] **MON-1 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — **Ακύρωση πιστωτικού «καίει» οριστικά τις επιστραφείσες ποσότητες — το αρχικό δεν ξανα-πιστώνεται.**
  `app/Actions/IssueCreditNote.php:113-141` μόνο αυξάνει `return_invoice_extras.qty_returned`·
  κανένα path δεν το μειώνει. Μετά από AADE-cancel του πιστωτικού το χρηματικό
  σκέλος επανέρχεται σωστά, αλλά η επανέκδοση σκάει με «Επιστροφή > διαθέσιμη
  ποσότητα 0.000». Σενάριο ρουτίνας: λάθος πιστωτικό → ακύρωση → επανέκδοση =
  αδύνατη χωρίς DB surgery. **Fix:** decrement/rollback του qty_returned όταν το
  πιστωτικό ακυρώνεται (τοπικά ή AADE).

- [ ] **MON-2 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — **Το VAT report αγνοεί τα πιστωτικά στις εκροές** (υπερ-δηλώνει τοπικά ΦΠΑ εκροών): `DashboardMetrics::baseInvoices()` (`:641-649`) κάνει `whereNull('credited_invoice_id')` αντί να τα αφαιρεί, ενώ στις εισροές αφαιρούνται (`VatPeriodReport.php:84-101`) και η Καρτέλα κάνει sign-flip. Δεν υποβάλλεται πουθενά (informational), αλλά τα εταιρικά νούμερα διαφωνούν με Σ(Καρτελών) όταν υπάρχουν πιστωτικά.
- [ ] **MON-3 · MEDIUM · ΠΙΘΑΝΟ** — Ταυτόχρονες πληρωμές: το `InvoiceBalance::recompute` (`:133-152`) κλειδώνει το invoice αλλά τα SUM διαβάζονται από REPEATABLE READ snapshot που στήθηκε πριν το lock → ο «χαμένος» writer μπορεί να γράψει stale `paid_total` μέχρι το επόμενο event. **Fix:** lock πριν από κάθε consistent read ή recompute after-commit.
- [ ] **MON-4 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ (εν μέρει by-design)** — ΑΑ δεσμεύεται στη δημιουργία DRAFT και τα drafts διαγράφονται (`EditInvoice.php:60-66`) → κενά αρίθμησης με ένα κλικ + δυνατότητα μη-χρονολογικών `issued_at` μέσα στη σειρά (το finalize δεν αγγίζει την ημερομηνία). Κανένα διπλό ΑΑ δεν είναι δυνατό (επιβεβαιωμένο). Θέμα φορολογικής υγιεινής — απόφαση/τεκμηρίωση: ή αρίθμηση στην οριστικοποίηση, ή ρητή πολιτική για τα κενά.
- [ ] **MON-5 · LOW** — Τα drafts μετράνε πλήρως σε έσοδα/εισπρακτέα/ΦΠΑ/Καρτέλα (`InvoiceScope::live()` φιλτράρει μόνο ακυρώσεις). Συνειδητό (πιστωτικά-draft) και συνεπές παντού, αλλά τα staged renewals/WHMCS drafts φουσκώνουν dashboards και εκτυπωμένα statements.
- [ ] **MON-6 · LOW** — AI `CountSalesTool` (`:50-56`) μετράει πιστωτικά ως θετικές πωλήσεις (ίδιο check θέλει το `VatSummaryTool`).
- [ ] **MON-7 · LOW** — Gross-edit: στρογγυλοποίηση net σε 2dp στο form (`InvoiceForm::netFromGross`) → 10,00€ @24% ξαναϋπολογίζεται 9,99€. Εγγενές στο `decimal(14,2)`· ενημέρωση χειριστή ή 4dp ενδιάμεσο.
- [ ] **MON-8 · LOW** — Καμία θετική-τιμής validation στα payments (form + `record_payment`)· αρνητική «πληρωμή» παρακάμπτει το refund mechanism (ο `PaymentAllocator` guard-άρει σωστά).

**Στέρεα:** τα κανονικά μαθηματικά (line discount → header discount → per-rate
VAT, round μόνο σε boundaries) ταιριάζουν το spec του CLAUDE.md και το legacy
proc· `payable_total`/[208] single-source με τον submitter· αρίθμηση ατομική
(lock+bump ίδιο transaction, rollback-safe)· cache columns γράφονται ΜΟΝΟ από
`InvoiceBalance` (forceFill, όχι fillable)· `live()` σε ΟΛΑ τα money sites που
βρέθηκαν· lifecycle: myDATA HTTP μετά το commit, EditInvoice draft-only με
TOCTOU re-check, IssueCreditNote κλειδώνει το αρχικό. `MoneyStatusConsistencyTest`
πραγματικό (5 seeds, invariants dashboard=Σledger=caches) — δεν καλύπτει: header
discounts, fees/stamp, cash-term, τοπικά ακυρωμένα πιστωτικά.

---

## C. PDF / QR / Email — νομικό περιεχόμενο εγγράφων (DOC)

- [x] **DOC-1 · HIGH (νομικό) · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-03** (§8.3 verbatim citation στο totals box, non-throwing· tests) — **Η αιτία απαλλαγής ΦΠΑ δεν τυπώνεται πουθενά στο PDF.** Το δεδομένο υπάρχει και υποβάλλεται στην ΑΑΔΕ (`vat_categories.vat_exemption_category`), αλλά grep για «απαλλαγ/exempt» στα `resources/views` = 0 hits. Κάθε 0% τιμολόγιο (ενδοκοινοτικό, αρ.39α κλπ.) βγαίνει χωρίς την απαιτούμενη αναφορά διάταξης (ΕΛΠ ν.4308/2014 αρ.9 §1ιβ — να επιβεβαιωθεί με λογιστή η ακριβής διατύπωση). **Fix:** τύπωμα του §8.3 label στο per-rate breakdown ή στο footer.
- [ ] **DOC-2 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — **Τοπικά ακυρωμένο + VALID στην ΑΑΔΕ τυπώνεται σαν πλήρως έγκυρο** (banner logic μόνο σε `mydata_state`, `resources/views/invoices/pdf.blade.php:136-142`): χωρίς ΑΚΥΡΩΘΕΝ, με QR + «Πιστοποιημένο». Το public route είναι fail-closed, αλλά download/email από χειριστή όχι. Υπο-περίπτωση: cancelled+null τυπώνει «ΠΡΟΧΕΙΡΟ» (λάθος ταμπέλα). **Fix:** banners = συνάρτηση `local_status` × `mydata_state`.
- [ ] **DOC-3 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — **Εκδοθέν-αλλά-μη-υποβληθέν τυπώνει μόνιμα «ΠΡΟΧΕΙΡΟ — ΔΕΝ ΕΧΕΙ ΥΠΟΒΛΗΘΕΙ ΣΤΗ myDATA»** — για τον εσθονικό tenant (`NullSubmitter`, state μένει null) ΚΑΘΕ νόμιμο τιμολόγιο κουβαλάει DRAFT banner για πάντα, με αναφορά σε myDATA. **Fix:** μαζί με DOC-2 (τρίτη κατάσταση «εκδόθηκε, εκτός myDATA»).
- [ ] **DOC-4 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — **ΓΕΜΗ: δεν υπάρχει καν πεδίο** στο `companies` και δεν τυπώνεται (ν.4919/2022 αρ.22 απαιτεί αριθμό ΓΕΜΗ στα έγγραφα — επιβεβαίωση με λογιστή)· ούτε το επάγγελμα/δραστηριότητα εκδότη (το `kad_primary` υπάρχει, δεν τυπώνεται). Σήμερα μόνο workaround μέσω `pdf_footer_text`.
- [ ] **DOC-5 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ (logic)** — Το ΜΑΡΚ τυπώνεται ΜΟΝΟ μέσα στο QR block (`pdf.blade.php:163-171`): VALID invoice με mark αλλά χωρίς `mydata_url` (ETL-imported legacy rows) βγαίνει χωρίς ΜΑΡΚ και χωρίς QR, χωρίς draft banner. Το ΔΑ template το κάνει σωστά (standalone «ΜΑΡΚ:»). **Fix:** αποσύζευξη ΜΑΡΚ κειμένου από QR.
- [ ] **DOC-6 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Drafts/ακυρωμένα μπορούν να σταλούν χειροκίνητα/bulk με email που λέει «…που εκδόθηκε…» (`ViewInvoice.php:757-761`, `InvoicesTable.php:371-382` — κανένα status gate)· τα auto paths είναι σωστά gated. **Fix:** gate ή warning στα manual/bulk.
- [ ] **DOC-7 · LOW** — Ακυρωμένο-στην-ΑΑΔΕ τυπώνει ακόμα QR + footer «Πιστοποιημένο στη myDATA» δίπλα στο ΑΚΥΡΩΘΕΝ banner (gates μόνο σε `mydata_url`).
- [ ] **DOC-8 · LOW** — Markdown injection στο σώμα email μέσω placeholders (πελατικό όνομα με `[text](url)` γίνεται live link — όχι javascript:)· escape γίνεται, το markdown parsing μετά το ξανανοίγει.
- [ ] **DOC-9 · LOW** — Μικρο-ασυνέπειες: CMR σε αγγλικό number format (μάλλον σωστό/να τεκμηριωθεί)· ledger view αγγλικό format ενώ το PDF ελληνικό· αγγλικά action labels («Email PDF to customer»)· πιθανό overflow σε πολύ μακριά domain strings στις περιγραφές.

**Στέρεα:** QR = πάντα το AADE-provided URL (ποτέ δεν κατασκευάζεται τοπικά),
600px + quiet zone· DejaVu Sans παντού (ελληνικά + €), γνωστό και προστατευμένο
το font-weight pitfall· ελληνικό format `1.234,56 €` μέσω ενός helper· το PDF
ταιριάζει με το filed payload (ίδιο `InvoiceVatBreakdown` + `additionalTaxAdjustment`)·
ΠΡΟΧΕΙΡΟ banner υπαρκτό· πιστωτικά με αμφίδρομη αναφορά· ΔΑ PDF πλήρες· public
route signed+fail-closed· email pipeline με per-tenant SMTP/From χωρίς
cross-tenant leak, πλήρες mail log, double-send guard στα auto paths· branding
per-render χωρίς static state.

---

## D. WHMCS bridge (WH)

> Τα WH-1..4 είναι **προϋπόθεση πριν οπλιστεί** το «Άμεση τιμολόγηση»
> (`whmcs_auto_issue_immediate`) σε production. Με το auto-issue OFF (και τα δύο
> κλειδιά είναι default off) παραμένουν HIGH αλλά όχι blockers, γιατί μεσολαβεί
> χειριστής + preview με warnings.

- [ ] **WH-1 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ (απουσία)** — Κανένα currency guard στο map/file/auto-issue path: `WhmcsInvoiceMapper` δεν κοιτάει `currencycode` (το feed το στέλνει). USD $120 → παραστατικό €120 στην ΑΑΔΕ. **Fix:** hold σε μη-EUR στο ingestor/filer.
- [ ] **WH-2 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Το WHMCS `taxrate` δεν συγκρίνεται ποτέ με τον default συντελεστή του tenant (`WhmcsInvoiceMapper.php:278` uses default VAT unconditionally)· η απόκλιση φαίνεται ΜΟΝΟ στο manual preview — `file()`/auto-issue δεν έχουν totals-mismatch check. **Fix:** filer-level guard: recomputed gross vs `whmcs_total` εντός tolerance, αλλιώς hold (καλύπτει και το WH-5).
- [ ] **WH-3 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Auto-issue αγνοεί το `legacy_invoiced` flag (dual-run με το legacy app) → παράθυρο διπλής υποβολής στην ΑΑΔΕ· το ίδιο το tooltip του badge προειδοποιεί για διπλή υποβολή αλλά ΚΑΝΕΝΑΣ κώδικας δεν το επιβάλλει. **Fix:** exclude `legacy_invoiced > 0` στα candidates + guard στο `assertCanBeFiled()`.
- [ ] **WH-4 · HIGH · ΠΙΘΑΝΟ** — Αρνητικές γραμμές (WHMCS promos/credits) περνάνε ως αρνητικό net → XSD rejection ΑΦΟΥ το τοπικό Invoice έχει δεσμεύσει ΑΑ (ghost invoice — ακριβώς το σενάριο που κλείνει το 0%-VAT preflight, το οποίο κοιτάει μόνο `vat_percent == 0`). **Fix:** negative-line preflight δίπλα στο `refuseProblematicZeroVatLines()`.
- [ ] **WH-5 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ (self-documented)** — Per-line back-compute `round(amount/1.24,2)×1.24` → filed σύνολο ±0,01€/γραμμή vs WHMCS· ο unattended δρόμος δεν το μπλοκάρει. Λύνεται με το guard του WH-2.
- [ ] **WH-6 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — `getInvoicesForClient()` (`WhmcsClient.php:297-302`) χρησιμοποιεί το αγνοούμενο `limit` (η ίδια κλάση bug με το frozen-at-16) → το per-customer ledger βλέπει μέχρι 25 rows χωρίς pagination loop. Read-only, αλλά ελλιπής εικόνα.
- [ ] **WH-7 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Αποτυχημένο MARK write-back: `whmcs_writeback_state='failed'` χωρίς κανένα retry surface (η «future retry-sweep command» δεν υπάρχει, και το log υποδεικνύει λάθος next-step). AADE OK αλλά το WHMCS badge μένει «Όχι στο AADE» μέχρι tinker. **Fix:** retry command ή Filament action.
- [ ] **WH-8 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ σχήμα** — Splitter: καμία διαβεβαίωση πληρότητας Σ(item_ids ανά group) == payload items (skew σεναρίων audit-frozen rows)· και κενές περιγραφές με ποσό droppάρονται σιωπηλά σε ΟΛΑ τα mapping paths → πιθανό under-billing. Διπλομέτρημα αδύνατο (verified). **Fix:** completeness assertion + refuse σε non-zero blank-description.
- [ ] **WH-9 · LOW** — Το feed `paid_unfiled` μετά το legacy cutover μεγαλώνει για πάντα (δεν εξαιρεί ids με mark στο `mod_ekdosi_invoice_marks`) — O(N) 15λεπτη δουλειά, idempotent αλλά άσκοπη. One-line join. Επίσης: χωρίς replay-nonce στα HMAC endpoints (mitigated — όλα idempotent)· held rows audit-frozen χωρίς «re-stage» hint· EU reverse-charge εκφράζεται μόνο με ΜΙΑ tenant-wide αιτία απαλλαγής (κρατιέται σωστά, capability gap).

**Στέρεα:** HMAC και στις 2 πλευρές (hash_equals, no fallthrough, 401/422,
logging χωρίς secrets)· το `tblinvoices.invoiced` ΔΕΝ γράφεται runtime
(verified — μόνο το SchemaGuard healing)· idempotency/double-issue προστασία
πραγματικά σφιχτή (unique upsert + race catch, `file()` re-lock +
`assertCanBeFiled`, submit εκτός tx με tripwire, `withoutOverlapping`)· το
frozen-at-16 bug διορθωμένο στην πηγή του (limitstart/limitnum + loop guard)·
mass-pay/consolidated detection και hold· VAT net-vs-gross payload-authoritative·
doc-type ανά δικαιούχο με HOLD σε κάθε ασάφεια· plugin SQL/CSRF καθαρά· versions
συνεπή (0.41.0 = CHANGELOG).

---

## E. Ops: Backups / Scheduler / Queue / Deploy / Monitoring (OPS)

- [x] **OPS-1 · BLOCKER · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-03** (schedule flags default ON· `BACKUP_DESTINATION_DISKS` env· go-live gate «Καθολικό αντίγραφο ΒΔ»· INSTALL.md §11/§14/§15 + restore drill· ⚠ στο prod host: όρισε off-site disk + `BACKUP_ARCHIVE_PASSWORD` + κάνε το drill) — **Whole-DB backup: local-only, default-OFF, και το INSTALL.md δεν το προβλέπει.**
  `config/backup.php:175-177` → μόνο `['local']` disk· `config/ekdosi.php:118-123`
  → `EKDOSI_SCHEDULE_BACKUP_RUN/CLEANUP/MONITOR` default false· το INSTALL.md §11
  στήνει cron μόνο για scheduler/queue, το §14 checklist δεν έχει backup item, και
  το §15 παραπέμπει σε `app/Console/Kernel.php` **που δεν υπάρχει** στο Laravel 13.
  Αποτέλεσμα: πιστή εκτέλεση του INSTALL.md = μηδέν αυτόματα DB backups. (Τα
  per-tenant backups υπάρχουν και έχουν alerting — αλλά βλ. OPS-5 για το τι
  περιέχουν by default.) **Fix:** enable τα 3 schedule flags, off-site disk
  (S3/SFTP), `BACKUP_ARCHIVE_PASSWORD`, ενημέρωση INSTALL.md §11+§14.
- [x] **OPS-2 · BLOCKER (μαζί με OPS-1) · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-03** (`OpsBackupNotifiable` + κοινό `BackupAlertRecipients` με τα per-company alerts· success mails σιωπηλά) — Ειδοποιήσεις αποτυχίας spatie backup σε **`your@example.com`** hardcoded (`config/backup.php:248`, χωρίς env override). Ακόμα κι όταν ενεργοποιηθεί το OPS-1, αποτυχία δεν ειδοποιεί κανέναν.
- [ ] **OPS-3 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — **Κανένα exception reporting**: `withExceptions()` άδειο, κανένα Sentry/Flare, `LOG_STACK=single`, cron `>> /dev/null 2>&1`. Για app που εκδίδει νομικά έγγραφα, τα σιωπηλά failures είναι το #1 λειτουργικό ρίσκο. **Fix:** έστω mail/Slack log channel σε `error` level, ή Sentry.
- [ ] **OPS-4 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — `ops:health` επιστρέφει ΠΑΝΤΑ 0 (`OperatorHealth.php:90`) → το `deploy/update.sh:160` gate είναι νεκρός κώδικας και δεν μπαίνει σε cron monitoring. **Fix:** non-zero exit σε RED findings.
- [ ] **OPS-5 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Τα per-tenant «off-site» backups by default ΔΕΝ περιέχουν τα βιβλία: `bucket` default `settings_setup` (migration `2026_06_09_000003:24`)· invoices/payments/marks μόνο σε `full`· και το `ops:health` δείχνει «Off-site ok» χωρίς να κοιτάει bucket → ψευδής αίσθηση DR. **Fix:** default `full` ή bucket-aware check.
- [ ] **OPS-6 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Deploy/restore με ζωντανό queue worker: στο `update.sh` ο worker τρέχει παλιό κώδικα ΚΑΤΑ το `migrate` (queue:restart μετά, `:149`)· στο `rollback.sh:47-54` το restore τρέχει με live worker → jobs γράφουν σε μισο-restored πίνακες. **Fix:** stop/drain worker πριν migrate/restore.
- [ ] **OPS-7 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ μηχανισμός** — `db-restore` χωρίς schema reset: πίνακας που δημιούργησε το «κακό» migration επιζεί του restore ενώ το migrations row γυρνάει πίσω → το επόμενο deploy σκάει («table already exists»). Και το snapshot παίρνεται ΠΡΙΝ το `artisan down` (`update.sh:95-99`) → μικρό παράθυρο χαμένων writes (θα μπορούσε να χαθεί τοπικά ΜΑΡΚ που η ΑΑΔΕ έχει δεχτεί). **Fix:** `--add-drop-database` ή documented `migrate:fresh` δρόμος + snapshot μετά το down.
- [ ] **OPS-8 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Αόρατα στο health reporting τα: `whmcs:auto-issue` (το μόνο που εκδίδει unattended!), `company:run-scheduled-backups`, `invoices:resend-failed-emails`, overdue/renewals/dunning/ai-reminders — το `$trackSchedule` καλύπτει μόνο 8 tasks. **Fix:** επέκταση trackSchedule + TASK_LABELS.
- [ ] **OPS-9 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Failed queue jobs δεν ειδοποιούν κανέναν (κανένα listener στο failed_jobs — φαίνονται μόνο αν κάποιος ανοίξει ops:health).
- [ ] **OPS-10 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — `invoices:resend-failed-emails` ξανα-στέλνει αιώνια τους πελάτες χωρίς email (κάθε sweep γράφει νέο failed row που ανανεώνει το `--since` παράθυρο) → φουσκώνει τους failure counters και κρύβει πραγματικά SMTP προβλήματα. (Gated: default OFF.)
- [ ] **OPS-11 · MEDIUM · ΠΙΘΑΝΟ** — `retry_after=90s` vs `RunFirebirdImport::$timeout=1800`: ασφαλές μόνο όσο υπάρχει ακριβώς 1 worker· με 2ο worker το 30λεπτο import ξανα-δεσμεύεται στα 90s και τρέχει παράλληλα με τον εαυτό του.
- [ ] **OPS-12 · LOW** — Email double-send σε retry μετά από SMTP-accept/crash-before-log (at-least-once, σπάνιο)· fallback σε global SMTP με tenant From → πιθανό SPF/DKIM misalignment (logged).
- [ ] **OPS-13 · LOW** — Multi-tenant scheduler wrappers αγνοούν per-tenant exit codes (mitigated από τα per-tenant health keys)· το per-tenant `status` δεν έχει staleness logic.
- [ ] **OPS-14 · LOW** — Χωρίς log rotation στο default `single` channel· χωρίς logrotate οδηγία στο INSTALL.md.
- [ ] **OPS-15 · LOW** — Restore ποτέ δεν έχει γίνει drill end-to-end (τα tests καλύπτουν argv/guards)· να μπει cadence στο runbook. `go-live-check` backup gate περνάει με μόνο το `enabled` flag (χωρίς επιτυχημένο run/off-site).

**Στέρεα:** σχεδίαση scheduler (run-time gates, bounded overlap TTLs, durable
`scheduled_task_runs`, heartbeat μέσω πραγματικού job)· update.sh σωστό ως δομή
(clean-tree, snapshot-abort, downgrade refusal, maintenance trap, restart-before-up)·
snapshot hygiene (MYSQL_PWD, single-transaction, gzip failure = fail)· per-company
backup pipeline με per-destination failures, retention, πραγματικό alerting +
`CompanyExportCoverageTest` (κάθε νέος πίνακας ΠΡΕΠΕΙ να ταξινομηθεί σε bucket —
εξαιρετικό guard)· mail observability πλήρης· myDATA submit synchronous (χωρίς
queue-retry double-file), auto-issue με lock+guards.

---

## F. Security / Tenant isolation (SEC)

- [ ] **SEC-1 · HIGH (απόφαση go-live) · ΕΠΙΒΕΒΑΙΩΜΕΝΟ (σκόπιμο design)** — Secrets plaintext-at-rest by default (`EKDOSI_ENCRYPT_SECRETS_AT_REST=false`): myDATA keys, WHMCS secrets, SMTP passwords, AI keys ως καθαρές στήλες → κάθε dump/snapshot/backup τα κουβαλάει cleartext· και το company backup default `secrets_mode='raw'`. Συνειδητό DR trade-off, αλλά πριν το go-live: ή `=true` + `secrets:reencrypt`, ή ρητή αποδοχή στο runbook + το `ops:health`/`go-live-check` να το επισημαίνουν.
- [ ] **SEC-2 · LOW · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Κανένα ελάχιστο μήκος password στο create/edit χρήστη (`UserForm.php:35-46` — μόνο maxLength· το reset action έχει minLength(8)). **Fix:** `Password::min(8)`/`defaults()` σε UserForm + Install.
- [ ] **SEC-3 · LOW · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Webhooks χωρίς replay protection (no timestamp/nonce) και τα body-signed POSTs δεν δένουν το slug στο signature (θέμα ΜΟΝΟ αν δύο tenants μοιραστούν ποτέ secret). Bounded impact (idempotent effects, read-only leaks σε secret-holder). Hardening: slug+timestamp στο canonical, κανόνας «ποτέ κοινό webhook secret».
- [ ] **SEC-4 · LOW · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Το public invoice-PDF signed URL δεν λήγει ποτέ (by design για WHMCS)· leak = μόνιμη πρόσβαση στο συγκεκριμένο PDF· ανάκληση = μόνο APP_KEY rotation. Μια γραμμή στο runbook.
- [ ] **SEC-5 · LOW** — `preserveFilenames()` στο Firebird import upload (hardening note)· δύο implicit no-op-scope sweeps (`OperatorHealthReport.php:263-266`, `RunScheduledCompanyBackups.php:33`) που αν κληθούν ποτέ με ambient tenant context θα υπο-λειτουργήσουν (ποτέ leak) — one-line `withoutGlobalScope` declarations.

**Στέρεα:** ο ισχυρισμός «0 tenant leaks» **επαληθεύτηκε ξανά** — κάθε entry point
μετά τις 2026-06-11 + δείγμα 9 παλαιότερων, query-by-query: κανένα cross-tenant
read/write· webhooks με verify-before-side-effect και tenant-scoped queries·
downloads auth+signed+tenant-checked (κανένα IDOR)· `canAccessTenant` πραγματικό
pivot check· AI tools δομικά tenant-safe (κανένα company param, actAs + explicit
filter, writes μόνο staged + re-validated)· role management με hard guard ΜΕΣΑ στο
action body (το mountAction κενό όντως κλειστό)· CompanySettings strict whitelist·
raw SQL όλο static/bound· `{!! !!}` όλα escape-first· 0 CSRF exemptions· 2FA
διαθέσιμο + enforceable.

---

## G. Onboarding / Seeders / Tests / Docs (SET)

- [ ] **SET-1 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — `db:seed` φτιάχνει `admin@ekdosi.local`/`password` super_admin **χωρίς** production guard (`DatabaseSeeder.php:34-41`). Ένα `--force` σε λάθος host = λογαριασμός με γνωστό password. **Fix (one-liner):** bail όταν `app()->isProduction()` (τα πραγματικά installs έχουν το `ekdosi:install`).
- [ ] **SET-2 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Bulk/force-delete σε lookups αφύλακτα (μόνο single-record `GuardedDeleteAction`)· backstop τα DB `restrictOnDelete` → 500 αντί για φιλικό μήνυμα. (Ήδη στο BACKLOG.)
- [ ] **SET-3 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ** — Τα concurrency tests (αρίθμηση/balance locks) είναι MariaDB-only και ΕΚΤΟΣ CI — η νομικά κρίσιμη εγγύηση συνεχούς αρίθμησης δεν προστατεύεται από regression αυτόματα. **Fix:** MariaDB service job στο GitHub Actions.
- [ ] **SET-4 · LOW** — `.env.example`: λείπουν ~11 μεταβλητές (EKDOSI_SCHEDULE_* νεότερα, `BACKUP_ARCHIVE_PASSWORD`, cron overrides) — defaults σωστά, θέμα discoverability.
- [ ] **SET-5 · LOW** — Tenant που δημιουργείται ως `none` και αλλάζει αργότερα σε `gr-mydata` ξεκινά με άδεια lookups (τα «Εισαγωγή τυπικών» buttons + preflight το πιάνουν/διορθώνουν).
- [ ] **SET-6 · LOW (docs)** — CLAUDE.md staleness: το gross-edit «NOT yet wired» έχει σιπάρει (G7 + test)· το «auto-calc percentage amounts still open» έχει σιπάρει (`RecomputeInvoiceTaxes` on-save)· docblock «NEEDS SANDBOX VALIDATION» στο ΔΑ ξεπερασμένο (validated 2026-06-10).

**Στέρεα:** `MyDataLookupSeeder` = πλήρη, σωστά ελληνικά defaults (ΦΠΑ §8.2 με
verbatim περιγραφές, 15 τύποι με πλήρες myDATA chain, §8.12 payment methods με
due_days, §8.13 units), idempotent/fill-empty, wired σε CreateCompany +
`ekdosi:install` + per-resource buttons· `CompanyObserver` provisionάρει ρόλους
σε κάθε δημιουργία· migrations empty-DB-safe (CI το αποδεικνύει συνεχώς)·
8/8 δειγματοληπτικοί ισχυρισμοί του FEATURES.md βρέθηκαν αληθινοί και wired·
go-live-check: hard FAIL σε production creds επιβεβαιωμένο· κανένα BACKLOG item
δεν κρίθηκε production blocker· «looks like a gap but isn't» λίστα = ακριβής.

---

## Προτεινόμενη σειρά εργασιών

**Φάση 1 — πριν από οποιοδήποτε production cutover (τα blockers):**
1. MYD-1 (header discount → per-line κατανομή ή hard-refuse + preflight) και sandbox validation με discount>0.
2. OPS-1 + OPS-2 (off-site whole-DB backup ενεργό + πραγματικός παραλήπτης alerts + INSTALL.md §11/§14) και **ένα πραγματικό restore drill**.
3. DOC-1 (αιτία απαλλαγής ΦΠΑ στο PDF) — μαζί με λογιστική επιβεβαίωση διατύπωσης.
4. DOC-2/DOC-3 + MYD-3 (banners = local_status × mydata_state × provider· visibility guard στην υποβολή).
5. SEC-1 απόφαση (encryption at rest ή ρητή αποδοχή).
6. SET-1 (guard στον DatabaseSeeder — one-liner).

**Φάση 2 — πριν οπλιστεί το WHMCS auto-issue:**
7. WH-1..4 (ένα filer-level preflight: non-EUR hold, negative lines, totals tolerance + `legacy_invoiced` exclusion).

**Φάση 3 — πρώτες εβδομάδες production:**
8. OPS-3 (exception reporting), OPS-4 (ops:health exit codes), OPS-5 (full bucket), OPS-6/7 (worker γύρω από migrate/restore).
9. MON-1 (qty_returned rollback), MON-2 (πιστωτικά στο VAT report), MYD-2 (submit lock + in-doubt state + sandbox πείραμα), MYD-4 (payment-method preflight).
10. DOC-4/5 (ΓΕΜΗ πεδίο, standalone ΜΑΡΚ), MON-3, SET-3 (MariaDB CI), και τα υπόλοιπα MEDIUM κατά προτεραιότητα.

Τα LOW συλλέγονται σε επόμενα polish passes — κανένα δεν απειλεί νομικά/χρηματικά.
