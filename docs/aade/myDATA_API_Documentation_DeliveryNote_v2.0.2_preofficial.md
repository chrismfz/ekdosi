# myDATA

# Ηλεκτρονικά Βιβλία ΑΑΔΕ

Τεχνική περιγραφή διεπαφών REST API για το Ψηφιακό Δελτίο Αποστολής Έκδοση 2.0.2 – Ιούνιος 2026

myDATA REST API 1

### Πίνακας περιεχομένων

Πίνακας περιεχομένων.............................................................................................................. 1

1 Εισαγωγή........................................................................................................................... 3

1. 1 Σκοπός....................................................................................................................... 3

1. 2 Ο Κύκλος Ζωής της Διακίνησης................................................................................. 3

1. 3 Οι Ρόλοι..................................................................................................................... 5

2 Τεχνολογικές απαιτήσεις λογισμικών έκδοσης παραστατικών........................................ 5

3 Περιγραφή REST API.......................................................................................................... 5

3. 1 Περιγραφή λειτουργίας των διεπαφών.................................................................... 6

3. 1.1 Απαραίτητα Headers......................................................................................... 6

3. 2 Περιγραφή νέων λειτουργιών................................................................................... 6

3. 2.1 RegisterTransfer................................................................................................ 6

3. 2.2 ConfirmDeliveryOutcome.................................................................................. 8

3. 2.3 RejectDeliveryNote............................................................................................ 9

3. 2.4 GetDeliveryNoteStatus.................................................................................... 10

3. 2.5 GenerateGroupQRCode.................................................................................. 11

3. 2.6 RequestGroupQRDetails.................................................................................. 12

3. 2.7 ConfirmDeliveryReturn.................................................................................... 14

4 Περιγραφή Σχημάτων...................................................................................................... 15

4. 1 Σχήμα DeliveryEventType (Ιστορικό Γεγονότων Διακίνησης)................................. 15

4. 2 Σχήμα TransportDetailType (Λεπτομέρειες Μεταφοράς)....................................... 17

4. 3 Σχήμα OutcomeDetailsType (Λεπτομέρειες Αποτελέσματος Παράδοσης)............ 19

4. 4 Σχήμα PackagingDetailType (Πληροφορίες Συσκευασίας)..................................... 19

4. 5 Σχήμα RejectionDetailsType (Λεπτομέρειες Απόρριψης)....................................... 20

5 Περιγραφή Απαντήσεων................................................................................................. 20

5. 1 Υποβολή Δεδομένων............................................................................................... 20

5. 2 Λήψη Κατάστασης (DeliveryNoteStatusResponse)................................................. 21

5. 3 Δημιουργίας Ομαδικού QR (GenerateGroupQRCodeResponse)............................ 22

6 Σφάλματα........................................................................................................................ 24

6. 1 Τεχνικά Σφάλματα................................................................................................... 24

6. 2 Επιχειρησιακά Σφάλματα........................................................................................ 25

7 Παράρτημα...................................................................................................................... 30

myDATA REST API 2

7. 1 Καταστάσεις Δελτίου Αποστολής (InvoiceDeliveryStatus)..................................... 30

7. 2 Τύποι Γεγονότων (DeliveryEventType).................................................................... 30

7. 3 Τύποι Συσκευασίας (PackagingType)...................................................................... 31

7. 4 Είδος Μεταφορικού Μέσου (transportType)......................................................... 31

8 Ιστορικό αλλαγών............................................................................................................ 32

8. 1 Έκδοση 2.0.0............................................................................................................ 32

8. 2 Έκδοση 2.0.1............................................................................................................ 32

8. 3 Έκδοση 2.0.2............................................................................................................ 33

myDATA REST API 3

### 1 Εισαγωγή

### 1.1 Σκοπός

Αυτό το έγγραφο περιγράφει την τεχνική διεπαφή REST API για τη λειτουργικότητα του Ψηφιακού Δελτίου Αποστολής (ΔΑ) της πλατφόρμας myDATA. Απευθύνεται σε Παρόχους Ηλεκτρονικής Τιμολόγησης και προγραμματιστές συστημάτων ERP που επιθυμούν να ενσωματώσουν τη λειτουργικότητα αυτή για λογαριασμό των πελατών τους.

Σκοπός της λειτουργικότητας είναι η παρακολούθηση της διακίνησης αγαθών σε πραγματικό χρόνο, από την έκδοση του παραστατικού διακίνησης έως την τελική παραλαβή του, αυξάνοντας τη διαφάνεια και την ασφάλεια στην εφοδιαστική αλυσίδα.

### 1.2 Ο Κύκλος Ζωής της Διακίνησης

Κάθε Δελτίο Αποστολής διέπεται από έναν κύκλο ζωής που αποτελείται από συγκεκριμένες καταστάσεις. Η μετάβαση από τη μία κατάσταση στην επόμενη πραγματοποιείται μέσω των μεθόδων API που περιγράφονται παρακάτω.

Οι βασικές καταστάσεις είναι:

 Registered: Το ΔΑ έχει εκδοθεί επιτυχώς και έχει λάβει ΜΑΡΚ. Η διακίνηση δεν έχει ξεκινήσει.  InTransit: Η διακίνηση έχει ξεκινήσει μετά από παραλαβή από τον πρώτο μεταφορέα.  InTransit (Return): Ο μεταφορέας επιστρέφει χωρίς να έχει παραδώσει όλα τα αγαθά.  DeliveredByCarrier: (Για B2B) Ο μεταφορέας δήλωσε ότι παρέδωσε, αλλά ο λήπτης δεν έχει ακόμη επιβεβαιώσει.  Completed: Η διακίνηση ολοκληρώθηκε επιτυχώς (είτε με επιβεβαίωση λήπτη σε κάποιες περιπτώσεις, είτε με δήλωση μεταφορέα σε B2C είτε με επιβεβαίωση εκδότη σε κάποιες περιπτώσεις).  Rejected: Ο λήπτης απέρριψε ολικά την παραλαβή.  Cancelled: Ο εκδότης ακύρωσε το ΔΑ πριν την έναρξη της διακίνησης.  FailedDelivery: Ο μεταφορέας δήλωσε αποτυχία παράδοσης.

Ακολουθεί διάγραμμα καταστάσεων κύκλου ζωής του Ψηφιακού Δελτίου αποστολής.

myDATA REST API

### 4

Εικόνα 1: Διάγραμμα Καταστάσεων

myDATA REST API 5

### 1.3 Οι Ρόλοι

 Εκδότης (Issuer): Η οντότητα που εκδίδει το παραστατικό διακίνησης.  Μεταφορέας (Carrier): Η οντότητα που αναλαμβάνει τη φυσική μεταφορά των αγαθών. Μπορεί να υπάρχουν πολλοί μεταφορείς σε μία διακίνηση (μεταφόρτωση).  Λήπτης (Recipient): Η οντότητα που είναι ο τελικός παραλήπτης των αγαθών.

### 2 Τεχνολογικές απαιτήσεις λογισμικών έκδοσης παραστατικών

Για την υλοποίηση της επικοινωνίας ενός συστήματος λογισμικού με τις διεπαφές χρησιμοποιούνται οι παρακάτω τεχνολογίες

- HTTPS – Secure HTTP

- Webservice

- REST API – REST interface required for the data reporting process

