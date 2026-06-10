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
- **QR + PDF** (Blade/dompdf).
- **Δύο ορθογώνιες καταστάσεις**: `local_status` (draft/active/cancelled) vs
  `mydata_state` (null/VALID/CANCELLED) — ποτέ μπερδεμένες· ένα predicate
  (`InvoiceScope::live()`) σε όλα τα money sites.
- **Lifecycle** actions: Οριστικοποίηση · Επαναφορά σε πρόχειρο · Ακύρωση · Επανέκδοση.
- **Πιστωτικά** (`IssueCreditNote`) — συσχετιζόμενα (5.1) ή μη (5.2), αμφίδρομη
  σύνδεση με το αρχικό· opt-in myDATA filing.
- **Τέλη / παρακρατήσεις / φόροι** — withholding (§8.4), χαρτόσημο/τέλη/λοιποί/
  κρατήσεις (taxesTotals), **product-linked per-unit fees** (π.χ. τέλος διαμονής),
  «Τυπικά τέλη/φόροι» quick-fill· gross-edit γραμμής (τιμή με ΦΠΑ → back-compute net).
- **Pickers**: αγαπημένα-πρώτα + most-used + inline create προϊόντος· tags· πλήρες
  ελληνικό UI· «Νέο Παραστατικό» από την Καρτέλα.

## 3. myDATA (ο πυρήνας)
- **Υποβολή / ακύρωση / dry-run** μέσω `firebed/aade-mydata` (`MyDataSubmitter`),
  sandbox-validated (1.1/2.1/11.x/5.1 + CANCEL + νέοι taxTypes + 4% override + ΔΑ).
- **`mydata_marks` = source of truth** (πλήρες request/response XML, νομικό audit).
- **Κονσόλα myDATA** — ζωντανός συγχρονισμός (`RequestTransmittedDocs`) + **reconciliation**:
  τοπικό (Phase 1) + ζωντανό (Phase 2, `SalesReconciler`)· matched / stateMismatch /
  missingAtAade / **αδέσποτα** (ομαδοποιημένα ανά οικονομική φύση).
- **Σελίδα ΜΑΡΚ** (direction-aware) + per-line E3 classification.
- **`mydata:preflight`** — read-only έλεγχος invoice-type/VAT config vs §8 code tables.
- **Code tables** (`App\Support\MyData\Codes`) — §8 πίνακες με validation helpers.

## 4. Έξοδα / Προμηθευτές / Ε3
- **Προμηθευτές** (`Supplier`) — CRUD + «Άντληση από ΑΑΔΕ» (GSIS) + **`suppliers:sync`**
  (μοναδικά issuer ΑΦΜ από `RequestDocs`).
- **Εισαγωγή αδέσποτων** εξόδων από myDATA (`ExpenseImporter`/`ExpenseReconciler`) +
  self-declared (αποδείξεις/μισθοδοσία/ΔΕΚΟ).
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
- **Καρτέλα**: ledger κινήσεων, aging, **YoY**, charts, εξαγωγή **PDF/CSV** + email.
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

## 11. WHMCS γέφυρα
- Ενοποιημένο plugin **`ekdosi_bridge`**, **PHP-to-PHP μέσω WHMCS API** (HMAC, όχι shared-DB).
- **Inbox draft-first** (`WhmcsInbox`) — webhook/poll → `pending_whmcs_invoices` →
  «Δημιουργία Παραστατικού» (editable draft) → lifecycle → write-back `invoiced=MARK`.
- **Πρόθεση πελάτη** (τιμολόγιο/απόδειξη, ΑΦΜ/ΔΟΥ, «λείπει ΑΦΜ»), **legacy badge**.
- **Αμφίδρομη ορατότητα** (WHMCS-side): badge+ΜΑΡΚ, badge λίστας, «Αποστολή στο Ekdosi»,
  **3-way map** (WHMCS#→ΤΠΥ→ΜΑΡΚ), συγκεντρωτική λίστα, AFM-keyed + deterministic
  `invoiced===legacy_id` historical link.
- **timologia v2 / τρίτοι** — resolution, single-party billing, **multi-party guided
  split** (όχι σιωπηλό ανακάτεμα), editable routing.
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
- **DR χωρίς APP_KEY** — `MaybeEncrypted` cast + `EKDOSI_ENCRYPT_SECRETS_AT_REST`
  (default plaintext) → plain `mysqldump` αυτάρκες· `secrets:reencrypt` για εναλλαγή.

## 15. Ασφάλεια & λειτουργικά
- **Secrets `$hidden`** (out of toArray/logs) + at-rest encryption optional.
- **2FA** (TOTP) + `EKDOSI_REQUIRE_2FA`.
- **FK-aware delete guard** (`GuardedDeleteAction`) — μπλοκάρει διαγραφή lookup σε χρήση.
- **`ops:health`** (queue/scheduler/backup/mail/WHMCS/myDATA/disk) — CLI **και**
  **σελίδα «Υγεία συστήματος»** (read-only, **super_admin-only** γιατί είναι cross-tenant·
  ίδια πηγή `OperatorHealthReport`: worker heartbeat, scheduled-task last-runs, backups,
  mail, WHMCS+myDATA ανά tenant, δίσκος) — στο νέο nav group **«Σύστημα»**.
  + **«Εργαλεία»** (artisan commands ως κουμπιά) +
  **Δοκιμή SMTP** (per-company + global) + **`ekdosi:install`** turnkey first-run.
- **Scheduler + queue** (DB driver) — backups/auto-email/reconcile/WHMCS/VAT-picture,
  gated by `EKDOSI_SCHEDULE_*`.

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
