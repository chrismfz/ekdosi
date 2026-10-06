# Τερματικά καρτών (POS) — σχέδιο (PR 3)

> Σχέδιο 2026-10-03, πριν τον κώδικα. Η έρευνα (νόμος, δίκτυα, specs Viva/Cardlink) → **`docs/pos-card-payments.md`**.
> Εδώ: **πώς** το χτίζουμε στο ekdosi ώστε να χωράει (α) πολλά τερματικά — ίδιου δικτύου (πολλά ταμεία) ή διαφορετικών
> (failover) — και (β) αργότερα πληρωμή με κάρτα και για **τιμολόγια** που εκδίδονται στο φυσικό κατάστημα.

## 1. Η αρχή

Ένα flow, πολλά «drivers». Ό,τι κι αν είναι το τερματικό, η πληρωμή με κάρτα ενός παραστατικού είναι πάντα:

```
υπογραφή παρόχου (InvoSign GetPayment) → εντολή στο τερματικό (driver) → αποτέλεσμα (poll / webhook)
→ υποβολή παραστατικού με πληρωμή τύπου 7 (+ ProvidersSignature, tid, transactionId)
```

Το «ποιο τερματικό» είναι **ρύθμιση/επιλογή**, όχι κώδικας. Ο driver ξέρει μόνο το πρωτόκολλο του δικτύου του
(Cardlink Web, Viva Cloud, Common WebECR — Mellon). Το ίδιο ακριβώς flow καλείται από το **Ταμείο** (απόδειξη) και από
ένα **τιμολόγιο** (β) — άρα δεν το «κλειδώνουμε» μέσα στη σελίδα του ταμείου.

## 2. Οντότητες

