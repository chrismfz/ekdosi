# Backlog / Roadmap — what's left + ideas (SINGLE SOURCE)

Ό,τι **συνειδητά δεν έχει χτιστεί ακόμα** (roadmap) + αποφάσεις που δεν ξανανοίγουμε (guardrails) + docs index.
**Κανόνας:** shipped → `FEATURES.md` + `CHANGELOG.md`, **σβήσ' το από εδώ**. Μικρά θέματα που ίσως δαγκώσουν δεν
καταγράφονται — θα βρεθούν τότε (logs / repro / MCP).
Pruned 2026-09-22 (2280→212 lines): done/minor items removed. Σχόλια κώδικα/docs που λένε «βλ. `docs/BACKLOG.md`»
για P2/tradeoff αναφέρονται στην **πριν-το-prune έκδοση**: `git show 631078d:docs/BACKLOG.md`.

---

## 🎯 Priorities

Ο κανόνας της σειράς: **η προτεραιότητα ενός finding δεν είναι ιδιότητά του — είναι finding × αυτή η επιχείρηση ×
αυτή η ημερομηνία.** Λεπτομέρειες ανά item στο «🗺️ Roadmap».

1. **Delivery notes (ΔΑ)** — «ίδια μέσα» + TARIC ✅ (v2.5.0). Μένουν: **συμπλήρωση TARIC στα είδη αγαθών πριν την
   1/1/2027** (δεδομένα, όχι κώδικας) · μερική παράδοση (deliveredPackaging) · πλήρη **9.1 / 9.2** (βλ. Roadmap).
2. **Migration / money tooling:** Bank-statement import → CSV εξόδων (αν χρειαστεί) → Cashflow /
   recurring-expenses (accountant-gated).
3. **Strategic epic «Αντικατάσταση WHMCS» → `PLAN.md`:** Domains (A4/A5) → Payment connectors → Provisioning →
   Portal transactional surfaces (largely greenfield).
4. **Parked / blocked-on-external:** PEPPOL Phase 2 (review 2027) · PROV-003 archive (InvoSign endpoint) · POS-1(c) ·
   «Εισερχόμενα από Πύλη» (ερώτημα λογιστή) · 2ος GR πάροχος.
5. **Ideas / low-commitment:** κεντρικός editor κειμένων (owner-requested) · setup profiles ανά κλάδο ·
   multi-currency · shared Contacts CRM · Bridges Phase 1 · AI Phase 2c.

---

## 🗺️ Roadmap

### 💳 Payments / money
- **POS-1(c) — πραγματική POS διασύνδεση (ν.5073/2023).** Σύλληψη `ProvidersSignature` + `tid` + `transactionId` από το
  Cardlink/Eurobank vPOS return → πέρασμα σε `InvoSignDocument`/`AadeInvoiceDocument` ώστε να φιλάρει το type-7.
  Μέχρι τότε card/vPOS/PayPal → §8.12 **1**, 3, 6 ή 8.
- **IRIS (έρευνα 2026-09-25).** Υποχρέωση αποδοχής για ΝΠ/ΟΕ **μόνο B2C** από **31/10/2025** (ν.5193/2025 άρθ.219 →
  άρθ.65 παρ.5 ν.4446/2016· τεχνικές ΚΥΑ Α.1147/1159/1160/1161/1162/2025 από 1/12/2025). Καλύπτει και e-shops· **όχι**
  B2B τιμολόγια. **Online ήδη καλυμμένο:** η σελίδα Eurobank/Cardlink του vPOS προσφέρει IRIS. **Επί τόπου** (π.χ. B2C
  στην εγκατάσταση) = QR IRIS της τράπεζας — χωρίς τερματικό, χωρίς κώδικα. Η διασύνδεση POS (Ε.2044/2024) εξαιρεί
  e-commerce → η πληρωμή της πύλης ΔΕΝ θέλει `ProvidersSignature`.
  **Λείπει (P2):** το settle να γράφει §8.12 κωδ. **8** (IRIS) όταν η Cardlink επιστρέφει πληρωμή IRIS — ⚠ επιβεβαίωσε ότι
  το return της Cardlink δηλώνει τη μέθοδο. **P3:** άμεσο IRIS request-to-pay / QR στο PDF → `payment-connectors.md`.
  Πηγές: https://www.lsa.gr/portal/draseis/anakoinoseis/10913-31-10-2025-iris-b2c ·
  https://www.taxheaven.gr/law/5193/2025 · https://www.taxheaven.gr/news/72117/iris-apo-112-stis-lianikes-synallages-b2c-nees-texnikes-prodiagrafes-diasyndeshs ·
  https://www.taxheaven.gr/news/67829/diasyndesh-fhm-pos-nees-dieykriniseis-kai-erwtapanthseis
