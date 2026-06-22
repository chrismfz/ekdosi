# CMR (διεθνής φορτωτική) — αυτοτελές έγγραφο

> Status: **✅ BUILT (Φάσεις 1–4, Ιούν 2026)** — `cmr_notes`/`cmr_lines`,
> `CmrResource` (standalone «Νέο CMR»), action «Δημιουργία CMR» σε Τιμολόγιο/ΔΑ
> (pre-fill + μεταγραφή ΕΛΟΤ-743), editable draft, `CmrPdf` (24-box). **Εκκρεμεί
> μόνο η Φάση 0** = φορολογικές αποφάσεις λογιστή για own-gear (move_purpose/ΦΠΑ)
> — ΔΕΝ μπλοκάρει το έγγραφο. Αφορμή: αποστολή **δικού μας** server GR→**Telepoint
> Sofia, BG** για colocation. _Το κείμενο πιο κάτω είναι η αρχική σχεδίαση._

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
- Είναι **αυτοτελές έγγραφο μεταφοράς** στα **Αγγλικά** (τυποποιημένη φόρμα 24
  κουτιών) — μπορεί να **συνοδεύει** δικό μας ΔΑ/τιμολόγιο Ή να στέκεται **μόνο του**
  (όταν τα αγαθά τρίτου περνούν από τα χέρια μας). Δες §3 για τις δύο περιπτώσεις.

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

## 3. Πού «κρεμάμε» το CMR — ΑΥΤΟΤΕΛΕΣ έγγραφο με ΠΡΟΑΙΡΕΤΙΚΗ πηγή

Δύο πραγματικές περιπτώσεις χρήσης (από τον operator):

- **(Α) Standalone CMR** — κάτι περνά από τα χέρια μου από **τρίτο**, που έχει ήδη
  δικό του τιμολόγιο/ΔΑ· θέλω **μόνο** CMR. Δεν υπάρχει δικό μας ΔΑ/τιμολόγιο.
- **(Β) CMR πάνω σε δικό μας παραστατικό** — έκοψα **τιμολόγιο ή ΔΑ** και θέλω
  ΚΑΙ CMR γι' αυτό.

→ Αυτό σημαίνει ότι το CMR **ΔΕΝ** είναι 1:1 επέκταση του ΔΑ (η αρχική σκέψη). Είναι
**αυτοτελές έγγραφο μεταφοράς** με μια **προαιρετική, πολυμορφική** σύνδεση πηγής:
`source = DeliveryNote | Invoice | null`.

- Standalone → `source = null`, ο χειριστής συμπληρώνει τα πάντα (αγγλικά).
- Από ΔΑ/τιμολόγιο → `source` δείχνει το έγγραφο· προ-συμπληρώνεται (μεταγραφή) και
  μένει **επεξεργάσιμο προσχέδιο** μέχρι την εκτύπωση.

Sender/Consignee είναι **ελεύθερο κείμενο** (στην περίπτωση Α είναι τρίτοι, όχι
απαραίτητα tenant/πελάτης) — απλώς προ-γεμίζουν από το context όταν υπάρχει.

**Δύο entry points, ΕΝΑ κοινό editable form** (δες §7):
1. **Μενού → «CMR (φορτωτικές)» → Νέο** (standalone). _Απόφαση υλοποίησης: μπήκε στο
   ΥΠΑΡΧΟΝ nav group «Ψηφιακή Διακίνηση», δίπλα στα Δελτία Αποστολής — για
   discoverability (τα έγγραφα διακίνησης μαζί), αντί για νέο group μόνο για ένα
   resource. Εννοιολογικά ΔΕΝ είναι myDATA e-transport (no QR/MARK)· αν ποτέ
   ενοχλεί, μετακινείται σε δικό του «Διακίνηση/Μεταφορά» group._
2. **Παραστατικό (Τιμολόγιο/ΔΑ) → Ενέργειες → «Δημιουργία CMR»** (pre-filled draft).

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

## 5. Data model (πρόταση — αυτοτελές `cmr_notes` + `cmr_lines`)

Το CMR είναι **self-contained**: κρατά δικά του στοιχεία + δικές του γραμμές αγαθών
(snapshot, ώστε standalone να δουλεύει χωρίς πηγή, και sourced να είναι editable EN).

