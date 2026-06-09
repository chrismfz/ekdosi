## myDATA Ηλεκτρονικά Βιβλία ΑΑΔΕ 

## **Τεχνική περιγραφή διεπαφών REST API για το Ψηφιακό Δελτίο Αποστολής Έκδοση 2.0.1 – Ιανουάριος 2026** 

## **Πίνακας περιεχομένων** 

|Πίνακας περιεχομένων .............................................................................................................. 1|Πίνακας περιεχομένων .............................................................................................................. 1|
|---|---|
|1|Εισαγωγή ........................................................................................................................... 3|
||1.1<br>Σκοπός ....................................................................................................................... 3|
||1.2<br>Ο Κύκλος Ζωής της Διακίνησης ................................................................................. 3|
||1.3<br>Οι Ρόλοι ..................................................................................................................... 5|
|2|Τεχνολογικές απαιτήσεις λογισμικών έκδοσης παραστατικών ........................................ 5|
|3|Περιγραφή REST API .......................................................................................................... 5|
||3.1<br>Περιγραφή λειτουργίας των διεπαφών .................................................................... 6|
||3.1.1<br>Απαραίτητα Headers ......................................................................................... 6|
||3.2<br>Περιγραφή νέων λειτουργιών ................................................................................... 6|
||3.2.1<br>RegisterTransfer ................................................................................................ 6|
||3.2.2<br>ConfirmDeliveryOutcome .................................................................................. 7|
||3.2.3<br>RejectDeliveryNote ............................................................................................ 9|
||3.2.4<br>GetDeliveryNoteStatus .................................................................................... 10|
||3.2.5<br>GenerateGroupQRCode .................................................................................. 10|
||3.2.6<br>RequestGroupQRDetails .................................................................................. 11|
|4|Περιγραφή Σχημάτων ...................................................................................................... 13|
||4.1<br>Σχήμα DeliveryEventType (Ιστορικό Γεγονότων Διακίνησης) ................................. 13|
||4.2<br>Σχήμα TransportDetailType (Λεπτομέρειες Μεταφοράς) ....................................... 15|
||4.3<br>Σχήμα OutcomeDetailsType (Λεπτομέρειες Αποτελέσματος Παράδοσης) ............ 16|
||4.4<br>Σχήμα PackagingDetailType (Πληροφορίες Συσκευασίας) ..................................... 17|
||4.5<br>Σχήμα RejectionDetailsType (Λεπτομέρειες Απόρριψης) ....................................... 17|
|5|Περιγραφή Απαντήσεων ................................................................................................. 18|
||5.1<br>Υποβολή Δεδομένων ............................................................................................... 18|
||5.2<br>Λήψη Κατάστασης (DeliveryNoteStatusResponse) ................................................. 19|
||5.3<br>Δημιουργίας Ομαδικού QR (GenerateGroupQRCodeResponse) ............................ 20|
|6|Σφάλματα ........................................................................................................................ 21|
||6.1<br>Τεχνικά Σφάλματα ................................................................................................... 21|
||6.2<br>Επιχειρησιακά Σφάλματα ........................................................................................ 22|
|7|Παράρτημα ...................................................................................................................... 25|
||7.1<br>Καταστάσεις Δελτίου Αποστολής (InvoiceDeliveryStatus) ..................................... 25|



1 

myDATA REST API 

||7.2|Τύποι Γεγονότων (DeliveryEventType) .................................................................... 25|
|---|---|---|
||7.3|Τύποι Συσκευασίας (PackagingType) ...................................................................... 26|
||7.4|Είδος Μεταφορικού Μέσου (transportType) ......................................................... 26|
|8|Ιστορικό αλλαγών ............................................................................................................ 27||
||8.1|Έκδοση 2.0.0 ............................................................................................................ 27|
||8.2|Έκδοση 2.0.1 ............................................................................................................ 27|



2 

myDATA REST API 

## **1 Εισαγωγή** 

## 1.1 **Σκοπός** 

Αυτό το έγγραφο περιγράφει την τεχνική διεπαφή REST API για τη λειτουργικότητα του **Ψηφιακού Δελτίου Αποστολής (ΔΑ)** της πλατφόρμας myDATA. Απευθύνεται σε Παρόχους Ηλεκτρονικής Τιμολόγησης και προγραμματιστές συστημάτων ERP που επιθυμούν να ενσωματώσουν τη λειτουργικότητα αυτή για λογαριασμό των πελατών τους. 

Σκοπός της λειτουργικότητας είναι η παρακολούθηση της διακίνησης αγαθών σε πραγματικό χρόνο, από την έκδοση του παραστατικού διακίνησης έως την τελική παραλαβή του, αυξάνοντας τη διαφάνεια και την ασφάλεια στην εφοδιαστική αλυσίδα. 

## 1.2 **Ο Κύκλος Ζωής της Διακίνησης** 

Κάθε Δελτίο Αποστολής διέπεται από έναν κύκλο ζωής που αποτελείται από συγκεκριμένες καταστάσεις. Η μετάβαση από τη μία κατάσταση στην επόμενη πραγματοποιείται μέσω των μεθόδων API που περιγράφονται παρακάτω. 

Οι βασικές καταστάσεις είναι: 

- **Registered:** Το ΔΑ έχει εκδοθεί επιτυχώς και έχει λάβει ΜΑΡΚ. Η διακίνηση δεν έχει ξεκινήσει. 

- **InTransit:** Η διακίνηση έχει ξεκινήσει μετά από παραλαβή από τον πρώτο μεταφορέα. 

- **DeliveredByCarrier:** (Για B2B) Ο μεταφορέας δήλωσε ότι παρέδωσε, αλλά ο λήπτης δεν έχει ακόμη επιβεβαιώσει. 

- **Completed:** Η διακίνηση ολοκληρώθηκε επιτυχώς (είτε με επιβεβαίωση λήπτη, είτε με δήλωση μεταφορέα σε B2C). 

- **Rejected:** Ο λήπτης απέρριψε ολικά την παραλαβή. 

- **Cancelled:** Ο εκδότης ακύρωσε το ΔΑ πριν την έναρξη της διακίνησης. 

- **FailedDelivery:** Ο μεταφορέας δήλωσε αποτυχία παράδοσης. 

Ακολουθεί διάγραμμα καταστάσεων κύκλου ζωής του Ψηφιακού Δελτίου αποστολής. 

3 

myDATA REST API 

_Εικόνα 1: Διάγραμμα Καταστάσεων_ 

**==> picture [74 x 9] intentionally omitted <==**

**----- Start of picture text -----**<br>
myDATA REST API<br>**----- End of picture text -----**<br>


