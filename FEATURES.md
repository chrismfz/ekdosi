# ekdosi — Κατάλογος χαρακτηριστικών (FEATURES)

> **Τι κάνει το ekdosi σήμερα.** Ενιαία, ενημερωμένη λίστα — γιατί έχουμε τόσα
> features που χανόμαστε. Ό,τι ΔΕΝ έχει χτιστεί ακόμα (+ νέες ιδέες) ζει στο
> **`docs/BACKLOG.md`**. Το «γιατί» πίσω από αποφάσεις: **`CLAUDE.md`** + τα
> per-feature docs στο `docs/`.
>
> **Stack:** Laravel 13 · FilamentPHP 5 · MariaDB · PHP 8.4 — web, multi-tenant,
> πολυγλωσσικό operator UI (ελληνικά). Πορτάρισμα του legacy C++Builder/Firebird
> desktop app, που το έχει **ξεπεράσει αποφασιστικά**.

---

## 1. Πλατφόρμα & αρχιτεκτονική
- **Web** από οποιονδήποτε browser (το legacy έτρεχε μόνο σε εύθραυστο Win7 VM).
- **Multi-tenant** — μία MariaDB, `company_id` σε κάθε πίνακα, ένα Filament panel με
  εναλλαγή εταιρίας. Surrogate PKs + `legacy_id` (audit + επαναλήψιμο ETL).
- **Multi-country από την αρχή** — `companies.einvoice_provider` επιλέγει submitter:
  `gr-mydata` (ΑΑΔΕ), `gr-provider` (πάροχος/InvoSign), `ee-peppol` (Εσθονία), `none`.
- **Ρόλοι/δικαιώματα ανά εταιρία** — Shield teams: `super_admin` / `company_admin` /
  `operator`, role-picker (μόνο super_admin), per-resource permissions.
- **Audit trail** — (α) πλήρες XML myDATA (`mydata_marks`/`expense_marks`), (β)
  activity log (ποιος-άλλαξε-τι σε τιμολόγια/πελάτες/πληρωμές) με καρτέλα «Ιστορικό»
  + tenant-wide «Δραστηριότητα» feed.

## 2. Παραστατικά / Τιμολόγηση
- **VAT/εκπτώσεις/στρογγυλοποίηση** portαρισμένα ακριβώς (`RecomputeInvoiceTotals` +
  `InvoiceVatBreakdown`), per-VAT-rate breakdown.