### `pos_terminals` — ένα φυσικό/εικονικό τερματικό καρτών
| πεδίο | σημασία |
|---|---|
| `company_id` | |
| `name` | «Ταμείο 1 — Cardlink», «Viva κινητό (εφεδρικό)» |
| `driver` | `cardlink_web` · `viva_cloud` · `webecr` (Mellon — spec v2.5.13, UAT με email) · `fake` (δοκιμές/demo) |
| `environment` | `test` / `live` (Viva demo-api vs api · Cardlink VPOS simulator vs πραγματικός κόμβος) |
| `terminal_ref` | το αναγνωριστικό στο δίκτυο: Viva `terminalId` («Source Terminal ID») · Cardlink TID |
| `config` (κρυπτογραφημένο) | ό,τι θέλει ο driver: Viva client id/secret, Cardlink pairing token… — `HasSecretConfig`, ίδιο μοτίβο με `PaymentGatewayConnection` |
| `paired_at`, `last_ok_at`, `last_error` | κατάσταση/υγεία (Cardlink «Cloud ERP» = κωδικός σύζευξης 5' από την οθόνη του τερματικού) |
| `is_active`, `sort` | on/off + σειρά προτίμησης |

### `pos_registers` — μια θέση ταμείου («Ταμείο 1», «Ταμείο 2»)
| πεδίο | σημασία |
|---|---|
| `name` | |
| `invoice_type_id`, `credit_type_id` | σειρά ΑΛΠ/ΠΙΛ της θέσης (null = της εταιρείας — σημερινή συμπεριφορά) |
| `terminal_id` | το **προεπιλεγμένο** τερματικό της θέσης |
| `fallback_terminal_ids` (json, με σειρά) | τα εφεδρικά — **failover** (π.χ. Cardlink → Viva στο κινητό) |
| `is_active` | |

Η **συσκευή** (PC/tablet του ταμείου) δηλώνεται μία φορά «είμαι το Ταμείο 1» — cookie με token, ακριβώς όπως το
`WorkCardKioskDevice` («Σημείο κάρτας»). Χωρίς δηλωμένη συσκευή: ο ταμίας διαλέγει θέση στο άνοιγμα του ταμείου.

### `pos_sessions.pos_register_id` (nullable)
Το «Ταμείο ημέρας» γίνεται **ανά θέση**: ένα ανοιχτό ταμείο ανά θέση (όχι ανά εταιρεία). Null = η σημερινή μία θέση —
μετάβαση χωρίς σπάσιμο (το `TillSessions::current()` παίρνει προαιρετική θέση). Αναφορές/στατιστικά αποκτούν φίλτρο θέσης.

### `pos_card_payments` — κάθε προσπάθεια χρέωσης (το «audit» της κάρτας, όπως τα `mydata_marks` για το myDATA)
| πεδίο | σημασία |
|---|---|
| `invoice_id`, `pos_terminal_id`, `pos_session_id`, `user_id` | |
| `amount` | μπορεί < σύνολο → **μικτή πληρωμή** (κάρτα + μετρητά, δύο κάρτες) |
| `status` | `signing` → `sent` → `approved` / `declined` / `aborted` / `timeout` / **`in_doubt`** |
| `session_ref` | το δικό μας uuid προς το τερματικό (Viva `sessionId`) — **idempotency** |
| `provider_uid`, `signature_data`, `signature` | ό,τι έδωσε το InvoSign |
| `tid`, `transaction_id`, `rrn`, `auth_code`, `card_mask` | ό,τι επέστρεψε το τερματικό — πάνε στο myDATA |
| `request`, `response` (json, χωρίς ευαίσθητα) | για έλεγχο/υποστήριξη |

## 3. Ρυθμίσεις — καρτέλα «Κάρτες (POS)» στην εταιρεία

- **Διακόπτης** `pos_card_enabled` (off = το ταμείο δεν δείχνει καν «Κάρτα»).
- **Προϋπόθεση**: έκδοση **μέσω παρόχου** που υποστηρίζει υπογραφή πληρωμής (σήμερα InvoSign). Με «απευθείας myDATA»
  η καρτέλα εξηγεί γιατί δεν γίνεται (FAQ Q37) αντί να αφήνει ρύθμιση που δεν θα δουλέψει.
- **Τερματικά** (λίστα): προσθήκη με οδηγό ανά driver — Viva: credentials + «Αναζήτηση τερματικών»· Cardlink: κωδικός
  σύζευξης από την οθόνη του τερματικού — και **«Δοκιμή σύνδεσης»** (health).
- **Θέσεις ταμείου**: όνομα, σειρές, τερματικό + εφεδρικά.
- **Ποιος**: τα **κλειδιά** (Viva secret) → super_admin (όπως όλα τα credentials — CLAUDE.md)· ονόματα/θέσεις/σύζευξη →
  company_admin. (Ανοιχτό ερώτημα §8.)

## 4. Το flow στο ταμείο

1. Ο ταμίας πατά **«Κάρτα»** (ή «Μικτή»: γράφει πόσα με κάρτα, τα υπόλοιπα μετρητά).
2. Το ekdosi φτιάχνει το πρόχειρο **και κρατά ΑΑ** (το `GetPayment` θέλει σειρά/ΑΑ/ποσά πριν την υποβολή).
3. `GetPayment` → υπογραφή → `pos_card_payments` (`sent`) → εντολή στο τερματικό της θέσης.
4. Η οθόνη δείχνει «Περιμένω την κάρτα στο τερματικό…» με **«Ακύρωση»** (abort στον driver).
5. Αποτέλεσμα (poll· webhook όπου υπάρχει):
   - **approved** → υποβολή του παραστατικού με τύπο 7 + στοιχεία → απόδειξη (με τα στοιχεία κάρτας).
   - **declined / aborted** → το πρόχειρο μένει· «Δοκίμασε ξανά», «Άλλο τερματικό», «Μετρητά».
   - **timeout / in_doubt** → **ΠΟΤΕ αυτόματη νέα χρέωση**: πρώτα ερώτηση κατάστασης στον driver (Viva `sessions/{id}`) —
     μόνο αν το δίκτυο λέει «δεν χρεώθηκε» επιτρέπεται νέα προσπάθεια.
6. Ταμείο ημέρας: οι πληρωμές κάρτας είναι ήδη ξεχωριστή γραμμή ανά τρόπο πληρωμής (`by_method`)· τα **μετρητά** που
   περιμένουμε στο συρτάρι μένουν μόνο τα μετρητά (η κάρτα δεν μπαίνει στο συρτάρι).

## 5. (α) Πολλά τερματικά

- **Ίδιο δίκτυο, πολλά ταμεία**: κάθε θέση έχει το τερματικό της — απλή αντιστοίχιση.
- **Failover**: αν το προεπιλεγμένο είναι εκτός (health ✗, σφάλμα σύνδεσης, «έπεσε» η σύζευξη) το ταμείο **προτείνει** το
  επόμενο εφεδρικό — ο ταμίας επιβεβαιώνει (ο πελάτης πρέπει να πάει στο άλλο τερματικό). Ποτέ αυτόματη μεταπήδηση στη
  μέση μιας χρέωσης (κίνδυνος διπλής χρέωσης). Κάθε προσπάθεια = νέα γραμμή `pos_card_payments`, νέα υπογραφή.
- Διαφορετικά δίκτυα = διαφορετικός driver· η υπογραφή του παρόχου είναι η ίδια (ο πάροχος δεν «συνδέεται» με την τράπεζα).
- Νομικά: **Δήλωση Συμβατότητας (Α.1054/2024)** για κάθε δίκτυο που υποστηρίζουμε.

## 6. (β) Τιμολόγια στο φυσικό κατάστημα

Πελάτης ζητά τιμολόγιο (1.1/ΤΠΥ) και πληρώνει με κάρτα στο κατάστημα — εμπίπτει στη διασύνδεση (επί τόπου πληρωμή).
- Στο τιμολόγιο (πρόχειρο **ή** ήδη εκδομένο): ενέργεια **«Πληρωμή με κάρτα στο κατάστημα»** → επιλογή θέσης/τερματικού
  (προεπιλογή: η θέση της συσκευής, αν το PC είναι δηλωμένο) → **ίδιο** `CardPaymentService`.
  - πρόχειρο → **ταυτόχρονη** συναλλαγή (όπως το ταμείο, §4)·
  - ήδη στο myDATA (έχει MARK) → **ετεροχρονισμένη**: `GetPayment` με `markid` → τερματικό → `iNVOSign_Payment.php`.
- Το τιμολόγιο αποκτά `Payment` (εισπράχθηκε) όπως κάθε είσπραξη· ο `TerminalGateway` είναι το «φυσικό» αδερφάκι του
  online `PaymentGateway` (Eurobank/Cardlink vPOS της πύλης), που **εξαιρείται** από τη διασύνδεση (e-commerce).

## 7. Κώδικας (σχήμα)

```
App\Contracts\TerminalDriver
    health(): TerminalHealth
    sale(TerminalSale $sale): string              // επιστρέφει session_ref
    status(string $ref): TerminalResult           // approved/declined/pending/... + tid, txn, rrn
    abort(string $ref): void
    refund(TerminalRefund $refund): string        // επιστροφές σε κάρτα (§9)
App\Services\Pos\Terminals\{CardlinkWebDriver, VivaCloudDriver, FakeDriver}
App\Services\Pos\TerminalRegistry                 // driver ανά pos_terminals.driver (όπως PaymentGatewayRegistry)
App\Services\Pos\CardPaymentService               // το flow §4 — καλείται από Ταμείο ΚΑΙ τιμολόγιο
App\Services\EInvoice\InvoSign… + getPayment()/payment()   // στο υπάρχον InvoSignTransport
AadeInvoiceDocument / InvoSignDocument: paymentMethodDetails τύπου 7 από τα approved pos_card_payments
```

Ο **`FakeDriver`** (approve/decline/timeout κατά παραγγελία) αφήνει να χτίσουμε όλο το UI/flow/tests **πριν** έρθουν τα
credentials της Cardlink/Viva.

## 8. Ανοιχτά ερωτήματα (απόφαση ιδιοκτήτη)

1. Η συσκευή δηλώνει θέση (cookie, μία φορά) — ή ο ταμίας διαλέγει θέση σε κάθε άνοιγμα ταμείου;
2. Failover: μόνο **πρόταση** με επιβεβαίωση ταμία (προτείνεται) — ή καθόλου στην αρχή;
3. Ποιος ρυθμίζει τερματικά/θέσεις: company_admin, με τα secrets μόνο super_admin;
4. Μικτή πληρωμή από την αρχή ή σε δεύτερη φάση;
5. Απόδειξη: τα στοιχεία κάρτας (τύπος, ****1234, έγκριση) τυπώνονται στο 80mm; (συνήθως ναι)

## 9. Να ερευνηθεί πριν το αντίστοιχο βήμα

- **Επιστροφή σε κάρτα** (πιστωτικό 11.4 πληρωμένο με κάρτα): θέλει υπογραφή παρόχου στο refund; (Viva: `transactions:refund`
  δέχεται τα ίδια AADE πεδία) — έλεγχος στα specs Cardlink/Viva + InvoSign.
- Cardlink Web: πλήρες spec από το developer portal (μετά την εγγραφή στον VPOS simulator).
- WebECR: δέχεται το UAT την υπογραφή του InvoSign (test public key/provider id στη Mellon); θέλει `ProviderData` η επιστροφή (TxnType 1);
- Χρεώνει credit το `GetPayment` του InvoSign; ποιοι acquirers έχουν δοκιμάσει την υπογραφή του;
- IRIS (Α.1160/2025, `EndToEndReferenceID`) — ίδια ραφή (§7), αργότερα.

## 10. Φάσεις

| | περιεχόμενο | εξαρτάται από |
|---|---|---|
| **3a** | σχήμα (`pos_terminals`, `pos_registers`, `pos_card_payments`, `pos_sessions.pos_register_id`), καρτέλα «Κάρτες (POS)», θέσεις + δήλωση συσκευής, `CardPaymentService` + **FakeDriver**, κουμπί «Κάρτα» στο ταμείο, τύπος 7 στο payload | — (ξεκινά τώρα) |
| **3b** | `CardlinkWebDriver` + σύζευξη, δοκιμές στον VPOS simulator | εγγραφή Cardlink |
| **3c** | `VivaCloudDriver` (demo + Tap-on-Phone) | demo λογαριασμός Viva |
| **3c′** | `WebEcrDriver` (Mellon UAT, 19 σενάρια → ραντεβού Mellon Lab → πιστοποίηση) | email Mellon + provider id InvoSign |
| **3d** | failover + μικτή πληρωμή | 3a |
| **3e** | (β) κάρτα σε τιμολόγιο — ταυτόχρονη + ετεροχρονισμένη | 3a + InvoSign `Payment.php` |
| **3f** | επιστροφή σε κάρτα | έρευνα §9 |
| — | Δήλωση Συμβατότητας Α.1054/2024 ανά δίκτυο, πιλοτικό στο κατάστημα | 3b |
