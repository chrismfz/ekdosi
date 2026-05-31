# Σύγκριση: Παλιό (legacy) vs Νέο ekdosi

Σύγκριση της παλιάς εφαρμογής **C++Builder (VCL) + Firebird** (Windows desktop)
με το νέο **Laravel 13 + FilamentPHP 5 + MariaDB** (web).

> Πηγές: `legacy/ekdosi-schema.sql`, `legacy/ekdosi-main/` (~45 φόρμες C++),
> `legacy/whmcs/` (5 plugins), `CLAUDE.md`, `docs/whmcs-legacy-plugin-map.md`,
> `docs/expenses-phase-plan.md`.

**Υπόμνημα:** ✅ καλύτερο/νέο · ➡️ ίδια λειτουργία (portαρισμένο πιστά) ·
🆕 δεν υπήρχε καθόλου στο legacy · 🚧 σε εξέλιξη/planned · ❌ καταργήθηκε σκόπιμα.

---

## 0. Με μια ματιά

| Τομέας | Legacy | Νέο | |
|---|---|---|---|
| Πλατφόρμα | Windows desktop, μόνο σε εύθραυστο Win7 VM | Web — από οποιονδήποτε browser | ✅ |
| Βάση | Firebird, **ένα DB ανά εταιρεία** | MariaDB **multi-tenant** (`company_id`) | ✅ |
| Χρήστες/ρόλοι | Ουσιαστικά single-user | Πολλοί χρήστες + ρόλοι/δικαιώματα (Shield) | 🆕 |
| Διεπαφή | VCL φόρμες | Filament panel, responsive, dark mode, ελληνικό UI | ✅ |
| Εκτυπώσεις | FastReport 3 (`.fr3`) | PDF μέσω Blade/dompdf | ➡️/✅ |
| myDATA | `CMyData.cpp` (χαμένο), προ-myDATA ΕΑΦΔΣΣ | `firebed/aade-mydata`, ζωντανός συγχρονισμός + reconciliation | ✅ |
| WHMCS | 5 plugins + MySQL mirror push | Ενοποιημένο `ekdosi_bridge`, PHP-to-PHP API, **draft-first** inbox | ✅ |
| Ορατότητα WHMCS | Κρυφή στήλη `invoiced` μόνο | Badge+ΜΑΡΚ, badge λίστας, «Αποστολή στο Ekdosi», **3-way map** (WHMCS#→ΤΠΥ→ΜΑΡΚ), **συγκεντρωτική λίστα** (περίοδος/Είδος/Τρίτος) — plugin v0.12.0 | 🆕 |
| Reconciliation | Καμία | Τοπικό + ζωντανό (πωλήσεις & έξοδα), ομαδοποίηση αδέσποτων | 🆕 |
| Έξοδα/Ε3 | Καμία | Προμηθευτές, RequestDocs, classification, ΦΠΑ, Ε3 | 🆕 |
| Προσφορές (quotes) | Καμία | Μη-νομικό sales offer σε ξεχωριστούς πίνακες + μετατροπή σε παραστατικό | 🆕 |
| Έλεγχος ΑΦΜ/ΦΠΑ | Μόνο GR (afm2name) | GR→GSIS native + **EU→VIES** (επαλήθευση/άντληση) + reverse-charge hint | 🆕 |
| Πολλές χώρες | Όχι (μόνο ΕΛ) | Multi-country από την αρχή (ΕΛ myDATA + ΕΕ PEPPOL stub) | 🆕 |

---

## 1. Πλατφόρμα & Αρχιτεκτονική
**Legacy:** μονολιθική desktop εφαρμογή που χτιζόταν μόνο σε ένα συγκεκριμένο
Windows 7 VM (η αποδέσμευση από αυτό το toolchain ήταν όλος ο λόγος του port).
Ξεχωριστή Firebird βάση ανά εταιρεία· τα PKs συγκρούονταν μεταξύ εταιρειών.

**Νέο:** μία web εφαρμογή, μία MariaDB **multi-tenant** (κάθε πίνακας έχει
`company_id`), ένα Filament panel με εναλλαγή εταιρείας (tenant switching).
Surrogate PKs + `legacy_id` (μοναδικό ανά εταιρεία) για audit + επαναλήψιμο ETL.
**✅ / 🆕**

---

## 2. Διεπαφή & εμπειρία χρήστη (UX)
**Legacy:** στατικές VCL φόρμες (`FShow*`, `FManage*`, `FAdd*`), εκτυπώσεις
FastReport.

**Νέο (✅ σαφώς καλύτερο):**
- Filament panel — responsive, dark mode, καθαρά **ελληνικό** operator UI.
- Πίνακες με **αναζήτηση, ταξινόμηση, φίλτρα, pagination, εξαγωγή, toggle στηλών**.
- **Πλήρης χρήση μεγάλων οθονών** (οι λίστες χρησιμοποιούν όλο το πλάτος — *PR #69*).
- **Καρτέλα Πελάτη** ανανεωμένη: KPI widgets, aging, **σύγκριση έτους-με-έτος (YoY)**,
  διάγραμμα υπολοίπου/εσόδων, ledger κινήσεων με links, εξαγωγή **PDF/CSV** και
  **αποστολή στο email** (*PR #65*).

---

## 3. Παραστατικά / Τιμολόγηση
**Legacy:** `FAddInvoice`/`FAddInvoice2`/`FEditInvoice` — η πραγματική λογική
ΦΠΑ/εκπτώσεων/στρογγυλοποίησης (`showSums`/`calcPrices`).

**Νέο:**
- ➡️ Η μαθηματική λογική ΦΠΑ portαρίστηκε **ακριβώς** (`RecomputeInvoiceTotals` +
  `InvoiceVatBreakdown`).
- ➡️ Αρίθμηση συνεχόμενη ανά τύπο (όπως τα legacy triggers), αλλά πλέον με
  **row-lock σε transaction** (`InvoiceNumberer`) — ασφαλές σε ταυτόχρονη χρήση.
- ✅ **QR** + **PDF** (Blade/dompdf αντί FastReport).
- ✅ **Δύο ορθογώνιες καταστάσεις** (`local_status` vs `mydata_state`) — ποτέ
  μπερδεμένες· ένα predicate (`InvoiceScope::live()`) σε όλα τα σημεία χρημάτων.
- ✅ **Πιστωτικά** (`IssueCreditNote`): καθαρή υλοποίηση — στο legacy το
  `CREATE_RETURN_INVOICE` ήταν **άδειο stub**.

---

## 4. myDATA (ο πυρήνας)
**Legacy:** `CMyData.cpp` (χαμένος κώδικας), και προ-myDATA **ΕΑΦΔΣΣ**
(`EAFDSS_SCRIPT`). `FShowMyData` / `FShowMyDataRemainingInvoices` = ουρά
υποβολής **μόνο** για εκδόσεις.

**Νέο (✅ μεγάλο άλμα):**
- Υποβολή/ακύρωση/dry-run μέσω `firebed/aade-mydata` (`MyDataSubmitter`),
  **sandbox-validated** για τους τύπους του myip (1.1, 2.1, 11.2, 5.1 + CANCEL).
- **`mydata_marks` = source of truth** (πλήρες XML αιτήματος/απάντησης, νομικό audit).
- 🆕 **Ζωντανός συγχρονισμός με ΑΑΔΕ** (`RequestTransmittedDocs`) + **δύο
  reconciliation**: τοπικό (Phase 1) και ζωντανό (Phase 2, `SalesReconciler`).
- 🆕 **«Αδέσποτα από myDATA»**: η ανάποδη ματιά — παραστατικά που έχει η ΑΑΔΕ
  αλλά λείπουν τοπικά (π.χ. e-τιμολόγιο/άλλο πρόγραμμα), **ομαδοποιημένα ανά
  οικονομική φύση** (έσοδα/έξοδα/λοιπά) ώστε μια μισθοδοσία €5k να μη φαίνεται
  σαν χαμένη πώληση. Οι κονσόλες **κρατούν cached** το τελευταίο fetch.
- 🆕 **Σελίδα ΜΑΡΚ**: δείχνει εκδότη/κατεύθυνση (προμηθευτής vs εμείς· «λιανική —
  δεν δηλώνεται» όταν το myDATA δεν δίνει εκδότη) + τον χαρακτηρισμό **E3** ανά
  γραμμή (το myDATA δεν στέλνει περιγραφή).
- 🆕 `mydata:preflight` — READ-ONLY έλεγχος ρυθμίσεων vs πίνακες κωδικών §8.
- ✅ **Έξοδα/ΦΠΑ εισροών** (`RequestDocs`/Ε3) — **υλοποιημένα** (προμηθευτές,
  reconciliation εξόδων, classification, ΦΠΑ εκροών−εισροών, Ε3 με διαχωρισμό
  εσόδων/εξόδων)· δεν υπήρχε **τίποτα** στο legacy. Βλ. `docs/expenses-phase-plan.md`.
- 🆕 **Self-declared έσοδα/έξοδα + αυτόματη είσοδος από myDATA**: εισαγωγή με ένα κλικ των **αδέσποτων** παραστατικών που έχει η ΑΑΔΕ αλλά λείπουν τοπικά (`ExpenseImporter`/`ExpenseReconciler` πάνω στο `RequestDocs`), **plus** εισαγωγή των **δικών μας** self-declared εξόδων (αποδείξεις/μισθοδοσία/ΔΕΚΟ/VIES) και του **χαρακτηρισμού E3 ανά γραμμή** απευθείας από τα myDATA docs. Τα πιστωτικά εξόδων αφαιρούνται σωστά από το ΦΠΑ εισροών (δεν το φουσκώνουν).

---

## 5. WHMCS γέφυρα
**Legacy:** 5 ξεχωριστά plugins + push σε MySQL mirror (`FMysqlSync`) — εύθραυστο
shared-DB:
- `afm2name` (GSIS lookup), `prepare_for_ekdosi` (flag «τιμολογήθηκε»),
  `timologia` (τιμολόγηση σε τρίτους/resellers), `transfer_invoice` +
  `relid_remover` (χειροκίνητος διαχωρισμός παραστατικών).

**Νέο (✅ — και πλέον πολύ πέρα από το legacy):**
- Ένα ενοποιημένο plugin **`ekdosi_bridge`** + **PHP-to-PHP μέσω WHMCS API**
  (όχι shared-DB), με **HMAC** υπογραφές στα webhooks.
- **Inbox model** (operator-gated), **draft-first**: WHMCS → webhook →
  `pending_whmcs_invoices` → ο χειριστής πατά **«Δημιουργία Παραστατικού»**
  (editable draft) → διορθώνει γραμμές → εκδίδει μέσω του κανονικού lifecycle.
  Δεν εκδίδει πια κατευθείαν στην ΑΑΔΕ (ασφαλέστερο — έλεγξε το πραγματικό
  παραστατικό πρώτα). Δείχνει την **πρόθεση πελάτη** (τιμολόγιο/απόδειξη,
  ΑΦΜ/ΔΟΥ, «λείπει ΑΦΜ» → αναμονή) από τα WHMCS custom fields.
- **Αμφίδρομη ορατότητα (operator)**: ο WHMCS χειριστής βλέπει κατάσταση ΑΑΔΕ
  **χωρίς να φύγει από το WHMCS** — badge με το πραγματικό **ΜΑΡΚ** στο τιμολόγιο,
  badge στη **λίστα** τιμολογίων, κουμπί **«Αποστολή στο Ekdosi»**, και
  **3-way map** ανά πελάτη (WHMCS # → ekdosi ΤΠΥ → ΜΑΡΚ, με τα προσχέδια ορατά).
  Το legacy εξέθετε μόνο μια κρυφή αριθμητική στήλη `invoiced`.
- ✅ **timologia v2 / τιμολόγηση σε τρίτους** (T-1 + T-2): resolution,
  single-party billing, **πολλαπλοί δικαιούχοι → block + guided split** (το
  legacy τους **ανακάτευε σιωπηλά** σε ένα παραστατικό — νομικό λάθος). Το
  inbox + η λίστα του plugin δείχνουν το **όνομα δικαιούχου**· admin μπορεί να
  **διορθώσει δρομολόγηση** (CS-side).
- ❌ `afm2name` καταργήθηκε (το ekdosi κάνει GSIS native — `AadeRegistryLookup`),
  με **«Διόρθωση από ΑΑΔΕ»** (overwrite — το μητρώο είναι η πηγή αλήθειας).

**Live-deploy hardening (2026-05-31, prod-verified):**
- 🐞→✅ **Inbox paging:** το WHMCS `GetInvoices` σελιδοποιεί με
  `limitstart/limitnum` (όχι `limit/offset` — αγνοούνταν σιωπηλά) → το inbox
  κολλούσε σε 1 σελίδα (16 αντί 146). Διορθώθηκε + loop guard.
- 🐞→✅ **ΦΠΑ:** ο mapper **αναγνωρίζει** net/gross από το payload του
  τιμολογίου (`subtotal/tax/taxrate/total`) αντί να μαντεύει — τέλος το
  «πετσόκομμα» τιμής σε tax-exclusive tenant.
- ✅ **Ορατότητα με ΑΦΜ:** ο ιστορικός σύνδεσμος WHMCS→ΤΠΥ δεν σώθηκε ποτέ στη
  μετάπτωση· η αντιστοίχιση γίνεται με ΑΦΜ (ο heuristic content-matcher χτίστηκε
  και **αφαιρέθηκε** — δεν μαντεύουμε νομικό σύνδεσμο).

---

## 6. Πελάτες & Καρτέλα
**Legacy:** `FShowCustomers`/`FShowBalance`/`FShowNewBalance`,
`GET_CUSTOMER_BALANCE` (μόνο όροι πίστωσης μετράνε).

**Νέο:**
- ➡️ Η λογική υπολοίπου portαρίστηκε (όροι πίστωσης, μετρητοίς settled-at-issue).
- ✅ **GSIS lookup** native + «Διασταύρωση ΑΦΜ με ΑΑΔΕ» (αντικαθιστά το `afm2name`).
- 🆕 **VIES (EU)**: για μη-Ελληνικά ενδοκοινοτικά ΑΦΜ, επαλήθευση + άντληση επωνυμίας/διεύθυνσης από την υπηρεσία VIES της ΕΕ (`ViesLookup`, REST). GR→GSIS, EU→VIES — καθαρός διαχωρισμός. Στο τιμολόγιο, hint για **ενδοκοινοτική παράδοση / reverse charge** (0% + §8.3 αιτία «16 — άρθρο 45»).
- ✅ Πλούσια **Καρτέλα** (βλ. §2): ledger, aging, YoY, charts, export/email.

---

## 7. Άμεση τιμολόγηση (griniaris / «γκρινιάρης»)
**Legacy:** `FAutoInvoice` = overnight batch (ουρά myDATA / WHMCS pull / -333 /
griniaris μέσω field 338).

**Νέο:** ✅ καλύτερη διαχείριση παραστατικών μέσω inbox + lifecycle.
🚧 Το flag `needs_immediate_invoice` (στήλη + φίλτρο/badge στους Πελάτες) **υπάρχει**·
το αυτόματο trigger «τιμολόγησε αμέσως μόλις πληρωθεί» περιμένει τον **live
scheduler + queue worker** να ενεργοποιηθούν στον deploy host (G8).

---

## 8. Πληρωμές & χρήματα
**Legacy:** `FAddPayment`, `PAYMENT`.

**Νέο:** ✅ `Payment` model (per-invoice ή έναντι λογαριασμού), **`InvoiceBalance`
ως μοναδική πηγή** για paid/credited/balance/status (cache στήλες γράφονται μόνο
από αυτό), έλεγχος συνέπειας dashboard ↔ ledger ↔ caches με test.

---

## 9. Migration / ETL (🆕 — δεν υπήρχε)
- `php artisan migrate:firebird` — **επαναλήψιμο** ETL, μία εταιρεία ανά run,
  με upsert σε `(company_id, legacy_id)`, χειρισμό **WIN1253**, UI εισαγωγής
  (`.fdb`/`.fbk`). Το παλιό app γίνεται read-only αρχείο μετά το cutover.

---

## 9.5 Προσφορές / Quotes (🆕 — δεν υπήρχε)
Μη-νομικό sales offer (ΔΕΝ φιλιάρεται myDATA, ΔΕΝ μετράει σε χρήματα/καρτέλα/ΦΠΑ):
- **Ξεχωριστοί πίνακες** (`quotes`/`quote_lines`/`quote_mail_logs`) — μηδενικό blast
  radius στο money-path· regression test ότι δεν διαρρέει στο `InvoiceScope::live()`.
- Γραμμές: προϊόν/υπηρεσία **ή** ελεύθερο κείμενο **ή** inline-create προϊόντος.
- Lifecycle: Πρόχειρη → Απεσταλμένη → Αποδεκτή/Απορριφθείσα + **Μετατροπή σε πρόχειρο
  παραστατικό** (αμφίδρομο ιστορικό quote↔invoice, χωρίς στήλη στον νόμιμο πίνακα).
- Δικός counter `ΠΡ-{n}` (`companies.quote_counter`) — **ποτέ** το νόμιμο ΑΑ.
- **PDF** (fork του invoice renderer, χωρίς QR/MARK) + **email** + **send-log** (queue job).
- Παρακολούθηση: «ισχύει έως» (λήξη προσφοράς) + «λήξη υπηρεσίας» (χειροκίνητη
  παρακολούθηση ανανέωσης μέχρι να μπει το recurring engine). **Independent-reviewed.**

---

## 10. Λοιπά νέα που δεν υπήρχαν στο legacy (🆕)
- **Multi-tenant** + **multi-country** (ΕΛ myDATA + ΕΕ PEPPOL stub).
- **Ρόλοι/δικαιώματα** ανά εταιρεία (Shield: `admin`/`operator`/`accountant_readonly`).
- **Dashboard + widgets/charts** (έσοδα/μήνα, σύγκριση ετών, top πελάτες, ΦΠΑ).
- **Audit trail** πλήρους XML (`mydata_marks`).
- **Backups** (`spatie/laravel-backup`), **scheduler** (wired).
- **Email παραστατικών** per-tenant + send-log· **αποστολή Καρτέλας** στο email.

---

## 11. Καταργήθηκαν σκόπιμα (❌ — δεν τα ξανακάνουμε)
- **CS-Cart bridge** (`FCSConnect`/`FManageCS*`, `CUSTCS_LINK`) — δεν χρησιμοποιήθηκε ποτέ.
- **ΕΑΦΔΣΣ** (`EAFDSS_SCRIPT`) — προ-myDATA, ξεπερασμένο.
- **FastReport** (`.fr3`) → Blade PDF.
- **`FMysqlSync`** (MySQL mirror push) → WHMCS API.
- **`GET_COMB_*`** (cross-DB `EXECUTE STATEMENT` με SYSDBA/masterkey inline) —
  **κίνδυνος ασφαλείας**, δεν μεταφέρθηκε.
- **`afm2name`** (WHMCS GSIS plugin) → native GSIS στο ekdosi.

> Σημείωση ειλικρίνειας: **stock/αποθήκη** και **ΣΔΕΠ/σωρευτικά** φαίνονται σαν
> «κενά» αλλά ήταν **νεκρός κώδικας στο legacy** (`CHECK_PROD_AVAILABILITY` άδειο,
> `findCumInvoiceDate` επιστρέφει 0). Δεν είμαστε «πίσω» — απλώς δεν υπήρχαν.

---

## 12. Σε εξέλιξη / planned (🚧)
- ~~**Έξοδα / Προμηθευτές + ΦΠΑ εισροών–εκροών + Ε3**~~ ✅ **DONE** (E0–E7 + self-declared import + per-line E3)· βλ. `docs/expenses-phase-plan.md`.
- ~~**Προσφορές / Quotes**~~ ✅ **DONE** (βλ. §9.5)· **Υπηρεσίες/Συμβόλαια (recurring)** σχεδιασμένο, όχι υλοποιημένο.
- **Διορθώσεις myDATA filing**: ✅ G1 παρακράτηση, ✅ G4 0%/απαλλαγή· 🚧 G3
  tax-inclusive WHMCS, G9 τρόπος πληρωμής→myDATA, G5 ποσότητα για αγαθά,
  G7 gross-edit, G6 auto-email στο non-myDATA path.
- **griniaris** άμεση τιμολόγηση (περιμένει live scheduler/worker).
- **PEPPOL** submitter (Εσθονία) — stub μέχρι την προθεσμία.
- **activitylog** σε invoices/customers/payments (installed, όχι wired).

---

## 13. Σύνοψη
Το νέο ekdosi **δεν είναι απλό port — έχει ξεπεράσει αποφασιστικά το legacy.**
Διατηρεί πιστά την κρίσιμη λογική (ΦΠΑ, αρίθμηση, υπόλοιπα) και προσθέτει
ολόκληρες **νέες κατηγορίες** που το legacy δεν είχε: web/multi-tenant/
multi-country αρχιτεκτονική, ζωντανό συγχρονισμό & **reconciliation πωλήσεων ΚΑΙ
εξόδων** με ΑΑΔΕ (με ομαδοποίηση αδέσποτων), πλήρη **φάση Εξόδων/ΦΠΑ/Ε3**, μια
**draft-first WHMCS γέφυρα με αμφίδρομη ορατότητα** (ο WHMCS χειριστής βλέπει
ΜΑΡΚ + 3-way αντιστοίχιση, ακόμη και προσχέδια), τιμολόγηση σε τρίτους με
ασφαλή διαχωρισμό, πολύ καλύτερη διεπαφή/Καρτέλα, ρόλους, dashboard, αυτόματα
backups πριν κάθε deploy και operator-friendly ασφάλεια παντού (dry-run,
preflight, δύο ορθογώνιες καταστάσεις, golden tests). Παράλληλα πέταξε με
ασφάλεια ό,τι ήταν νεκρό ή επικίνδυνο στο legacy. **Πρακτικά: το legacy είναι
πλέον το read-only αρχείο· το νέο app είναι το σύστημα καταγραφής.**
