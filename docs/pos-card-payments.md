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
| **Mellon** (Ingenico· NBG Pay, PayzyPOS) | Common WebECR (REST, JWT + `X-Api-Key` από το μενού του τερματικού· webhook/polling) | έχει SaaS mode για ERP πολλών εμπόρων· UAT `uat.mreceipts.com`· 19 σενάρια πιστοποίησης — βλ. ενότητα «Mellon WebECR» [Β] |
| **Cardlink/Worldline** (και πρώην Eurobank) | **cloud ERP → Common Web = το WebECR της Mellon** (production `wl.mreceipts.com`) · τοπικά: ECR2EFT WEB / DLL | NSP κωδ. **122** [Β] |
| **Euronet/epay** (Πειραιώς) | Common WebECR | [Π] |
| Nexi (Alpha), Attica, Pancreta | Common WebECR | [Π] |
| SumUp, Revolut | — | δεν βρέθηκαν στη λίστα Α.1155 — να αποφευχθούν [;] |

Πηγές: https://developer.viva.com/apis-for-point-of-sale/card-terminals-devices/ ·
https://aade.mellongroup.com/ (spec v2.5.13 στο `docs/`) · https://pos.mellongroup.com/erp/ ·
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

## Ίδιο μηχάνημα ≠ ίδιο δίκτυο (για να μη μπερδευτούμε ξανά)

- **Οι κατασκευαστές** (PAX, Verifone, Ingenico, Castles) φτιάχνουν το *μηχάνημα*. **Τα δίκτυα/NSP** (Cardlink, Viva, Mellon,
  Euronet/epay, Nexi…) βάζουν το *λογισμικό*, το δίκτυο, την υποστήριξη και τη σύνδεση με τον acquirer. Ένα Ingenico μπορεί να
  είναι Cardlink, Mellon ή άλλου — το κουτί δεν το λέει.
- **Πώς το αναγνωρίζεις:** το λογότυπο/επωνυμία στο απόκομμα (slip) που τυπώνει το τερματικό (π.χ. «CARDLINK S.A»), το
  αυτοκόλλητο με το τηλέφωνο υποστήριξης, η σύμβαση του καταστήματος· στο slip φαίνεται και ο acquirer (π.χ. WORLDLINE).
- **Cardlink ↔ Mellon:** διαφορετικές εταιρείες, ΑΛΛΑ για **cloud ERP** η Cardlink χρησιμοποιεί την **πλατφόρμα WebECR της
  Mellon** («Common Web») για **όλα** τα τερματικά της (PAX, Verifone, Ingenico)· δοκιμές στο UAT της Mellon, παραγωγή στο
  `https://wl.mreceipts.com`. Άρα για το ekdosi «Cardlink» = «WebECR της Mellon» σε επίπεδο πρωτοκόλλου. [Β — βλ. παρακάτω]

## Cardlink (το τερματικό του καταστήματος) — 2026-10-03, διορθώθηκε 2026-10-06

- **Τρία πρωτόκολλα** για τη διασύνδεση ERP–POS: **Cardlink Web based**, **Cardlink TCPSocket based** (DLL) και **Common Web**
  (πλέον για όλα τα POS της). [Β] https://support.cardlink.gr/support/discussions/topics/7000043333
- **«Cloud ERP»** (Android POS, app ≥ 7.5· και VX520): το τερματικό κάνει «Εγγραφή στον ενδιάμεσο κόμβο» της Cardlink και
  δείχνει **κωδικό σύζευξης (5 λεπτά)** που καταχωρείται στο ERP· χωρίς static IP / LAN — μέσω internet όπως η Viva Cloud.
  Το τερματικό περιμένει εντολή από το Cloud ERP (εικονίδιο «σύννεφο» = επανασύνδεση στον κόμβο). [Β]
  https://cardlink.gr/wp-content/uploads/2025/02/quick-guide-cloud-erp-7.5-android.pdf ·
  https://cardlink.gr/wp-content/uploads/2025/02/cardlink-cloud-erp-vx520-manual.pdf