4 

## 1.3 **Οι Ρόλοι** 

- **Εκδότης (Issuer):** Η οντότητα που εκδίδει το παραστατικό διακίνησης. 

- **Μεταφορέας (Carrier):** Η οντότητα που αναλαμβάνει τη φυσική μεταφορά των αγαθών. Μπορεί να υπάρχουν πολλοί μεταφορείς σε μία διακίνηση (μεταφόρτωση). 

- **Λήπτης (Recipient):** Η οντότητα που είναι ο τελικός παραλήπτης των αγαθών. 

## **2 Τεχνολογικές απαιτήσεις λογισμικών έκδοσης παραστατικών** 

Για την υλοποίηση της επικοινωνίας ενός συστήματος λογισμικού με τις διεπαφές χρησιμοποιούνται οι παρακάτω τεχνολογίες 

- HTTPS – Secure HTTP 

- Webservice 

- REST API – REST interface required for the data reporting process 

- XML – eXtensible Markup Language 

Οι διεπαφές μπορεί να χρησιμοποιηθούν από οποιοδήποτε λογισμικό που μπορεί να υλοποιήσει HTTPS κλήσεις και να δημιουργήσει έγγραφα XML συμβατά με το σχήμα που περιγράφεται στο παρόν έγγραφο. 

Εκτός των σχετικών δεδομένων, το λογισμικό θα πρέπει να μπορεί να στείλει ταυτόχρονα και αυτοματοποιημένα και τις απαραίτητες πληροφορίες για την ταυτοποίηση του χρήστη μέσω της ίδιας HTTPS κλήσης. 

## **3 Περιγραφή REST API** 

Συνοπτικά, η διεπαφή παρέχει τις εξής λειτουργίες-μεθόδους: 

- **/RegisterTransfer:** διαδικασία δήλωσης έναρξης ή μεταφόρτωσης διακίνησης από μεταφορέα. 

- **/ConfirmDeliveryOutcome:** διαδικασία δήλωσης αποτελέσματος παράδοσης από μεταφορέα ή λήπτη. 

- 

   - **/RejectDeliveryNote:** διαδικασία ολικής απόρριψης διακίνησης από τον λήπτη. 

- **/GetDeliveryNoteStatus:** διαδικασία λήψης της κατάστασης και του ιστορικού ενός Δελτίου Αποστολής. 

- **/GenerateGroupQRCode:** διαδικασία δημιουργίας ομαδικού QR Code για πολλαπλά Δελτία Αποστολής. 

- **/RequestGroupQRDetails:** διαδικασία ανάκτησης των λεπτομερειών και των συσχετιζόμενων QR Codes ενός Ομαδικού QR Code. 

5 

myDATA REST API 

Λεπτομερής περιγραφή των λειτουργιών περιγράφονται σε επόμενο τμήμα αυτού του εγγράφου. 

## **3.1 Περιγραφή λειτουργίας των διεπαφών** 

## **3.1.1 Απαραίτητα Headers** 

Κάθε κλήση πρέπει να περιέχει με τη μορφή ζευγαριών-τιμών, τα παρακάτω headers,τα οποία είναι απαραίτητα για την ταυτοποίηση του χρήστη. Σε περίπτωση λανθασμένων στοιχείων ο χρήστης θα λάβει μήνυμα σφάλματος. 

|KEY|Data Type|VALUE|DESCRIPTION|
|---|---|---|---|
|aade-user-id|String|{Όνομα Χρήστη}|Το όνομαχρήστητου λογαριασμού|
|ocp-apim-subscription-key|String|{Subscription Key}|Το subscription  keyτουχρήστη|



Μέσα από την ταυτοποίηση του χρήστη μέσω των headers η διεπαφή θα αποκτά πρόσβαση και στον ΑΦΜ που είχε δηλώσει ο χρήστης κατά την εγγραφή του, ώστε να μην είναι απαραίτητη η εισαγωγή αυτού του στοιχείου ξανά σε κάθε κλήση υπηρεσίας. 

## **3.2 Περιγραφή νέων λειτουργιών** 

## **3.2.1 RegisterTransfer** 

Η κλήση της μεθόδου RegisterTransfer είναι διαθέσιμη μέσω του ακόλουθου URL: 

https://mydatapi.aade.gr/myDATA/RegisterTransfer 

Η κλήση έχει τα ακόλουθα χαρακτηριστικά: 

- /RegisterTransfer, μέθοδος POST 

- Headers όπως αναφέρεται στην παράγραφο: 3.1.1 

- Body που αποτελείται από ένα στοιχείο Transport. Ο τύπος περιγράφεται από το παρακάτω διάγραμμα. Η δομή του στοιχείου TransportDetailType περιγράφεται και αναλύεται στο κεφάλαιο: 4.2 

6 

myDATA REST API 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό Πε**|**εωτικό Περιγραφή**|
|---|---|---|---|
|transferMark|xs:long|Ναι|Μοναδικός Αριθμός Καταχώρησης<br>του<br>γεγονότος<br>μεταφοράς.<br>Συμπληρώνεται<br>από<br>την<br>υπηρεσία.|
|qrUrl|xs:string|Ναι|Το URL του QR code του Δελτίου<br>Αποστολής ή του Ομαδικού QR<br>Code.|
|transportDetail|TransportDetailType Ναι|TransportDetailType Ναι|Αντικείμενο<br>που<br>περιέχει<br>τις<br>λεπτομέρειεςτης μεταφοράς.|



Παρατηρήσεις: 

1. Η μέθοδος καλείται από τον μεταφορέα για να δηλώσει την παραλαβή των αγαθών και την έναρξη της διακίνησης, ή την παραλαβή από προηγούμενο μεταφορέα (μεταφόρτωση). 

2. Με την επιτυχή κλήση, το Δελτίο Αποστολής μεταβαίνει σε κατάσταση InTransit. 

3. Σε περίπτωση επιτυχίας, η απόκριση περιέχει το transportMark, το οποίο είναι ο Μοναδικός Αριθμός Καταχώρησης του γεγονότος μεταφοράς. 

_***Σημείωση:**_ Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι διαθέσιμη στο URL: https://mydataapidev.aade.gr/RegisterTransfer 

## **3.2.2 ConfirmDeliveryOutcome** 

Η κλήση της μεθόδου ConfirmDeliveryOutcome είναι διαθέσιμη μέσω του ακόλουθου URL: 

https://mydatapi.aade.gr/myDATA/ConfirmDeliveryOutcome 

Η κλήση έχει τα ακόλουθα χαρακτηριστικά: 

- /ConfirmDeliveryOutcome, μέθοδος POST 