- XML – eXtensible Markup Language

Οι διεπαφές μπορεί να χρησιμοποιηθούν από οποιοδήποτε λογισμικό που μπορεί να υλοποιήσει HTTPS κλήσεις και να δημιουργήσει έγγραφα XML συμβατά με το σχήμα που περιγράφεται στο παρόν έγγραφο.

Εκτός των σχετικών δεδομένων, το λογισμικό θα πρέπει να μπορεί να στείλει ταυτόχρονα και αυτοματοποιημένα και τις απαραίτητες πληροφορίες για την ταυτοποίηση του χρήστη μέσω της ίδιας HTTPS κλήσης.

### 3 Περιγραφή REST API

Συνοπτικά, η διεπαφή παρέχει τις εξής λειτουργίες-μεθόδους:

 /RegisterTransfer: διαδικασία δήλωσης έναρξης ή μεταφόρτωσης διακίνησης από μεταφορέα.  /ConfirmDeliveryOutcome: διαδικασία δήλωσης αποτελέσματος παράδοσης από μεταφορέα ή λήπτη.  /RejectDeliveryNote: διαδικασία ολικής απόρριψης διακίνησης από τον λήπτη.  /GetDeliveryNoteStatus: διαδικασία λήψης της κατάστασης και του ιστορικού ενός Δελτίου Αποστολής.  /GenerateGroupQRCode: διαδικασία δημιουργίας ομαδικού QR Code για πολλαπλά Δελτία Αποστολής.  /RequestGroupQRDetails: διαδικασία ανάκτησης των λεπτομερειών και των συσχετιζόμενων QR Codes ενός Ομαδικού QR Code.

myDATA REST API 6

 /ConfirmDeliveryReturn: διαδικασία δήλωσης επιστροφής από εκδότη

Λεπτομερής περιγραφή των λειτουργιών περιγράφονται σε επόμενο τμήμα αυτού του εγγράφου.

### 3.1 Περιγραφή λειτουργίας των διεπαφών

3. 1.1 Απαραίτητα Headers

Κάθε κλήση πρέπει να περιέχει με τη μορφή ζευγαριών-τιμών, τα παρακάτω headers,τα οποία είναι απαραίτητα για την ταυτοποίηση του χρήστη. Σε περίπτωση λανθασμένων στοιχείων ο χρήστης θα λάβει μήνυμα σφάλματος.

KEY Data Type VALUE DESCRIPTION aade-user-id String {Όνομα Χρήστη} Το όνομα χρήστη του λογαριασμού ocp-apim-subscription-key String {Subscription Key} Το subscription key του χρήστη

Μέσα από την ταυτοποίηση του χρήστη μέσω των headers η διεπαφή θα αποκτά πρόσβαση και στον ΑΦΜ που είχε δηλώσει ο χρήστης κατά την εγγραφή του, ώστε να μην είναι απαραίτητη η εισαγωγή αυτού του στοιχείου ξανά σε κάθε κλήση υπηρεσίας.

### 3.2 Περιγραφή νέων λειτουργιών

3. 2.1 RegisterTransfer

Η κλήση της μεθόδου RegisterTransfer είναι διαθέσιμη μέσω του ακόλουθου URL:

https://mydatapi.aade.gr/myDATA/RegisterTransfer

Η κλήση έχει τα ακόλουθα χαρακτηριστικά:

 /RegisterTransfer, μέθοδος POST

 Headers όπως αναφέρεται στην παράγραφο: 3.1.1

 Body που αποτελείται από ένα στοιχείο Transport. Ο τύπος περιγράφεται από το παρακάτω διάγραμμα. Η δομή του στοιχείου TransportDetailType περιγράφεται και αναλύεται στο κεφάλαιο: 4.2

myDATA REST API 7

Πεδίο Τύπος Υποχρεωτικό Περιγραφή transferMark xs:long Ναι Μοναδικός Αριθμός Καταχώρησης του γεγονότος μεταφοράς. Συμπληρώνεται από την υπηρεσία. qrUrl xs:string Ναι Το URL του QR code του Δελτίου Αποστολής ή του Ομαδικού QR Code. transportDetail TransportDetailType Ναι Αντικείμενο που περιέχει τις λεπτομέρειες της μεταφοράς.

Παρατηρήσεις:

1. Η μέθοδος καλείται από τον μεταφορέα για να δηλώσει την παραλαβή των αγαθών

και την έναρξη της διακίνησης, ή την παραλαβή από προηγούμενο μεταφορέα (μεταφόρτωση).

2. Με την επιτυχή κλήση, το Δελτίο Αποστολής μεταβαίνει σε κατάσταση InTransit ή

στην κατάσταση InTransit (Return). Στο παρακάτω πίνακα αποτυπώνονται οι συνθήκες / προϋποθέσεις του πότε με την κλήση της RegisterTrasnfer γίνεται μετάβαση σε InTransit ή σε InTransit (Return):

# Από Κατάσταση Προς Κατάσταση Συνθήκη

1 Registered In Transit

2 In Transit In Transit

3 Delivered By Carrier In Transit (Return) Εφόσον έχει γίνει μερική παράδοση από τον μεταφορέα (έγινε κλήση της ConfirmDeliveryOutcome με OUTCOME:PARTIAL)

4 Rejected In Transit (Return)

5 In Transit (Return) In Transit (Return)

myDATA REST API 8

3. Σε περίπτωση επιτυχίας, η απόκριση περιέχει το transportMark, το οποίο είναι ο

Μοναδικός Αριθμός Καταχώρησης του γεγονότος μεταφοράς.

- Σημείωση: Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι

διαθέσιμη στο URL: https://mydataapidev.aade.gr/RegisterTransfer

3. 2.2 ConfirmDeliveryOutcome

Η κλήση της μεθόδου ConfirmDeliveryOutcome είναι διαθέσιμη μέσω του ακόλουθου URL:

https://mydatapi.aade.gr/myDATA/ConfirmDeliveryOutcome

Η κλήση έχει τα ακόλουθα χαρακτηριστικά:

 /ConfirmDeliveryOutcome, μέθοδος POST

 Headers όπως αναφέρεται στην παράγραφο: 3.1.1

 Body που αποτελείται από ένα στοιχείο ConfirmDeliveryOutcomeRequest. Ο τύπος περιγράφεται από το παρακάτω διάγραμμα. Η δομή του στοιχείου PackagingDetailType περιγράφεται και αναλύεται στο κεφάλαιο: 4.4

Πεδίο Τύπος Υποχρεωτικό Περιγραφή qrUrl xs:string Ναι Το URL του QR code του Δελτίου Αποστολής ή του Ομαδικού QR Code. outcome DeliveryOutcomeType Ναι Το αποτέλεσμα της παράδοσης. Αποδεκτές τιμές: FULL, PARTIAL, NONE

myDATA REST API 9

deliveredWithoutRecipient xs:boolean Όχι Η τιμή είναι true αν η παράδοση έγινε χωρίς την παρουσία του παραλήπτη. deliveredPackaging PackagingDetailType Όχι Λίστα με τις συσκευασίες και τις ποσότητες που παραδόθηκαν.

Παρατηρήσεις:

1. Η μέθοδος καλείται είτε από τον Μεταφορέα για να δηλώσει το αποτέλεσμα της