```
companies:                      +name_en, +address_en, +city_en   (sender box 1, set-once)
                                 (country υπάρχει ως country_code)

NEW cmr_notes:
  id, company_id, legacy_id?
  number INT, reference_no STRING(40) NULL      # per-company counter + box top-right ref
  status STRING(20) DEFAULT 'draft'             # draft → finalized (όχι myDATA· soft lock)
  # ΠΡΟΑΙΡΕΤΙΚΗ πηγή (πολυμορφική): DeliveryNote | Invoice | null (standalone)
  source_type STRING NULL, source_id BIGINT NULL
  customer_id BIGINT NULL                        # link όταν consignee = πελάτης μας
  # boxes 1–4 (ελεύθερο κείμενο, λατινικά· pre-filled με μεταγραφή)
  sender_text, consignee_text, delivery_text, taking_over_text   TEXT
  taking_over_place STRING NULL, taking_over_at DATETIME NULL     # box 4
  # carrier — boxes 16/17/18/23
  carrier_name, carrier_address STRING NULL, successive_carrier STRING NULL
  tractor_plate, trailer_plate STRING NULL, carrier_reservations TEXT NULL
  # documents / instructions / agreements — boxes 5/13/19
  annexed_documents, sender_instructions, special_agreements TEXT NULL
  # freight charges — boxes 14/15/20
  freight_paid BOOL NULL                                          # box 14
  charges_to_be_paid_by ENUM('sender','consignee') NULL           # box 20
  carriage_charges, reductions, balance, supplement,
    misc_charges, total_charges DECIMAL(14,2) NULL                # box 20 table
  cash_on_delivery DECIMAL(14,2) NULL                             # box 15
  established_place STRING NULL, established_on DATE NULL          # box 21
  copies_count TINYINT DEFAULT 4
  issued_at DATETIME, printed BOOL DEFAULT false, notes TEXT NULL
  timestamps, softDeletes
  index(company_id, source_type, source_id)

NEW cmr_lines (goods table boxes 6–12):
  id, company_id, cmr_note_id
  marks_numbers STRING(60) NULL          # box 6
  packages_count INT NULL                # box 7
  packing_method STRING(40) NULL         # box 8
  nature_en STRING(256) NULL             # box 9 (περιγραφή αγαθών, αγγλικά)
  statistical_no STRING(20) NULL         # box 10 (HS/commodity)
  weight_kg DECIMAL(9,3) NULL            # box 11 (gross weight)
  volume_m3 DECIMAL(9,3) NULL            # box 12
  adr_class STRING(10) NULL              # επικίνδυνα — συνήθως κενό
  timestamps
```

Όλα **nullable**: standalone ξεκινά κενό· sourced προ-γεμίζει (μεταγραφή) και
διορθώνεται. Tenant-scoped (`BelongsToCompany`). **Καμία εμπλοκή myDATA / ΑΑ
μετρητή** — το `number` είναι απλός per-company counter για αρχειοθέτηση.

> Σημ.: η αρχική ιδέα ήταν `delivery_note_cmr` (1:1 με ΔΑ). Απορρίφθηκε γιατί η
> περίπτωση (Α) standalone απαιτεί CMR **χωρίς** ΔΑ — άρα first-class έγγραφο.

## 6. Entry points & ροή (προσχέδιο → εκτύπωση)

**Ένα `CmrResource` (Filament)** σε nav group «Ψηφιακή Διακίνηση» (μαζί με τα ΔΑ· βλ. §3),
με κοινό **editable form** για τα 24 κουτιά + repeater για `cmr_lines`. Δύο τρόποι
δημιουργίας, ίδιο form, ίδιο record:

1. **Standalone** — `CmrResource` → «Νέο CMR»: κενή φόρμα, ο χειριστής συμπληρώνει
   sender/consignee/goods/carrier (αγγλικά). `source = null`.
2. **Από παραστατικό** — action **«Δημιουργία CMR»** μέσα στο `ViewInvoice` και στο
   `DeliveryNote` (στις «Ενέργειες»): δημιουργεί `cmr_notes` με `source` = αυτό το
   έγγραφο, **προ-συμπληρωμένο** (μεταγραφή ΕΛΟΤ-743 από τα ελληνικά στοιχεία +
   γραμμές), `status='draft'`, και κάνει redirect στο edit form για διορθώσεις.