- Headers όπως αναφέρεται στην παράγραφο: 3.1.1 

7 

myDATA REST API 

- Body που αποτελείται από ένα στοιχείο ConfirmDeliveryOutcomeRequest. Ο τύπος περιγράφεται από το παρακάτω διάγραμμα. Η δομή του στοιχείου PackagingDetailType περιγράφεται και αναλύεται στο κεφάλαιο: 4.4 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό**|**Περιγραφή**|
|---|---|---|---|
|qrUrl|xs:string|Ναι|Το URL του QR code<br>του<br>Δελτίου<br>Αποστολής<br>ή<br>του<br>ΟμαδικούQR Code.|
|outcome|DeliveryOutcomeType|Ναι|Το αποτέλεσμα της<br>παράδοσης.<br>Αποδεκτές<br>τιμές<br>:<br>FULL,PARTIAL,NONE|
|deliveredWithoutRecipient|xs:boolean|Όχι|Η τιμή είναι true αν η<br>παράδοση<br>έγινε<br>χωρίς την παρουσία<br>του παραλήπτη.|
|deliveredPackaging|PackagingDetailType|Όχι|Λίστα<br>με<br>τις<br>συσκευασίες και τις<br>ποσότητες<br>που<br>παραδόθηκαν.|



Παρατηρήσεις: 

1. Η μέθοδος καλείται είτε από τον Μεταφορέα για να δηλώσει το αποτέλεσμα της παράδοσης, είτε από τον Λήπτη για να επιβεβαιώσει την παραλαβή. 

2. Αν κληθεί από Μεταφορέα σε B2B συναλλαγή, θέτει το ΔΑ σε κατάσταση DeliveredByCarrier. 

3. Αν κληθεί από Μεταφορέα σε B2C συναλλαγή, θέτει το ΔΑ σε κατάσταση Completed. 

4. Αν κληθεί από Λήπτη, θέτει το ΔΑ σε κατάσταση Completed. 

8 

myDATA REST API 

5. Η τιμή NONE για το πεδίο outcome θέτει το ΔΑ σε κατάσταση FailedDelivery. 

_***Σημείωση:**_ Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι διαθέσιμη στο URL: https://mydataapidev.aade.gr/ConfirmDeliveryOutcome 

## **3.2.3 RejectDeliveryNote** 

Η κλήση της μεθόδου RejectDeliveryNote είναι διαθέσιμη μέσω του ακόλουθου URL: 

https://mydatapi.aade.gr/myDATA/RejectDeliveryNote 

Η κλήση έχει τα ακόλουθα χαρακτηριστικά: 

- /RejectDeliveryNote, μέθοδος POST 

- Headers όπως αναφέρεται στην παράγραφο: 3.1.1 

- Body που αποτελείται από ένα στοιχείο RejectDeliveryNoteRequest. Ο τύπος περιγράφεται από το παρακάτω διάγραμμα : 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό Πε**|**εωτικό Περιγραφή**|
|---|---|---|---|
|qrUrl|xs:string Ναι (choice)|xs:string Ναι (choice)|Το URL του QR code του Δελτίου Αποστολής ή<br>του ΟμαδικούQR Code. Θα|
|invoiceMark|Xs:long|Ναι(choice)|Το ΜΑΡΚ του παραστατικού διακίνησης|
|rejectionReason xs:strin|ectionReason xs:stringΌ|Όχι|Περιγραφήτου λόγου απόρριψης.|



Παρατηρήσεις: 

1. Η μέθοδος καλείται αποκλειστικά από τον Λήπτη για να δηλώσει την ολική απόρριψη των ειδών του Δελτίου Αποστολής. 

2. Με την επιτυχή κλήση, το Δελτίο Αποστολής μεταβαίνει στην τελική κατάσταση Rejected. 

3. Σε περίπτωση επιτυχίας, η απόκριση περιέχει το rejectMark, το οποίο είναι ο Μοναδικός Αριθμός Καταχώρησης του γεγονότος απόρριψης. 

9 

myDATA REST API 

_*** Σημείωση:**_ Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι διαθέσιμη στο URL: https://mydataapidev.aade.gr/RejectDeliveryNote 

## **3.2.4 GetDeliveryNoteStatus** 

Αυτή η GET μέθοδος χρησιμοποιείται για την ανάκτηση της τρέχουσας κατάστασης και του πλήρους ιστορικού ενός Δελτίου Αποστολής και είναι διαθέσιμη μέσω του URL: 

= https://mydatapi.aade.gr/myDATA/GetDeliveryNoteStatus?mark {mark} 

|**Όνομα**<br>**Παραμέτρου**|**Υποχρεωτικό**|**Περιγραφή**|
|---|---|---|
|mark|Ναι|Ο Μοναδικός Αριθμός Καταχώρησης (ΜΑΡΚ) του<br>Δελτίου Αποστολής.|
|issuerVatNumber|Όχι|Το ΑΦΜ του εκδότη. Απαιτείται αν ο καλών δεν είναι<br>ο εκδότης.|



Παρατηρήσεις: 

1. Η μέθοδος επιστρέφει ένα αντικείμενο DeliveryNoteStatusResponse που περιέχει την τρέχουσα κατάσταση (status) και το ιστορικό (lifecycleHistory). Η δομή του στοιχείου DeliveryNoteStatusResponse  περιγράφεται και αναλύεται στο κεφάλαιο: 5.2 

2. Η κλήση επιτρέπεται στον εκδότη, τον λήπτη και σε οποιονδήποτε μεταφορέα συμμετείχε στη διακίνηση. 

_*** Σημείωση:**_ Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι = διαθέσιμη στο URL: https://mydataapidev.aade.gr/GetDeliveryNoteStatus?mark {mark} 

## **3.2.5 GenerateGroupQRCode** 

Η κλήση της μεθόδου GenerateGroupQRCode είναι διαθέσιμη μέσω του ακόλουθου URL: 

https://mydatapi.aade.gr/myDATA/GenerateGroupQRCode 

Η κλήση έχει τα ακόλουθα χαρακτηριστικά: 

- /GenerateGroupQRCode, μέθοδος POST 

- Headers όπως αναφέρεται στην παράγραφο: 3.1.1 

- Body που αποτελείται από ένα στοιχείο GenerateGroupQRCodeRequest. Ο τύπος περιγράφεται από το παρακάτω διάγραμμα : 

10 

myDATA REST API 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό Πε**|**εωτικό Περιγραφή**|
|---|---|---|---|
|qrUrls|QrUrlsType Ναι|QrUrlsType Ναι|Λίστα<br>με<br>τα<br>URL<br>των<br>QR<br>code<br>προς<br>ομαδοποίηση.|
|QrUrlsType xs:strin|e xs:string|Ναι||