παράδοσης, είτε από τον Λήπτη για να επιβεβαιώσει την παραλαβή.

2. Αν κληθεί από Μεταφορέα σε B2B συναλλαγή, θέτει το ΔΑ σε

κατάσταση DeliveredByCarrier.

3. Αν κληθεί από Μεταφορέα με την τιμή FULL στο πεδίο outcome, θέτει το ΔΑ σε

κατάσταση Completed, εφόσον το ΔΑ έχει εκδοθεί με την ένδειξη NonObligatedRecipient = true (Μη Υπόχρεος Λήπτης)

4. Αν κληθεί από Λήπτη, θέτει το ΔΑ σε κατάσταση Completed, εφόσον ο μεταφορέας

δεν έχει θέσει το ΔΑ σε κατάσταση FailedDelivery ή σε κατάσταση Μερικής Παράδοσης.

5. Η τιμή NONE για το πεδίο outcome θέτει το ΔΑ σε κατάσταση FailedDelivery.

6. Η τιμή PARTIAL για το πεδίο outcome επιτρέπεται μόνο στην περίπτωση που η

κλήση γίνεται από τον μεταφορέα και υποδηλώνει ότι έγινε μερική παράδοση από τον μεταφορέα.

- Σημείωση: Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι

διαθέσιμη στο URL: https://mydataapidev.aade.gr/ConfirmDeliveryOutcome

3. 2.3 RejectDeliveryNote

Η κλήση της μεθόδου RejectDeliveryNote είναι διαθέσιμη μέσω του ακόλουθου URL:

https://mydatapi.aade.gr/myDATA/RejectDeliveryNote

Η κλήση έχει τα ακόλουθα χαρακτηριστικά:

 /RejectDeliveryNote, μέθοδος POST

 Headers όπως αναφέρεται στην παράγραφο: 3.1.1

 Body που αποτελείται από ένα στοιχείο RejectDeliveryNoteRequest. Ο τύπος περιγράφεται από το παρακάτω διάγραμμα:

myDATA REST API 10

Πεδίο Τύπος Υποχρεωτικό Περιγραφή qrUrl xs:string Ναι (choice) Το URL του QR code του Δελτίου Αποστολής ή του Ομαδικού QR Code. invoiceMark Xs:long Ναι (choice) Το ΜΑΡΚ του παραστατικού διακίνησης rejectionReason xs:string Όχι Περιγραφή του λόγου απόρριψης.

Παρατηρήσεις:

1. Η μέθοδος καλείται αποκλειστικά από τον Λήπτη για να δηλώσει την ολική

απόρριψη των ειδών του Δελτίου Αποστολής.

2. Με την επιτυχή κλήση, το Δελτίο Αποστολής μεταβαίνει στην κατάσταση Rejected.

3. Σε περίπτωση επιτυχίας, η απόκριση περιέχει το rejectMark, το οποίο είναι ο

Μοναδικός Αριθμός Καταχώρησης του γεγονότος απόρριψης.

- Σημείωση: Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι

διαθέσιμη στο URL: https://mydataapidev.aade.gr/RejectDeliveryNote

3. 2.4 GetDeliveryNoteStatus

Αυτή η GET μέθοδος χρησιμοποιείται για την ανάκτηση της τρέχουσας κατάστασης και του πλήρους ιστορικού ενός Δελτίου Αποστολής. Η κλήση μπορεί να πραγματοποιηθεί με έναν από τους δύο παρακάτω τρόπους :

https://mydatapi.aade.gr/myDATA/GetDeliveryNoteStatus?mark={mark}

ή

https://mydatapi.aade.gr/myDATA/GetDeliveryNoteStatus?qrUrl={qrUrl}

Όνομα Παραμέτρου Υποχρεωτικό Περιγραφή

mark Ναι * Ο Μοναδικός Αριθμός Καταχώρησης (ΜΑΡΚ) του Δελτίου Αποστολής

myDATA REST API 11

qrUrl Ναι * Το URL του QR code του Δελτίου Αποστολής issuerVatNumber Όχι Το ΑΦΜ του εκδότη. Επιτρέπεται μόνο αν η κλήση γίνει με την παράμετρο mark και απαιτείται αν ο καλών δεν είναι ο εκδότης.

- Σημείωση: Πρέπει να παρέχεται υποχρεωτικά είτε το mark είτε το qrUrl. Δεν επιτρέπεται η

ταυτόχρονη χρήση και των δύο

Παρατηρήσεις:

1. Η μέθοδος επιστρέφει ένα αντικείμενο DeliveryNoteStatusResponse που περιέχει

την τρέχουσα κατάσταση (status) και το ιστορικό (lifecycleHistory). Η δομή του στοιχείου DeliveryNoteStatusResponse περιγράφεται και αναλύεται στο κεφάλαιο:

5. 2

2. Η κλήση επιτρέπεται στον εκδότη, τον λήπτη και σε οποιονδήποτε μεταφορέα

συμμετείχε στη διακίνηση.

- Σημείωση: Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι

διαθέσιμη στο URL: https://mydataapidev.aade.gr/GetDeliveryNoteStatus?mark={mark} ή εναλλακτικά: https://mydataapidev.aade.gr/GetDeliveryNoteStatus?qrUrl={qrUrl}

3. 2.5 GenerateGroupQRCode

Η κλήση της μεθόδου GenerateGroupQRCode είναι διαθέσιμη μέσω του ακόλουθου URL:

https://mydatapi.aade.gr/myDATA/GenerateGroupQRCode

Η κλήση έχει τα ακόλουθα χαρακτηριστικά:

 /GenerateGroupQRCode, μέθοδος POST

 Headers όπως αναφέρεται στην παράγραφο: 3.1.1

 Body που αποτελείται από ένα στοιχείο GenerateGroupQRCodeRequest. Ο τύπος περιγράφεται από το παρακάτω διάγραμμα:

myDATA REST API 12

Πεδίο Τύπος Υποχρεωτικό Περιγραφή qrUrls QrUrlsType Ναι Λίστα με τα URL των QR code προς ομαδοποίηση. QrUrlsType xs:string Ναι

Παρατηρήσεις:

1. Η μέθοδος μπορεί να κληθεί από οποιονδήποτε εξουσιοδοτημένο χρήστη (εκδότη ή

μεταφορέα).

2. Απαιτούνται τουλάχιστον 2 qrUrl για τη δημιουργία ομάδας.

3. Η απόκριση περιέχει το groupQrUrl, το οποίο μπορεί να χρησιμοποιηθεί στις

μεθόδους RegisterTransfer, ConfirmDeliveryOutcome και RejectDeliveryNote για την ταυτόχρονη ενημέρωση όλων των ΔΑ της ομάδας.

4. Το groupQrUrl έχει περιορισμένη διάρκεια ισχύος, η οποία επιστρέφεται στο

πεδίο expiresAt.

- Σημείωση: Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι

διαθέσιμη στο URL: https://mydataapidev.aade.gr/GenerateGroupQRCode

3. 2.6 RequestGroupQRDetails

Η κλήση της μεθόδου RequestGroupQRDetails είναι διαθέσιμη μέσω του ακόλουθου URL:

https://mydatapi.aade.gr/myDATA/RequestGroupQRDetails?groupId={groupId}