- **Payment connectors — card-POS** → `payment-connectors.md` (POS-1(c) parked).
- **Bank-statement import → match πληρωμών** — ανέβασμα κίνησης (CSV/MT940) → auto-match σε ανοιχτά τιμολόγια
  (ποσό/ημερομηνία/ΑΦΜ) → προτεινόμενες `Payment` εγγραφές προς έγκριση. **DEFERRED (owner, 2026-09-23)** — θέλει
  πρώτα δείγμα export από την τράπεζα (format/στήλες) για να σχεδιαστεί ο parser.
- **Dunning — επόμενα βήματα** (οι υπενθυμίσεις + insights + «Υπενθύμιση τώρα» είναι χτισμένα → `FEATURES.md §8`):
  ενέργεια→task με ημ/νία+υπεύθυνο + ιστορικό επαφών στην Καρτέλα· κλιμάκωση με `send_customer_statement`.
- **Ταμειακή εικόνα / cashflow — «τα έξοδα που δεν έρχονται μόνα τους»** _(ιδέα 2026-07-12· **θα το δει με τον
  λογιστή πρώτα**)._ Διοικητική/ταμειακή εικόνα, **ΟΧΙ τα βιβλία του λογιστή**. Κουβάδες εξόδων: **Α.** myDATA GR
  (λυμένο, `ExpenseImporter`) · **Β.** foreign B2B (AWS/Hetzner/cPanel…, ορατά μόνο αν αυτο-δηλώνονται 14.x) ·
  **Γ.** μη-τιμολόγια (μισθοδοσία/ΕΦΚΑ/δάνεια/δώρα — ποτέ στο myDATA). Τα Β+Γ είναι κυρίως recurring. Φάσεις:
  (1) μητρώο «Πάγια / Επαναλαμβανόμενα έξοδα» → ο scheduler φτιάχνει πρόχειρο `Expense` ανά περίοδο, ο operator
  επιβεβαιώνει· (2) ημερολόγιο εποχικών (δώρα/επίδομα αδείας/ετήσιες ασφάλειες)· (3) widget «Τι μου περισσεύει»
  (Έσοδα − Έξοδα = καθαρή ροή + σωρευτικό αποθεματικό). **⚠ Κίνδυνος διπλομέτρησης:** πρότυπο «ΔΕΗ» + myDATA
  τιμολόγιο ΔΕΗ = 2× → κανόνας «το πρότυπο μετράει μόνο αν ΔΕΝ βρεθεί myDATA παραστατικό τον μήνα». Ερώτημα
  λογιστή: ποια foreign δηλώνονται ήδη. Λείπουν μόνο recurring-templates + cashflow widget + anti-double-count.
- **«Φορολογικά»: ποιες κατηγορίες παρακράτησης συμψηφίζονται με τον φόρο εισοδήματος** _(ερώτημα λογιστή)._ Η
  `IncomeTaxEstimate` αφαιρεί ΟΛΟ το `invoices.withhold_amount`, ανεξαρτήτως `withhold_category` (§8.4, 18 κατηγορίες)·
  αν κάποια δεν είναι προκαταβολή φόρου εισοδήματος, το υπόλοιπο βγαίνει μικρότερο. Φιλτράρισμα ανά κατηγορία όταν
  απαντήσει ο λογιστής (σήμερα οι παρακρατήσεις μας είναι 0 → χωρίς πρακτική επίπτωση).

### 🚚 Delivery notes (Ψηφιακό ΔΑ)
- **«Ίδια μέσα» — υπόλοιπα (τα βασικά ✅ v2.5.0, `FEATURES.md §5`).** (1) **Μερική παράδοση** — ConfirmDeliveryOutcome
  PARTIAL θέλει [814] `deliveredPackaging` (τύπος+ποσότητα συσκευασίας) → μοντέλο συσκευασιών ανά γραμμή. (2) `delivery:refresh-status`
  χωρίς HealthRecorder streak (ops:health δεν βλέπει μόνιμη αποτυχία) και χωρίς bell όταν ο scheduler βρει ΤΔΑ ακυρωμένο στην
  ΑΑΔΕ (το ακυρώνει τοπικά σιωπηλά). (3) Group QR (`GenerateGroupQRCode`) — μόνο για πολλά ΔΑ/δρομολόγιο.
  Πηγές/νομικό πλαίσιο: `docs/delivery-two-party-sandbox.md` §UPDATE 2026-09-25 · Α.1094/2026 · Α.1122/2024 · Ε.2030/2025.
