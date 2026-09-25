# ΕΡΓΑΝΗ ΙΙ — Οδηγός Χρήσης Διαλειτουργικοτήτων (Web API) — κείμενο

> Αυτούσια εξαγωγή κειμένου (pypdf) του επίσημου PDF `ERGANI_II_Odigos_Dialeitourgikotiton_2025-04-15.pdf`
> (Υπουργείο Εργασίας & Κοινωνικής Ασφάλισης, «Τελευταία Ενημέρωση: 15/04/2025»). Πηγή:
> https://static-ypakp-gr-gefufeabdmg3ggcs.a01.azurefd.net/staticfiles/trialv2/ (ΕΡΓΑΝΗ ΙΙ - Οδηγός Χρήσης Διαλειτουργικοτήτων.pdf).
> Αναπαράγεται για μη κερδοσκοπικό/τεχνικό σκοπό με αναφορά πηγής, όπως επιτρέπει η σημείωση copyright της σελ. 2.
> Οι πίνακες της σελ. 31–42 βγήκαν «σπασμένοι» στην εξαγωγή — για τους κωδικούς εντύπων δες το PDF. Σύνοψη/σχέδιο: `README.md`.

```text


<!-- page 1 -->
 
  
Τελευταία Ενημέρωση: 15/04/2025
Πληροφοριακό 
Σύστημα
Εργάνη ΙΙ
ΕΛΛΗΝΙΚΗ ΔΗΜΟΚΡΑΤΙΑ
Υπουργείο Εργασίας & Κοινωνικής Ασφάλισης
Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας
Οδηγός  Εφαρμογής
 

<!-- page 2 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 2 / 47 
 
           
 
Copyright © Υπουργείο Εργασίας και Κοινωνικής Ασφάλισης 
 
Με επιφύλαξη παντός δικαιώματος. 
Απαγορεύεται η αντιγραφή, αποθήκευση και διανομή του παρόντος Οδηγού εξ ολοκλήρου ή τμήματος 
αυτού, για εμπορικό σκοπό. Επιτρέπεται η ανατύπωση, αποθήκευση και διανομή για σκοπό μη 
κερδοσκοπικό, εκπαιδευτικής ή ερευνητικής φύσης, υπό την προϋπόθεση να αναφ έρεται η πηγή 
προέλευσης και να διατηρείται το παρόν μήνυμα. Ερωτήματα που αφορούν τη χρήση του παρόντος 
Οδηγού για κερδοσκοπικό σκοπό οι ενδιαφερόμενοι θα πρέπει να απευθύνονται προς το Υπουργείο 
Εργασίας και Κοινωνικής Ασφάλισης. 
 
Copyright © Ministry of Labour and Social Security 
 
All rights reserved. 
It is prohibited to copy, store and distribute this Guide in whole or in part for commercial purposes. 
Reproduction, storage and distribution for a non-profit, educational or research purpose is permitted, 
provided the source is acknowledged and this message is preserved. 
Questions regarding the use of this Guide for commercial purposes should be addressed to the 
Ministry of Labour and Social Security. 
 
  

<!-- page 3 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 3 / 47 
 
           
Περιεχόμενα 
 
 .............................................................................................................................................................. 1 
1. Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας ................................................................. 4 
1.1. Δοκιμαστικό περιβάλλον ........................................................................................................ 4 
2. Χρήση Υπηρεσιών Διαλειτουργικότητας - Web API (REST) .......................................................... 6 
2.1. Ergani Web API για εργοδότες – Τεκμηρίωση ........................................................................ 6 
2.1.1. Authentication ................................................................................................................ 6 
2.1.2. RefreshAuthentication ................................................................................................... 8 
2.1.3. Logout ............................................................................................................................. 9 
2.1.4. Submissions ................................................................................................................. 10 
2.1.5. Documents ................................................................................................................... 11 
2.1.6. Documents (Νέα δήλωση) ........................................................................................... 13 
2.1.7. Cancel Document (Διαδικασία ανάκλησης υποβληθείσας δήλωσης υποβολής) .......... 15 
2.1.8. Documents (Διαδικασία διάθεσης υποβληθείσας δήλωσης υποβολής) ....................... 16 
2.1.9. ServicesList .................................................................................................................. 17 
2.1.10. ExecuteService ......................................................................................................... 19 
2.2. Παραδείγματα ...................................................................................................................... 25 
2.2.1. Υποβολή δήλωσης Κάρτας Εργασίας ............................................................................ 25 
3. Συνοδευτικά αρχεία .................................................................................................................... 27 
4. Λίστα τύπων Οργάνωσης Χρόνου Εργασίας ............................................................................... 28 
Παράρτημα Ι: Ύπαρξη XML – Έκδοση XSD .......................................................................................... 31 
Παράρτημα IΙ: Ορθή Χρήση Υπηρεσιών Διαλειτουργικότητας – Web API (REST) – Αυθεντικοποίηση 
μέσω JWT tokens ............................................................................................................................... 43 
1. Περιγραφή αυθεντικοποίησης για τη χρήση των Υπηρεσιών Διαλειτουργικότητας – Web API 
(Services API) ..................................................................................................................................... 43 
2. Περιγραφή ορθής χρήσης .......................................................................................................... 44 
3. Προβλεπόμενη χρήση ................................................................................................................. 45 
4. Λανθασμένη χρήση ..................................................................................................................... 46 
 
 
 
 
 
 
 
 

<!-- page 4 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 4 / 47 
 
           
 
1. Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
1.1. Δοκιμαστικό περιβάλλον 
 
Το σύνολο των υπηρεσιών διαλειτουργικότητας διατίθεται στο δοκιμαστικό περιβάλλον του ΠΣ 
Εργάνη στην ηλεκτρονική διεύθυνση: 
 
 
RestAPI: https://trialv2eservices.yeka.gr/WebservicesAPI/Api/ 
RestAPI UI: https://trialv2eservices.yeka.gr/WebservicesAPIUI/ 
 
 
Στο δοκιμαστικό περιβάλλον  https://trialv2eservices.yeka.gr, οι επιχειρήσεις έχουν πρόσβαση 
όμοια με του παραγωγικού περιβάλλοντος του ΠΣ ΕΡΓΑΝΗ.  
 
 
 
Η σύνδεση γίνεται με λογαριασμούς e -ΕΦΚΑ και μπορούν να δημιουργηθούν χρήστες 
Παραρτημάτων που αφορούν το δοκιμαστικό περιβάλλον για δοκιμές . 
 
 
Τονίζεται ότι τα έντυπα που υποβάλλονται στο  δοκιμαστικό περιβάλλον του ΠΣ Εργάνη 
φέρουν τη σήμανση  «ΑΚΥΡΟ» και δεν αποτελούν  εφαρμογή οιασδήποτε νομοθεσίας.  
 
 


<!-- page 5 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 5 / 47 
 
           
 
 
Με το τον Οδηγό του Σύστηματος Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας  παρέχεται 
συνοδευτικά και συμπιασμένος φάκελος του trial eServices) που περιέχει ,μεταξύ άλλων χρήσιμα 
Json παραδείγματα για το REST API (βλ. Συνοδευτικά αρχεία). 
 
 
  


<!-- page 6 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 6 / 47 
 
           
2. Χρήση Υπηρεσιών Διαλειτουργικότητας - Web API (REST) 
 
Εργάνη Trial endpoint: https:// trialv2eservices.yeka.gr/WebServicesApi/api/ 
 
2.1. Ergani Web API για εργοδότες – Τεκμηρίωση 
 
2.1.1. Authentication 
 
Route: Authentication, Method: Post 
Πριν από κάθε κλήση στο ΑPI απαιτείται να γίνει μια κλήση ώστε να παραχθεί ένα JSON Web Token 
(JWT). Το JWT που θα παραχθεί χρησιμοποιείται σε κάθε επόμενη κλήση στο Header.  
 
Authorization: Bearer «Access Token». 
 
Παράδειγμα Request του Authentication 
 
Header  
Content-Type: application/json 
 
Body 
{ 
    "Username": "myusername", 
    "Password": "mypassword", 
    "Usertype": "02" 
} 
 
Οι τιμές της παραμέτρου Usertype είναι οι εξής: 
 
01 - Εξωτερικός 
02 - Σύνδεση με κωδικούς «ΕΡΓΑΝΗ», 
03 - Σύνδεση με κωδικούς για Οικοδομοτεχνικά Έργα από ΕΦΚΑ 
 
Παράδειγμα Response του Authentication 
 
Body 
{ 
    "accessToken": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJodHRwOi8vc2NoZW1hcy54bWxzb2
FwLm9yZy93cy8yMDA1LzA1L2lkZW50aXR5L2NsYWltcy9uYW1lIjoiMDIiLCJodHRwOi8vc2NoZW1
hcy54bWxzb2FwLm9yZy93cy8yMDA1LzA1L2lkZW50aXR5L2NsYWltcy9uYW1laWRlbnRpZmllciI6I

<!-- page 7 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 7 / 47 
 
           
lBhcmFydGhtYSIsImh0dHA6Ly9zY2hlbWFzLm1pY3Jvc29mdC5jb20vd3MvMjAwOC8wNi9pZGVud
Gl0eS9jbGFpbXMvcHJpbWFyeXNpZCI6IjQ5NjgwIiwiZXhwIjoxNjUxNTcxMDE3fQ.pt0UEpYD98uLPk
vlZkgUbVBxemhCzG4pQRxxthWE4EQ", 
    "accessTokenExpired": 10800, 
    "refreshToken": "pnFB5Vdno3pd/YgkzBjDdn+Vxe29b5I+eTLSWD8cbWk=", 
    "refreshTokenExpired": "2022-05-10T09:43:37.5388855+03:00" 
} 
 
accessToken : Χρησιμοποιείται στο Header όπως αναφέρθηκε παραπάνω για την επόμενες κλήσης 
accessTokenExpired : Λήξη του access token σε δευτερόλεπτα. Στην περίπτωση λήξης 
επιστρέφεται στο Header η τιμή api-token-expired: true 
refreshToken : Χρησιμοποιείται για ανανέωση του access token( περιγράφεται παρακάτω) 
refreshTokenExpired : Λήξη του refresh token  

<!-- page 8 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 8 / 47 
 
           
2.1.2. RefreshAuthentication 
 
Route: Authentication/Refresh, Method: Post 
Το RefreshAuthentication είναι υπεύθυνο για την ανανέωση του access token στην περίπτωση που 
δεν έχει επέλθει ακόμα λήξη του refresh token.  
 
Παράδειγμα Request του RefreshAuthentication 
 
Header  
Content-Type: application/json 
 
Body 
{ 
    "AccessToken": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJodHRwOi8vc2NoZW1hcy54bWxzb2
FwLm9yZy93cy8yMDA1LzA1L2lkZW50aXR5L2NsYWltcy9uYW1lIjoiMDIiLCJodHRwOi8vc2NoZW1
hcy54bWxzb2FwLm9yZy93cy8yMDA1LzA1L2lkZW50aXR5L2NsYWltcy9uYW1laWRlbnRpZmllciI6I
lBhcmFydGhtYSIsImh0dHA6Ly9zY2hlbWFzLm1pY3Jvc29mdC5jb20vd3MvMjAwOC8wNi9pZGVud
Gl0eS9jbGFpbXMvcHJpbWFyeXNpZCI6IjQ5NjgwIiwiZXhwIjoxNjUwNTM4NjUwfQ.xH8Q7CEDyMN
wyDjElXPaP-xf3IF9uRzX4ZNqoy_Zzdk", 
    "RefreshToken": "43TD1+HMkakFt+uKDQoN+mKxttgHvSxN9S8AI+5e4kE=" 
} 
 
Παράδειγμα Response του RefreshAuthentication (όπως του Authentication) 
 
Body 
{ 
    "accessToken": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJodHRwOi8vc2NoZW1hcy54bWxzb2
FwLm9yZy93cy8yMDA1LzA1L2lkZW50aXR5L2NsYWltcy9uYW1lIjoiMDIiLCJodHRwOi8vc2NoZW1
hcy54bWxzb2FwLm9yZy93cy8yMDA1LzA1L2lkZW50aXR5L2NsYWltcy9uYW1laWRlbnRpZmllciI6I
lBhcmFydGhtYSIsImh0dHA6Ly9zY2hlbWFzLm1pY3Jvc29mdC5jb20vd3MvMjAwOC8wNi9pZGVud
Gl0eS9jbGFpbXMvcHJpbWFyeXNpZCI6IjQ5NjgwIiwiZXhwIjoxNjUxNjIxMDY0fQ.2p5MIVSYj_Ky0OC
48f1MP-sPUo_SCwO3GGJH6hFtH3U", 
    "accessTokenExpired": 10800, 
    "refreshToken": "1IgdTXb1Z9YFiFP6udMzsSK3hgZe4MvYCG254RDK3aw=", 
    "refreshTokenExpired": "2022-05-10T23:37:44.6725426+03:00" 
} 
 
  

<!-- page 9 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 9 / 47 
 
           
2.1.3. Logout 
 
Route: Authentication/Logout, Method: Post 
To Logout είναι υπεύθυνο για την διαγραφή του refresh token. 
 
Παράδειγμα Request του Logout 
 
Header  
Content-Type: application/json 
Body 
"43TD1+HMkakFt+uKDQoN+mKxttgHvSxN9S8AI+5e4kE=" 
 
Παράδειγμα Response του Logout 
 
Http Status Code: 200 OK 
 
  

<!-- page 10 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 10 / 47 
 
           
2.1.4. Submissions 
 
Route: Lookup/Submissions, Method: Get 
 
To Submissions είναι υπεύθυνο για την ανάκτηση όλων των ενεργών υποβολών. Δεν απαιτεί καμία 
παράμετρο στο Request. 
 
Παράδειγμα Response του Submissions 
 
Body 
[ 
    { 
        "id": 82, 
        "code": "WRKCardSE", 
        "description": "Δήλωση έναρξης/λήξης εργασίας εργαζομένων" 
    }, 
    { 
        "id": 79, 
        "code": "WKChgWK", 
        "description": "Δήλωση Μεταβολής Στοιχείων Εργασιακής Σχέσης - Οργάνωση Χρόνου 
Εργασίας" 
    }, 
    { 
        "id": 8, 
        "code": "E3", 
        "description": "Ε3 ΕΝΙΑΙΟ ΕΝΤΥΠΟ ΑΝΑΓΓΕΛΙΑΣ ΠΡΟΣΛΗΨΗΣ" 
    }, 
    { 
        "id": 81, 
        "code": "WTODaily", 
        "description": "Οργάνωση Χρόνου Εργασίας - Μεταβαλλόμενο/Τροποποιούμενο ανά Ημέρα" 
    }, 
    { 
        "id": 80, 
        "code": "WTOWeek", 
        "description": "Οργάνωση Χρόνου Εργασίας - Σταθερό Εβδομαδιαίο" 
    } 
] 
 
  

<!-- page 11 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 11 / 47 
 
           
2.1.5. Documents 
 
Route: Documents/(κωδικός ενεργής υποβολής), Method: Get 
 
To Documents είναι υπεύθυνο για την ανάκτηση του σχήματος σε JSON format μιας ενεργής 
υποβολής. 
 
Παράδειγμα Request του Documents 
 
Κωδικός ενεργής υποβολής . Επιστρέφεται από το Lookup/Submissions API και αναφέρεται στο 
πεδίο code. 
 
Παράδειγμα Response του Documents για «WRKCardSE» 
 
Body 
{ 
    "Cards": { 
        "Card": [ 
            { 
                "f_afm_ergodoti": "f_afm_ergodoti", 
                "f_aa": "f_aa", 
                "f_comments": "f_comments", 
                "Details": { 
                    "CardDetails": [ 
                        { 
                            "f_afm": "f_afm", 
                            "f_eponymo": "f_eponymo", 
                            "f_onoma": "f_onoma", 
                            "f_type": "0", 
                            "f_reference_date": "2022-05-13", 
                            "f_date": "2022-05-13T09:21:37.4578278+03:00", 
                            "f_aitiologia": "f_aitiologia" 
                        }, 
                        { 
                            "f_afm": "f_afm", 
                            "f_eponymo": "f_eponymo", 
                            "f_onoma": "f_onoma", 
                            "f_type": "0", 
                            "f_reference_date": "2022-05-13", 
                            "f_date": "2022-05-13T09:21:37.4578278+03:00", 
                            "f_aitiologia": "f_aitiologia" 
                        } 

<!-- page 12 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 12 / 47 
 
           
                    ] 
                } 
            }, 
            { 
                "f_afm_ergodoti": "f_afm_ergodoti", 
                "f_aa": "f_aa", 
                "f_comments": "f_comments", 
                "Details": { 
                    "CardDetails": [ 
                        { 
                            "f_afm": "f_afm", 
                            "f_eponymo": "f_eponymo", 
                            "f_onoma": "f_onoma", 
                            "f_type": "0", 
                            "f_reference_date": "2022-05-13", 
                            "f_date": "2022-05-13T09:21:37.4578278+03:00", 
                            "f_aitiologia": "f_aitiologia" 
                        }, 
                        { 
                            "f_afm": "f_afm", 
                            "f_eponymo": "f_eponymo", 
                            "f_onoma": "f_onoma", 
                            "f_type": "0", 
                            "f_reference_date": "2022-05-13", 
                            "f_date": "2022-05-13T09:21:37.4578278+03:00", 
                            "f_aitiologia": "f_aitiologia" 
                        } 
                    ] 
                } 
            } 
        ] 
    } 
} 
 
Το αντίστοιχο JSON για κάθε ενεργή υποβολή επιστρέφεται από το API Documents/(κωδικός 
ενεργής υποβολής) με μέθοδο Get. 
 
  

<!-- page 13 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 13 / 47 
 
           
2.1.6. Documents (Νέα δήλωση) 
 
Route: Documents/(κωδικός ενεργής υποβολής), Method: Post 
 
To Documents είναι υπεύθυνο για την καταχώρηση μιας νέας υποβολής. 
 
Παράδειγμα Request του Documents 
 
Κωδικός ενεργής υποβολής . Επιστρέφεται από το Lookup/Submissions API και αναφέρεται στο 
πεδίο code. 
 
Body 
{ 
    "Cards": { 
        "Card": [ 
            { 
                "f_afm_ergodoti": "012345678", 
                "f_aa": "0", 
                "f_comments": "test from REST API", 
                "Details": { 
                    "CardDetails": [ 
                        { 
                            "f_afm": "012345678","f_eponymo": "ΚΑΠΟΙΟΣ","f_onoma": "ΛΑΜΠΡΟΣ","f_type": "0"
,"f_reference_date": "2022-05-04","f_date": "2022-05-
04T01:10:00.7099109+03:00","f_aitiologia": null 
                        }                     
                    ] 
                } 
            } 
        ] 
    } 
} 
 
Παράδειγμα Response του Documents 
 
Στην περίπτωση επιτυχημένης κλήσης, το ΑPI επιστρέφει Http Status Code 200 OK και τα στοιχεία 
της υποβολής. 
 
Body 
[ 
    { 

<!-- page 14 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 14 / 47 
 
           
        "id": "92", 
        "protocol": "ΕΥΣ92", 
        "submitDate": "04/05/2022 01:13" 
    } 
] 
 
id : Κλειδί αποθήκευσης της υποβολής 
protocol : Αριθμός πρωτοκόλλου  
submitDate  : Ημερομηνία Υποβολής 
 
Στην περίπτωση αποτυχημένης κλήσης, το ΑPI επιστρέφει Http Status Code 400 Bad Request με το 
αντίστοιχο μήνυμα λάθους. 
 
Body 
{ 
    "message": "Για το Παράρτημα: 0\\nΤο ΑΦΜ δεν αντιστοιχεί στον συνδεδεμένο εργοδότη." 
} 
 
  

<!-- page 15 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 15 / 47 
 
           
2.1.7. Cancel Document (Διαδικασία ανάκλησης υποβληθείσας δήλωσης υποβολής)  
 
Route: Documents/CancelSubmittedDocument, Method: Post 
 
To Cancel Document είναι υπεύθυνο για την ανάκληση υποβληθείσας δήλωσης υποβολής, για όσες 
διαδικασίες προβλέπεται.  
 
Προσωρινά, προβλέπεται για τις διαδικασίες  
• Οργάνωση Χρόνου Εργασίας – Άδειες και 
• Οργάνωση Χρόνου Εργασίας – Άδειες ΟΡΘΗ ΕΠΑΝΑΛΗΨΗ. 
 
Παράδειγμα Request του Documents 
 
Body 
{ 
    "TypeOfDocument": "00009", -> κωδικός ενεργής υποβολής 
    "Protocol": "TA123", -> αριθμός πρωτοκόλλου 
    "SubmittedDate": "19800410" -> Ημ/νία Υποβολής (yyyymmdd) 
} 
 
Παράδειγμα Response του Documents 
 
Στην περίπτωση επιτυχημένης κλήσης, το ΑPI επιστρέφει Http Status Code 200 OK με το παρακάτω 
μήνυμα. 
 
Body 
{ 
    "message": "Η ακύρωση ολοκληρώθηκε επιτυχώς" 
} 
 
 
  

<!-- page 16 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 16 / 47 
 
           
2.1.8. Documents (Διαδικασία διάθεσης υποβληθείσας δήλωσης υποβολής) 
 
Route: Documents/(κωδικός ενεργής υποβολής) ?protocol=(αριθμός 
πρωτοκόλλου)&submittedDate=(Ημ/νία Υποβολής yyyymmdd), Method: Get 
 
To Documents είναι υπεύθυνο για την ανάκτηση του εντύπου PDF μιας υποβληθείσας υποβολής. 
 
Το έντυπο επιστρέφεται σε μορφή Base64. 
 
Παράδειγμα Request του Documents 
 
Κωδικός ενεργής υποβολής . Επιστρέφεται από το Lookup/Submissions API και αναφέρεται στο 
πεδίο code. 
Αριθμός Πρωτοκόλλου. O αριθμός πρωτοκόλλου που επιστρέφεται κατά την επιτυχημένη υποβολή 
μιας νέας υποβολής. 
Ημ/νία Υποβολής. Η ημ/νία υποβολής που επιστρέφεται κατά την επιτυχημένη υποβολή μιας νέας 
υποβολής. Η μορφή της ημερομηνίας είναι yyyymmdd. 
 
  

<!-- page 17 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 17 / 47 
 
           
2.1.9. ServicesList 
 
Route: WebServices/ServicesList, Method: Get 
 
To ServicesList επιστρέφει όλα τα διαθέσιμα services των εργοδοτών, με τις παραμέτρους τους. 
 
Δεν απαιτείται καμία παράμετρος στο Request. 
 
Παράδειγμα Request του ServicesList 
 
Body 
[ 
  { 
    "name": "EX_BASE_01", 
    "description": "ΣΤΟΙΧΕΙΑ ΕΡΓΟΔΟΤΗ", 
    "parameters": [] 
  }, 
  { 
    "name": "EX_BASE_02", 
    "description": "ΣΤΟΙΧΕΙΑ ΠΑΡΑΡΤΗΜΑΤΩΝ", 
    "parameters": [] 
  } 
] 
 
name :To όνομα του Service. 
Description : Προαιρετική περιγραφή του Service. 
 
Στην περίπτωση που υπάρχουν παράμετροι σε κάποιο service επιστρέφονται σαν Array στο πεδίο 
parameters. 
 
[ 
  { 
    "name": "EX_BASE_03", 
    "description": "ΣΤΟΙΧΕΙΑ TOY SERVICE EX_BASE_03", 
    "parameters": [ 
      { 
        "name": "Param1", 
        "description": "Περιγραφή παραμέτρου 1", 
        "isRequired": true, 
        "type": "Int", 
        "maxLength": 0 

<!-- page 18 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 18 / 47 
 
           
      }, 
      { 
        "name": "Param2", 
        "description": "Περιγραφή παραμέτρου 2", 
        "isRequired": false, 
        "type": "Int", 
        "maxLength": 0 
      } 
    ] 
  }  
] 
 
name : Όνομα παραμέτρου. 
description : Προαιρετική περιγραφή παραμέτρου. 
isRequired : True αν η παράμετρος είναι υποχρεωτική. 
type : Τύπος παραμέτρου.  
Οι τιμές είναι 
• Text = 1, 
• Date = 2, 
• Int = 3, 
• Decimal = 4, 
• ListString = 5, 
• ListInt = 6, 
• ListStringDate = 7, 
• XML = 8, 
• MIME = 9. 
maxLength : Μέγιστο μήκος τιμής παραμέτρου αν ο τύπος είναι Text. 
 
  

<!-- page 19 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 19 / 47 
 
           
2.1.10. ExecuteService 
 
Route: WebServices/ExecuteService, Method: Post 
 
To ExecuteService API είναι υπεύθυνο για την εκτέλεση ενός service. 
 
Παράδειγμα Request του ExecuteServices 
 
Header  
Content-Type: application/json 
 
Body 
{ 
    "ServiceCode": "SERVICE1",  
    "Parameters": [ 
        { 
            "ParameterName": "Afm", 
            "ParameterValue": "000000000" 
        } 
    ] 
} 
 
Απαιτείται το όνομα του Service και η συμπλήρωση των υποχρεωτικών παραμέτρων, ενώ στην 
περίπτωση που δεν υπάρχουν παράμετροι το πεδίο Parameters συμπληρώνεται με άδειο Array []. 
 
Παράδειγμα Response του ExecuteServices 
 
Στην περίπτωση της επιτυχημένης κλήσης το ΑPI επιστρέφει Http Status Code 200 OK και τα 
αντίστοιχα δεδομένα του service. 
 
 


<!-- page 20 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 20 / 47 
 
           
 
Body 
{ 
    "EX_BASE_01": { 
        "Ergodotis": { 
            "Id": "16858", 
            "Afm": "000000036", 
            "Eponimia": "ΔΟΚΙΜΑΣΤΙΚΗ ΕΤΑΙΡΙΑ 1", 
            "DiakritikosTitlos": "ΔΟΚ 1", 
            "Ame": "1000000003", 
            "IsInCardSector": "0" 
        } 
    } 
} 
 
Στην περίπτωση αποτυχημένης κλήσης, το ΑPI επιστρέφει Http Status Code 400 Bad Request με το 
αντίστοιχο μήνυμα λάθους. 
 
Body 
{ 
    "message": "Service Code is not authenticated to specific User" 
} 
 
Παράδειγμα: Μηνιαία Εργασιακή Κατάσταση 
Στο API του ΠΣ ΕΡΓΑΝΗ, προστέθηκε το παρακάτω Service: 
• EX_BASE_04 ΣΤΟΙΧΕΙΑ ΜΗΝΙΑΙΑΣ ΚΑΤΑΣΤΑΣΗΣ 
στην Κατηγορία ΕΡΓΟΔΟΤΩΝ που έχει οριστεί προς εξαγωγή στους εργοδότες. 
Όπως φαίνεται και στην εικόνα παρακάτω, ο χρήστης εισάγει το Έτος και το Μήνα που επιθυμεί να 
λάβει τα στοιχεία για τη μηνιαία κατάσταση απασχόλησης. 
 

<!-- page 21 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 21 / 47 
 
           
 
 
Τα στοιχεία παρουσιάζονται, με τη μορφή που φαίνεται στη παρακάτω εικόνα, σε ένα σύνολο 
δεδομένων σε μορφή JSON για τη συγκεκριμένη χρονική περίοδο.  
 
 
Τρόπος Χρήσης: 
Route: WebServices/ExecuteService, Method: Post  
 
To ExecuteService API είναι υπεύθυνο για την εκτέλεση ενός service. 
 
Παράδειγμα Request του ExecuteServices. 
 
Header 
Content-Type: application/json  
 
Body 
{ 
    "ServiceCode": "EX_BASE_04",  
    "Parameters": [ 


<!-- page 22 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 22 / 47 
 
           
        { 
            "ParameterName": "ReportYear", 
            "ParameterValue": "2024" 
        }, 
        { 
            "ParameterName": "ReportMonth", 
            "ParameterValue": "10" 
        } 
    ] 
} 
 
 
Απαιτείται το όνομα του Service και η συμπλήρωση των υποχρεωτικών παραμέτρων 
• ReportYear,  
• ReportMonth. 
Παράδειγμα Response του ExecuteServices. Στην περίπτωση της επιτυχημένης κλήσης το ΑPI 
επιστρέφει Http Status Code 200 OK και τα αντίστοιχα δεδομένα του service. 
Body 
{ 
    "EX_BASE_04": { 
        "MiniaiaKatastash": { 
            "f_ergodoti_id": "29281", 
            "f_pararthma_aa": "0", 
            "f_year": "2024", 
            "f_month": "10", 
            "f_ergazomenos_type": "Εξαρτημένη", 
            "f_afm": "000000000", 
            "f_eponimo": "ΚΑΠΟΙΟΣ", 
            "f_onoma": "ΝΙΚΟΛΑΟΣ", 
            "f_onoma_patera": "ΓΕΩΡΓΙΟΣ", 
            "f_onoma_miteras": "ΓΕΩΡΓΙΑ", 
            "f_date_birth": "1978-05-12T00:00:00+03:00", 
            "f_sex": "Άντρας", 
            "f_nationality": "048-ΕΛΛΑΔΑ", 
            "f_marital_status": "Άγαμος/η", 
            "f_ar_teknwn": "0", 
            "f_amka": "01010101010", 
            "f_education_level": "11-ΑΕΙ", 
            "f_xarakthsismos": "Υπάλληλος", 
            "f_sxesh_apasxolhshs": "Αορίστου Χρόνου", 
            "f_kathestos": "Πλήρης", 
            "f_step": "222-ΠΟΛΙΤΙΚΟΙ ΜΗΧΑΝΙΚΟΙ", 
            "f_apodoxes": "5500.00", 
            "f_week_wres": "40.0", 

<!-- page 23 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 23 / 47 
 
           
            "f_hour_apodoxes": "10.00", 
            "f_programma": "-", 
            "f_responsible": "ΠΕΡΙΠΤΩΣΗ Α", 
            "f_date_proslipsis": "2024-09-01T00:00:00+03:00", 
            "f_arithmos_hmerwn_ergasias": "23", 
            "f_arithmos_hmerwn_tilergasias": "0", 
            "f_arithmos_hmerwn_anapaushs_repo": "8", 
            "f_arithmos_hmerwn_mh_ergasias": "0", 
            "f_arithmos_hmerwn_kanonikh_adeia": "0", 
            "f_arithmos_hmerwn_aimodotikh_adeia": "0", 
            "f_arithmos_hmerwn_adeia_exetasewn": "0", 
            "f_arithmos_hmerwn_adeia_axoris_apodw": "0", 
            "f_arithmos_hmerwn_adeia_mhrotitas": "0", 
            "f_arithmos_hmerwn_eidikh_paroxh_prostasias_ths_mhrotitas": "0", 
            "f_arithmos_hmerwn_adeia_patrotitas": "0", 
            "f_arithmos_hmerwn_adeia_frontidas_paidiou": "0", 
            "f_arithmos_hmerwn_gonikh_adeia": "0", 
            "f_arithmos_hmerwn_adeia_frontisti": "0", 
            "f_arithmos_hmerwn_ergasias_logo_anoteras_vias": "0", 
            "f_arithmos_hmerwn_adeia_methodous_iatrikws_ipovothoumenhs_anaparagoghs": "0", 
            "f_arithmos_hmerwn_adeia_exetasewn_progennitikou_elegxou": "0", 
            "f_arithmos_hmerwn_adeia_gamou": "0", 
            "f_arithmos_hmerwn_adeia_logw_sovarwn_noshmatwn_twn_paidion": "0", 
            "f_arithmos_hmerwn_adeia_logw_noshlias_paidion": "0", 
            "f_arithmos_hmerwn_adeia_monogoneikon_oikogeneion": "0", 
            "f_arithmos_hmerwn_adeia_parakolouthishs_sxolikhs_epidosis_paidiou": "0", 
            "f_arithmos_hmerwn_adeia_astheneias_paidiou_h_allou_exartoumenou_melous": "0", 
            "f_arithmos_hmerwn_apousia_apo_ergasia_logo_vias_parenoxlisis": "0", 
            "f_arithmos_hmerwn_astheneias_anipaitiokolima_paroxhs_ergasias": "0", 
            "f_arithmos_hmerwn_adeia_amea": "0", 
            "f_arithmos_hmerwn_adeia_thanatos_syggeneous": "0", 
            "f_arithmos_hmerwn_adeia_anhlikwn_spoudastwn": "0", 
            "f_arithmos_hmerwn_metaggiseis_aimatos_h_aimokatharsi": "0", 
            "f_arithmos_hmerwn_ekpaideytikh_adeia_foithtes_KANEP_GSEE": "0", 
            "f_arithmos_hmerwn_aids": "0", 
            "f_arithmos_hmerwn_eveliktes_rythmiseis_ergasias": "0", 
            "f_arithmos_hmerwn_frontida_paidiou_lepta": "0", 
            "f_arithmos_hmerwn_gonikh_adeia_lepta": "0", 
            "f_arithmos_hmerwn_anoteras_vias_lepta": "0", 
            "f_arithmos_hmerwn_eveliktes_rythmiseis_ergasias_lepta": "0", 
            "f_arithmos_hmerwn_exetaseis_progennitikou_lepta": "0", 
            "f_arithmos_hmerwn_parakolouthish_paidiou_lepta": "0", 
            "f_arithmos_hmerwn_alli_adeia": "0", 
            "f_arithmos_hmerwn_alli_adeia_lepta": "0", 
            "f_arithmos_leptwn": "0", 
            "f_lepta_yperorias": "0", 
            "f_arithmos_hmerwn_yperorias": "0", 
            "f_arithmos_hmerwn_karta_ergasias": "0", 

<!-- page 24 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 24 / 47 
 
           
            "f_arithmos_kyriakwn_psoxe": "0", 
            "f_arithmos_kyriakwn_karta": "0", 
            "f_synolo_hmerwn_adeias_asfalish": "0", 
            "f_synolo_hmerwn_astheneias_asfalish": "0" 
        } 
    } 
} 
  

<!-- page 25 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 25 / 47 
 
           
2.2. Παραδείγματα 
 
2.2.1. Υποβολή δήλωσης Κάρτας Εργασίας 
 
Route: Documents/ WRKCardSE, Method: Post 
 
Υποβάλλει δήλωση προσέλευσης ή αποχώρησης εργαζόμενου στην εργασία του 
 
Παράδειγμα Request του Documents 
 
Body 
{ 
    "Cards": { 
        "Card": [ 
            { 
                "f_afm_ergodoti": "094187530", 
                "f_aa": "0", 
                "f_comments": "test from REST API", 
                "Details": { 
                    "CardDetails": [ 
                        { 
                            "f_afm": "028233026","f_eponymo": "ΚΑΠΟΙΟΣ","f_onoma": "ΛΑΜΠΡΟΣ","f_type": "0"
,"f_reference_date": "2022-05-04","f_date": "2022-05-
04T01:10:00.7099109+03:00","f_aitiologia": null 
                        }                     
                    ] 
                } 
            } 
        ] 
    } 
} 
 
Το Array Card αναφέρετε σε λίστα εργοδοτών και περιλαμβάνει τα εξής στοιχεία. 
 
f_afm_ergodoti  : Α.Φ.Μ Εργοδότη (Για επαλήθευση) 
f_aa  : Α/Α Παραρτήματος 
f_comments : Σχόλια  
 
Το Array CardDetails αναφέρετε σε λίστα εργαζομένω και περιλαμβάνει τα εξής στοιχεία. 
 
f_afm : Α.Φ.Μ εργαζόμενου 

<!-- page 26 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 26 / 47 
 
           
f_eponymo : Επώνυμο εργαζόμενου 
f_onoma : Όνομα εργαζόμενου 
f_type : 0 Προσέλευση, 1 Αποχώρηση 
f_reference_date : Ημερομηνία αναφοράς  
f_date : Ημερομηνία Κίνησης 
f_aitiologia : Κωδικός αιτιολογίας που συμπληρώνεται στην περίπτωση εκπρόθεσμης υποβολής 
 
Παράδειγμα Response του Documents 
 
Στην περίπτωση της επιτυχημένης κλήσης το ΑPI επιστρέφει Http Status Code 200 OK και τα 
στοιχεία της υποβολής. 
 
Body 
[ 
    { 
        "id": "92", 
        "protocol": "ΕΥΣ92", 
        "submitDate": "04/05/2022 01:13" 
    } 
] 
 
id : Κλειδί αποθήκευσης της υποβολής 
protocol : Αριθμός πρωτοκόλλου  
submitDate : Ημερομηνία Υποβολής 
 
Στην περίπτωση της μη επιτυχημένης κλήσης το ΑPI επιστρέφει Http Status Code 400 Bad Request 
με το αντίστοιχο μήνυμα λάθους. 
 
Body 
{ 
    "message": "Για το Παράρτημα: 0\\nΤο ΑΦΜ δεν αντιστοιχεί στον συνδεδεμένο εργοδότη." 
} 
 
 
 
 

<!-- page 27 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 27 / 47 
 
           
3. Συνοδευτικά αρχεία 
 
Τα αντίστοιχα XSD και τα συνοδευτικά αρχεία διατίθενται στις ανακοινώσεις  - ενημερώσεις 
εμφανίζονται στον αντίστοιχο σύνδεσμο πάνω δεξιά σελίδα του Δοκιμαστικού  περιβάλλοντος . 
 
Οι σχετικές Κωδικοποιήσεις όπου απαιτούνται δίνονται από τις  αντίστοιχες σελίδα του 
Συστήματος ή μέσω του API.  
 
Σε κάθε ενημέρωση θα ακολουθεί σχετική ανακοίνωση.  
 
Σχετικά με τα συνοδευτικά αρχεία (XML, XSD, JSON, EXCEL files).  
 
 
  


<!-- page 28 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 28 / 47 
 
           
4. Λίστα τύπων Οργάνωσης Χρόνου Εργασίας 
 
Για το Ωράριο Απασχόλησης – Σταθερό Εβδομαδιαίο Ωράριο και το 
Τροποποιούμενο/Μεταβαλλόμενο Ανά Ημέρα , οι επιλογές που υποστηρίζονται για την 
Ανάλυση Απασχόλησης Ημέρας είναι οι παρακάτω:  
• Εργασία (ώρα από / έως)  
• Τηλεργασία (ώρα από / έως)  
• Ανάπαυση / Ρεπό  
• Μη Εργασία (χρησιμοποιείται στην περίπτωση μερικής απασχόλησης ή εκ 
περιτροπής)  
 
Κωδικός Περιγραφή Κατηγορία Υποκατηγορία 
ΜΕ ΜΗ ΕΡΓΑΣΙΑ Εργασίας Χωρίς Εργασία - Ανάπαυση - 
Ρεπό 
ΑΝ ΑΝΑΠΑΥΣΗ/ΡΕΠΟ Εργασίας Χωρίς Εργασία - Ανάπαυση - 
Ρεπό 
ΤΗΛ ΤΗΛΕΡΓΑΣΙΑ Εργασίας Εργασία 
ΕΡΓ ΕΡΓΑΣΙΑ Εργασίας Εργασία 
 
Για τις Υπερωρίες , οι επιλογές που υποστηρίζονται για την Ανάλυση Απασχόλησης Ημέρας 
είναι οι παρακάτω:  
 
Κωδικός Περιγραφή Κατηγορία Υποκατηγορία 
ΥΠ ΥΠΕΡΩΡΙΑ Εργασίας Εργασία 
ΧΥΠ* ΧΩΡΙΣ ΥΠΕΡΩΡΙΑ  Εργασίας Χωρίς Εργασία - Ανάπαυση - 
Ρεπό 
*Για χρήση κατ’ αντιστοιχία ακύρωσης υπερωρίας στην δήλωση Ε8. 
 
Για τις Άδειες και την δήλωση Οργάνωσης Χρόνου Εργασίας με στοιχεία για τον 
προγραμματισμό Αδειών ανά Ημέρα για καθορισμένο χρονικό διάστημα , οι επιλογές που 
υποστηρίζονται για την Ανάλυση είναι οι παρακάτω:  
 
Κωδικός Περιγραφή Κατηγορία Υποκατηγορία 
ΑΔΚΑΝ Κανονική άδεια Αδειών Άδεια 
ΑΔΑΙΜ Αιμοδοτική άδεια Αδειών Άδεια 
ΑΔΕΞ Άδεια εξετάσεων Αδειών Άδεια 
ΑΔΑΑ Άδεια άνευ αποδοχών Αδειών Άδεια 
ΑΔΜΗ Άδεια μητρότητας Αδειών Άδεια 
ΑΔΠΠΜ Ειδική παροχή προστασίας της μητρότητας Αδειών Άδεια 
ΑΔΠΑ Άδεια πατρότητας Αδειών Άδεια 
ΑΔΦΠ Άδεια φροντίδας παιδιού Αδειών Άδεια 

<!-- page 29 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 29 / 47 
 
           
ΑΔΓΟΝ Γονική άδεια Αδειών Άδεια 
ΑΔΦΡΟ Άδεια φροντιστή Αδειών Άδεια 
 
Κωδικός Περιγραφή Κατηγορία Υποκατηγορία 
ΑΔΑΠΑΒ Απουσία από την εργασία για λόγους ανωτέρας 
βίας 
Αδειών Άδεια 
ΑΔΙΥΑ Άδεια για υποβολή σε μεθόδους ιατρικώς 
υποβοηθούμενης αναπαραγωγής 
Αδειών Άδεια 
ΑΔΠΕ Άδεια εξετάσεων προγεννητικού ελέγχου Αδειών Άδεια 
ΑΔΓΑΜ Άδεια γάμου Αδειών Άδεια 
ΑΔΣΝΠ Άδεια λόγω σοβαρών νοσημάτων των παιδιών Αδειών Άδεια 
ΑΔΝΠ Άδεια λόγω νοσηλείας των παιδιών Αδειών Άδεια 
ΑΔΜΟ Άδεια μονογονεϊκών οικογενειών Αδειών Άδεια 
ΑΔΠΣΕΤ Άδεια παρακολούθησης σχολικής επίδοσης 
τέκνου 
Αδειών Άδεια 
ΑΔΑΠΕΜ Άδεια λόγω ασθένειας παιδιού ή άλλου 
εξαρτώμενου μέλους 
Αδειών Άδεια 
ΑΔΑΠΣΚ Απουσία από την εργασία λόγω επικείμενου 
σοβαρού κινδύνου βίας ή παρενόχλησης 
Αδειών Άδεια 
ΑΔΑΣ Άδεια ασθένειας (ανυπαίτιο κώλυμα παροχής 
εργασίας) 
Αδειών Άδεια 
ΑΔΑΜΕΑ Άδεια απουσίας Α.Μ.Ε.Α. Αδειών Άδεια 
ΑΔΘΣΥΓ Άδεια λόγω θανάτου συγγενούς  Αδειών Άδεια 
ΑΔΑΝΣΠ Άδεια ανήλικων σπουδαστών Αδειών Άδεια 
ΑΔΜΑΑ Άδεια για μεταγγίσεις αίματος και των 
παραγώγων του ή αιμοκάθαρση 
Αδειών Άδεια 
ΑΔΕΚΦ Εκπαιδευτική άδεια για φοιτητές στο Κ.ΑΝ.Ε.Π. 
- Γ.Σ.Ε.Ε. 
Αδειών Άδεια 
ΑΔΣΕΑΑ Άδεια λόγω AIDS Αδειών Άδεια 
ΑΔΕΡΕ Ευέλικτες ρυθμίσεις εργασίας Αδειών Άδεια 
ΩΑΦΠ Άδεια φροντίδας παιδιού (ΩΡΕΣ) Αδειών Ωροάδεια 
ΩΑΓΟΝ Γονική άδεια (ΩΡΕΣ) Αδειών Ωροάδεια 
ΩΑΑΠΑΒ Απουσία από την εργασία για λόγους ανωτέρας 
βίας (ΩΡΕΣ) 
Αδειών Ωροάδεια 
ΩΑΕΡΕ Ευέλικτες ρυθμίσεις εργασίας (ΩΡΕΣ) Αδειών Ωροάδεια 
ΩΑΠΕ Άδεια εξετάσεων προγεννητικού ελέγχου 
(ΩΡΕΣ) 
Αδειών Ωροάδεια 
ΩΑΠΣΕΤ Άδεια παρακολούθησης σχολικής επίδοσης 
τέκνου (ΩΡΕΣ) 
Αδειών Ωροάδεια 
ΑΔΑΛ Άδεια Άλλη Αδειών Άδεια 
ΩΑΑΛ Άδεια Άλλη (ΩΡΕΣ) Αδειών Ωροάδεια 

<!-- page 30 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 30 / 47 
 
           
 

<!-- page 31 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 31 / 47 
 
           
 
Παράρτημα Ι: Ύπαρξη XML – Έκδοση XSD 
 
Τα αντίστοιχα XSD και τα συνοδευτικά αρχεία διατίθενται στην αρχική σελίδα του 
δοκιμαστικού περιβάλλοντος . 
 
Οι σχετικές Κωδικοποιήσεις όπου απαιτούνται δίνονται από την αντίστοιχ η σελίδα του 
Συστήματος ή μέσω του API.  
 
Σε κάθε ενημέρωση θα ακολουθεί σχετική ανακοίνωση.  
 
Ακολουθεί πίνακας με τα συνοδευτικά αρχεία (XML, XSD, JSON, EXCEL files) : 
Διαδικασία  ενέργεια  Τύπος 
αρχείου  Filename  Document Code  
Οργάνωση Χρόνου 
Εργασίας – Σταθερό 
Εβδομαδιαίο  
Εισαγωγή με 
αρχείο 
Microsoft 
Excel 
Spreadshee
t 
XLSX 
example  
EXCEL_PROTOTYPE_WEEKL
Y.xlsx  
 
Οργάνωση Χρόνου 
Εργασίας – Σταθερό 
Εβδομαδιαίο  
Εισαγωγή 
από αρχείο 
XML  
XSD  WTO_Weekly_v1.xsd  
 
Οργάνωση Χρόνου 
Εργασίας – Σταθερό 
Εβδομαδιαίο  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  WTOWEEKLY.xml  
 
Οργάνωση Χρόνου 
Εργασίας – Σταθερό 
Εβδομαδιαίο  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  WTOWEEKLY.json  
WTOWeek  
Οργάνωση Χρόνου 
Εργασίας – 
Μεταβαλλόμενο  
Εισαγωγή με 
αρχείο 
Microsoft 
Excel 
Spreadshee
t 
XLSX 
example  
EXCEL_PROTOTYPE_DAILY_
PROGRAM.xlsx  
 
Οργάνωση Χρόνου 
Εργασίας – 
Μεταβαλλόμενο  
Εισαγωγή 
από αρχείο 
XML  
XSD  WTO_v1.xsd   
Οργάνωση Χρόνου 
Εργασίας – 
Μεταβαλλόμενο  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  WTODAILY.xml   
Οργάνωση Χρόνου 
Εργασίας – 
Μεταβαλλόμενο  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  WTODaily.json  WTODaily  

<!-- page 32 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 32 / 47 
 
           
Οργάνωση Χρόνου 
Εργασίας – Άδειες  
Εισαγωγή με 
αρχείο 
Microsoft 
Excel 
Spreadshee
t 
XLSX 
example  
EXCEL_PROTOTYPE_DAILY_
LEAVES.xlsx  
 
Οργάνωση Χρόνου 
Εργασίας – Άδειες  
Εισαγωγή 
από αρχείο 
XML  
XSD  WTO_v2.xsd  
 
Οργάνωση Χρόνου 
Εργασίας – Άδειες  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  
wtoHoliday_v2.xml, 
wtoHolidayCor_v2.xml  
 
Οργάνωση Χρόνου 
Εργασίας – Άδειες  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  
wtoHoliday_v2.json, 
wtoHolidayCor_v2.json  
 
Κάρτα Εργασίας – 
Δήλωση έναρξης / 
λήξης  
Εισαγωγή 
από αρχείο 
XML  
XSD  Card_v1.xsd   
Κάρτα Εργασίας – 
Δήλωση έναρξης / 
λήξης  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  
WorkCard_2submissions.xm
l,  
Card.xml  
 
Κάρτα Εργασίας – 
Δήλωση έναρξης / 
λήξης  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  
Card.json,  
WorkCard_2submissions.jso
n 
WRKCardSE  
Δήλωση Εξαίρεσης 
από την 
Υποχρέωση 
Προαναγγελίας  
Εισαγωγή 
από αρχείο 
XML  
XSD  ExProan.xsd   
Δήλωση Εξαίρεσης 
από την 
Υποχρέωση 
Προαναγγελίας  
Χρήση Web 
API – 
αποστολή  
  ExProan  
Δήλωση 
Απασχόλησης την 
Έκτακτης Βάρδιας  
Εισαγωγή 
από αρχείο 
XML  
XSD  SixthDay_v1.xsd   
Δήλωση 
Απασχόλησης την 
Έκτακτης Βάρδιας  
Χρήση Web 
API    SixthDay  
Οργάνωση Χρόνου 
Εργασίας – 
Μεταβαλλόμενο 
Απολογιστικό  
Εισαγωγή με 
αρχείο 
Microsoft 
Excel 
Spreadsheet  
XLSX 
example  
EXCEL_PROTOTYPE_DAILY_
PROGRAM.xlsx  
 
Οργάνωση Χρόνου 
Εργασίας – 
Μεταβαλλόμενο 
Απολογιστικό  
Εισαγωγή 
από αρχείο 
XML  
XSD  WTO_v1.xsd   

<!-- page 33 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 33 / 47 
 
           
Οργάνωση Χρόνου 
Εργασίας – 
Μεταβαλλόμενο 
Απολογιστικό  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  WTODAILY.xml   
Οργάνωση Χρόνου 
Εργασίας – 
Μεταβαλλόμενο 
Απολογιστικό  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  WTODaily.json  WTODailyA  
Οργάνωση Χρόνου 
Εργασίας – 
Μεταβαλλόμενο 
Οδηγοί  
Εισαγωγή με 
αρχείο 
Microsoft 
Excel 
Spreadsheet  
XLSX 
example  
EXCEL_PROTOTYPE_DAILY_
PROGRAM.xlsx  
 
Οργάνωση Χρόνου 
Εργασίας – 
Μεταβαλλόμενο 
Οδηγοί  
Εισαγωγή 
από αρχείο 
XML  
XSD  WTO_v1.xsd   
Οργάνωση Χρόνου 
Εργασίας – 
Μεταβαλλόμενο 
Οδηγοί  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  WTODAILY.xml   
Οργάνωση Χρόνου 
Εργασίας – 
Μεταβαλλόμενο 
Οδηγοί  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  WTODaily.json  WTODailyD  
Οργάνωση Χρόνου 
Εργασίας - 
Υπερωρίες  
Εισαγωγή με 
αρχείο 
Microsoft 
Excel 
Spreadsheet  
XLSX 
example  
EXCEL_PROTOTYPE_DAILY_
PROGRAM.xlsx   
Οργάνωση Χρόνου 
Εργασίας - 
Υπερωρίες  
Εισαγωγή 
από αρχείο 
XML  
XSD  WTO_v1.xsd   
Οργάνωση Χρόνου 
Εργασίας - 
Υπερωρίες  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  WTOOv.xml   
Οργάνωση Χρόνου 
Εργασίας - 
Υπερωρίες  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  WTOOv.json  WTOOv  
Οργάνωση Χρόνου 
Εργασίας – 
Υπερωρίες 
Απολογιστικό  
Εισαγωγή με 
αρχείο 
Microsoft 
Excel 
Spreadsheet  
XLSX 
example  
EXCEL_PROTOTYPE_DAILY_
PROGRAM.xlsx   
Οργάνωση Χρόνου 
Εργασίας – 
Υπερωρίες 
Απολογιστικό  
Εισαγωγή 
από αρχείο 
XML  
XSD  WTO_v1.xsd   

<!-- page 34 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 34 / 47 
 
           
Οργάνωση Χρόνου 
Εργασίας – 
Υπερωρίες 
Απολογιστικό  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  WTOOv.xml   
Οργάνωση Χρόνου 
Εργασίας – 
Υπερωρίες 
Απολογιστικό  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  WTOOv.json  WTOOvA  
Οργάνωση Χρόνου 
Εργασίας – 
Υπερωρίες Οδηγοί  
Εισαγωγή με 
αρχείο 
Microsoft 
Excel 
Spreadsheet  
XLSX 
example  
EXCEL_PROTOTYPE_DAILY_
PROGRAM.xlsx   
Οργάνωση Χρόνου 
Εργασίας – 
Υπερωρίες Οδηγοί  
Εισαγωγή 
από αρχείο 
XML  
XSD  WTO_v1.xsd   
Οργάνωση Χρόνου 
Εργασίας – 
Υπερωρίες Οδηγοί  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  WTOOv.xml   
Οργάνωση Χρόνου 
Εργασίας – 
Υπερωρίες Οδηγοί  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  WTOOv.json  WTOOvD  
Έναρξη 
Απασχόλησης - 
Πρόσληψη  
Εισαγωγή 
από αρχείο 
XML  
XSD  E3N_v1.xsd   
Έναρξη 
Απασχόλησης - 
Πρόσληψη  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testProslipsiNew.xml   
Έναρξη 
Απασχόλησης - 
Πρόσληψη  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  testProslipsiNew.json  WebE3N  
Έναρξη 
Απασχόλησης – 
Μεταβίβαση από 
Επιχείρηση  
Εισαγωγή 
από αρχείο 
XML  
XSD  E3M_v1.xsd   
Έναρξη 
Απασχόλησης – 
Μεταβίβαση από 
Επιχείρηση  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testMetabibashFrom.xml   
Έναρξη 
Απασχόλησης – 
Μεταβίβαση από 
Επιχείρηση  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  testMetabibashFrom.json  WebE3M  
Έναρξη 
Απασχόλησης – 
Δανεισμός από 
Επιχείρηση  
Εισαγωγή 
από αρχείο 
XML  
XSD  E3D_v1.xsd   

<!-- page 35 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 35 / 47 
 
           
Έναρξη 
Απασχόλησης – 
Δανεισμός από 
Επιχείρηση  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testDaneismosFrom.xml   
Έναρξη 
Απασχόλησης – 
Δανεισμός από 
Επιχείρηση  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  testDaneismosFrom.json  WebE3D  
Έναρξη 
Απασχόλησης – 
Πρόσληψη για 
Δανεισμό  
Εισαγωγή 
από αρχείο 
XML  
XSD  E3PD_v1.xsd   
Έναρξη 
Απασχόλησης – 
Πρόσληψη για 
Δανεισμό  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testProslipsiDaneismos.xml   
Έναρξη 
Απασχόλησης – 
Πρόσληψη για 
Δανεισμό  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  testProslipsiDaneismos.json  WebE3PD  
Λήξη Απασχόλησης 
– Οικειοθελής 
Αποχώρηση  
Εισαγωγή 
από αρχείο 
XML  
XSD  E5N_v1.xsd   
Λήξη Απασχόλησης 
– Οικειοθελής 
Αποχώρηση  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testApoxwrhshNew.xml   
Λήξη Απασχόλησης 
– Οικειοθελής 
Αποχώρηση  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  testApoxwrhshNew.json  WebE5N  
Λήξη Απασχόλησης 
– Δήλωση Όχλησης 
για δυνατότητα 
Οικειοθελούς 
Αποχώρησης  
Εισαγωγή 
από αρχείο 
XML  
XSD  E5O_v1.xsd   
Λήξη Απασχόλησης 
– Δήλωση Όχλησης 
για δυνατότητα 
Οικειοθελούς 
Αποχώρησης  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testApoxwrhshOxlhsh.xml   
Λήξη Απασχόλησης 
– Δήλωση Όχλησης 
για δυνατότητα 
Οικειοθελούς 
Αποχώρησης  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  testApoxwrhshOxlhsh.json  WebE5O  
Λήξη Απασχόλησης 
– Οικειοθελής 
Αποχώρηση μετά 
από Όχληση  
Εισαγωγή 
από αρχείο 
XML  
XSD  E5AO_v1.xsd   

<!-- page 36 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 36 / 47 
 
           
Λήξη Απασχόλησης 
– Οικειοθελής 
Αποχώρηση μετά 
από Όχληση  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  
testApoxwrhshAfterOxlhsh.x
ml   
Λήξη Απασχόλησης 
– Οικειοθελής 
Αποχώρηση μετά 
από Όχληση  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  
testApoxwrhshAfterOxlhsh.j
son  WebE5AO  
Λήξη Απασχόλησης 
– Καταγγελία 
Σύμβασης χωρίς 
Προειδοποίηση  
Εισαγωγή 
από αρχείο 
XML  
XSD  E6NXP_v1.xsd   
Λήξη Απασχόλησης 
– Καταγγελία 
Σύμβασης χωρίς 
Προειδοποίηση  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  
testKataggeliaXwrisProidopo
ihsh.xml   
Λήξη Απασχόλησης 
– Καταγγελία 
Σύμβασης χωρίς 
Προειδοποίηση  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  
testKataggeliaXwrisProidopo
ihsh.json  WebE6NXP  
Λήξη Απασχόλησης 
– Καταγγελία 
Σύμβασης με 
Προειδοποίηση  
Εισαγωγή 
από αρχείο 
XML  
XSD  E6NMP_v1.xsd   
Λήξη Απασχόλησης 
– Καταγγελία 
Σύμβασης με 
Προειδοποίηση  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  
testKataggeliaMeProidopoih
sh.xml   
Λήξη Απασχόλησης 
– Καταγγελία 
Σύμβασης με 
Προειδοποίηση  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  
testKataggeliaMeProidopoih
sh.json  WebE6NMP  
Λήξη Απασχόλησης 
– Λύση Σύμβασης 
Ορισμένου Χρόνου  
Εισαγωγή 
από αρχείο 
XML  
XSD  E7N_v1.xsd   
Λήξη Απασχόλησης 
– Λύση Σύμβασης 
Ορισμένου Χρόνου  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testLyshSymbashsNew.xml   
Λήξη Απασχόλησης 
– Λύση Σύμβασης 
Ορισμένου Χρόνου  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  testLyshSymbashsNew.json  WebE7N  
Λήξη Απασχόλησης 
– Εθελούσια 
Έξοδος  
Εισαγωγή 
από αρχείο 
XML  
XSD  E5E_v1.xsd   
Λήξη Απασχόλησης 
– Εθελούσια 
Έξοδος  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  
testApoxwrhshEthelousia.x
ml   

<!-- page 37 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 37 / 47 
 
           
Λήξη Απασχόλησης 
– Εθελούσια 
Έξοδος  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  
testApoxwrhshEthelousia.js
on  WebE5E  
Λήξη Απασχόλησης 
– Συνταξιοδότηση 
με Οικειοθελή 
Αποχώρηση  
Εισαγωγή 
από αρχείο 
XML  
XSD  E5S_v1.xsd   
Λήξη Απασχόλησης 
– Συνταξιοδότηση 
με Οικειοθελή 
Αποχώρηση  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testApoxwrhshSyntaksh.xml   
Λήξη Απασχόλησης 
– Συνταξιοδότηση 
με Οικειοθελή 
Αποχώρηση  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  
testApoxwrhshSyntaksh.jso
n WebE5S  
Λήξη Απασχόλησης 
- Οικειοθελής 
αποχώρηση 
μισθωτού λόγω 
συμπλήρωσης 
δεκαπενταετίας 
στον ίδιο εργοδότη 
ή υπέρβασης του 
ορίου ηλικίας 
συνταξιοδότησης 
με τη συγκατάθεση 
του εργοδότη  
Εισαγωγή 
από αρχείο 
XML  
XSD  E5DS_v1.xsd   
Λήξη Απασχόλησης 
– Οικειοθελής 
αποχώρηση 
μισθωτού λόγω 
συμπλήρωσης 
δεκαπενταετίας 
στον ίδιο εργοδότη 
ή υπέρβασης του 
ορίου ηλικίας 
συνταξιοδότησης 
με τη συγκατάθεση 
του εργοδότη  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  
testSyntakshDekapentaetia.
xml   
Λήξη Απασχόλησης 
– Οικειοθελής 
αποχώρηση 
μισθωτού λόγω 
συμπλήρωσης 
δεκαπενταετίας 
στον ίδιο εργοδότη 
ή υπέρβασης του 
ορίου ηλικίας 
συνταξιοδότησης 
Χρήση Web 
API – 
αποστολή 
JSON  
JSON 
example  
testSyntakshDekapentaetia.j
son  WebE5DS  

<!-- page 38 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 38 / 47 
 
           
με τη συγκατάθεση 
του εργοδότη  
Λήξη Απασχόλησης 
– Συνταξιοδότηση 
με Καταγγελία 
Σύμβασης Χωρίς 
Προειδοποίηση  
Εισαγωγή 
από αρχείο 
XML  
XSD  E6SXP_v1.xsd   
Λήξη Απασχόλησης 
– Συνταξιοδότηση 
με Καταγγελία 
Σύμβασης Χωρίς 
Προειδοποίηση  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  
testSyntakshXwrisProidopoi
hsh.xml   
Λήξη Απασχόλησης 
– Συνταξιοδότηση 
με Καταγγελία 
Σύμβασης Χωρίς 
Προειδοποίηση  
Χρήση Web 
API – 
αποστολή 
JSON  
JSON 
example  
testSyntakshXwrisProidopoi
hsh.json  WebE6SXP  
Λήξη Απασχόλησης 
– Λήξη λόγω 
Θανάτου  
Εισαγωγή 
από αρχείο 
XML  
XSD  E5D_v1.xsd   
Λήξη Απασχόλησης 
– Λήξη λόγω 
Θανάτου  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testApoxwrhshThanatos.xml   
Λήξη Απασχόλησης 
– Λήξη λόγω 
Θανάτου  
Χρήση Web 
API – 
αποστολή 
JSON  
JSON 
example  
testApoxwrhshThanatos.jso
n WebE5D  
Λήξη Απασχόλησης 
– Αυτοδίκαιη Λύση 
Δοκιμαστικής 
Περιόδου  
Εισαγωγή 
από αρχείο 
XML  
XSD  E6LT_v1.xsd   
Λήξη Απασχόλησης 
– Αυτοδίκαιη Λύση 
Δοκιμαστικής 
Περιόδου  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testLyshDokimastikhs.xml   
Λήξη Απασχόλησης 
– Αυτοδίκαιη Λύση 
Δοκιμαστικής 
Περιόδου  
Χρήση Web 
API – 
αποστολή 
JSON  
JSON 
example  testLyshDokimastikhs.json  WebE6LT  
Λήξη Απασχόλησης 
– Μεταβίβαση σε 
Επιχείρηση  
Εισαγωγή 
από αρχείο 
XML  
XSD  E6M_v1.xsd   
Λήξη Απασχόλησης 
– Μεταβίβαση σε 
Επιχείρηση  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testMetabibashTo.xml   
Λήξη Απασχόλησης 
– Μεταβίβαση σε 
Επιχείρηση  
Χρήση Web 
API – 
αποστολή 
JSON  
JSON 
example  testMetabibashTo.json  WebE6M  

<!-- page 39 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 39 / 47 
 
           
Λήξη Απασχόλησης 
– Λήξη Δανεισμού 
από Επιχείρηση  
Εισαγωγή 
από αρχείο 
XML  
XSD  E6LD_v1.xsd   
Λήξη Απασχόλησης 
– Λήξη Δανεισμού 
από Επιχείρηση  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testLhkshDaneismou.xml   
Λήξη Απασχόλησης 
– Λήξη Δανεισμού 
από Επιχείρηση  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  testLhkshDaneismou.json  WebE6LD  
Μεταβολή 
Στοιχείων 
Απασχόλησης   
Εισαγωγή 
από αρχείο 
XML  
XSD  MA_v1.xsd   
Μεταβολή 
Στοιχείων 
Απασχόλησης   
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testMA.xml   
Μεταβολή 
Στοιχείων 
Απασχόλησης   
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  testMA.json  WebMA  
Μεταβολή 
Στοιχείων 
Απασχόλησης 
Δανειζόμενου 
Προσωπικού   
Εισαγωγή 
από αρχείο 
XML  
XSD  MAD_v1.xsd   
Μεταβολή 
Στοιχείων 
Απασχόλησης 
Δανειζόμενου 
Προσωπικού   
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testMAD.xml   
Μεταβολή 
Στοιχείων 
Απασχόλησης 
Δανειζόμενου 
Προσωπικού   
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  testMAD.json  WebMAD  
Αναγγελ ία 
Απασχολο ύμενου 
Προσωπικο ύ σε 
Οικοδομοτεχνικ ά 
Έργα  
Εισαγωγή 
από αρχείο 
XML  
XSD  E12_v1.xsd   
Αναγγελ ία 
Απασχολο ύμενου 
Προσωπικο ύ σε 
Οικοδομοτεχνικ ά 
Έργα  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  test E12 .xml   
Αναγγελ ία 
Απασχολο ύμενου 
Προσωπικο ύ σε 
Οικοδομοτεχνικ ά 
Έργα  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  test E12 .json  WebE12  

<!-- page 40 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 40 / 47 
 
           
Απογραφική 
Αναγγελ ία 
Απασχολο ύμενου 
Προσωπικο ύ σε 
Οικοδομοτεχνικ ά 
Έργα  
Εισαγωγή 
από αρχείο 
XML  
XSD  E12Apografiko_v1.xsd   
Απογραφική 
Αναγγελ ία 
Απασχολο ύμενου 
Προσωπικο ύ σε 
Οικοδομοτεχνικ ά 
Έργα  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  test E12Ap .xml   
Απογραφική 
Αναγγελ ία 
Απασχολο ύμενου 
Προσωπικο ύ σε 
Οικοδομοτεχνικ ά 
Έργα  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  test E12Ap .json  WebE12Ap  
Δήλωση Εργοδότη 
Χορήγησης Γονικής 
Άδειας  
Εισαγωγή 
από αρχείο 
XML  
XSD  E14_v1.xsd   
Δήλωση Εργοδότη 
Χορήγησης Γονικής 
Άδειας  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  test E14 .xml   
Δήλωση Εργοδότη 
Χορήγησης Γονικής 
Άδειας  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  test E14 .json  WebE14 , 
WebE14 C 
Έναρξη 
Απασχόλησης – 
Πρόσληψη για 
Κάλυψη 
Επειγουσών 
Αναγκών  
Εισαγωγή 
από αρχείο 
XML  
XSD  FastAna_v1.xsd   
Έναρξη 
Απασχόλησης – 
Πρόσληψη για 
Κάλυψη 
Επειγουσών 
Αναγκών  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  testFastAn .xml   
Έναρξη 
Απασχόλησης – 
Πρόσληψη για 
Κάλυψη 
Επειγουσών 
Αναγκών  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  testFastAn .json  FastAna , 
FastAnaC  
Γνωστοπο ίηση 
Στοιχε ίων Ετ ήσιας 
Κανονικ ής Άδειας  
Εισαγωγή 
από αρχείο 
XML  
XSD  EA_v1.xsd   

<!-- page 41 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 41 / 47 
 
           
Γνωστοπο ίηση 
Στοιχε ίων Ετ ήσιας 
Κανονικ ής Άδειας  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  test EA .xml   
Γνωστοπο ίηση 
Στοιχε ίων Ετ ήσιας 
Κανονικ ής Άδειας  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  test EA .json  WebEA  
Απολογιστική 
Έκθεση  ΙΓΕΕ  
Εισαγωγή 
από αρχείο 
XML  
XSD  ApEkIgee_v1.xsd   
Απολογιστική 
Έκθεση  ΙΓΕΕ  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  test Igee .xml   
Απολογιστική 
Έκθεση  ΙΓΕΕ  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  test Igee .json  WebIgee  
Απολογιστική 
Έκθεση  ΕΠΑ  
Εισαγωγή 
από αρχείο 
XML  
XSD  ApEkEpa_v1.xsd   
Απολογιστική 
Έκθεση  ΕΠΑ  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  test Epa.xml   
Απολογιστική 
Έκθεση  ΕΠΑ  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  test Epa. json  We bEpa  
Δήλωση Εργοδ ότη 
Χρ ήσης από  
Εργαζ όμενους 
Μοτοποδηλ άτου ή 
Μοτοσυκλ έτας  
Εισαγωγή 
από αρχείο 
XML  
XSD  E13_v1.xsd   
Δήλωση Εργοδ ότη 
Χρ ήσης από  
Εργαζ όμενους 
Μοτοποδηλ άτου ή 
Μοτοσυκλ έτας  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  test E13.xml   
Δήλωση Εργοδ ότη 
Χρ ήσης από  
Εργαζ όμενους 
Μοτοποδηλ άτου ή 
Μοτοσυκλ έτας  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  test E13.json  WebE13  
Έναρξη Πρακτικής 
Άσκησης 
Σπουδαστών / 
Φοιτητών  
Εισαγωγή 
από αρχείο 
XML  
XSD  E35 _v 3.xsd   
Έναρξη Πρακτικής 
Άσκησης 
Σπουδαστών / 
Φοιτητών  
Εισαγωγή 
από αρχείο 
XML  
XML 
example  test E35 .xml   

<!-- page 42 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 42 / 47 
 
           
Έναρξη Πρακτικής 
Άσκησης 
Σπουδαστών / 
Φοιτητών  
Χρήση Web  
API  – 
αποστολή 
JSON  
JSON 
example  test E35 .json  WebE35, 
WebE35C  
 
 
 
 

<!-- page 43 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 43 / 47 
 
           
Παράρτημα IΙ: Ορθή Χρήση Υπηρεσιών Διαλειτουργικότητας – Web API 
(REST) – Αυθεντικοποίηση μέσω JWT tokens 
 
1. Περιγραφή αυθεντικοποίησης για τη χρήση των Υπηρεσιών 
Διαλειτουργικότητας – Web API (Services API) 
 
Τα services που παρέχονται χρησιμοποιούν authentication μέσω της χρήσης JWT access tokens, 
καθώς και refresh tokens για τη διευκόλυνση της χρήσης και ελαχιστοποίηση των φορών που 
χρειάζεται ο χρήστης να δώσει τα διαπιστευτήριά του. 
 
Στο παρακάτω διάγραμμα απεικονίζεται η λειτουργία της αυθεντικοποίησης με τη χρήση των JWT 
access tokens: 
 
 
 
  


<!-- page 44 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 44 / 47 
 
           
2. Περιγραφή ορθής χρήσης 
 
Για την ορθή χρήση της λειτουργίας αυθεντικοποίησης της πρόσβασης στις υπηρεσίες , πρέπει να 
τηρηθούν τα παρακάτω βήματα: 
 
 
 
 
 
 
Ο χρήστης στέλνει τα διαπιστευτήριά του και αν αυτά είναι σωστά λαμβάνει ένα JWT
access Token και ένα refresh token. To access token έχει διάρκεια ζωής 3 ωρών,
ενώ το refresh token 7 ημερών. Οι διάρκειες αυτές δύναται να αλλάξουν κατόπιν
ενημέρωσης.
Action endpoint: api/authentication
1. Απόκτηση πρόσβασης
Σε κάθε επόμενη κλήση συμπεριλαμβάνεται στην κεφαλίδα (header) το access
token. Αποτυχία κλήσης και αίτημα για refresh token.
Αν το access token έχει λήξει (έχουν παρέλθει οι 3 ώρες), τότε επιστρέφεται 401
Unauthorized. Σε αυτή την περίπτωση μπορεί να γίνει αίτημα για νέο access token,
χωρίς να δοθούν εκ νέου τα διαπιστευτήρια, στέλνοντας το παλιό access token
μαζί με το refresh token του. Το νέο access token, μπορεί να χρησιμοποιηθεί για να
πραγματοποιηθεί η κλήση που απέτυχε, καθώς και όλες οι επόμενες κλήσεις.
Action endpoint: api/authentication/refresh
2. Κλήση για πρόσβαση σε προστατευμένους πόρους
Αν το refresh token έχει λήξει, δηλαδή έχουν παρέλθει 7 ημέρες από την ώρα που
δημιουργήθηκε και αποστάλθηκε, τότε θα επιστραφεί 401 Unauthorized. Σε αυτή
την περίπτωση, θα πρέπει να επαναληφθεί το βήμα 1 για να αποκτηθεί ένα νέο
access token, δίνοντας τα διαπιστευτήρια.
Failed endpoint: api/authentication/refresh
Action endpoint: api/authentication
3. Αποτυχία κλήσης αιτήματος refresh token
Στην κλήση αποσύνδεσης πρέπει να αποστέλλεται και το refresh token, το οποίο θα
ανακληθεί (γίνει revoked). Με την ανάκληση, μειώνεται η διάρκεια χρήσης του
token στη διάρκεια ζωής του access token (3 ώρες), αντί των 7 ημερών του refresh
token. Προφανώς, μετά από κάθε αποσύνδεση πρέπει η διαδικασία πρόσβασης να
ξεκινήσει πάλι από το 1ο βήμα.
Action endpoint: api/authentication/logout
4. Αποσύνδεση (προαιρετική)

<!-- page 45 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 45 / 47 
 
           
3. Προβλεπόμενη χρήση 
 
Με βάση τις παραπάνω δυνατότητες, η ορθή χρήση για «συνεχόμενη λειτουργία» εμπεριέχει: 
• Χρήση Authentication για Λήψη Access και Refresh Token  
• Τήρηση  Access και Refresh Token και χρήση του Access Token για τις επόμενες 3 ώρες 
• Κάθε 3 ώρες ή όποτε χρειαστεί (Response 401) εντός των 7 ημερών από την τελευταία λήψη 
token: Χρήση Refresh για Λήψη νέων Access και Refresh Token  
 
 
Προσοχή: το σύνολο των κλήσεων εμπεριέχει έλεγχο ορθής χρήσης και περιορισμό κλήσεων 
και σε όποια περίπτωση γίνει υπέρβαση επιστρέφεται 429 Too Many Requests. 
 
 
  

<!-- page 46 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 46 / 47 
 
           
4. Λανθασμένη χρήση 
 
Παραδείγματα λανθασμένης χρήσης: 
 
1) Για κάθε κλήση λαμβάνεται πρώτα ένα access token: κλήση για access token, κλήση με το access 
token, κλήση για access token, κλήση με to access token, κα. 
 
2) Λαμβάνεται αρχικά ένα access token και έπειτα για κάθε κλήση ζητείται πρώτα ένα refresh token: 
κλήση για access token, κλήση με το access και refresh token για να πάρω νέο access token, κλήση 
με το νέο access token, κλήση με το access και refresh token για να πάρω νέο access token, κλήση 
με το νέο access token, κα. 
 
 
Ο γενικός κανόνας είναι ότι αποφεύγεται να γίνεται κλήση για λήψη 
access token αν δεν είναι αυτό απαραίτητο. 
 
  

<!-- page 47 -->
Πληροφοριακό Σύστημα Εργάνη ΙΙ,  Σύστημα Εκτέλεσης Υπηρεσιών Διαλειτουργικότητας 
 
 
σελίδα 47 / 47 
 
           
ΕΛΛΗΝΙΚΗ ΔΗΜΟΚΡΑΤΙΑ
Υπουργείο Εργασίας & Κοινωνικής Ασφάλισης
 
```