Παρατηρήσεις: 

1. Η μέθοδος μπορεί να κληθεί από οποιονδήποτε εξουσιοδοτημένο χρήστη (εκδότη ή μεταφορέα). 

2. Απαιτούνται τουλάχιστον 2 qrUrl για τη δημιουργία ομάδας. 

3. Η απόκριση περιέχει το groupQrUrl, το οποίο μπορεί να χρησιμοποιηθεί στις μεθόδους RegisterTransfer, ConfirmDeliveryOutcome και RejectDeliveryNote για την ταυτόχρονη ενημέρωση όλων των ΔΑ της ομάδας. 

4. Το groupQrUrl έχει περιορισμένη διάρκεια ισχύος, η οποία επιστρέφεται στο πεδίο expiresAt. 

_***Σημείωση**_ : Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι διαθέσιμη στο URL: https://mydataapidev.aade.gr/GenerateGroupQRCode 

- **3.2.6 RequestGroupQRDetails** 

Η κλήση της μεθόδου RequestGroupQRDetails είναι διαθέσιμη μέσω του ακόλουθου URL: 

= https://mydatapi.aade.gr/myDATA/RequestGroupQRDetails?groupId {groupId} 

Η κλήση έχει τα ακόλουθα χαρακτηριστικά: 

11 

myDATA REST API 

- /RequestGroupQRDetails, μέθοδος **GET** ή **POST** 

- Headers όπως αναφέρεται στην παράγραφο: 3.1.1 

- Είσοδος δεδομένων που γίνεται είτε μέσω παραμέτρου URL (για GET) είτε μέσω Body που αποτελείται από ένα στοιχείο 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό Πε**|**εωτικό Περιγραφή**|
|---|---|---|---|
|groupId xs:string|groupId xs:string|Ναι|Το μοναδικό αναγνωριστικό (ID) του Ομαδικού QR<br>Codeγια το οποίοζητούνται λεπτομέρειες.|



Παρατηρήσεις: 

1. Η μέθοδος καλείται για να ανακτηθούν τα επιμέρους QR Codes που περιέχονται σε ένα Ομαδικό QR Code (Group QR). 

2. Η απόκριση περιέχει τη λίστα των qrUrls, το πλήθος τους, τον ΑΦΜ του δημιουργού, καθώς και την ημερομηνία λήξης της ομάδας. 

***** _**Σημείωση:** Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι διαθέσιμη στο_ URL: 

= https://mydataapidev.aade.gr/RequestGroupQRDetails?groupId {groupId} 

12 

myDATA REST API 

## **4 Περιγραφή Σχημάτων** 

## **4.1 Σχήμα DeliveryEventType (Ιστορικό Γεγονότων Διακίνησης)** 

Το ιστορικό διακίνησης είναι μια λίστα από στοιχεία τύπου DeliveryEventType. Η δομή κάθε γεγονότος περιγράφεται παρακάτω: 

13 

myDATA REST API 

14 

myDATA REST API 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό Περιγραφή**|**Υποχρεωτικό Περιγραφή**|
|---|---|---|---|
|eventType|xs:string|Ναι|Ο<br>τύπος<br>του<br>γεγονότος.<br>Αποδεκτές<br>τιμές: RegisterTransfer, ConfirmOutcome, Rejection.|
|eventTimestamp xs:dateTime|eventTimestamp xs:dateTime|Ναι|Η χρονική σήμανση (timestamp) του γεγονότος.|
|actorVat|xs:string|Ναι|ΑΦΜ Χρήστηπου δημιούργησε το συμβάν.|
|mark|xs:long|Όχι|Μοναδικός<br>Αριθμός<br>Καταχώρησης<br>Συμβάντος<br>(παράγεται από το myDATA).|
|transportDetails|TransportDetailType|Όχι (choice)|Στοιχεία μεταφοράς.|
|outcomeDetails|OutcomeDetailsType|Όχι (choice)|Λεπτομέρειες για το αποτέλεσμα της παράδοσης.|
|rejectionDetails|RejectionDetailsType Όχι (choice)|RejectionDetailsType Όχι (choice)|Λεπτομέρειες για την απόρριψη.|



_***Σημείωση:**_ Τα πεδία transportDetails, outcomeDetails και rejectionDetails είναι αμοιβαία αποκλειόμενα (Choice). 

## **4.2 Σχήμα TransportDetailType (Λεπτομέρειες Μεταφοράς)** 

Η δομή του σχήματος TransportDetailType περιγράφεται παρακάτω: 

15 

myDATA REST API 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό**|**Περιγραφή**|
|---|---|---|---|
|vehicleNumber|xs:string|Ναι|Αριθμός Μεταφορικού Μέσου (Αριθμός<br>κυκλοφορίας/Όνομα<br>πλωτού<br>μέσου/Κωδικός<br>Δρομολογίου<br>ή<br>πτήσης/Διακίνηση<br>άνευ<br>Μεταφορικού<br>Μέσου)|
|transportType|xs:int|Ναι|Είδος Μεταφορικού Μέσου. Αποδεκτές<br>Τιμές: Λίστα Τιμών, λεπτομέρειες στον<br>σχετικό πίνακα του παραρτήματος|
|timeStamp|xs:dateTime|Όχι|Χρονοσφραγίδα|
|carrierVatNumber|xs:string|Ναι|ΑΦΜ ΜεταφορικήςΕταιρείας|
|pNumber|xs:string|Όχι|Αριθμός<br>κυκλοφορίας<br>"Ρ"<br>(αριθμός<br>κυκλοφορίας<br>του<br>επικαθήμενου/ρυμουλκούμενου<br>οχήματος)|
|location|LocationType|Όχι|Τοποθεσία Μεταφόρτωσης|
|longitude|xs:decimal|Ναι|Γεωγραφικό Μήκος|
|latitude|xs:decimal|Ναι|Γεωγραφικό Πλάτος|



## **4.3 Σχήμα OutcomeDetailsType (Λεπτομέρειες Αποτελέσματος Παράδοσης)** 

Η δομή του σχήματος OutcomeDetailsType περιγράφεται παρακάτω: 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό Πε**|**εωτικό Περιγραφή**|
|---|---|---|---|
|outcome|DeliveryOutcomeType Ναι|DeliveryOutcomeType Ναι|Το<br>αποτέλεσμα<br>της<br>παράδοσης<br>(FULL,PARTIAL,NONE).|
|deliveredWithoutRecipient xs:boolean|deliveredWithoutRecipient xs:boolean|Όχι|Έχει<br>τιμή<br>true αν<br>η<br>παράδοση έγινε χωρίς<br>την<br>παρουσία<br>του<br>παραλήπτη.|
|deliveredPackaging|PackagingDetailType|Όχι|Λίστα<br>με<br>τις|



