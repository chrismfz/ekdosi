# Κάρτα στο ταμείο — έρευνα (POS PR 3)

> Έρευνα 2026-10-03 (νόμος/ΑΑΔΕ + αγορά). Πηγές στο τέλος κάθε ενότητας. Επίπεδο βεβαιότητας:
> **[Β]** επιβεβαιωμένο από πρωτογενή πηγή · **[Π]** πιθανό · **[;]** ανεπιβεβαίωτο.
> Συμπληρώνει/διορθώνει το `docs/woocommerce-bridge-plan.md` §11.3.

## Η ουσία

**Ο πάροχος (InvoSign) ΔΕΝ «συνδέεται» με καμία τράπεζα — μόνο υπογράφει.** Κατά την Α.1155/2023 ο πάροχος
παράγει μια **υπογραφή πληρωμής** (ECDSA P-256 πάνω σε `UID;MARK;timestamp;ποσά;TID`). Το ERP (ekdosi) τη στέλνει
στο τερματικό μέσω του API του acquirer/NSP· το τερματικό την επαληθεύει με το **δημόσιο κλειδί του παρόχου, που
το δημοσιεύει η ΑΑΔΕ** (38 πάροχοι, `getSignatureById/{001–038}`), χρεώνει την κάρτα και επιστρέφει `tid` +
`transactionId` (Unique Payment ID: κωδ. acquirer + RRN + έγκριση)· το ekdosi εκδίδει την ΑΛΠ μέσω του παρόχου με
πληρωμή τύπου 7 + `ProvidersSignature` + `tid` + `transactionId`. [Β]

Άρα η «μήτρα» **δεν** είναι πάροχος × τράπεζα αλλά **ekdosi × πρωτόκολλο τερματικού** — και στην πράξη δύο
οικογένειες: **Viva Cloud Terminal API** και **«Common WebECR»** (Mellon· το υιοθετούν Cardlink/Worldline, Euronet/epay,
Nexi, Attica, Pancreta). Αντιστοιχούν ακριβώς στο `nsp=0` / `nsp=1` του InvoSign `GetPayment`. [Β/Π]

## Νομικό πλαίσιο (Α.1155/2023 + Α.1074/2024, Α.1102/2024, Α.1029/2025, Α.1160/2025)

| Δρόμος | Υπογραφή στο myDATA | Για το κατάστημα |
|---|---|---|
| ΦΗΜ (αυτόνομη ή ERP+ΦΗΜ) | `ECRToken` (Α.1098/2022) | δεν θέλουμε ταμειακή |
| ERP + πάροχος (ΥΠΑΗΕΣ) | `ProvidersSignature` | **ο δρόμος μας** |
| ERP απευθείας στο myDATA | — | **ΑΠΟΚΛΕΙΕΤΑΙ στη λιανική**: FAQ myDATA Q37 «μόνο η επιλογή πιστοποιημένου Παρόχου μπορεί να αντικαταστήσει την υποχρέωση χρήσης ΦΗΜ» → στο ταμείο το ekdosi εκδίδει ΠΑΝΤΑ μέσω παρόχου |
| «All-in-one» Android POS παρόχου (Epsilon, Oxygen, SoftOne…) | ο πάροχος στη συσκευή | η απόδειξη βγαίνει έξω από το ekdosi — δεν μας κάνει |

- Δεν υπάρχει πιστοποιημένος ρόλος «πάροχος διασύνδεσης». [Β]
- **Υποχρέωση του ekdosi ως κατασκευαστή ERP: Δήλωση Συμβατότητας (Α.1054/2024)** ανά υποστηριζόμενο NSP/μοντέλο
  POS, με email στο ts.compliance@aade.gr· η ΑΑΔΕ τις δημοσιεύει. [Β]
- Η υπογραφή που δεν «πληρώθηκε» απορρίπτεται σε 60 ώρες (2 ώρες στην εστίαση). Αυτόνομη λειτουργία τερματικού: 48h
  μετά από αποσύνδεση ERP/παρόχου. [Β]
- Εξαιρούνται: e-shop / payment links / card-not-present, μη επανδρωμένα. Φυσικό λιανικό κατάστημα: **καμία εξαίρεση**.
  Πρόστιμα €10k/€20k (ΚΦΔ 54Θ§1). Σε ισχύ από 30/9/2024. [Β/Π]
- Α.1160/2025: προσθέτει IRIS (`EndToEndReferenceID`) — ίδια λογική υπογραφής. [Β]