- **Δοκιμή χωρίς τερματικό: VPOS Simulator** (Verifone + Android) για προμηθευτές ERP — εγγραφή με φόρμα
  (https://cardlink.wufoo.com/forms/z1uf80ag1qy4v89/), οι κωδικοί έρχονται στο email· είσοδος https://virtualpos.services.novidea.gr/login.
  ⚠ **Ο simulator ΔΕΝ υποστηρίζει το Common Web** — μόνο Cardlink Web / TCPSocket. Υπάρχει θέμα «Τι κλειδιά (Provider Keys)
  μπορώ να χρησιμοποιήσω στο VPOS?» (δοκιμαστικά κλειδιά παρόχου). [Β]
- **Πολιτική δοκιμών Cardlink:** μελέτη/επιλογή spec → ερωτήσεις μέσω φόρμας → ανάπτυξη → **δοκιμές στον simulator** με
  τους τύπους συναλλαγών του πελάτη → **πιλοτικό με επιλεγμένο πελάτη σε πραγματικό POS** (version με Α.1098/Α.1155) →
  rollout. Τα αναλυτικά specs ανά πρωτόκολλο βρίσκονται στο developer portal (support.cardlink.gr, ενότητα «Διασύνδεση
  POS-ERP — ΑΑΔΕ Α.1155. Τεχνικά Θέματα (Developers)»· κάποια θέματα θέλουν login). [Β]
- ~~Συμπέρασμα 2026-10-03: Cardlink Web + VPOS simulator~~ — **ΛΑΘΟΣ, διορθώθηκε 2026-10-06** από το forum developers της Cardlink
  (https://support.cardlink.gr/support/discussions/topics/7000043244 — το link που στέλνει η Cardlink μετά την εγγραφή):
  - Τύποι POS Cardlink: **Android (PAX A920/A920Pro/A80…)**, **Verifone VX-520**, **Ingenico ICT-220**. (topic 7000043243)
  - **ECR2EFT DLL** (TCP socket, σύγχρονο) και **ECR2EFT WEB** (json) = για ERP **στο τοπικό δίκτυο** — το ECR2EFT WEB είναι
    service σε Java 8 που τρέχει σε PC του καταστήματος (Windows 7 SP1+). Δεν ταιριάζει σε web εφαρμογή χωρίς τοπικό agent.
  - **«Για Cloud-based ERP συστήματα θα γίνεται χρήση του Common web πρωτοκόλλου για όλους τους τύπους POS της Cardlink»**
    (topic 7000043243) — **αυτό είναι το ekdosi**. Common Web = το **WebECR της Mellon** (το topic 7000044019 παραπέμπει στο
    WebECR documentation της Mellon, API `v2.2`).
  - **Δοκιμές:** οι VPOS simulators της Cardlink (Verifone + Android) καλύπτουν μόνο τα δικά της πρωτόκολλα· για Common Web
    οι δοκιμές γίνονται στον **Mellon simulator** (topic 7000043245). Μετά: **production URL `https://wl.mreceipts.com`** για
    **όλους** τους τύπους Cardlink, με τα παραγωγικά credentials της Mellon (topic 7000043432).
  - Λοιπά [Β]: NSP κωδ. Cardlink **122** · κωδικοί παρόχων στο site ΑΑΔΕ (7000043515) · στο VPOS provider id `00X` = test
    κλειδιά, `50X` = production κλειδιά· φυσικό τερματικό μόνο production (7000043546) · `uniqueIntegratorId` μόνο VPOS
    (7000043550) · **Unique Payment ID** = acquirer + batch + sequence + approval → void/refund έχουν **νέο** ID (7000043300) ·
    prepayment vs sale: για το POS ίδια συναλλαγή (7000043301) · υπογραφή base64, τα πεδία επαλήθευσης **ακριβώς** όπως τα
    έδωσε ο πάροχος (χωρίς υποδιαστολές/κενά) + provider id (7000043722) · IRIS: `CardType` "IRIS", PAN αστεράκια, AuthCode
    "000000", RRN έως 35 (7000044018/44019).
  - Το «Cloud ERP» με κωδικό σύζευξης (quick guides παραπάνω) ταιριάζει με το «Link with 3rd party services» του WebECR. [Π]
- **Συμπέρασμα (ισχύει):** για το κατάστημα → **driver `webecr`** (Common Web/Mellon): UAT της Mellon → σενάρια → πιστοποίηση
  → πιλοτικό στο κατάστημα με το Cardlink τερματικό του → `wl.mreceipts.com`. Η Viva (demo + Tap-on-Phone) μένει δεύτερη
  υλοποίηση. Ένα flow (υπογραφή παρόχου → πώληση → αποτέλεσμα → υποβολή), drivers `webecr` + `viva_cloud`.

## Mellon WebECR — διαδικασία πιστοποίησης + πρωτόκολλο (v2.5.13) — 2026-10-06

> Spec στο repo: **`docs/EFTPOS-WebECR v2.5.13.pdf`** (δημοσ. 7/10/2025). Σελίδα για ERP: **https://aade.mellongroup.com/**
> («Βήματα Διασύνδεσης», «Συχνές Ερωτήσεις», λίστα πιστοποιημένων ERP). Αφορά τα τερματικά Mellon (Ingenico — NBG Pay,
> PayzyPOS κ.ά.) **ΚΑΙ όλα τα Cardlink τερματικά όταν το ERP είναι cloud** (βλ. ενότητα Cardlink) → **είναι ο δρόμος για το
> κατάστημα**. Sandbox με ένα email, χωρίς φυσικό τερματικό. Production για Cardlink: `https://wl.mreceipts.com`.

### Η «γραφειοκρατία» — 5 βήματα (ισχύουν και για Cardlink μέσω Common Web)

1. **Simulator (UAT).** Email στο **mellonwebecr@mellongroup.com** με: (α) ένα email που γίνεται το username του λογαριασμού,
   (β) αν χρησιμοποιείται ΦΗΜΑΣ ή πάροχος και **το provider id του παρόχου** (→ του InvoSign: να το επιβεβαιώσει η GV Solutions),
   (γ) την επίσημη επωνυμία της εταιρείας. **Ο λογαριασμός είναι του ΚΑΤΑΣΚΕΥΑΣΤΗ του ERP (εμείς — «3rd party servicer»),
   όχι του εμπόρου:** ένας για όλο το ekdosi· κάθε κατάστημα συνδέει το τερματικό του με κωδικό από το μενού του POS
   (API key ανά έμπορο). Username = ένα μόνιμο role-mailbox της εταιρείας ανάπτυξης, όχι προσωπικό. Έρχεται email με URL → **Authorization Code (λήγει σε 5', resend γίνεται)** →
   το ERP τον κάνει redeem (§3.7.3) → TXN INIT (§3.7.5). Στο sandbox δεν υπάρχει τερματικό: προσομοιωμένες απαντήσεις,
   έγκριση/απόρριψη «ρεαλιστικά» από αλγόριθμο. **Ο λογαριασμός ανοίγει από τον ιδιοκτήτη (email), όχι από εμάς.**
   - Token: `https://uat.mreceipts.com/api/token` και `…/api/token/refresh`
   - Όλα τα άλλα: `https://uat.mreceipts.com/api/v2.2/` (π.χ. `…/v2.2/authorization/redeem/`, `…/v2.2/terminal/`)
   - Βοηθητικό: «Διαδικασία Δημιουργίας Σφραγίδας από Παρόχους» v1.5 (πώς υπογράφει ο **πάροχος** — πληροφοριακό για εμάς):
     https://aade.mellongroup.com/Portals/0/Library/Token%20crypto%20proposal%20-%20v1.5.pdf
   - FAQ «Provider id — public key»: **ο ΠΑΡΟΧΟΣ** στέλνει το test public key του και η Mellon του αναθέτει provider id. Εμείς
     δεν είμαστε πάροχος → **να ρωτηθεί η Mellon αν έχει ήδη το test κλειδί του InvoSign** (αλλιώς η επαλήθευση της
     υπογραφής στο UAT θα αποτυγχάνει) ή αν δέχεται τα δοκιμαστικά κλειδιά της ΑΑΔΕ. [;]
2. **Προετοιμασία ERP για τα 19 σενάρια** (v2.4.2 — υπάρχει και ως Excel στη σελίδα). Όλα με κλήσεις WebECR:
   | # | σενάριο | τι σημαίνει για τον driver |
   |---|---|---|
   | 1 | pairing ERP ↔ τερματικό / λίστα τερματικών | redeem κωδικού + `GET terminal/` |
   | 2–3 | πώληση 1 € / 2 € (chip, contactless) → έγκριση | `txninit` TxnType 0 |
   | 4 | ακύρωση (void) της πώλησης 1 € από το ERP | `txnvoid` |
   | 5 | επιστροφή 2 € συσχετισμένη | TxnType 1 + `InitialTransaction` |
   | 6 | επιστροφή τυχαίου ποσού **χωρίς** συσχέτιση | TxnType 1 χωρίς `InitialTransaction` |
   | 7 | δόσεις | `Instalments` |
   | 8–9 | **ετεροχρονισμένη** 2 €: προφόρτωση στο POS, πληρωμή αργότερα με κάρτα | `PreloadTransaction` + `PreloadExpiration` |
   | 10 | mail order 3 € | TxnType 4 |
   | 11–12 | προέγκριση (μόνο από το POS) → ολοκλήρωση από το ERP | TxnType 3 |
   | 13 | πώληση 2 € — **διακοπή από τον χειριστή** (κόκκινο κουμπί) | Result 3 CANCELLED |
   | 14 | πώληση **9,99 €** → **απόρριψη** από τον host | Result 2 DECLINED |
   | 15 | πώληση **22,22 €** → έγκριση **μετά από 150''** | περιμένουμε/κάνουμε poll — όχι timeout στα 180'' |
   | 16 | πώληση **13,13 €** → **χάνεται η επικοινωνία** POS↔ERP την ώρα της έγκρισης | `in_doubt` + ανάκτηση με poll |
   | 17 | τακτοποίηση (το τερματικό πρώτα, μετά το ERP): προφορτωμένες, επιστροφές, προεγκρίσεις | λίστα intents/transactions |
   | 18 | κλείσιμο πακέτου στο POS — το ERP δεν παίρνει απάντηση | Result 7 MAX_TRANSACTIONS αν δεν γίνει |
   | 19 | έλεγχος διαφορών ERP ↔ POS | `GET transaction/` ανά ημέρα — συμφωνία με τα `pos_card_payments` |

   Τα «μαγικά» ποσά (9,99 / 22,22 / 13,13) να τα μιμείται και ο **`FakeDriver`** — ίδια σενάρια στα tests μας.
3. **Ραντεβού δοκιμών** με email στο **mellonerpappointment@mellongroup.com**, Subject «ERP Testing εξ αποστάσεως» ή «ERP Testing
   στο Mellon Lab». Τα τερματικά + μηχανικός είναι πάντα στο Mellon Lab (και στις εξ αποστάσεως).
4. **ERP 2 POS Certification** από τη Mellon μετά το end-to-end των σεναρίων → μπαίνουμε στη λίστα πιστοποιημένων ERP.
5. **Πιλοτικό** σε έναν έμπορο, σε παραγωγή.

(+ από τη δική μας πλευρά, ανεξάρτητα: **Δήλωση Συμβατότητας Α.1054/2024** στην ΑΑΔΕ ανά δίκτυο.)

### Το πρωτόκολλο σε μία σελίδα

- **REST/JSON, πεδία PascalCase, ποσά σε λεπτά (int), `CurrencyCode` 978.** Υποχρέωση: αγνοούμε άγνωστα πεδία. Σφάλματα: HTTP
  400 (λάθος αίτημα — π.χ. λείπουν τα ProviderData όπου απαιτούνται) / 401 / 403 / 404 / 500.
- **Auth:** JWT — `POST token/` (username/password) → `access` + `refresh`· `Authorization: Bearer …`.
  - *Direct* (2.2.1): ο λογαριασμός ανήκει στον έμπορο και του αντιστοιχίζονται τα τερματικά του.
  - ***3rd party servicer*** (2.2.2 — **αυτό είναι το ekdosi**, SaaS πολλών εμπόρων): ένας λογαριασμός-πελάτης του ekdosi χωρίς
    τερματικά + **`X-Api-Key` ανά έμπορο**: στο POS μενού «Link with 3rd party services» → κωδικός → ο χρήστης τον πληκτρολογεί στο
    ekdosi → `POST authorization/redeem/` `{"Type":"webecr","Code":"…"}` → `Id` = API key. **Δεν λήγει ποτέ** (FAQ), αλλά ο
    έμπορος μπορεί να το ανακαλέσει → 401/403 = «χρειάζεται νέα σύζευξη», όχι σφάλμα πληρωμής.
- **Τερματικά:** `GET terminal/` (φίλτρα `TerminalID`, `Merchant`, `Acquirer`) → `id`, `TerminalID`, `Merchant`, `Acquirer`.
  ⚠ Στο `terminal/{id}/…` μπαίνει το **`id`** της λίστας, **ΟΧΙ** το TID (FAQ).
- **Πώληση:** `POST terminal/{id}/txninit/` — `TxnType` (0 πώληση · 1 επιστροφή · 2 προέγκριση · 3 ολοκλήρωση · 4 MOTO …),
  `Amount`, `CurrencyCode`, **`CustomerReference`** (δικό μας, ≤50 — το uuid `session_ref` = idempotency/ανάκτηση),
  `Instalments`, `PreloadTransaction`/`PreloadExpiration` (λεπτά), `InitialTransaction` (επιστροφή), `PaymentType` (0 κάρτα · 1 IRIS),
  **`Timeout`** (0 = ασύγχρονα· έως **180''** σύγχρονα) και **`ProviderData`**:
  `Uid`, `Mark` (null στην ταυτόχρονη), `SignatureTimestamp` (`YYYYMMDDhhmmss`, ώρα Ελλάδας — ίδιο με την υπογραφή),
  `NetAmount`, `VatAmount`, `TotalAmount` (λεπτά), `ProviderId`, `Signature`. Το WebECR επαληθεύει με
  Uid/Mark/timestamp/Amount/Net/Vat/Total **+ το TID του τερματικού** → το `TerminalID` που δίνουμε στο InvoSign `GetPayment`
  πρέπει να είναι το **`TerminalID` της λίστας**. (Εναλλακτικά `EcrTokenData` + `ecrbind`/`ecrkeyexchange` — μόνο για ΦΗΜΑΣ, όχι εμάς.)
- **Απάντηση/intent:** `Status` 1 PENDING · 2 SENT · 3 COMPLETED — `Result` 1 APPROVED · 2 DECLINED · 3 CANCELLED · 4 FAILED ·
  **5 UNKNOWN** (→ `in_doubt`) · 6 BUSY · 7 MAX_TRANSACTIONS (κλείσιμο πακέτου). Στην έγκριση: **`TransactionId`**
  (`"075;RRN;AuthCode"` = το Unique Payment ID → myDATA `transactionId`) και `Transaction` {`TID`, `MID`, `RRN`, `AuthCode`,
  `CardPAN` (masked), `CardType`, `STAN`, `BatchNumber`, `Instalments`, `CustomerReceipt`/`MerchantReceipt`}.
- **Ανάκτηση (timeout / async / σενάριο 16):** `GET transactionintent/?CustomerReference=…` ή `transactionintent/{id}/` · συναλλαγές
  `GET transaction/` (φίλτρα ημερομηνίας/TID/RRN — η συμφωνία του σεναρίου 19). Σελιδοποίηση `count/next/previous/results`.
- **Webhook:** εναλλακτικά του polling — **το callback URL ορίζεται μία φορά στη δημιουργία του λογαριασμού** (άρα να το δώσουμε
  στο email του βήματος 1 αν το θέλουμε· στην αρχή αρκεί polling).
- **Ακύρωση:** `POST terminal/{id}/txnvoid/` `{OriginalIdentifier, OriginalIdentifierType: 1 intent id | 2 TransactionId, Timeout}`.
- **Ηλεκτρονική απόδειξη τερματικού:** `GET r/{ReceiptReference}` → PNG (προαιρετικό).

**Αντιστοίχιση στο σχέδιο (`docs/pos-terminals-design.md`):** driver `webecr`· στο `pos_terminals` → `terminal_ref` = TID,
`config` = { webecr `id`, **API key** (κρυπτογραφημένο) }· τα username/password του λογαριασμού-πελάτη είναι **του ekdosi** (ένα ανά
περιβάλλον UAT/live — ρύθμιση εγκατάστασης, όχι εταιρείας). Η σύζευξη (κωδικός από το μενού του POS) μοιάζει με το «Cloud ERP» της
Cardlink → ίδια οθόνη «Σύζευξη τερματικού» για όλους τους drivers.

> **Σχέδιο υλοποίησης** (τερματικά, θέσεις ταμείου, failover, κάρτα σε τιμολόγια, φάσεις) → **`docs/pos-terminals-design.md`**.

## Επόμενα βήματα

1. ✅ Το κατάστημα έχει **Cardlink** (✅ εγγραφή στο μητρώο Cardlink 2026-10-06 — απάντησαν με το forum). Για cloud ERP →
   **Common Web = Mellon WebECR**: **email στο mellonwebecr@mellongroup.com** για λογαριασμό UAT (ο ιδιοκτήτης). Ο VPOS
   simulator της Cardlink δεν χρειάζεται για εμάς.
2. Ερώτηση στην GV Solutions (InvoSign): δοκιμασμένοι acquirers · χρεώνει credit το `GetPayment`; · **ποιο είναι το provider id
   τους και έχουν δώσει το test public key στη Mellon (WebECR UAT);**
3. Sandbox: InvoSign `GetPayment` + Mellon UAT (username-email · πάροχος InvoSign + provider id · επωνυμία) · Viva demo δεύτερο.
4. Δήλωση Συμβατότητας Α.1054/2024 για το ekdosi πριν τη ζωντανή χρήση.
5. Θέσεις ταμείου (registers) στο ίδιο PR: ένα τερματικό (TerminalID) ανά θέση.