myDATA REST API 

16 

παραδοθείσες συσκευασίες. 

## **4.4 Σχήμα PackagingDetailType (Πληροφορίες Συσκευασίας)** 

Η δομή του σχήματος PackagingDetailType περιγράφεται παρακάτω: 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό Περιγραφή**|**Υποχρεωτικό Περιγραφή**|**Αποδεκτές**<br>**τιμές**|
|---|---|---|---|---|
|packagingType|xs:int|Ναι|Είδος<br>Συσκευασίας|Επιτρεπτές<br>τιμές {1,6}.|
|quantity|xs:int|Ναι|Πλήθος||
|otherPackagingTypeTitle|xs:string|Όχι|Τίτλος για Λοιπά<br>ΕίδηΣυσκευασίας||



## **4.5 Σχήμα RejectionDetailsType (Λεπτομέρειες Απόρριψης)** 

Η δομή του σχήματος RejectionDetailsType περιγράφεται παρακάτω: 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό Πε**|**εωτικό Περιγραφή**|
|---|---|---|---|
|reason|xs:string|Όχι|Προαιρετική<br>αιτιολογία<br>απόρριψης|



17 

myDATA REST API 

## **5 Περιγραφή Απαντήσεων** 

## **5.1 Υποβολή Δεδομένων** 

Στις περιπτώσεις που ο χρήστης χρησιμοποιήσει κάποια μέθοδο υποβολής στοιχείων ή ακύρωση (RegisterTransfer, ConfirmDeliveryOutcome, RejectDeliveryNote, CancelDeliveryNote) θα λαμβάνει ως απάντηση ένα αντικείμενο ResponseDoc σε xml μορφή. Το αντικείμενο περιλαμβάνει μια λίστα από στοιχεία τύπου response, ένα για κάθε οντότητα που υποβλήθηκε. 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό Πε**|**εωτικό Περιγραφή**|**Τιμές**|
|---|---|---|---|---|
|index|xs:int|Όχι|Αριθμός Σειράς Οντότητας<br>εντός του υποβληθέντος<br>xml||
|statusCode|xs:string|Ναι|Κωδικός Αποτελέσματος|Success,<br>ValidationError,<br>TechnicalError,<br>XMLSyntaxError|
|transferMark|xs:long|Όχι|Μοναδικός Αριθμός<br>Εκκίνησης/Μεταφόρτωσης<br>Διακίνησης||
|rejectMark|xs:long|Όχι|Μοναδικός<br>Αριθμός<br>ΑπόρριψηςΔιακίνησης||
|deliveryOutcomeMark xs:long|deliveryOutcomeMark xs:long|Όχι|Μοναδικός<br>Αριθμός<br>Αποτελέσματος<br>ΠαράδοσηςΔιακίνησης||
|errors|ErrorType|Ναι (choice)|Λίστα Σφαλμάτων||



18 

myDATA REST API 

Παρατηρήσεις: 

- 1) Το είδος της απάντησης (πετυχημένη ή αποτυχημένη διαδικασία) καθορίζεται από την τιμή του πεδίου statusCode. 

- 2) Σε περίπτωση επιτυχίας το πεδίο statusCode έχει τιμή Success και η απάντηση περιλαμβάνει τις αντίστοιχες τιμές για τα πεδία transferMark και rejectMark ανάλογα με την οντότητα που υποβλήθηκε. 

- 3) Σε περίπτωση αποτυχίας το πεδίο statusCode έχει τιμή αντίστοιχη του είδους του σφάλματος και η απάντηση περιλαμβάνει μια λίστα στοιχείων σφάλματος τύπου ErrorType για κάθε οντότητα που η υποβολή της απέτυχε. Όλα τα στοιχεία σφάλματος ανά οντότητα είναι υποχρεωτικά της ίδιας κατηγορίας που χαρακτηρίζει την απάντηση. 

- 4) Το πεδίο  transferMark επιστρέφει μόνο στην περίπτωση κλήση της μεθόδου RegisterTransfer 

- 5) Το πεδίο  rejectMark επιστρέφει μόνο στην περίπτωση κλήση της μεθόδου RejectDeliveryNote 

- 6) Το πεδίο  deliveryOutcomeMark επιστρέφει μόνο στην περίπτωση κλήση της μεθόδου ConfirmDeliveryOutcome 

## **5.2 Λήψη Κατάστασης (DeliveryNoteStatusResponse)** 

Στην περίπτωση που ο χρήστης χρησιμοποιήσει τη μέθοδο GetDeliveryNoteStatus θα λαμβάνει ως απάντηση ένα αντικείμενο DeliveryNoteStatusResponse σε xml μορφή.  Η δομή του περιγράφεται παρακάτω : 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό Πε**|**εωτικό Περιγραφή**|**Τιμές**|
|---|---|---|---|---|
|invoiceMark|xs:string|Ναι|Το Mark του Παραστατικού<br>Διακίνησης||
|status|xs:string|Ναι|Τρέχουσα<br>Κατάσταση<br>Παραστατικού Διακίνησης|Λίστα<br>τιμών<br>(περιγράφονται<br>στον<br>πίνακα<br>παρατήματος<br>Καταστάσεις|



19 

myDATA REST API 

|||||Παραστατικού<br>Διακίνησης)|
|---|---|---|---|---|
|dispatchTimestamp xs:dateTime|dispatchTimestamp xs:dateTime|Ναι|Ημερομηνία και Ώρα<br>Εκκίνησης/Μεταφόρτωσης<br>Διακίνησης||
|lifecycleHistory|DeliveryEventType Όχι|DeliveryEventType Όχι|Ιστορικό<br>Γεγονότων<br>Διακίνησης||



Παρατηρήσεις: 

- 1) Η δομή του στοιχείου DeliveryEventType περιγράφεται και αναλύεται στο κεφάλαιο: 4.1 

## **5.3 Δημιουργίας Ομαδικού QR (GenerateGroupQRCodeResponse)** 

