# Διακίνηση μέσω παρόχου — το split-brain του lifecycle (blueprint)

> Status: **✅ ΛΥΘΗΚΕ & ΕΠΙΒΕΒΑΙΩΘΗΚΕ ΓΡΑΠΤΩΣ.** Μετά το InvoSign reference
> (invosign.gr/site/help_site) ξεκαθάρισε ότι ο πάροχος κάνει ΜΟΝΟ έκδοση +
> ακύρωση δελτίου· η κίνηση είναι myDATA-native. Υλοποιήθηκε αναλόγως:
> **έκδοση + ακύρωση → πάροχος· έναρξη/παράδοση/έλεγχος/history → απευθείας myDATA**
> (gated στα myDATA creds). Δεν υπάρχει πλέον split-brain ούτε blanket guard.
>
> **Επιβεβαίωση παρόχου (Β. Καρίνος, InvoSign, email):** «τα endpoints του παρόχου
> είναι αυτά που βλέπετε στο API guide … η **Β' φάση** του ψηφιακού δελτίου
> αποστολής αναφέρεται στα **ERP** όχι στον πάροχο». Δηλαδή η Β' φάση (= ο κύκλος
> κίνησης: RegisterTransfer/ConfirmDeliveryOutcome/RequestDeliveryNoteStatus) είναι
> ευθύνη του ERP απευθείας προς myDATA — ΑΚΡΙΒΩΣ το μοντέλο που υλοποιήσαμε. Το
> open item κλείνει· καμία περαιτέρω ενέργεια.

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

## ⓘ Τι εκθέτει ΟΝΤΩΣ ο InvoSign (από το επίσημο reference, invosign.gr/site/help_site)
| myDATA DGM ενέργεια | InvoSign endpoint |
|---|---|
| Υποβολή (έκδοση ΔΑ, `isDeliveryNote=1`) | ✅ `iNVOSign_Api.php` |
| Ακύρωση ΔΑ | ✅ `iNVOSign_CancelDeliveryNote.php` (by MARK → cancellationMark) |
| Έλεγχος κατάστασης | ⚠️ `invoice_status.php` — επιστρέφει **transmission status** (invoiceMark/statusCode), ΟΧΙ την §7.1 κίνηση ούτε lifecycleHistory |
| RegisterTransfer (έναρξη) | ❌ δεν υπάρχει |
| ConfirmDeliveryOutcome (παράδοση) | ❌ δεν υπάρχει |

**Συνέπεια:** option A (πλήρες lifecycle μέσω παρόχου) **ΑΔΥΝΑΤΟ** — ο InvoSign δεν
έχει endpoints έναρξης/παράδοσης/κίνησης. Η **κίνηση είναι myDATA-native** (ο
εκδότης/μεταφορέας τη δηλώνει απευθείας στο myDATA· ο πάροχος κάνει μόνο έκδοση +
ακύρωση). Άρα το ρεαλιστικό μοντέλο:
- **Έκδοση → πάροχος** (έγινε).
- **Ακύρωση → μπορεί μέσω παρόχου** (`iNVOSign_CancelDeliveryNote.php`) → να γίνει
  channel-aware (όπως `GrProviderSubmitter::cancel` για τα τιμολόγια).
- **Έναρξη/Παράδοση/Έλεγχος-κίνησης/History → myDATA-only** (απευθείας), εφόσον ο
  tenant έχει myDATA creds. Αυτό ΔΕΝ είναι «split-brain» — είναι η σχεδίαση του
  myDATA (έκδοση μέσω παρόχου, tracking απευθείας). Μένει η επιβεβαίωση creds/ΑΑΔΕ.

Η σχηματική απεικόνιση + τα προαιρετικά πεδία `API_Additionals`
(`DocumentDispatchFrom/To`, `DocumentMovePursposeLabel`) που στέλνουμε πλέον στη ΔΑ
ταιριάζουν 1:1 με το delivery example του παρόχου.

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

### ✅ ΥΛΟΠΟΙΗΜΕΝΟ μοντέλο (μετά το InvoSign reference)
Το reference απέκλεισε το A (ο InvoSign δεν έχει register/confirm/movement-status
endpoints). Υλοποιήθηκε ο φυσικός συνδυασμός:
- **Έκδοση → πάροχος** (`DeliveryNoteSubmitter::submitViaProvider`).
- **Ακύρωση → πάροχος** (`DeliveryLifecycleService::cancelViaProvider` →
  `iNVOSign_CancelDeliveryNote`), για provider tenants· direct `CancelInvoice` για
  gr-mydata. Η INSERT-MARK αναζήτηση καλύπτει πλέον και `PROVIDER_INSERT`.
- **Έναρξη / Παράδοση / Έλεγχος / History → απευθείας myDATA** (firebed), για
  ΟΛΟΥΣ. Ο interim guard **αφαιρέθηκε**· το gating το κάνει ο υπάρχων έλεγχος
  creds στο `initFirebed()` (provider tenant χωρίς myDATA creds → σαφές μήνυμα).
- UI: και τα 4 actions ξανα-εμφανίζονται για provider tenants.
- Test: `DeliveryLifecycleServiceTest::test_provider_tenant_cancel_routes_via_provider`.

## Εκκρεμεί
Τίποτα. Ο πάροχος επιβεβαίωσε γραπτώς (βλ. Status) ότι η Β' φάση (κίνηση) είναι
ευθύνη του ERP απευθείας προς myDATA — δεν υπάρχουν provider endpoints γι' αυτήν.
Το υλοποιημένο μοντέλο είναι το οριστικό.

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
