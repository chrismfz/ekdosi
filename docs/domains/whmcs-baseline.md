# WHMCS domains — baseline από την παραγωγή (MyIP, 2026-09-23)

> Read-only **αθροιστικά** queries στην παραγωγική WHMCS της MyIP (MariaDB 11.4). Μόνο μετρήσεις:
> κανένα όνομα, επαφή, κωδικός ή τιμή additional field. Σκοπός: η «θεωρία» πριν το A4 (grEPP) και
> το A5 (reconciliation). Το import μένει **registrar-first** (απόφαση ιδιοκτήτη, `DomainImportService`):
> αυτά είναι για έλεγχο του σχεδίου, όχι πηγή δεδομένων.

## 1. Portfolio (Active)

| Registrar | Active | TLDs | Άλλες καταστάσεις |
|---|---:|---|---|
| **grepp** (.gr / .ελ) | **1381** | .gr 1340 · .com.gr 25 · .net.gr 7 · .edu.gr 4 · .org.gr 4 · .ελ 1 | Grace 3 · Cancelled 6 |
| **cnic** (CentralNic) | **226** | .com 145 · .eu 27 · .org 17 · .net 12 · +15 άλλα | Grace 2 · Redemption 3 · Pending 1 |
| **openprovider** | **224** | .com 153 · .eu 19 · .net 12 · .org 10 · +20 άλλα | Pending 1 |
| enom | 1 | .io | — |
| (κενό) | 1 | .com | Pending 1 (.gr) · Cancelled 14 |

Σύνολο **1833 active, ~75% είναι .gr**. Καταστάσεις WHMCS που εμφανίζονται: Active, Pending, Grace,
Redemption, Cancelled. **Δεν** εμφανίζονται: Expired, Transferred Away, Fraud (όσα φεύγουν, σβήνονται
ή γίνονται Cancelled).

## 2. Τα δύο ρολόγια

- **`nextduedate = expirydate` σε ΟΛΑ τα active (0 αποκλίσεις).** `DomainSyncNextDueDate=on` με
  `DomainSyncNextDueDateDays=0`: η WHMCS αντιγράφει τη λήξη του registrar στο billing date.
  → Στο import: `next_due_date` του SC = `expires_at` (ήδη ο κανόνας του README §6.2). Στο A5
  η baseline απόκλιση είναι **0**, άρα κάθε διαφορά είναι εύρημα και όχι ανοχή.
- `nextinvoicedate ≠ nextduedate` σε 185 (cnic 166, openprovider 11, grepp 8). Η WHMCS το μετακινεί
  όταν βγάζει ή ακυρώνει τιμολόγιο ανανέωσης. Στο ekdosi το staging γίνεται από το SC, οπότε δεν μεταφέρεται.
- **Τουλάχιστον 355 χειροκίνητες αλλαγές ημερομηνιών** (εγγραφή/λήξη/due) από admin σε 2 χρόνια,
  συχνά με σημείωση `<grEPP>Transfer in: …</grEPP>` στο `additionalnotes`. Οι ημερομηνίες των .gr
  διορθώνονται σήμερα με το χέρι. Το nightly sync του A4 (`domain:info` → exDate) το καλύπτει αυτόματα.

## 3. Περίοδοι & flags

- **grepp: μόνο 2/4/6/8 έτη** (2 έτη: 809 εγγραφές + 522 μεταφορές). Επιβεβαιώνει τον κανόνα
  «άρτια πολλαπλάσια» του `grepp/README.md` §8 (ως 8 έτη· 10 δεν εμφανίζεται).
- cnic/openprovider: 1–5 έτη (+ 9 έτη σε 5 εγγραφές, μάλλον λάθος καταχώρισης).
- `donotrenew`: 70 (grepp 56) → `auto_renew = false`.
- Addons πρακτικά ανύπαρκτα: ID protection 7, DNS management 16, email forwarding 1, premium 0.
  Επιβεβαιώνει το defer (README §11).
- grepp: 837 εγγραφές / 553 μεταφορές (τύπος παραγγελίας).

## 4. Χρεώσεις (`tblinvoiceitems`, όλο το ιστορικό)

Ανανέωση (`Domain`) 10243 · `DomainRegister` 2657 · `DomainTransfer` 1043 · **`DomainRedemptionFee` 276**
· ID protection 7 · DNS 1. Με `DomainExpirationFeeHandling=existing` το fee μπαίνει στο υπάρχον
τιμολόγιο ανανέωσης. Το restore billing είναι σήμερα «εκτός v1 / χειροκίνητα» (BACKLOG). Με 276
περιπτώσεις δεν είναι σπάνιο → υποψήφιο για αναβάθμιση.

