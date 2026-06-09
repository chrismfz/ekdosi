# Διακίνηση μέσω παρόχου — το split-brain του lifecycle (blueprint)

> Status: **διερεύνηση / απόφαση εκκρεμεί.** Κανένας κώδικας lifecycle-via-provider
> δεν έχει γραφτεί ακόμα. Συνοδεύει το fix του `[88-006]` (έκδοση δελτίου μέσω
> παρόχου — βλ. `InvoSignDocument::augmentDelivery`).

## Το πρόβλημα σε μία πρόταση
Για tenant παρόχου (`einvoice_provider='gr-provider'`, π.χ. `myip`/InvoSign) η
**έκδοση** του δελτίου πάει μέσω παρόχου, αλλά **όλος ο κύκλος ζωής** (έναρξη /
παράδοση / έλεγχος / ακύρωση) πάει **απευθείας στο AADE myDATA** — δύο διαφορετικά
κανάλια για το ίδιο παραστατικό.

## Πού φαίνεται στον κώδικα
| Ενέργεια | Δρομολόγηση σήμερα | Αρχείο |
|---|---|---|
| ΕΚΔΟΣΗ (SendInvoices) | `isLiveProviderTenant()` → **πάροχος** (`submitViaProvider`) | `DeliveryNoteSubmitter::submit` |
| ΕΝΑΡΞΗ (RegisterTransfer) | **απευθείας myDATA** (`initFirebed`) | `DeliveryLifecycleService::registerTransfer` |
| ΠΑΡΑΔΟΣΗ (ConfirmDeliveryOutcome) | **απευθείας myDATA** | `…::confirmDelivery` |
| ΕΛΕΓΧΟΣ (RequestDeliveryNoteStatus) | **απευθείας myDATA** | `…::refreshStatus` |
| ΑΚΥΡΩΣΗ (CancelInvoice by MARK) | **απευθείας myDATA** | `…::cancel` |

`DeliveryLifecycleService` **δεν ελέγχει καθόλου** `isLiveProviderTenant()` — κάθε
μέθοδος καλεί `initFirebed()` και χτυπά το myDATA endpoint με το **δικό** subscription
του tenant.

Ο `EInvoiceProviderTransport` (contract) εκθέτει μόνο `send / sendDelivery /
cancel / status / ping` — **κανένα** register/confirm/status-lifecycle. Ο InvoSign
έχει `iNVOSign_CancelDeliveryNote.php` (ένα cancel endpoint) αλλά το lifecycle
reference (`docs/paroxos/research/invosign-api-reference.md`) **δεν τεκμηριώνει**
RegisterTransfer/ConfirmOutcome/Status.

## Γιατί έχει σημασία (το ρίσκο)
1. **Ασυνέπεια καναλιού.** Κανονιστικά, ο πάροχος υποβάλλει εκ μέρους του tenant·
   τα γεγονότα διακίνησης είναι κι αυτά υποβολές με δικό τους MARK. Μικτή χρήση
   (έκδοση→πάροχος, lifecycle→απευθείας) δεν είναι εγγυημένα αποδεκτή.
2. **Credentials.** Ένας «καθαρός» tenant παρόχου μπορεί να **μην έχει** έγκυρα
   δικά του myDATA prod creds — οπότε τα lifecycle calls θα βγάζουν auth error
   στην παραγωγή (στο sandbox «δούλεψε» μόνο επειδή ο `myip` γυρίστηκε προσωρινά σε
   direct-myDATA με dev creds).
3. **Διευθυνσιμότητα του MARK.** Το MARK που γυρίζει ο πάροχος μπορεί να μην είναι
   απευθείας query-άσιμο από το tenant subscription στο `RequestDeliveryNoteStatus`.
4. **Σιωπηλή αστοχία.** Σήμερα δεν υπάρχει guard — ο operator πατάει «Έναρξη
   διακίνησης» και είτε πετυχαίνει σε λάθος κανάλι είτε αποτυγχάνει με γενικό
   σφάλμα, χωρίς να ξέρει γιατί.

**Τι ΕΧΕΙ επικυρωθεί:** το direct-myDATA μονοπάτι end-to-end (έκδοση→…→έλεγχος)
και το `lifecycleHistory` feature — γιατί το sandbox test έτρεξε όλο από myDATA.
**Τι ΔΕΝ έχει επικυρωθεί:** το μονοπάτι παρόχου (έκδοση **και** lifecycle).