Η κλήση έχει τα ακόλουθα χαρακτηριστικά:

 /RequestGroupQRDetails, μέθοδος GET ή POST  Headers όπως αναφέρεται στην παράγραφο: 3.1.1  Είσοδος δεδομένων που γίνεται είτε μέσω παραμέτρου URL (για GET) είτε μέσω Body που αποτελείται από ένα στοιχείο

myDATA REST API 13

Πεδίο Τύπος Υποχρεωτικό Περιγραφή

groupId xs:string Ναι Το μοναδικό αναγνωριστικό (ID) του Ομαδικού QR Code για το οποίο ζητούνται λεπτομέρειες.

Παρατηρήσεις:

1. Η μέθοδος καλείται για να ανακτηθούν τα επιμέρους QR Codes που περιέχονται σε

ένα Ομαδικό QR Code (Group QR).

2. Η απόκριση περιέχει τη λίστα των qrUrls, το πλήθος τους, τον ΑΦΜ του

δημιουργού, καθώς και την ημερομηνία λήξης της ομάδας.

- Σημείωση: Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι

διαθέσιμη στο URL: https://mydataapidev.aade.gr/RequestGroupQRDetails?groupId={groupId}

myDATA REST API 14

3. 2.7 ConfirmDeliveryReturn

Η κλήση της μεθόδου ConfirmDeliveryReturn είναι διαθέσιμη μέσω του ακόλουθου URL:

https://mydatapi.aade.gr/myDATA/ConfirmDeliveryReturn

Η κλήση έχει τα ακόλουθα χαρακτηριστικά:

 /ConfirmDeliveryReturn, μέθοδος POST

 Headers όπως αναφέρεται στην παράγραφο: 3.1.1

Body που αποτελείται από ένα στοιχείο ConfirmDeliveryReturnRequest. Ο τύπος περιγράφεται από το παρακάτω διάγραμμα.

Πεδίο Τύπος Υποχρεωτικό Περιγραφή qrUrl xs:string Ναι Το URL του QR code του Δελτίου Αποστολής ή του Ομαδικού QR Code.

Παρατηρήσεις:

1. Η μέθοδος καλείται από τον Εκδότη του Δελτίου Διακίνησης για να δηλώσει την

ολοκλήρωση της διακίνησης κατά την επιστροφή (ο μεταφορέας δεν παρέδωσε όλα τα αγαθά)

2. Η μέθοδος καλείται στις εξής περιπτώσεις:

Συνθήκη Προηγούμενη Κατάσταση Αν έχει γίνει απόρριψη του Δελτίου από τον Λήπτη Rejected Αν έχει γίνει μερική παράδοση των αγαθών του Δελτίου DeliveredByCarrier με Outcome: PARTIAL Αποτυχία παράδοσης των αγαθών FailedDelivery Συγκεντρωτικό Δελτίο Διακίνησης (τύπος 9.2) InTransit Δελτίο Αποστολής Αντίστροφης Διακίνησης (τύπος 9.3 με την ένδειξη reverseDeliveryNote = true) InTransit

3. Με την επιτυχή κλήση, το Δελτίο Αποστολής μεταβαίνει σε κατάσταση Completed.

4. Σε περίπτωση επιτυχίας, η απόκριση περιέχει το deliveryReturnMark, το οποίο είναι

ο Μοναδικός Αριθμός Καταχώρησης του γεγονότος.

myDATA REST API 15

- Σημείωση: Για τη φάση της ανάπτυξης και διενέργειας δοκιμών, η μέθοδος είναι

διαθέσιμη στο URL: https://mydataapidev.aade.gr/ConfirmDeliveryReturn

### 4 Περιγραφή Σχημάτων

### 4.1 Σχήμα DeliveryEventType (Ιστορικό Γεγονότων Διακίνησης)

Το ιστορικό διακίνησης είναι μια λίστα από στοιχεία τύπου DeliveryEventType. Η δομή κάθε γεγονότος περιγράφεται παρακάτω:

myDATA REST API 16

myDATA REST API 17

Πεδίο Τύπος Υποχρεωτικό Περιγραφή

eventType xs:string Ναι Ο τύπος του γεγονότος. Αποδεκτές τιμές: RegisterTransfer, ConfirmOutcome, Rejection.

eventTimestamp xs:dateTime Ναι Η χρονική σήμανση (timestamp) του γεγονότος.

actorVat xs:string Ναι ΑΦΜ Χρήστη που δημιούργησε το συμβάν. mark xs:long Όχι Μοναδικός Αριθμός Καταχώρησης Συμβάντος (παράγεται από το myDATA). transportDetails TransportDetailType Όχι (choice) Στοιχεία μεταφοράς.

outcomeDetails OutcomeDetailsType Όχι (choice) Λεπτομέρειες για το αποτέλεσμα της παράδοσης.

rejectionDetails RejectionDetailsType Όχι (choice) Λεπτομέρειες για την απόρριψη.

- Σημείωση: Τα πεδία transportDetails, outcomeDetails και rejectionDetails είναι αμοιβαία

αποκλειόμενα (Choice).

### 4.2 Σχήμα TransportDetailType (Λεπτομέρειες Μεταφοράς)

Η δομή του σχήματος TransportDetailType περιγράφεται παρακάτω:

myDATA REST API 18

Πεδίο Τύπος Υποχρεωτικό Περιγραφή vehicleNumber xs:string Ναι Αριθμός Μεταφορικού Μέσου (Αριθμός κυκλοφορίας/Όνομα πλωτού μέσου/Κωδικός Δρομολογίου ή πτήσης/Διακίνηση άνευ Μεταφορικού Μέσου) transportType xs:int Ναι Είδος Μεταφορικού Μέσου. Αποδεκτές Τιμές: Λίστα Τιμών, λεπτομέρειες στον σχετικό πίνακα του παραρτήματος timeStamp xs:dateTime Όχι Χρονοσφραγίδα carrierVatNumber xs:string Ναι ΑΦΜ Μεταφορικής Εταιρείας pNumber xs:string Όχι Αριθμός κυκλοφορίας "Ρ" (αριθμός κυκλοφορίας του επικαθήμενου/ρυμουλκούμενου οχήματος) location LocationType Όχι Τοποθεσία Μεταφόρτωσης longitude xs:decimal Ναι Γεωγραφικό Μήκος latitude xs:decimal Ναι Γεωγραφικό Πλάτος packingsDeclaration PackagingDetailType Όχι Δήλωση Συσκευασιών

myDATA REST API 19

### 4.3 Σχήμα OutcomeDetailsType (Λεπτομέρειες Αποτελέσματος

Παράδοσης)

Η δομή του σχήματος OutcomeDetailsType περιγράφεται παρακάτω:

Πεδίο Τύπος Υποχρεωτικό Περιγραφή outcome DeliveryOutcomeType Ναι Το αποτέλεσμα της παράδοσης (FULL, PARTIAL, NONE). deliveredWithoutRecipient xs:boolean Όχι Έχει τιμή true αν η παράδοση έγινε χωρίς την παρουσία του παραλήπτη. deliveredPackaging PackagingDetailType Όχι Λίστα με τις παραδοθείσες συσκευασίες.

### 4.4 Σχήμα PackagingDetailType (Πληροφορίες Συσκευασίας)

Η δομή του σχήματος PackagingDetailType περιγράφεται παρακάτω:

