# Changelog — ekdosi

Notable changes to the **ekdosi app** (Laravel + Filament). Format:
[Keep a Changelog](https://keepachangelog.com/). The app ships continuously
(no SemVer tag yet), so changes are grouped under `[Unreleased]` and dated as
they merge.

> **The WHMCS-side plugin has its own log:**
> `whmcs-plugin/ekdosi_bridge/CHANGELOG.md`.
> **Deep archive** (dated inspection notes, per-PR review logs):
> `docs/CLAUDE-history.md`. This file starts at 2026-06 — older history lives
> in that archive + git.
>
> **Discipline (from 2026-06 on):** every PR adds a line here under
> `[Unreleased]` (Added / Changed / Fixed / Removed). On a release cut, rename
> `[Unreleased]` to the dated/versioned heading.

## [Unreleased]
### Added
- **Plugin-API consolidation — push path fetches via the bridge.** The WHMCS
  invoice-paid webhook (`WhmcsInvoicePaidController`) now pulls the canonical
  invoice payload from the ekdosi_bridge plugin (`resolve.php op=invoice`, via the
  new `WhmcsBridgeClient::fetchInvoice`) for tenants on `whmcs_fetch_via_bridge`,
  instead of the native WHMCS API — so BOTH the inbox pull and the push share one
  HMAC path (the Plugin-API). Native API stays the path for tenants without the
  plugin. A bridge config gap → 422, same as before.
- **`whmcs:use-bridge --tenant=SLUG [--off]`** — guarded switch for a tenant's
  invoice SOURCE (Plugin-API vs native WHMCS API). ENABLING probes the deployed
  plugin for `op=invoice` support first and refuses to flip if it's older than
  v0.32.0 (closes the deploy-ordering trap that would break the push path);
  reversible with `--off`. The flag drives both pull and push; plugin-less
  tenants stay on the native API ("API only when there's no plugin").
- **Company «Fetch pending» button → Plugin-API for bridge tenants.** The admin
  Company form's manual fetch now delegates to `whmcs:fetch-pending` for tenants
  on `whmcs_fetch_via_bridge` (same source + legacy-invoiced refresh as the
  scheduler), instead of its own native-API loop — closing the last spot that
  still hit the WHMCS API on the happy path. Plugin-less tenants keep the native
  loop.
### Fixed
- **Scheduler silent multi-day stall — bounded `withoutOverlapping(30)`.** Every
  scheduled task used the default 24h overlap-lock TTL; a run killed mid-flight
  (reboot/deploy/OOM) orphaned the cache lock and every later `schedule:run`
  SILENTLY skipped the task for a full day — how the WHMCS fetch went dark ~1.5
  days. Now the lock self-heals in ≤30 min (tasks are idempotent, so a rare real
  overlap is benign).
### Changed
- **Πληρωμές — money trail σε cash-term παραστατικά (model refinement).** Ένα
  μετρητοίς/άμεσο τιμολόγιο (`due_days=0`) θεωρείται «εξοφλημένο στην έκδοση»
  **μόνο όσο ΔΕΝ έχει καταγεγραμμένη πληρωμή**. Μόλις ο χειριστής καταχωρίσει
  πραγματική είσπραξη (π.χ. Stripe/POS receipt + transaction_id/τράπεζα για τα
  βιβλία), το τιμολόγιο γίνεται **tracked παντού** (cockpit, Καρτέλα, dashboard,
  receivables) και **κάνει net-to-zero** χρέωση↔πληρωμή — κανένα phantom. Νέο
  πάντα-διαθέσιμο «Καταχώριση πληρωμής» στο cockpit (ακόμη και σε μηδενικό
  υπόλοιπο). Ενιαίος κανόνας «tracked = επί-πιστώσει Ή έχει πληρωμή» σε
  `InvoiceBalance`, `DashboardMetrics`/`Customer` (receivables predicate),
  `CustomerLedgerBuilder`. Τα ~6.7k imported τιμολόγια αμετάβλητα (legacy
  πληρωμές = on-account). `CashTermRecordedPaymentTest` + `MoneyStatusConsistencyTest`.
- **Μενού — οι «Πληρωμές» μετακινήθηκαν** από το τεχνικό group «Data» σε νέο
  group **«Είσπραξη/Πληρωμές»**.
### Added
- **Πληρωμές — Επιστροφές / refunds (#3).** Νέα στήλη `payments.kind`
  (`payment`|`refund`, default `payment`)· μια επιστροφή αποθηκεύεται με **θετικό**
  ποσό αλλά **αφαιρείται** από το paid παντού (`Payment::NET_AMOUNT_SQL`):
  `InvoiceBalance`, dashboard receivables, `Customer::withOutstandingBalance`,
  Καρτέλα (stats/aging/yearly + **γραμμή DEBIT «Επιστροφή χρημάτων»**). UI:
  action «Επιστροφή χρημάτων» στο cockpit τιμολογίου (ανά ΤΙΜ) + στην Καρτέλα
  (customer-level / on-account)· «Τύπος» badge· labels σε ledger/CSV/PDF.
  Κλείνει τον κύκλο «χρήμα πίσω» (μαζί με ακύρωση/πιστωτικό). `RefundTest`.
  **Deploy:** `php artisan migrate`.
- **Πληρωμές — Χρήση πίστωσης (#1) & Χειροκίνητη κατανομή (#2).** Στην Καρτέλα:
  «Χρήση πίστωσης» μετακινεί διαθέσιμη on-account πίστωση πάνω σε ανοιχτό
  τιμολόγιο (re-point των payment rows — **net-zero** στο συνολικό υπόλοιπο,
  capped από υπόλοιπο τιμολογίου & διαθέσιμη πίστωση), «Χειροκίνητη κατανομή»
  ορίζει **ακριβές ποσό ανά τιμολόγιο** (vs FIFO). `PaymentAllocator::applyCredit`
  / `allocateManual` / `availableCredit`. `ApplyCreditAndManualAllocationTest`.
- **Πληρωμές — Ληξιπρόθεσμα / Due (#6).** Ημερομηνία λήξης = `issued_at +
  payment_method.due_days` (μηδέν για μετρητοίς). Νέα `Invoice::dueDate()` /
  `isOverdue()` / `scopeOverdue()` (driver-aware date math, EXISTS σε
  `payment_methods` — μετράει μόνο live, active, μη-πιστωτικά, επί-πιστώσει,
  ανοιχτά (`payment_status` unpaid/partial) με due date στο παρελθόν· μηδέν
  αλλαγή money model). Στη **λίστα τιμολογίων**: στήλη «Λήξη» (κόκκινο
  «Ληξιπρόθεσμο») + filter «Μόνο ληξιπρόθεσμα». **Dashboard**: widget
  «Ληξιπρόθεσμα τιμολόγια» (παλαιότερα πρώτα, link στο παραστατικό).
  **Notifications (bell, ΟΧΙ email)**: `invoices:notify-overdue [--tenant]
  [--dry-run]` — ημερήσιο digest ανά tenant (scheduler flag
  `EKDOSI_SCHEDULE_OVERDUE_NOTIFICATIONS`, default OFF). `OverdueInvoicesTest`.
  **Deploy:** `php artisan migrate` (πίνακας `notifications`).
- **Πληρωμές — Τραπεζικοί Λογαριασμοί (L2).** Νέο lookup `bank_accounts` (ανά
  tenant: τράπεζα, IBAN, δικαιούχος, SWIFT, `is_active`) με δικό του Filament
  resource (Setup → «Τραπεζικοί λογαριασμοί»). Νέο **`payments.bank_account_id`**
  («σε ποιον λογαριασμό μπήκαν τα χρήματα») σε ΚΑΘΕ φόρμα πληρωμής + στο έμβασμα
  (`PaymentAllocator`, ίδιος σε όλες τις γραμμές) μέσω κοινού `BankAccountField`
  (εμφανίζεται μόνο αν ο tenant έχει active λογαριασμό). Νέο
  **`invoices.bank_account_id`** (λογαριασμός κατάθεσης) στη φόρμα παραστατικού →
  **τυπώνεται στο PDF** («Λογαριασμός κατάθεσης: Τράπεζα — IBAN») για πληρωμή με
  έμβασμα. Πληροφοριακό — μηδέν αλλαγή στο money model (`InvoiceBalance`).
  `BankAccountTaggingTest`. **Deploy:** `php artisan migrate` + `shield:generate`
  (νέο resource permission).
- **Πληρωμές — κωδικός συναλλαγής (L1, `transaction_id`).** Προαιρετικό πεδίο σε
  ΚΑΘΕ φόρμα πληρωμής (cockpit τιμολογίου, ViewInvoice «Καταχώριση πληρωμής»,
  Καρτέλα «Πληρωμή έναντι λογαριασμού» + «Είσπραξη/Έμβασμα») για Stripe `pi_…` /
  PayPal txn / ref εμβάσματος τράπεζας. Στο έμβασμα (`PaymentAllocator`) μπαίνει
  **ίδιος σε όλες τις γραμμές** της ομάδας. Column (copyable) στο cockpit·
  audited. AR roadmap + deferred αποφάσεις: `docs/payments-ar-roadmap.md`.
  **Deploy:** `php artisan migrate`.
- **Πληρωμές — ομαδοποίηση εμβάσματος στην Καρτέλα (Φ3).** Τα `Payment` rows ενός
  εμβάσματος (κοινό `reference`) εμφανίζονται ως **ΜΙΑ γραμμή «Έμβασμα €X»** στην
  Καρτέλα κινήσεων, με **drill-down «Κατανομή»** (modal: ποια τιμολόγια πληρώθηκαν +
  τυχόν πίστωση/προκαταβολή). Το **running balance μένει αμετάβλητο** (credit =
  άθροισμα). Μεμονωμένες/legacy πληρωμές (χωρίς reference) μένουν ως έχουν. CSV/PDF
  statement δείχνουν τη σύνοψη κατανομής σε μία γραμμή (`ReceiptAllocationSummary`).
  Display-only — μηδέν αλλαγή σε `InvoiceBalance`/`PaymentObserver`/allocator.
- **Πληρωμές — Είσπραξη/Έμβασμα (Φ2, allocation).** Νέα action «Είσπραξη (έμβασμα)»
  στην Καρτέλα: ένα ποσό **κατανέμεται FIFO** (παλαιότερα ανοιχτά τιμολόγια πρώτα),
  το τελευταίο μπορεί να μείνει μερικώς πληρωμένο, και **ό,τι περισσέψει → on-account
  πίστωση/προκαταβολή**. `App\Services\Payments\PaymentAllocator` φτιάχνει απλά
  `Payment` rows (PaymentObserver recompute) με κοινό `payments.reference` (για
  ομαδοποίηση στη Φ3) — **μηδέν αλλαγή στο `InvoiceBalance`**. Καλύπτει €1200/€1500
  (μερική) και €2000/€1500 (όλα + €500 πίστωση). `PaymentAllocatorTest`. **Deploy:**
  `php artisan migrate`.
- **Πληρωμές — cockpit ανά τιμολόγιο (Φ1).** Νέο tab «Πληρωμές» στο invoice View
  (`InvoicePaymentsRelationManager`): λίστα πληρωμών + **Προσθήκη/Επεξεργασία/
  Διαγραφή**, quick **«Πλήρης εξόφληση»** (προ-συμπληρώνει το υπόλοιπο) + **«Μερική
  πληρωμή»** (warning σε υπερπληρωμή) + **«Σήμανση ως ανεξόφλητο»** (διαγράφει όλες
  τις πληρωμές → υπόλοιπο στο πλήρες — διορθώνει phantom πληρωμές π.χ. από import,
  όπως το ΤΙΜ385). Το money cache επανυπολογίζεται μόνο του (PaymentObserver). Μηδέν
  αλλαγή στο `InvoiceBalance`. Φ2 (έμβασμα σε πολλά τιμολόγια/on-account) ξεχωριστά.
  `InvoicePaymentsCockpitTest` (partial / overpaid / mark-unpaid).
- **Αποθήκη — αναστροφές ακύρωσης/πιστωτικού (S3).** Κλείνει ο κύκλος: όταν ένα
  τιμολόγιο **ακυρώνεται** (τοπικά ή myDATA CANCELLED → `local_status='cancelled'`)
  το stock-OUT της πώλησης **αναστρέφεται** (+ποσότητα πίσω, reason `cancel`,
  idempotent, μόνο για γραμμές που όντως κίνησε), και όταν ένα **πιστωτικό** γίνεται
  active καταγράφεται **επιστροφή** (+ποσότητα, reason `return`). Όλα best-effort
  στον `InvoiceObserver` (η αλλαγή status έχει ήδη γραφτεί — stock hiccup δεν
  εμφανίζεται ως ψεύτικη αποτυχία). **Supplier auto-είσοδος deferred** — τα expense
  lines δεν συνδέονται με προϊόντα (free-text)· η χειροκίνητη «Παραλαβή» (S2.6) το
  καλύπτει μέχρι να μπει βήμα matching. `StockSaleTest` (return + cancel-reverse +
  idempotent).
- **Αποθήκη — αναπαραγγελία, backorders, γρήγορη παραλαβή (S2.6).** (1) Per-product
  **όριο αναπαραγγελίας** (`products.reorder_level`): το badge «Απόθεμα» γίνεται
  **πορτοκαλί** όταν ≤ όριο/εξαντλημένο (κόκκινο σε αρνητικό), ώστε να ξέρεις τι
  να παραγγείλεις ΠΡΙΝ μηδενίσεις. (2) Filter **«Κατάσταση αποθέματος»** στη λίστα
  προϊόντων → «χρειάζεται αναπαραγγελία» / «αρνητικό (backorder)» (what you owe).
  (3) Row-action **«Παραλαβή»** κατευθείαν στη λίστα — καταχώριση εισόδου χωρίς
  να μπεις στην καρτέλα. `StockServiceTest` (filter SQL buckets, sqlite-safe via
  groupBy). **Deploy:** `php artisan migrate`.
- **Αποθήκη — ορατότητα (S2.5).** Το απόθεμα φαίνεται **τη στιγμή που κόβεις**:
  (α) στον picker προϊόντος του τιμολογίου → «· απόθεμα: N» (⚠ αν αρνητικό),
  (β) μη-μπλοκάρον warning στην **Οριστικοποίηση** αν κάποια γραμμή πάει αρνητικό
  («Σε αρνητικό: X (−1). Η έκδοση προχώρησε κανονικά — backorder»),
  (γ) μεγάλος αριθμός «Τρέχον απόθεμα» στην καρτέλα προϊόντος (⚠ σε αρνητικό).
  Read-only UI — ποτέ δεν μπλοκάρει (το −1 = backorder, by design).
- **Αποθήκη — auto έξοδος στην πώληση (S2, whichever-first).** Στο απόθεμα
  μειώνεται **−ποσότητα** αυτόματα όταν ένα τιμολόγιο γίνεται `active`
  (`InvoiceObserver`, μόνο `track_stock` goods· τα πιστωτικά εξαιρούνται = S3
  επιστροφή) ΚΑΙ όταν εκδίδεται **ΔΑΠ με σκοπό «Πώληση»** (μόνο move_purpose=1·
  ενδοδιακίνηση/σέρβις/φύλαξη ΔΕΝ μειώνουν). **Whichever-first dedup:** νέο
  προαιρετικό link `delivery_notes.invoice_id` («Σχετικό τιμολόγιο» στη φόρμα) —
  μια πώληση μετριέται ΜΙΑ φορά (αν το linked τιμολόγιο/δελτίο το κίνησε ήδη, το
  άλλο παραλείπει). Idempotent ανά source-line (re-finalize δεν διπλομετρά).
  `StockService::recordSaleForInvoice/recordSaleForDeliveryNote`· warn-only.
  `StockSaleTest`. **Deploy:** `php artisan migrate`.
- **Αποθήκη / απόθεμα — foundation (S1).** Opt-in stock tracking ανά προϊόν
  (`products.track_stock` — εμπορεύματα ναι, υπηρεσίες όχι· ό,τι δεν είναι tracked
  το αγνοεί ο μηχανισμός) + signed ledger `stock_movements` (τρέχον on-hand =
  SUM, **derived ποτέ cached** όπως το InvoiceBalance· auditable/reversible) +
  `App\Services\Stock\StockService` (current/record, **warn-only — ποτέ δεν
  μπλοκάρει πώληση**, επιτρέπει αρνητικό). UI: στήλη «Απόθεμα» στα Products
  (κόκκινο σε αρνητικό· «—» για μη-tracked· το legacy fractional `reserve`
  ξεχώρισε ως «Reserve (legacy)» για να μη μπερδεύεται) + tab «Κινήσεις
  αποθέματος» ανά προϊόν με χειροκίνητη «Καταχώριση κίνησης»
  (Παραλαβή/Αρχική απογραφή/Διόρθωση). Ledger append-only (διορθώνεις με νέα
  κίνηση). Επόμενα: S2 = auto-έξοδος (τιμολόγιο + ΔΑΠ-Πώληση, whichever-first με
  link/dedup)· S3 = auto-είσοδος προμηθευτή + αναστροφές ακύρωσης/πιστωτικού.
  `StockServiceTest`. **Deploy:** `php artisan migrate`.
- **2FA (TOTP) + root redirect.** Ενεργοποιήθηκε το ενσωματωμένο MFA του Filament:
  `User` υλοποιεί `HasAppAuthentication`(+`Recovery`), νέες encrypted-at-rest στήλες
  `app_authentication_secret`/`_recovery_codes`, και το panel
  `->multiFactorAuthentication([AppAuthentication::make()->recoverable()])`. Το
  enrollment (QR), τα recovery codes και disable/regenerate ζουν **αυτόματα** στη
  σελίδα προφίλ. **Opt-in** by default· `EKDOSI_REQUIRE_2FA=true` το επιβάλλει σε
  όλους στο επόμενο login (αφού πρώτα εγγραφούν). Το `/` πλέον redirect → `/admin`
  (δεν υπάρχει public landing). Οι MFA στήλες είναι `#[Hidden]` (να μην διαρρέουν
  σε serialization) + admin action **«Επαναφορά 2FA»** στη λίστα Users (recovery
  για χαμένη συσκευή — αλλιώς μόνιμο κλείδωμα). **Runbook:** μην κάνεις rotate το
  `APP_KEY` χωρίς να μηδενίσεις πρώτα τις 2 στήλες. **Deploy:** `php artisan migrate`.
  `TwoFactorAndRootTest`.
- **Deploy safety net (ρίζα: ένα `migrate:fresh`/test έσβησε κατά λάθος την prod).**
  Τρία επίπεδα ώστε να μην ξανασυμβεί: (1) `DB::prohibitDestructiveCommands()` στον
  `AppServiceProvider` μπλοκάρει `db:wipe`/`migrate:fresh`/`migrate:refresh`
  **παντού εκτός από το testing env** (δεμένο στο `environment('testing')`, ΟΧΙ
  στο `isProduction()`, γιατί το prod ήταν κατά λάθος `APP_ENV=local` — το απλό
  `migrate` δεν επηρεάζεται)· (2) `clean.sh` κάνει abort αν το backup είναι
  ύποπτα μικρό/καταρρέει (άδειο dump = ψεύτικη ασφάλεια· πιάνει σπασμένη βάση ΠΡΙΝ
  το migrate)· (3) `App\Support\Backup\MinimumBackupSizeInKilobytes` health-check
  μαρκάρει ένα σχεδόν-άδειο backup ως unhealthy. `MinimumBackupSizeHealthCheckTest`.
  **Προσοχή στο deploy host:** βάλε `APP_ENV=production` + `APP_DEBUG=false` στο
  `.env` και τρέξε `php artisan config:clear && php artisan config:cache`.
- **Διακίνηση — «Ιστορικό myDATA» στο δελτίο.** Το `DeliveryNoteResource` απέκτησε
  read-only relation manager (`DeliveryMarksRelationManager`) που δείχνει ΟΛΟΝ τον
  audit trail του δελτίου — INSERT (έκδοση), REGISTER_TRANSFER (έναρξη),
  CONFIRM_OUTCOME (παράδοση), CANCEL, **REJECTED** — με χρωματιστά badges + modals
  request/response XML ανά γραμμή. Πριν δεν φαινόταν πουθενά στο UI ο κύκλος ζωής.
### Fixed
- **«Επί Πιστώσει» έδειχνε ΟΛΑ τα τιμολόγια «Εξοφλημένα» χωρίς πληρωμή (root cause
  του «phantom payment» στο ΤΙΜ385).** Ο `MyDataLookupSeeder` έσπερνε ΟΛΕΣ τις
  μεθόδους πληρωμής με `due_days=0` — και το «Επί Πιστώσει» (§8.12 κωδ. 5). Με
  due_days=0 το `InvoiceBalance` τη θεωρεί cash-term → «εξοφλημένο στην έκδοση,
  paid=owed, balance 0, ΧΩΡΙΣ πληρωμή» (γι' αυτό 0 credit rows στην Καρτέλα· δεν
  υπήρχε πληρωμή να σβηστεί). Πλέον το seed δίνει στο «Επί Πιστώσει» **due_days=30**
  (credit term)· οι υπόλοιπες μένουν 0. Το `due_days` helperText έγινε ελληνικό +
  προειδοποιεί ρητά. **Υπάρχοντες tenants (το seed ΔΕΝ ξαναγράφει υπάρχοντα):**
  Setup → Payment Methods → «Επί Πιστώσει» → due_days>0, μετά
  `php artisan invoices:recompute-balances --company=SLUG` για να φρεσκάρει τα
  cached badges. `PaymentMethodCreditTermSeedTest`.
- **Εικόνα από myDATA — ΦΠΑ: ο μήνας κρίνεται με το ΔΙΚΟ του πρόσημο.** Στην κάρτα
  «Τρίμηνο — Καθαρό ΦΠΑ» η ένδειξη του μήνα δανειζόταν την ετικέτα/χρώμα του
  τριμήνου (`$quarter->isPayable()`) και δειχνόταν ως `abs()` — έτσι μια
  **πίστωση** μήνα (εκροών − εισροών < 0, π.χ. 0 − 9,70 = −9,70 όταν ο μήνας έχει
  μόνο έξοδα) διαβαζόταν ως «Προς απόδοση 9,70 €», σαν οφειλή. Πλέον ο μήνας
  παίρνει δική του λέξη «προς απόδοση / πίστωση / 0,00 €» (`monthVatLabel`)·
  η κάρτα κρατά ένα χρώμα (= το τρίμηνο, που είναι το headline). `MyDataPictureStats`.
- **Panel 403 σε production (λανθάνον — ξεσκεπάστηκε με τη διόρθωση του `APP_ENV`).**
  Το `User` δήλωνε μόνο `HasTenants`, ΟΧΙ το `FilamentUser` contract — οπότε το
  Filament επέτρεπε το panel μόνο σε `APP_ENV=local` και έβγαζε **403 σε
  production**. Δούλευε όλον τον καιρό μόνο επειδή το `.env` ήταν (λάθος) `local`·
  μόλις μπήκε σωστά `production`, 403 για όλους. Το `User` υλοποιεί πλέον
  `FilamentUser` με ρητό `canAccessPanel()` = «ανήκει σε ≥1 εταιρεία» (operators-only
  app· tenancy + Shield policies γκρινιάζουν τα υπόλοιπα). **Ορατότητα (το γυμνό
  403 δεν άφηνε ίχνος):** (α) το deny κάνει `Log::warning` με user/email/panel —
  greppable· (β) custom `errors/403` εξηγεί «δεν έχεις ανατεθεί σε εταιρεία —
  επικοινώνησε με διαχειριστή» + Αποσύνδεση· (γ) η λίστα Users δείχνει badge
  «χωρίς εταιρεία» + filter ώστε ο admin να πιάνει τους ορφανούς πριν κλειδωθούν.
  `PanelAccessTest`.
- **Διακίνηση (myDATA) — απορρίψεις ΑΑΔΕ φαίνονται στο UI.** Ο
  `DeliveryNoteSubmitter` γράφει πλέον forensic `delivery_marks` row
  (`mydata_action='REJECTED'`, null mark, με το response) σε απόρριψη, δίδυμο του
  invoice `recordRejection` — ώστε η απόρριψη να φαίνεται στο «Ιστορικό myDATA»
  του δελτίου (πριν surface-αρόταν μόνο στο CLI report του `sandbox-validate`).
- **Παραστατικά (myDATA) — απορρίψεις ΑΑΔΕ δεν χάνονται πια.** Όταν η ΑΑΔΕ
  απορρίπτει υποβολή τιμολογίου (status ≠ Success), ο `MyDataSubmitter` πετά
  πλέον `MyDataRejected` που κουβαλά το request+response XML ΚΑΙ γράφει μια
  forensic γραμμή `mydata_marks` (`mydata_action='REJECTED'`, χωρίς MARK) — ώστε
  ο χειριστής να βλέπει ΤΙ στάλθηκε και ΓΙΑΤΙ απορρίφθηκε από το «Ιστορικό
  myDATA» του παραστατικού, αντί να χάνεται το round-trip στο throw (πριν: bare
  RuntimeException μόνο με το μήνυμα). Το `mydata:test-submit` τυπώνει το
  request/response σε απόρριψη. Παράλληλο του `DeliveryNoteRejected` της
  διακίνησης. (`MyDataRejected`, `MyDataSubmitter::recordRejection`.)
- **Δελτίο Αποστολής / Ψηφιακή Διακίνηση (9.3) — sandbox-validated end-to-end
  στο AADE dev (2026-06-03).** Το `DeliveryNoteSubmitter` payload διορθώθηκε με
  βάση ζωντανές απορρίψεις: για τύπο 9.x η ΑΑΔΕ **απαγορεύει** `<isDeliveryNote>`,
  `<currency>` και `<thirdPartyCollection>false>` ([205]/[214]) και **απαιτεί**
  πλήρη ταυτοποίηση issuer + counterpart (name + address, [204]) — αντίθετα με
  τον κανόνα μονόδρομου τιμολογίου που τα κρύβει για GR. Πλέον περνά καθαρά όλη η
  αλυσίδα ΕΚΔΟΣΗ→ΕΝΑΡΞΗ→ΠΑΡΑΔΟΣΗ→ΕΛΕΓΧΟΣ (SendInvoices/RegisterTransfer/
  ConfirmDeliveryOutcome/RequestDeliveryNoteStatus).
- **`delivery_marks.mark_time` ήταν `timestamp` αντί `time`** (ο δίδυμος
  `mydata_marks.mark_time` είναι `time`) — έσκαγε το persist του MARK με
  «Incorrect datetime value '03:36:16'». Διορθώθηκε η migration + ALTER.
- **Report writer**: σε απόρριψη AADE, ο `DeliveryNoteSubmitter` πετά πλέον
  `DeliveryNoteRejected` που μεταφέρει request+response XML, ώστε το `.txt`
  report των `delivery:sandbox-validate`/`delivery:test-submit` να τα καταγράφει
  (πριν χάνονταν — η απόρριψη συμβαίνει πριν γραφτεί η `delivery_marks` row).

### Changed
- **Σαφήνεια «σημειώσεων» (εσωτερικές vs εκτυπώσιμες).** Το πεδίο `invoices.notes`
  (που ΕΚΤΥΠΩΝΕΤΑΙ στο PDF/email) ξαναβαφτίστηκε «Παρατηρήσεις (εκτυπώνονται στο
  παραστατικό)» με ρητό ⚠ helper που παραπέμπει στις εσωτερικές σημειώσεις — ώστε
  ένα «κακοπληρωτής» να μην καταλήξει στο χαρτί του πελάτη (form section + view
  infolist ευθυγραμμισμένα στη λέξη «Παρατηρήσεις», όπως ήδη ο τίτλος στο PDF).
  Στον πελάτη, το παλιό «ξερό» free-text tab «Σχόλια» (`customers.details`)
  **αφαιρέθηκε** υπέρ της πλουσιότερης καρτέλας «Σημειώσεις (εσωτερικές)»
  (χρονολογημένες, πολλαπλές, με συντάκτη).
- **`customers.details` → εσωτερικές σημειώσεις (3 φάσεις, idempotent).** Τα
  εισαγόμενα σχόλια ενοποιήθηκαν στο νέο σύστημα σημειώσεων: το `notes` απέκτησε
  πεδίο **`source`** (NULL=χειριστής, `backup`=από import)· νέα υπηρεσία
  `App\Services\Etl\BackupNoteSync` κάνει **upsert μίας** σημείωσης `source='backup'`
  ανά πελάτη (Epsilon `Remarks` / legacy `DETAILS`) — re-runnable χωρίς διπλότυπα,
  σβήνει τη σημείωση αν το σχόλιο αδειάσει στην πηγή, δεν αγγίζει τις χειροκίνητες.
  Μια **data migration** μετέφερε τα υπάρχοντα `details` και μετά η στήλη **έπεσε**
  (`dropColumn`). Στην Καρτέλα + στο tab οι imported σημειώσεις φέρουν badge «από
  backup» και είναι **read-only** (τις διαχειρίζεται το import). Ανθεκτικότητα:
  το sync χειρίζεται soft-deleted backup note (restore αντί για διπλότυπο), η
  drop migration **αρνείται** να ρίξει τη στήλη αν υπάρχει σχόλιο χωρίς backup note,
  και το rollback είναι **μη-καταστροφικό** (η `down` ξαναγράφει τα σχόλια στη
  στήλη πριν σβήσει τις σημειώσεις). **Deploy:** `php artisan migrate` (3 migrations:
  add `source` → migrate data → drop `details`).
- **myDATA consoles — ένα κουμπί «Έλεγχος» αντί για δύο** (έσοδα + έξοδα): οι δύο
  «κατευθύνσεις» (τα-δικά-μας vs αδέσποτα) έκαναν την ΙΔΙΑ κλήση
  (`SalesReconciler`/`ExpenseReconciler`) — τώρα ένα κουμπί κάνει ένα fetch και
  δείχνει **και τις δύο μαζί** συγκεντρωτικά (μισές κλήσεις AADE· καμία απώλεια
  πληροφορίας/bucket). Νέος **preset επιλογέας** διαστήματος (τρέχων μήνας /
  τρέχον τρίμηνο / προηγούμενο τρίμηνο / έτος / προσαρμογή) σε ημερολογιακά =
  φορολογικά όρια (`App\Filament\Pages\Concerns\ResolvesReconcileWindow`). Τα
  write actions των Εξόδων («Λήψη δικών μας εξόδων», «Καταχώριση αδέσποτων»)
  μένουν αυτούσια· το «Καταχώριση αδέσποτων» δεν εξαρτάται πια από mode. Μόνο
  pages + views — καμία αλλαγή στους reconcilers/δεδομένα. `ReconcileWindowPresetTest`
  + ενημερωμένα console tests.
### Added
- **Ψηφιακή Διακίνηση / Δελτίο Αποστολής — κύκλος ζωής διακίνησης (Phase D3, Β' φάση)**:
  `App\Services\Delivery\DeliveryLifecycleService` οδηγεί τον κύκλο ζωής ΠΑΝΩ σε ένα
  ήδη εκδομένο δελτίο — `registerTransfer` (RegisterTransfer, registered→in_transit,
  qrUrl-keyed, αποθηκεύει `transfer_mark`), `confirmDelivery` (ConfirmDeliveryOutcome,
  FULL/PARTIAL/NONE → delivered/partial/failed, `outcome_mark`), `refreshStatus`
  (RequestDeliveryNoteStatus by MARK + ΑΦΜ εκδότη, read-only §8.22→`delivery_state`
  reconcile, χωρίς νέα γραμμή mark) και `cancel` (μέσω `CancelInvoice` by issue MARK,
  όπως τα τιμολόγια — η provider-only CancelDeliveryNote δεν ισχύει στο ERP route).
  Καθρεφτίζει τον `DeliveryNoteSubmitter` (per-tenant `initFirebed`, MockHandler seam,
  try/catch→Greek RuntimeException, forceFill των guarded lifecycle στηλών + γραμμή
  `delivery_marks` σε transaction — actions REGISTER_TRANSFER/CONFIRM_OUTCOME/CANCEL).
  Header actions στο `ViewDeliveryNote` («Έναρξη διακίνησης» / «Δήλωση παράδοσης» με
  Select αποτελέσματος / «Έλεγχος κατάστασης (ΑΑΔΕ)» / «Ακύρωση») με visibility gates
  ανά state + Greek notifications· `delivery_state` badge με Greek label στο infolist.
  **ΔΕΝ έχει επικυρωθεί στο sandbox** (όπως όλο το 9.x/DGM μονοπάτι).
- **Ψηφιακή Διακίνηση / Δελτίο Αποστολής — εκτυπώσιμο PDF + QR (Phase D2.4)**:
  `App\Services\Delivery\DeliveryNotePdf` renders a Δελτίο Αποστολής to PDF bytes
  via DomPDF + `resources/views/delivery-notes/pdf.blade.php` — a value-LESS twin
  of the invoice PDF (no prices/VAT/totals; same DejaVu-Sans Greek font setup,
  A4 portrait, per-render ini guard, and `App\Support\MyData\QrImage` for the
  AADE QR). Εκδότης/Παραλήπτης (or «Ενδοδιακίνηση»), σκοπός/τόπος φόρτωσης→
  παράδοσης/μεταφορικό μέσο/όχημα/μεταφορέας, and a quantities-only lines table
  (μονάδα μέτρησης resolved via new `DeliveryCodes::measurementUnitLabel`, §8.13).
  The MARK + QR footer render only when filed (`mydata_state==='VALID'`); a draft
  shows «ΠΡΟΧΕΙΡΟ — μη διαβιβασμένο» and no QR. A «Εκτύπωση (PDF)» header action
  on `ViewDeliveryNote` streams `deltio-<invcode>.pdf` for both draft + filed
  notes (mirrors ViewInvoice's PDF action).
- **Ψηφιακή Διακίνηση / Δελτίο Αποστολής — data model (Phase D1)**: the schema
  for myDATA e-transport delivery notes. New `delivery_notes` /
  `delivery_note_lines` / `delivery_marks` tables — value-LESS twins of
  invoices/lines/marks (no money/VAT, kept in their own tables like quotes so
  they never touch InvoiceScope or the money services). Models `DeliveryNote` /
  `DeliveryNoteLine` / `DeliveryMark` (`BelongsToCompany`; the `mydata_*` cache +
  the lifecycle `*_mark`/`delivery_state` columns are guarded — written only via
  forceFill by the future submitter/lifecycle service). `App\Support\MyData\
  DeliveryCodes` wraps the firebed e-transport enums (σκοπός διακίνησης §8.14,
  τρόπος μεταφοράς, συσκευασία §8.23, κατάσταση §8.22) and bakes the AADE policy
  that move purposes **6/15/16/17/18 are no longer transmittable** (so 18
  «Διακίνηση Παγίων» is excluded — own-equipment moves use 8 Ενδοδιακίνηση or 19
  Λοιπές). Numbering reuses `InvoiceNumberer` unchanged (a delivery series is
  just a 9.x `invoice_types` row). No UI yet (D2 = submit+form, D3 = lifecycle).
  `DeliveryCodesTest` + `DeliveryNoteModelTest`. **Deploy:** `php artisan migrate`.
- **Ψηφιακή Διακίνηση — myDATA submitter (Phase D2, partial)**:
  `App\Services\Delivery\DeliveryNoteSubmitter` files a value-less Δελτίο
  Αποστολής (9.x) via the SAME `SendInvoices` path as invoices —
  `buildAadeDeliveryNote()` (Issuer + delivery `InvoiceHeader` with
  `isDeliveryNote=true` / `movePurpose` / dispatch / vehicle /
  `otherDeliveryNoteHeader` loading+delivery addresses + GR-rule recipient
  counterpart) + value-less lines (`quantity` + `measurementUnit` + `netValue=0`
  + `vatCategory=8` + `vatAmount=0`) + an all-zero `InvoiceSummary`;
  `previewXml()` for dry-run; `submit()` persists the MARK/qrUrl into the note's
  guarded cache (`mydata_*` + `delivery_state='registered'`) and a
  `delivery_marks` INSERT audit row, idempotent. Self-contained (the proven
  invoice submitter is untouched). Line/summary shape grounded in firebed's 9.3
  reference payload — **flagged for AADE sandbox validation** before go-live.
  `DeliveryNoteSubmitterTest` (build/previewXml + mock-Guzzle submit happy-path).
- **Ψηφιακή Διακίνηση — Filament resource + issue flow (Phase D2, part 3)**:
  `DeliveryNoteResource` (new nav group «Ψηφιακή Διακίνηση», truck icon,
  admin-gated on `View:DeliveryNote` + Company tenant, mirrors Reports/LedgerBook)
  with List/Create/View/Edit pages. The form wires the operator-guidance helpers
  end-to-end: a non-blocking exemption notice (`DeliveryGuidance::EXEMPTIONS_LEAD`
  + `EXEMPTIONS` + `INTRO`), a reactive «Τι θέλω να κάνω;» scenario picker
  (`scenarioOptions()` → fills `move_purpose` + the «Λοιπές» title; UI-only,
  `dehydrated(false)`), `move_purpose`/transport/packaging selects from
  `DeliveryCodes`, per-line measurement-unit from `Codes::QUANTITY_TYPES`, and
  `fieldHelp()` on every field. **Any-party recipient picker** searches BOTH
  customers AND suppliers (prefixed `c:`/`s:` keys) — a supplier recipient (e.g. a
  datacenter) snapshots `recipient_afm`/`recipient_name` and leaves `customer_id`
  null; a manual ΑΦΜ+name fallback covers parties in neither table; empty recipient
  = ενδοδιακίνηση. Mandatory addresses (loading + delivery), transport_type,
  vehicle_number, dispatch_at enforced in-form (last-line submitter guards
  unchanged). Numbering reuses `InvoiceNumberer` under a row lock in
  `CreateDeliveryNote` (identical to CreateInvoice); the type's `mydata_type`
  (9.x, default ΔΑΠ/9.3) is snapshotted at save. The View page's «Έκδοση»
  header action (draft-only) files via `DeliveryNoteSubmitter`. Edit limited to
  drafts. Added a `DeliveryNoteLine::saving` hook to auto-stamp `company_id` from
  the parent note (the Repeater relationship omits it). `DeliveryNoteResourceTest`
  (Livewire create→ΑΑ/draft/lines, required-field validation, issue-action
  draft-only visibility, mock-Guzzle submit→VALID+mark, recipient union search).
  **Deploy:** `php artisan shield:generate` so `View:DeliveryNote` exists.
- **Συνημμένα + εσωτερικές σημειώσεις (polymorphic).** Two reusable, tenant-safe
  tabs available on customers AND invoices (and any future model via a trait):
  - **Συνημμένα** (`attachments` table, `App\Models\Attachment`,
    `HasAttachments`) — upload files to a private disk with metadata + uploader
    audit; authenticated streamed download (never publicly served); force-delete
    removes the bytes, soft-delete keeps them. `AttachmentsRelationManager`.
  - **Σημειώσεις (εσωτερικές)** (`notes` table, `App\Models\Note`,
    `HasInternalNotes`) — operator-only notes that are **NEVER printed on the PDF
    and NEVER sent to AADE** (distinct from the printed `invoices.notes`); pinned
    notes float to the top; author + timestamp captured. `InternalNotesRelation
    Manager`. The Καρτέλα surfaces both contacts and pinned/recent internal notes
    read-only. **Deploy:** `php artisan migrate`.
- **Επαφές πελάτη (Customer contacts).** A customer can now hold multiple named
  contacts (λογιστήριο, τεχνικός, υπεύθυνος…) — each with ρόλος/τμήμα, τηλέφωνο,
  email, σημειώσεις, and an optional «Κύρια» flag (single-primary enforced on the
  model). Managed via a new «Επαφές» tab on the customer Edit page
  (`ContactsRelationManager`, stamps `company_id` like the other tenant-owned
  child managers) and surfaced read-only on the Καρτέλα (primary first). New
  `customer_contacts` table + `App\Models\CustomerContact`. **Deploy:**
  `php artisan migrate`.
- **Καρτέλα: «Συχνά προϊόντα/υπηρεσίες» + πλουσιότερο header.** A new panel on the
  customer Καρτέλα lists what the customer buys most (frequency, total qty, net
  spend, last-bought date) — aggregated from their LIVE sales lines (credit notes
  + cancelled excluded), product-linked or free-text (`App\Services\CustomerLedger\
  CustomerTopProducts`). The identity header now also surfaces fields we already
  store but never showed: τηλέφωνο/email, τρόπος πληρωμής, έκπτωση, σημειώσεις, and
  badges (Άμεση τιμολόγηση / Μεταπωλητής).
- **Λογαριασμοί (ΕΓΛΣ) + νέο group «Λογιστικά»** — a LIGHT, indicative Greek
  chart-of-accounts layer (`App\Support\Accounting\ChartOfAccounts`): the ΕΓΛΣ
  group accounts we reference + a default `category1_x`/`category2_x → account`
  map (70/71/73 income, 20/24/60/61/62/64/66/14 expense, 54 ΦΠΑ). The Βιβλίο
  Εσόδων-Εξόδων now shows a «Λογαριασμός» column (table + per-category subtotals
  + CSV/JSON/xlsx exports) derived from the myDATA category we already store. A
  read-only «Λογαριασμοί» page documents the chart + the mapping (clearly
  flagged INDICATIVE — the accountant's software does the definitive mapping;
  a per-tenant editable chart is a deferred follow-up). The book + λογαριασμοί
  now live in a dedicated «Λογιστικά» navigation group. Admin-gated on
  `View:Accounts` (run `shield:generate` + `shield:sync-super-admin` after
  deploy). `ChartOfAccountsTest` covers the mapping.
- **Βιβλίο Εσόδων-Εξόδων (απλογραφικά / Β' κατηγορίας)** — new read-only page
  «Βιβλίο Εσόδων-Εξόδων»: a chronological book of the tenant's invoices (έσοδα)
  + expenses (έξοδα), classified by the myDATA category we already store
  (`category1_x`/`category2_x` → Greek label via `Codes::e3CategoryLabel`),
  with period/book/category filters, per-category subtotals and the period
  totals (έσοδα, έξοδα, ΦΠΑ εκροών−εισροών). Pure read-model
  (`App\Services\Accounting\LedgerBook` → `LedgerBookResult`/`LedgerRow`) over
  the existing tables — no new persistence, no money/myDATA path change. Live
  scoping follows `InvoiceScope::live()` (sales) + "not AADE-cancelled"
  (expenses); credit notes are listed with NEGATIVE amounts so period sums are
  net. Never-issued drafts (`local_status='draft'` with no `legacy_id`) are
  excluded as not-yet-book-entries; legacy-imported drafts (`legacy_id` set, =
  real historical invoices) are kept. Admin-gated on `View:LedgerBook` (run `shield:generate` +
  `shield:sync-super-admin` after deploy). Full double-entry (γενική λογιστική)
  stays out — exports feed the accountant's software. `LedgerBookTest` covers
  signed credit notes / scoping / filters / totals.
- **Βιβλίο Εσόδων-Εξόδων — εξαγωγές (CSV / Excel / JSON)**: header «Εξαγωγή»
  group on the page renders the current (filtered) period in three formats via
  `App\Services\Accounting\LedgerBookExporter` — CSV (UTF-8 BOM + ';' + comma
  decimal, el-GR-Excel-friendly) and JSON are dependency-free; the **.xlsx**
  uses the already-present `openspout/openspout` (bold header, raw numeric
  amounts so Excel sums/sorts) — no PhpSpreadsheet/maatwebsite needed. All three
  emit the same table + a totals trailer (έσοδα/έξοδα/ΦΠΑ balance).
  `LedgerBookExporterTest` covers CSV/JSON shape + that the xlsx is a real
  workbook. (The accountant's Union import format — Phase C — still TBD.)
- **myDATA — «Άντληση/έλεγχος από ΑΑΔΕ» on ΜΑΡΚ detail**: for a local invoice
  imported with a MARK but no AADE QR (Epsilon/legacy), a live pull by MARK
  (`RequestTransmittedDocs`) now stamps the QR (`qrCodeUrl`) onto
  `invoices.mydata_url` (+ `mydata_marks.invoice_url`) so our reprinted PDF
  shows MARK **and** QR. The same call drives a field-by-field **comparison**
  popup/panel — what agrees (✓) and what differs (⚠) vs AADE. Policy: QR always
  (re)written, everything else **fill-blanks only** (never overwrites a
  populated value on a filed doc), differences reported not auto-applied
  (`App\Services\MyData\EnrichInvoiceFromAade`, `App\Support\MyData\QrImage`,
  `MarkDetail`/`TransmittedDocReader` now carry `qrCodeUrl`). Gated on
  `View:MyDataConsole` (live AADE call). ORPHAN→create-local still deferred.
- **Data Import — Epsilon Smart Sales → invoices** (Phase 2): the «Epsilon
  Smart (JSON)» tab gains a Πωλήσεις (`DataExport-Sales.json`) slot. Each Epsilon
  sale lands as a historical, already-filed invoice — `active` + `mydata_state=
  VALID` + the MARK (leading apostrophe stripped) + a `mydata_marks` audit row
  (`mydata_action=INSERT`, so cancel/credit-note correlation finds it). Lines,
  invoice header and the MARK are written via raw query-builder inserts (mirrors
  the Firebird ETL) so the FILED net/gross values are kept verbatim — the
  `InvoiceLine` recompute hook is bypassed (it would drift by rounding and throw
  on zero-qty lines). Counterpart resolved by ΑΦΜ (resolve-or-create), product
  matched by name, the Epsilon DocNum kept as the ΑΑ, the invoice-type counter
  bumped so new ekdosi invoices continue. **Settled on import:** credit-term
  («Επί Πιστώσει») sales get a full settlement Payment dated at issue so the
  already-paid historical docs aren't phantom receivables; cash-term settle at
  issue via `InvoiceBalance`. Re-runnable by `(company_id, invcode)`; a re-run
  refreshes the header and replaces lines + mark + the settlement payment
  (`EpsilonImporter::importSales`). Known limitations: no AADE QR on the PDF
  (Epsilon exports the UID but not the QR URL); per-line E3 classification not
  stored (derived at submit, as elsewhere); non-GR counterpart defaults to GR.
### Added
- **Data Import — Epsilon Smart (JSON)** (Phase 1): the «Firebird Import» screen
  is renamed «Data Import» and gains a 2nd tab. The Firebird flow is unchanged
  (its own tab); the new «Epsilon Smart (JSON)» tab imports the Τιμολόγηση
  exports — **Customers** (match by ΑΦΜ) and **Items/Services → products** (VAT
  from the Epsilon class, unit, category; WhosalePrice as the net sell price).
  Re-runnable upsert by natural key; resolves against the standard AADE lookups
  the seeder installs (`App\Services\Etl\EpsilonImporter`). Runs synchronously
  (the exports are tiny). Sales→invoices is a planned Phase 2.
### Fixed
- **Data Import no longer 500s when an upload fails to persist.** If a uploaded
  file silently vanished before Filament saved it (temp-dir pruning, storage
  perms, or a request over php.ini's upload/post limits), every file field came
  back empty and `CreateFirebirdImportRun` fell through to the Firebird branch,
  crashing on `Storage::disk('local')->path(null)` (opaque flysystem TypeError).
  It now halts with an actionable Greek notification («Δεν ελήφθη κανένα αρχείο…»)
  and the Epsilon importer checks each staged path exists before reading.
- **Import View page no longer 500s on Epsilon counts.** The «Imported rows»
  infolist assumed the flat Firebird `table => int` shape and crashed on
  `number_format(array)` for the Epsilon importer's nested
  `entity => ['created','updated','skipped']` — so the View page died right after
  a successful Epsilon import. It now renders both shapes (nested → «+N νέα · ~N
  ενημ. · N παράλειψη»).

## 2026-06-02
### Added
- **Fresh-install seeding** (PR #152): a new Greek/myDATA tenant auto-installs
  the standard AADE lookups — VAT categories (§8.2) + invoice types pre-classified
  by-the-book (mydata_type + E3 income class + category) + payment methods (§8.12),
  distribution aims, metric units, delivery methods, product categories. Idempotent
  «Εισαγωγή τυπικών» buttons on each Setup list; runs on UI Create-Company +
  `db:seed`.
- **Tags** (PR #150): tenant-scoped tags on customers / suppliers / products /
  invoices / expenses — multi-select filter + Έξοδα-style fast-filter tabs (count
  badges) + bulk attach; managed via a `TagResource`.
- **Favourites-first pickers** (PR #150): `is_favorite` ⭐ on invoice types /
  customers / products; the invoice + quote pickers show favourites then most-used
  on open. Inline product create, Είδος→dimensions auto-fill, «Νέο Παραστατικό»
  from the customer Καρτέλα, full Greek labels on the invoice form.
### Changed
- ETL `copyLookup` now ADOPTS a pre-seeded lookup row by its natural key (stamps
  its legacy_id) so a re-import converges on one row instead of duplicating the
  seeded «Μετρητά» / «24%» / «ΤΕΜ» (PR #152).
### Removed
- Inert legacy `mailed` / `printed` / `email_sent` flags on invoices (PR #151).

## History
Earlier changes (tenancy/auth, customers + Καρτέλα, invoices + VAT math + QR/PDF,
myDATA submit/cancel/reconcile, payments + credit notes, Έξοδα/Ε3, Quotes, VIES,
roles, activitylog, the WHMCS bridge A–B3 + timologia v2) predate this file —
see `docs/CLAUDE-history.md`, `docs/Comparison.md`, and git history.