## Επιλογές
### A) Ο πάροχος αναλαμβάνει και το lifecycle  *(πλήρες, μακροπρόθεσμο)*
Επέκταση του `EInvoiceProviderTransport` με `registerTransfer / confirmOutcome /
requestDeliveryStatus / cancelDelivery`· ο `DeliveryLifecycleService` δρομολογεί
μέσω παρόχου όταν `isLiveProviderTenant()`, αλλιώς direct (όπως τώρα).
- **Καθαρό:** ένα κανάλι ανά παραστατικό.
- **Μπλοκαρισμένο:** απαιτεί ο InvoSign να **εκθέτει όντως** αυτά τα endpoints —
  σήμερα άγνωστο/μη τεκμηριωμένο. Χρειάζεται επιβεβαίωση από τον πάροχο.

### B) Επιτρεπτό το direct-myDATA lifecycle για tenant παρόχου  *(αν το λέει η ΑΑΔΕ)*
Κρατάμε το direct lifecycle, αλλά **επικυρώνουμε** με ΑΑΔΕ/πάροχο ότι ένα δελτίο
που εκδόθηκε μέσω παρόχου μπορεί να γίνει lifecycle απευθείας με τα myDATA creds
του tenant — και διασφαλίζουμε ότι ο tenant **έχει** έγκυρα myDATA creds (νέο
preflight check).
- **Φθηνό:** ελάχιστος κώδικας.
- **Ρίσκο:** εξαρτάται από κανονιστική επιβεβαίωση· πιθανώς δεν ισχύει για καθαρό
  πάροχο χωρίς myDATA subscription.

### C) Interim guard  *(✅ ΥΛΟΠΟΙΗΘΗΚΕ)*
Ο `DeliveryLifecycleService` αποτρέπει ρητά κάθε direct-myDATA lifecycle κλήση για
tenant παρόχου: ο guard ζει στο **single choke-point `initFirebed()`** (απ' όπου
περνούν register/confirm/status/cancel — και κάθε μελλοντική), ρίχνει σαφές
ελληνικό μήνυμα αντί για σιωπηλό split-brain. Στο UI (`ViewDeliveryNote`) τα 4
lifecycle actions είναι **κρυμμένα** για provider tenants (`! $isProviderChannel`).
Η **έκδοση** μέσω παρόχου ΔΕΝ επηρεάζεται. Αναστρέψιμο: μόλις κριθεί A ή B, αφαιρείς
τον guard (ή τον κάνεις conditional). Test: `DeliveryLifecycleServiceTest::
test_provider_tenant_is_blocked_from_every_direct_lifecycle_call`.

## Σύσταση
**C έγινε** (κλείνει το ρίσκο άμεσα). Επόμενο: **ερώτημα στον πάροχο/ΑΑΔΕ** που
ξεκλειδώνει A ή B. Μη γράψεις A/B πριν την απάντηση — και τα δύο εξαρτώνται από
εξωτερικά άγνωστα (endpoints του InvoSign / κανονιστική θέση της ΑΑΔΕ).

## Ανοιχτά ερωτήματα (για πάροχο/ΑΑΔΕ)
1. Εκθέτει ο InvoSign endpoints για RegisterTransfer / ConfirmDeliveryOutcome /
   RequestDeliveryNoteStatus; (το CancelDeliveryNote υπάρχει ήδη.)
2. Επιτρέπει η ΑΑΔΕ lifecycle calls **απευθείας** από tenant που εκδίδει μέσω
   παρόχου; Αν ναι, χρειάζεται ξεχωριστό myDATA subscription ο tenant;
3. Το issue-MARK του παρόχου είναι το ίδιο που δέχονται τα lifecycle endpoints
   (πάροχου ή myDATA);

## Τι να επικυρωθεί στο sandbox (αφού μπει το 88-006 fix)
- Έκδοση δελτίου **μέσω InvoSign sandbox** (όχι flip σε direct) — να περάσει το
  `API_InvoiceDetails` (κανένα νέο `[88-0xx]`)· κράτα το raw `xml_arxeio`.
- Αν ο πάροχος έχει lifecycle endpoints: δοκίμασε έναν κύκλο μέσω παρόχου.
- Αλλιώς: τεκμηρίωσε το auth/κανάλι αποτέλεσμα ενός direct lifecycle call πάνω σε
  ένα **μέσω-παρόχου-εκδοθέν** δελτίο.