myDATA REST API 20

Πεδίο Τύπος Υποχρεωτικό Περιγραφή Αποδεκτές τιμές packagingType xs:int Ναι Είδος Συσκευασίας Επιτρεπτές τιμές {1,6}.

quantity xs:int Ναι Πλήθος otherPackagingTypeTitle xs:string Όχι Τίτλος για Λοιπά Είδη Συσκευασίας

### 4.5 Σχήμα RejectionDetailsType (Λεπτομέρειες Απόρριψης)

Η δομή του σχήματος RejectionDetailsType περιγράφεται παρακάτω:

Πεδίο Τύπος Υποχρεωτικό Περιγραφή reason xs:string Όχι Προαιρετική αιτιολογία απόρριψης

### 5 Περιγραφή Απαντήσεων

### 5.1 Υποβολή Δεδομένων

Στις περιπτώσεις που ο χρήστης χρησιμοποιήσει κάποια μέθοδο υποβολής στοιχείων ή ακύρωση (RegisterTransfer, ConfirmDeliveryOutcome, RejectDeliveryNote, CancelDeliveryNote, ConfirmDeliveryReturn) θα λαμβάνει ως απάντηση ένα αντικείμενο ResponseDoc σε xml μορφή. Το αντικείμενο περιλαμβάνει μια λίστα από στοιχεία τύπου response, ένα για κάθε οντότητα που υποβλήθηκε.

Πεδίο Τύπος Υποχρεωτικό Περιγραφή Τιμές index xs:int Όχι Αριθμός Σειράς Οντότητας εντός του υποβληθέντος xml statusCode xs:string Ναι Κωδικός Αποτελέσματος Success, ValidationError, TechnicalError, XMLSyntaxError transferMark xs:long Όχι Μοναδικός Αριθμός Εκκίνησης/Μεταφόρτωσης Διακίνησης

myDATA REST API 21

rejectMark xs:long Όχι Μοναδικός Αριθμός Απόρριψης Διακίνησης deliveryOutcomeMark xs:long Όχι Μοναδικός Αριθμός Αποτελέσματος Παράδοσης Διακίνησης deliveryReturnMark xs:long Όχι Μοναδικός Αριθμός Επιστροφής Διακίνησης errors ErrorType Ναι (choice) Λίστα Σφαλμάτων

Παρατηρήσεις:

1. Το είδος της απάντησης (πετυχημένη ή αποτυχημένη διαδικασία) καθορίζεται από την

τιμή του πεδίου statusCode.

2. Σε περίπτωση επιτυχίας το πεδίο statusCode έχει τιμή Success και η απάντηση

περιλαμβάνει τις αντίστοιχες τιμές για τα πεδία transferMark, rejectMark, deliveryOutcomeMark και deliveryReturnMark ανάλογα με την οντότητα που υποβλήθηκε.

3. Σε περίπτωση αποτυχίας το πεδίο statusCode έχει τιμή αντίστοιχη του είδους του

σφάλματος και η απάντηση περιλαμβάνει μια λίστα στοιχείων σφάλματος τύπου ErrorType για κάθε οντότητα που η υποβολή της απέτυχε. Όλα τα στοιχεία σφάλματος ανά οντότητα είναι υποχρεωτικά της ίδιας κατηγορίας που χαρακτηρίζει την απάντηση.

4. Το πεδίο transferMark επιστρέφει μόνο στην περίπτωση κλήση της μεθόδου

RegisterTransfer

5. Το πεδίο rejectMark επιστρέφει μόνο στην περίπτωση κλήση της μεθόδου

RejectDeliveryNote

6. Το πεδίο deliveryOutcomeMark επιστρέφει μόνο στην περίπτωση κλήση της μεθόδου

ConfirmDeliveryOutcome

7. Το πεδίο deliveryReturnMark επιστρέφει μόνο στην περίπτωση κλήση της μεθόδου

ConfirmDeliveryReturn

### 5.2 Λήψη Κατάστασης (DeliveryNoteStatusResponse)

Στην περίπτωση που ο χρήστης χρησιμοποιήσει τη μέθοδο GetDeliveryNoteStatus θα λαμβάνει ως απάντηση ένα αντικείμενο DeliveryNoteStatusResponse σε xml μορφή. Η δομή του περιγράφεται παρακάτω :

myDATA REST API 22

Πεδίο Τύπος Υποχρεωτικό Περιγραφή Τιμές invoiceMark xs:string Ναι Το Mark του Παραστατικού Διακίνησης status xs:string Ναι Τρέχουσα Κατάσταση Παραστατικού Διακίνησης Λίστα τιμών (περιγράφονται στον πίνακα παρατήματος Καταστάσεις Παραστατικού Διακίνησης) dispatchTimestamp xs:dateTime Ναι Ημερομηνία και Ώρα Εκκίνησης/Μεταφόρτωσης Διακίνησης lifecycleHistory DeliveryEventType Όχι Ιστορικό Γεγονότων Διακίνησης

Παρατηρήσεις:

1. Η δομή του στοιχείου DeliveryEventType περιγράφεται και αναλύεται στο

κεφάλαιο: 4.1

### 5.3 Δημιουργίας Ομαδικού QR (GenerateGroupQRCodeResponse)

Στην περίπτωση που ο χρήστης χρησιμοποιήσει τη μέθοδο GenerateGroupQRCode θα λαμβάνει ως απάντηση ένα αντικείμενο GenerateGroupQRCodeResponse σε xml μορφή. Η δομή του περιγράφεται παρακάτω :

myDATA REST API 23

Πεδίο Τύπος Υποχρεωτικό Περιγραφή Τιμές groupQrUrl xs:string Ναι Το νέο, ομαδικό URL του QR Code. qrUrlsCount xs:int Ναι Το πλήθος των ΔΑ που περιλαμβάνονται στην ομάδα. expiresAt xs:string Ναι Η ημερομηνία και ώρα λήξης του ομαδικού QR Code. statusCode xs:string Ναι Το αποτέλεσμα της επεξεργασίας. Success, ValidationError, TechnicalError, XMLSyntaxError

myDATA REST API 24

### 6 Σφάλματα

Τα σφάλματα είναι στοιχεία ErrorType και περιγράφονται παρακάτω:

Κάθε στοιχείο σφάλματος που αφορά μια οντότητα αποτελείται από ένα μήνυμα που περιγράφει το σφάλμα και έναν κωδικό σφάλματος.

Πεδίο Τύπος Υποχρεωτικό Περιγραφή message xs:string Ναι Μήνυμα Σφάλματος code xs:string Ναι Κωδικός Σφάλματος

### 6.1 Τεχνικά Σφάλματα

Τα τεχνικά σφάλματα χαρακτηρίζουν την κλήση ως μη επιτυχημένη και επιστρέφουν ένα τυπικό.ΝΕΤ HttpResponseMessage αντί για το ErrorType που περιγράφεται στην παράγραφο 6. Ως εκ τούτου δεν έχουν ειδικό κωδικό σφάλματος, δεν συνοδεύονται από κάποιο statusCode του στοιχείου ResponseType, και αναγνωρίζονται από το αντίστοιχο HttpStatusCode.

# HTTP Response Περιγραφή Περιγραφή ENG 1 HTTP 401 UNAUTHORIZED Λείπει η κεφαλίδα Aade-user-id Aade-user-id header is missing

