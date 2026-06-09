# Sandbox validation runbook — τρέξε στο VM, μοίρασε το `sandbox-results.txt`

> Στόχος: να επικυρώσουμε στο **AADE sandbox** ό,τι χτίστηκε αλλά δεν έχει
> round-trip-αριστεί ζωντανά: **(A)** Δελτίο Αποστολής lifecycle, **(B)** οι νέοι
> taxTypes (χαρτόσημο/τέλη/λοιποί/κρατήσεις) + **product-linked taxes**, **(Γ)** το
> **4% override** (κατ.6 vs 10). Όλα είναι code-complete + unit-tested· λείπει το
> πραγματικό POST στην ΑΑΔΕ.

## Προαπαιτούμενα (στο VM)
- Ο tenant `myip` σε **sandbox** mode με dev myDATA credentials
  (`Companies → myip → myDATA → Sandbox`). Επιβεβαίωση:
  ```bash
  php artisan mydata:preflight --tenant=myip 2>&1 | tee -a sandbox-results.txt
  ```
- Όλες οι εντολές γράφουν και στο `sandbox-results.txt` (το `tee -a`) — **στείλε μου
  αυτό το αρχείο** στο τέλος.

---

## A) Δελτίο Αποστολής — πλήρες lifecycle
```bash
# Dry-run πρώτα (μόνο XML, κανένα POST):
php artisan delivery:sandbox-validate --tenant=myip 2>&1 | tee -a sandbox-results.txt

# Πραγματικό round-trip (issue → έναρξη → παράδοση → έλεγχος → ακύρωση):
php artisan delivery:sandbox-validate --tenant=myip --execute --cancel 2>&1 | tee -a sandbox-results.txt
```
**Τι θέλουμε:** MARK σε κάθε βήμα, `delivery_state` να αλλάζει
(registered → in_transit → delivered), και ΟΚ στην ακύρωση. Η εντολή γράφει και
δικό της `.txt` report — στείλε κι αυτό.

---

## B) Νέοι taxTypes + product-linked taxes
1. **Στο UI**, έκδωσε 3 ΠΡΟΧΕΙΡΑ ΤΠΥ (myip) με 24% γραμμή και:
   - **B1 — Χαρτόσημο 3,6%**: «Παρατηρήσεις & τέλη/φόροι» → «⚡ Τυπικά τέλη/φόροι» →
     «Χαρτόσημο 3,6%». (Θέτει `stamp_duty_rate=3.6` + κατηγορία· ποσό auto.)
   - **B2 — Παρακράτηση 20%**: ίδιο, «Παρακράτηση 20% (συμβούλων)».
   - **B3 — Product-linked**: φτιάξε προϊόν «Δοκιμαστικό τέλος» με
     «Δεμένο τέλος/φόρος myDATA» = Τέλη (§8.5), κατηγορία X, €0,50/μονάδα· βάλ' το
     σε γραμμή με ποσότητα 3 (→ τέλος €1,50 auto).
2. Σημείωσε τα `invoice id` τους και υπόβαλε ΞΕΧΩΡΙΣΤΑ:
   ```bash
   # Dry-run (δες το <taxesTotals> + <totalGrossValue> + <amount> στο XML):
   php artisan mydata:test-submit <ID> 2>&1 | tee -a sandbox-results.txt
   # Πραγματικό:
   php artisan mydata:test-submit <ID> --execute 2>&1 | tee -a sandbox-results.txt
   ```
**Τι θέλουμε:** statusCode **Success** + MARK (όχι rejection). Ειδικά κοίτα ότι το
`totalGrossValue` = net+ΦΠΑ + τέλη − κρατήσεις και ότι το `<amount>` του payment
ταιριάζει (αυτό ήταν το review fix).

---

## Γ) 4% override (ν.5057/2023 → κατηγορία 10)
1. **Setup → VAT Categories**: στην 4%-κατηγορία του myip, όρισε
   «Κατηγορία ΦΠΑ myDATA (override)» = **10**.
2. Έκδωσε ΠΡΟΧΕΙΡΟ ΤΠΥ με **4%** γραμμή, υπόβαλε:
   ```bash
   php artisan mydata:test-submit <ID> 2>&1 | tee -a sandbox-results.txt          # δες <vatCategory>10
   php artisan mydata:test-submit <ID> --execute 2>&1 | tee -a sandbox-results.txt
   ```
**Τι θέλουμε:** `<vatCategory>10` (όχι 6) + Success. (Χωρίς override → 6.)

---

## Στο τέλος
- **Στείλε εδώ το `sandbox-results.txt`** (+ τα `.txt` reports του delivery).
- Αν κάποιο απορριφθεί, το μήνυμα της ΑΑΔΕ (`[NNN] …`) μου λέει ακριβώς τι να
  διορθώσω. Αν όλα Success → τα σημειώνουμε ως sandbox-validated στο CLAUDE.md.
