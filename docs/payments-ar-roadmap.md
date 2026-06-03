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

## ✅ Ολοκληρωμένος κύκλος «χρήματα μέσα → πίσω → πού πήγαν»
- **#1 Εφαρμογή πίστωσης σε τιμολόγιο** ✅ — «Χρήση πίστωσης» στην Καρτέλα:
  re-point on-account credit πάνω σε ανοιχτό ΤΙΜ (net-zero). `applyCredit`.
- **#2 Χειροκίνητη κατανομή εμβάσματος** ✅ — «Χειροκίνητη κατανομή»: ποσό ανά
  τιμολόγιο (vs FIFO). `allocateManual`.
- **#3 Επιστροφές / refunds** ✅ — `kind='refund'`, money OUT, nets out παντού·
  action στο cockpit (ανά ΤΙΜ) + Καρτέλα (customer-level/on-account).

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