Στην περίπτωση που ο χρήστης χρησιμοποιήσει τη μέθοδο GenerateGroupQRCode θα λαμβάνει ως απάντηση ένα αντικείμενο GenerateGroupQRCodeResponse σε xml μορφή.  Η δομή του περιγράφεται παρακάτω : 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό Πε**|**εωτικό Περιγραφή**|**Τιμές**|
|---|---|---|---|---|
|groupQrUrl|xs:string|Ναι|Το νέο, ομαδικό URL του<br>QR Code.||
|qrUrlsCount|xs:int|Ναι|Το πλήθος των ΔΑ που<br>περιλαμβάνονται<br>στην<br>ομάδα.||
|expiresAt|xs:string|Ναι|Η ημερομηνία και ώρα<br>λήξης του ομαδικού QR<br>Code.||
|statusCode|xs:string|Ναι|Το<br>αποτέλεσμα<br>της<br>επεξεργασίας.|Success,<br>ValidationError,<br>TechnicalError,<br>XMLSyntaxError|



20 

myDATA REST API 

## **6 Σφάλματα** 

Τα σφάλματα είναι στοιχεία ErrorType και περιγράφονται παρακάτω: 

Κάθε στοιχείο σφάλματος που αφορά μια οντότητα αποτελείται από ένα μήνυμα που περιγράφει το σφάλμα και έναν κωδικό σφάλματος. 

|**Πεδίο**|**Τύπος**|**Υποχρεωτικό**|**Περιγραφή**|
|---|---|---|---|
|message|xs:string|Ναι|Μήνυμα Σφάλματος|
|code|xs:string|Ναι|ΚωδικόςΣφάλματος|



## **6.1 Τεχνικά Σφάλματα** 

Τα τεχνικά σφάλματα χαρακτηρίζουν την κλήση ως μη επιτυχημένη και επιστρέφουν ένα τυπικό .ΝΕΤ HttpResponseMessage αντί για το ErrorType που περιγράφεται στην παράγραφο 6. Ως εκ τούτου δεν έχουν ειδικό κωδικό σφάλματος, δεν συνοδεύονται από κάποιο statusCode του στοιχείου ResponseType, και αναγνωρίζονται από το αντίστοιχο HttpStatusCode. 

|**#**|**HTTP Response**|**Περιγραφή**|**Περιγραφή ENG**|
|---|---|---|---|
|1|HTTP<br>401<br>UNAUTHORIZED|Λείπει η κεφαλίδα Aade-user-id|Aade-user-id header is missing|
|2|HTTP<br>401<br>UNAUTHORIZED|Το<br>κλειδί<br>πρόσβασης<br>δεν<br>αντιστοιχεί<br>στο<br>δεδομένο<br>αναγνωριστικόχρήστη (User Id)|Access Key does not correspond<br>to given User Id|
|3|HTTP<br>400<br>BAD_REQUEST|Περάστε<br>το<br>MARK<br>στις<br>παραμέτρους ή στο σώμα του<br>httpαιτήματος|Please pass mark in the request<br>parameters or body|
|4|HTTP<br>400<br>BAD_REQUEST|Γενικό Σφάλμα Εξαίρεσης|General Exception Error|



21 

myDATA REST API 

## **6.2 Επιχειρησιακά Σφάλματα** 

Τα επιχειρησιακά σφάλματα είναι τύπου ErrorType (βλ Παρ. 6) και προκύπτουν κατά την αποτυχία των επιχειρησιακών ελέγχων. Στην περίπτωση τους η κλήση θεωρείται τεχνικά επιτυχημένη (HTTP Response 200). 

|**HTTP Response**|**statusCode**|**Κωδικός**|**Στοιχείο**|**Περιγραφή**|**Περιγραφή  ENG**|
|---|---|---|---|---|---|
|HTTP 200 OK|XMLSyntaxError|100|Application|Σφάλμα<br>επικύρωσης<br>σύνταξηςXML|XML Syntax Validation Error|
|HTTP 200 OK|ValidationError|800|Application|Η<br>κλήση<br>της<br>μεθόδου<br>(RegisterTransfer,<br>ConfirmDeliveryOutcome)<br>δεν επιτρέπεται λόγω της<br>τρέχουσας κατάστασης της<br>διακίνησης<br>(π.χ.<br>λάθος<br>status).|The<br>method<br>call<br>(RegisterTransfer,<br>ConfirmDeliveryOutcome) is<br>not allowed due to the<br>current<br>state<br>of<br>the<br>transaction (e.g. incorrect<br>status).|
|HTTP 200 OK|ValidationError|801|Application|Το παραστατικό δεν μπορεί<br>να<br>ακυρωθεί<br>λόγω<br>της<br>τρέχουσας<br>κατάστασης<br>διακίνησης<br>(αφορά<br>τη<br>μέθοδο<br>CancelDeliveryNote).|Invoice cannot be canceled<br>due to its current movement<br>status<br>(regards<br>CancelDeliveryNote<br>method).|
|HTTP 200 OK|ValidationError|802|Application|Το παραστατικό δεν μπορεί<br>να απορριφθεί λόγω της<br>τρέχουσας<br>κατάστασης<br>διακίνησης<br>(αφορά<br>τη<br>μέθοδο RejectDeliveryNote).|Invoice cannot be rejected<br>due to its current movement<br>status<br>(regards<br>RejectDeliveryNote method).|
|HTTP 200 OK|ValidationError|803|Application|Ο χρήστης δεν μπορεί να<br>απορρίψει το παραστατικό.<br>Μόνο ο λήπτης έχει αυτό το<br>δικαίωμα (αφορά τη μέθοδο<br>RejectDeliveryNote).|The user cannot reject the<br>invoice. Only the recipient<br>has<br>this<br>right<br>(regards<br>RejectDeliveryNote method).|
|HTTP 200 OK|ValidationError|804|Application|Το παραστατικό δεν μπορεί<br>να απορριφθεί λόγω του<br>τύπου<br>του<br>(αφορά<br>τη<br>μέθοδο RejectDeliveryNote).|Invoice cannot be rejected<br>due to its type. (regards<br>RejectDeliveryNote method).|
|HTTP 200 OK|ValidationError|805|Application|Η<br>κλήση<br>της<br>μεθόδου<br>RegisterTransfer<br>για<br>το<br>παραστατικό με ΜΑΡΚ :<br>{mark} δεν επιτρέπεται λόγω<br>του τύπου του: (π.χ 1.1 (με<br>την ένδειξη isDeliveryNote =<br>false),<br>Δεν<br>είναι<br>παραστατικό διακίνησης)|<br>Cannot call RegisterTransfer<br>for<br>Invoice<br>with<br>MARK:<br>{mark}<br>because<br>of<br>its<br>invoiceType: (e.g 1.1 (with<br>isDeliveryNote = false), It is<br>not deliveryNote)|
|HTTP 200 OK|ValidationError|806|Application|Δεν βρέθηκε QR!|Not Found QR!|
|HTTP 200 OK|ValidationError|807|Application|Μη έγκυρο QR!|Invalid QR!|



