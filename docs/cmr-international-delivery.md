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

## 4. Η πραγματική φόρμα (από το `docs/reference/cmr-template.pdf`)

Standard CMR, **μονή σελίδα A4 portrait** (594.96×841.92 pt). Επικεφαλίδα «CMR
INTERNATIONAL CONSIGNMENT NOTE» + η ρήτρα Σύμβασης· πάνω-δεξιά **Reference No.**·
πάνω-αριστερά η ετικέτα αντιτύπου («Copy for the carrier» κ.λπ.). Τα 24 κουτιά:

```
1  Sender (name, address, country)
2  Consignee (name, address, country)        16 Carrier (name, address, country)
3  Place of delivery (place, country)         17 Successive carriers
4  Place & date of taking over                18 Carrier's reservations & observations
5  Annexed documents
   ── Goods table (γραμμές) ──
   6 Marks & numbers | 7 No. of packages | 8 Method of packing | 9 Nature of goods
   10 Statistical number | 11 Gross weight kg | 12 Volume m³
   (υπο-γραμμή ADR επικίνδυνων: Class | Number | Letter | ADR)
13 Sender's instructions (Customs/formalities) 19 Special agreements
14 Directions as to freight payment            20 To be paid by: Sender | Consignee
   (Freight paid / Freight to be paid)            Carriage / Reductions / Balance /
15 Cash on delivery                               Supplement / Miscellaneous / Total
21 Established in / on
22 Signature & stamp of sender  | 23 Signature & stamp of carrier | 24 …consignee
                                  (Tractor plate / Trailer plate)
```

Τυπικά **4 αντίτυπα** με χρώμα/ετικέτα: κόκκινο=Sender, μπλε=Consignee,
πράσινο=Carrier, μαύρο=αρχείο. Η φόρμα είναι μία σελίδα — τα αντίτυπα διαφέρουν
μόνο στην ετικέτα/χρώμα πάνω-αριστερά.

## 5. Data model (πρόταση — βάσει της πραγματικής φόρμας)

```
companies:                      +name_en, +address_en, +city_en   (sender box 1, set-once)
                                 (country υπάρχει ως country_code)

delivery_note_lines (goods table boxes 6–12):
  +product_descr_en STRING(256) NULL   (box 9· fallback=μεταγραφή του product_descr)
  +marks_numbers    STRING(60)  NULL   (box 6)
  +packages_count   INT         NULL   (box 7)
  +packing_method   STRING(40)  NULL   (box 8)
  +statistical_no   STRING(20)  NULL   (box 10· HS/commodity code)
  +weight_kg        DECIMAL(9,3) NULL  (box 11· gross weight)
  +volume_m3        DECIMAL(9,3) NULL  (box 12)
  +adr_class        STRING(10)  NULL   (επικίνδυνα — συνήθως κενό για server)

NEW delivery_note_cmr (1:1 με delivery_notes, unique delivery_note_id):
  id, company_id, delivery_note_id
  reference_no STRING(40) NULL                 # box top-right (default = ΔΑ invcode)
  # overrides λατινικά (pre-filled, editable) — boxes 1–4
  sender_text, consignee_text, delivery_text, taking_over_text   TEXT
  taking_over_place STRING, taking_over_at DATETIME              # box 4
  # carrier — boxes 16/17/23
  carrier_name, carrier_address STRING                          # (έχουμε μόνο carrier_afm)
  successive_carrier STRING NULL                                # box 17
  tractor_plate, trailer_plate STRING NULL                      # κάτω από box 23
  carrier_reservations TEXT NULL                                # box 18
  # documents / instructions / agreements — boxes 5/13/19
  annexed_documents, sender_instructions, special_agreements TEXT NULL
  # freight charges — boxes 14/15/20
  freight_paid BOOL NULL                                        # box 14 (paid/to-be-paid)
  charges_to_be_paid_by ENUM('sender','consignee') NULL         # box 20
  carriage_charges, reductions, balance, supplement,
    misc_charges, total_charges DECIMAL(14,2) NULL              # box 20 table
  cash_on_delivery DECIMAL(14,2) NULL                           # box 15
  established_place STRING NULL, established_on DATE NULL        # box 21
  copies_count TINYINT DEFAULT 4
  printed BOOL DEFAULT false
  timestamps, softDeletes
```

Όλα **nullable**: το CMR γεννιέται από το ΔΑ προ-συμπληρωμένο και ο χειριστής
διορθώνει/συμπληρώνει. Tenant-scoped (`BelongsToCompany`). Τα freight-charges είναι
χρήσιμα όταν πληρώνεις μεταφορέα· για own-gear colocation συχνά μένουν κενά.

## 6. Rendering

Σιβλινγκ του υπάρχοντος ΔΑ PDF — **καμία εμπλοκή myDATA**:
- `App\Services\Delivery\CmrPdf` (κατά το `DeliveryNotePdf`: DomPDF, A4, ίδιο
  memory/time guard, ίδιο logo helper).
- Blade `resources/views/delivery-notes/cmr.blade.php` — **πιστή αναπαραγωγή** της
  `docs/reference/cmr-template.pdf` (μονή A4, 24 κουτιά, αγγλικά labels). Η geometry/
  διάταξη κουτιών αντιγράφεται από το reference PDF.
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

_Δες επίσης: **`docs/reference/cmr-template.pdf`** (η ακριβής φόρμα που αναπαράγουμε),
`docs/aade/myDATA_API_Documentation_DeliveryNote_v2.0.1_preofficial.md` (ΔΑ lifecycle),
`app/Services/Delivery/DeliveryNotePdf.php` (το PDF pattern που αντιγράφουμε),
`FEATURES.md §Ψηφιακό ΔΑ`._