**Προσχέδιο πριν «ξερά»:** το record μένει `draft` και πλήρως **editable** — ο
χειριστής διορθώνει ελληνικά→αγγλικά πριν εκτυπώσει. Η «Εκτύπωση CMR» βγάζει το PDF
οποτεδήποτε (δεν χρειάζεται lock — δεν είναι φορολογικό)· προαιρετικά `status=finalized`
+ `printed=true` ως ένδειξη. Από-edit μετά την εκτύπωση επιτρέπεται (re-print).

## 7. Rendering

Σιβλινγκ του υπάρχοντος ΔΑ PDF — **καμία εμπλοκή myDATA**:
- `App\Services\Cmr\CmrPdf` (κατά το `DeliveryNotePdf`: DomPDF, A4, ίδιο memory/time
  guard, ίδιο logo helper).
- Blade `resources/views/cmr/pdf.blade.php` — **πιστή αναπαραγωγή** της
  `docs/reference/cmr-template.pdf` (μονή A4, 24 κουτιά, αγγλικά labels). Η geometry
  αντιγράφεται από το reference PDF.
- Βγάζει τα N αντίτυπα (`copies_count`) με σήμανση «Copy 1 – Sender» κ.λπ.
- **ΟΧΙ** QR/MARK (δεν είναι myDATA έγγραφο).

## 8. Πλάνο (φάσεις)

- **Φάση 0 — απόφαση/λογιστής (μπλοκάρει ΜΟΝΟ το tax σκέλος, όχι το CMR):** για την
  περίπτωση (Β)/own-gear: ποιος `move_purpose` (§8.14) στο ΔΑ· ΦΠΑ/ICS συνέπεια·
  επίσημη αγγλική επωνυμία/διεύθυνση· consignee = «η εταιρεία μου c/o Telepoint» ή
  Telepoint. (Το CMR ως έγγραφο χτίζεται ανεξάρτητα.)
- **Φάση 1 — δεδομένα:** migrations (`companies` αγγλικά· νέα `cmr_notes` + `cmr_lines`),
  models (+ `BelongsToCompany`, πολυμορφικό `source`), `TransliterateGreek` helper
  (ΕΛΟΤ 743), per-company `number` counter.
- **Φάση 2 — `CmrResource` + form:** list/create/edit (το κοινό editable form +
  `cmr_lines` repeater). Standalone create λειτουργεί από εδώ.
- **Φάση 3 — pre-fill από πηγή:** action «Δημιουργία CMR» σε `ViewInvoice` +
  `DeliveryNote` → δημιουργεί draft με μεταγραφή & redirect στο edit.
- **Φάση 4 — εκτύπωση:** `CmrPdf` + Blade 24-box + αντίτυπα. Tests (render smoke +
  mapping + pre-fill/μεταγραφή + isolation).
- **Φάση 5 (προαιρ.):** «πακέτο εξαγωγής» — source PDF + CMR (+ προαιρ. commercial/
  proforma invoice) μαζί για τελωνείο/μεταφορέα.

## 9. Ανοιχτά ερωτήματα

1. **Φάση 0 φορολογικά** (λογιστής) — βλ. πάνω. Δεν τα αποφασίζει το ekdosi.
2. Μεταγραφή: ΕΛΟΤ 743 αρκεί ως default; (ναι, με override παντού).
3. `source` από **Invoice**: το τιμολόγιο δεν έχει split loading/delivery διεύθυνσης
   (το ΔΑ έχει) → ο χειριστής τις συμπληρώνει· OK για draft.
4. Numbering: per-company `number` counter αρκεί, ή θέλουμε σειρά/έτος; (πρόταση:
   απλός counter + ελεύθερο `reference_no`).
5. Υπογραφές: αρκεί κενό πλαίσιο για χειρόγραφη; (ναι σε πρώτη φάση).
6. Permission/Shield: νέο `CmrResource` → `shield:generate` + role provisioning.

---

_Δες επίσης: **`docs/reference/cmr-template.pdf`** (η ακριβής φόρμα που αναπαράγουμε),
`docs/aade/myDATA_API_Documentation_DeliveryNote_v2.0.1_preofficial.md` (ΔΑ lifecycle),
`app/Services/Delivery/DeliveryNotePdf.php` (το PDF pattern που αντιγράφουμε),
`FEATURES.md §Ψηφιακό ΔΑ`._
