# CMR (διεθνής φορτωτική) πάνω στο Δελτίο Αποστολής — σχεδίαση & πλάνο

> Status: **DESIGN / NOT BUILT** (Ιούν 2026). Αφορμή: αποστολή **δικού μας**
> εξοπλισμού (server) GR→**Telepoint Sofia, BG** για colocation. Ο λογιστής
> ζήτησε **CMR** πέρα από το Δελτίο Αποστολής.

## 1. Τι είναι το CMR — και τι ΔΕΝ είναι

**CMR** = διεθνής φορτωτική οδικής μεταφοράς (Σύμβαση CMR, Γενεύη 1956). Είναι
**έγγραφο ΜΕΤΑΦΟΡΑΣ** — η απόδειξη της σύμβασης μεταφοράς μεταξύ αποστολέα και
οδικού μεταφορέα. Συμπληρώνεται από τον αποστολέα/μεταφορέα και **συν-υπογράφεται**
(αποστολέας → μεταφορέας → παραλήπτης), σε πολλαπλά αντίτυπα (συνήθως 3-4).

Ισχύει για **διεθνή** οδική μεταφορά (αφετηρία/προορισμός σε διαφορετικές χώρες,
≥1 σε χώρα της Σύμβασης). Το GR→BG το πληροί. Για **εσωτερική** GR μεταφορά **δεν**
είναι νομική υποχρέωση — το Δελτίο Αποστολής αρκεί.

**Κρίσιμο για τον σχεδιασμό:**
- Το CMR **ΔΕΝ** είναι φορολογικό παραστατικό. Η ΑΑΔΕ/myDATA **δεν** το διέπει.
  → **ΟΧΙ** `invoice_type` «CMR», **ΟΧΙ** δεύτερη υποβολή myDATA, **ΟΧΙ** ΑΑ μετρητής.
- Είναι **συνοδευτικό του Δελτίου Αποστολής** — διαφορετική **όψη/εκτύπωση** των
  ΙΔΙΩΝ δεδομένων διακίνησης, στα **Αγγλικά**, σε τυποποιημένη φόρμα 24 κουτιών.

> **Φορολογικό σκέλος = ερώτημα λογιστή, ΟΧΙ απόφαση κώδικα.** Η μεταφορά δικών σου
> αγαθών σε άλλο κράτος-μέλος ΕΕ μπορεί να είναι «μεταφορά ιδίων αγαθών» (deemed
> ICS/ICA) Ή να πέφτει σε εξαίρεση (προσωρινή χρήση / αγαθά υπό τον έλεγχό σου).
> Το ekdosi **δεν αποφασίζει** φορολογική μεταχείριση· φτιάχνει το **έγγραφο**. Ο
> `move_purpose` (§8.14) του ΔΑ και τυχόν ΦΠΑ ορίζονται από τον λογιστή.

## 2. Το «όλα στα Αγγλικά» πρόβλημα

Όλα τα στοιχεία ταυτότητας σήμερα είναι **ελληνικά** και **δεν υπάρχει αγγλική
εκδοχή** πουθενά:

| CMR απαιτεί (λατινικά) | Τι έχουμε σήμερα | Πηγή |
|---|---|---|
| Sender (όνομα/διεύθυνση) | `companies.name`/`address`/`city`/`postcode` (ΕΛ) | εταιρεία |
| Consignee (παραλήπτης) | `delivery_notes.recipient_name`, `customers.*` (ΕΛ) | snapshot ΔΑ |
| Place of taking over (φόρτωση) | `delivery_notes.loading_*` (ΕΛ) | snapshot ΔΑ |
| Place of delivery (παράδοση) | `delivery_notes.delivery_*` (ΕΛ) | snapshot ΔΑ |
| Goods (περιγραφή/ποσότητα/βάρος) | `delivery_note_lines.product_descr`, `qty`, `metric_unit` (ΕΛ) | γραμμές ΔΑ |
| Carrier (μεταφορέας) | μόνο `carrier_afm` (όχι όνομα/διεύθυνση) | ΔΑ |

**Λύση — υβριδική (set-once + per-document override + transliteration fallback):**
1. **Sender (μία φορά):** προαιρετικά αγγλικά στην εταιρεία — `name_en`,
   `address_en`, `city_en`, `country` (η επίσημη αγγλική επωνυμία, π.χ. «… P.C.»).