- **Κατάλογος ΣΟ (`cn_codes`) — δύο σχεδιαστικά κενά (review 2026-09-25).** (1) **«Τελευταίο» ≠ «σε ισχύ» έτος:** η
  αναζήτηση / οι ετικέτες / οι «καταργημένοι» χρησιμοποιούν το ΜΕΓΙΣΤΟ φορτωμένο έτος — αν εισαχθεί η ΣΟ του επόμενου έτους
  τον Οκτώβριο, ως τις 31/12 προτείνονται κωδικοί που δεν ισχύουν ακόμα και οι σημερινοί φαίνονται «καταργημένοι». Θέλει
  «έτος σε ισχύ = min(τρέχον, μέγιστο)» + προεπισκόπηση του επόμενου. (2) **Τα δεδομένα 2026 φορτώνονται μέσα στο migration**
  — σε επόμενο `schema:dump --prune` το migration σβήνεται και νέα εγκατάσταση θα έχει άδειο κατάλογο (σιωπηλά). Πριν από
  squash: μετάφερέ το σε idempotent `cn:import --bundled` που καλεί το install/deploy.
- **TARIC — υπόλοιπα (✅ v2.5.0: πεδίο είδους, snapshot γραμμής, TaricNo/itemCode σε ΤΔΑ/9.x, sandbox-validated).**
  (1) **Δεδομένα:** συμπλήρωση `taric_code` στα είδη αγαθών πριν την **1/1/2027**. (2) Η επέκταση 8→10 με «00» είναι η
  συνήθης· δεν υπάρχει ρητή οδηγία ΑΑΔΕ (https://www.aade.gr/sites/default/files/2026-07/FAQS_tARIC_0.pdf) — αν βγει, άλλαξε
  `Taric::normalize`. (3) P2: preview XML εκδομένου εγγράφου χωρίς snapshot δείχνει τον ΤΡΕΧΟΝΤΑ κωδικό του είδους· άκυρος
  κωδικός από CSV φαίνεται μόνο ως [101] στην υποβολή (θέλει προειδοποίηση/preflight).
- **Β' Φάση (12/10/2026) — από την πλευρά του ΠΑΡΑΛΗΠΤΗ:** η επιβεβαίωση παραλαβής + ποσοτικός/ποιοτικός έλεγχος
  (`ConfirmDeliveryOutcome`, qrUrl-only) = Slice 4c παρακάτω· χρειάζεται μόνο αν tenant παραλαμβάνει αγαθά με ψηφιακό ΔΑ.
- **Inbound «Εισερχόμενα Διακίνησης»** — 4a+4b ✅ ΧΤΙΣΜΕΝΑ (#540/#566: fetch + Απόρριψη/Έλεγχος/Παραλήφθηκε).
  Μένουν: sandbox rehearsal μιας πραγματικής απόρριψης (nexon⇄myip) · **4c** (qrUrl Confirm-outcome) DEFERRED
  μέχρι να παραλάβει tenant ψηφιακά παρακολουθούμενη διακίνηση.
- **Πλήρη 9.1 / 9.2** — 9.1 (συσχετιζόμενο) θέλει correlated MARKs (`addCorrelatedInvoice` + επιλογή σχετικών)·
  9.2 (συγκεντρωτικό) μοντέλο σύνοψης κινήσεων. Σήμερα κρυμμένα + μπλοκαρισμένα (MYD-012) — ξεμπλόκαρε με
  προσθήκη στο `Codes::SUPPORTED_DELIVERY_TYPES` όταν χτιστεί το μοντέλο.
- **Issuer-side `confirmDelivery()` = dead-end** ([833]/[817]/[814] — το outcome είναι μόνο του παραλήπτη/μεταφορέα)
  → κρύψ' το από τη ροή του εκδότη (punch-list, TIER-1 delivery).

### 📥 Import / onboarding
- **CSV εξόδων** — το CSV import πελατών/προϊόντων/προμηθευτών ✅ υπάρχει· τα έξοδα έμειναν έξω σκόπιμα (τα GR
  έρχονται από myDATA· έξοδα εκτός myDATA = κίνδυνος διπλομέτρησης). Μαζί με το «Ταμειακή εικόνα» αν χρειαστεί.
- **Setup profiles + curated tax-presets ανά κλάδο** (λιανική / εστίαση / ξενοδοχείο / υπηρεσίες) — bundle σε ένα
  κλικ: invoice types + default ΦΠΑ + «πρότυπα τελών» + payment methods + withholding/Ψηφιακό Τέλος presets
  (+ %-ανά-προϊόν, όχι μόνο €/τεμ). Πάνω στο υπάρχον seeding.
- **Durable native portable key (μετά το legacy_id sunset)** — `uuid`/`public_id` ανά portable πίνακα ως ΤΟ
  idempotency key του `CompanyImporter` (uuid→legacy_id→signature) + ΑΦΜ-dedup. Μόνο για sync/merge μεταξύ ζωντανών
  ekdosi — όχι για μεταφορά-σε-VM.

### 🧾 myDATA / classification
- **Υποβολή χαρακτηρισμών για λογαριασμό τρίτου (λογιστής, `entityVatNumber` [323])** — το ekdosi **ετοιμάζει**
  τους χαρακτηρισμούς (rules-engine ✅), ο λογιστής (δικό του login + ΑΦΜ + έγκριση) τους **στέλνει**· θέλει
  διερεύνηση ρόλων/δικαιωμάτων. (+ `RequestMyExpenses` sanity totals.)
- **Κατηγορίες εσόδων/εξόδων** — backfill ιστορικών WHMCS γραμμών (description→package→group→category) · bulk-assign
  κατηγορίας/tags στη λίστα ειδών · ίδιο report + widget για **έξοδα**.

### 🌐 PEPPOL / πάροχοι
- **PEPPOL Phase 2 — Access-Point transport + EE AP (PARKED, review 2027).** Phase 1 (UBL builder + «Προβολή/Λήψη UBL»
  + `invoice_ubl` + `peppol:test-submit`) ✅. Δεν επείγει: ViDA cross-border 2030 · Estonia 2027 (proposed) · GR
  καλύπτεται από InvoSign. ΕΝΑ AP (Telema/Billberry/Finbite/Unifiedpost) = GR+EE send — **check αν ο InvoSign κάνει
  ήδη PEPPOL send**· επιβεβαίωσε legal deadline με λογιστή. **Προϋποθέσεις:** buyer από το **frozen snapshot**
  (`counterpartAfm()/counterpartName()/counterpartCountryForFiling()` + `frozenPartyColumns()` στον PEPPOL submitter,
  όχι ζωντανός `customer`)· **9933 endpoint bare-vs-EL** — κλείδωσέ το με τον πραγματικό AP/Schematron.
  `paroxos/regulatory-blueprint.md §7`.
- **2ος GR πάροχος / SBZ** — ο InvoSign είναι live· το blueprint (`paroxos/`) μένει για δεύτερο πάροχο (θέλει creds + sandbox).
- **PROV-003 archive half (BLOCKED στον InvoSign)** — ανάκτηση + ιδιωτική αρχειοθέτηση του επίσημου PDF παρόχου
  (SHA-256, immutable, retry μόνο download, `evidence_pending`)· ξεμπλοκάρει μόνο με download/retention API.

### 🌍 Αντικατάσταση WHMCS (σταδιακή) → **`PLAN.md`**
- **Master epic** — strangler-fig (όχι big-bang· `billing_connections` επιτρέπει συνύπαρξη). Σειρά: **Domains →
  Payment gateways → Provisioning → Portal** (+ Support core ✅). Πλήρες σχέδιο + phase gates: `PLAN.md`.
- **Multi-party SPLIT write-back στο WHMCS** — ένα MARK ≠ N invoices.

### 🌐 Domains (Πυλώνας A — `docs/domains/README.md`)
- **A4 — grEPP** (.gr/.ελ direct EPP· 2ετία min, no privacy/lock) — **το κύριο κομμάτι: ~75% του portfolio**
  (1381 από 1833 active, `domains/whmcs-baseline.md`).
- **Υπηρεσίες/domains χωρίς χρέωση (δικές μας / φίλων / υπαλλήλων) — ΠΡΙΝ τη μεταφορά υπηρεσιών από WHMCS.**
  Σχέδιο v2: `non-billable-services.md`. **PR 1 ✅** (άτυπη σειρά + κοινό φίλτρο + test συνέπειας + αρίθμηση +
  PDF + προεπιλογή πελάτη → `FEATURES.md §2`). **PR 2 ✅** («Μετατροπή σε φορολογικό» + αναφορά «Αξία άτυπων»).
  **Μένουν (αν χρειαστούν):** μαζική «Αλλαγή σειράς» υπηρεσιών (για τη μεταφορά των δικών μας από τη WHMCS) ·
  επισήμανση στο inbox της WHMCS · συγκεντρωτική μετατροπή (πολλά ΕΣΩ → ένα). Φορολογικά (6.2
  ιδιοχρησιμοποίηση;) → λογιστής.
- **Υπενθυμίσεις λήξης domain (60/30/15/10/5 ημέρες) — cutover blocker.** Η WHMCS έχει στείλει 34.296· στο ekdosi
  δεν υπάρχουν (README §3.8 δεν χτίστηκε).
- **CNIC: 226 active ακόμα στο CentralNic** → **απόφαση: «χωρίς API»** (manual σύνδεση, σταδιακά όλα στο OP·
  ~155 φεύγουν μόνα τους ως 2027-02 μέσω της WHMCS). Λείπει: `domains:import-csv --connection=` ώστε τα CNIC
  domains να πάρουν ρητή manual σύνδεση (αλλιώς κληρονομούν το OP του .com) — `domains/whmcs-baseline.md` §9.
- **A5 — polish + registrar↔local reconciliation** (mirror του myDATA reconcile): bulk availability, portfolio
  dashboard· **cross-tenant guard και στο READ path** (`DomainSyncService::sync` by-name adopt σε κοινό reseller
  account)· reconciler inputs: renew logs με `short_of_target=null`/κάτω από `target_expiry`, orphan unconsumed renewals.
- **Εκτός v1 (συνειδητά):** DNSSEC key management · restore billing (σήμερα χειροκίνητα) · approve-transfer/resend-FOA.

### 🛠️ Services / provisioning / portal
- **Real provisioning modules** (cPanel/Mailcow/license server) — σήμερα μόνο `NullProvisioningModule` (= Πυλώνας C).
- **Multi-line service contracts** — v1 = single-line.
- **ΠΡΟΤ στην πύλη — renew/upgrade → convert** — recurring ΠΡΟΤ (filing OFF) → ο πελάτης επιλέγει
  ανανέωση/upgrade/downgrade/ακύρωση → πληρωμή (gateway) → **convert σε νόμιμο τιμολόγιο** → mark paid.
- **Λήξη προσφοράς + cascade στην υπηρεσία** — μηχανή καταστάσεων με ημερομηνίες (`offer_expires_at`, χάρη ~1 εβδ.,
  scheduled job → suspend → terminate)· «Δεν το χρειάζομαι» = επιτάχυνση. Ανοιχτό: πληρωμή μέσα στη χάρη → ξεπαγώνει;
- **«Εισερχόμενα από Πύλη» (BLOCKED στον λογιστή)** — επιφάνεια απόφασης πάνω στα πληρωμένα προτιμολόγια (ΤΠΥ/ΑΠΥ/
  ακύρωση/αρχειοθέτηση), χωρίς staging table. Ερώτημα: πόσος χρόνος από την είσπραξη ως την έκδοση;
  **+ Παραγγελίες από το /user (ιδέα, 2026-09-24):** αν η πύλη δεχτεί ποτέ παραγγελίες, καταλήγουν **εδώ** ως γραμμή
  (όπως τα `pending_whmcs_invoices` — η πηγή αλλάζει, όχι η λογική), **ποτέ** αυτόματο παραστατικό: ο χειριστής
  αποφασίζει ΑΛΠ ή ΤΠΥ (πρόταση ανά ΑΦΜ όπως `WhmcsInvoiceSplitter`), απορρίπτει απάτη / λάθος παραγγελία (+
  επιστροφή αν πληρώθηκε), ή «Δημιουργία παραστατικού» (πρόχειρο → κανονική ροή). Πληρωμή με την παραγγελία → πίστωση
  πελάτη ως την έκδοση, φαίνεται «πληρωμένη — εκκρεμεί παραστατικό». Ο πελάτης βλέπει στο /user την κατάσταση της
  παραγγελίας. **ΟΧΙ ως άτυπη σειρά** («ΠΑΡ»): η οριστικοποίηση άτυπου αφαιρεί απόθεμα και προχωρά ανανεώσεις, δεν
  φαίνεται στον πελάτη, δεν δέχεται πληρωμή. Κανόνας: Εισερχόμενα = κάτι που **ζητήθηκε** και δεν αποφασίστηκε· άτυπη
  σειρά = κάτι που **έγινε** αλλά δεν πωλήθηκε (ΕΣΩ/φίλοι/δοκιμές).
- **Πύλη — transactional surfaces (Πυλώνας D)** — πλήρωσε → B · domains → A · services → C, ανά πυλώνα (`PLAN.md §6`).
- **Πύλη — self-register (design locked, NOT built)** — μόνο **tier-2 CLAIM** (ΑΦΜ+email που ταιριάζει σε `customers`
  → email verification → grant εγκρίνεται από operator), ποτέ open signup. Μόνο όταν το ζητήσει tenant.
- **Multi-domain πύλη — Option B «hard scope»** — το custom host φιλτράρει docs/logins μόνο στον tenant του (αλλάζει
  feed + login-gating + edge cases πελάτη-σε-δύο-tenants).

### 🤖 AI / MCP / connectors (`ai-assistant-blueprint.md`)
- **External MCP follow-ups** — per-tenant OAuth binding (claude.ai multi-company) · `connection_health` tool
  (WHMCS/myDATA freshness).
- **Βοηθός Phase 2c (χαμηλή προτ.)** — per-company κλειδί UI (`companies.ai_api_key` + per-key billing) ·
  `ai_conversations` persistence (+ UI επιλογής) · streaming απαντήσεων (SSE/Livewire).
- **Bridges/Connectors Phase 1** — πραγματική 2η πηγή (WooCommerce/Blesta…) → `bridges-connectors.md`. **Χτίσ' το
  μόνο όταν υπάρξει πραγματική 2η πηγή** (αλλιώς το contract κουβαλά WHMCS-isms).

### 💡 UX / ERP-parity ideas
- **Κεντρικός editor κειμένων/ετικετών (ζητήθηκε 2026-09-19)** — «Ρυθμίσεις → Κείμενα/Ετικέτες» για όλα τα
  customer-facing strings (`PdfLabels::MAP`, `lang/{el,en}/mail.php`, `lang/{el,en}/portal.php`)· πίνακας
  `label_overrides` (`company_id` nullable=global) + override-layer με **fallback στα code defaults**.
  **DEFERRED (owner, 2026-09-23).** Όταν χτιστεί: ΑΠΟΡΡΟΦΑ ή ρητά ΕΞΑΙΡΕΙ τα ήδη editable κείμενα (`MailTemplateFields`,
  τα `reminder_templates` των υπενθυμίσεων) — ποτέ δεύτερη πηγή για το ίδιο κείμενο.
- **Multi-currency invoicing** — `currency` υπάρχει (EUR hardcoded)· FX + στρογγυλοποίηση + εμφάνιση (myDATA θέλει EUR ισοτιμία).
- **Επαφές (shared CRM)** — κοινή `Contact` ↔ many customers με ρόλους (π.χ. λογιστής πολλών πελατών). DEFERRED —
  να μη σπάσει το per-customer `customer_contacts` που χρησιμοποιεί ο Sendable statement.

---

## 🧭 Guardrails — decided, don't re-open without a NEW reason

**Scope / product**
- **Απορρίπτονται:** αξιόγραφα/επιταγές (καμία από το 2007) · αποθήκη/απογραφή · λιανική (Χ/Ζ) · τα ~120 settings του Epsilon.
- **Ψηφιακό Πελατολόγιο — ΔΕΝ μας αφορά (έρευνα 2026-09-25· watch).** Α.1057/2025 (ΦΕΚ Β' 1828/14-04-2025), από 1/7/2025
  **μόνο κλάδος οχημάτων** (συνεργεία, φανοποιεία, πλυντήρια, στάθμευση, ενοικιάσεις)· ανακοινωμένα επόμενα (χωρίς ΦΕΚ):
  εκδηλώσεις/catering, ξενοδοχεία, υγεία/ομορφιά/γυμναστήρια/εκπαίδευση/νομικές. Όχι IT/hosting/επισκευή Η/Υ. Αν ενταχθεί
  κλάδος μας: μικρό REST API (DCL: `SendClient`/`UpdateClient`/`CancelClient`/`ClientCorrelations`/`RequestClients`)
  στα ίδια myDATA creds. Άρα **η λιανική (Χ/Ζ) μένει απορριφθείσα** — οι ΑΠΥ μέσω παρόχου αρκούν.
  Πηγές: https://www.aade.gr/psifiako-pelatologio · https://www.taxheaven.gr/circulars/50129/a-1057-2025 ·
  https://www.aade.gr/en/mydata/technical-specifications-digital-client-list-portal-publications
- **Διαχειριστικά (όχι κώδικας), έρευνα 2026-09-25:** (1) **Η/Τ B2B Β' περίοδος 1/10/2026** — επιβεβαίωσε ότι η InvoSign
  έκανε τη δήλωση παρόχου ανά tenant (https://www.taxheaven.gr/news/74429/hlektronikh-timologhsh-b-periodos-analytiko-xronodiagramma-paradeigmata-erwthseis-kai-prostima)·
  (2) **ΚΑΔ Rev.2.1** (Α.1003/2026, από 1/3/2026) — ενημέρωσε τον ΚΑΔ εκδότη στα στοιχεία εταιρίας (τον ζητά ο πάροχος)
  (https://www.taxheaven.gr/codes/kad2026).
- **Per-product τιμοκατάλογος — DROPPED:** per-line έκπτωση + per-customer default discount αρκούν (re-open μόνο με πραγματικό use case).
- **Η/Τ B2B (κύματα 2/2/2026 · 1/10/2026) = ιστορικό:** η παραγωγή ΗΔΗ φιλάρει μέσω παρόχου (InvoSign — invoicer.myip.gr, ekdosi.nexon.gr).
- **«Μοιάζει κενό αλλά δεν είναι»:** E3 overview υπάρχει (`MyDataE3Overview`) · `TenantScopedUnique` redundant (DB unique) · stock/ΣΔΕΠ/WHMCS sentinels = dead legacy code.
- **RequestVatInfo «ΦΠΑ cross-check» + E3↔local classification diff — deferred ON PURPOSE:** μετράει το Φ2 *deductible* → μόνιμη ψεύτικη διαφορά (η ΦΠΑ picture `MyDataVatAggregator` ΕΙΝΑΙ χτισμένη).
- **Multi-branch (MYD-010):** issuer `branch=0` είναι η αλήθεια· counterpart branch per-invoice (`invoices.counterpart_branch`)· το πραγματικό fix είναι child table `customer_branches`.
- **In-app update apply = DISARMED** (`deploy/update.sh <tag>` είναι το path)· re-arm μόνο με UPD-001…004 + κοινό resolver repo/token (UI override→env) και για τα δύο μονοπάτια.
- **IA:** clusters ανά πυλώνα (βάθος, όχι πλάτος)· 2ο Filament panel μόνο αν αλλάζει το κοινό (`docs/menu-ia.md`).
- **Support:** in-app KB DROPPED (BookStack) · SLA timers = δικό τους slice · **ΟΧΙ per-department ticket dedup** (δοκιμάστηκε, revert — duplicate-on-redelivery).
- **Credit-use bell — ΑΠΟΡΡΙΦΘΗΚΕ:** η χρήση πίστωσης είναι χρήμα που ήδη έφτασε (το «Ιστορικό» έχει causer=πελάτης)· bell μόνο για νέο χρήμα από gateway.

**myDATA / provider**
- **MYD-023:** υιοθέτηση «ήδη ακυρωμένου» σε ΔΑ + πάροχο **ΠΡΙΝ** την αυστηρή άρνηση Success-χωρίς-ΜΑΡΚ-ακύρωσης (αλλιώς stranding όπως MYD-021).
- **InvoSign contact fields (`CounterpartTaxOffice/Phone/Email`) = ζωντανά by design** — όχι νομική ταυτότητα· αν χρειαστούν αναπαραγώγιμα → δικές τους snapshot στήλες.
- **ΔΑ σε εξωτερικό παραλήπτη χωρίς ΑΦΜ = ανοιχτό ερώτημα ΑΑΔΕ/λογιστή** (η σεντινέλα `000000000` είναι για ενδοδιακίνηση)· μην αυτοσχεδιάσεις.
- **Issuer name/address snapshot — deferred:** `[219]`/`[220]` απαγορεύουν issuer name στο τιμολόγιο· ξανανοίγει αν tenant αλλάξει πραγματικά έδρα.
- **Filing-policy snapshot (MYD-018) — δεν είναι μονόγραμμη:** το `mydata_type` στηρίζει fallback του `SalesReconciler` (null σε ETL rows).
- **Provider exactly-once:** ο InvoSign κάνει dedup (`recoverViaStatusCheck` wired)· ξανασήκωσέ το για πάροχο που ΔΕΝ κάνει dedup.
- **PROV-005 — κανένα authenticated probe:** κάθε InvoSign call κοστίζει credits· `ping()` = μόνο reachability.
- **PROV-009 — delivery-fail warn / quota poll = infeasible** (κανένα callback ή non-issuing endpoint).
- **PROV-019 — ΟΧΙ 5-state correction machine:** `reissued_from_invoice_id` + soft-warn· hard-block toggle μόνο αν φανεί double-turnover.
- **Gapless-at-send ΑΑ:** `release()` μόνο τον αριθμό που κράτησε ΑΥΤΟ το submit· gapless μόνο υπό serial issuance (ok στα ~70 docs/μήνα).

**Money / payments / portal**
- **Eurobank vPOS return = επαληθευμένο σε production** (myip, intent #5, 2026-09-20: canonical digest ✓, `currency` ✓, 12ψήφιο `txId` ✓)· η μοναδικότητα `txId` θεωρείται δεδομένη (αύξων μετρητής Cardlink) → αν ποτέ εμφανιστεί ψευδές `duplicate_transaction`, N-day window στο `transactionAlreadySettled()`.
- **Payment intents: το expiry ΠΟΤΕ δεν μπλοκάρει settle** (money > tidiness)· expiry = housekeeping/badge μόνο.
- **B1 declined:** κανένα auto-cancel intent σε FAILED/CANCELLED return (retry-CAPTURE στο ίδιο orderid) · δύο `connectionFor()` loaders σκόπιμα (portal refuses inactive, return `withTrashed`).
- **Gateway config keys μοιράζονται ένα `statePath('config')`** → namespace (`config.eurobank.testmode`) ΠΡΙΝ το B2 PayPal/Stripe.
- **WHMCS receipt amount = δικό μας owed**, όχι τα ευρώ του WHMCS (editable money-trail, όχι reconciliation).
- **WHMCS mapper / mass-pay υποθέτουν ΕΝΑ ΦΠΑ rate** — revisit πριν μπει reduced-rate tenant.
- **Πύλη: invited-claim vs revoked grant = by design** — login status ⟂ grants· για εξουδετέρωση → **suspend**, όχι revoke.
- **Πύλη: reseller grant βλέπει ΟΛΟ το document history** του πελάτη — σκόπιμο (ο grant ΕΙΝΑΙ η σχέση).
- **Octane readiness:** `SetPortalLocale` χωρίς locale reset + `View::share('portalCompany')` worker-global → θα διέρρεαν μεταξύ requests/tenants· fix πριν πάμε Octane (FPM = non-issue).

**Security / tenancy / secrets**
- **Legacy secrets (`/legacy/` .dfm/.cfg) — κλειστό:** τα credentials άλλαξαν και το git history καθαρίστηκε (2026-09).
- **Proxies/hosts (2026-09):** `TRUSTED_PROXIES` default `local` (loopback + οι IP του server → CFM edge χωρίς ρύθμιση· `none` σε shared hosting) · trust μόνο `X-Forwarded-For/Proto` (ποτέ Host/Port) · **host pinning (`trustHosts`) — χτίστηκε και ΑΦΑΙΡΕΘΗΚΕ:** το CFM edge δρομολογεί ανά vhost (άγνωστα Host κατά κανόνα δεν φτάνουν στην εφαρμογή), και ένα pin δίνει 400 σε κάθε αλλαγή domain/CDN/IP· ξανανοίγει μόνο αν η εφαρμογή γίνει default vhost ενός box.
- **Strict tenant scope (null→throw) — deferred:** audit 0 leaks σε ~54 entry points· το no-op default είναι load-bearing (`CLAUDE.md`).
- **Secrets μένουν super_admin**· `CompanySettings` = SAFE whitelisted subset — μην μεταφέρεις credentials μαζικά στον company_admin.
- **Declined:** tenant-slug existence oracle (404 vs 401) στο `issued-doc-pdf` — συνεπές με τα sibling webhooks, τα slugs δεν είναι μυστικά.
- **Declined (security-through-obscurity):** κανένα CSP (panel = Livewire inline· 0 `{!! !!}` στα customer views) · `X-Powered-By` (fix αν ποτέ: `expose_php=Off`).

**Deploy / ops / schema**
- **Deploy guards:** καμία override για dirty tracked tree (το `git stash` ΕΙΝΑΙ το override) · ποτέ δεν καταστρέφουμε αρχείο που δεν μπορέσαμε να αντιγράψουμε.
- **Schema baseline squash:** `migrate:rollback` = no-op για τα baseline migrations· επαναφορά μέσω `ekdosi:db-restore` snapshot· ξανα-strip τα `DROP TABLE` μετά από `schema:dump`.
- **OPS-001 cron↔worker 5-min ambiguity — DECLINED:** μην το ξαναμπαλώνεις με timing math· μόνο με 2ο ανεξάρτητο worker-liveness signal.
- **Domains A2:** επαλήθευσε το OP min-term cost quoting (`max(1, min_years)`) με live creds στο go-live, πριν εμπιστευτείς κόστη πολυετών TLDs.

---

## 📚 Reference docs

- **`PLAN.md`** (root) — master roadmap «Ekdosi ως σταδιακή αντικατάσταση WHMCS» (Domains → Payment gateways →
  Provisioning → Portal, strangler-fig).
- **`domains/README.md`** — Πυλώνας A design: data model + `DomainRegistrar` contract + Openprovider mapping + .gr/grEPP + phase gates.
- **`domains/whmcs-baseline.md`** — τι δείχνει η παραγωγική WHMCS (portfolio, ρολόγια, ενέργειες, υπενθυμίσεις) + αποφάσεις.
- **`non-billable-services.md`** — άτυπη σειρά (δικά μας / δοκιμές / φίλοι-υπάλληλοι) + μετατροπή σε φορολογικό — σχέδιο.
- **`paroxos/regulatory-blueprint.md`** + **`paroxos/implementation-plan.md`** — GR ΥΠΑΗΕΣ πάροχος + EU PEPPOL. Ο GR
  πάροχος είναι **LIVE στην παραγωγή (InvoSign)**· PEPPOL Phase 1 DONE· ανοιχτό μόνο το **PEPPOL Phase 2**.
- **`payment-connectors.md`** — card-POS + IRIS design (NOT-STARTED, blueprint).
- **`bridges-connectors.md`** — multi-billing-source. Phase 0 DONE· Phase 1 (real 2nd source) OPEN.
- **`whmcs-legacy-plugin-map.md`** — legacy WHMCS plugins → `ekdosi_bridge`. T-1/T-2 DONE· T-3 cutover OPEN.
- **`operator-health.md`** · **`dr-without-app-key.md`** · **`go-live-usage-checks.sql.md`** — ops runbooks (reference).
