# Πληρωμές / Εισπράξεις (AR) — roadmap & deferred decisions

Πού στεκόμαστε στο money-trail του πελάτη (accounts-receivable) και τι μένει.
Καταγραφή των κλειδωμένων αποφάσεων ώστε να μην ξανα-συζητηθούν.

> **Status:** Φ1+Φ2+Φ3+L1 **DONE** (branch `claude/invoice-payments-cockpit`).
> Τα παρακάτω «liked / deferred» είναι εγκεκριμένα ως μελλοντικά — όχι τώρα.

---

## ✅ Built (το «πλήρες» AR core)
- **Φ1 — cockpit ανά τιμολόγιο** (`InvoicePaymentsRelationManager`): λίστα +
  add/edit/delete, Πλήρης εξόφληση / Μερική / Σήμανση ως ανεξόφλητο.
- **Φ2 — Είσπραξη/Έμβασμα** (`PaymentAllocator`): ένα ποσό κατανέμεται **FIFO**
  στα ανοιχτά τιμολόγια· ό,τι περισσέψει → **on-account πίστωση/προκαταβολή**.
- **Φ3 — ομαδοποίηση στην Καρτέλα**: τα rows ενός εμβάσματος (κοινό `reference`)
  μία γραμμή «Έμβασμα €X» + drill-down «Κατανομή». Display-only.
- **L1 — `transaction_id`**: προαιρετικός κωδικός συναλλαγής (Stripe `pi_…`,
  PayPal txn, ref εμβάσματος τράπεζας) σε **κάθε** φόρμα πληρωμής + στο έμβασμα
  (ίδιος σε όλες τις γραμμές της ομάδας). Column + copyable στο cockpit.
- **L2 — Τραπεζικοί Λογαριασμοί**: lookup `bank_accounts` (Setup) + tag στις
  πληρωμές (`payments.bank_account_id`, σε όλες τις φόρμες + έμβασμα) + λογαριασμός
  κατάθεσης στο παραστατικό (`invoices.bank_account_id` → τυπώνεται στο PDF).
  Κοινό `BankAccountField` (εμφανίζεται μόνο με active λογαριασμό). Πληροφοριακό.

**Money model αμετάβλητο:** όλα γράφουν απλά `Payment` rows → `PaymentObserver`
→ `InvoiceBalance`. Καμία αλλαγή σε `InvoiceScope`/balance/καρτέλα/dashboard.

---

### #6 — Due / Ληξιπρόθεσμα ✅ DONE
- Due-date = `issued_at + payment_method.due_days`· `Invoice::dueDate/isOverdue/
  scopeOverdue`. Στήλη «Λήξη» + filter «Μόνο ληξιπρόθεσμα» στη λίστα.
- Dashboard widget «Ληξιπρόθεσμα τιμολόγια».
- **Dunning = ΜΟΝΟ dashboard + bell notifications** (`invoices:notify-overdue`,
  scheduler default OFF) — **όχι email** σε εμάς ή στον πελάτη.

---

## 💡 Liked / deferred (εγκεκριμένα ως ιδέες — μικρά, αργότερα)
- **Εφαρμογή υπάρχουσας πίστωσης σε νέο τιμολόγιο.** Σήμερα η on-account
  προκαταβολή κάθεται σωστά ως credit στο running balance, αλλά λείπει action
  «χρησιμοποίησέ την στο επόμενο ΤΙΜ». *(Το υπόλοιπο είναι ήδη σωστό — UX nicety.)*
- **Χειροκίνητη κατανομή εμβάσματος.** Τώρα μόνο FIFO-auto· μελλοντικά «βάλ' το
  ΣΥΓΚΕΚΡΙΜΕΝΑ σε αυτά τα τιμολόγια».
- **Επιστροφές / refunds (χρήμα ΠΙΣΩ στον πελάτη).** Τώρα μόνο διαγραφή πληρωμής.
  Μελλοντικά: **μαζί με το ακυρωτικό/πιστωτικό** — τα χρήματα είτε γυρίζουν στον
  πελάτη είτε μένουν ως πίστωση. (Edge case, αλλά να κλείσει ο κύκλος ακύρωσης.)

---

## 🔮 L3 — Connectors (μελλοντικό)
POS κάρτας + IRIS (request-to-pay) → αυτόματο `Payment` + link στο MARK, χωρίς ο
cloud app να αγγίζει card data. **Πλήρες σχέδιο: `docs/payment-connectors.md`**
(rails, cloud-to-cloud, ΑΑΔΕ POS↔ERP mandate, contract/registry pattern,
σύσταση «IRIS πρώτα»).

---

## ❌ Εκτός scope (ρητά, απόφαση χρήστη)
- **Προμηθευτές / AP πληρωμές** — «προμηθευτές θα χαθούμε, όχι ακόμα».
- **Epsilon εμβάσματα import** — «Epsilon δεν μας πειράζει».