2 HTTP 401 UNAUTHORIZED Το κλειδί πρόσβασης δεν αντιστοιχεί στο δεδομένο αναγνωριστικό χρήστη (User Id)

Access Key does not correspond to given User Id

3 HTTP 400 BAD_REQUEST Περάστε το MARK στις παραμέτρους ή στο σώμα του http αιτήματος

Please pass mark in the request parameters or body

4 HTTP 400 BAD_REQUEST Γενικό Σφάλμα Εξαίρεσης General Exception Error

myDATA REST API 25

### 6.2 Επιχειρησιακά Σφάλματα

Τα επιχειρησιακά σφάλματα είναι τύπου ErrorType (βλ Παρ. 6) και προκύπτουν κατά την αποτυχία των επιχειρησιακών ελέγχων. Στην περίπτωση τους η κλήση θεωρείται τεχνικά επιτυχημένη (HTTP Response 200).

HTTP Response statusCode Κωδικός Στοιχείο Περιγραφή Περιγραφή ENG HTTP 200 OK XMLSyntaxError 100 Application Σφάλμα επικύρωσης σύνταξης XML XML Syntax Validation Error

HTTP 200 OK ValidationError 800 Application Η κλήση της μεθόδου RegisterTransfer, δεν επιτρέπεται λόγω της τρέχουσας κατάστασης της διακίνησης: {deliveryStatus}

Cannot call RegisterTransfer because of its current delivery status: {deliveryStatus}

HTTP 200 OK ValidationError 801 Application Το παραστατικό δεν μπορεί να ακυρωθεί λόγω της τρέχουσας κατάστασης διακίνησης: {deliveryStatus} (αφορά τη μέθοδο CancelDeliveryNote).

Invoice cannot be canceled due to its current delivery status: {deliveryStatus} (regards CancelDeliveryNote method).

HTTP 200 OK ValidationError 802 Application Το παραστατικό δεν μπορεί να απορριφθεί λόγω της τρέχουσας κατάστασης διακίνησης: {deliveryStatus} (αφορά τη μέθοδο RejectDeliveryNote).

Invoice cannot be rejected due to its current delivery status: {deliveryStatus} (regards RejectDeliveryNote method).

HTTP 200 OK ValidationError 803 Application Ο χρήστης δεν μπορεί να απορρίψει το παραστατικό. Μόνο ο λήπτης έχει αυτό το δικαίωμα (αφορά τη μέθοδο RejectDeliveryNote).

The user cannot reject the invoice. Only the recipient has this right (regards RejectDeliveryNote method).

HTTP 200 OK ValidationError 804 Application Το παραστατικό δεν μπορεί να απορριφθεί λόγω του τύπου του (αφορά τη μέθοδο RejectDeliveryNote).

Invoice cannot be rejected due to its type. (regards RejectDeliveryNote method).

HTTP 200 OK ValidationError 805 Application Η κλήση της μεθόδου RegisterTransfer για το παραστατικό με ΜΑΡΚ: {mark} δεν επιτρέπεται λόγω του τύπου του: (π.χ 1.1 (με την ένδειξη isDeliveryNote = false), Δεν είναι παραστατικό διακίνησης)

Cannot call RegisterTransfer for Invoice with MARK: {mark} because of its invoiceType: (e.g 1.1 (with isDeliveryNote = false), It is not deliveryNote)

HTTP 200 OK ValidationError 806 Application Δεν βρέθηκε QR! Not Found QR!

HTTP 200 OK ValidationError 807 Application Μη έγκυρο QR! Invalid QR!

HTTP 200 OK ValidationError 808 Application Δεν βρέθηκε παραστατικό για αυτό το QR No Invoice found for this QR!

myDATA REST API 26

HTTP 200 OK ValidationError 809 Application Η κλήση της μεθόδου ConfirmDeliveryOutcome για το παραστατικό με ΜΑΡΚ: {mark} δεν επιτρέπεται. Το παραστατικό έχει ακυρωθεί.

Cannot confirm delivery outcome for Invoice with MARK: {mark}. The invoice has been cancelled.

HTTP 200 OK ValidationError 810 Application Η κλήση της μεθόδου ConfirmDeliveryOutcome για το παραστατικό με ΜΑΡΚ: {mark} δεν επιτρέπεται. Το παραστατικό διακίνησης έχει απορριφθεί.

Cannot confirm delivery outcome for Invoice with MARK: {mark}. The delivery note has been rejected.

HTTP 200 OK ValidationError 811 Application Η κλήση της μεθόδου ConfirmDeliveryOutcome για το παραστατικό με ΜΑΡΚ: {mark} δεν επιτρέπεται. Η παράδοση του έχει ολοκληρωθεί.

Cannot confirm delivery outcome for Invoice with MARK: {mark}. The delivery has already been completed.

HTTP 200 OK ValidationError 812 Application Η κλήση της μεθόδου ConfirmDeliveryOutcome για το παραστατικό με ΜΑΡΚ: {mark} δεν επιτρέπεται. Η παράδοση του έχει δηλωθεί ότι έχει αποτύχει (δεν πραγματοποιήθηκε).

Cannot confirm delivery outcome for Invoice with MARK: {mark}. The delivery has already been failed.

HTTP 200 OK ValidationError 813 Application Η κλήση της μεθόδου ConfirmDeliveryOutcome για το παραστατικό με ΜΑΡΚ: {mark} δεν επιτρέπεται. Δεν έχει ξεκινήσει ακόμα η διακίνηση του. Τρέχουσα κατάσταση διακίνησης: Registered (Το παραστατικό διακίνησης έχει εκδοθεί επιτυχώς.)

Cannot confirm delivery outcome for Invoice with MARK: {mark}. It has not been dispatched yet. Current status: Registered

HTTP 200 OK ValidationError 814 Application Το πεδίο deliveredPackaging είναι υποχρεωτικό όταν το πεδίο outcome (αποτέλεσμα της παράδοσης) έχει την τιμή: PARTIAL! (αφορά τη μέθοδο ConfirmDeliveryOutcome).

deliveredPackaging is required when outcome is PARTIAL! (regards ConfirmDeliveryOutcome method).

HTTP 200 OK ValidationError 815 Application Μη έγκυρη τιμή του πεδίου packagingType. Οι τιμές του πρέπει να είναι μεταξύ 1 και 6.

Invalid packagingType: {value}. Must be between 1 and 6.

myDATA REST API 27

HTTP 200 OK ValidationError 816 Application Μη έγκυρη τιμή του πεδίου quantity. Η τιμή του πρέπει να είναι μεγαλύτερη του 0.

Invalid quantity: {value}. Must be greater than 0.

HTTP 200 OK ValidationError 817 Application Μόνο ο μεταφορέας μπορεί να θέσει την τιμή NONE (αποτυχία παράδοσης) στο πεδίο outcome (αποτέλεσμα της παράδοσης) (αφορά τη μέθοδο ConfirmDeliveryOutcome).

Only the carrier can set outcome to NONE (failed delivery)! (regards ConfirmDeliveryOutcome method).