2. **Consignee / places / goods (ανά έγγραφο):** πεδία override στο CMR, **προ-
   συμπληρωμένα με αυτόματη μεταγραφή** ΕΛ→λατινικά (ΕΛΟΤ 743) από το ελληνικό
   snapshot, **επεξεργάσιμα** από τον χειριστή πριν την εκτύπωση.
3. **Carrier:** νέα πεδία `carrier_name` / `carrier_address` (έχουμε μόνο ΑΦΜ).
4. **Goods:** προαιρετικό αγγλικό override ανά γραμμή· fallback = μεταγραφή του
   `product_descr` (+ καλό να μπαίνει **βάρος** kg, που το CMR θέλει και σήμερα δεν
   το κρατάμε — δες §4).

> Η μεταγραφή ΕΛΟΤ-743 είναι «αρκετά καλή για να διαβαστεί», **όχι** αυθεντική. Γι'
> αυτό κάθε πεδίο είναι **editable** — ο χειριστής διορθώνει επίσημες επωνυμίες.

## 3. Πού «κρεμάμε» το CMR — όχι νέο παραστατικό, επέκταση του ΔΑ

Το CMR είναι **1-προς-1 με ένα Δελτίο Αποστολής** (το ίδιο φορτίο, η ίδια κίνηση).
Δύο επιλογές αποθήκευσης των CMR-only στοιχείων:

- **(A) Νέος πίνακας `delivery_note_cmr`** (1:1 με `delivery_notes`) — καθαρός
  διαχωρισμός, δεν φουσκώνει το ΔΑ· κρατά τα overrides + carrier + βάρη/όγκο +
  flags (π.χ. cash-on-delivery, instructions). **Προτεινόμενο.**
- (B) Στήλες πάνω στο `delivery_notes` — απλούστερο αλλά ανακατεύει transport-doc
  πεδία με το φορολογικό ΔΑ.

→ **Επιλογή (A).** Το CMR παραμένει «πρόσθετο layer» πάνω στο ΔΑ, όπως ακριβώς το
CMR είναι layer πάνω στη διακίνηση στην πραγματικότητα.

## 4. Data model (πρόταση)

```
companies:                      +name_en, +address_en, +city_en   (sender, set-once)
                                 (country υπάρχει ως country_code)

delivery_note_lines:            +weight_kg DECIMAL(9,3) NULL       (CMR box 11)
                                 +product_descr_en STRING(256) NULL (override· fallback=μεταγραφή)

NEW delivery_note_cmr (1:1):
  id, company_id, delivery_note_id (unique)
  # overrides (λατινικά)
  sender_text, consignee_text, taking_over_text, delivery_text     (TEXT, pre-filled)
  # carrier (CMR box 16/17)
  carrier_name, carrier_address, successive_carrier_name?
  # transport meta
  taking_over_place, taking_over_at, established_place_date
  documents_attached, instructions, payment_terms,
  cash_on_delivery DECIMAL(14,2) NULL, special_agreements
  reservations (box 18), copies_count TINYINT DEFAULT 3
  printed BOOL DEFAULT false
  timestamps, softDeletes
```

Όλα **nullable**: ένα CMR γεννιέται από το ΔΑ, προ-συμπληρωμένο, και ο χειριστής
συμπληρώνει/διορθώνει τα κενά. Tenant-scoped (`BelongsToCompany`), όπως όλα.

## 5. CMR 24-box → πηγή δεδομένων (mapping)

| # | Πεδίο CMR | Πηγή |
|---|---|---|
| 1 | Sender | `companies.name_en/address_en` (fallback μεταγραφή) |
| 2 | Consignee | `delivery_note_cmr.consignee_text` (← `recipient_name`/customer) |
| 3 | Place of delivery | `…taking_over`/`delivery_text` (← `delivery_*`) |
| 4 | Place & date of taking over | `taking_over_place`/`taking_over_at` (← `loading_*`/`dispatch_at`) |
| 5 | Documents attached | συνδεδεμένο ΔΑ invcode + (προαιρ.) τιμολόγιο |
| 6–9 | Marks/numbers, packages, packing | γραμμές ΔΑ + (προαιρ.) πεδία |
| 10–12 | Goods description / gross weight | `product_descr_en` + `weight_kg` |
| 13 | Sender's instructions | `instructions` |
| 15 | Terms of payment | `payment_terms` |
| 16 | Carrier | `carrier_name/address` (έχουμε `carrier_afm`) |
| 17 | Successive carrier | `successive_carrier_name` |
| 18 | Reservations | `reservations` |
| 21 | Established in / on | `established_place_date` |
| 22–24 | Signatures (sender/carrier/consignee) | κενά πλαίσια υπογραφής στο PDF |

