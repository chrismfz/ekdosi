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
| WHMCS | 5 plugins + MySQL mirror push | Ενοποιημένο `ekdosi_bridge`, PHP-to-PHP API, inbox | ✅ |
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
- 🆕 **«Αδέσποτα από myDATA»** (*PR #71*): η ανάποδη ματιά — παραστατικά που έχει
  η ΑΑΔΕ αλλά λείπουν τοπικά (π.χ. e-τιμολόγιο/άλλο πρόγραμμα).
- 🆕 `mydata:preflight` — READ-ONLY έλεγχος ρυθμίσεων vs πίνακες κωδικών §8.
- 🚧 **Έξοδα/ΦΠΑ εισροών** (`RequestDocs`/`RequestVatInfo`/Ε3) — blueprint έτοιμο
  (`docs/expenses-phase-plan.md`)· δεν υπήρχε **τίποτα** στο legacy.

---

## 5. WHMCS γέφυρα
**Legacy:** 5 ξεχωριστά plugins + push σε MySQL mirror (`FMysqlSync`) — εύθραυστο
shared-DB:
- `afm2name` (GSIS lookup), `prepare_for_ekdosi` (flag «τιμολογήθηκε»),
  `timologia` (τιμολόγηση σε τρίτους/resellers), `transfer_invoice` +
  `relid_remover` (χειροκίνητος διαχωρισμός παραστατικών).

**Νέο (✅):**
- Ένα ενοποιημένο plugin **`ekdosi_bridge`** + **PHP-to-PHP μέσω WHMCS API**
  (όχι shared-DB), με **HMAC** υπογραφές στα webhooks.
- **Inbox model** (operator-gated): WHMCS → webhook → `pending_whmcs_invoices` →
  ο χειριστής ελέγχει → καταχώρηση στην ΑΑΔΕ → write-back. (Τα παραστατικά είναι
  νομικά σημαντικά — δεν εκδίδονται αυτόματα.)
- ✅ **timologia v2 / τιμολόγηση σε τρίτους** (T-1 + T-2, merged): resolution,
  single-party billing, multi-party guided split, flagging resellers.
- ❌ `afm2name` καταργήθηκε (το ekdosi κάνει GSIS native — `AadeRegistryLookup`).

---

## 6. Πελάτες & Καρτέλα
**Legacy:** `FShowCustomers`/`FShowBalance`/`FShowNewBalance`,
`GET_CUSTOMER_BALANCE` (μόνο όροι πίστωσης μετράνε).

**Νέο:**
- ➡️ Η λογική υπολοίπου portαρίστηκε (όροι πίστωσης, μετρητοίς settled-at-issue).
- ✅ **GSIS lookup** native + «Διασταύρωση ΑΦΜ με ΑΑΔΕ» (αντικαθιστά το `afm2name`).
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
- **Έξοδα / Προμηθευτές + ΦΠΑ εισροών–εκροών + Ε3** — blueprint
  (`docs/expenses-phase-plan.md`).
- **Διορθώσεις myDATA filing**: ✅ G1 παρακράτηση, ✅ G4 0%/απαλλαγή· 🚧 G3
  tax-inclusive WHMCS, G9 τρόπος πληρωμής→myDATA, G5 ποσότητα για αγαθά,
  G7 gross-edit, G6 auto-email στο non-myDATA path.
- **griniaris** άμεση τιμολόγηση (περιμένει live scheduler/worker).
- **PEPPOL** submitter (Εσθονία) — stub μέχρι την προθεσμία.
- **activitylog** σε invoices/customers/payments (installed, όχι wired).

---

## 13. Σύνοψη
Το νέο ekdosi **δεν είναι απλό port**: διατηρεί πιστά την κρίσιμη λογική
(ΦΠΑ, αρίθμηση, υπόλοιπα) και ταυτόχρονα προσθέτει web/multi-tenant αρχιτεκτονική,
ζωντανό συγχρονισμό & reconciliation με ΑΑΔΕ (συμπ. «αδέσποτα»), ενοποιημένη WHMCS
γέφυρα με τιμολόγηση σε τρίτους, πολύ καλύτερη διεπαφή/Καρτέλα, ρόλους, dashboard,
backups και ένα καθαρό μονοπάτι για **Έξοδα/ΦΠΑ**. Παράλληλα πέταξε με ασφάλεια
ό,τι ήταν νεκρό ή επικίνδυνο στο legacy.
