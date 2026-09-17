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
- **Υποκατάστημα πελάτη ανά παραστατικό** (`invoices.counterpart_branch`, 0=έδρα) — ο χειριστής
  δηλώνει ρητά ποια **εγκατάσταση** του πελάτη τιμολογείται· ένας πελάτης = ένα ΑΦΜ (η «by the book»
  αντικατάσταση του legacy duplicate-ΑΦΜ hack). Ο αριθμός φτάνει στο filed myDATA `Counterpart`
  (`Invoice::filedCounterpartBranch()` = ο ένας ορισμός· 0 σε λιανική/ξένο μέρος)· η διεύθυνση της
  εγκατάστασης γράφεται στο ήδη επεξεργάσιμο address snapshot. Πιστωτικά/επανεκδόσεις κρατούν το branch.
  Στο **ETL** ο legacy δίδυμος (ίδιο ΑΦΜ, δεύτερη εγγραφή) μπαίνει με `--afm-keep=CUST_ID`: κρατά
  ΑΦΜ/παραστατικά/ιστορικό αλλά όχι την ταυτότητα ΑΦΜ (`customers.afm_key_parked`), και ο χειριστής
  αποφασίζει μετά — συγχώνευση ή υποκατάστημα ανά παραστατικό. Η legacy βάση δεν πειράζεται ποτέ.