- **Αρίθμηση** συνεχόμενη ανά τύπο, με **row-lock σε transaction** (`InvoiceNumberer`).
- **QR + PDF** (Blade/dompdf) — **γλώσσα ανά παραστατικό** (Ελληνικά/Αγγλικά/**Δίγλωσσο
  GR-EN**), per-invoice/quote επιλογή με default από τη χώρα πελάτη (GR → Ελληνικά, ξένος
  → δίγλωσσο)· `App\Support\Pdf\PdfLabels` localizes μόνο τις ετικέτες (όχι ποσά/περιεχόμενο),
  σε invoice + quote. **Στοιχεία εκδότη στην κεφαλίδα**: επωνυμία/διεύθυνση/ΑΦΜ/ΔΟΥ/τηλ/email
  + **ΓΕΜΗ** (`companies.gemi`, ν.4919/2022) + **Δραστηριότητα/ΚΑΔ** (`kad_primary`)· απαλλαγή
  ΦΠΑ (§8.3 αιτία) σε 0% γραμμές.
- **Αποστολή τιμολογίου με email + ιστορικό** — auto (σε myDATA accept) ή χειροκίνητα· κάθε
  προσπάθεια καταγράφεται (`invoice_mail_log`: παραλήπτης/θέμα/κατάσταση/χρόνοι/ποιος). Ιστορικό
  **per-invoice** (ViewInvoice), **per-customer** (tab «Ιστορικό email»), και **γενικό tenant-wide**
  (`InvoiceMailLogResource`, read-only, φίλτρα). Idempotent (OPS-12 `send_key` — όχι διπλό email σε
  retry)· markdown-safe body (DOC-8).
- **«Υπόλοιπο πελάτη» στο PDF** (legacy «ΝΕΟ ΥΠΟΛΟΙΠΟ») — Προηγούμενο + αυτό το παραστατικό
  = Νέο υπόλοιπο, **snapshot τη στιγμή έκδοσης** (`invoices.customer_balance_snapshot`,
  σταθερό σε reprint)· opt-in ανά εταιρεία (`show_customer_balance_on_pdf`) με override ανά
  πελάτη· μόνο σε παραστατικά επί πιστώσει/πιστωτικά (τα μετρητοίς εξοφλούνται στην έκδοση).
- **Δύο ορθογώνιες καταστάσεις**: `local_status` (draft/active/cancelled) vs
  `mydata_state` (null/VALID/CANCELLED) — ποτέ μπερδεμένες· ένα predicate
  (`InvoiceScope::live()`) σε όλα τα money sites.
- **Lifecycle** actions: Οριστικοποίηση · Επαναφορά σε πρόχειρο · Ακύρωση · Επανέκδοση.
- **Πιστωτικά** (`IssueCreditNote`) — συσχετιζόμενα (5.1) ή μη (5.2), αμφίδρομη
  σύνδεση με το αρχικό· opt-in myDATA filing.
- **Τέλη / παρακρατήσεις / φόροι** — withholding (§8.4), Ψηφιακό Τέλος Συναλλαγής (§8.6)/
  τέλη (§8.7)/λοιποί φόροι (§8.5)/κρατήσεις (taxesTotals), **product-linked per-unit fees** (π.χ. τέλος διαμονής),
  «Τυπικά τέλη/φόροι» quick-fill· gross-edit γραμμής (τιμή με ΦΠΑ → back-compute net).
  **«Πρότυπα τελών»**: selective-import έτοιμων προϊόντων με θεσμικό τέλος (σακούλα/πλαστικά/
  ανακύκλωσης/διαμονής, προ-ρυθμισμένα ως Τέλη §8.7) στη λίστα Προϊόντων.
  **Μετράνε στο εισπρακτέο:** `invoices.payable_total` (= καθαρή+ΦΠΑ + τέλη − παρακράτηση,
  κανόνας AADE [208]) είναι η βάση για owed/balance/Καρτέλα/receivables (το `gross_total`
  μένει net+ΦΠΑ = τζίρος)· το PDF «Πληρωτέο» = `payable_total`.
- **Pickers**: αγαπημένα-πρώτα + most-used + inline create προϊόντος· tags· πλήρες
  ελληνικό UI· «Νέο Παραστατικό» από την Καρτέλα· **browse-all** (μικρός κατάλογος ≤200 →
  ολόκληρος στο άνοιγμα, χωρίς πληκτρολόγηση).
- **Per-customer εμπορικά defaults**: επιλογή πελάτη → εφαρμόζεται η «Default discount %» του στην
  κεφαλίδα + ο default τρόπος πληρωμής ως fallback (ο τύπος παραστατικού υπερισχύει).

## 3. myDATA (ο πυρήνας)
- **Υποβολή / ακύρωση / dry-run** μέσω `firebed/aade-mydata` (`MyDataSubmitter`),
  sandbox-validated (1.1/2.1/11.x/5.1 + CANCEL + νέοι taxTypes + 4% override + ΔΑ).
- **Per-line χαρακτηρισμός E3** — μικτό τιμολόγιο αγαθών+υπηρεσιών δηλώνει κάθε γραμμή στο σωστό
  bucket (ανά κατηγορία προϊόντος· ο E3 τύπος ακολουθεί το κανάλι) — summary ανά (τύπο,κατηγορία).
- **Pre-submit guards** — διασταύρωση χώρας↔τύπου αντισυμβαλλόμενου (καθαρό μήνυμα αντί για ΑΑΔΕ
  [242]-[244])· hard-fail σε ξένη διεύθυνση που λείπει· cancel [251] «already cancelled» → self-heal.
- **Anti-διπλο-υποβολή** — per-invoice cache lock (ταυτόχρονη υποβολή) **+ «in-doubt» gate σε
  transport timeout**: το ERP κανάλι της ΑΑΔΕ ΔΕΝ κάνει dedup (sandbox-proven), οπότε πριν από
  κάθε retry γίνεται reconcile `(series, ΑΑ)` — live MARK ⇒ υιοθέτηση χωρίς 2η υποβολή· τίποτα ⇒
  άρνηση εντός grace window (feed lag) και μετά κανονική υποβολή. (Το κανάλι παρόχου κάνει dedup +
  real-time status → ήδη ασφαλές.)
- **`mydata_marks` = source of truth** (πλήρες request/response XML, νομικό audit).
- **Κονσόλα myDATA** — ένα μενού (cluster) με tabs **Πωλήσεις / Έξοδα / Επισκόπηση Ε3 /
  Έλεγχος ρυθμίσεων**· ζωντανός συγχρονισμός (`RequestTransmittedDocs`) + **reconciliation**:
  τοπικό (Phase 1, ξεχωριστός «Τοπικός έλεγχος κατάστασης») + ζωντανό (Phase 2, `SalesReconciler`)·
  matched / stateMismatch / **contentMismatch** («Διαφορά περιεχομένου» — μικτό/καθαρή αξία/τύπος/σειρά-ΑΑ/
  ημ-νία/ΑΦΜ, κοινός `ReconciliationContentComparator`) / **contentIncomplete** («Ελλιπή τοπικά
  στοιχεία» — πεδίο που έχει η ΑΑΔΕ αλλά λείπει τοπικά· warning, όχι σύγκρουση αλλά ούτε
  «συμφωνεί») / missingAtAade / **αδέσποτα** (ομαδοποιημένα ανά οικονομική φύση). Κάθε tab κρατά
  δικό του «τελευταία ενημέρωση» + lazy fetch.
- **«Ανανέωση όλων»** (`MyDataConsoleRefresh`) — ένα κουμπί κατεβάζει μαζί Πωλήσεις+Έξοδα+Ε3+εικόνα
  ΦΠΑ (σειριακά) και σπέρνει την cache κάθε tab· per-step isolation + summary toast. Το per-tab
  «Έλεγχος» μένει ως δευτερεύον single-source refresh. **Auto-refresh**: stale banner όταν η cache
  είναι παλιά (>6h) + προγραμματισμένη εργασία `mydata:refresh-console` (opt-in, default OFF) που
  ζεσταίνει όλα τα snapshots ανά tenant — σαν το VAT-picture cron.
- **myDATA «Outbox»** — φίλτρο «Προς υποβολή» στα Παραστατικά + Ψηφιακή Διακίνηση (ζωντανά έγγραφα
  filable χωρίς ΜΑΡΚ· `scopeAwaitingMyData`) + dashboard widget **«Συγχρονισμός myDATA»** (προς
  υποβολή / τοπικές ασυμφωνίες / διασταύρωση-AADE με freshness — κάθε κάρτα link στο worklist της).
- **Έλεγχος ρυθμίσεων** (tab) — structured insight πάνω στο `MyDataConfigAudit`: ετοιμότητα
  tenant + κάθε τύπος παραστατικού/κατηγορία ΦΠΑ με badge ✓/⚠/✗ και **link «Διόρθωση →»** στη
  ρύθμιση. Ίδιο audit τροφοδοτεί το `mydata:preflight` ΚΑΙ το badge «Ετοιμότητα myDATA» στη
  λίστα Invoice Types.
- **Σελίδα ΜΑΡΚ** (direction-aware) + per-line E3 classification.
- **Enrich/έλεγχος από ΑΑΔΕ** (`EnrichInvoiceFromAade`) — από τη Σελίδα ΜΑΡΚ: live-pull
  του MARK, stamp **QR**, συμπλήρωση κενών header πεδίων + **per-field σύγκριση**
  (cross-check τοπικού ↔ ΑΑΔΕ).
- **`mydata:preflight`** — read-only έλεγχος invoice-type/VAT config vs §8 code tables (thin
  renderer πάνω στο κοινό `MyDataConfigAudit`· βλ. «Έλεγχος ρυθμίσεων» tab).
- **Αυτόματος χαρακτηρισμός εξόδων** (`ExpenseClassifier` + «Κανόνες χαρακτηρισμού») — «προμηθευτής
  (+ προαιρ. τύπος) → E3 χαρακτηρισμός»· auto-apply στο import + bulk «Εφαρμογή κανόνων» + worklist
  «Προς χαρακτηρισμό» + «Δημιουργία κανόνα» από έξοδο. (Η υποβολή-για-τρίτο/`entityVatNumber` μένει BACKLOG.)
- **Code tables** (`App\Support\MyData\Codes`) — §8 πίνακες με validation helpers.

## 4. Έξοδα / Προμηθευτές / Ε3
- **Προμηθευτές** (`Supplier`) — CRUD + «Άντληση από ΑΑΔΕ» (GSIS) + **`suppliers:sync`**
  (μοναδικά issuer ΑΦΜ από `RequestDocs`) + **«Συμπλήρωση επωνυμιών από ΑΑΔΕ»** (κουμπί στη
  λίστα + CLI `suppliers:backfill-names` — γεμίζει επωνυμία από GSIS σε παλιούς «αδέσποτους»
  μόνο-ΑΦΜ, fill-only-empty· κοινός `SupplierNameBackfiller`).
- **Συγχρονισμός κατάστασης εξόδων από ΑΑΔΕ** (`SyncExpenseStateFromAade` + action
  «Συγχρονισμός κατάστασης από ΑΑΔΕ» στην κονσόλα Εξόδων) — εφαρμόζει ακύρωση προμηθευτή
  σε υπάρχον έξοδο (VALID↔CANCELLED, audited μέσω `ExpenseMark`, χωρίς επανεισαγωγή).
- **Εισαγωγή αδέσποτων** εξόδων από myDATA (`ExpenseImporter`/`ExpenseReconciler`) +
  self-declared (αποδείξεις/μισθοδοσία/ΔΕΚΟ). **Κουμπί «Άντληση από myDATA» στη λίστα
  Έξοδα** (one-click read-only fetch → worklist· **επιλογή διαστήματος** στο modal —
  τρίμηνο/εξάμηνο/έτος τρέχον ή προηγούμενο, live re-fetch, `ExpensePickerWindow`) + tip
  «τελευταία άντληση · X αδέσποτα» +
  read-only cron **`mydata:refresh-expenses`** (καθολικός toggle στη «Ρυθμίσεις
  χρονοπρογραμματιστή», default OFF· δεν δημιουργεί εγγραφές) **+ per-company opt-in**
  «Αυτόματη άντληση εξόδων» στις «Ρυθμίσεις εταιρείας» (`--auto-only` → μόνο όσοι tenants το
  άναψαν· ο company_admin ελέγχει τον δικό του). Νέος ΕΛ προμηθευτής **GSIS-enriched κατά
  την εισαγωγή** (κοινός `SupplierGsisEnricher`) ώστε να μην μένει «παύλα».
- **Σημειώσεις χειριστή** ανά έξοδο (action «Σημειώσεις», γράφει μόνο το `notes`) —
  διαθέσιμο και στα read-only myDATA έξοδα, εμφανίζεται στην προβολή.
- **Χειροκίνητη καταχώριση εξόδου** (`source=manual`) — για παραστατικό προμηθευτή εκτός
  myDATA (ξένος προμηθευτής, απόδειξη): φόρμα με γραμμές (header totals από τις γραμμές),
  tab «Χειροκίνητα», edit μόνο για manual (τα myDATA-sourced μένουν read-only). **Συνημμένο
  PDF/scan** (`expenses.document_path`, ιδιωτικό) με λήψη μέσω υπογεγραμμένου route
  (auth + tenant-checked).
- **Χαρακτηρισμός** (E3 type + category2_x) **per-document ή per-line** (εμπορεύματα/
  πάγια/δαπάνες) → **υποβολή στην ΑΑΔΕ** (`SendExpensesClassification`) +
  `expenses:test-classify` dry-run. Audit row + transactional safety.
- **ΦΠΑ εκροών−εισροών** (`VatPictureCache` / dashboard «Εικόνα από myDATA») +
  **Ε3 overview** (`RequestE3Info`, διαχωρισμός εσόδων/εξόδων) + ΦΠΑ-τριμήνου.

## 5. Διακίνηση / Δελτία αποστολής (Ψηφιακό ΔΑ)
- **`DeliveryNoteResource`** invoice-grade (View/lines/Ιστορικό/Συνημμένα), αμφίδρομη
  σύνδεση δελτίο↔τιμολόγιο.
- **Lifecycle**: έκδοση → έναρξη διακίνησης → δήλωση παράδοσης → έλεγχος κατάστασης →
  ακύρωση (`DeliveryLifecycleService` + `DeliveryNoteSubmitter`), §7.1 status cache.
- **lifecycleHistory** timeline (carrier/recipient events).
- **Χώρα παραλήπτη (frozen)** — ο παραλήπτης μπορεί να είναι πελάτης/προμηθευτής/χειροκίνητος·
  η χώρα του παγώνει στο δελτίο (`recipient_country`, ISO-2) και είναι υποχρεωτική όταν υπάρχει
  ΑΦΜ παραλήπτη. Ξένος παραλήπτης **δεν δηλώνεται ποτέ ως GR**: χωρίς αναγνωρίσιμη χώρα η υποβολή
  απορρίπτεται· GR μόνο για ενδοδιακίνηση. Κοινός normaliser `Support\IsoCountry` (EL→GR, UK→GB)
  με το monetary invoice.
- **Πάροχος vs direct**: έκδοση/ακύρωση μέσω παρόχου· έναρξη/παράδοση/έλεγχος direct
  myDATA. Sandbox round-tripped.
- **CMR (διεθνής φορτωτική)** — αυτοτελές έγγραφο μεταφοράς (ΟΧΙ myDATA), στα Αγγλικά, για
  διασυνοριακές αποστολές. `CmrResource` (standalone «Νέο CMR») + action «Δημιουργία CMR» σε
  Τιμολόγιο/ΔΑ → **προσχέδιο** με μεταγραφή ΕΛΟΤ-743 (ελληνικά→λατινικά), editable πριν την
  εκτύπωση. Προαιρετική πηγή (Τιμολόγιο | ΔΑ | standalone)· `cmr_notes`/`cmr_lines`, `CmrPdf`
  (φόρμα 24 κουτιών), per-company counter. Αγγλικά στοιχεία εταιρείας (Sender). Σχεδίαση:
  `docs/cmr-international-delivery.md`.

## 6. Πάροχοι e-invoicing & PEPPOL
- **Δίαυλος αποστολής** per-tenant: `gr-mydata` (απευθείας ΑΑΔΕ), `gr-provider`
  (InvoSign — `EInvoiceProviderTransport` + registry), `none`.
- **ProviderConsole** + `einvoice:preflight` / `einvoice:test-submit`.
- **PEPPOL Phase 1** (Εσθονία) — provider-independent **BIS Billing 3.0 / EN 16931 UBL**
  builder (`PeppolInvoiceDocument` μέσω `josemmo/einvoicing`) + `peppol:test-submit`
  (dry-run + validate). Phase 2 (Access-Point transport) = backlog.

## 7. Πελάτες & Καρτέλα
- **GSIS lookup** native (`AadeRegistryLookup`) + «Άντληση/Διόρθωση από ΑΑΔΕ».
- **Ένας πελάτης ανά ΑΦΜ (DB-enforced)**: `customers.afm_key` (`Afm::uniqueKey`: ψηφία για GR με/χωρίς
  EL/GR, γράμματα για ξένο VAT, NULL για placeholder/κενό) + `UNIQUE(company_id, afm_key)` και σε
  soft-deleted· φιλικό validation στη φόρμα· `customers:afm-duplicates` audit· ETL/importer/sync/WHMCS
  όλα μέσω `whereAfmKeyOf`.
- **Συγχρονισμός πελατών από myDATA** (`CustomerSyncFromMyData` / `customers:sync`) — bulk discovery
  από τα ΑΦΜ συναλλασσομένων στις πωλήσεις μας + GSIS enrichment· lookback presets 3/12/24 μήνες
  (καθρέφτης του `suppliers:sync`).
- **VIES (EU)** — επαλήθευση/άντληση μη-GR ενδοκοινοτικών ΑΦΜ (`ViesLookup`) +
  **reverse-charge hint** (0% + §8.3 «16 — άρθρο 45»).
- **Καρτέλα**: ledger κινήσεων, aging, **YoY**, charts, εξαγωγή **PDF/CSV** + email·
  «αναλυτική παρακράτηση» (αξία εγγράφου + παρακράτηση/τέλη κάτω από την αναφορά, χωρίς
  να αλλάζει το υπόλοιπο). **Όψη περιόδου**: φίλτρα (έτος/τύπος/κατάσταση) πάνω από τον
  πίνακα + **σύνολα έτους** (τζίρος καθαρό/με ΦΠΑ, εισπράξεις, υπόλοιπο τέλους) όταν επιλεγεί
  έτος· το τρέχον υπόλοιπο μένει full-history. Header actions ομαδοποιημένα σε dropdowns.
- **Αποστολή Καρτέλας με email — επαφή-aware**: πολλοί παραλήπτες με επιλογή από τον πελάτη
  + τις **επαφές του** με email (role-labelled, π.χ. λογιστήριο), προεπιλογή πελάτης + κύρια
  επαφή, συν ελεύθερα extras· validation + dedupe, ένα PDF για όλους.
- **Επαφές πελάτη** (per-customer): named πρόσωπα (λογιστήριο/τεχνικός/υπεύθυνος) με
  email/τηλέφωνο/ρόλο, μία κύρια ανά πελάτη — τροφοδοτούν την αποστολή Καρτέλας.
- **Tags** (tenant-scoped) + favourites σε customers/products.
- **Φίλτρα λίστας πελατών**: **Υπόλοιπο** (χρεωστικοί / πιστωτικοί / μηδενικό) + **ανοιχτά
  προτιμολόγια** (drafts), δίπλα στα Active / αγαπημένα / άμεση τιμολόγηση / tags. Το «χρεωστικοί»
  είναι ο στόχος του dashboard drill-down («Ανεξόφλητα»)· ίδια μαθηματικά υπολοίπου με το headline.

## 7β. Leads / mini-CRM (pre-customer)
Υποψήφιοι πελάτες ΠΡΙΝ γίνουν `Customer` — χωρίς money semantics (ποτέ παραστατικά/υπόλοιπα/myDATA).
Design + gates: `docs/leads-mini-crm.md`. **Χτισμένο (L0):**
- **`Leads` resource** κάτω από τους Πελάτες: όσα στοιχεία έχουμε (μόνο η επωνυμία υποχρεωτική),
  χειριστής, πηγή, σύσταση από πελάτη, επόμενο βήμα, tags/σημειώσεις/συνημμένα/ιστορικό.
- **Χρονολόγιο** (`lead_activities`): quick-add Τηλέφωνο / Email / Ραντεβού / Σημείωση με
  ποιος/πότε/κατεύθυνση/αποτέλεσμα/τι ειπώθηκε + προαιρετικό «επόμενο βήμα»· μια πραγματική επαφή
  (απαντημένο τηλέφωνο / email με απάντηση / ραντεβού που έγινε) σε «Νέο» lead το πάει αυτόματα σε «Επικοινωνήσαμε».
- **Portability**: `leads`/`lead_activities` μέσα στο full export/import bundle + στο company-wipe (party tables).
- **Καταστάσεις** Νέο → Επικοινωνήσαμε → Ενδιαφέρεται → Στάλθηκε προσφορά → Πελάτης (μόνο μέσω
  μετατροπής, L1) / Χάθηκε / Όχι τώρα / Μην ξαναενοχλήσετε (λόγος υποχρεωτικός)· κάθε αλλαγή =
  αυτόματη γραμμή στο χρονολόγιο («Αλλαγή κατάστασης» action).
- **Λίστα**: tabs Ανοιχτά / Νέα / Για σήμερα (επόμενο βήμα έως το τέλος της ημέρας — όπου προσγειώνει το reminder) / Ληξιπρόθεσμα (πέρασε το επόμενο βήμα) / Αδρανή (14 ημ. χωρίς επαφή)
  / Πελάτες / Χαμένα / Όλα· φίλτρα χειριστή/κατάστασης/πηγής/tags· global search.
- **Dedupe («να μην ξαναζαλίζουμε κόσμο»)**: banner στη φόρμα όταν το ΑΦΜ/email/τηλέφωνο υπάρχει
  ήδη σε πελάτη (και soft-deleted, `secondary_email`, επαφές πελάτη) ή σε άλλο lead (και χαμένο/
  διαγραμμένο)· «μην ξαναενοχλήσετε» = unbounded έλεγχος, κόκκινο banner **και** η αποθήκευση
  απαιτεί ρητή αναγνώριση (`LeadMatcher`). DNC μόνο από «Αλλαγή κατάστασης» με λόγο + checkbox
  επιβεβαίωσης· «Όχι τώρα» απαιτεί ημερομηνία επόμενου βήματος.
- **Ρόλοι**: ο κυνηγός = `operator` (Leads στο `OPERATOR_PERMISSION_MAP`)· όλοι βλέπουν όλα.

**Χτισμένο (L1 — μετατροπή):**
- **«Μετατροπή σε πελάτη»** (`ConvertLeadToCustomer`): νέος πελάτης από το lead (στοιχεία 1:1, tags,
  «με ποιον μιλάμε» → κύρια επαφή, σύσταση από πελάτη) **ή** σύνδεση με υπάρχοντα (προεπιλογή το
  dedupe match — ποτέ διπλός πελάτης). Transaction + row-lock, idempotent· ο ΜΟΝΟΣ writer του «Πελάτης».
- **Αμφίδρομος δεσμός**: `leads.converted_customer_id` (unique) ↔ `Customer::originLead()`· ο πελάτης
  αποκτά tab **«Προέλευση»** (lead, πηγή, χειριστής, πρώτη επαφή, ημέρες ως τη μετατροπή, πλήθος
  τηλεφώνων/emails/ραντεβού, link στο χρονολόγιο)· φίλτρο «Από lead» στη λίστα πελατών.
- **Προσφορά από lead**: «Νέα προσφορά» στο lead → φόρμα προσφοράς προσυμπληρωμένη (`quotes.lead_id`)·
  η αποστολή email πάει στο email του lead όταν δεν υπάρχει πελάτης· **όταν σταλεί** (επιτυχές email ή
  «Σήμανση ως απεσταλμένη») γράφεται γραμμή «Προσφορά» στο χρονολόγιο και το lead πάει σε «Στάλθηκε
  προσφορά» (το draft δεν είναι επαφή)· tab «Προσφορές» στο lead· στη μετατροπή οι προσφορές περνούν
  στον πελάτη· προσφορά μη-μετατραπέντος lead δεν γίνεται τιμολόγιο/υπηρεσία.

**Χτισμένο (L2 — λογοδοσία):**
- **«Απολογισμός πωλήσεων»** (`SalesActivityReport` page, perm `View:SalesActivityReport` — company_admin):
  ανά χειριστή × περίοδο (σήμερα / εβδομάδα / μήνας / προσαρμογή, φίλτρο χειριστή): νέα leads, τηλέφωνα
  (απάντησαν), emails (απάντησαν), ραντεβού (έγιναν), προσφορές, μετατροπές, χαμένα, ανοιχτά τώρα· χοάνη ανά
  κατάσταση· ημερολόγιο (κάθε γραμμή χρονολογίου της περιόδου με link στο lead)· εξαγωγή CSV. Read-only,
  μηδέν νέοι πίνακες (`App\Services\Leads\SalesActivityReport`).
- **`leads:notify-due`** (scheduled, `EKDOSI_SCHEDULE_LEADS_NOTIFY_DUE`, default OFF, ώρα ρυθμιζόμενη στο
  «Χρονοπρογραμματισμός»): καθημερινό «καμπανάκι» (DB notification, χωρίς email) για leads με επόμενο βήμα
  σήμερα ή ληξιπρόθεσμο — στον χειριστή του lead, ή σε όλους αν δεν έχει· link στη λίστα.
- **Dashboard widget «Leads»** (μόνο για όσους έχουν `ViewAny:Lead`): ανοιχτά · ληξιπρόθεσμα βήματα (+αδρανή) ·
  μετατροπές μήνα → links στα tabs της λίστας.
- _Προαιρετικό (όχι χτισμένο): εβδομαδιαίο digest email στον company_admin — BACKLOG._

**Χτισμένο (L3 — όψεις):**
- **«Πίνακας leads»** (kanban, `View:LeadsBoard`, operator+): στήλες Νέο / Επικοινωνήσαμε / Ενδιαφέρεται /
  Στάλθηκε προσφορά / Όχι τώρα, κάρτα ανά ανοιχτό lead (επαφή, χειριστής, επόμενο βήμα κόκκινο αν πέρασε),
  φίλτρο χειριστή («τα δικά μου»). **Drag-and-drop** = αλλαγή κατάστασης μέσα από τον ίδιο hook (γραμμή
  «Αλλαγή κατάστασης» στο χρονολόγιο)· «Όχι τώρα» ζητά ημερομηνία (modal)· Πελάτης/Χαμένα/DNC ποτέ από τον
  πίνακα (μόνο από το lead, με λόγο/επιβεβαίωση). Μετακίνηση = `Update:Lead`. Χωρίς JS βιβλιοθήκη (Alpine + HTML5 drag).
- **«Ημερολόγιο leads»** (`View:LeadsCalendar`, operator+): μηνιαίο πλέγμα Δευ–Κυρ με τα επόμενα βήματα των
  ανοιχτών leads (κόκκινο = πέρασε, σήμερα τονισμένο), πλοήγηση μήνα, φίλτρο χειριστή, ένδειξη «Ν ληξιπρόθεσμα
  πριν από αυτόν τον μήνα» → λίστα. **Drag σε άλλη μέρα = μετάθεση** του επόμενου βήματος (κρατά την ώρα).
- Λίστα ↔ Πίνακας ↔ Ημερολόγιο: κουμπιά-links στο header των τριών σελίδων.

## 8. Πληρωμές & Είσπραξη (AR)
- **`InvoiceBalance` = μοναδική πηγή** για paid/credited/balance/status (cross-surface
  consistency test).
- **Cockpit ανά τιμολόγιο** (πλήρης/μερική εξόφληση, σήμανση ανεξόφλητου).
- **Έμβασμα FIFO** + **on-account πίστωση/προκαταβολή** + **χρήση πίστωσης** +
  **χειροκίνητη κατανομή** + **επιστροφές (refunds)**.
- **Τραπεζικοί λογαριασμοί** (πού μπήκαν τα χρήματα + κατάθεση στο PDF) + κωδικός
  συναλλαγής.
- **Ληξιπρόθεσμα** — due date, badge/filter, dashboard widget, ημερήσιες ειδοποιήσεις
  (`invoices:notify-overdue`).
- **Πρόχειρα εκτός money totals + ορατότητα** (MON-5) — τα unissued sale-drafts δεν μετρούν σε
  τζίρο/εισπρακτέα/ΦΠΑ/Καρτέλα (`InvoiceScope::excludeUnissuedDrafts`, συνεπές σε όλα τα surfaces·
  credit-note & legacy drafts κρατιούνται)· πλακίδιο dashboard **«Πρόχειρα (προτιμολόγια)»**
  (count + αξία, click→drafts) — η pro-forma ουρά.

## 9. Προσφορές / Quotes
Μη-νομικό sales offer σε **ξεχωριστούς πίνακες** (μηδέν money-path leak)· γραμμές
προϊόν/υπηρεσία/free-text/inline-create· lifecycle Αποδοχή/Απόρριψη + **Μετατροπή →
πρόχειρο παραστατικό** (αμφίδρομο ιστορικό)· counter `ΠΡ-{n}`· PDF + email + send-log.

## 10. Υπηρεσίες / Συμβόλαια (recurring)
WHMCS-style, προσαρμοσμένο στο per-invoice myDATA: κατάλογος + **per-cycle price
matrix** + per-customer `service_contracts`· **ανανέωση = staged DRAFT** (ποτέ
auto-AADE)· **dunning** (auto suspend/terminate, opt-in ανά προϊόν)· **provisioning
seam** (`servers`/`server_groups` + ProvisioningModule)· dashboard MRR + upcoming.

## 11. Γέφυρες τιμολόγησης (WHMCS + seam για πολλαπλές)
- **Source-neutral «Εισερχόμενα»** + per-row **source badge** (από `BillingSourceRegistry`)·
  **«Γέφυρες» page** (`Bridges`, `View:Bridges`): λίστα πηγών με αληθινό status (WHMCS
  «ρυθμισμένο» = creds) + link ρυθμίσεων. Χωρίς fake on/off — η πραγματική
  ενεργοποίηση/credentials-ανά-σύνδεση (`billing_connections.config`) είναι Phase 1
  (όταν προστεθεί 2η γέφυρα). Phase-0 seam: `BillingSource`/`BillingSourceRegistry`/
  `SourceCapabilities` + `billing_connections` (company×source×is_active×config).
- Ενοποιημένο plugin **`ekdosi_bridge`**, **PHP-to-PHP μέσω WHMCS API** (HMAC, όχι shared-DB).
- **Inbox draft-first** (`WhmcsInbox`) — webhook/poll → `pending_whmcs_invoices` →
  «Δημιουργία Παραστατικού» (editable draft) → lifecycle → write-back `invoiced=MARK`.
- **Paid/unpaid-aware τιμολόγηση**: badge «Πληρωμή WHMCS» (Πληρωμένο/Απλήρωτο) στο inbox· το draft
  προ-επιλέγει τύπο βάσει κατάστασης — ΑΠΛΗΡΩΤΟ → «Προεπιλεγμένος τύπος για ΑΠΛΗΡΩΤΑ» (επί πιστώσει →
  ανοιχτή οφειλή), ΠΛΗΡΩΜΕΝΟ → cash-term (τιμολόγιο/απόδειξη κατά πρόθεση)· override πάντα.
- **Inbound συγχρονισμός πληρωμών** (`whmcs:sync-payments`, opt-in scheduled): όταν ένα επί-πιστώσει
  WHMCS τιμολόγιο πληρωθεί στο WHMCS, καταγράφεται Payment στο ekdosi που κλείνει την οφειλή —
  money-write μόνο στο ekdosi, only-if-open + dedup. **On-demand και από το UI**: header action
  «Συγχρονισμός πληρωμών τώρα» στο inbox (bulk) + per-invoice «Έχει πληρωθεί στο WHMCS;» πάνω σε
  ανοιχτό WHMCS-συνδεδεμένο παραστατικό.
- **Κεντρικός «Συγχρονισμός πληρωμών»** (σελίδα ομάδας Data + dashboard tile + bell): read-only
  `whmcs:reconcile-payments` εντοπίζει ποια ανοιχτά επί-πιστώσει πληρώθηκαν στο WHMCS και τα δείχνει
  εύκαιρα με 1-click «Καταγραφή πληρωμής» (ζωντανή επιβεβαίωση + κλείσιμο οφειλής). Cache μόνο ids,
  ειδοποίηση για κάθε νέα εκκρεμότητα· κανένα money-write στον εντοπισμό.
- **Outbound σήμανση πληρωμένου (ekdosi → WHMCS)** *(opt-in, `whmcs_push_payments`)*: όταν εξοφληθεί
  επί-πιστώσει τιμολόγιο στο ekdosi, το WHMCS σημαίνεται Paid (`AddInvoicePayment`) — αυτόματα (queued
  job) + κουμπί «Σήμανση Paid στο WHMCS» (τιμολόγιο + λίστα «Προς ενημέρωση»). Native ή bridge
  `op=add_payment`· idempotent (marker + claim-before-write + transid), anti-echo, query-first,
  live/credit-term-only.
- **Ζωντανός έλεγχος όρου πληρωμής** στους προεπιλεγμένους τύπους (καρτέλα WHMCS): ρητό «γιατί» +
  προειδοποίηση αν ο paid τύπος έχει `due_days>0` (θα φαινόταν ως οφειλή) **ή** ο unpaid τύπος είναι
  cash-term (δεν θα φαινόταν ως οφειλή).
- **Inbox alerts**: «άμεση τιμολόγηση» rows float to top + red badge + red nav badge +
  **durable bell notification** (Filament database notifications, 30s poll) on staging· 30s
  table poll· **«Τρίτος» badge** (δικαιούχος / «Πολλοί (N)») + «Άμεσο»/«Τρίτος» filters.
- **Πρόθεση πελάτη** (τιμολόγιο/απόδειξη, ΑΦΜ/ΔΟΥ, «λείπει ΑΦΜ»), **legacy badge**.
- **Εισαγωγή πελάτη από ΑΦΜ μέσα στο «Δημιουργία Παραστατικού»** — επεξεργάσιμο ΑΦΜ +
  GSIS lookup (επίσημα ΑΑΔΕ + συμπλήρωση email/τηλεφώνου/διεύθυνσης από WHMCS), δημιουργεί
  & συνδέει τον πελάτη χωρίς να φύγει ο χειριστής· διαφορές ΑΑΔΕ↔WHMCS → κρατιέται το
  επίσημο **με προειδοποίηση** (`WhmcsCustomerCreator`).
- **Αμφίδρομη ορατότητα** (WHMCS-side): badge+ΜΑΡΚ, badge λίστας, «Αποστολή στο Ekdosi»,
  **3-way map** (WHMCS#→ΤΠΥ→ΜΑΡΚ), συγκεντρωτική λίστα, AFM-keyed + deterministic
  `invoiced===legacy_id` historical link. **«Άμεσο» κόκκινη γραμμή** στη λίστα τιμολογίων
  (plugin v0.40) — οι πελάτες άμεσης τιμολόγησης με αστάλτο τιμολόγιο βάφονται κόκκινοι.
- **timologia v2 / τρίτοι** — resolution, single-party billing, **multi-party guided
  split** (όχι σιωπηλό ανακάτεμα), editable routing. **Τύπος ανά δικαιούχο**: η ΙΔΙΑ μερίδα
  του μεταπωλητή τυποποιείται από το ΔΙΚΟ του ΑΦΜ (χωρίς ΑΦΜ → Απόδειξη), οι routed γραμμές
  από το ρητό per-route `is_receipt` προς τον τελικό πελάτη (κάθε δικαιούχος → δικό του παραστατικό).
- **Άμεση τιμολόγηση (auto-issue) type-aware** — διαλέγει Απόδειξη/Τιμολόγιο από την πρόθεση
  (ΑΦΜ/wantsinvoice ή route is_receipt)· `whmcs_default_invoice_type_id` + `whmcs_default_receipt_type_id`·
  ό,τι δεν τυποποιείται με ασφάλεια ΜΕΝΕΙ στο Inbox (ποτέ λάθος τύπος).
- **Plugin-API consolidation** (`resolve.php`) + Bridge logs tab + `whmcs:use-bridge`.
- **Φύλαξη από «mass payment»**: συγκεντρωτικά τιμολόγια πληρωμής του WHMCS (γραμμές-αναφορές σε
  άλλα τιμολόγια, χωρίς δικό τους ΦΠΑ) εντοπίζονται (`detectConsolidatedRefs`) και παρκάρονται «Σε
  αναμονή» — ποτέ δεν εκδίδονται (ούτε χειροκίνητα ούτε με άμεση τιμολόγηση), ώστε να μη διπλομετρηθεί
  τζίρος / δηλωθεί μικτό με 0% ΦΠΑ· εκδίδονται τα επιμέρους παραστατικά.
- **Φύλαξη έκδοσης (`WhmcsFilingGuard`)** — choke-point πριν δεσμευτεί ΑΑ που ΚΡΑΤΑ (HOLD) στα
  Εισερχόμενα ό,τι δεν εκδίδεται σωστά αυτόματα: μη-EUR τιμολόγιο, αρνητικές γραμμές (promo/credit),
  ασυμφωνία συντελεστή ΦΠΑ (WHMCS `taxrate` vs default), ασυμφωνία μικτού συνόλου με το WHMCS total
  (πέρα από ανοχή στρογγυλοποίησης), και **γραμμή με ποσό αλλά κενή περιγραφή** (θα χανόταν σιωπηλά →
  under-billing)· επιπλέον το `whmcs:auto-issue` εξαιρεί rows που το legacy ekdosi
  έχει ήδη τιμολογήσει (`legacy_invoiced`), αποτρέποντας διπλή υποβολή στο dual-run. Ο **split** ελέγχει
  και **πληρότητα** (κάθε χρεώσιμη γραμμή σε ακριβώς έναν δικαιούχο — πιάνει missing/διπλή/orphan γραμμή).
- **Επανάληψη επιστροφής ΜΑΡΚ** — όταν η ΑΑΔΕ πετύχει αλλά αποτύχει η ενημέρωση του WHMCS, το inbox δείχνει
  «Επιστροφή ΜΑΡΚ: Απέτυχε» (στήλη + φίλτρο) με per-row action **«Επανάληψη επιστροφής ΜΑΡΚ»** και batch
  command **`whmcs:retry-writebacks`** (δεν αγγίζει την ΑΑΔΕ — ξαναστέλνει το ήδη εκδοθέν ΜΑΡΚ).

## 12. Βιβλία / Λογιστικά / Αναφορές
- **Βιβλίο Εσόδων-Εξόδων** (`LedgerBook`) — συντόμευση περιόδου/προσαρμογή → έσοδα/έξοδα ανά κατηγορία
  + ημερολόγιο με **Έσοδα/Έξοδα στήλες + σύνολα**, **ΜΑΡΚ + κατάσταση myDATA**, **export CSV/XLSX/JSON
  + PDF (οριζόντιο A4)**. + **Λογαριασμοί** (`Accounts`).
- **Αναφορές & Στατιστικά** (`Reports`) — KPIs, τζίρος ανά μήνα/έτος, σύγκριση ετών (σωρευτικά),
  εποχικότητα (καμπύλη + heatmap), πρόβλεψη· **Εισπράξεις ανά μήνα φέτος vs πέρσι** (ταμειακά, ανά
  `pay_date`, καθαρά από επιστροφές — δείχνει τους αδύναμους/εποχικούς μήνες)· **ΦΠΑ εκροών ανά
  συντελεστή × τρίμηνο** (βοηθητικός πίνακας για την περιοδική δήλωση: φορολογητέα βάση + ΦΠΑ ανά
  24/13/6/0%, καθαρά από πιστωτικά). Οδηγούνται από τους επιλογείς Έτος + Σύγκριση με.
- **ΦΠΑ ανά περίοδο** (`VatPeriodReport`, μήνας/τρίμηνο) — εκροών − εισροών (πόσο ΦΠΑ θα χρωστάμε).
- **Ηλικίωση οφειλών** (`AgedReceivables`) — ανοιχτό υπόλοιπο ανά πελάτη σε buckets 0-30/31-60/61-90/90+
  (ίδιο FIFO aging με την Καρτέλα), σύνολα, drill στην Καρτέλα, εξαγωγή CSV.

## 13. Migration / ETL
- **`migrate:firebird`** — επαναλήψιμο ETL, μία εταιρία/run, upsert σε
  `(company_id, legacy_id)`, χειρισμός WIN1253, UI εισαγωγής (`.fdb`/`.fbk`).
- **Ζωντανή σύνδεση Firebird** — tab «Ζωντανή σύνδεση» στη φόρμα εισαγωγής: απευθείας στη ζωντανή legacy
  βάση (IP + διαπιστευτήρια + διαδρομή `.fdb`), χωρίς gbak/upload, με κουμπί **«Έλεγχος σύνδεσης»**
  (μετρά CUSTOMER/INVTYPE/INVOICE/PRODUCT πριν το import· read-only· κωδικός μόνο στη μνήμη).

## 14. Backups / Portability / DR
- **Per-company backups** (`spatie/laravel-backup`) — πρόγραμμα/διατήρηση/προορισμοί
  (Τοπικά/SFTP/FTP/S3), «Αντίγραφο/Λήψη τώρα».
- **Export/Import εταιρίας** — settings+setup ή πλήρες· **χωρίς υποχρεωτικό κωδικό**
  (passphrase ή raw, με σαφή plaintext προειδοποίηση στο raw)· `company:export`/`company:import`
  + panel actions. Η κατάσταση κρυπτογράφησης **καθολικών** αντιγράφων (env `BACKUP_ARCHIVE_PASSWORD`)
  φαίνεται read-only («🔒/⚠ χωρίς κωδικό») στις «Ρυθμίσεις συστήματος».
- **Επιλεκτική εξαγωγή CSV** (Phase 3) — checkboxes «τι να τραβήξω» → .zip με CSV ανά
  entity (Excel-ready, UTF-8 BOM)· tenant-scoped + redaction μυστικών· «Εξαγωγή CSV»
  στο panel + `company:export-csv` (`CsvEntityExporter`).
- **DR χωρίς APP_KEY** — `MaybeEncrypted` cast + `EKDOSI_ENCRYPT_SECRETS_AT_REST`
  (default plaintext) → plain `mysqldump` αυτάρκες· `secrets:reencrypt` για εναλλαγή.
- **DB snapshot/restore** (`ekdosi:db-snapshot` / `ekdosi:db-restore`) — γρήγορο
  τοπικό gzip στιγμιότυπο όλης της ΒΔ ως rollback point (creds από .env, password
  μέσω `MYSQL_PWD`). Restore guarded (production → `--force`). Το rollback layer
  των updates (ξεχωριστό από τα off-site spatie αρχεία).
- **Ασφαλή updates** — `deploy/update.sh <tag>` (snapshot→maintenance→checkout→
  composer→migrate→optimize→shield→queue:restart→ops:health) + `deploy/rollback.sh`·
  version tags via `ekdosi:release`. Runbook: `docs/updates-runbook.md`.

## 15. Ασφάλεια & λειτουργικά
- **Secrets `$hidden`** (out of toArray/logs) + at-rest encryption optional.
- **2FA** (TOTP) + `EKDOSI_REQUIRE_2FA`.
- **FK-aware delete guard** (`GuardedDeleteAction`) — μπλοκάρει διαγραφή lookup σε χρήση, σε **single + bulk +
  force** (η μαζική/οριστική διαγραφή παραλείπει τις σε-χρήση εγγραφές με σύνοψη «Διαγράφηκαν/Παραλείφθηκαν»).
- **Off-site backup verification** (`ops:health` → `backup.companies`) — ανά tenant με
  ενεργά backups: υπάρχει προορισμός **εκτός VM** (sftp/ftp/s3); και πέτυχε η τελευταία
  off-site αποστολή; `offsite_gap` προειδοποιεί για «μένουν μόνο τοπικά» ή αποτυχημένο push·
  **`books_gap`** προειδοποιεί για backup που **δεν περιέχει τα βιβλία** (bucket≠full).
- **`ops:health` verdict + exit code** — `OperatorHealthSeverity` αποστάζει το report σε
  **0=ok / 1=warning / 2=critical**, ώστε το deploy gate + cron `ops:health || alert` να είναι
  ζωντανά· **failed queue jobs ειδοποιούν** (`Queue::failing` → ίδιο ops email με τα exceptions).
- **`ops:health` shared-webhook-secret detector** (SEC-3) — row «Security» + warning όταν δύο
  tenants μοιράζονται `whmcs_webhook_secret` (forgeable cross-tenant webhooks)· συγκρίνει hash του
  decrypted, ποτέ plaintext στο report.
- **Build stamp + read-only update check** — δίπλα στο όνομα (και `ekdosi:version`) η ταυτότητα του
  deployed build `v{SemVer} · 2026.07.11-150101 (sha)`, παραγόμενη αυτόματα από το git commit στο
  deploy (`storage/app/build.json`, ώρα Ελλάδας· fallback live git σε dev). Το SemVer μένει σκόπιμο
  (`ekdosi:release`). Η «Υγεία συστήματος» δείχνει read-only αν υπάρχει νεότερη έκδοση στο GitHub
  («N commits πίσω» + link), cached 6h, graceful offline. `docs/versioning-and-updates.md`.
- **In-app ενημέρωση από GitHub** (Phase 2 / Φάση A) — super_admin action «Εγκατάσταση ενημέρωσης»
  στη «Υγεία συστήματος»: εφαρμόζει νέα έκδοση από το panel (snapshot → maintenance → `git checkout` →
  `composer install` *από το lock, ΠΟΤΕ `composer update`* → `migrate` → `optimize` → shield →
  `queue:restart` → opcache → `ops:health`). **Shared-hosting-first — χωρίς sudo/systemd/root**: τρέχει
  ως ο ίδιος account user, εκτελείται out-of-band από τον cron scheduler (`ekdosi:self-update`), ώστε η
  εφαρμογή να κάνει restart τον εαυτό της με ασφάλεια. `UpdateRun` model + resource «Ενημερώσεις»
  (ζωντανή πρόοδος + στάδιο + έξοδος + ιστορικό)· signed `/internal/opcache-flush`· token-authenticated
  `git fetch` (ένα PAT για check + pull). **Χωρίς arming flag** — το κουμπί εμφανίζεται όταν υπάρχει
  διαθέσιμη έκδοση (σε private repo προϋποθέτει έγκυρο token)· super_admin-only + confirmation.
  **Φάση Β: «Επαναφορά»** — αναιρεί μια ολοκληρωμένη (ή αποτυχημένη) ενημέρωση με checkout του
  προηγούμενου commit + `db-restore` του pre-update snapshot (destructive· λαμβάνει safety snapshot
  πρώτα). `EKDOSI_UPDATE_STRATEGY` = `php` (φορητό) ή `script` (wrap `deploy/update.sh` σε VPS). Το
  manual `deploy/update.sh <tag>` παραμένει το VPS path. `docs/versioning-and-updates.md`.
- **Deploy worker-drain** — `deploy/update.sh`/`rollback.sh` σταματούν τον queue worker πριν το
  `migrate`/restore (κανένα in-flight job σε μισο-migrated schema)· `db-snapshot` clean-slate
  (`--add-drop-database`) + snapshot μετά το `artisan down`.
- **`ops:health`** (queue/scheduler/backup/mail/WHMCS/myDATA/disk) — CLI **και**
  **σελίδα «Υγεία συστήματος»** (read-only, **super_admin-only** γιατί είναι cross-tenant·
  ίδια πηγή `OperatorHealthReport`: worker heartbeat, scheduled-task last-runs, backups,
  mail, WHMCS+myDATA ανά tenant, δίσκος, **+ ιστορικό εκτελέσεων** `scheduled_task_runs`
  + pending/failed jobs + κουμπί **«Επανάληψη αποτυχημένων»**) — στο νέο nav group
  · **Λίστα αρχείων αντιγράφων ΒΔ** (spatie): φάκελος, πλήθος, συνολικό μέγεθος + τα πιο πρόσφατα
  με μέγεθος/timestamp (όχι μόνο «το τελευταίο είναι φρέσκο»)· ο Χρονοπρογραμματιστής έχει link
  «Αρχεία αντιγράφων (Υγεία)» που δείχνει στο section (#backups). (Τα per-tenant runs φαίνονται στην καρτέλα κάθε εταιρίας.)
  **«Σύστημα»**. + **Επανυπολογισμός υπολοίπων** (κουμπί-repair στη λίστα Παραστατικά,
  admin-only· τρέχει `invoices:recompute-balances` για την τρέχουσα εταιρία) +
  **Δοκιμή SMTP** (per-company + global) + **`ekdosi:install`** turnkey first-run +
  **`ekdosi:create-admin`** (create/reset system super_admin σε όλες τις εταιρίες).
  Ο demo seed (`db:seed`) είναι **opt-in** (`EKDOSI_SEED_DEMO`, default OFF) — κανένας
  γνωστός-password super_admin σε πραγματικό host.
- **`ekdosi:go-live-check --tenant=SLUG [--json]`** — per-tenant cutover-readiness gate
  (read-only): provider · τύποι+E3 · default ΦΠΑ · ΦΠΑ→ΑΑΔΕ · **production creds (hard FAIL)** ·
  mode · αρίθμηση · **golden totals-drift** · backups · queue/infra (από `OperatorHealthReport`).
  myDATA gates SKIP για μη-myDATA tenants. Exit 0/1/2. Runbook: `docs/go-live-runbook.md`
  (τα χειροκίνητα: Firebird usage probes + AADE production smoke-test).
- **Scheduler + queue** (DB driver) — backups/auto-email/reconcile/WHMCS/VAT-picture,
  gated by `EKDOSI_SCHEDULE_*` **+ σελίδα «Ρυθμίσεις χρονοπρογραμματιστή»**
  (super_admin-only): toggles ανά εργασία **+ ενότητα «Χρονισμός»** (cron 5 πεδίων ή
  ΩΩ:ΛΛ ανά εργασία, validated στο save) στο `system_settings` store, διαβάζονται
  run-time από `routes/console.php` (env = προεπιλογή· αποθηκεύονται μόνο οι αποκλίσεις,
  με audit). Ο χρονισμός περνά από `ScheduleTiming` με ασφαλές fallback — μη έγκυρη
  τιμή αγνοείται, δεν σπάει τον scheduler. Νέο nav group **«Σύστημα»**.
- **Σελίδα «Ρυθμίσεις συστήματος»** (super_admin-only) — οι καθολικές knobs ως audited
  toggles στο `system_settings` (env = προεπιλογή, αποθηκεύονται μόνο οι αποκλίσεις):
  **`require_2fa`** (live — διαβάζεται από τον panel), **backup-alert on/off + email(s)**
  (live — `company:run-scheduled-backups`), **ειδοποιήσεις σφαλμάτων** (on/off + email +
  throttle — `ExceptionNotifier`), **AI «Βοηθός» καθολικός διακόπτης** (`AssistantRunner`/
  page), **έλεγχος ενημερώσεων** on/off (`UpdateChecker`). **At-rest κρυπτογράφηση** +
  **κατάσταση mailer** εμφανίζονται read-only (η αλλαγή κρυπτογράφησης γίνεται με ασφάλεια
  μέσω `secrets:reencrypt`).
- **Σελίδα «Ρυθμίσεις εταιρείας»** (company_admin + super_admin, gated `View:CompanySettings`)
  — self-service υποσύνολο των ρυθμίσεων της ΙΔΙΑΣ εταιρείας χωρίς το panel-global
  CompanyResource: εμφάνιση PDF (logo/υποσέλιδο/υπόλοιπο), πρότυπα email + αποστολέας,
  auto-email toggles, **ενεργοποίηση + συχνότητα αντιγράφων**. Audited («Ιστορικό»).
  Τα ευαίσθητα (SMTP server, διαπιστευτήρια myDATA/GSIS/WHMCS, κρυπτογράφηση/προορισμοί/
  διατήρηση αντιγράφων, ταυτότητα/ΑΦΜ) μένουν super_admin — η save γράφει **μόνο**
  explicit whitelist (κανένα raw mass-assign).

## 16. Dashboard & widgets
Έσοδα μήνα/προηγ./τρίμηνο, ΦΠΑ εκροών, ανεξόφλητα, παραστατικά μήνα, MRR/ανανεώσεις,
**Εικόνα από myDATA — ΦΠΑ**, top πελάτες, YoY chart, ληξιπρόθεσμα, πελάτες με υπόλοιπο.
**«Καθημερινές εργασίες»** — quick-actions panel στην κορυφή: Νέο Παραστατικό/Είσπραξη/Προσφορά/Πελάτης
+ WHMCS Εισερχόμενα (με badge)/Παραστατικά/Κονσόλα myDATA/Ηλικίωση οφειλών· κάθε κουμπί gated στο ίδιο
δικαίωμα με τον προορισμό του (δεν εμφανίζεται ό,τι δεν επιτρέπεται).

## 16β. AI «Βοηθός» (insights + links + write actions με confirm)
In-app chat που απαντά για τα δεδομένα της **τρέχουσας** εταιρείας μέσω εργαλείων.
**6 read-only tools** (επεκτάσιμο registry): `count_sales`, `outstanding_receivables`,
`list_top_debtors` (top οφειλέτες + link Καρτέλας), `find_customer` (αναζήτηση ονόματος/ΑΦΜ +
links Καρτέλας/νέου παραστατικού), `recent_invoices` (πρόσφατα + view link), `vat_summary` (ΦΠΑ
εκροών για περίοδο). **2 write tools με operator-confirm**: `send_customer_statement` («στείλε
ενημερωτικό/καρτέλα» — επαφή-aware) και `create_reminder` («θύμισέ μου / notification»). Ο βοηθός
**ΠΟΤΕ δεν εκτελεί** write μόνος του: στήνει εγγραφή σε `ai_pending_actions`, ο χειριστής πατά
**«Επιβεβαίωση»/«Άκυρο»** σε κάρτα κάτω από το chat, και η εκτέλεση γίνεται server-side
(`AiActionExecutor`, re-validate + permission, scoped tenant+user). Υπενθυμίσεις → Filament database
notifications («καμπανάκι») όταν ωριμάσουν, μέσω `ai:dispatch-reminders` (scheduler). **Clickable
links**: όταν ένα tool επιστρέφει URL, ο βοηθός το δίνει ως markdown
link· render μέσω `App\Support\Assistant\ChatMarkup` (escape-first, **bold** + links **μόνο same-origin** →
external/phishing αδρανές text). **Σελίδα «Βοηθός AI» + floating widget σε κάθε σελίδα** (κοινό
`AssistantRunner`, συνομιλία στο session). **Isolation = tool layer** (κανένα `company` param → cross-tenant
αδύνατο), **per-tool Shield permission**. **Governance web/DB**: per-company on/off · μοντέλο (Sonnet
default/Haiku/Opus) · μηνιαίο όριο tokens · προαιρ. per-company κλειδί. **Metering** `ai_usage_log`
(tokens+κόστος ανά εταιρεία/χρήστη) + caps (soft 80% / hard 100% / global backstop) · prompt-caching
(`EKDOSI_AI_PROMPT_CACHE`). Global switch `EKDOSI_AI_ENABLED` (default OFF). Engine = Laravel HTTP
(Messages API), χωρίς SDK.

## 16γ. Εξωτερικό MCP server (ίδια εργαλεία, από έξω)
`POST /mcp` (`EkdosiMcpServer`, `laravel/mcp`) — **δεύτερο μεταφορικό πάνω στο ΙΔΙΟ registry**
του «Βοηθού»: ό,τι δουλεύει εντός panel δουλεύει και από **εξωτερικό MCP client** (Claude Desktop,
claude.ai connector, άλλος agent). **Universal auth**: Sanctum bearer (πάντα· `ekdosi:mcp-token
<email> --tenant=<slug>`) ή OAuth 2.1 (claude.ai, μόλις εγκατασταθεί Passport). **Επιλογή εταιρείας
(cfm-style `company`/`company="all"`)**: τα tenant-scoped tools παίρνουν προαιρετικό `company` (slug)
ή `"all"` για fan-out σε όλες (per-company map, χωρίς merge)· ένα Sanctum token με `--tenant` είναι
**κλειδωμένο** σε μία. Η επιλογή **επικυρώνεται server-side** (`McpTenantResolver`): μέλος → μόνο δικές
του· super_admin → όλες (όπως ο tenant switcher)· **ΠΟΤΕ** δεν εμπιστεύεται εταιρεία από free text →
cross-tenant αδύνατο. `list_companies` δίνει τα slugs. **Per-tool Shield permission** ισχύει (adapter
`AssistantMcpTool` → `ToolRegistry`, ίδιο harness). Τα **write tools** (`send_customer_statement`,
`create_reminder`) είναι **propose-only** εξωτερικά: στήνουν `AiPendingAction`, ο χειριστής
επιβεβαιώνει **μέσα** στο ekdosi (καμία εξωτερική auto-εκτέλεση). **Νέα ops/debug tools για remote
troubleshooting** (super_admin, read-only): `app_health` (= `ops:health`: queues/crons/backup/mail/
WHMCS/myDATA/disk + severity), `failed_jobs` (failed queue jobs + κεφαλή exception), `log_tail`
(Laravel log με φίλτρα level/substring). **Νέα state tools** (και στα δύο κανάλια): `app_version`
(τρέχον build + διαθέσιμη ενημέρωση) και `recent_activity` (audit trail). Τα write tools ΔΕΝ κάνουν
fan-out (`"all"` απαγορεύεται — blast-radius). **Always-on** (χωρίς env flag· η ασφάλεια είναι το auth
+ token). Πλήρες: **`MCP.md`**.

## 17. Setup / lookups
VAT categories · invoice types · payment/delivery methods · distribution aims · metric
units · bank accounts · product categories · **tags** — όλα tenant-scoped, με
guarded delete, προ-σπαρμένα από `MyDataLookupSeeder` για άμεση έκδοση.
- **Web installer πρώτης εγκατάστασης** (`/install`) — «πέτα» τα αρχεία σε φρέσκο host (άδειο VM ή
  cPanel/DirectAdmin) με μόνο μια κενή βάση + χρήστη· μπαίνεις στη διεύθυνση και ένας οδηγός φτιάχνει
  `.env` (όνομα/URL/περιβάλλον/γλώσσα/ζώνη ώρας + βάση + προαιρετικό SMTP), παράγει `APP_KEY`, ελέγχει
  ζωντανά τη σύνδεση («Δοκιμή σύνδεσης»), τρέχει `migrate` + Shield + τα ελληνικά AADE lookups, και
  δημιουργεί τον πρώτο super-admin + εταιρία — με λίστα επόμενων βημάτων σε επίπεδο διακομιστή (cron,
  queue worker, PHP extensions). **Fail-closed & αυτο-απενεργοποίηση:** middleware `EnsureInstalled`
  (τρέχει πριν το session/APP_KEY stack) δρομολογεί ένα «παρθένο» σύστημα στον οδηγό και μόλις υπάρξει
  `APP_KEY` (ή marker ολοκλήρωσης) κάνει το `/install` μόνιμα ανενεργό (→ `/admin`). **Πύλη με filesystem
  token** (αρχείο στο `storage/app/install/`, επαλήθευση σε κάθε POST) αποδεικνύει πρόσβαση στον διακομιστή,
  και το βήμα `migrate` **αρνείται βάση που έχει ήδη ολοκληρωμένη εγκατάσταση**. Τα per-tenant secrets
  (myDATA/WHMCS/GSIS) μένουν εκτός — ρυθμίζονται αργότερα ανά εταιρία.
  - **Preflight «Έλεγχος συστήματος»** στην κορυφή του οδηγού — read-only έλεγχος περιβάλλοντος που **δεν
    αλλάζει τίποτα** (το `composer install`/ενεργοποίηση επεκτάσεων μένει στο shell· ο installer απλώς
    επαληθεύει το αποτέλεσμα). **Υποχρεωτικά** (PHP ≥ 8.4, εγγράψιμα `storage/`+`bootstrap/cache/`, και οι
    επεκτάσεις χωρίς τις οποίες σκάει κανονική ροή πάνελ/έκδοσης — `pdo_mysql`, `mbstring`, `openssl`,
    `ctype`, `tokenizer`, `dom`, `xml`, `fileinfo`, `intl` (το Filament `->money()` σκάει χωρίς αυτή),
    `soap`) βγαίνουν κόκκινα, **κλειδώνουν το κουμπί «Εγκατάσταση»** και δείχνουν την εντολή διόρθωσης· το
    POST τα ξαναελέγχει server-side πριν αγγίξει τη βάση. **Προειδοποιήσεις** (soft) μόνο ενημερώνουν τι
    δεν θα δουλεύει: `pdo_firebird` → εισαγωγή Firebird, `gd` → η εικόνα QR στο PDF (το παραστατικό
    εκδίδεται και χωρίς αυτή), `curl` → οι κλήσεις HTTP έχουν fallback, `zip` → backups, `bcmath`,
    `proc_open`, τα όρια upload/μνήμης για imports, HTTPS.

---

## Καταργήθηκαν σκόπιμα (δεν τα ξανακάνουμε)
CS-Cart bridge · ΕΑΦΔΣΣ (`EAFDSS_SCRIPT`) · FastReport `.fr3` (→ Blade PDF) ·
`FMysqlSync` MySQL mirror (→ WHMCS API) · `GET_COMB_*` (cross-DB με inline SYSDBA —
security landmine) · `afm2name` (→ native GSIS). **stock/αποθήκη + ΣΔΕΠ/σωρευτικά**
ήταν **νεκρός κώδικας** στο legacy — δεν «λείπουν», απλώς δεν υπήρχαν.