## 5. Τι γίνεται στην πράξη (activity log, 2 χρόνια)

| Ενέργεια | Πλήθος | Για το ekdosi |
|---|---:|---|
| Αυτόματη ανανέωση **μετά την πληρωμή** / επιτυχείς | 1603 / 1507 | ⚠ το ekdosi ανανεώνει **στην έκδοση** (README §6.1) — βλ. §7 |
| Αυτόματη εγγραφή μετά την πληρωμή / επιτυχείς | 214 / 228 | ίδιο με ekdosi (register = post-payment) |
| Μεταφορά μετά την πληρωμή / ξεκίνησαν | 194 / 364 | πολλές από τη μετάβαση CNIC→Openprovider |
| Αιτήματα EPP code | 198 | .gr = DACoR (A4)· αργότερα self-service στην πύλη |
| Ο πελάτης / ο admin έκλεισε το auto-renew | 66 / 36 | toggle στην πύλη (Πυλώνας D) |
| Μεταφορά domain σε άλλον πελάτη | 36 | ανάθεση/αλλαγή πελάτη |
| Ακυρώσεις/αναπαραγωγή τιμολογίων ανανέωσης | 196 + 182 + 39 | θόρυβος της WHMCS· το SC κάνει staging μία φορά |
| **Αποτυχία ανανέωσης: «Credit limit exceeded»** | 46 | έλεγχος υπολοίπου registrar πριν την ανανέωση + ειδοποίηση |
| «Renewal replaced by Openprovider transfer» | 168 | η μετάβαση CNIC→OP γίνεται **στην ανανέωση** (custom hooks) |
| Αποθήκευση nameservers | 47 | ✅ υπάρχει |
| Registrar lock (cnic) / unlock για μετάβαση | 76 / 56 | ✅ υπάρχει (OP)· .gr χωρίς lock |

Custom εργασίες της WHMCS: `[MIGRATION_AUTORENEW]` (daily), `[NS_CORRECTION]`, `[cnicmigration]`.

## 6. Υπενθυμίσεις λήξης

`DomainRenewalNotices = 60,30,15,10,5`, με **34.296 αποστολές** (8765 / 8145 / 6897 / 5790 / 4699).
Templates: «Ενημέρωση λήξης domain - Upcoming Domain Renewal Notice» + «Domain Renewal Confirmation».
**Κενό στο ekdosi:** δεν υπάρχουν υπενθυμίσεις λήξης domain (ο `domain_reminders` του README §3.8 δεν
χτίστηκε, και το README έγραφε 15/10/5). **Cutover blocker** για τα domains.

## 7. Τι αλλάζει στο σχέδιο

1. **Το A4 (grEPP) είναι το κύριο κομμάτι**, όχι «ένας 2ος registrar για απόδειξη του abstraction».
2. **CNIC: 226 active είναι ακόμα εκεί.** Το README έγραφε «η MyIP έφυγε» — η μετάβαση είναι σε εξέλιξη
   και γίνεται στην ανανέωση. Το registrar-first import από Openprovider **δεν τα βλέπει** → χρειάζεται απόφαση.
3. **Υπενθυμίσεις λήξης (60/30/15/10/5)** πριν το cutover.
4. **Χρονισμός ανανέωσης:** η WHMCS σήμερα ανανεώνει μετά την πληρωμή· το ekdosi στην έκδοση
   (η MyIP πληρώνει τον registrar πριν πληρώσει ο πελάτης) → επιβεβαίωση ιδιοκτήτη.
5. Redemption billing: ίσως από «εκτός v1» σε v1 (276 περιπτώσεις).
6. Έλεγχος υπολοίπου registrar πριν την ανανέωση (grEPP account info §5.11 / υπόλοιπο OP).
7. WHMCS «Pending» (3 εγγραφές) → `pending_register` / `pending_transfer` ανάλογα με τον τύπο.

## 8. Ανοιχτά (επόμενος γύρος)

- `wDomain_handle` (823 γραμμές) — πιθανότατα τα contact handles του grEPP module → δομή + prefix.
- `mod_domain_migration` (160) · `mod_cnic_zones` (826 — DNS zones στο CNIC;).
- Ονόματα additional fields (το UNION έσκασε σε collation).
- `tblmodulelog`: ποιες εντολές registrar τρέχουν (μόνο module/action).
- Redemption fees ανά TLD · πότε λήγουν τα 226 cnic (πόσο κρατάει η μετάβαση).
