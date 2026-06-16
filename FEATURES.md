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
  σε invoice + quote.
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
- **Τέλη / παρακρατήσεις / φόροι** — withholding (§8.4), χαρτόσημο/τέλη/λοιποί/
  κρατήσεις (taxesTotals), **product-linked per-unit fees** (π.χ. τέλος διαμονής),
  «Τυπικά τέλη/φόροι» quick-fill· gross-edit γραμμής (τιμή με ΦΠΑ → back-compute net).
  **Μετράνε στο εισπρακτέο:** `invoices.payable_total` (= καθαρή+ΦΠΑ + τέλη − παρακράτηση,
  κανόνας AADE [208]) είναι η βάση για owed/balance/Καρτέλα/receivables (το `gross_total`
  μένει net+ΦΠΑ = τζίρος)· το PDF «Πληρωτέο» = `payable_total`.
- **Pickers**: αγαπημένα-πρώτα + most-used + inline create προϊόντος· tags· πλήρες
  ελληνικό UI· «Νέο Παραστατικό» από την Καρτέλα.

## 3. myDATA (ο πυρήνας)
- **Υποβολή / ακύρωση / dry-run** μέσω `firebed/aade-mydata` (`MyDataSubmitter`),
  sandbox-validated (1.1/2.1/11.x/5.1 + CANCEL + νέοι taxTypes + 4% override + ΔΑ).
- **`mydata_marks` = source of truth** (πλήρες request/response XML, νομικό audit).
- **Κονσόλα myDATA** — ένα μενού (cluster) με tabs **Πωλήσεις / Έξοδα / Επισκόπηση Ε3 /
  Έλεγχος ρυθμίσεων**· ζωντανός συγχρονισμός (`RequestTransmittedDocs`) + **reconciliation**:
  τοπικό (Phase 1, ξεχωριστή «Συμφωνία myDATA») + ζωντανό (Phase 2, `SalesReconciler`)· matched /
  stateMismatch / missingAtAade / **αδέσποτα** (ομαδοποιημένα ανά οικονομική φύση). Κάθε
  tab κρατά δικό του «τελευταία ενημέρωση» + lazy fetch.
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
- **Code tables** (`App\Support\MyData\Codes`) — §8 πίνακες με validation helpers.

## 4. Έξοδα / Προμηθευτές / Ε3
- **Προμηθευτές** (`Supplier`) — CRUD + «Άντληση από ΑΑΔΕ» (GSIS) + **`suppliers:sync`**
  (μοναδικά issuer ΑΦΜ από `RequestDocs`).
- **Εισαγωγή αδέσποτων** εξόδων από myDATA (`ExpenseImporter`/`ExpenseReconciler`) +
  self-declared (αποδείξεις/μισθοδοσία/ΔΕΚΟ). **Κουμπί «Άντληση από myDATA» στη λίστα
  Έξοδα** (one-click read-only fetch → worklist) + tip «τελευταία άντληση · X αδέσποτα» +
  read-only cron **`mydata:refresh-expenses`** (default OFF, toggle στη «Ρυθμίσεις
  χρονοπρογραμματιστή»· δεν δημιουργεί εγγραφές).
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
- **Πάροχος vs direct**: έκδοση/ακύρωση μέσω παρόχου· έναρξη/παράδοση/έλεγχος direct
  myDATA. Sandbox round-tripped.

## 6. Πάροχοι e-invoicing & PEPPOL
- **Δίαυλος αποστολής** per-tenant: `gr-mydata` (απευθείας ΑΑΔΕ), `gr-provider`
  (InvoSign — `EInvoiceProviderTransport` + registry), `none`.
- **ProviderConsole** + `einvoice:preflight` / `einvoice:test-submit`.
- **PEPPOL Phase 1** (Εσθονία) — provider-independent **BIS Billing 3.0 / EN 16931 UBL**
  builder (`PeppolInvoiceDocument` μέσω `josemmo/einvoicing`) + `peppol:test-submit`
  (dry-run + validate). Phase 2 (Access-Point transport) = backlog.

## 7. Πελάτες & Καρτέλα
- **GSIS lookup** native (`AadeRegistryLookup`) + «Άντληση/Διόρθωση από ΑΑΔΕ».
- **VIES (EU)** — επαλήθευση/άντληση μη-GR ενδοκοινοτικών ΑΦΜ (`ViesLookup`) +
  **reverse-charge hint** (0% + §8.3 «16 — άρθρο 45»).
- **Καρτέλα**: ledger κινήσεων, aging, **YoY**, charts, εξαγωγή **PDF/CSV** + email·
  «αναλυτική παρακράτηση» (αξία εγγράφου + παρακράτηση/τέλη κάτω από την αναφορά, χωρίς
  να αλλάζει το υπόλοιπο).
- **Tags** (tenant-scoped) + favourites σε customers/products.

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