## 6. Rendering

Σιβλινγκ του υπάρχοντος ΔΑ PDF — **καμία εμπλοκή myDATA**:
- `App\Services\Delivery\CmrPdf` (κατά το `DeliveryNotePdf`: DomPDF, A4, ίδιο
  memory/time guard, ίδιο logo helper).
- Blade `resources/views/delivery-notes/cmr.blade.php` — τυποποιημένη φόρμα CMR 24
  κουτιών, **αγγλικά labels** (ή πολύγλωσσα EN/FR/DE όπως η επίσημη φόρμα).
- Action **«Εκτύπωση CMR»** στο `DeliveryNote` (δίπλα στο ΔΑ PDF), visible μόνο για
  διασυνοριακά (π.χ. όταν `customer.country`/`delivery` ≠ GR — ή πάντα διαθέσιμο με
  προειδοποίηση για εσωτερικά).
- Βγάζει τα N αντίτυπα (`copies_count`) με σήμανση «Copy 1 – Sender» κ.λπ.
- **ΟΧΙ** QR/MARK (δεν είναι myDATA έγγραφο).

## 7. Πλάνο (φάσεις)

- **Φάση 0 — απόφαση/λογιστής (μπλοκάρει):** ποιος `move_purpose` (§8.14) για
  «αποστολή ιδίου εξοπλισμού για colocation»; υπάρχει ΦΠΑ/ICS συνέπεια; ποια
  επίσημη αγγλική επωνυμία/διεύθυνση; consignee = «δική μου εταιρεία c/o Telepoint»
  ή Telepoint; (καθαρά εκτός κώδικα).
- **Φάση 1 — δεδομένα:** migrations (`companies` αγγλικά, `delivery_note_lines`
  weight/descr_en, νέος `delivery_note_cmr`), models, `BelongsToCompany`,
  `TransliterateGreek` helper (ΕΛΟΤ 743).
- **Φάση 2 — προ-συμπλήρωση + φόρμα:** «Δημιουργία/Επεξεργασία CMR» από ένα ΔΑ
  (προ-γεμίζει με μεταγραφή· editable). RelationManager ή dedicated page.
- **Φάση 3 — εκτύπωση:** `CmrPdf` + Blade 24-box + action + αντίτυπα. Tests
  (render smoke + το mapping + isolation).
- **Φάση 4 (προαιρ.):** «πακέτο εξαγωγής» — ΔΑ PDF + CMR + (προαιρ.) commercial/
  proforma invoice για το τελωνείο/μεταφορέα μαζί.

## 8. Ανοιχτά ερωτήματα

1. **Φάση 0 φορολογικά** (λογιστής) — βλ. πάνω. Δεν τα αποφασίζει το ekdosi.
2. Μεταγραφή: ΕΛΟΤ 743 αρκεί ως default; (ναι, με override παντού).
3. Πεδίο **βάρους**: το προσθέτουμε σε **όλες** τις γραμμές ΔΑ ή μόνο όταν υπάρχει
   CMR; (πρόταση: στήλη στις γραμμές, προαιρετική — χρήσιμη και αλλού).
4. Visibility του action: αυστηρά διασυνοριακά ή πάντα; (πρόταση: πάντα διαθέσιμο,
   με badge «international» όταν consignee country ≠ GR).
5. Υπογραφές: αρκεί κενό πλαίσιο για χειρόγραφη; (ναι σε πρώτη φάση).

---

_Δες επίσης: `docs/aade/myDATA_API_Documentation_DeliveryNote_v2.0.1_preofficial.md`
(ΔΑ lifecycle), `app/Services/Delivery/DeliveryNotePdf.php` (το PDF pattern που
αντιγράφουμε), `FEATURES.md §Ψηφιακό ΔΑ`._
