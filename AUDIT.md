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

| # | Blocker | Κατάσταση |
|---|---------|-------|
| 1 | **MYD-1** — έκπτωση κεφαλίδας → βέβαιη απόρριψη ΑΑΔΕ [207]/[209] | ✅ **FIXED** (PR #335)· ⚠ εκκρεμεί sandbox run με discount>0 |
| 2 | **OPS-1/OPS-2** — whole-DB backup εκτός VM + πραγματικός παραλήπτης alerts | ✅ **FIXED** (PR #335)· ⚠ στο prod: off-site disk + passphrase + restore drill |
| 3 | **DOC-1** — αιτία απαλλαγής ΦΠΑ στο PDF (ΕΛΠ ν.4308/2014 αρ.9) | ✅ **FIXED** (PR #335) |
| 4 | **DOC-2 + MYD-3** — ακυρωμένο τυπώνεται/υποβάλλεται σαν έγκυρο | ✅ **FIXED** (PR #336) |
| 5 | **WH-1..5** — filer preflight πριν οπλιστεί το `whmcs_auto_issue_immediate` | ✅ **FIXED** (`WhmcsFilingGuard`)· ⚠ επιβεβαίωση με πραγματικό WHMCS πριν το arming |
| 6 | **SEC-1** — απόφαση: encrypt ή ρητή αποδοχή plaintext-at-rest | ✅ **RESOLVED** — αποδεκτό plaintext ρητά (`EKDOSI_SECRETS_PLAINTEXT_ACKNOWLEDGED` + `docs/security-at-rest.md`) |

**Απομένει από τα blockers: κανένα** — όλα κλεισμένα (SEC-1 = αποδεκτό plaintext ρητά).
Prod-side ενέργειες πριν το cutover: sandbox discount run (MYD-1), backup off-site +
passphrase + restore drill (OPS-1), και `EKDOSI_SECRETS_PLAINTEXT_ACKNOWLEDGED=true` στο prod .env.
Αμέσως μετά (πρώτες εβδομάδες): ~~MON-1~~ ✅, ~~MON-2~~ ✅ (2026-07-05)·
~~OPS-3~~ ✅, ~~ΓΕΜΗ στο PDF (DOC-4)~~ ✅, ~~MYD-4~~ ✅ (payment-method preflight),
~~MYD-2~~ ✅ (submit lock + in-doubt gate, sandbox-proven 2026-07-07), ~~OPS-4..9~~ ✅ (DR/backup/deploy safety, 2026-07-05)·
απομένουν καθαρά MEDIUM/LOW: guard στον `DatabaseSeeder` (SET-1), DOC-5/6, WH-6..9,
MON-3/4, OPS-10..15, MYD-5..9, SET-2..6.

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

- [x] **MYD-2 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-07** (σκέλη α+β+γ όλα κλειστά). **Εύρημα (sandbox πείραμα 2026-07-07):** η ΑΑΔΕ **ΔΕΝ** κάνει server-side dedup στο ERP κανάλι — blind retry του ίδιου `(series, ΑΑ)` παρήγαγε **δύο διαφορετικά MARK** (`…931`/`…971`) με **ίδιο `invoiceUid`** (`E230F0…EAD1`), και τα δύο Success. Άρα το blind retry ήταν όντως επικίνδυνο (διπλά δηλωμένο έσοδο). Πλήρες response XML + reconnaissance: `docs/mydata-sandbox-myd2-retry-2026-07-07.md`.
  - **(α) ✅** το πείραμα έγινε· η υπόθεση του κώδικα (`AadeInvoiceDocument` «NO `<uid>`») διαψεύστηκε για ERP.
  - **(β) ✅** cache lock ανά παραστατικό + fresh re-read κάτω από το lock (`MyDataSubmitConcurrencyTest`).
  - **(γ) ✅** **in-doubt gate**: νέα στήλη `invoices.mydata_pending_since` (mirror, forceFill-only)· σε transport failure σημαίνεται in-doubt· στο επόμενο `submit()` γίνεται ΠΡΩΤΑ reconcile `(series, ΑΑ)` μέσω `RequestTransmittedDocs` → αν υπάρχει live MARK **υιοθετείται** (self-heal, καμία 2η υποβολή)· αν ΔΕΝ υπάρχει, εντός grace window (`einvoice.in_doubt_grace_minutes`, default 10) **αρνείται** (το feed της ΑΑΔΕ καθυστερεί ~λεπτά — sandbox-observed διπλο-υποβολή `…665`/`…666` όταν έλειπε αυτό)· μετά το grace υποβάλλει κανονικά. Tests: `MyDataSubmitInDoubtTest` (3 branches) + real sandbox E2E.
  - **Backstop:** το ημερήσιο `mydata:reconcile-sales` παραμένει για ό,τι ξεφύγει (και το adopt-path λογκάρει warning στην πολλαπλή-MARK περίπτωση).

- [x] **MYD-3 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-04** (visibility + hard guard στο action + service-level refusal σε MyDataSubmitter/GrProviderSubmitter) — **«Υποβολή στο myDATA» ορατή σε τοπικά ακυρωμένα παραστατικά.**
  `app/Filament/Resources/Invoices/Pages/ViewInvoice.php:384` — visibility ελέγχει
  μόνο `mydata_state === null`, όχι `local_status`. Το bulk submit το κάνει σωστά
  (`InvoicesTable.php:333-345` σκιπάρει cancelled). Σενάριο: ακύρωση ενεργού
  αδήλωτου παραστατικού → το κουμπί μένει → υποβολή ακυρωμένης πώλησης ως έσοδο.
  **Fix (one-liner):** `&& $record->local_status !== 'cancelled'`.

### Medium

- [x] **MYD-4 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-05** (config-audit warn + submitter Log::warning· hard-fail σκόπιμα ΟΧΙ — δεν μπλοκάρουμε ζωντανή έκδοση για payload-quality) — Χωρίς mapping, κάθε τρόπος πληρωμής δηλώνεται «Μετρητά» (type 3): `AadeInvoiceDocument.php:321-326` fallback σιωπηλό· το `MyDataConfigAudit` ΔΕΝ ελέγχει payment methods. Με POS-interconnection/IRIS καθεστώς, συστηματική δήλωση «μετρητά» για κάρτες = audit-relevant. **Fix:** preflight warning + hard-fail σε production mode.
- [x] **MYD-5 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-06** (per-line E3 ανά κατηγορία προϊόντος· νέα `product_categories.mydata_income_class[_category]`· η γραμμή resolve-άρει product→category→bucket, με fallback στον τύπο· το summary εκπέμπει ένα node ανά distinct (type,category) που αθροίζει στο totalNet) — μικτό τιμολόγιο αγαθών+υπηρεσιών έπαιρνε ίδιο E3 σε όλες τις γραμμές. **Σχεδιαστική επιλογή (χρήστης):** override του **bucket** (category1_1 αγαθά / category1_3 υπηρεσίες) ανά κατηγορία, ο E3 **τύπος** μένει από το κανάλι του παραστατικού (ώστε το ίδιο προϊόν σε χονδρική/λιανική να μη misfile-άρει). **Review fix (F1):** η φόρμα κατηγορίας περιορίστηκε στα 3 buckets (αγαθά/προϊόντα/υπηρεσίες) ώστε ο χειριστής να μη διαλέξει bucket που η ΑΑΔΕ απορρίπτει για τον τύπο ([307]/[313]).
- [x] **MYD-6 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-06** (`assertCounterpartCountryMatchesType` πριν την υποβολή: 1.1/2.1→GR, 1.2/2.2→ΕΕ-όχι-GR, 1.3/2.3→εκτός ΕΕ, μέσω `Codes::counterpartCountryClass` + `EU_MEMBER_COUNTRIES`· και **hard-fail** σε ξένο counterpart χωρίς πλήρη διεύθυνση αντί για fabricated `'Unknown'/'00000'`) — καθαρό μήνυμα αντί για opaque [242]-[244]. **Review fix (F3):** το `EL` (VAT prefix Ελλάδας) normalise-άρει σε `GR` ώστε να μη μπλοκάρει εγχώρια έκδοση.
- [x] **MYD-7 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-06** (αναγνώριση [251] «already cancelled» → self-heal: `finaliseCancellation` συγχρονίζει το τοπικό state σε CANCELLED αντί να throw-άρει· κοινός choke-point με το successful-cancel path) — cancel πετυχημένο στην ΑΑΔΕ + αποτυχία τοπικού write δεν κολλάει πια σε VALID.

### Low

- [x] **MYD-8 · LOW — ✅ FIXED 2026-07-06** (νέο `Codes::vatRateFileable(rate, override)`: το 3% είναι fileable ΜΟΝΟ με override που είναι §8.2 κωδικός **με συντελεστή 3%** — δηλ. μόνο κατ. 9· το 4% ήδη fileable απευθείας· τα δύο audit surfaces έγιναν override-aware) — έπαυσε το ψευδο-warning σε σωστά ρυθμισμένο 3% row. **Review fix (F2):** override που είναι έγκυρος κωδικός αλλά λάθος συντελεστή (π.χ. 8=χωρίς ΦΠΑ) δεν πρασινίζει πλέον ψευδώς το row.
- [x] **MYD-9 · LOW — ✅ FIXED 2026-07-06** (`MyDataConfigAudit` warns όταν `mydata_requires_quantity` ≠ goods/services φύση του τύπου, μέσω `Codes::typeIsGoods`: goods χωρίς quantity → [204], services με quantity → [205]) — πιάνει χειροποίητο τύπο με λάθος flag πριν το πρώτο filing.

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

- [x] **MON-1 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-05** (`invoice_lines.original_line_id` + `RecomputeReturnedQuantities` = Σ ζωντανών· observer ελευθερώνει σε ακύρωση/διαγραφή· ETL-safe) — **Ακύρωση πιστωτικού «καίει» οριστικά τις επιστραφείσες ποσότητες — το αρχικό δεν ξανα-πιστώνεται.**
  `app/Actions/IssueCreditNote.php:113-141` μόνο αυξάνει `return_invoice_extras.qty_returned`·
  κανένα path δεν το μειώνει. Μετά από AADE-cancel του πιστωτικού το χρηματικό
  σκέλος επανέρχεται σωστά, αλλά η επανέκδοση σκάει με «Επιστροφή > διαθέσιμη
  ποσότητα 0.000». Σενάριο ρουτίνας: λάθος πιστωτικό → ακύρωση → επανέκδοση =
  αδύνατη χωρίς DB surgery. **Fix:** decrement/rollback του qty_returned όταν το
  πιστωτικό ακυρώνεται (τοπικά ή AADE).

- [x] **MON-2 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-05** (`DashboardMetrics::outputForVat` αφαιρεί ζωντανά πιστωτικά· ευθυγράμμιση με LedgerBook+Καρτέλα· `income()` αμετάβλητο) — **Το VAT report αγνοεί τα πιστωτικά στις εκροές** (υπερ-δηλώνει τοπικά ΦΠΑ εκροών): `DashboardMetrics::baseInvoices()` (`:641-649`) κάνει `whereNull('credited_invoice_id')` αντί να τα αφαιρεί, ενώ στις εισροές αφαιρούνται (`VatPeriodReport.php:84-101`) και η Καρτέλα κάνει sign-flip. Δεν υποβάλλεται πουθενά (informational), αλλά τα εταιρικά νούμερα διαφωνούν με Σ(Καρτελών) όταν υπάρχουν πιστωτικά.
- [x] **MON-3 · MEDIUM · ΠΙΘΑΝΟ — ✅ FIXED** — Ταυτόχρονες πληρωμές: το `InvoiceBalance::recompute` κλείδωνε το invoice αλλά τα SUM διαβάζονταν από REPEATABLE-READ snapshot στημένο πριν το lock → stale `paid_total`. **Fix:** το SUM **πληρωμών** + το `hasRecordedPayments` τρέχουν ως **locking reads** (`for($locked, locking: true)`)· το UI `for()` μένει plain. Το SUM **πιστωτικών** μένει σκόπιμα plain read (self-heals μέσω `InvoiceObserver`) — locking εκεί έκλεινε **deadlock cycle** invoice↔invoice με το credit-note filing path (review finding). Επιπλέον το `recompute()` **αυτο-τυλίγεται σε transaction** όταν ο caller δεν έχει (Filament edit path) ώστε το lock να ισχύει (review finding #2). Tests: `InvoiceBalanceTest` (nested-outer-tx + locking≡non-locking· contention-proof MariaDB-only όπως το InvoiceNumberer probe). **2 findings από adversarial review — και τα δύο fixed.**
- [x] **MON-4 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ (by-design) — ✅ RESOLVED (documented policy + guard)** — ΑΑ δεσμεύεται στη δημιουργία DRAFT· η διαγραφή draft αφήνει κενό στη σειρά. **Απόφαση:** κρατάμε την αρίθμηση-στο-draft (η αρίθμηση-στην-οριστικοποίηση = μεγάλο blast radius· κάθε PDF/preview/WHMCS surface προϋποθέτει invcode), τεκμηριώνουμε ρητά την **πολιτική κενών** (`InvoiceNumberer` docblock: κενά νόμιμα — η myDATA ταυτοποιεί με ΜΑΡΚ, ο ΑΑ ΔΕΝ επαναχρησιμοποιείται· το legacy συμπεριφερόταν ίδια) και σκληραίνουμε το draft-delete με confirmation που εξηγεί το μόνιμο κενό. **Alt (αρίθμηση στην οριστικοποίηση) παραμένει διαθέσιμη αν λογιστής απαιτήσει gapless ΑΑ — flag για review.**
- [ ] **MON-5 · LOW** — Τα drafts μετράνε πλήρως σε έσοδα/εισπρακτέα/ΦΠΑ/Καρτέλα (`InvoiceScope::live()` φιλτράρει μόνο ακυρώσεις). Συνειδητό (πιστωτικά-draft) και συνεπές παντού, αλλά τα staged renewals/WHMCS drafts φουσκώνουν dashboards και εκτυπωμένα statements.
- [x] **MON-6 · LOW — ✅ FIXED** — AI `CountSalesTool` μετρούσε πιστωτικά ως θετικές πωλήσεις. **Fix:** νέα `InvoiceScope::excludeCreditNotes()`/`onlyCreditNotes()` με τον **πλήρη** predicate (correlated `credited_invoice_id` **Ή** `invoice_types.is_credit` — ο 2ος πιάνει τα ETL-imported legacy ΠΙΣ που δεν έχουν `credited_invoice_id`, review finding)· `CountSalesTool` **εξαιρεί**, `VatSummaryTool` **αφαιρεί** (ΦΠΑ εκροών νετάρει — €124 πώληση + πλήρες πιστωτικό = €0). Tests: `AssistantInsightToolsTest` (3, incl. standalone-is_credit).
- [ ] **MON-7 · LOW** — Gross-edit: στρογγυλοποίηση net σε 2dp στο form (`InvoiceForm::netFromGross`) → 10,00€ @24% ξαναϋπολογίζεται 9,99€. Εγγενές στο `decimal(14,2)`· ενημέρωση χειριστή ή 4dp ενδιάμεσο.
- [x] **MON-8 · LOW — ✅ FIXED** — Καμία θετική-τιμής validation στα δύο UI entry points (το `amount` είναι πάντα θετικό· το `kind` φέρει το πρόσημο). **Fix:** `->minValue(0.01)` σε `PaymentForm` + στο inline `record_payment` του `ViewInvoice` (και τα δύο γράφουν `kind='payment'`)· η νόμιμη επιστροφή (refund action) μένει ανέγγιχτη. Ο `PaymentAllocator` συνεχίζει να guard-άρει server-side. (Review: `minValue` επιβάλλεται **server-side** — `min:0.01` Laravel rule, όχι μόνο HTML attr.)
- [x] **MON-9 · LOW — ✅ RESOLVED 2026-07-11 (full ledger-parity)** — Τα turnover/VAT sites (`baseInvoices`/`creditNotesQuery`/top-customers) → `InvoiceScope::excludeCreditNotes()`/`onlyCreditNotes()`. Τα **receivables** sites (dashboard headline `outstandingReceivables` + `Customer::scopeWithOutstandingBalance`) εξαιρούν τα credit notes ΚΑΙ **αφαιρούν το payable των standalone legacy ΠΙΣ** (is_credit, χωρίς `credited_invoice_id` — δεν έχουν original με `credited_total`), ώστε **και οι 3 επιφάνειες (dashboard/per-customer/ledger) να συμφωνούν** με τον `CustomerLedgerBuilder`. Νέος helper `onlyStandaloneCreditNotes()` + κοινό `Customer::OUTSTANDING_BALANCE_SQL` (select/onlyDebtors/CustomersTable μία πηγή). Regressions: `test_standalone_legacy_credit_note_reduces_all_three_receivables_surfaces` + standalone ΠΙΣ στο randomized invariant-A. **Convergence sweep** (surfaced από το review, ίδια bug-class): `LedgerBook` (Βιβλίο Εσόδων-Εξόδων — standalone ΠΙΣ σημαίνεται −1), `Invoice::scopeOverdue` (ληξιπρόθεσμα/dunning), `CustomerLedger::openInvoiceOptions` (payment-alloc dropdown), `CustomerTopProducts`, `Invoice::isOverdue()` (single-record twin), `PaymentAllocator` (FIFO + manual — μια πληρωμή δεν auto-allocate-άρεται σε ΠΙΣ) — όλα → `excludeCreditNotes()`/`isCreditNote()`· regressions στο LedgerBookTest + OverdueInvoicesTest· full suite πράσινο. **Residual (low, cosmetic, εκτός money-math):** per-record UI guards (badge «Πιστωτικό» `InvoicesTable`, ορατότητα action «πληρωμή»/ακύρωσης `ViewInvoice`/`InvoicePaymentsRelationManager`) δείχνουν ένα standalone legacy ΠΙΣ σαν κανονικό τιμολόγιο — δεν επηρεάζουν τζίρο/ΦΠΑ/υπόλοιπα. Το `DashboardMetrics` (receivables `:124`, sales `:270`, `baseInvoices`/`creditNotesQuery`) + η «Εικόνα ΦΠΑ» φιλτράρουν πιστωτικά **μόνο** με `credited_invoice_id`, ενώ ο ledger (`CustomerLedgerBuilder::isCreditNote`) χρησιμοποιεί τον πλήρη `credited_invoice_id OR invoice_types.is_credit`. Για tenant με ETL-imported legacy ΠΙΣ (myip) το dashboard **υπερδηλώνει** τζίρο/ΦΠΑ και αποκλίνει από τον ledger. Οι AI tools πλέον χρησιμοποιούν τον πλήρη predicate (MON-6)· χρειάζεται **ολιστικό** fix σε όλα τα dashboard sites (ξεχωριστό PR — money surface, να επαληθευτεί το `MoneyStatusConsistencyTest`). Helper έτοιμος: `InvoiceScope::excludeCreditNotes()`/`onlyCreditNotes()`.

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
- [x] **DOC-2 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-04** (`InvoiceBannerState`: banner = f(local×aade×provider) + note «Εκκρεμεί ακύρωση στο myDATA») — **Τοπικά ακυρωμένο + VALID στην ΑΑΔΕ τυπώνεται σαν πλήρως έγκυρο** (banner logic μόνο σε `mydata_state`, `resources/views/invoices/pdf.blade.php:136-142`): χωρίς ΑΚΥΡΩΘΕΝ, με QR + «Πιστοποιημένο». Το public route είναι fail-closed, αλλά download/email από χειριστή όχι. Υπο-περίπτωση: cancelled+null τυπώνει «ΠΡΟΧΕΙΡΟ» (λάθος ταμπέλα). **Fix:** banners = συνάρτηση `local_status` × `mydata_state`.
- [x] **DOC-3 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-04** (νέο banner «ΕΚΔΟΘΕΝ — ΕΚΚΡΕΜΕΙ ΥΠΟΒΟΛΗ» για myDATA tenants· κανένα banner για none/ee/Off· draft λεκτικό provider-agnostic) — **Εκδοθέν-αλλά-μη-υποβληθέν τυπώνει μόνιμα «ΠΡΟΧΕΙΡΟ — ΔΕΝ ΕΧΕΙ ΥΠΟΒΛΗΘΕΙ ΣΤΗ myDATA»** — για τον εσθονικό tenant (`NullSubmitter`, state μένει null) ΚΑΘΕ νόμιμο τιμολόγιο κουβαλάει DRAFT banner για πάντα, με αναφορά σε myDATA. **Fix:** μαζί με DOC-2 (τρίτη κατάσταση «εκδόθηκε, εκτός myDATA»).
- [x] **DOC-4 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-05** (νέο `companies.gemi` + φόρμα + τύπωμα ΓΕΜΗ & ΚΑΔ στην κεφαλίδα PDF) — **ΓΕΜΗ: δεν υπάρχει καν πεδίο** στο `companies` και δεν τυπώνεται (ν.4919/2022 αρ.22 απαιτεί αριθμό ΓΕΜΗ στα έγγραφα — επιβεβαίωση με λογιστή)· ούτε το επάγγελμα/δραστηριότητα εκδότη (το `kad_primary` υπάρχει, δεν τυπώνεται). Σήμερα μόνο workaround μέσω `pdf_footer_text`.
- [x] **DOC-5 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ (logic) — ✅ FIXED** — Το ΜΑΡΚ τυπωνόταν ΜΟΝΟ μέσα στο QR block· VALID invoice με mark αλλά χωρίς `mydata_url` (ETL-imported) έβγαινε χωρίς ΜΑΡΚ/QR. **Fix:** αποσύζευξη — το `pdf.blade.php` τυπώνει το ΜΑΡΚ όποτε υπάρχει (με «ΜΑΡΚ:» label όταν λείπει το QR, όπως το ΔΑ template)· το QR μένει conditional στο url. Νέο `PdfLabels` key `mark_label`. Tests: `InvoicePdfMarkTest`.
- [x] **DOC-6 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED** — Drafts/ακυρωμένα μπορούσαν να σταλούν manual/bulk με email «…που εκδόθηκε…». **Fix:** gate με το fail-closed predicate `isPubliclyViewable()` σε ΟΛΑ τα σημεία — UI actions (single hidden+body-guard, bulk skip+tally), **το ίδιο το job** (`SendInvoiceEmail::handle` = ο πραγματικός choke-point· κλείνει και το batch sweep + το TOCTOU), και το query του `invoices:resend-failed-emails` (anti-churn). **Bonus finding από το review:** το `invoice_mail_log.trigger` enum δεν είχε `'batch'` → κάθε batch αποστολή έσκαγε — migration το προσθέτει. Tests: `InvoiceEmailStatusFilterTest`, `SendInvoiceEmailTest`, `ResendFailedInvoiceEmailsTest`.
- [x] **DOC-7 · LOW — ✅ FIXED 2026-07-04** (certified row + verify footer μόνο σε VALID & μη-ακυρωμένο· QR+ΜΑΡΚ μένουν σκόπιμα) — Ακυρωμένο-στην-ΑΑΔΕ τυπώνει ακόμα QR + footer «Πιστοποιημένο στη myDATA» δίπλα στο ΑΚΥΡΩΘΕΝ banner (gates μόνο σε `mydata_url`).
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

- [x] **WH-1 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-04** (`WhmcsFilingGuard::assertPayloadFilable` — HOLD μη-EUR σε file/createDraft/split) — Κανένα currency guard στο map/file/auto-issue path: `WhmcsInvoiceMapper` δεν κοιτάει `currencycode` (το feed το στέλνει). USD $120 → παραστατικό €120 στην ΑΑΔΕ. **Fix:** hold σε μη-EUR στο ingestor/filer.
- [x] **WH-2 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-04** (`assertVatRateReconciles` σύγκριση `taxrate`↔applied + `assertTotalsReconcile` gross↔total· πιάνει και tax-inclusive) — Το WHMCS `taxrate` δεν συγκρίνεται ποτέ με τον default συντελεστή του tenant (`WhmcsInvoiceMapper.php:278` uses default VAT unconditionally)· η απόκλιση φαίνεται ΜΟΝΟ στο manual preview — `file()`/auto-issue δεν έχουν totals-mismatch check. **Fix:** filer-level guard: recomputed gross vs `whmcs_total` εντός tolerance, αλλιώς hold (καλύπτει και το WH-5).
- [x] **WH-3 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-04** (exclude στα candidates + hard guard στο `assertCanBeFiled`) — Auto-issue αγνοεί το `legacy_invoiced` flag (dual-run με το legacy app) → παράθυρο διπλής υποβολής στην ΑΑΔΕ· το ίδιο το tooltip του badge προειδοποιεί για διπλή υποβολή αλλά ΚΑΝΕΝΑΣ κώδικας δεν το επιβάλλει. **Fix:** exclude `legacy_invoiced > 0` στα candidates + guard στο `assertCanBeFiled()`.
- [x] **WH-4 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-04** (`assertPayloadFilable` — HOLD αρνητικών γραμμών· mapper εκθέτει `negative_lines`) — Αρνητικές γραμμές (WHMCS promos/credits) περνάνε ως αρνητικό net → XSD rejection ΑΦΟΥ το τοπικό Invoice έχει δεσμεύσει ΑΑ (ghost invoice — ακριβώς το σενάριο που κλείνει το 0%-VAT preflight, το οποίο κοιτάει μόνο `vat_percent == 0`). **Fix:** negative-line preflight δίπλα στο `refuseProblematicZeroVatLines()`.
- [x] **WH-5 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-04** (καλύπτεται από το `assertTotalsReconcile` με ανοχή ±1λεπτό/γραμμή — μικρή στρογγυλοποίηση περνά, δομική ασυμφωνία μπλοκάρει) — Per-line back-compute `round(amount/1.24,2)×1.24` → filed σύνολο ±0,01€/γραμμή vs WHMCS· ο unattended δρόμος δεν το μπλοκάρει. Λύνεται με το guard του WH-2.
- [x] **WH-6 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED** — `getInvoicesForClient()` χρησιμοποιούσε το αγνοούμενο `limit` → το per-customer ledger έβλεπε μέχρι ~25 rows. **Fix:** paginating loop με `limitstart`/`limitnum` + loop-guard + minDate early-stop (ακριβώς το σχήμα του `getPendingInvoices`, κρατά ΟΛΑ τα statuses). Tests: `WhmcsClientTest` (3).
- [x] **WH-7 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED** — Αποτυχημένο MARK write-back δεν είχε retry surface (η «future retry-sweep command» δεν υπήρχε, το `whmcs_writeback_state` δεν φαινόταν πουθενά στο panel, το log έδειχνε λάθος next-step). **Fix:** νέα `WhmcsWritebackService::retryWriteback()` (reuse `pushMark`, skip split, resolve invoice fwd+rev) πίσω από (α) per-row Filament action «Επανάληψη επιστροφής ΜΑΡΚ» + στήλη/φίλτρο «Επιστροφή ΜΑΡΚ», και (β) batch `whmcs:retry-writebacks`· διορθώθηκε το next-step log. Tests: `WhmcsInvoiceFilerWritebackTest` (3) + `WhmcsRetryWritebacksCommandTest` (3).
- [x] **WH-8 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED (money)** — (β) κενές περιγραφές με ποσό droppάρονταν σιωπηλά → under-billing στα `createDraft`/`split` paths (που παρακάμπτουν το totals-reconcile). **Fix:** ο mapper εκθέτει `blank_description_charge_lines`· το `assertPayloadFilable` (κοινό και στα 3 paths) τα ΚΡΑΤΑ (κενή-μηδενική γραμμή = αβλαβής spacer, μένει). (α) καμία completeness assertion στον splitter → νέα `assertSplitCoversAllItems` (missing/duplicate/orphan item_ids). Tests: `WhmcsInvoiceMapperTest`, `WhmcsFilingGuardTest` (3), `WhmcsInvoiceSplitterTest` (1).
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
- [x] **OPS-3 · HIGH · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-05** (`ExceptionNotifier` στο `withExceptions()->report()`· email deduped/throttled· κοινή recipient chain με backups) — **Κανένα exception reporting**: `withExceptions()` άδειο, κανένα Sentry/Flare, `LOG_STACK=single`, cron `>> /dev/null 2>&1`. Για app που εκδίδει νομικά έγγραφα, τα σιωπηλά failures είναι το #1 λειτουργικό ρίσκο. **Fix:** έστω mail/Slack log channel σε `error` level, ή Sentry.
- [x] **OPS-4 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-05** (`OperatorHealthSeverity` distils το report σε level+exit 0/1/2· το `ops:health` επιστρέφει πλέον 2=critical/1=warning/0=ok + verdict banner + `severity` στο JSON & στη σελίδα «Υγεία συστήματος») — `ops:health` επέστρεφε ΠΑΝΤΑ 0 → το `deploy/update.sh` gate ήταν νεκρός κώδικας και δεν έμπαινε σε cron monitoring. **Review-driven refinements (4 review agents, 2 γύροι):** heartbeat grace — missing/briefly-stale = warning, μόνο >30′ σιωπής = critical (αλλιώς κάθε deploy που κρατά >10′ έβγαζε ψευδές CRITICAL, αφού το OPS-6 σταματά τον worker)· failed-jobs gating σε **24ω παράθυρο** (όχι all-time)· το `update.sh` final gate «τσιρίζει» μόνο σε exit≥2· **completeness critic**: προστέθηκαν στο severity ο **χώρος δίσκου** (<2% critical / <5% warning, conservative λόγω reserved blocks) και τα **στημένα email** (queued/sending)· η σελίδα «Υγεία συστήματος» δείχνει πλέον και τα per-tenant off-site/books rows (parity με CLI).
- [x] **OPS-5 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-05** (default bucket → `full` σε φόρμα + migration· `ops:health` πλέον bucket-aware: per-tenant `books_included` + top-level `books_gap` → warning· υπάρχοντα rows δεν αλλάζουν σιωπηλά, προειδοποιούνται) — Τα per-tenant «off-site» backups by default ΔΕΝ περιείχαν τα βιβλία (`bucket` default `settings_setup`· invoices/payments/marks μόνο σε `full`) και το `ops:health` έδειχνε «Off-site ok» χωρίς να κοιτάει bucket → ψευδής αίσθηση DR.
- [x] **OPS-6 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-05** (`stop_queue_worker`/`start_queue_worker` σε `update.sh`+`rollback.sh`: drain πριν το migrate/restore, restart μετά· `QUEUE_STOP_CMD`/`QUEUE_START_CMD` hooks + auto-detect του `ekdosi-queue` systemd unit) — long in-flight job (π.χ. 30λεπτο Firebird import) έτρεχε ΚΑΤΑ το `migrate` → writes σε μισο-migrated schema· restore με live worker.
- [x] **OPS-7 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ μηχανισμός — ✅ FIXED 2026-07-05** (το `db-restore` κάνει `DROP+CREATE DATABASE` στη ΒΔ της σύνδεσης και μετά φορτώνει το DB-agnostic snapshot· clean-slate που ρίχνει ορφανό πίνακα κακού migration + self-heal σε διακοπή· το snapshot μεταφέρθηκε ΜΕΤΑ το `artisan down` — snapshot-failure = clean abort) — restore χωρίς schema reset σκότωνε το επόμενο deploy («table already exists»)· snapshot πριν το `down` = παράθυρο χαμένων writes. **Review note:** το αρχικό `--add-drop-database --databases` στο dump απορρίφθηκε (baked-in όνομα ΒΔ → confirmation mismatch + δεν self-heal-άρε)· μεταφέρθηκε στο restore.
- [x] **OPS-8 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-05** (`$trackSchedule` + TASK_LABELS επεκτάθηκαν: `whmcs_auto_issue`, `company_backups`, `resend_failed_emails`, `overdue_notifications`, `service_renewals`, `service_dunning`, `mydata_console_refresh`· `ai:dispatch-reminders` σκόπιμα ΟΧΙ — every-minute θα έπνιγε το recent-runs) — αόρατα στο health τα unattended (κυρίως ο AADE filer `whmcs:auto-issue` + τα per-tenant backups).
- [x] **OPS-9 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-05** (`Queue::failing` listener → `ExceptionNotifier::reportFailedJob`, ίδιο deduped/throttled email channel με OPS-3· guard στον test-runner) — failed queue jobs δεν ειδοποιούσαν κανέναν.
- [x] **OPS-10 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED** — `invoices:resend-failed-emails` ξανα-έστελνε αιώνια τους πελάτες χωρίς email (κάθε sweep γράφει νέο failed row → ανανεώνει το `--since` παράθυρο). **Fix:** το query του `failedInvoices()` εξαιρεί πελάτες χωρίς email (`whereHas('customer', email not null/'')`) — δομικά δεν στέλνονται ποτέ· αν ο πελάτης αποκτήσει email αργότερα, ξαναμπαίνει αυτόματα. Test: `ResendFailedInvoiceEmailsTest`.
- [x] **OPS-11 · MEDIUM · ΠΙΘΑΝΟ — ✅ FIXED** — `retry_after=90s` < `RunFirebirdImport::$timeout=1800` → 2ος worker ξανα-δεσμεύει το τρέχον import στα 90s. **Review-verified subtlety:** με σκέτο `$tries=1` το re-reservation ΑΠΟΤΥΓΧΑΝΕΙ σε max-attempts ΠΡΙΝ τρέξει το middleware → false «failed» run + false alert (OPS-9), ενώ ο worker-1 δουλεύει κανονικά (το double-exec ήδη το απέτρεπε το tries=1). **Fix (τριάδα):** `retryUntil(timeout+300)` short-circuit-άρει το max-attempts check (ο διπλότυπος φτάνει στο fire()), `WithoutOverlapping`+`dontRelease` τον ρίχνει καθαρά (χωρίς 2η gbak/migrate), `maxExceptions=1` κρατά μία μόνο πραγματική προσπάθεια. Ασφαλές ανεξαρτήτως αριθμού workers. Tests: `FirebirdImportRunTest` (2).
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

- [x] **SEC-1 · HIGH (απόφαση go-live) — ✅ RESOLVED 2026-07-05: αποδεκτό plaintext, ρητά** (`EKDOSI_SECRETS_PLAINTEXT_ACKNOWLEDGED`· go-live gate warn→pass· `docs/security-at-rest.md`) — Secrets plaintext-at-rest by default (`EKDOSI_ENCRYPT_SECRETS_AT_REST=false`): myDATA keys, WHMCS secrets, SMTP passwords, AI keys ως καθαρές στήλες → κάθε dump/snapshot/backup τα κουβαλάει cleartext· και το company backup default `secrets_mode='raw'`. Συνειδητό DR trade-off, αλλά πριν το go-live: ή `=true` + `secrets:reencrypt`, ή ρητή αποδοχή στο runbook + το `ops:health`/`go-live-check` να το επισημαίνουν.
- [x] **SEC-2 · LOW — ✅ RESOLVED 2026-07-11** (conditional `min:8` rule στο `UserForm` — gate σε filled ώστε το blank-edit «κράτα τον κωδικό» να μην απορρίπτεται· 8-char guard στο `ekdosi:install` πριν το transaction· tests) — Κανένα ελάχιστο μήκος password στο create/edit χρήστη.
- [x] **SEC-3 · LOW — ✅ RESOLVED 2026-07-11 (cheap)** — Ο κανόνας «ποτέ κοινό webhook secret» τεκμηριωμένος (`docs/security-at-rest.md`) + shared-secret-collision detector στο `ops:health` (Security row + severity warning, συγκρίνει hash του decrypted — ποτέ plaintext στο report). Το slug+timestamp/nonce στο canonical παραμένει **deferred** (coupled σε plugin-first rollout — `docs/CLAUDE-history.md`).
- [x] **SEC-4 · LOW — ✅ RESOLVED 2026-07-11** — Το «never-expiring public invoice-PDF signed URL» τεκμηριωμένο ρητά στο `docs/security-at-rest.md` (leak = μόνιμη πρόσβαση στο ΕΝΑ PDF· revoke = μόνο APP_KEY rotation).
- [x] **SEC-5 · LOW — ✅ RESOLVED 2026-07-11** — `preserveFilenames()` ήδη παρόν στο Firebird import upload· οι δύο all-tenant sweeps (`OperatorHealthReport::mail()`, `RunScheduledCompanyBackups`) δηλώνουν πλέον ρητά `->withoutGlobalScope(CompanyScope::class)`.

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

- [x] **SET-1 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-07** (ο `DatabaseSeeder` γίνεται **opt-in**: no-op εκτός αν `EKDOSI_SEED_DEMO=true` — prod-safe ανεξαρτήτως `APP_ENV`, αφού ο prod είχε κάποτε λάθος `APP_ENV=local`· + δεύτερο belt `app()->isProduction()` bail· + demo password env-overridable). Νέα εντολή **`ekdosi:create-admin`** (prompt/flags, create-ή-reset system super_admin σε όλες τις εταιρίες, reuse `shield:sync-super-admin --user`) ως ασφαλές path όταν δεν υπάρχει admin — δίπλα στο υπάρχον `ekdosi:install`.
- [x] **SET-2 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED** — Bulk/force-delete σε lookups αφύλακτα. **Fix:** νέα `GuardedDeleteAction::bulk()` / `::forceBulk()` (custom BulkAction που partial-skip-άρει τις σε-χρήση εγγραφές + notification «Διαγράφηκαν/Παραλείφθηκαν», UX ίδιο με WhmcsInbox). Ο dependent map κάθε lookup εξήχθη σε ένα `Resource::dependents()` (single source — single + bulk + force τον διαβάζουν) σε **8 resources** (VatCategory, ProductCategory, MetricUnit, InvoiceType, PaymentMethod, DistributionAim, DeliveryMethod, BankAccount)· έκλεισε και το κενό `whmcs_default_receipt_type_id` στον InvoiceType map. Tests: `GuardedDeleteActionTest` (bulk + force + receipt-gap + per-resource).
- [x] **SET-3 · MEDIUM · ΕΠΙΒΕΒΑΙΩΜΕΝΟ — ✅ FIXED 2026-07-07** (νέο CI job `numbering-concurrency` με MariaDB service container: `migrate --force` → `php artisan test:invoice-numbering-concurrent --workers=16` με πραγματικά row locks + forked processes· ένα dropped `lockForUpdate()`/transaction κόβει πλέον το CI). Το phpunit suite μένει sqlite (γρήγορο)· η νομικά κρίσιμη εγγύηση αρίθμησης προστατεύεται πλέον από regression.
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