## 12. Βιβλία / Λογιστικά / Αναφορές
- **Λογιστικά βιβλία** (`LedgerBook`) + **Λογαριασμοί** (`Accounts`).
- **Αναφορές** (`Reports`) + **ΦΠΑ ανά περίοδο** (`VatPeriodReport`, μήνας/τρίμηνο).

## 13. Migration / ETL
- **`migrate:firebird`** — επαναλήψιμο ETL, μία εταιρία/run, upsert σε
  `(company_id, legacy_id)`, χειρισμός WIN1253, UI εισαγωγής (`.fdb`/`.fbk`).

## 14. Backups / Portability / DR
- **Per-company backups** (`spatie/laravel-backup`) — πρόγραμμα/διατήρηση/προορισμοί
  (Τοπικά/SFTP/FTP/S3), «Αντίγραφο/Λήψη τώρα».
- **Export/Import εταιρίας** — settings+setup ή πλήρες· **χωρίς υποχρεωτικό κωδικό**
  (passphrase ή raw)· `company:export`/`company:import` + panel actions.
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
- **FK-aware delete guard** (`GuardedDeleteAction`) — μπλοκάρει διαγραφή lookup σε χρήση.
- **Off-site backup verification** (`ops:health` → `backup.companies`) — ανά tenant με
  ενεργά backups: υπάρχει προορισμός **εκτός VM** (sftp/ftp/s3); και πέτυχε η τελευταία
  off-site αποστολή; `offsite_gap` προειδοποιεί για «μένουν μόνο τοπικά» ή αποτυχημένο push.
- **`ops:health`** (queue/scheduler/backup/mail/WHMCS/myDATA/disk) — CLI **και**
  **σελίδα «Υγεία συστήματος»** (read-only, **super_admin-only** γιατί είναι cross-tenant·
  ίδια πηγή `OperatorHealthReport`: worker heartbeat, scheduled-task last-runs, backups,
  mail, WHMCS+myDATA ανά tenant, δίσκος, **+ ιστορικό εκτελέσεων** `scheduled_task_runs`
  + pending/failed jobs + κουμπί **«Επανάληψη αποτυχημένων»**) — στο νέο nav group
  **«Σύστημα»**. + **«Εργαλεία»** (artisan commands ως κουμπιά) +
  **Δοκιμή SMTP** (per-company + global) + **`ekdosi:install`** turnkey first-run.
- **`ekdosi:go-live-check --tenant=SLUG [--json]`** — per-tenant cutover-readiness gate
  (read-only): provider · τύποι+E3 · default ΦΠΑ · ΦΠΑ→ΑΑΔΕ · **production creds (hard FAIL)** ·
  mode · αρίθμηση · **golden totals-drift** · backups · queue/infra (από `OperatorHealthReport`).
  myDATA gates SKIP για μη-myDATA tenants. Exit 0/1/2. Runbook: `docs/go-live-runbook.md`
  (τα χειροκίνητα: Firebird usage probes + AADE production smoke-test).
- **Scheduler + queue** (DB driver) — backups/auto-email/reconcile/WHMCS/VAT-picture,
  gated by `EKDOSI_SCHEDULE_*` **+ σελίδα «Ρυθμίσεις χρονοπρογραμματιστή»**
  (super_admin-only): toggles ανά εργασία στο `system_settings` store, διαβάζονται
  run-time από `routes/console.php` (env = προεπιλογή· αποθηκεύονται μόνο οι αποκλίσεις,
  με audit). Νέο nav group **«Σύστημα»**.
- **Σελίδα «Ρυθμίσεις συστήματος»** (super_admin-only) — οι καθολικές knobs ως audited
  toggles στο `system_settings` (env = προεπιλογή, αποθηκεύονται μόνο οι αποκλίσεις):
  **`require_2fa`** (live — διαβάζεται από τον panel), **backup-alert on/off + email(s)**
  (live — διαβάζεται από `company:run-scheduled-backups`). **At-rest κρυπτογράφηση** +
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

## 17. Setup / lookups
VAT categories · invoice types · payment/delivery methods · distribution aims · metric
units · bank accounts · product categories · **tags** — όλα tenant-scoped, με
guarded delete, προ-σπαρμένα από `MyDataLookupSeeder` για άμεση έκδοση.

---

## Καταργήθηκαν σκόπιμα (δεν τα ξανακάνουμε)
CS-Cart bridge · ΕΑΦΔΣΣ (`EAFDSS_SCRIPT`) · FastReport `.fr3` (→ Blade PDF) ·
`FMysqlSync` MySQL mirror (→ WHMCS API) · `GET_COMB_*` (cross-DB με inline SYSDBA —
security landmine) · `afm2name` (→ native GSIS). **stock/αποθήκη + ΣΔΕΠ/σωρευτικά**
ήταν **νεκρός κώδικας** στο legacy — δεν «λείπουν», απλώς δεν υπήρχαν.