Πηγές: taxheaven (κωδικοποιημένη Α.1155) https://www.taxheaven.gr/circulars/45062/a-1155-2023 ·
FAQ myDATA https://www.aade.gr/sites/default/files/2023-02/FAQs_myDATA_epixeirisiaka_themata.pdf (Q37) ·
Α.1054/2024 https://www.taxheaven.gr/circulars/46780/a-1054-2024 · Ε.2044/2024 https://www.taxheaven.gr/circulars/47407/e-2044-2024 ·
κλειδιά παρόχων https://www.aade.gr/diasyndesi-pos-tameiakon-systimaton/protokolla-tekmiriosi-gia-diasyndesi-me-basi-tin-a11552023/ypografes-parohon-ypaies ·
λίστα POS με δήλωση συμβατότητας https://www.aade.gr/en/interface-pos-cash-systems/what-are-my-options/pos-models-which-declaration-conformity-has-beensubmitted-providers

## Δίκτυα τερματικών

| Δίκτυο | Πρωτόκολλο | Σημείωση |
|---|---|---|
| **Viva** | Cloud Terminal API (REST, OAuth2· `aadeProviderId` + υπογραφή) | επίσης **η ίδια πάροχος** («Viva Fiscal») — βλ. Β [Β] |
| **Mellon** (Ingenico· NBG Pay, PayzyPOS) | Common WebECR (REST, JWT + `X-Api-Key` από το μενού του τερματικού· webhook/polling) | έχει SaaS mode για ERP πολλών εμπόρων· UAT `uat.mreceipts.com`· ~16 test cases πιστοποίησης [Β] |
| **Cardlink/Worldline** (και πρώην Eurobank) | Cardlink Web / Common WebECR | ό,τι χρησιμοποιεί ο «SOFT1 POS Connector» [Π] |
| **Euronet/epay** (Πειραιώς) | Common WebECR | [Π] |
| Nexi (Alpha), Attica, Pancreta | Common WebECR | [Π] |
| SumUp, Revolut | — | δεν βρέθηκαν στη λίστα Α.1155 — να αποφευχθούν [;] |

Πηγές: https://developer.viva.com/apis-for-point-of-sale/card-terminals-devices/ ·
https://aade.mellongroup.com/Portals/0/Library/EFTPOS-WebECR%20v2.5.12%201.pdf · https://pos.mellongroup.com/erp/ ·
https://cardlink.gr/wp-lp/ecr-pos-integration/ · https://go.prosvasis.com/softone-prosvasis-go-diasyndesi-me-pos/

## InvoSign

- Ταυτόχρονη: `iNVOSign_GetPayment.php` (external_id, issueDate, branch, Type, series, aa, `markid` κενό, Net/Vat/Total,
  `Amount` → μικτή πληρωμή, tipAmount, **TerminalID**, `nsp`) → `uid` + `paymentToken{timestamp, signature, amount}` →
  χρέωση στο τερματικό → κανονική υποβολή με τα στοιχεία πληρωμής. Ετεροχρονισμένη: `iNVOSign_Payment.php`. [Β]
- Δεν δημοσιεύει λίστα acquirers — είναι ουδέτερος (υπογράφει). Αδειοδοτήθηκε 05/2025: **να ρωτηθεί η GV Solutions
  ποιοι acquirers έχουν ήδη δοκιμάσει την υπογραφή του** και αν το `GetPayment` χρεώνει credit. [;]
- Κόστος: πακέτα 1.000 = €50 (€0,05/παραστατικό) … 10.000 = €350 + ΦΠΑ. [Β]

Πηγές: https://invosign.gr/site/help_site/?page=pos_tautoxroni · https://invosign.gr/?page=timokatalogos

## Επιλογές για το ekdosi

**Α. InvoSign υπογράφει + το υπάρχον τερματικό του καταστήματος (Viva ή WebECR) — ΠΡΟΤΕΙΝΕΤΑΙ.**
ekdosi → InvoSign `GetPayment` → τερματικό (cloud API) → webhook/polling → ekdosi → InvoSign υποβάλλει 11.1 με τύπο 7.
Κατασκευή: 2 κλήσεις στο υπάρχον `InvoSignTransport` + ένα `TerminalGateway` (Viva ή WebECR — ίδιο σχήμα), idempotency
ανά πληρωμή, χειρισμός αυτόνομης λειτουργίας. Ένας πάροχος, όλα στο ekdosi, όποια τράπεζα θέλει το κατάστημα.
Κόστος ≈ credits InvoSign + προμήθειες κάρτας. Ρίσκο: να αναγνωρίζει ο acquirer το ProviderId του InvoSign — δοκιμή
πρώτα στο sandbox (ProviderId 999).