HTTP 200 OK ValidationError 818 Application Ο λήπτης δεν μπορεί να θέσει την τιμή NONE (αποτυχία παράδοσης) στο πεδίο outcome (αποτέλεσμα της παράδοσης) στην περίπτωση Β2Β διακίνησης (αφορά τη μέθοδο ConfirmDeliveryOutcome).

Recipient cannot set outcome to NONE in B2B scenario!! (regards ConfirmDeliveryOutcome method).

HTTP 200 OK ValidationError 819 Application Δεν επιτρέπεται η κλήση της μεθόδου ConfirmDeliveryOutcome. Ο μεταφορέας έχει ήδη δηλώσει παράδοση. Μόνο ο λήπτης μπορεί να την καλέσει για επιβεβαίωση παραλαβής

Cannot confirm delivery outcome. The carrier has already declared the delivery. Only the recipient can confirm.

HTTP 200 OK ValidationError 820 Application Δεν βρέθηκε το ομαδικό (Group) QR ή έχει λήξει η διάρκεια του

Group QR not found or has expired

HTTP 200 OK ValidationError 821 Application Η κλήση της μεθόδου RegisterTransfer για το παραστατικό με ΜΑΡΚ: {mark} δεν επιτρέπεται. Το παραστατικό έχει ακυρωθεί.

Cannot call RegisterTransfer for Invoice with MARK: {mark}. The invoice has been cancelled.

HTTP 200 OK ValidationError 822 Application Η κλήση της μεθόδου RejectDeliveryNote για το παραστατικό με ΜΑΡΚ: {mark} δεν επιτρέπεται λόγω της τρέχουσας κατάστασης της διακίνησης του: {DeliveryStatus}. Μόνο παραστατικά στις καταστάσεις Registered, InTransit ή DeliveredByCarrier μπορούν να απορριφθούν.

Cannot call RejectDeliveryNote for Invoice with MARK: {mark} due to its current movement status: {DeliveryStatus}. Only Registered or InTransit or DeliveredByCarrier can be rejected.

HTTP 200 OK ValidationError 823 Application Δεν επιτρέπονται και το QrUrl και το invoiceMark πεδίο (αφορά τη μέθοδο

Both QrUrl and invoiceMark are not allowed (regards RejectDeliveryNote method).

myDATA REST API 28

RejectDeliveryNote). HTTP 200 OK ValidationError 824 Application Απαιτείται είτε το QrUrl ή το invoiceMark πεδίο (αφορά τη μέθοδο RejectDeliveryNote).

Either QrUrl or invoiceMark is required (regards RejectDeliveryNote method).

HTTP 200 OK ValidationError 825 Application Η κλήση της μεθόδου ConfirmDeliveryReturn για το παραστατικό με ΜΑΡΚ: {mark} δεν επιτρέπεται. Το παραστατικό έχει ακυρωθεί.

Cannot confirm delivery return for Invoice with MARK: {mark}. The invoice has been cancelled

HTTP 200 OK ValidationError 826 Application Η κλήση της μεθόδου ConfirmDeliveryReturn για το παραστατικό με ΜΑΡΚ: {mark} δεν επιτρέπεται. Η παράδοση (διακίνηση) του έχει ολοκληρωθεί.

Cannot confirm delivery return for Invoice with MARK: {mark}. The delivery has already been completed.

HTTP 200 OK ValidationError 827 Application Η κλήση της μεθόδου ConfirmDeliveryReturn για το παραστατικό με ΜΑΡΚ: {mark} δεν επιτρέπεται. Δεν έχει ξεκινήσει ακόμα η διακίνηση του. Τρέχουσα κατάσταση διακίνησης: Registered (Το παραστατικό διακίνησης έχει εκδοθεί επιτυχώς.)

Cannot confirm delivery return for Invoice with MARK: {mark}. It has not been dispatched yet. Current status: {Registered}

HTTP 200 OK ValidationError 828 Application Η κλήση της μεθόδου ConfirmDeliveryReturn για το παραστατικό με ΜΑΡΚ: {mark} δεν επιτρέπεται. Λόγω της τρέχουσας κατάστασης διακίνησης: {DeliveryStatus}

Cannot call ConfirmDeliveryReturn for Invoice with MARK: {mark} because of its current delivery status: {DeliveryStatus}

HTTP 200 OK ValidationError 829 Application Ο χρήστης με {ΑΦΜ Χρήστη} δεν μπορεί να μπορεί να καλέσει τη μέθοδο ConfirmDeliveryReturn για το παραστατικό με ΜΑΡΚ: {mark}. Μόνο ο εκδότης του παραστατικού μπορεί να την καλέσει

User with VAT number: {parameters[0]} cannot call ConfirmDeliveryReturn for Invoice with MARK: {mark}. Only the issuer can call it.

HTTP 200 OK ValidationError 830 Application Μόνο ο μεταφορέας μπορεί να θέσει την τιμή PARTIAL (μερική παράδοση) στο πεδίο outcome (αποτέλεσμα της παράδοσης) (αφορά τη μέθοδο ConfirmDeliveryOutcome).

Only the carrier can set outcome to PARTIAL! (regards ConfirmDeliveryOutcome method).

myDATA REST API 29

HTTP 200 OK ValidationError 831 Application Δεν επιτρέπεται η κλήση της μεθόδου ConfirmDeliveryOutcome. Ο μεταφορέας έχει ήδη δηλώσει μερική παράδοση.

Cannot confirm delivery outcome. The carrier has already declared the partial delivery.

HTTP 200 OK ValidationError 832 Application Μόνο ο μεταφορέας μπορεί να καλέσει τη μέθοδο ConfirmDeliveryOutcome για το παραστατικό με ΜΑΡΚ: {mark} διότι το παραστατικό εκδόθηκε με την ένδειξη NonObligatedRecipient == true (Μη Υπόχρεος Λήπτης).

Only the carrier can confirm delivery outcome for Invoice with MARK: {mark} because invoice has been issued with NonObligatedRecipient == true (regards ConfirmDeliveryOutcome method)

HTTP 200 OK ValidationError 833 Application Ο χρήστης με {ΑΦΜ Χρήστη} δεν έχει δικαίωμα να καλέσει τη μέθοδο ConfirmDeliveryOutcome για το παραστατικό με ΜΑΡΚ: {mark}. Μόνο λήπτης ή ο μεταφορέας του παραστατικού μπορεί να την καλέσει