- **Αρίθμηση** συνεχόμενη ανά τύπο, με **row-lock σε transaction** (`InvoiceNumberer`).
- **QR + PDF** (Blade/dompdf) — **γλώσσα ανά παραστατικό** (Ελληνικά/Αγγλικά/**Δίγλωσσο
  GR-EN**), per-invoice/quote επιλογή με default από τη χώρα πελάτη (GR → Ελληνικά, ξένος
  → δίγλωσσο)· `App\Support\Pdf\PdfLabels` localizes μόνο τις ετικέτες (όχι ποσά/περιεχόμενο),
  σε invoice + quote. **Στοιχεία εκδότη στην κεφαλίδα**: επωνυμία/διεύθυνση/ΑΦΜ/ΔΟΥ/τηλ/email
  + **ΓΕΜΗ** (`companies.gemi`, ν.4919/2022) + **Δραστηριότητα/ΚΑΔ** (`kad_primary`)· απαλλαγή
  ΦΠΑ (§8.3 αιτία) σε 0% γραμμές.
- **Αιτία απαλλαγής ΦΠΑ §8.3 ανά γραμμή** (MYD-007) — κάθε μηδενική γραμμή κρατά τη ΔΙΚΗ της αιτία
  (`invoice_lines.vat_exemption_category`), όχι μία tenant-wide· πεδίο «Αιτία απαλλαγής» στη φόρμα
  (εμφανίζεται σε 0%, υποχρεωτικό, auto-πρόταση από τον τύπο). Helper `VatExemptionGuidance` σε απλά
  ελληνικά (ενδοκοιν. υπηρεσία→4, αγαθά→14, εξαγωγή→8, εγχώριο RC→16, μικρή επιχ.→15, OSS/IOSS/Tax-Free).
  0% χωρίς αιτία = blocking preflight error. Πιστωτικά κληρονομούν την αιτία.
- **Πολιτική κατηγοριοποίησης εσόδων ανά επιχείρηση §8.6** (MYD-006) — `companies.business_activity_type`
  (μεταπωλητής/παραγωγός/υπηρεσίες/μικτή) + helper `ClassificationGuidance`. Ορίζει την κατηγορία
  αγαθών: εμπορεύματα→category1_1, δικά μας προϊόντα→category1_2 (ο `AadeInvoiceDocument` την εφαρμόζει
  όπου η κατηγορία θα έπεφτε στο default εμπορευμάτων· ρητό override ανά κατηγορία προϊόντος υπερισχύει).
  Πιστωτικά κληρονομούν την ταξινόμηση του αρχικού. Go-live gate: δεν γίνεται πράσινο χωρίς επιλογή.
  Picker στη «Ρυθμίσεις εταιρείας» + καρτέλα εταιρείας. Το προϊόν/υπηρεσία δείχνει (create/edit) πώς
  ταξινομείται στην ΑΑΔΕ· στήλη «Κατηγ. εσόδων» στις λίστες Προϊόντων + Κατηγοριών προϊόντων.
  Οι **seed-κατηγορίες προϊόντων** έρχονται με το §8.6 bucket τους έτοιμο (Υπηρεσίες→category1_3,
  Εμπορεύματα→category1_1, Προϊόντα→category1_2· ο E3 **τύπος** μένει channel-driven στον τύπο
  παραστατικού), οπότε ένας μικτός tenant είναι πράσινος out-of-the-box. **New-rows-only** (μια
  υπάρχουσα κατηγορία δεν επαναταξινομείται ποτέ σιωπηλά — MYD-006).
- **Απόδειξη παρόχου ΥΠΑΗΕΣ στο PDF** (A.1112/2025, PROV-003) — για παραστατικό που εκδόθηκε **μέσω
  παρόχου** (υπάρχει `PROVIDER_INSERT` ΜΑΡΚ, VALID/μη-ακυρωμένο), το PDF τυπώνει μπλοκ «Εκδόθηκε μέσω
  παρόχου (ΥΠΑΗΕΣ)»: εμπορική+νομική επωνυμία, ιστότοπος, κωδικός ΑΑΔΕ, **αρ. αδείας ΥΠΑΗΕΣ**, ΜΑΡΚ,
  **UID** (νέα στήλη `mydata_marks.uid`) και **κωδικός αυθεντικοποίησης**. Ταυτότητα παρόχου σε
  immutable config (`einvoice.provider_identity` → `App\Support\EInvoice\ProviderIdentity`), ανά
  `provider_key` — ένας 2ος πάροχος = μία γραμμή. Άμεσα-myDATA/ακυρωμένα → κανένα μπλοκ. Η ταυτότητα
  εν ισχύ **παγώνει ανά έγγραφο** (`mydata_marks.provider_identity`), ώστε rotation αδείας να μη
  ξαναγράφει παλιά τυπωμένα· η **ίδια απόδειξη + η URL επαλήθευσης** (δείκτης στο επίσημο έγγραφο
  παρόχου) εμφανίζεται και στη **σελίδα** του παραστατικού («myDATA / Πάροχος»), από το ίδιο snapshot.
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
- **Επεξεργασία πρόχειρου** — κουμπί «Επεξεργασία» στο παραστατικό & στη λίστα (μόνο σε πρόχειρα,
  gated στο `update`) → πλήρες περιβάλλον (ημερομηνία/τύπος/γραμμές/είδος/τιμή). Για παρόχους,
  προαιρετικό action «Ημερομηνία έκδοσης → σήμερα».
- **Δύο ημερομηνίες, ξεχωριστές** — «Ημερομηνία δημιουργίας» (`created_at`, εσωτερική — πότε φτιάχτηκε το
  πρόχειρο) vs «Ημερομηνία έκδοσης» (`issued_at`, δημόσια/νόμιμη). Στην **αποστολή σε πάροχο/διακίνηση** το
  `issued_at` **σφραγίζεται αυτόματα στη σημερινή** (η έκδοση συμβαίνει τη στιγμή του send· InvoSign 238),
  αντί να μπλοκάρει ένα παλιό πρόχειρο (PROV-020). Το direct-myDATA μένει ως έχει (η ΑΑΔΕ δέχεται backdate).
- **Αρίθμηση χωρίς κενά (gapless-at-send, αναστρέφει το MON-4)** — ο αύξων αριθμός (ΑΑ) δεσμεύεται τη στιγμή
  της **υποβολής**, όχι στη δημιουργία. Όσο είναι πρόχειρο/οριστικοποιημένο-αστάλτο, το παραστατικό δείχνει
  **προσωρινή ταυτότητα** «ΠΡΟΣ-ΤΠΥ-{id}» (`code`=NULL) και δεν καταναλώνει αριθμό· ο πραγματικός αριθμός +
  σειρά μπαίνουν στο send (`InvoiceNumberer::assign`), με **release σε οριστική απόρριψη/σφάλμα** (decrement-
  if-top). Έτσι η σειρά που βλέπει η ΑΑΔΕ είναι πάντα συνεχής και η **διαγραφή πρόχειρου δεν αφήνει κενό**.
  Non-transmitting tenants → αριθμός στην οριστικοποίηση/`NullSubmitter`. **Phase 2 (ΔΑ): ναι** — το ίδιο
  ισχύει και στα **δελτία αποστολής** («ΠΡΟΣ-ΔΑΠ-{id}» όσο είναι πρόχειρο· πραγματικός ΑΑ 9.x στην αποστολή
  μέσω `InvoiceNumberer::assignDelivery`/`releaseDelivery`).
- **Πιστωτικά** (`IssueCreditNote`) — συσχετιζόμενα (5.1) ή μη (5.2), αμφίδρομη
  σύνδεση με το αρχικό· opt-in myDATA filing.
- **Πρόχειρο πιστωτικό ≠ νόμιμη ακύρωση (PROV-019)** — διαχωρισμός τοπικής εμπορικής μείωσης
  (`isFullyCredited`) από νόμιμη ακύρωση ΑΑΔΕ (`isLegallyReversed`: πιστωτικό filed VALID ή αρχικό
  CANCELLED). Το badge/PDF λένε «Μειώθηκε με πρόχειρο πιστωτικό — δεν υποβλήθηκε» μέχρι να γίνει
  πραγματική. Η επανέκδοση κρατά σύνδεσμο στο αρχικό (`reissued_from_invoice_id`)· η υποβολή
  αντικατάστασης ενώ το αρχικό στέκεται ακόμη προειδοποιεί (soft-warn) + ίχνος στο log.
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
- **Σελίδα ΜΑΡΚ** (direction-aware) + per-line E3 classification — τώρα και στα **δικά μας**
  παραστατικά (διακριτική υπο-γραμμή κάτω από την περιγραφή, όχι μόνο στα «αδέσποτα»), και ως
  **compact στήλη «E3 (ΑΑΔΕ)» με tooltip** στις γραμμές του παραστατικού (view). Κοινός
  `IncomeClassResolver` → ό,τι βλέπεις = ό,τι υποβάλλεται.
- **Enrich/έλεγχος από ΑΑΔΕ** (`EnrichInvoiceFromAade`) — από τη Σελίδα ΜΑΡΚ: live-pull
  του MARK, stamp **QR**, συμπλήρωση κενών header πεδίων + **per-field σύγκριση**
  (cross-check τοπικού ↔ ΑΑΔΕ).
- **`mydata:preflight`** — read-only έλεγχος invoice-type/VAT config vs §8 code tables (thin
  renderer πάνω στο κοινό `MyDataConfigAudit`· βλ. «Έλεγχος ρυθμίσεων» tab).
- **Περιβάλλον ανάγνωσης myDATA (override)** — από προεπιλογή η ΑΝΑΓΝΩΣΗ (κονσόλα/συμφωνία/έξοδα)
  ακολουθεί τον «Τρόπο αποστολής» (πάροχος Δοκιμαστικό → Sandbox, Παραγωγή → Production). Νέος
  διακόπτης `mydata_read_env` (καρτέλα myDATA) το **αποσυνδέει**: διαβάζεις **Παραγωγή ενώ στέλνεις
  μέσω δοκιμαστικού παρόχου** (τιμάται μόνο αν υπάρχουν τα creds του περιβάλλοντος· αλλιώς πέφτει σε
  «Αυτόματο»). Μία πηγή: `Company::mydataReadMode()`· οι αναγνώσεις δεν γράφουν ποτέ στην ΑΑΔΕ.
- **`mydata_settings`** (MCP, super_admin, read-only) — η ρύθμιση καναλιού ενός tenant: submit
  channel/mode vs το **resolved περιβάλλον ανάγνωσης** (sandbox/production + AADE endpoint) και ποια
  credential slots είναι γεμάτα (booleans — **ποτέ** τα keys). Η γρήγορη απάντηση στο «γιατί η κονσόλα
  φέρνει μόνο δοκιμαστικά παραστατικά;».
- **`mydata:backfill-config`** — εναρμονίζει imported (ETL) tenants με τα fresh-setup defaults
  (`ConfigBackfiller`): §8.12 τύπος πληρωμής από keyword-suggestion (whole-word/stem, unmatched→null)·
  §8.3 αιτία 0% στην seed-προεπιλογή **μόνο με `--exemption-default`** (νομικός κωδικός, opt-in· 2+ 0%
  κατηγορίες = ambiguous, δεν μαντεύει). Dry-run by default, idempotent, AADE-filing tenants μόνο.
- **Αυτόματος χαρακτηρισμός εξόδων** (`ExpenseClassifier` + «Κανόνες χαρακτηρισμού») — «προμηθευτής
  (+ προαιρ. τύπος) → E3 χαρακτηρισμός»· auto-apply στο import + bulk «Εφαρμογή κανόνων» + worklist
  «Προς χαρακτηρισμό» + «Δημιουργία κανόνα» από έξοδο. (Η υποβολή-για-τρίτο/`entityVatNumber` μένει BACKLOG.)
- **Code tables** (`App\Support\MyData\Codes`) — §8 πίνακες με validation helpers.

## 4. Έξοδα / Προμηθευτές / Ε3
- **Προμηθευτές** (`Supplier`) — CRUD + «Άντληση από ΑΑΔΕ» (GSIS) + **`suppliers:sync`**
  (μοναδικά issuer ΑΦΜ από `RequestDocs`) + **«Συμπλήρωση επωνυμιών από ΑΑΔΕ»** (κουμπί στη
  λίστα + CLI `suppliers:backfill-names` — γεμίζει επωνυμία από GSIS σε παλιούς «αδέσποτους»
  μόνο-ΑΦΜ, fill-only-empty· κοινός `SupplierNameBackfiller`). Ο `suppliers:sync` (και ο
  δίδυμος `customers:sync`) τρέχει πλέον και **αυτόματα** ανά myDATA εταιρεία, ελεγχόμενος από
  τη σελίδα «Χρονοπρογραμματιστής» (default OFF — γράφει μητρώο).
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
- **Τιμολόγιο–Δελτίο Αποστολής (ΤΔΑ) — combined document.** Ένα ΤΔΑ ΔΕΝ είναι ξεχωριστός τύπος: είναι
  ένα **monetary 1.1 τιμολόγιο με `isDeliveryNote=true`** + πλήρες movement header στο ΙΔΙΟ έγγραφο, οπότε
  ζει με τα **Παραστατικά** (`Invoice`), όχι στην Ψηφιακή Διακίνηση. Επιλέγεται ο τύπος «ΤΔΑ» (seed 1.1 +
  flag) → η φόρμα Παραστατικού αποκαλύπτει το movement sub-form (σκοπός §8.14, διευθύνσεις
  φόρτωσης/παράδοσης, μεταφορικό)· ο builder εκπέμπει το isDeliveryNote + κίνηση στο 1.1 (με guard: μόνο
  τύποι που το επιτρέπει η ΑΑΔΕ). Η **ίδια lifecycle διακίνησης** (Έναρξη → Έλεγχος → Επιστροφή) τρέχει
  από την προβολή του παραστατικού μέσω του contract-typed `DeliveryLifecycleService` (`MovableDocument`):
  ένα ΤΔΑ είναι ΕΝΑ έγγραφο/MARK, οπότε **ακυρώνεται από το monetary path** (όχι movement cancel). Fork
  v2.0.2 `withoutDigitalTransportTracking` → φιλάρεται κατευθείαν «ολοκληρωμένο» χωρίς qrUrl/κύκλο ζωής.
  Το PDF του παραστατικού φέρει block «Στοιχεία Διακίνησης» (σκοπός/φόρτωση/παράδοση/μεταφορικό) δίπλα στο
  QR/MARK· το απόθεμα κινείται ΜΙΑ φορά (ως Invoice, ποτέ διπλά από το DeliveryNote path).
- **`DeliveryNoteResource`** invoice-grade (View/lines/Ιστορικό/Συνημμένα), αμφίδρομη
  σύνδεση δελτίο↔τιμολόγιο.
- **Lifecycle (εκδότης)**: έκδοση → έναρξη διακίνησης → *(παρατήρηση αποτελέσματος μέσω ελέγχου
  κατάστασης)* → δήλωση επιστροφής → ακύρωση (`DeliveryLifecycleService` + `DeliveryNoteSubmitter`),
  §7.1 status cache. Το **αποτέλεσμα παράδοσης (ConfirmDeliveryOutcome)** είναι ενέργεια
  **παραλήπτη/μεταφορέα**, όχι εκδότη (η ΑΑΔΕ το απορρίπτει [833] με τα credentials του εκδότη) — δεν
  προσφέρεται ως ενέργεια, μόνο παρατηρείται μέσω «Έλεγχος κατάστασης» (→ delivered/partial/failed).
  Δες `docs/delivery-two-party-sandbox.md`.
- **Δήλωση επιστροφής (ConfirmDeliveryReturn, myDATA v2.0.2)** — όταν ο μεταφορέας δεν παρέδωσε και
  επέστρεψε τα αγαθά: από `rejected`/`partial`/`failed`/`in_transit_return → returned` (το `in_transit`
  απορρίπτεται [828]), η ΑΑΔΕ φέρνει `deliveryReturnMark`
  (cache `delivery_notes.return_mark`, audit `CONFIRM_RETURN`). Τερματικό state (ο έλεγχος κατάστασης
  δεν το πατάει πίσω). Direct-myDATA μονοπάτι· ο durable attempt-record που ξεκλείδωσε το DEP-001.
- **Σκέλος επιστροφής ορατό (v2.0.2)** — το `IN_TRANSIT_RETURN` (9) της ΑΑΔΕ είναι δικό του state
  «Σε διακίνηση (επιστροφή)» (όχι συγχωνευμένο στο in_transit)· ο «Έλεγχος κατάστασης» το αναδεικνύει
  και το ιστορικό δείχνει τα `RegisterTransferReturn`/`ConfirmReturn` events. **Carrier-reported**
  (το firebed δεν εκθέτει submit action· μόνο παρατήρηση μέσω status query).
- **lifecycleHistory** timeline (carrier/recipient events).
- **Χώρα παραλήπτη (frozen)** — ο παραλήπτης μπορεί να είναι πελάτης/προμηθευτής/χειροκίνητος·
  η χώρα του παγώνει στο δελτίο (`recipient_country`, ISO-2) και είναι υποχρεωτική όταν υπάρχει
  ΑΦΜ παραλήπτη. Ξένος παραλήπτης **δεν δηλώνεται ποτέ ως GR**: χωρίς αναγνωρίσιμη χώρα η υποβολή
  απορρίπτεται· GR μόνο για ενδοδιακίνηση. Κοινός normaliser `Support\IsoCountry` (EL→GR, UK→GB)
  με το monetary invoice.
- **Πάροχος vs direct**: έκδοση/ακύρωση μέσω παρόχου· έναρξη/**επιστροφή**/έλεγχος direct myDATA
  (το αποτέλεσμα παράδοσης το δηλώνει ο παραλήπτης/μεταφορέας). Sandbox round-tripped two-party
  (myip⇄nexon, 2026-09-13). (Η επιστροφή μέσω παρόχου — PROV-002 — μένει για επόμενο slice.)
- **CMR (διεθνής φορτωτική)** — αυτοτελές έγγραφο μεταφοράς (ΟΧΙ myDATA), στα Αγγλικά, για
  διασυνοριακές αποστολές. `CmrResource` (standalone «Νέο CMR») + action «Δημιουργία CMR» σε
  Τιμολόγιο/ΔΑ → **προσχέδιο** με μεταγραφή ΕΛΟΤ-743 (ελληνικά→λατινικά), editable πριν την
  εκτύπωση. Προαιρετική πηγή (Τιμολόγιο | ΔΑ | standalone)· `cmr_notes`/`cmr_lines`, `CmrPdf`
  (φόρμα 24 κουτιών), per-company counter. Αγγλικά στοιχεία εταιρείας (Sender). Σχεδίαση:
  `docs/archive/cmr-international-delivery.md`.
- **«Εισερχόμενα Διακίνησης» (recipient inbox)** — η ΠΑΡΑΛΗΠΤΡΙΑ πλευρά: τα παραστατικά διακίνησης που
  ΑΛΛΟΙ έκοψαν σε εμάς (εμπορεύματα που παραλαμβάνουμε). `delivery:fetch-inbound` (scheduler-gated,
  default OFF) αντλεί τη ροή `RequestDocs` (ίδια με τα Έξοδα), κρατά μόνο τα παραστατικά κίνησης
  (`invoiceDeliveryStatus`/`otherDeliveryNoteHeader`/τύπος 9.x) και τα σταδιοποιεί σε δικό τους πίνακα
  `inbound_delivery_notes` (δίδυμος των WHMCS «Εισερχόμενα», ΟΧΙ ο εκδοτικός audit). Το Filament resource
  δίνει ανά έγγραφο: **«Απόρριψη»** (`RejectDeliveryNote` με MARK — desk-actionable), **«Έλεγχος
  κατάστασης (ΑΑΔΕ)»** (`RequestDeliveryNoteStatus`· terminal ακύρωση εκδότη → `cancelled_by_issuer`) και
  τοπικό **«Παραλήφθηκε»**. Η **επιβεβαίωση παραλαβής (ConfirmDeliveryOutcome)** χρειάζεται το qrUrl του
  φυσικού QR (ο counterpart feed δεν το επιστρέφει) → deferred (Slice 4c, BACKLOG). Το idempotent re-poll
  ΠΟΤΕ δεν πατά τη διάθεση του χειριστή. Σχεδίαση: `docs/delivery-inbound-design.md`.

## 6. Πάροχοι e-invoicing & PEPPOL
- **Δίαυλος αποστολής** per-tenant: `gr-mydata` (απευθείας ΑΑΔΕ), `gr-provider`
  (InvoSign — `EInvoiceProviderTransport` + registry), `none`.
- **ProviderConsole** + `einvoice:preflight` / `einvoice:test-submit`. Ο έλεγχος
  ετοιμότητας (Console) απαιτεί ΟΛΑ τα υποχρεωτικά στοιχεία εκδότη που ζητά ο πάροχος
  (επωνυμία/ΚΑΔ/ΔΟΥ/διεύθυνση → σφάλμα· email/τηλέφωνο → προειδοποίηση) και πλήρες
  ζεύγος myDATA read-creds για το ενεργό περιβάλλον (PROV-005). Τα υποχρεωτικά στοιχεία
  εκδότη (+ΑΦΜ) ελέγχονται ΚΑΙ ως gate στο `ekdosi:go-live-check` (το read-creds ζεύγος
  μένει στο Console preflight — μη-μπλοκάρον advisory).
- **Ένδειξη «είναι αποθηκευμένο;» στα διαπιστευτήρια παρόχου.** Κάθε πεδίο της καρτέλας «Πάροχος
  (ΥΠΑΗΕΣ)» γράφει από κάτω αν υπάρχει ΟΝΤΩΣ αποθηκευμένη τιμή: «✓ Αποθηκευμένο (••••1234)» με τα 4
  τελευταία ψηφία για τα μυστικά (ώστε να ξεχωρίζουν δύο tokens χωρίς να αποκαλύπτεται κανένα· ≤4
  χαρακτήρες → πλήρης μάσκα), ή «⚠ Δεν έχει αποθηκευτεί». Ένα μασκαρισμένο πεδίο με προ-συμπληρωμένη
  τιμή είναι οπτικά ίδιο με ένα κενό — η ένδειξη λύνει το «σώθηκε ή όχι;». Μετά από αλλαγή παρόχου λέει
  ρητά ότι τα στοιχεία του προηγούμενου ΔΕΝ μεταφέρονται (αυτό ακριβώς κάνει και η αποθήκευση).
- **Υπόλοιπο εκδόσεων παρόχου (PROV-009)** — ο πάροχος επιστρέφει σε κάθε έκδοση το quota
  (`remaining_invoices`) + τα emails παραλήπτη (`receptionEmails`), που αποθηκεύονται δομημένα στο
  `mydata_marks`. Η κάρτα **«Υπόλοιπο εκδόσεων»** (χρωματισμένη κοντά στο όριο) μπαίνει ως **4η στην
  ομάδα «Εικόνα από myDATA — ΦΠΑ»** του dashboard, στην ίδια γραμμή με τις 3 κάρτες ΦΠΑ (το πλέγμα
  κλειδώνει στις 4 στήλες όσο η σειρά έχει ≥4 κάρτες)· αν ο tenant δεν διαβάζει myDATA, πέφτει πίσω στο
  ξεχωριστό widget **«Πάροχος ΥΠΑΗΕΣ»** (ποτέ και τα δύο) + προειδοποίηση στο log σε χαμηλό
  υπόλοιπο. **Χωρίς polling:** η μέτρηση έρχεται μόνο πάνω στην απάντηση κάθε έκδοσης, γι' αυτό η
  κάρτα γράφει «τελευταία υποβολή …» (ένα ήσυχο δεκαήμερο δείχνει παλιά ένδειξη, όχι χαλασμένο
  refresh).
- **Διακόπτης «Αποστολή email πελάτη στον πάροχο»** (`einvoice_include_customer_email`, καρτέλα «Πάροχος
  (ΥΠΑΗΕΣ)», default OFF) — το `<CounterpartEmail>` που ο πάροχος χρησιμοποιεί για να στείλει το νόμιμο
  παραστατικό στον πελάτη μπαίνει στο XML **μόνο** όταν ο tenant το ανάψει· σβηστό → κενό πεδίο (δεν φεύγει
  email δοκιμαστικά σε πραγματικούς πελάτες). Ισχύει σε τιμολόγια + δελτία αποστολής, μόνο στον δίαυλο
  παρόχου· η δική του ροή email του ekdosi (`SendInvoiceEmail`) είναι ανεξάρτητη.
- **Auto-email πελάτη στην αποδοχή — parity με direct myDATA.** Όταν μια φρέσκια έκδοση μέσω παρόχου
  γίνει αποδεκτή (VALID) και ο tenant το έχει ανοιχτό (`auto_email_on_mydata_accept`, με per-customer
  opt-out), μπαίνει στην ουρά το email με το PDF — από κοινό trait `DispatchesAcceptanceEmail` με τον
  απευθείας δρόμο. Ο `shouldAutoEmailOnFinalize` θεωρεί τον πάροχο «φιλάρει ηλεκτρονικά» (μέσω
  `SendChannel`), οπότε ΔΕΝ στέλνει δεύτερο email στο finalize.
- **UBL / PEPPOL BIS Billing 3.0 (EN 16931) — Phase 1 (προβολή + λήψη, ΟΛΕΣ οι εταιρίες).**
  Provider-independent builder (`PeppolInvoiceDocument` μέσω `josemmo/einvoicing`): κουμπιά
  **«Προβολή UBL»** (modal με το XML + αποτέλεσμα ελέγχου εγκυρότητας) και **«Λήψη UBL»** (.xml) σε
  κάθε παραστατικό — **ανεξάρτητα από τον `einvoice_provider`**, ώστε κάθε tenant (και GR mainland)
  να έχει έτοιμο το τυποποιημένο e-invoice και να «κουμπώνει» εύκολα πάροχο/Access Point αργότερα.
  Ελληνικό-σωστό: `<Country>` = **GR** (ISO 3166-1) αλλά VAT identifier με πρόθεμα **EL**
  (`EL800561849`, EN 16931 BR-CO-9). CLI `peppol:test-submit` (dry-run + validate) + read-only
  MCP/«Βοηθός» εργαλείο **`invoice_ubl`** (ίδια bytes, για έλεγχο εκτός panel). Ο έλεγχος του
  builder είναι υποσύνολο EN 16931 + PEPPOL — ο οριστικός γίνεται από το Access Point (Phase 2:
  transport, backlog).

## 7. Πελάτες & Καρτέλα
- **GSIS lookup** native (`AadeRegistryLookup`) + «Άντληση/Διόρθωση από ΑΑΔΕ».
- **Ένας πελάτης ανά ΑΦΜ (DB-enforced)**: `customers.afm_key` (`Afm::uniqueKey`: ψηφία για GR με/χωρίς
  EL/GR, γράμματα για ξένο VAT, NULL για placeholder/κενό) + `UNIQUE(company_id, afm_key)` και σε
  soft-deleted· φιλικό validation στη φόρμα· ETL/importer/sync/WHMCS όλα μέσω `whereAfmKeyOf`.
- **Διπλοί πελάτες: εντοπισμός + συγχώνευση.** `customers:afm-duplicates` (read-only audit· δείχνει τι
  κρέμεται από κάθε γραμμή και ✓ ποιον να κρατήσεις) και **`customers:merge <keep> <drop>`** /
  action «Συγχώνευση με άλλον πελάτη»: όλα (παραστατικά, πληρωμές, προσφορές, επαφές, ΔΑ, συμβόλαια,
  WHMCS, leads, σημειώσεις, συνημμένα, ετικέτες, ιστορικό) περνούν στον επιζώντα σε μία transaction,
  τα στοιχεία που διέφεραν μένουν ως καρφιτσωμένη σημείωση, ο άλλος διαγράφεται οριστικά. `--dry-run`
  δείχνει ακριβώς τι θα γίνει.
- **Συγχρονισμός πελατών από myDATA** (`CustomerSyncFromMyData` / `customers:sync`) — bulk discovery
  από τα ΑΦΜ συναλλασσομένων στις πωλήσεις μας + GSIS enrichment· lookback presets 3/12/24 μήνες
  (καθρέφτης του `suppliers:sync`).
- **VIES (EU)** — επαλήθευση/άντληση μη-GR ενδοκοινοτικών ΑΦΜ (`ViesLookup`) +
  **reverse-charge hint** (0% + §8.3 «16 — άρθρο 45»).
- **Χώρα ISO (MYD-011)** — καθαρή στήλη `country_code` (ISO-3166-1 alpha-2) σε πελάτες/προμηθευτές
  με **ISO picker** στη φόρμα· κανονικοποίηση στο save (`IsoCountry::syncCountryCode`), backfill
  (`ekdosi:backfill-country-codes`), ETL alignment. Ένας ξένος προμηθευτής δεν «παγώνει» πλέον ως
  ελληνικός σε ΔΑ (τέλος το `suppliers.country` default-GR).
- **Καρτέλα**: ledger κινήσεων, aging, **YoY**, charts, εξαγωγή **PDF/CSV** + email·
  «αναλυτική παρακράτηση» (αξία εγγράφου + παρακράτηση/τέλη κάτω από την αναφορά, χωρίς
  να αλλάζει το υπόλοιπο). **Όψη περιόδου**: φίλτρα (έτος/τύπος/κατάσταση) πάνω από τον
  πίνακα + **σύνολα έτους** (τζίρος καθαρό/με ΦΠΑ, εισπράξεις, υπόλοιπο τέλους) όταν επιλεγεί
  έτος· το τρέχον υπόλοιπο μένει full-history. Header actions ομαδοποιημένα σε dropdowns.
  **Section «Πρόχειρα»**: τα ανέκδοτα πρόχειρα του πελάτη (μη οριστικοποιημένα/μη υποβληθέντα)
  ορατά με link άνοιγμα/επεξεργασία — εκτός υπολοίπου & κινήσεων (`onlyUnissuedDrafts`).
- **Αποστολή Καρτέλας με email — επαφή-aware**: πολλοί παραλήπτες με επιλογή από τον πελάτη
  + τις **επαφές του** με email (role-labelled, π.χ. λογιστήριο), προεπιλογή πελάτης + κύρια
  επαφή, συν ελεύθερα extras· validation + dedupe, ένα PDF για όλους.
- **Επαφές πελάτη** (per-customer): named πρόσωπα (λογιστήριο/τεχνικός/υπεύθυνος) με
  email/τηλέφωνο/ρόλο, μία κύρια ανά πελάτη — τροφοδοτούν την αποστολή Καρτέλας.
- **Tags** (tenant-scoped) + favourites σε customers/products.
- **Στήλες δραστηριότητας στη λίστα** (`Customer::scopeWithInvoiceStats`, SQL grouped sub-select →
  sortable χωρίς per-row PHP): **«Αρ. Παρ/ων»** (πλήθος εκδομένων παραστατικών — LIVE, χωρίς
  πρόχειρα/πιστωτικά· ορατή, τα «0» γκριζάρουν → sort εντοπίζει «νεκρούς» & busy), **«Τζίρος»**
  (καθαρός κύκλος εργασιών = καθαρή αξία πωλήσεων μείον πιστωτικά, default off — signs credit notes
  negative όπως το `LedgerBook`) και **«Τελ. παρ/κό»** (`MAX(issued_at)` πώλησης, default off, σήμα
  churn). Ίδιο LIVE+issued set με το βιβλίο εσόδων. Το **email** sortable· το **τηλέφωνο** default off.
- **Φίλτρα λίστας πελατών**: **Υπόλοιπο** (χρεωστικοί / πιστωτικοί / μηδενικό) + **ανοιχτά
  προτιμολόγια** (drafts) + **Δραστηριότητα** (με/χωρίς παραστατικά — ίδιο alias με τη στήλη) +
  **Email** (με/χωρίς — κενό ή NULL = χωρίς, για κυνήγι πελατών χωρίς email τώρα που στέλνει και ο
  Πάροχος), δίπλα στα Active / αγαπημένα / άμεση τιμολόγηση / από lead / tags. Το «χρεωστικοί»
  είναι ο στόχος του dashboard drill-down («Ανεξόφλητα»)· ίδια μαθηματικά υπολοίπου με το headline.

## 7β. Leads / mini-CRM (pre-customer)
Υποψήφιοι πελάτες ΠΡΙΝ γίνουν `Customer` — χωρίς money semantics (ποτέ παραστατικά/υπόλοιπα/myDATA).
Design + gates: `docs/archive/leads-mini-crm.md`. **Χτισμένο (L0):**
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
- **`leads_pulse`** (MCP + Βοηθός, `ViewAny:Lead`): ο σφυγμός των leads απ' έξω — σύνολα, τι έκανε κάθε
  χειριστής στην περίοδο, ποιος άνοιξε τα τελευταία leads και πότε, τελευταίες κινήσεις.
- Λίστα ↔ Πίνακας ↔ Ημερολόγιο: κουμπιά-links στο header των τριών σελίδων. Το ημερολόγιο δηλώνει ότι
  δείχνει μόνο leads **με** επόμενο βήμα και πόσα ανοιχτά δεν έχουν (με link στη λίστα).

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
- **Στατιστικά χρέωσης ανά συμβόλαιο** (`ServiceContractBilling`, tenant-scoped/reusable): φορές
  τιμολογήθηκε · **συνολικό έσοδο** (καθαρό, live, μείον πιστωτικά) · μικτό · πρώτη/τελευταία χρέωση ·
  εκκρεμή πρόχειρα · **ιστορικό τιμής καταλόγου** (audit log). Tab **«Ανανεώσεις»** = read-only λίστα
  παραστατικών του συμβολαίου.
- **«Σύνδεση υπάρχοντος παραστατικού»** (retro-link): «κουμπώνει» ένα ήδη-εκδομένο παραστατικό του πελάτη
  σε σύμβαση (θέτει μόνο `service_contract_id`· tenant+customer scoped, re-checked στο write) — για
  χειροκίνητες πωλήσεις που έγιναν πριν φτιαχτεί το συμβόλαιο.

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
- **Inbox status tabs** (CFM-style, με live counts): Ανοιχτά · Προς έλεγχο · Σε αναμονή · Προσχέδια ·
  Καταχωρημένα · Απορρίφθηκαν · Διαχωρισμένα · Ολοκληρωμένα · Όλα. Default **«Ανοιχτά» = pending_review + held
  μαζί**, ώστε ένα «Σε αναμονή» να μη κρύβεται· nav badge = pending_review + held.
- **Cut-over κατά ημ. ΠΛΗΡΩΜΗΣ** (`whmcs_invoice_min_date`, plugin ≥ 0.48.0): το feed φέρνει ό,τι **πληρώθηκε**
  από το cut-over κι έπειτα (όχι κατά ημ. έκδοσης) — αργοπληρωμές παλιών invoices εμφανίζονται τη μέρα που
  πληρώνονται, χωρίς flood ιστορικών. (Native fetch path ακόμη creation-date → BACKLOG.)
- **Ημ. πληρωμής + transaction id**: το modal του WHMCS τιμολογίου δείχνει «Ημ. πληρωμής» + «Πληρωμή/Συναλλαγές»
  (transaction id/gateway/ημ/νία/ποσό, από `tblaccounts` μέσω feed)· στη λίστα η ημ. πληρωμής διπλώνει στο κελί
  «Ποσό» («Πληρωμένο · 15/09»)· το `whmcs_inbox_list` MCP tool τα εκθέτει για insights.
- **Mass-pay (συγκεντρωτικό πληρωμής)**: μια κατάθεση για πολλά προτιμολόγια → **«Ενοποίηση σε ένα»** (ένα
  παραστατικό με τις πραγματικές γραμμές όλων, νόμιμο συγκεντρωτικό) ή **«Ανάλυση σε επιμέρους»** (ένα ανά
  παραγγελία). Τρίτου πελάτη → εξαιρείται/split. Το mass-pay κλείνει «Ολοκληρώθηκε», ποτέ δεν φιλάρεται·
  write-back 1→N (MARK σε όλα τα επιμέρους WHMCS invoices). `MassPayConsolidator`.
- **Αυτόματη κατηγορία εσόδων ανά ομάδα προϊόντων** (MYD-006 bridge) — σελίδα «Αντιστοίχιση WHMCS
  (έσοδα)»: αντλεί τον κατάλογο (`GetProducts`), ο χειριστής ορίζει §8.6 bucket **ανά ομάδα** («Web
  Hosting → υπηρεσία»· νέα πακέτα κληρονομούν). Ο mapper γεμίζει per-line snapshot
  (`invoice_lines.mydata_income_class(_category)`, `WhmcsIncomeClassifier` product→group→fallback)· ο
  submitter το διαβάζει πρώτο. Ποσό/περιγραφή μένουν του WHMCS. Plugin feed v0.44.0 δίνει `whmcs_product_id`/
  `whmcs_group_id` ανά γραμμή.
- **Αυτόματος §8.12 τρόπος πληρωμής ανά gateway** — σελίδα «Αντιστοίχιση WHMCS (πληρωμές)»
  (`WhmcsPaymentMapping`): αντλεί τα ενεργά gateways (`GetPaymentMethods`), ο χειριστής ορίζει τρόπο
  πληρωμής ekdosi **ανά gateway** («Stripe → Κάρτα», «Τραπεζική → Κατάθεση»· μόνο εξοφλημένοι-στην-έκδοση
  τρόποι). Ο mapper διαβάζει τον gateway (`paymentmethod`) σε **πληρωμένο** τιμολόγιο → `whmcs_payment_maps`
  → `WhmcsPaymentMethodResolver`, fallback στον τύπο (ή για ΑΠΛΗΡΩΤΟ)· ο τρόπος ekdosi κουβαλά τον §8.12,
  οπότε ένα κάρτα/κατάθεση-πληρωμένο τιμολόγιο δεν δηλώνεται πια «Μετρητά». Plugin feed v0.45.0 κουβαλά το
  `paymentmethod` (bridge parity με το native).
- **Paid/unpaid-aware τιμολόγηση**: badge «Πληρωμή WHMCS» (Πληρωμένο/Απλήρωτο) στο inbox· το draft
  προ-επιλέγει τύπο βάσει κατάστασης — ΑΠΛΗΡΩΤΟ → «Προεπιλεγμένος τύπος για ΑΠΛΗΡΩΤΑ» (επί πιστώσει →
  ανοιχτή οφειλή), ΠΛΗΡΩΜΕΝΟ → cash-term (τιμολόγιο/απόδειξη κατά πρόθεση)· override πάντα.
- **Inbound συγχρονισμός πληρωμών** (`whmcs:sync-payments`, opt-in scheduled): όταν ένα επί-πιστώσει
  WHMCS τιμολόγιο πληρωθεί στο WHMCS, καταγράφεται Payment στο ekdosi που κλείνει την οφειλή —
  money-write μόνο στο ekdosi, only-if-open + dedup. **On-demand και από το UI**: header action
  «Συγχρονισμός πληρωμών τώρα» στο inbox (bulk) + per-invoice «Έχει πληρωθεί στο WHMCS;» πάνω σε
  ανοιχτό WHMCS-συνδεδεμένο παραστατικό.
- **Αυτόματη καταγραφή είσπραξης στην έκδοση** (`WhmcsReceiptRecorder`): όταν το WHMCS τιμολόγιο ήταν
  **ήδη πληρωμένο** τη στιγμή της έκδοσης (Eurobank vPOS / έμβασμα — η περίπτωση που ο inbound sync ΔΕΝ
  πιάνει, γιατί είναι only-if-open), καταγράφεται αυτόματα η αντίστοιχη είσπραξη πάνω στο νέο παραστατικό
  με το money-trail (gateway + πραγματικό transaction id + ημερομηνία, από το payload). Τρέχει και στα
  **δύο** μονοπάτια έκδοσης (ανεπιτήρητο `whmcs:auto-issue`/direct `file()` + draft-first lifecycle),
  best-effort (ποτέ δεν μπλοκάρει το AADE filing), idempotent, και συνεργάζεται με τον inbound sync
  (full receipt → balance 0 → only-if-open ⇒ no double). Είναι απλή, **editable/deletable** πληρωμή στο
  tab «Πληρωμές» (όχι νομικό παραστατικό — λάθος διορθώνεται εκεί, μηδέν επίπτωση στο myDATA).
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
  Το καμπανάκι **αυτο-καθαρίζεται** (mark-read για όλους τους operators) μόλις η γραμμή χειριστεί
  (εκδοθεί / προσχέδιο / αρχειοθετηθεί / ενοποιηθεί / διαχωριστεί), μέσω `PendingWhmcsInvoiceObserver`
  + structured tag στο viewData· backfill παλιών: `php artisan whmcs:resolve-immediate-bells`.
- **Πρόθεση πελάτη** (τιμολόγιο/απόδειξη, ΑΦΜ/ΔΟΥ, «λείπει ΑΦΜ»).
- **Εισαγωγή πελάτη από ΑΦΜ μέσα στο «Δημιουργία Παραστατικού»** — επεξεργάσιμο ΑΦΜ +
  GSIS lookup (επίσημα ΑΑΔΕ + συμπλήρωση email/τηλεφώνου/διεύθυνσης από WHMCS), δημιουργεί
  & συνδέει τον πελάτη χωρίς να φύγει ο χειριστής· διαφορές ΑΑΔΕ↔WHMCS → κρατιέται το
  επίσημο **με προειδοποίηση** (`WhmcsCustomerCreator`).
- **Αμφίδρομη ορατότητα** (WHMCS-side): badge+ΜΑΡΚ, badge λίστας, «Αποστολή στο Ekdosi»,
  **3-way map** (WHMCS#→ΤΠΥ→ΜΑΡΚ), συγκεντρωτική λίστα, AFM-keyed + deterministic
  `invoiced===legacy_id` historical link. **«Άμεσο» κόκκινη γραμμή** στη λίστα τιμολογίων
  (plugin v0.40) — οι πελάτες άμεσης τιμολόγησης με αστάλτο τιμολόγιο βάφονται κόκκινοι.
- **«Εκδοθέντα Παραστατικά» για τον πελάτη** (client-area, plugin v0.47, `show_client_issued`,
  default OFF): ο reseller βλέπει σε πίνακα τα ekdosi παραστατικά που εκδόθηκαν γι' αυτόν μέσω της
  γέφυρας — **στο όνομά του ΚΑΙ σε τρίτους που δρομολόγησε ο ίδιος** — με ΤΠΥ/ΜΑΡΚ/κατάσταση,
  **επίσημο PDF** και σύνδεσμο **επαλήθευσης ΑΑΔΕ/παρόχου** (context-aware label «Προβολή παρόχου
  (ΥΠΑΕΣ)» vs «Επαλήθευση ΑΑΔΕ» μέσω `verify_kind`). Δύο leak-proof πηγές: **bridge-derived**
  (`pending_whmcs_invoices.whmcs_userid`· το ekdosi είναι η αυθεντία, split → πολλά παραστατικά) **και
  historical (pre-bridge)** — `invoices.whmcs_invoice_id` matched στα **δικά του** WHMCS invoice ids
  (`tblinvoices.userid`, τα στέλνει το plugin). Endpoints `issued-for-client` (POST) + `issued-doc-pdf`
  (GET). Το authorization boundary είναι πάντα τα invoices **που πλήρωσε ο ίδιος**, όχι ο ΑΦΜ του τρίτου
  (κανένα leak). Το **PDF περνά proxy** (bytes server-side πάνω από το HMAC· το signed URL δεν εκτίθεται)·
  κάθε αίτημα ξανα-ελέγχει membership + `isPubliclyViewable()` (drafts/ακυρωμένα → 404).
- **timologia v2 / τρίτοι** — resolution, single-party billing, **multi-party guided
  split** (όχι σιωπηλό ανακάτεμα), editable routing. **Τύπος ανά δικαιούχο**: η ΙΔΙΑ μερίδα
  του μεταπωλητή τυποποιείται από το ΔΙΚΟ του ΑΦΜ (χωρίς ΑΦΜ → Απόδειξη), οι routed γραμμές
  από το ρητό per-route `is_receipt` προς τον τελικό πελάτη (κάθε δικαιούχος → δικό του παραστατικό).
- **Άμεση τιμολόγηση (auto-issue) type-aware** — διαλέγει Απόδειξη/Τιμολόγιο από την πρόθεση
  (ΑΦΜ/wantsinvoice ή route is_receipt)· `whmcs_default_invoice_type_id` + `whmcs_default_receipt_type_id`·
  ό,τι δεν τυποποιείται με ασφάλεια ΜΕΝΕΙ στο Inbox (ποτέ λάθος τύπος).
- **Συγχρονισμός «Άμεσης τιμολόγησης» από το WHMCS (WHMCS = πηγή αλήθειας)** — το `needs_immediate_invoice`
  σπέρνεται στη δημιουργία πελάτη ΚΑΙ **καθρεφτίζεται σε κάθε ingest** στον συνδεδεμένο (πρωτεύοντα) πελάτη
  (`WhmcsInvoiceIngestor` + `PendingWhmcsInvoice::wantsImmediateInvoice`): αν κάποιος γκρινιάξει έναν μήνα μετά,
  τσεκάρεις «γκρινιάρης» στο WHMCS και **περνά** (ON→ON, OFF→OFF) στο επόμενο fetch. Γράφει μόνο σε πραγματική
  αλλαγή (audited «Σύστημα»)· **δεν αγγίζει** τίποτα αν ο tenant δεν έχει χαρτογραφήσει το `griniaris`. Για
  συνδεδεμένους πελάτες το toggle στη φόρμα κλειδώνει read-only («Ελέγχεται από το WHMCS»).
- **«Τιμολόγιο πριν την πληρωμή» (`needs_invoice_before_payment`)** — ξεχωριστή ανά-πελάτη σήμανση για
  δημόσιο/δήμους/Α.Ε. που θέλουν παραστατικό ΠΡΙΝ πληρώσουν. Η εντολή **`whmcs:fetch-unpaid`**
  (`WhmcsUnpaidFetcher`) φέρνει τα **ΑΠΛΗΡΩΤΑ** WHMCS invoices αυτών των πελατών στο Inbox για **χειροκίνητη**
  έκδοση επί πιστώσει (τύπος `whmcs_default_unpaid_type_id`, ανοιχτό υπόλοιπο) — badge «Απλήρωτο» + φίλτρο.
  **ΠΟΤΕ αυτόματα** (μόνο STAGE· `chooseType()` κρατά κάθε unpaid· η auto-issue κλειδώνει στο άλλο flag).
  OFF by default (`EKDOSI_SCHEDULE_WHMCS_FETCH_UNPAID`).
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
- **Καμία αυτόματη έκδοση 0% ΦΠΑ** (`assertNoUntaxedForUnattendedIssue`, `file(unattended: true)`) — ένα
  παλιό WHMCS προϊόν με «Apply Tax» off έρχεται ως `taxed=0` → 0% ΦΠΑ, που για εγχώρια υπηρεσία οφείλει
  24% ενώ για ενδοκοινοτικό reverse-charge είναι σωστό· η **άμεση τιμολόγηση** δεν ξεχωρίζει τις δύο, οπότε
  **κρατά** κάθε τέτοια γραμμή για άνθρωπο (ακόμη κι όταν υπάρχει configured λόγος απαλλαγής). Στη
  χειροκίνητη «Δημιουργία προσχεδίου» βγαίνει **soft warning** (όρισε 24% με gross-edit για ίδιο τελικό, ή
  επιβεβαίωσε απαλλαγή) — χωρίς να μπλοκάρει τη ροή draft-first.
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
  Τα widgets διαβάζουν **cache ανά κομμάτι** (`DashboardMetricsCache`) αντί να ξανα-τρέχει το καθένα
  τα aggregates του σε κάθε άνοιγμα· η `dashboard:warm-metrics` (scheduler, `EKDOSI_SCHEDULE_DASHBOARD_METRICS`)
  προθερμαίνει το cache ανά tenant (τρέχον + προηγούμενο έτος), το κουμπί **«Ανανέωση»** το μηδενίζει άμεσα
  ανά tenant, και τα ποσά στους άξονες/tooltips των γραφημάτων εμφανίζονται σε **€** (Backlog #7).
- **Έσοδα ανά κατηγορία** (`RevenueByCategoryReport`, perm `View:RevenueByCategoryReport`) — καθαρά/ΦΠΑ/
  μεικτά ανά ekdosi `ProductCategory` για ένα έτος, με **% τζίρου**, **YoY** vs πέρσι, σύνολα, export CSV.
  Η κατηγορία γραμμής: `invoice_lines.product_category_id` (σφραγίδα WHMCS) → κατηγορία προϊόντος →
  «Αταξινόμητα». Ζωντανά/εκδοθέντα μόνο, πιστωτικά αφαιρούνται (`App\Services\Accounting\RevenueByCategory`).
  Οι WHMCS ομάδες αντιστοιχίζονται σε κατηγορία ekdosi στη σελίδα «Αντιστοίχιση WHMCS» (στήλη «Κατηγορία
  ekdosi», δίπλα στη §8.6)· σφραγίζεται σε νέα παραστατικά (forward-only). Backlog #2.
- **Ισοζύγιο Ειδών/Υπηρεσιών** (`RevenueByItemReport`, perm `View:RevenueByItemReport`) — ίδια εικόνα με το
  «Έσοδα ανά κατηγορία» αλλά **ανά είδος/υπηρεσία**: καθαρά/ΦΠΑ/μεικτά + **ποσότητα**, δεσπόζουσα κατηγορία,
  **% τζίρου**, **YoY**, σύνολα, export CSV (όλα τα είδη· η οθόνη δείχνει top-200 + γραμμή «Λοιπά είδη»).
  **Τα σύνολα συμφωνούν 1-προς-1 με το «Έσοδα ανά κατηγορία»** (ίδιο scope/έκπτωση/πιστωτικά — πιστωτικά
  αφαιρούν αξία **&** ποσότητα). Ταυτότητα γραμμής: `product_id` → προϊόν, αλλιώς η περιγραφή
  **κανονικοποιημένη** (`App\Support\Accounting\ItemLabelNormalizer` αφαιρεί την τελική παρένθεση περιόδου π.χ.
  «(1/9/2026-31/8/2027)» ώστε οι ανανεώσεις του ίδιου πακέτου να μετρούν μαζί — display-only, δεν αγγίζει το
  παραστατικό) (`App\Services\Accounting\RevenueByItem`). Backlog #4.
- **ΦΠΑ ανά περίοδο** (`VatPeriodReport`, μήνας/τρίμηνο) — εκροών − εισροών (πόσο ΦΠΑ θα χρωστάμε).
- **Ηλικίωση οφειλών** (`AgedReceivables`) — ανοιχτό υπόλοιπο ανά πελάτη σε buckets 0-30/31-60/61-90/90+
  (ίδιο FIFO aging με την Καρτέλα), σύνολα, drill στην Καρτέλα, εξαγωγή CSV. **Εργασίες Είσπραξης (Φάση A):**
  κουμπί «Εργασία είσπραξης» ανά πελάτη (ανάθεση/επόμενο βήμα+ημ/νία/σημείωση/καταγραφή επαφής) + στήλες
  «Επόμενο βήμα»/«Τελ. επαφή» (κατάσταση σε `customers.collection_*`· record-keeping, χωρίς ειδοποιήσεις).
- **Ισοζύγιο Πελατών** (`CustomerTrialBalance`, perm `View:CustomerTrialBalance`) — ανά πελάτη
  `Υπόλοιπο μεταφοράς | Χρέωση | Πίστωση | Τελικό` για ελεύθερη περίοδο (Από/Έως), σύνολα, drill στην
  Καρτέλα, εξαγωγή CSV. Ίδια βάση με την Καρτέλα (`CustomerLedgerBuilder::periodBalances`) → το «Τελικό»
  ισοσκελίζει με το υπόλοιπο Καρτέλας και τα ανεξόφλητα (`App\Services\Accounting\CustomerTrialBalanceReport`).

## 13. Migration / ETL
- **`migrate:firebird`** — επαναλήψιμο ETL, μία εταιρία/run, upsert σε
  `(company_id, legacy_id)`, χειρισμός WIN1253, UI εισαγωγής (`.fdb`/`.fbk`).
- **Ζωντανή σύνδεση Firebird** — tab «Ζωντανή σύνδεση» στη φόρμα εισαγωγής: απευθείας στη ζωντανή legacy
  βάση (IP + διαπιστευτήρια + διαδρομή `.fdb`), χωρίς gbak/upload, με κουμπί **«Έλεγχος σύνδεσης»**
  (μετρά CUSTOMER/INVTYPE/INVOICE/PRODUCT πριν το import· read-only· κωδικός μόνο στη μνήμη).
- **Εισαγωγή Epsilon Smart (JSON)** — tab «Epsilon Smart» στη φόρμα εισαγωγής (`EpsilonImporter`,
  επαναλήψιμο upsert με φυσικό κλειδί): Πελάτες (ΑΦΜ) / Είδη·Υπηρεσίες (→ προϊόντα) / Πωλήσεις (ιστορικά
  παραστατικά με ΜΑΡΚ) και **Πληρωμές/Υπόλοιπα** — εμβάσματα & εισπράξεις πελατών → πληρωμές **«έναντι»
  (on-account)** που μειώνουν το υπόλοιπο, idempotent με το Epsilon UID· ακυρωμένες/ακυρωτικές εισπράξεις
  παραλείπονται· στο τέλος **αναφορά συμφωνίας** ekdosi vs `EpsilonBalance` ανά πελάτη (καρφώνει τα
  μετασχηματισμένα ΔΑ / cash-bank πιστωτικά που θέλουν χειροκίνητη τακτοποίηση).

## 14. Backups / Portability / DR
- **Per-company backups** (`spatie/laravel-backup`) — πρόγραμμα/διατήρηση/προορισμοί
  (Τοπικά/SFTP/FTP/S3), «Αντίγραφο/Λήψη τώρα».
- **Διατήρηση καθολικών (whole-DB) backups — env-tunable** (`BACKUP_KEEP_ALL_DAYS`/`DAILY_DAYS`/
  `WEEKLY_WEEKS`/`MONTHLY_MONTHS`/`YEARLY_YEARS` + `BACKUP_MAX_STORAGE_MB`, `config/backup.php`).
  Default «ελαφρύ + λίγοι μήνες»: όλα 7 μέρες → άλλες 30 μέρες 1/μέρα → 6 μήνες 1/μήνα (προσθετικές
  βαθμίδες, ~7 μήνες σύνολο)· το πιο πρόσφατο δεν σβήνεται ποτέ. Ξεχωριστό από τα per-company παραπάνω.
- **Export/Import εταιρίας** — settings+setup ή πλήρες· **χωρίς υποχρεωτικό κωδικό**
  (passphrase ή raw, με σαφή plaintext προειδοποίηση στο raw)· `company:export`/`company:import`
  + panel actions. Η κατάσταση κρυπτογράφησης **καθολικών** αντιγράφων (env `BACKUP_ARCHIVE_PASSWORD`)
  φαίνεται read-only («🔒/⚠ χωρίς κωδικό») στις «Ρυθμίσεις συστήματος».
  - **Χειριστές (operators) μέσα στο bundle** — το export κουβαλά τους ανατεθειμένους χρήστες της
    εταιρίας (email + όνομα + ο ένας managed ρόλος: super_admin/company_admin/operator), **ΠΟΤΕ κωδικό**.
    Στο import: υπάρχων χρήστης (match με email) συνδέεται + παίρνει τον ρόλο του· χρήστης που λείπει
    **δημιουργείται** με τυχαίο κωδικό (login μόνο μέσω «ξέχασα τον κωδικό») → στήνεται η ομάδα σε φρέσκο VM
    χωρίς να ταξιδεύει credential.
  - **WHMCS default τύποι (απόδειξη/απλήρωτο)** rewire σωστά στο import (μαζί με τον τύπο τιμολογίου) —
    δείχνουν στον εισαγόμενο invoice_type αντί για stale source id.
  - **Install από bundle** — `ekdosi:install --bundle=<zip> [--bundle-passphrase=…]` σηκώνει φρέσκο box
    με προ-ρυθμισμένη πρώτη εταιρία (όλα τα παραπάνω) + install-admin ως super_admin σε μία εντολή· skip
    lookup-seeding (το bundle τα κουβαλά). Ιδανικό για νέα cPanel/DirectAdmin installs (MyIP/nexon).
- **Επιλεκτική εξαγωγή CSV** (Phase 3) — checkboxes «τι να τραβήξω» → .zip με CSV ανά
  entity (Excel-ready, UTF-8 BOM)· tenant-scoped + redaction μυστικών· «Εξαγωγή CSV»
  στο panel + `company:export-csv` (`CsvEntityExporter`).
- **DR χωρίς APP_KEY** — `MaybeEncrypted` cast + `EKDOSI_ENCRYPT_SECRETS_AT_REST`
  (default plaintext) → plain `mysqldump` αυτάρκες· `secrets:reencrypt` για εναλλαγή.
- **DB snapshot/restore** (`ekdosi:db-snapshot` / `ekdosi:db-restore`) — γρήγορο
  τοπικό gzip στιγμιότυπο όλης της ΒΔ ως rollback point (creds από .env, password
  μέσω `MYSQL_PWD`). Restore guarded (production → `--force`). Το rollback layer
  των updates (ξεχωριστό από τα off-site spatie αρχεία).
- **Ασφαλή updates** — `deploy/update.sh <tag>` (read-only data pre-flight→snapshot→maintenance→
  queue drain→checkout→composer→migrate→optimize→shield→queue:restart→ops:health) +
  `deploy/rollback.sh`· version tags via `ekdosi:release`. Runbook: `docs/updates-runbook.md`.
- **`roles:reprovision`** — διαγνωστικό/επισκευαστικό για τα δικαιώματα των `company_admin`/`operator` ανά
  tenant: `--dry-run` (τι λείπει/περισσεύει), **προσθετικό** by default (δεν σβήνει χειροκίνητες
  προσαρμογές, σε αντίθεση με το πλήρες re-sync του `shield:sync-super-admin` που τρέχει στο deploy),
  `--tenant=` για μία εταιρεία, `--prune` για πλήρη ευθυγράμμιση.
- **Queue drain χωρίς root** (`ops:queue-drain`) — hook → systemd → portable (`queue:restart` +
  αναμονή μέχρι να μην τρέχει job). Δουλεύει και σε cPanel/Plesk/DirectAdmin/shared ή με cron worker·
  το deploy σταματά μόνο αν μείνει job σε εξέλιξη (`QUEUE_DRAIN_TIMEOUT`).

## 15. Ασφάλεια & λειτουργικά
- **Secrets `$hidden`** (out of toArray/logs) + at-rest encryption optional.
- **Password policy** (`Password::defaults()`): min 8 **+ έλεγχος διαρροής** (HaveIBeenPwned k-anonymity,
  fail-open), σε χειριστές/CustomerUsers/portal-reset/create-user.
- **Security headers** σε κάθε απόκριση (nosniff / Referrer-Policy / X-Frame-Options SAMEORIGIN).
- **«Οι συνεδρίες μου»** (user menu): λίστα ενεργών συνεδριών (συσκευή/IP/last-active) + per-session
  revoke + password-confirmed «Αποσύνδεση όλων των άλλων», guard-scoped στον web χειριστή.
- **Portal login enumeration-resistant** (ίση χρονική απόκριση known/unknown + generic μήνυμα).
- **2FA** (TOTP) + `EKDOSI_REQUIRE_2FA`. Self-service enrolment στο προφίλ (QR)· στους «Χρήστες»
  στήλη κατάστασης «2FA» + φίλτρο + «Επαναφορά 2FA» (admin disable/reset — η ενεργοποίηση μένει
  self-service).
- **Log συνδέσεων & ασφάλειας** (`auth_events`) — σύνδεση/αποσύνδεση/**αποτυχία** και στα δύο panels
  (`/admin`, `/user`) με IP + user-agent + επιχειρούμενο username (ακόμη & ανύπαρκτο· κωδικός ποτέ):
  ορατότητα για recon/brute-force. Tab «Συνδέσεις & ασφάλεια» στη «Δραστηριότητα» (**super-admin
  μόνο**), retention με `model:prune`. Στους «Χρήστες»: «Τελ. σύνδεση» + «IP». `TRUSTED_PROXIES` για
  πραγματικό client IP πίσω από edge.
- **FK-aware delete guard** (`GuardedDeleteAction`) — μπλοκάρει διαγραφή lookup σε χρήση, σε **single + bulk +
  force** (η μαζική/οριστική διαγραφή παραλείπει τις σε-χρήση εγγραφές με σύνοψη «Διαγράφηκαν/Παραλείφθηκαν»).
- **Off-site backup verification** (`ops:health` → `backup.companies`) — ανά tenant με
  ενεργά backups: υπάρχει προορισμός **εκτός VM** (sftp/ftp/s3); και πέτυχε η τελευταία
  off-site αποστολή; `offsite_gap` προειδοποιεί για «μένουν μόνο τοπικά» ή αποτυχημένο push·
  **`books_gap`** προειδοποιεί για backup που **δεν περιέχει τα βιβλία** (bucket≠full).
- **`ops:health` verdict + exit code** — `OperatorHealthSeverity` αποστάζει το report σε
  **0=ok / 1=warning / 2=critical**, ώστε το deploy gate + cron `ops:health || alert` να είναι
  ζωντανά· **failed queue jobs ειδοποιούν** (`Queue::failing` → ίδιο ops email με τα exceptions).
- **`ops:health` cron/worker disambiguation + `ops:cron` helper** (OPS-001/OPS-003) — ξεχωριστός
  **scheduler heartbeat** (το `schedule:run` γράφει σφυγμό ΣΥΓΧΡΟΝΑ κάθε λεπτό, χωρίς worker) ώστε το
  health να λέει **«ο cron δεν τρέχει»** αντί να ρίχνει το φταίξιμο στον worker (ο σφυγμός του worker
  είναι job που τον στέλνει ο cron — άρα όταν ο cron πέσει ο worker δεν κατηγορείται). Ίδιο cron gate
  και στο `ekdosi:go-live-check`. **`ops:cron`**: τυπώνει τις ακριβείς γραμμές crontab + queue worker
  για αυτόν τον host — VPS (systemd) **και** shared-hosting/cPanel/DirectAdmin (cron-driven worker,
  `--stop-when-empty`, με το πραγματικό PHP path) — μαζί με την τρέχουσα κατάσταση. `docs/shared-hosting-deploy.md`.
- **`lookups:seed` — headless (ξανα)στήσιμο/backfill τυπικών AADE lookups** (`--tenant=SLUG` / `--all`
  / `--json`): το CLI-δίδυμο του «Εισαγωγή τυπικών», idempotent, ώστε ένα `git pull` σε
  cPanel/DirectAdmin να συμπληρώνει ό,τι λείπει (νέους τύπους/κατηγορίες που δεν υπάρχουν ακόμη, ή
  κατηγοριοποίηση myDATA fill-empty σε τύπους χωρίς αυτή) χωρίς να ανοίξει ο χειριστής το panel ανά tenant.
- **`ops:health` shared-webhook-secret detector** (SEC-3) — row «Security» + warning όταν δύο
  tenants μοιράζονται `whmcs_webhook_secret` (forgeable cross-tenant webhooks)· συγκρίνει hash του
  decrypted, ποτέ plaintext στο report.
- **Build stamp + read-only update check** — δίπλα στο όνομα (και `ekdosi:version`) η ταυτότητα του
  deployed build `v{SemVer} · 2026.07.11-150101 (sha)`, παραγόμενη αυτόματα από το git commit στο
  deploy (`storage/app/build.json`, ώρα Ελλάδας· fallback live git σε dev). Το SemVer μένει σκόπιμο
  (`ekdosi:release`). Η «Υγεία συστήματος» δείχνει read-only αν υπάρχει νεότερη έκδοση στο GitHub
  («N commits πίσω» + link), cached 6h, graceful offline. `docs/versioning-and-updates.md`.
- **Εξαγωγή παραστατικών σε PDF (παράδοση σε εταιρεία που φεύγει)** — `company:export-pdfs
  --tenant=SLUG`: όλα τα τιμολόγια + δελτία αποστολής σε PDF, ένα zip ανά έτος/είδος, με
  `index.csv` (ΜΑΡΚ, ημερομηνία, πελάτης, σύνολο) και README. Συμπληρωματικό του `company:export`
  (bundle επαναφοράς, μόνο για άλλο ekdosi) — αυτό διαβάζεται από άνθρωπο και λογιστή χωρίς την
  εφαρμογή. Η **διαγραφή εταιρείας** δείχνει τι υποβεβλημένο χάνεται και δείχνει και τα δύο.
- **In-app ενημέρωση από GitHub — ΑΠΕΝΕΡΓΟΠΟΙΗΜΕΝΗ by default** (`EKDOSI_UPDATE_IN_APP_APPLY=false`,
  triage 2026-09-02). Ο **έλεγχος** ενημερώσεων μένει ενεργός· η αναβάθμιση γίνεται από τον server με
  **`deploy/update.sh <tag>`** (και `deploy/rollback.sh` για επαναφορά) — η «Υγεία συστήματος» δείχνει
  τη νέα έκδοση **και την ακριβή εντολή**. Λόγος: το ίδιο το updater audit είχε βγάλει «μη βασίζεσαι
  στο UI apply μέχρι να κλείσουν τα UPD-001…004» (δεν αδειάζει τον queue worker που κάνει restart,
  fails open μετά από μερική εφαρμογή, στοχεύει mutable tag αντί για verified SHA). Ο μηχανισμός
  παρακάτω **υπάρχει ολόκληρος** και ξανα-ενεργοποιείται με ένα env var — αφού κλείσουν αυτά.
  Το `ekdosi:self-update` αρνείται κάθε queued run (update ή rollback) όσο είναι disarmed.
- **In-app ενημέρωση από GitHub** (Phase 2 / Φάση A) — super_admin action «Εγκατάσταση ενημέρωσης»
  στη «Υγεία συστήματος»: εφαρμόζει νέα έκδοση από το panel (snapshot → maintenance → `git checkout` →
  `composer install` *από το lock, ΠΟΤΕ `composer update`* → `migrate` → `optimize` → shield →
  `queue:restart` → opcache → `ops:health`). **Shared-hosting-first — χωρίς sudo/systemd/root**: τρέχει
  ως ο ίδιος account user, εκτελείται out-of-band από τον cron scheduler (`ekdosi:self-update`), ώστε η
  εφαρμογή να κάνει restart τον εαυτό της με ασφάλεια. `UpdateRun` model + resource «Ενημερώσεις»
  (ζωντανή πρόοδος + στάδιο + έξοδος + ιστορικό)· signed `/internal/opcache-flush`· token-authenticated
  `git fetch` (ένα PAT για check + pull). **Arming flag** `EKDOSI_UPDATE_IN_APP_APPLY` (default **OFF**, βλ. παραπάνω)·
  όταν είναι ON το κουμπί εμφανίζεται εφόσον υπάρχει διαθέσιμη έκδοση (σε private repo προϋποθέτει
  έγκυρο token)· super_admin-only + confirmation.
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
- **Σελίδα «Έλεγχος ετοιμότητας»** (`Preflight`, super_admin-only, «Σύστημα») — το **config-δίδυμο**
  του «Υγεία συστήματος» (liveness): «είμαι νόμιμος;» ανά εταιρεία σε μία οθόνη με κατάσταση
  **Έτοιμο / Προσοχή / Μπλόκο** (worst-of-sections). Ενότητες: **ρυθμίσεις myDATA** (τύποι/ΦΠΑ/§8.3/§8.12
  — ο ΙΔΙΟΣ `MyDataConfigAudit` με το «Έλεγχος ρυθμίσεων» tab + `mydata:preflight`· 0% χωρίς §8.3 = Μπλόκο),
  **πίνακες lookups** (ΦΠΑ/τύποι/πληρωμές/μονάδες/αποστολή/διακίνηση/κατηγορίες — auto-seeded, warn αν λείπουν),
  **κατηγορίες εσόδων προϊόντων §8.6** και **προαιρετικές αντιστοιχίσεις WHMCS** (έσοδα §8.6 + πληρωμές §8.12).
  Read-only, cross-tenant σωστά scoped (κάθε εταιρεία μέσα σε `CompanyContext::actAs`)· link στον «Οδηγό κωδικών»
  για τις §8.3/§8.12 αναφορές. `ReadinessReport` service, cached (30s, «Ανανέωση» busts).
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
**Εικόνα από myDATA — ΦΠΑ**, top πελάτες, **top είδη/υπηρεσίες (έσοδα)**, **έσοδα ανά κατηγορία**,
**top προμηθευτές (έξοδα)**, YoY chart, ληξιπρόθεσμα, πελάτες με υπόλοιπο, επερχόμενες ανανεώσεις.
Leads card: ανοιχτά/ληξιπρόθεσμα/μετατροπές + **αξία pipeline** (μικτή αξία προσφορών ανοιχτών leads).
**«Καθημερινές εργασίες»** — quick-actions panel στην κορυφή: Νέο Παραστατικό/Είσπραξη/Προσφορά/Πελάτης
+ WHMCS Εισερχόμενα (με badge)/Παραστατικά/Κονσόλα myDATA/Ηλικίωση οφειλών· κάθε κουμπί gated στο ίδιο
δικαίωμα με τον προορισμό του (δεν εμφανίζεται ό,τι δεν επιτρέπεται).

## 16β. AI «Βοηθός» (insights + links + write actions με confirm)
In-app chat που απαντά για τα δεδομένα της **τρέχουσας** εταιρείας μέσω εργαλείων.
**Read-only tools** (επεκτάσιμο registry): `count_sales`, `outstanding_receivables`,
`list_top_debtors` (top οφειλέτες + link Καρτέλας), `find_customer` (αναζήτηση ονόματος/ΑΦΜ +
links Καρτέλας/νέου παραστατικού), `recent_invoices` (πρόσφατα + view link), `vat_summary` (ΦΠΑ
εκροών για περίοδο), `recent_activity`, `leads_pulse`, **`income_vs_expense`** (έσοδα vs έξοδα +
υπόλοιπο ΦΠΑ, Βιβλίο Εσόδων-Εξόδων), **`top_products`** (κορυφαία είδη/υπηρεσίες ανά περίοδο),
**`whmcs_inbox`** (εκκρεμή προτιμολόγια WHMCS), **`ai_usage`** (κόστος/tokens/όριο του AI Βοηθού
ανά μήνα για την εταιρεία· MCP `company="all"` → ανά-εταιρεία για super-admin), **`knowledge_search`**
(grounded «βοήθεια & συμβουλή» από curated KB `docs/assistant-kb/` — app how-to + επιβεβαιωμένες
φορολογικές σημειώσεις· ΑΥΣΤΗΡΟ grounding, «ρώτα λογιστή» όταν δεν καλύπτεται). **3 write tools με operator-confirm**: `send_customer_statement` («στείλε
ενημερωτικό/καρτέλα» — επαφή-aware), `create_reminder` («θύμισέ μου / notification») και `record_payment`
(«καταχώρισε είσπραξη» — FIFO σε ανοιχτά τιμολόγια, gate `Create:Payment`, μονοσήμαντος πελάτης). Ο βοηθός
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
**Χρήση & κόστος AI** (Phase 2c-ε): σελίδα «Χρήση & κόστος AI» ΜΕΣΑ στην περιοχή «AI Βοηθός» (group
«Σύστημα», δίπλα στο «Βοηθός AI») — read-only surface πάνω στο `ai_usage_log` («ποιος πληρώνει, ποιος
κοντά στο όριο»): ανά εταιρεία (tokens in/out/cache, χρεώσιμα, όριο, % ορίου, εκτ. κόστος USD) + ανά
χρήστη + μηνιαία τάση + επιλογή μήνα + CSV. **Cross-tenant → μόνο system super_admin** (`AiUsageReport`
με ρητό `withoutGlobalScope`· ΠΟΤΕ Shield-grantable για να μη διαρρεύσει κόστος άλλου tenant).

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
επιβεβαιώνει **μέσα** στο ekdosi (καμία εξωτερική auto-εκτέλεση). **Line-level tools παραστατικών &
εισερχομένων** (read-only, `View:Invoice`/`View:PendingWhmcsInvoice`): `invoice_get` (ένα παραστατικό
με ΓΡΑΜΜΕΣ + εσωτερικές σημειώσεις + myDATA/whmcs ids· lookup by ΤΠΥ/id/whmcs_invoice_id),
`search_invoices` (αναζήτηση σε γραμμές ή/και σημειώσεις ή κατά whmcs_invoice_id → πλήθος + δείγμα με
matched snippet· «πόσα παραστατικά ανανέωσαν το X»), `whmcs_inbox_list` (τα «Εισερχόμενα» αναλυτικά με
γραμμές + **έλεγχος διπλότυπου**: υπάρχον ekdosi παραστατικό ίδιου whmcs id / legacy `whmcs_invoice_log`
hit / ίδιος πελάτης+ποσό, + πρόταση file/archive/check βάσει cut-over `whmcs_invoice_min_date` κατά ημ.
πληρωμής· `status=archived` σημαίνει «mis_archived» — αρχειοθετημένα που μάλλον θέλουν έκδοση). **Νέα ops/debug tools για remote
troubleshooting** (super_admin, read-only): `app_health` (= `ops:health`: queues/crons/backup/mail/
WHMCS/myDATA/disk + severity), `failed_jobs` (failed queue jobs + κεφαλή exception), `log_tail`
(Laravel log με φίλτρα level/substring), `error_log_tail` (το PHP/FPM/web-server ERROR log — fatals/
recursion/worker-deaths που ΔΕΝ φτάνουν στο laravel.log, δηλ. το «Error while loading page» με κενό app
log· primary source το `ini_get('error_log')`, portable σε cPanel/DirectAdmin/Virtualmin/standalone).
**Νέα myDATA/provider forensics** (super_admin, cross-tenant,
read-only — «γιατί έσκασε ΑΥΤΟ το παραστατικό;» απ' έξω, χωρίς panel): `invoice_filing` (ένα
παραστατικό με invcode/id → τοπική×myDATA κατάσταση + όλο το ιστορικό `mydata_marks`: ΜΑΡΚ, ακύρωσης,
πάροχος, auth code, κωδικοί σφάλματος· `include_xml`/`mark_id` για το raw XML), `mydata_failures`
(πρόσφατα `REJECTED`/`*_FAILED` με τους κωδικούς AADE/InvoSign), `stuck_documents` (in-doubt /
οριστικοποιημένα-αδήλωτα / ΔΑ in-doubt), `mydata_discrepancies` (ο αριθμός αποκλίσεων του `app_health`
ως γραμμές: cached count + τοπικό phase-1· `live=true` = πραγματικό `SalesReconciler` AADE pull),
`preflight` (`MyDataConfigAudit` = `mydata:preflight` απ' έξω· `error_count>0` = go-live blocker).
Βάση `ForensicMcpTool`· τα στοιχεία **υπάρχουν ήδη** (byte-exact XML ανά προσπάθεια) — πρόσβαση, όχι
επιπλέον logging (OBS-001· βλ. `docs/BACKLOG.md §MCP forensics`). **Νέα state tools** (και στα δύο κανάλια): `app_version`
(τρέχον build + διαθέσιμη ενημέρωση) και `recent_activity` (audit trail). Τα write tools ΔΕΝ κάνουν
fan-out (`"all"` απαγορεύεται — blast-radius). **Always-on** (χωρίς env flag· η ασφάλεια είναι το auth
+ token). **Self-service κλειδί από το panel:** «Τα κλειδιά MCP μου» (user menu, `View:McpTokens` →
super_admin + company_admin εξ ορισμού· operator μόνο αν του δοθεί ρητά, γιατί το bearer token
παρακάμπτει login + 2FA) κόβει/ανακαλεί το tenant-bound Sanctum token χωρίς CLI — δεμένο στην
τρέχουσα εταιρεία, plaintext **μία φορά**, λίστα + «Τελευταία χρήση» + ανάκληση (μόνο τα δικά σου, μόνο
αυτού του tenant). Ο CLI δρόμος (`ekdosi:mcp-token`) μένει· για τον claude.ai OAuth connector δεν
χρειάζεται token εδώ. Πλήρες: **`MCP.md`**.

## 17. Setup / lookups
VAT categories · invoice types · payment/delivery methods · distribution aims · metric
units · bank accounts · product categories · **tags** — όλα tenant-scoped, με
guarded delete, προ-σπαρμένα από `MyDataLookupSeeder` για άμεση έκδοση.
- **myDATA-readiness στη λίστα κάθε lookup που κουβαλά κωδικό §8.** Τύποι παραστατικών (§8.1, με
  suggestion + «Ετοιμότητα myDATA»), ΦΠΑ (§8.2, flag σε μη-έγκυρο συντελεστή), κατηγορίες προϊόντων
  (§8.6, «κληρονομεί τύπο») και **τρόποι πληρωμής (§8.12)** δείχνουν στη λίστα τον κωδικό ή «λείπει» —
  ο τρόπος πληρωμής ως warn «λείπει → 3» (δηλώνεται «Μετρητά», δεν μπλοκάρει) μόνο σε tenant που φιλάρει
  στην ΑΑΔΕ. Μονάδες μέτρησης / τρόποι αποστολής / σκοποί διακίνησης **δεν έχουν κωδικό myDATA** → καμία
  τέτοια στήλη. Η φόρμα τρόπου πληρωμής έχει hint-icon με τη λεζάντα των 8 κωδικών §8.12.
- **«Οδηγός κωδικών myDATA»** (`MyDataCodeGuide`, `CodeReference`) — read-only γλωσσάρι §8: τι είναι
  κάθε κωδικός (τύποι παραστατικών §8.1 π.χ. 2.1, κατηγορίες εσόδων §8.6, ΦΠΑ §8.2, αιτίες απαλλαγής
  §8.3, είδη δραστηριότητας) + πού χρησιμοποιείται, σε απλά ελληνικά. Οι φόρμες παραπέμπουν με link
  «📖 Οδηγός κωδικών». Ανοιχτό σε κάθε χειριστή (help).
- **Web installer πρώτης εγκατάστασης** (`/install`) — «πέτα» τα αρχεία σε φρέσκο host (άδειο VM ή
  cPanel/DirectAdmin) με μόνο μια κενή βάση + χρήστη· μπαίνεις στη διεύθυνση και ένας οδηγός φτιάχνει
  `.env` (όνομα/URL/περιβάλλον/γλώσσα/ζώνη ώρας + βάση + προαιρετικό SMTP), παράγει `APP_KEY`, ελέγχει
  ζωντανά τη σύνδεση («Δοκιμή σύνδεσης»), τρέχει `migrate` + Shield + τα ελληνικά AADE lookups, και
  δημιουργεί τον πρώτο super-admin + εταιρία — με λίστα επόμενων βημάτων σε επίπεδο διακομιστή (cron,
  queue worker, PHP extensions). **Fail-closed & αυτο-απενεργοποίηση:** middleware `EnsureInstalled`
  (τρέχει πριν το session/APP_KEY stack) δρομολογεί ένα «παρθένο» σύστημα στον οδηγό και μόλις υπάρξει
  `APP_KEY` (ή marker ολοκλήρωσης) κάνει το `/install` μόνιμα ανενεργό (→ `/admin`). **Πύλη με filesystem
  token** (αρχείο στο `storage/app/install/`, επαλήθευση σε κάθε POST) αποδεικνύει πρόσβαση στον διακομιστή,
  και το βήμα `migrate` **αρνείται βάση που έχει ήδη ολοκληρωμένη εγκατάσταση**. Το preflight «Έλεγχος
  συστήματος» **μπλοκάρει** πλέον και σε `proc_open` απενεργοποιημένο ή απόντα πελάτη `mariadb`: μετά το
  v2.0.2 squash το schema χτίζεται φορτώνοντας το baseline με shell-out σε αυτό το binary, οπότε χωρίς τα
  δύο η εγκατάσταση θα έσκαγε στη μέση του `migrate` (και το retry θα απέτυχε ίδια). Ο έλεγχος βάσης
  **σταματά επίσης οριστικά** (χωρίς checkbox παράκαμψης — δεν βοηθά) σε **μισο-χτισμένο schema**: βάση που
  κρατά ήδη πίνακες του baseline ενώ το `migrations` είναι άδειο/απόν — υπόλειμμα διακοπείσας επαναφοράς ή
  `migrate` που πέθανε στη μέση του baseline. Κάθε retry θα ξαναφόρτωνε το baseline και θα έσκαγε σε «Table …
  already exists» επ' άπειρον, οπότε ο οδηγός λέει ευθέως τι πρέπει να γίνει. Τα per-tenant secrets
  (myDATA/WHMCS/GSIS) μένουν εκτός — ρυθμίζονται αργότερα ανά εταιρία.
  - **«Νέα εταιρία» ή «Εισαγωγή από .zip»** — ο οδηγός ρωτά αν θες κενή πρώτη εταιρία ή να **ανεβάσεις ένα
    `company:export` .zip** (ταυτότητα/ρυθμίσεις/σφραγισμένα secrets/setup/χειριστές). Δέχεται
    **κρυπτογραφημένο ή μη** (πεδίο συνθηματικού, υποχρεωτικό μόνο για σφραγισμένο — ο server το επιβάλλει από
    το `secrets.mode` του αρχείου)· το bundle διαβάζεται + επαληθεύεται **πριν** το `migrate` και η
    εγκατάσταση περνά μέσα από το `ekdosi:install --bundle`. Έτσι στήνεται προ-ρυθμισμένο box **χωρίς κονσόλα**
    (κώδικας + .zip + κενή MariaDB → ανέβασμα → τέλος).
  - **«Δοκιμή email»** — το SMTP αντίστοιχο του «Δοκιμή σύνδεσης» της βάσης: ανοίγει SMTP session με τα
    στοιχεία της φόρμας (connect + κρυπτογράφηση + AUTH) και ξεχωρίζει **ποιο** βήμα έσκασε — `auth` (λάθος
    credentials), `tls` (λάθος συνδυασμός «Κρυπτογράφηση»/port: 587+TLS vs 465+SSL), `unreachable`
    (host/port/firewall). Προαιρετικό «στείλε δοκιμαστικό σε [email]» για πραγματική end-to-end αποστολή.
    Token-gated `POST /install/test-mail`, short timeout ώστε λάθος host να μη κρεμάει τον οδηγό.
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

## 18. Πύλη πελατών (customer portal) — foundation
- **Slice 0 (auth shell):** ξεχωριστός **`portal` auth guard** + πίνακας/model `customer_users`
  (global login identity, unique email σε όλες τις εταιρίες· λιτός — auth + account-safety, καμία
  νομική ταυτότητα). `/user/login`·`/user/logout`·`/user`·**προφίλ** `/user/settings` (στοιχεία + αλλαγή
  κωδικού· email/ΑΦΜ όχι επεξεργάσιμα). **UI με Flux UI (Free)** πάνω στο υπάρχον Tailwind v4/Vite. Πλήρης **διαχωρισμός
  guard** από τους operators (portal login ≠ operator· δεν φτάνει ποτέ στο `/admin`), throttled login,
  no-enumeration errors, status gating (invited/active/suspended — μόνο active+κωδικός συνδέεται).
  `php artisan portal:create-user` για χειροκίνητη δημιουργία (registration/invite/backfill = επόμενα).
- **Operator-side «Χρήστες πύλης»** (Filament, group «Πύλη πελατών», **super-admin only**):
  create/activate/suspend/ορισμός-κωδικού των logins. Forward-looking στήλες (2FA à la Fortify,
  username/locale/phone) dormant.
- **Access grants (Slice 1):** `customer_user_access` = «ποιο login βλέπει ποιον πελάτη, σε ποια εταιρία»
  (`customer_user × company × customer × role`), με audit (granted_by/granted_at) + soft revoke. Λύνει το
  «ίδιο email σε πολλές εταιρίες». **Operator management** στο «Χρήστες πύλης» (RelationManager «Πρόσβαση σε
  πελάτες»: προσθήκη/ανάκληση/επαναφορά ανά πελάτη/ΑΦΜ). Δεν το διαβάζει ακόμα customer-facing οθόνη.
- **Routing:** καθαρός διαχωρισμός **`/admin` (operators) ⟂ `/user` (πελάτες)**· το `/` είναι σκόπιμα κενό
  placeholder (δεν αποκαλύπτει καμία από τις δύο επιφάνειες).
- **Λίστα παραστατικών (Slice 2):** στο `/user` ο πελάτης βλέπει τα εκδοθέντα παραστατικά του,
  ομαδοποιημένα ανά (εταιρία, πελάτη/ΑΦΜ), με ημ/νία·κωδικό·τύπο·κατάσταση·ΜΑΡΚ + **PDF** (proxied/streamed)
  και **Επαλήθευση** (provider/ΑΑΔΕ URL). Υπηρεσία **`CustomerDocumentFeed`** = η μοναδική πηγή «ποια live
  παραστατικά ανήκουν σε (εταιρία, πελάτη)» + «μπορεί το login να δει αυτό» — αυστηρά μέσω **ενεργού grant**
  (ποτέ ΑΦΜ/εταιρία σκέτα), μόνο **live** (ίδιο allow-list με `isPubliclyViewable()`). Route
  `/user/document/{invoice}/pdf` **fail-closed** (404 χωρίς αποκάλυψη ύπαρξης, ξανα-ελέγχει το boundary ανά
  request)· το middleware ξανα-ελέγχει `canLogin()` κάθε request (suspend μετά το login → logout αμέσως).
- **Reset password + invited «claim»:** `/user/forgot-password` + `/user/reset-password/{token}` (απομονωμένος
  `customer_users` broker). Η ίδια ροή ορίζει τον πρώτο κωδικό ενός operator-invited login (invited→active) —
  **operator-gated onboarding, χωρίς open registration**. Anti-abuse: generic response (no enumeration),
  honeypot, throttle ανά email + IP, queued mail, κανένα email σε suspended.
- **Session invalidation σε αλλαγή κωδικού:** κάθε session δένεται με το password hash του login· μια αλλαγή
  κωδικού οπουδήποτε (reset/operator/profile) αποσυνδέει κάθε άλλη session στο επόμενο request, ενώ η session
  που έκανε την αλλαγή επιβιώνει (`EnsurePortalAuthenticated`, explicit για τον `portal` guard).
- **«Η καρτέλα μου» (Slice 3):** read-only υπόλοιπο + χρονολογική καρτέλα (χρέωση/πίστωση/τρέχον υπόλοιπο)
  ανά (εταιρία, πελάτη) στο `/user/statement`. `CustomerLedgerFeed` πάνω στο **ίδιο** `CustomerLedgerBuilder`
  με τον operator (ποτέ ξαναϋπολογισμός → ίδιοι αριθμοί) + ίδιο grant boundary. Αρνητικό υπόλοιπο = «πιστωτικό
  υπόλοιπο» (seat για prepaid credit). Το «πλήρωσε» έρχεται με τον gateway πυλώνα.
- **Επόμενα slices:** self-register (**tier-2 claim** — ΑΦΜ+email match → email verify → grant πάντα από
  operator· ποτέ open signup) + auto-provision reseller-grants από τη δρομολόγηση «Παραστατικά σε τρίτους» +
  κοινό `CustomerDocumentFeed` και στο WHMCS «Εκδοθέντα».

## 19. Τρόποι online πληρωμής (Πυλώνας B) — foundation
- **B0a — modular seam + admin (SHIPPED):** `PaymentGateway` contract + `PaymentGatewayRegistry`
  (config-driven, Null fallback). Νέο gateway = μία class + μία γραμμή στο `config/ekdosi.php →
  payments.gateways` — μηδέν core edit/migration (Eurobank φέτος, Viva του χρόνου). Per-tenant
  `payment_gateway_connections` (gateway/label/is_active/sort/**encrypted** config) = WHMCS-style λίστα·
  Filament «Τρόποι online πληρωμής» (Ρυθμίσεις, **super-admin**): add/enable(inline)/name/order/settings-ανά-
  gateway + «Έλεγχος». Πρώτο: **manual** (κατάθεση, offline). Design+threat-model: `docs/payment-gateways-design.md`.
- **B0b — η ροή «Πλήρωσε» (SHIPPED):** στο `/user/statement` ο πελάτης πληρώνει (ποσό + τρόπος) → `payment_intents`
  (pending) → οδηγίες. Το **manual «Τραπεζική κατάθεση»** αναδεικνύει τους υπάρχοντες `bank_accounts` (toggle
  ποιοι φαίνονται· κενό = όλοι). Operator «Εκκρεμείς πληρωμές πύλης» → «Καταχώριση πληρωμής» → `Payment` μέσω
  `PaymentAllocator` (FIFO + credit) → `InvoiceBalance`. **Idempotent** settle· ο browser δεν εξοφλεί ποτέ.
- **B1 — Eurobank / Cardlink vPOS (SHIPPED):** το πρώτο hosted gateway (`flow=redirect`) και ο **βασικός**
  πάροχος του πελάτη — κάρτα + Apple/Google Pay + IRIS σε μία σελίδα. «Πλήρωσε» → auto-submit υπογεγραμμένης
  φόρμας στη σελίδα της τράπεζας → η επιστροφή (vPOS **digest** πάνω στο raw body) επαληθεύεται → idempotent
  `settle()` ΜΟΝΟ σε `CAPTURED` + ταίριασμα ποσού/νομίσματος/εταιρίας (T1/T3/T6). Per-tenant: Merchant ID +
  **write-only** Shared Secret (κρυπτ.) + γλώσσα + sandbox. Νέες opt-in διεπαφές `HostedRedirectGateway`/
  `WebhookGateway`/`HasSecretConfig` (το manual δεν υλοποιεί καμία). *Περιορισμός:* το module δεν έχει
  server-to-server webhook — η εξόφληση περνά από το browser-return· δίχτυ = operator manual-settle + reconcile.
- **Invoice-targeted payment + πλήρες trail (SHIPPED):** στη «Πλήρωσε» ο πελάτης διαλέγει «Όλο το υπόλοιπο» ή
  ΣΥΓΚΕΚΡΙΜΕΝΟ παραστατικό (`payment_intents.invoice_id`)· το settle το εφαρμόζει σε ΕΚΕΙΝΟ (capped, υπερβάλλον →
  έναντι λογαριασμού· fallback FIFO αν έγινε μη-πληρωτέο). Κάθε Payment δείχνει πίσω στο intent (`payment_intent_id`,
  hard FK) — «Προέλευση» clickable στην πληρωμή, στήλη «Πληρωμές»→Καρτέλα στα intents, στήλη **ID** (= vPOS orderid)
  για ταίριασμα με την ειδοποίηση της τράπεζας.
- **Auto-λήξη εκκρεμών intents (SHIPPED):** `payments:expire-stale-intents` λήγει εγκαταλελειμμένα *online* intents
  (offline = worklist, ποτέ)· late verified capture settle-άρει ακόμη και expired (money truth).
- **«Log πύλης» (SHIPPED):** read-only audit (super-admin) κάθε vPOS return — έκβαση/λόγος/υπογραφή/status/txn/IP/ID.
  Το εργαλείο για «πλήρωσα, δεν φαίνεται». Best-effort, runtime (εκτός export).
- **Κανάλι + auto myDATA «Τρόπος» (SHIPPED):** στήλη «Κανάλι» (Πύλη·gateway vs Χειροκίνητα) στις Πληρωμές· per-connection
  «Τρόπος πληρωμής (myDATA)» που το settle stamp-άρει αυτόματα (Eurobank → «Ηλεκτρονικά μέσα»).
- **Απόδειξη είσπραξης PDF + στοιχεία στο παραστατικό + ειδοποίηση settle (SHIPPED):** row action «Απόδειξη» (άτυπο
  αποδεικτικό είσπραξης, ανά reference-group)· στήλη «Κανάλι» στο tab «Πληρωμές» του τιμολογίου· καμπανάκι χειριστή
  σε αυτόματη είσπραξη πύλης.
- **Επόμενα:** B2 PayPal/Stripe (2ος redirect adapter — research notes:
  `docs/payment-gateways-b2-paypal-stripe.md`) → B3 office rails (card-POS + ΑΑΔΕ) → B4 reconcile/
  prepaid/refund.

## 20. Σύστημα υποστήριξης / Tickets (Πυλώνας E) — foundation
- **Per-tenant kill-switch (SHIPPED):** όλος ο πυλώνας πίσω από `companies.support_enabled`
  (**default OFF, τελείως κρυμμένο** — μενού/ρυθμίσεις/portal), toggle στη φόρμα Εταιρείας (super-admin).
  Design: `docs/ticket-system-design.md`· build-our-own thin domain (multi-tenant native), δανεικό μόνο
  το mail layer (Phase 3).
- **Domain (Phase 1a, SHIPPED):** `tickets` / `ticket_messages` (public reply **ή** εσωτερική σημείωση) /
  `ticket_departments` (+ IMAP config encrypted, Phase 3) / `canned_replies`. State machine «ποιος έγραψε →
  κατάσταση» σε ΕΝΑ choke-point (`PostTicketMessage`/`OpenTicket`, enum `TicketStatus`). Reference
  `TK-YYYY-MM-DD-xxxxxx` (ημ/νία + αμάντευτη ουρά = email token). Reuse `HasAttachments`/`HasTags`/`TracksActivity`.
- **Χειριστικό UI (Phase 1b, SHIPPED):** cluster **«Υποστήριξη»** (στα «Καθημερινά», gated) — λίστα με tabs
  («Στην ουρά»/«Χωρίς ανάθεση»/«Ανοιχτά»/«Όλα») + badges, «Νέο αίτημα», σελίδα προβολής με **thread**
  (εσωτερικές σημειώσεις ξεχωριστά, «δεν το βλέπει ο πελάτης») + ενέργειες Απάντηση/Σημείωση/Ανάθεση/
  Αναμονή/Κλείσιμο. Ρυθμίσεις **Τμημάτων** στο Settings Cluster → «Υποστήριξη».
- **Polish (SHIPPED):** **έτοιμες απαντήσεις** (`CannedReplyResource` σε κατηγορίες + `{{token}}` expander)
  με picker στη «Απάντηση»· **context panel «Πελάτης»** μέσα στο ticket — ΑΦΜ/email + **υπόλοιπο**
  (canonical `withOutstandingBalance`) + πρόσφατα ζωντανά παραστατικά + «Άνοιγμα Καρτέλας» (native
  πλεονέκτημα έναντι WHMCS, read-only).
- **Πύλη πελάτη «Τα αιτήματά μου» (Phase 2, SHIPPED):** ο πελάτης στο `/user` ανοίγει/βλέπει/απαντά τα
  αιτήματά του. **Grant-scoped & fail-closed** (`grantedTargets`, ρητό company/customer, 404 σε άγνωστο id)·
  **μόνο δημόσια μηνύματα** (εσωτερική σημείωση δεν διαρρέει)· γράψιμο μέσω `OpenTicket`/`PostTicketMessage`.
- **Inbound email → ticket (Phase 3a, SHIPPED — core):** `InboundTicketRouter` δρομολογεί parsed email σε
  ticket (αντιστοίχιση πελάτη + `clients_only`, threading με References/`[TK-…]` token, καθάρισμα σώματος με
  `email-reply-parser`). Transport-agnostic (`ParsedInboundEmail`) — ο IMAP poller + outbound threading =
  Phase 3b.
- **IMAP poller + observability (Phase 3b-i, SHIPPED):** `tickets:poll-imap` (`webklex/php-imap` πίσω από
  `ImapMailbox` seam) → `InboundTicketRouter`, per-department isolation, mark-seen-after-route. **«Test
  σύνδεσης»** στο τμήμα, `ticket_poll_runs` health log, structured logging, **MCP `support_imap`** (live
  connect-test). Scheduler `tickets_poll_imap` (**default OFF**).
- **Outbound email threading (Phase 3b-ii, SHIPPED):** η απάντηση χειριστή → threaded email στον πελάτη
  (`SendTicketReplyEmail`/`TicketReplyMail`, από το mailbox του τμήματος, με Message-ID/In-Reply-To +
  `[TK-…]` token)· κρατάμε το Message-ID ώστε η απάντηση του πελάτη να κάνει thread πίσω. **Ο πλήρης
  κύκλος email→ticket→email είναι live.**
- **Operator bell + watchers/CC (Phase 4, SHIPPED):** κάθε **δημόσιο μήνυμα πελάτη** (νέο ή reply) χτυπά
  **καμπανάκι** (Filament database notification) στους operators του ticket — recipients = agents τμήματος
  (fallback: όλοι οι χρήστες tenant) ∪ assignee ∪ **watchers**· post-commit, best-effort (`TicketMessageObserver`
  → `TicketNotifier`). **Watchers/CC:** operators κάνουν watch/unwatch (auto-watch όποιος απαντά = participant),
  εξωτερικά **emails** κοινοποιούνται (**κρυφό Bcc**) στις απαντήσεις· λίστα watchers στο ticket, «Προσθήκη watcher» (χειριστής
  ή email). _(SLA σκόπιμα εκτός.)_
- **Αξιολόγηση εξυπηρέτησης / feedback-on-close (Phase 4, SHIPPED):** όταν ένα αίτημα κλείσει σε τμήμα με
  `feedback_on_close`, ο πελάτης αξιολογεί **1–5** (+ σχόλιο) από την πύλη· fail-closed (closed + feedback τμήμα
  + κάτοχος grant)· ο χειριστής βλέπει ★ n/5 + σχόλιο στο ticket. Επανα-υποβάλλεται όσο μένει κλειστό.
- **Αποκλεισμός αποστολέα / spam (Phase 4, SHIPPED):** per-tenant blocklist (`ticket_blocked_senders`)· ο
  inbound router ρίχνει email από αποκλεισμένη διεύθυνση **ή domain** πριν ανοίξει ticket. Resource
  «Αποκλεισμένοι αποστολείς» (Ρυθμίσεις → Υποστήριξη) + ένα κλικ «Αποκλεισμός αποστολέα» στο ticket.
- **Inbound-CC → watchers/CC (Phase 4, SHIPPED):** τα `To`/`Cc` ενός εισερχόμενου email **γνωστού πελάτη**
  γίνονται email watchers (`source=cc`), ώστε οι απαντήσεις να κοινοποιούν και τους «άσχετους» παραλήπτες
  (developer/agency…)· μόνο known-customer (όχι open-relay), εξαίρεση αποστολέα/τμήματος/owner/blocked, idempotent.
- **Συγχώνευση αιτημάτων / merge (Phase 4, SHIPPED):** διπλότυπο → ενσωμάτωση σε επιβιωμένο (μηνύματα/
  watchers/tags/attachments μεταφέρονται, source κλείνει με `merged_into_id`, terminal). **Μόνο same-owner**
  (ίδιος customer ή guest email) — αλλιώς leak· action «Συγχώνευση» στο ticket· η πύλη κρύβει το source +
  redirect στο survivor.
- **Email-invite αξιολόγησης στο κλείσιμο (Phase 4 follow-up, SHIPPED):** στο «Κλείσιμο» ενός αιτήματος
  τμήματος με `feedback_on_close`, ο πελάτης παίρνει email με **signed link** σε δημόσια σελίδα αξιολόγησης
  (χωρίς login) — και όποιος δεν ξαναμπαίνει στην πύλη αξιολογεί.
- **Reply-threading για watcher/CC (Phase 4 follow-up, SHIPPED):** απάντηση από watcher/CC ενός ticket κάνει
  thread εκεί (όχι νέο ticket)· ο anti-injection guard μένει (μόνο πραγματικοί watchers, όχι όποιος έχει το token).
- **HTML-body strip + visible-CC (Phase 4 follow-ups, SHIPPED):** HTML-only inbound → καθαρό κείμενο
  (`HtmlToText`)· cc-sourced watchers σε ορατό **Cc** (manual μένουν Bcc).
- **Συνημμένα αρχεία — portal + operator (Phase 4 follow-up, PR A, SHIPPED):** ο πελάτης ανεβάζει αρχεία στο
  άνοιγμα/απάντηση από την πύλη, ο χειριστής στην απάντηση/σημείωση από το panel· links λήψης στο νήμα και
  στις δύο πλευρές. **Security-first:** ιδιωτικός δίσκος, **μόνο λήψη** (`Content-Disposition: attachment`,
  ποτέ inline), allowlist τύπων (όχι scripts/HTML/SVG/executables), τυχαίο όνομα στον δίσκο, escaped filename,
  tenant/grant-scoped download (ο πελάτης μόνο σε δικό του ticket, ο χειριστής μόνο εντός εταιρείας), και
  συνημμένο **εσωτερικής σημείωσης δεν φτάνει ποτέ στην πύλη** (`publicOnly`). `App\Support\TicketAttachments`.
- **Συνημμένα αρχεία μέσω email — inbound + outbound (Phase 4 follow-up, PR B, SHIPPED):** ο IMAP poller εξάγει
  τα πραγματικά (μη-inline) attachments εισερχόμενου email → στο μήνυμα του ticket· τα συνημμένα απάντησης χειριστή
  επισυνάπτονται στο outbound threaded email. **Untrusted sender:** extension allowlist (όχι scripts/HTML/SVG/exe),
  per-file (20MB) + count (5) + **per-email total (25MB)** caps, ΔΕΝ εμπιστευόμαστε το Content-Type, ποτέ
  decompress (zip-bomb αδρανές), inline parts αγνοούνται· download-only όπως στο PR A. Outbound = all-or-nothing
  στο budget (αλλιώς reply χωρίς αρχεία + log). `TicketAttachments::storeInbound()`/`outboundPayload()`.
- **Επόμενα:** maybe: στήλη/φίλτρο αξιολόγησης, per-department validate_cert, structured sender identity,
  AV-scanning συνημμένων (ClamAV) αν χρειαστεί.
  _(In-app KB DROPPED — το BookStack το καλύπτει· Announcements = maybe-later.)_

---

## 21. Domains (Πυλώνας A) — foundation
- **Per-tenant kill-switch (A0, SHIPPED):** όλος ο πυλώνας πίσω από `companies.enable_domain_management`
  (**default OFF, τελείως κρυμμένο** — cluster «Domains» στα «Καθημερινά»), toggle στη φόρμα Εταιρείας
  (super-admin, Tab «Domains»). Design: `docs/domains/README.md` (+ grEPP υλικό `docs/domains/grepp/`).
- **Registrar seam (A0, SHIPPED):** contract `DomainRegistrar` + config-driven `DomainRegistrarRegistry`
  (`ekdosi.domains.registrars`) + capabilities/credentials value objects + `NullDomainRegistrar` = ο
  first-class **«Manual (χωρίς API)»** registrar (ρίχνει typed exception σε API ενέργειες — ποτέ ψεύτικη
  επιτυχία). Νέος registrar = μία κλάση + μία γραμμή config (Openprovider A2, grEPP A4).
- **Συνδέσεις registrar (A0, SHIPPED):** `domain_registrar_connections` per (tenant × λογαριασμός) —
  label/mode (fail-safe: μόνο ρητό `production` = live)/active, creds **encrypted at rest**,
  resource **super_admin-only** μέσα στο cluster, με «Έλεγχος σύνδεσης».
- **Data model + κατάλογος TLD (A1a, SHIPPED):** `domain_tlds` (κανόνες + routing ανά TLD) ·
  `domain_tld_prices` (ρητή τιμή ανά ενέργεια×έτος×νόμισμα, enable/disable ανά term) · `domains`
  (nullable customer = **αδέσποτο**, εκτός billing μέχρι ανάθεση· registrar-truth `expires_at` ×
  SC billing clock) · `domain_nameservers` · `domain_contacts` (registrant/admin/tech/billing, ένα
  ανά τύπο — και το χειροκίνητο assign aid). Resource «TLDs & τιμές» (operator Create/Update,
  GuardedDelete), enum `DomainStatus`.
- **Portfolio CRUD + ανάθεση + καρτέλα πελάτη (A1b, SHIPPED):** resource «Domains» (tabs
  Ενεργά/Λήγουν σύντομα/**Χωρίς πελάτη** + nav badge)· View με inline Επαφές + NS +
  notes/attachments/activity· **«Ανάθεση σε πελάτη»** = guarded action που δημιουργεί το 1:1
  `ServiceContract` (τιμή από TLD renewal ή override, κύκλος από έτη, next_due = λήξη registrar)·
  **«Μεταφορά ιδιοκτησίας»** (SC ακολουθεί, παραστατικά μένουν)· tab «Domains» στην καρτέλα
  πελάτη (flag-gated)· ο registrant φαίνεται δίπλα στα αδέσποτα ως assign aid.
- **Openprovider adapter READ-ONLY + creds (A2a, SHIPPED):** `OpenproviderRegistrar` (bearer auth
  cached, single 401 re-login, fail-safe sandbox/production routing) με ping + availability·
  **κανένα mutating endpoint μέχρι το A3** (test-enforced) → production creds ακίνδυνα· credential
  fields στη σύνδεση από config `registrar_fields`, secrets write-only· mock-HTTP tests.
- **Renewal billing πειθαρχία (SHIPPED):** προσχέδια = «ΠΡΟΣΧ» (χωρίς ΑΑ — gapless-at-send,
  αόρατα στην πύλη, μηδενικό ίχνος σε ακύρωση)· `auto_renew` off = «αφήνεται να λήξει» (σιωπηλά,
  επιλογή β)· ανάθεση ανάβει auto_renew· **«Προσχέδιο ανανέωσης τώρα»** on-demand (Υπηρεσίες +
  Domains) για early renewals· νεκρά domains αχρέωτα από ΚΑΘΕ μονοπάτι (sweep/on-demand/transfer).
- **Registrar sync + availability (A2b, SHIPPED):** nightly `domains:sync` (gated, default OFF) —
  λήξη/NS/registrar-id/confident status από τον registrar, `sync_error` ανά row· «Συγχρονισμός»
  στο View + «Έλεγχος διαθεσιμότητας» στη λίστα (routing μέσω TLD)· stray-request-proof tests.
- **Pricing cost-sync (A2c-1, SHIPPED):** `domains:sync-pricing [--tenant] [--tld]` (manual-run) —
  `getTldPricing()` ανά TLD με `supportsPricingSync` → γράφει **ΜΟΝΟ** το `cost` στη γραμμή του
  ελάχιστου term (π.χ. .gr → years=2)· γραμμές που λείπουν γεννιούνται **ανενεργές + χωρίς τιμή
  πώλησης** (αχρέωτες εκ κατασκευής)· τιμή πώλησης/enable = πάντα χέρι operator (τα περιθώρια
  είναι manual per-TLD, §7.1).
- **Registrar-first import (A2c-2, SHIPPED):** `domains:import-registrar` (paginated λίστα +
  contacts από Openprovider) και `domains:import-csv` (grweb export για .gr — auto-detect
  delimiter/στηλών, ελληνικές ημερομηνίες) → νέα domains **αδέσποτα** με γεμάτες επαφές (assign
  aid στο «Χωρίς πελάτη»)· υπάρχοντα rows μόνο registrar truth (ίδιο apply με το sync)· ποτέ
  customer_id/auto_renew· tombstones/διαγραμμένα TLD δεν ανασταίνονται· re-runnable.
- **Sealed export συνδέσεων registrar (A2c-3, SHIPPED):** τα `domain_registrar_connections`
  ταξιδεύουν στο per-company bundle όπως τα payment gateways — μεταδεδομένα καθαρά, creds
  **passphrase-sealed** (ποτέ raw APP_KEY ciphertext), re-encrypt στο target APP_KEY, idempotent
  ανά (registrar, label), tombstones δεν ταξιδεύουν. Devbox → production χωρίς re-typing.
- **Registrar renew + adopt guard (A3a, SHIPPED):** το πρώτο WRITE. `DomainRenewalService`
  (μοναδικός δρόμος): sync-first πριν από κάθε renew, ήδη-καλυμμένη περίοδος → **adopt** (καμία
  κλήση — το WHMCS double-renew war story λυμένο by construction)· on-issue hook (§6.1 «renew =
  on-issue», best-effort, περίοδος από το SC cursor)· κουμπί «Ανανέωση στον registrar» στο View
  (confirm modal, δεν αγγίζει billing cursor)· **`domain_registrar_logs`** API history (κάθε
  write ok/adopted/failed)· αποτυχίες → καμπανάκι operators.
- **Registrar register (A3b, SHIPPED):** «Καταχώρηση στον registrar» σε pending domains —
  operator-gated (post-payment πρακτική), availability-first, **adopt-on-retry** (κατειλημμένο
  αλλά δικό μας = υιοθέτηση, όχι δεύτερη χρέωση), guards (registrant contact, ≥2 NS, lock),
  handles reusable (ensure → persist), autorenew πάντα off στον registrar, όλα στο API history.
- **Μεταφορές + κωδικός EPP (A3c, SHIPPED):** «Μεταφορά στον registrar» σε pending-transfer
  domains — async §6.3 (nightly sync ολοκληρώνει ή ⚠-φλαγκάρει το FAI), adopt-on-retry, ο auth
  code δεν λογκάρεται ποτέ· «Κωδικός EPP» (transfer-out aid) με audit ΧΩΡΙΣ τον κωδικό.
- **Management writes + restore (A3d, SHIPPED):** ActionGroup «Registrar» στο View — αποστολή
  nameservers (full replacement), κλείδωμα/ξεκλείδωμα μεταφοράς, WHOIS privacy, αποστολή επαφών
  (ensure handles) — όλα μέσω `DomainManagementService` πάνω στο κοινό write-skeleton
  (`GuardsRegistrarWrites`, το ίδιο που τρέχουν πλέον renewal/registration/transfer)· mirrors
  μόνο μετά την αποδοχή του registrar· **«Επαναφορά από redemption»** sync-first (ήδη-ζωντανό =
  adopt, καμία χρέωση), χρέωση πελάτη χειροκίνητη v1· DNSSEC keys εκτός v1.
- **Επόμενα:** WHMCS linkage hint (προαιρετικό, §9) · margin engine (αν χρειαστεί) ·
  A4 grEPP (+ Recall 5 ημερών .gr) · A5 reconciliation · DNSSEC key management ·
  export/import των υπόλοιπων domain tables (βλ. `docs/BACKLOG.md`).

## Καταργήθηκαν σκόπιμα (δεν τα ξανακάνουμε)
CS-Cart bridge · ΕΑΦΔΣΣ (`EAFDSS_SCRIPT`) · FastReport `.fr3` (→ Blade PDF) ·
`FMysqlSync` MySQL mirror (→ WHMCS API) · `GET_COMB_*` (cross-DB με inline SYSDBA —
security landmine) · `afm2name` (→ native GSIS). **stock/αποθήκη + ΣΔΕΠ/σωρευτικά**
ήταν **νεκρός κώδικας** στο legacy — δεν «λείπουν», απλώς δεν υπήρχαν.