**Β. Viva Fiscal** — η Viva χρεώνει ΚΑΙ εκδίδει (είναι πάροχος #27). Το ekdosi στέλνει την πώληση σε δική της μορφή
(`ft*Case`, όχι myDATA XML), η Viva επιστρέφει MARK/QR. Απλό στο ταμείο, αλλά: μόνο Viva, δύο πάροχοι στην
επιχείρηση, οι χαρακτηρισμοί E3 τους βγάζει η Viva, επιστροφές με διαπιστευτήρια εμπόρου.

**Γ. ΦΗΜ μόνο για κάρτες** — εφεδρικό: διπλά βιβλία, Ζ ημέρας.  **Δ. All-in-one Android POS** — η απόδειξη βγαίνει
εκτός ekdosi· όχι.

## Viva — τι λένε τα specs (κατέβηκαν 2026-10-03: `eft-pos-api.yml`, `p2p-eft-pos-api.yml`)

**Cloud Terminal API** (ECR → Viva cloud → τερματικό → acquirer → τράπεζα· αποτέλεσμα με polling ή webhook):
- Token: OAuth2 `client_credentials` στο `https://demo-accounts.vivapayments.com/connect/token` (live: `accounts.vivapayments.com`)
  με τα **POS API credentials** του εμπόρου (ή ISV scheme: `/ecr/isv/v1/...`).
- Τερματικά: `POST /ecr/v1/devices:search` (ή «Source Terminal ID» στο Viva Terminal app → More → About).
- Πώληση: `POST /ecr/v1/transactions:sale` στο `https://demo-api.vivapayments.com` — `sessionId` (uuid, δικό μας), `terminalId`,
  `cashRegisterId`, `amount` (λεπτά), `currencyCode` 978, `merchantReference` και τα ελληνικά:
  `aadeProviderId` (αριθμός παρόχου· **`999` = δοκιμαστικά κλειδιά**· `800` = ΦΗΜΑΣ), `aadeProviderSignatureData`
  (`UID;MARK;timestamp;ποσό;καθαρό;ΦΠΑ;σύνολο;TID`), `aadeProviderSignature` (ECDSA P-256 / SHA256, base64),
  `aadePreloaded` (+ `aadePreloadedDuration`, ≤24h) για «φόρτωση τώρα, πληρωμή αργότερα».
- Αποτέλεσμα: `GET /ecr/v1/sessions/{sessionId}` (ή webhooks «Transaction POS ECR Session Created / Failed») → `success`,
  `transactionId`, `tid`, `retrievalReferenceNumber`, `authorizationId`, `aadeTransactionId`, `aadeResultCommand`, `cardType`…
  — ό,τι θέλει το myDATA για τον τύπο 7. Επίσης `transactions:refund`, `sessions/{id}` DELETE (abort).
- Σφάλματα AADE: `1076` «Signature verification failed (ProviderID)», `1077` «AADE details mismatch», `1083` «Not all
  required AADE request parameters» → επιβεβαιώνουν ότι το ΤΕΡΜΑΤΙΚΟ επαληθεύει την υπογραφή του παρόχου μόνο του.
- Demo: ελάχιστο ποσό 0,30 €· καμία πραγματική χρέωση.

**Local Terminal API** (ECR → `https://<IP τερματικού>:<port>/pos/v1/sale`, χωρίς auth σε κλειστό δίκτυο, polling, ίδια πεδία
AADE): δεν ταιριάζει σε web εφαρμογή — ο server του ekdosi δεν βλέπει το LAN του καταστήματος και ο browser (σελίδα HTTPS)
μπλοκάρεται από self-signed cert + Private Network Access. Θα χρειαζόταν τοπικός agent στο PC. **→ Cloud.**

**Δοκιμή χωρίς φυσικό τερματικό:** το **Viva Terminal app** (Android, Tap-on-Phone) σε demo λογαριασμό = demo τερματικό.
Χρειάζεται: demo λογαριασμός εμπόρου (demo.vivapayments.com) + POS API credentials + κινητό Android με NFC.

**Cardlink** (το τερματικό του καταστήματος): Common WebECR / «Cardlink Web» — ζητάμε πρόσβαση developer/τερματικό
δοκιμών από το πρόγραμμα ECR integration της Cardlink. Το `TerminalGateway` του ekdosi σχεδιάζεται ώστε Viva και
WebECR να είναι δύο υλοποιήσεις του ίδιου flow (υπογραφή παρόχου → πώληση → αποτέλεσμα → υποβολή).

## Επόμενα βήματα

1. Ποιο τερματικό έχει σήμερα το κατάστημα (κεφαλίδα στο απόκομμα κάρτας). Με SoftOne: πιθανότατα Cardlink ή epay.
2. Ερώτηση στην GV Solutions (InvoSign): δοκιμασμένοι acquirers · χρεώνει credit το `GetPayment`;
3. Sandbox: InvoSign `GetPayment` + το αντίστοιχο cloud API (Viva demo / Mellon UAT).
4. Δήλωση Συμβατότητας Α.1054/2024 για το ekdosi πριν τη ζωντανή χρήση.
5. Θέσεις ταμείου (registers) στο ίδιο PR: ένα τερματικό (TerminalID) ανά θέση.