User with VAT number: {parameters[0]} is not authorized to confirm delivery outcome for Invoice for Invoice with MARK: {mark}. Only the recipient or carrier can confirm delivery outcome. (regards ConfirmDeliveryOutcome method) HTTP 200 OK ValidationError 834 Application Η κλήση της μεθόδου ConfirmDeliveryOutcome, δεν επιτρέπεται λόγω της τρέχουσας κατάστασης της διακίνησης: {deliveryStatus

Cannot call ConfirmDeliveryOutcomebec ause of its current delivery status: {deliveryStatus}

HTTP 200 OK TechnicalError - - Μη αναμενόμενο σφάλμα συνθήκης Unexpected condition error

myDATA REST API 30

### 7 Παράρτημα

### 7.1 Καταστάσεις Δελτίου Αποστολής (InvoiceDeliveryStatus)

Κωδικός Περιγραφή Επεξήγηση

1 Registered Το ΔΑ έχει εκδοθεί επιτυχώς.

2 Cancelled Ο εκδότης ακύρωσε το ΔΑ πριν την έναρξη της διακίνησης.

3 InTransit Η διακίνηση έχει ξεκινήσει.

4 Rejected Ο λήπτης απέρριψε την παραλαβή.

5 DeliveredByCarrier Ο μεταφορέας δήλωσε παράδοση (αναμονή επιβεβαίωσης από λήπτη B2B).

7 FailedDelivery Ο μεταφορέας δήλωσε αποτυχία παράδοσης.

8 Completed Η διακίνηση ολοκληρώθηκε με επιτυχία.

9 InTransit (Return) Ο μεταφορέας επιστρέφει με εμπόρευμα

### 7.2 Τύποι Γεγονότων (DeliveryEventType)

Κωδικός Περιγραφή RegisterTransfer Έναρξη διακίνησης ή μεταφόρτωση.

ConfirmOutcome Δήλωση αποτελέσματος παράδοσης / Επιβεβαίωση παραλαβής. Rejection Ολική απόρριψη από τον λήπτη.

ConfirmReturn Επιβεβαίωση από εκδότη της επιστροφής

RegisterTransferReturn Επιστροφή (όταν δεν έγινε πλήρης παράδοση)

myDATA REST API 31

### 7.3 Τύποι Συσκευασίας (PackagingType)

Κωδικός Περιγραφή

1 Παλέτα

2 Κούτα

3 Κιβώτιο

4 Βαρέλι

5 Σάκος

6 Λοιπά

### 7.4 Είδος Μεταφορικού Μέσου (transportType)

Κωδικός Περιγραφή

1 Φορτηγό Δημόσιας Χρήσης

2 Φορτηγό Ιδιωτικής Χρήσης

3 Πλοίο

4 Τρένο

5 Αεροπλάνο

6 Λοιπά Μεταφορικά Μέσα (π.χ Δίκυκλα, ..) 7 Άνευ

myDATA REST API 32

### 8 Ιστορικό αλλαγών

### 8.1 Έκδοση 2.0.0

Αρχική δημιουργία του εγγράφου τεκμηρίωσης για τη λειτουργικότητα του Ψηφιακού Δελτίου Αποστολής.

### 8.2 Έκδοση 2.0.1

 Προσθήκες

 Παρ. 3.2.3: Προσθήκη του πεδίου invoiceMark στο body (τύπου RejectDeliveryNoteRequest) της μεθόδου RejectDeliveryNote, ώστε να μπορεί να γίνει απόρριψη ενός παραστατικού διακίνησης από τον λήπτη του και με την χρήση του ΜΑΡΚ του παραστατικού  Προσθήκη του πίνακα παραρτήματος: Είδος Μεταφορικού Μέσου (Παρ. 7.4)  Παρ. 5.1: Προσθήκη του πεδίου deliveryOutcomeMark στο σχήμα του τύπου response και επεξήγηση του στις Παρατηρήσεις  Παρ. 6.2: Προσθήκη κωδικών επιχειρησιακών σφαλμάτων (Κωδικοί από 805 έως και 824).

 Ενημερώσεις

 Παρ. 3.2.1: transportMark μετονομασία σε transferMark  Παρ. 3.2.3: Το πεδίο qrUrl έγινε υποχρεωτικό/choice (από υποχρεωτικό)  Παρ. 5.1: Αλλαγή του τύπου των πεδίων transferMark (πρώην transportMark) και rejectMark, από xs:string σε xs:long  Παρ. 5.2: Αλλαγή στις τιμές του πεδίου status (λίστα τιμών, οι τιμές των οποίων περιγράφονται στον πίνακα παρατήματος Καταστάσεις Παραστατικού Διακίνησης αντί των λανθασμένων αναγραφόμενων τιμών: Success, ValidationError, TechnicalError, XMLSyntaxError)  Παρ. 7.1: Αντικατάσταση των τιμών της στήλης Κωδικός του πίνακα με τις αριθμητικές τιμές που αντιστοιχούν στην κατάσταση του Δελτίου Αποστολής (InvoiceDeliveryStatus), μετακίνηση των παλιών τιμών της στήλης Κωδικός (αλφαριθμητικά) στη στήλη Περιγραφή και μετακίνηση των παλιών τιμών της στήλης Περιγραφή σε νέα στήλη με το όνομα Επεξήγηση

myDATA REST API 33

### 8.3 Έκδοση 2.0.2

 Προσθήκες

 Παρ. 1.2: Προσθήκη νέας κατάστασης: InTransit (Return)  Παρ. 3.2.1: Στην υπ. αριθμ. 2 Παρατήρηση προσθήκη της μετάβασης και στην κατάσταση InTransit (Return) καθώς και σχετικού πίνακα απεικόνισης των περιπτώσεων μετάβασης στις καταστάσεις InTransit ή InTransit (Return) με την κλήση της μεθόδου RegisterTransfer  Παρ. 3.2.2: Προσθήκη της υπ’ αριθμ. 6 Παρατήρησης  Παρ. 3.2.4: Προσθήκη εναλλακτικά της παραμέτρου qrUrl για την κλήση της μεθόδου GetDeliveryNoteStatus  Προσθήκη της νέας μεθόδου ConfirmDeliveryReturn (Παρ. 3 και Παρ. 3.2.7)

 Ενημερώσεις

 Παρ. 1.2: Αλλαγή του πότε μπορεί να βρεθεί σε κατάσταση Completed το δελτίο  Παρ. 1.2: Αλλαγή του διαγράμματος καταστάσεων  Παρ. 3.2.2: Αλλαγή της υπ’ αριθμ. 3 Παρατήρησης (NonObligatedRecipient = true)  Παρ. 3.2.2: Αλλαγή της υπ’ αριθμ. 4 Παρατήρησης (Ο λήπτης δεν μπορεί να καλέσει την ConfirmDeliveryOutcome αν το ΔΑ είναι σε κατάσταση FailedDelivery ή Μερικής Παράδοσης από τον μεταφορέα)  Παρ. 3.2.4: Το πεδίο mark έγινε υποχρεωτικό υπό συνθήκη (σχετική επεξήγηση στο παράγραφο *Σημείωση)  Παρ. 3.2.4: Ενημέρωση της στήλης Περιγραφή του πίνακα του πεδίου issuerVatNumber  Παρ. 3.2.3: Διαγραφή της λέξης τελική (τελική κατάσταση) από τη 2 η

παράγραφο των Παρατηρήσεων  Παρ. 4.1: Αλλαγή του διαγράμματος (προσθήκη και του πεδίου packingsDeclaration στο outcomeDetails)  Παρ. 4.2: Προσθήκη του πεδίου packingsDeclaration  Παρ. 5.1: Προσθήκη και του πεδίου deliveryReturnMark (περίπτωση κλήσης της νέας μεθόδου ConfirmDeliveryReturn)  Παρ. 6.2: Μικρή αλλαγή των περιγραφών των κωδικών επιχειρησιακών σφαλμάτων: 800, 801 και 802.  Παρ. 6.2: Προσθήκη κωδικών επιχειρησιακών σφαλμάτων (Κωδικοί από 825 έως και 834).  Παρ. 7.1: Προσθήκη του κωδικού κατάστασης 9: InTransit (Return).  Παρ. 7.2: Προσθήκη των κωδικών ConfirmReturn και RegisterTransferReturn