22 

myDATA REST API 

|HTTP 200 OK|ValidationError|808|Application|Δεν βρέθηκε παραστατικό<br>για αυτό τοQR|No Invoice found for this QR!|
|---|---|---|---|---|---|
|HTTP 200 OK|ValidationError|809|Application|Η<br>κλήση<br>της<br>μεθόδου<br>ConfirmDeliveryOutcome<br>για<br>το<br>παραστατικό<br>με<br>ΜΑΡΚ:<br>{mark}<br>δεν<br>επιτρέπεται.<br>Το<br>παραστατικό έχει ακυρωθεί.|<br>Cannot<br>confirm<br>delivery<br>outcome for Invoice with<br>MARK: {mark}. The invoice<br>has been cancelled.|
|HTTP 200 OK|ValidationError|810|Application|Η<br>κλήση<br>της<br>μεθόδου<br>ConfirmDeliveryOutcome<br>για<br>το<br>παραστατικό<br>με<br>ΜΑΡΚ:<br>{mark}<br>δεν<br>επιτρέπεται.<br>Το<br>παραστατικό<br>διακίνησης<br>έχει απορριφθεί.|<br>Cannot<br>confirm<br>delivery<br>outcome for Invoice with<br>MARK: {mark}. The delivery<br>note has been rejected.|
|HTTP 200 OK|ValidationError|811|Application|Η<br>κλήση<br>της<br>μεθόδου<br>ConfirmDeliveryOutcome<br>για<br>το<br>παραστατικό<br>με<br>ΜΑΡΚ:<br>{mark}<br>δεν<br>επιτρέπεται. Η παράδοση<br>του έχει ολοκληρωθεί.|<br>Cannot<br>confirm<br>delivery<br>outcome for Invoice with<br>MARK: {mark}. The delivery<br>has already been completed.|
|HTTP 200 OK|ValidationError|812|Application|Η<br>κλήση<br>της<br>μεθόδου<br>ConfirmDeliveryOutcome<br>για<br>το<br>παραστατικό<br>με<br>ΜΑΡΚ:<br>{mark}<br>δεν<br>επιτρέπεται. Η παράδοση<br>του έχει δηλωθεί ότι έχει<br>αποτύχει<br>(δεν<br>πραγματοποιήθηκε).|<br>Cannot<br>confirm<br>delivery<br>outcome for Invoice with<br>MARK: {mark}. The delivery<br>has already been failed.|
|HTTP 200 OK|ValidationError|813|Application|Η<br>κλήση<br>της<br>μεθόδου<br>ConfirmDeliveryOutcome<br>για<br>το<br>παραστατικό<br>με<br>ΜΑΡΚ:<br>{mark}<br>δεν<br>επιτρέπεται.<br>Δεν<br>έχει<br>ξεκινήσει ακόμα η διακίνηση<br>του. Τρέχουσα κατάσταση<br>διακίνησης: Registered (Το<br>παραστατικό<br>διακίνησης<br>έχει εκδοθεί επιτυχώς.)|<br>Cannot<br>confirm<br>delivery<br>outcome for Invoice with<br>MARK: {mark}. It has not<br>been dispatched yet. Current<br>status: Registered|
|HTTP 200 OK|ValidationError|814|Application|Το πεδίο deliveredPackaging<br>είναι υποχρεωτικό όταν το<br>πεδίο outcome (αποτέλεσμα<br>της παράδοσης) έχει την<br>τιμή: PARTIAL! (αφορά τη<br>μέθοδο<br>ConfirmDeliveryOutcome).|deliveredPackaging<br>is<br>required when outcome is<br>PARTIAL!<br>(regards<br>ConfirmDeliveryOutcome<br>method).|
|HTTP 200 OK|ValidationError|815|Application|Μη έγκυρη τιμή του πεδίου<br>packagingType. Οι τιμές του<br>πρέπει να είναιμεταξύ 1 και|Invalid<br>packagingType:<br>{value}. Must be between 1<br>and 6.|



23 

myDATA REST API 

|||||6.||
|---|---|---|---|---|---|
|HTTP 200 OK|ValidationError|816|Application|Μη έγκυρη τιμή του πεδίου<br>quantity. Η  τιμή του πρέπει<br>να είναιμεγαλύτερητου 0.|Invalid<br>quantity:<br>{value}.<br>Must be greater than 0.|
|HTTP 200 OK|ValidationError|817|Application|Μόνο ο μεταφορέας μπορεί<br>να θέσει την τιμή  NONE<br>(αποτυχία παράδοσης) στο<br>πεδίο outcome (αποτέλεσμα<br>της παράδοσης) (αφορά τη<br>μέθοδο<br>ConfirmDeliveryOutcome).|Only the carrier can set<br>outcome to NONE (failed<br>delivery)!<br>(regards<br>ConfirmDeliveryOutcome<br>method).|
|HTTP 200 OK|ValidationError|818|Application|Ο λήπτης δεν μπορεί να<br>θέσει την τιμή  NONE<br>(αποτυχία παράδοσης) στο<br>πεδίο outcome (αποτέλεσμα<br>της<br>παράδοσης)<br>στην<br>περίπτωση Β2Β διακίνησης<br>(αφορά<br>τη<br>μέθοδο<br>ConfirmDeliveryOutcome).|Recipient<br>cannot<br>set<br>outcome to NONE in B2B<br>scenario!!<br>(regards<br>ConfirmDeliveryOutcome<br>method).|
|HTTP 200 OK|ValidationError|819|Application|Δεν επιτρέπεται η κλήση της<br>μεθόδου<br>ConfirmDeliveryOutcome. Ο<br>μεταφορέας<br>έχει<br>ήδη<br>δηλώσει παράδοση. Μόνο ο<br>λήπτης<br>μπορεί<br>να<br>την<br>καλέσει για επιβεβαίωση<br>παραλαβής|Cannot<br>confirm<br>delivery<br>outcome. The carrier has<br>already<br>declared<br>the<br>delivery. Only the recipient<br>can confirm.|
|HTTP 200 OK|ValidationError|820|Application|Δεν βρέθηκε το ομαδικό<br>(Group) QR ή έχει λήξει η<br>διάρκεια του|Group QR not found or has<br>expired|
|HTTP 200 OK|ValidationError|821|Application|Η<br>κλήση<br>της<br>μεθόδου<br>RegisterTransfer<br>για<br>το<br>παραστατικό με ΜΑΡΚ :<br>{mark} δεν επιτρέπεται. Το<br>παραστατικό έχει ακυρωθεί.|<br>Cannot call RegisterTransfer<br>for<br>Invoice<br>with<br>MARK:<br>{mark}. The invoice has been<br>cancelled.|
|HTTP 200 OK|ValidationError|822|Application|Η<br>κλήση<br>της<br>μεθόδου<br>RejectDeliveryNote για το<br>παραστατικό με ΜΑΡΚ :<br>{mark} δεν επιτρέπεται λόγω<br>της τρέχουσας κατάστασης<br>της<br>διακίνησης<br>του:<br>{DeliveryStatus}.<br>Μόνο<br>παραστατικά<br>στις<br>καταστάσεις<br>Registered,<br>InTransit<br>ή<br>DeliveredByCarrier μπορούν<br>να απορριφθούν.|<br>Cannot<br>call<br>RejectDeliveryNote<br>for<br>Invoice with MARK: {mark}<br>due to its current movement<br>status: {DeliveryStatus}. Only<br>Registered or InTransit or<br>DeliveredByCarrier can be<br>rejected.|
|HTTP 200 OK|ValidationError|823|Application|Δεν επιτρέπονται και το<br>QrUrl και το invoiceMark|<br>Both QrUrl and invoiceMark<br>are not allowed(regards|



24 

myDATA REST API 

|||||πεδίο (αφορά τη μέθοδο<br>RejectDeliveryNote).|<br>RejectDeliveryNote method).|
|---|---|---|---|---|---|
|HTTP 200 OK|ValidationError|824|Application|Απαιτείται είτε το QrUrl ή το<br>invoiceMark  πεδίο (αφορά<br>τη<br>μέθοδο<br>RejectDeliveryNote).|<br> <br> <br>Either QrUrl or invoiceMark<br>is<br>required<br>(regards<br>RejectDeliveryNote method).|
|HTTP 200 OK|TechnicalError|-|-|Μη αναμενόμενο σφάλμα<br>συνθήκης|<br>Unexpected condition error|



## **7 Παράρτημα** 

**7.1 Καταστάσεις Δελτίου Αποστολής (InvoiceDeliveryStatus)** 

|**Κωδικός**|**Περιγραφή**|**Επεξήγηση**|
|---|---|---|
|1|Registered|Το ΔΑ έχει εκδοθεί επιτυχώς.|
|2|Cancelled|Ο εκδότης ακύρωσε το ΔΑ πριν την έναρξη της<br>διακίνησης.|
|3|InTransit|Η διακίνηση έχει ξεκινήσει.|
|4|Rejected|Ο λήπτης απέρριψε την παραλαβή.|
|5|DeliveredByCarrier|Ο μεταφορέας δήλωσε παράδοση (αναμονή<br>επιβεβαίωσης από λήπτη B2B).|
|7|FailedDelivery|Ο μεταφορέας δήλωσε αποτυχία παράδοσης.|
|8|Completed|Η διακίνηση ολοκληρώθηκε με επιτυχία.|



**7.2 Τύποι Γεγονότων (DeliveryEventType)** 

|**Κωδικός**|**Περιγραφή**|
|---|---|
|RegisterTransfer|Έναρξη διακίνησης ή μεταφόρτωση.|
|ConfirmOutcome|Δήλωση αποτελέσματος παράδοσης / Επιβεβαίωση<br>παραλαβής.|
|Rejection|Ολική απόρριψη από τον λήπτη.|



25 

myDATA REST API 

## **7.3 Τύποι Συσκευασίας (PackagingType)** 

|**Κωδικός**|**Περιγραφή**|
|---|---|
|1|Παλέτα|
|2|Κούτα|
|3|Κιβώτιο|
|4|Βαρέλι|
|5|Σάκος|
|6|Λοιπά|



## **7.4 Είδος Μεταφορικού Μέσου (transportType)** 

|**Κωδικός**|**Περιγραφή**|
|---|---|
|1|Φορτηγό Δημόσιας Χρήσης|
|2|Φορτηγό Ιδιωτικής Χρήσης|
|3|Πλοίο|
|4|Τρένο|
|5|Αεροπλάνο|
|6|Λοιπά Μεταφορικά Μέσα<br>(π.χΔίκυκλα,..)|
|7|Άνευ|



26 

myDATA REST API 

## **8 Ιστορικό αλλαγών** 

## **8.1 Έκδοση 2.0.0** 

Αρχική δημιουργία του εγγράφου τεκμηρίωσης για τη λειτουργικότητα του Ψηφιακού Δελτίου Αποστολής. 

## **8.2 Έκδοση 2.0.1** 

- Προσθήκες 

   - Παρ. 3.2.3: Προσθήκη του πεδίου invoiceMark στο body (τύπου RejectDeliveryNoteRequest) της μεθόδου RejectDeliveryNote, ώστε να μπορεί να γίνει απόρριψη ενός παραστατικού διακίνησης από τον λήπτη του και με την χρήση του ΜΑΡΚ του παραστατικού 

   - Προσθήκη του πίνακα παραρτήματος: Είδος Μεταφορικού Μέσου (Παρ. 7.4) 

   - Παρ. 5.1: Προσθήκη του πεδίου deliveryOutcomeMark στο σχήμα του τύπου response και επεξήγηση του στις Παρατηρήσεις 

   - Παρ. 6.2: Προσθήκη κωδικών επιχειρησιακών σφαλμάτων (Κωδικοί από 805 έως και 824). 

##  Ενημερώσεις 

- Παρ. 3.2.1: transportMark μετονομασία σε transferMark 

- Παρ. 3.2.3: Το πεδίο qrUrl έγινε υποχρεωτικό/choice (από υποχρεωτικό) 

- 

   - Παρ. 5.1: Αλλαγή του τύπου των πεδίων transferMark (πρώην transportMark) και rejectMark, από xs:string σε xs:long 

- Παρ. 5.2: Αλλαγή στις τιμές του πεδίου status (λίστα τιμών, οι τιμές των οποίων περιγράφονται στον πίνακα παρατήματος Καταστάσεις Παραστατικού Διακίνησης αντί των λανθασμένων αναγραφόμενων τιμών: Success, ValidationError, TechnicalError, XMLSyntaxError) 

- Παρ. 7.1: Αντικατάσταση των τιμών της στήλης Κωδικός του πίνακα με τις αριθμητικές τιμές που αντιστοιχούν στην κατάσταση του Δελτίου Αποστολής (InvoiceDeliveryStatus), μετακίνηση των παλιών τιμών της στήλης Κωδικός (αλφαριθμητικά) στη στήλη Περιγραφή και μετακίνηση των παλιών τιμών της στήλης Περιγραφή σε νέα στήλη με το όνομα Επεξήγηση 

27 

myDATA REST API 

