# ΕΡΓΑΝΗ ΙΙ — Ψηφιακή Κάρτα Εργασίας + Άδειες (Φάση 0: έρευνα + σχέδιο)

> **Status: DESIGN / NOT-STARTED** (έρευνα 2026-09-25). Κανένας κώδικας ακόμα.
> Πηγή αλήθειας για το API: **`ERGANI_II_Odigos_Dialeitourgikotiton_2025-04-15.pdf`** (+ `.md` εξαγωγή κειμένου).

## 1. Μας αφορά; — ΟΧΙ ακόμα (υποχρέωση), ναι ως «έτοιμοι + εσωτερική χρησιμότητα»

- Ένταξη = **κύριος ΚΑΔ στο TAXIS** (εγκύκλιος 25291/23-09-2026). MyIP ΟΕ: κύριος **63.10.12.00 «Υπηρεσίες
  ιστοφιλοξενίας»**.
- Φάση 1 (ΥΑ 15441/2026, ΦΕΚ Β' 3051/02.06.2026, κυρώσεις **12/10/2026**): ΚΑΔ **61**, 78, 81, 86, 96. Ο «Π8 Τηλεπικοινωνίες,
  προγραμματισμός, υπηρεσίες πληροφορίας» είναι ο τίτλος του τομέα — περιέχει **μόνο 61.x**.
- Φάση 2 (ΥΑ 18047/2026, ΦΕΚ Β' 3791/29.06.2026, κυρώσεις **16/11/2026**): 36, 37, 38, 52.1, **70**, **73**, **82**, 92, 95.
  Οι «συμβουλευτικές υπηρεσίες» = 70.x (διοίκησης), **όχι** 62.20.
- Παλαιότεροι κλάδοι (πίνακας ως 19/05/2026): κανένας 60/62/63.
- ⇒ Με βάση τους πίνακες (όπως αναπαράγονται σε 4–5 λογιστικά sites — **δεν διαβάστηκαν τα ίδια τα ΦΕΚ**) η MyIP **δεν
  είναι υπόχρεη**. Ο λογιστής είπε «Νοέμβριο» (πιθανώς 62.20 ↔ «συμβουλευτικές») — ζητήθηκε η πηγή του.
- **✅ ΕΠΙΒΕΒΑΙΩΘΗΚΕ από το ίδιο το ΕΡΓΑΝΗ (trial, 2026-09-25):** `EX_BASE_01` («Στοιχεία εργοδότη») για ΑΦΜ 800561849 →
  **`IsInCardSector: "0"`**. Ένα παράρτημα (`Aa: 0`, Κανάρη Ξάνθη). Ξανατρέξε το περιοδικά (αλλάζει όταν ενταχθεί ο ΚΑΔ).
- Η τάση είναι επέκταση σε όλο και περισσότερους κλάδους → πιθανή ένταξη 62/63 αργότερα. Ισχύει για **κάθε** αριθμό
  μισθωτών (κανένα κατώφλι)· εκτός: εταίροι ΟΕ, διευθυντικά στελέχη, ημέρες τηλεργασίας.

## 2. Το API (σύνοψη του επίσημου οδηγού)

| | |
|---|---|
| Trial REST | `https://trialv2eservices.yeka.gr/WebServicesAPI/api/` (UI: `…/WebservicesAPIUI/`) — έντυπα φέρουν «ΑΚΥΡΟ» |
| Prod REST | `https://eservices.yeka.gr/WebServicesAPI/api/` (⚠ από SDKs/πρακτική — **όχι** στον οδηγό· επιβεβαίωσε) |
| Auth | `POST Authentication` `{Username, Password, Usertype}` → `accessToken` (3h) + `refreshToken` (7d). ⚠ Με κωδικούς **e-ΕΦΚΑ** δουλεύει **`Usertype:"01"`** (το `"02"` του οδηγού = κωδικοί «ΕΡΓΑΝΗ» → 401) |
| Refresh | `POST Authentication/Refresh` `{AccessToken, RefreshToken}` · Logout `POST Authentication/Logout` |
| Rate limit | **429** — ο οδηγός (Παρ. ΙΙ) ρητά απαγορεύει νέο token ανά κλήση → **cache το token** (per tenant) |
| Λίστα εντύπων | `GET Lookup/Submissions` · σχήμα `GET Documents/{code}` |
| Υποβολή | `POST Documents/{code}` → `200 [{id, protocol:"ΕΥΣ92", submitDate:"04/05/2022 01:13"}]` / `400 {message}` |
| Ανάκληση | `POST Documents/CancelSubmittedDocument` `{TypeOfDocument, Protocol, SubmittedDate:yyyymmdd}` — **μόνο Άδειες** |
| PDF εντύπου | `GET Documents/{code}?protocol=…&submittedDate=yyyymmdd` → base64 |
| Services | `GET WebServices/ServicesList` · `POST WebServices/ExecuteService {ServiceCode, Parameters:[…]}` (`EX_BASE_01` εργοδότης, `EX_BASE_02` παραρτήματα, `EX_BASE_04` μηνιαία κατάσταση) |

**Κάρτα — `WRKCardSE`** (§2.2.1, πλήρως τεκμηριωμένο):
```json
{"Cards":{"Card":[{"f_afm_ergodoti":"…","f_aa":"0","f_comments":"…",
  "Details":{"CardDetails":[{"f_afm":"…","f_eponymo":"…","f_onoma":"…",
    "f_type":"0","f_reference_date":"2026-10-12","f_date":"2026-10-12T08:58:03+03:00","f_aitiologia":null}]}}]}}
```
`f_type` 0=προσέλευση 1=αποχώρηση · `f_aa` = Α/Α παραρτήματος · `f_aitiologia` μόνο σε **εκπρόθεσμη** υποβολή
(από SDK: `001` διακοπή ρεύματος, `002` βλάβη συστημάτων εργοδότη, `003` μη διαθεσιμότητα ΕΡΓΑΝΗ).

**Κωδικοί εντύπων — ΕΠΙΒΕΒΑΙΩΜΕΝΟΙ** από `Lookup/Submissions` στο trial (36 έντυπα· αυτούσια στο
`schemas/Lookup_Submissions.json`): `WRKCardSE` κάρτα · **`WTOLeave`** άδειες / **`WTOLeaveC`** ορθή επανάληψη (οι
μόνες που ανακαλούνται μέσω API) · **`WTOOv`** υπερωρίες (όχι `OvTime` του SDK — αυτό είναι το παλιό Ε8) · `WTODaily`/`WTOWeek`
ωράριο · `34` = Ε11 ετήσια κανονική άδεια. Κωδικοί άδειας (§4 οδηγού): `ΑΔΚΑΝ` κανονική, `ΑΔΑΣ` ασθένεια, `ΑΔΑΑ` άνευ
αποδοχών, `ΑΔΓΑΜ` γάμου, … ωροάδειες `ΩΑ*`. Ανάλυση ημέρας: `ΕΡΓ`, `ΤΗΛ` (τηλεργασία), `ΑΝ` (ρεπό), `ΜΕ`.

**Σχήματα** (`GET Documents/{code}` → `{title, json, propertiesInfo[restrictions]}`), αυτούσια στο `schemas/`:
- **`WTOLeave`**: `WTOS.WTO[]{f_aa_pararthmatos, f_rel_protocol, f_rel_date, f_comments, f_from_date, f_to_date,
  Ergazomenoi.ErgazomenoiWTO[]{f_afm, f_eponymo, f_onoma, f_date, ErgazomenosAnalytics.ErgazomenosWTOAnalytics[]{f_type
  (π.χ. ΑΔΚΑΝ), f_from, f_to, f_year, f_req_days}}}`. Ημερομηνίες **`dd/mm/yyyy`**, ώρες `HH:MM`, `f_req_days` 3 ψηφία.
- **`WTOOv`** (✅ υλοποιήθηκε — `OvertimeService`): ίδιο σχήμα χωρίς `f_year`/`f_req_days`. **Επαληθεύτηκε στο trial
  2026-09-26:** `f_type` **`ΥΠ`** δεκτό (πρωτ. `ΑΚ - ΟΡ…`)· slot που έχει ήδη ξεκινήσει → **400 «Η υποβολή σας θεωρείται
  εκπρόθεσμη»** → το ekdosi το απορρίπτει πριν καλέσει. Δεν ανακαλείται μέσω API (μόνο `WTOLeave`/`WTOLeaveC`).
- **Παράμετροι services:** `Parameters: [{ParameterName, ParameterValue}]` (επαληθευμένο — ΟΧΙ `Name`/`Value`).
- **`EX_BASE_07` (ημερολόγιο πραγματικής απασχόλησης) / `EX_BASE_08` (τρέχουσα ψηφιακή οργάνωση χρόνου)** — δοκιμή στην
  **Παραγωγή 2026-09-26** (μόνο ανάγνωση), `PararthmaAa=0`, `Date=dd/mm/yyyy`: **400 «Criteria doesn't meet requirements»**
  (και στο trial). Η μορφή είναι σωστή (`20260925` → «Parameter Date Invalid Form»· ISO με ώρα → «should contain 2
  arguments»), άρα το 400 είναι **επιλεξιμότητα**: οι υπηρεσίες αφορούν την ψηφιακή οργάνωση χρόνου — εργοδότες στην κάρτα
  (MyIP: `IsInCardSector=0`). Ο φύλακας `ergani:watch` ειδοποιεί όταν αλλάξει. `EX_BASE_04` → «Service Code is not
  authenticate to specific User».
- **Services** (`schemas/ServicesList.json`): `EX_BASE_05` τρέχον δυναμικό (`afm` προαιρετικό) → **import εργαζομένων**
  (✅ υλοποιήθηκε — `ErganiEmployeeImporter`). **Επαληθεύτηκε στην Παραγωγή 2026-09-25** (μόνο ανάγνωση): απάντηση
  `{"EX_BASE_05":{"Cur":[…]}}`, ανά εργαζόμενο `afm`, `Eponimo`, `Onoma` (κεφαλαία χωρίς τόνους), `PararthmaAa`, `DateFrom`
  (ISO με offset), ΚΑΙ ευαίσθητα (`Amka`, `AmIka`, `ArTaytotitas`, `Dieythinsi`, `Apodoxes`, `BirthDate`, …) — **δεν τα
  αποθηκεύουμε**. Στο trial η λίστα είναι κενή. Ίδιοι κωδικοί e-ΕΦΚΑ δουλεύουν και στην Παραγωγή (URL επιβεβαιώθηκε)· `EX_BASE_07/08` ημερολόγιο/ωράριο (`PararthmaAa`, `Date` = `dd/mm/yyyy`) → για MyIP «Criteria doesn't meet
  requirements» (πιθανώς επειδή εκτός κλάδου κάρτας).

Αναφορά κώδικα (όχι dependency): `withlogicco/ergani-python-sdk` (MIT) — `ergani/client.py`, `models.py`, `utils.py`.

## 3. Τι χτίζουμε (MVP) — «ό,τι γίνεται μέσα στη μέρα, στο γραφείο»

Ο **λογιστής κρατά** ωράρια/προγράμματα. Το ekdosi κάνει μόνο:

1. **Εργαζόμενοι** (`employees`, tenant-scoped): ΑΦΜ, επώνυμο/όνομα (κεφαλαία όπως στο ΕΡΓΑΝΗ), παράρτημα `f_aa`,
   ενεργός, optional σύνδεση με `users` (για self-service). ΟΧΙ μισθοδοσία.
2. **Κάρτα**: endpoint `POST /ergani/scan` που δέχεται **token από περιστρεφόμενο QR** (HMAC, ~30s, σε tablet στο γραφείο
   — απόδειξη φυσικής παρουσίας) + ταυτότητα εργαζόμενου· + κουμπί «Είσοδος/Έξοδος» στο panel για τον διαχειριστή.
   Τύπος (0/1) = αυτόματος από το τελευταίο event της ημέρας, με override.
3. **Αποστολή** σαν `mydata_marks`: `work_card_events` **append-only** (request/response JSON, `protocol`, `submitDate`,
   status) → job στην ουρά → `WRKCardSE`. Retry/backoff· **ειδοποίηση καμπανάκι στα ~10'** αν δεν πέρασε (όριο 15')·
   μετά το 15λεπτο → εκπρόθεσμη με `f_aitiologia`. Γραμμή στο `ops:health` («εκκρεμή χτυπήματα»).
4. **Credentials ΕΡΓΑΝΗ ανά tenant**, κρυπτογραφημένα (όπως τα myDATA στο `companies`) + `ergani:set-credentials --test`.
   Token cache ανά tenant (όχι νέο login ανά κλήση — 429). Jobs μέσα σε `CompanyContext::actAs`.
5. **Υπερωρία** — το μόνο «κουμπί δήλωσης» στο MVP (απρόβλεπτη μέσα στη μέρα, πρέπει **πριν**)· αν το payload (§2)
   αποδειχθεί βαρύ, πρώτη εκδοχή = «ειδοποίηση λογιστή» (email) αντί για API.

## 4. Άδειες / ημερολόγιο — το πραγματικό σημερινό πρόβλημα

Σήμερα: κοινόχρηστο Google Calendar «MyIP – Adeies» → **ξεχνάμε να ενημερώσουμε τον λογιστή** → στο ΕΡΓΑΝΗ φαίνεται ότι
δουλεύει κάποιος που είναι σε άδεια, και μετά δεν ξέρουμε πότε δόθηκε ποια άδεια. Άρα το ημερολόγιο πρέπει να γίνει
**η πηγή αλήθειας που ενημερώνει μόνη της** τον λογιστή/ΕΡΓΑΝΗ — όχι ένα ακόμα ημερολόγιο.

- **Αίτημα → έγκριση**: ο εργαζόμενος (ή ο διαχειριστής) καταχωρεί άδεια (από–έως, τύπος `ΑΔΚΑΝ`/`ΑΔΑΣ`/…) → ο
  διαχειριστής εγκρίνει.
- **Με την έγκριση**: (α) **αυτόματο email στον λογιστή** με τα στοιχεία (δουλεύει από την 1η μέρα, χωρίς API)·
  (β) αργότερα/προαιρετικά **υποβολή στο ΕΡΓΑΝΗ** (έντυπο Άδειες, `wtoHoliday_v2`) — ανακαλείται μέσω API
  (`CancelSubmittedDocument`), άρα λάθη διορθώνονται. Ποιος υποβάλλει (εμείς ή ο λογιστής) = απόφαση ιδιοκτήτη.
- **Ορατότητα**: **ICS feed** ανά tenant (subscribe σε Google Calendar/Thunderbird) → η εικόνα «ποιος λείπει» μένει
  εκεί που τη βλέπουμε σήμερα, αλλά πηγή = ekdosi.
- **Υπόλοιπο κανονικής άδειας** ανά εργαζόμενο/έτος (δικαιούμενες − ληφθείσες) → «πότε να δώσουμε άδεια».
- Η κάρτα (όταν υπάρξει) ξέρει ποιος είναι σε άδεια → ο ημερήσιος έλεγχος δεν τον βγάζει «χωρίς χτύπημα».

Αυτό έχει αξία **ανεξάρτητα** από την υποχρέωση κάρτας → προτεινόμενη σειρά: **Άδειες πρώτα**, κάρτα μετά.

## 5. Φάσεις

1. ~~**Φάση 0.5 — trial access**~~ ✅ 2026-09-25: auth (`Usertype 01`), `IsInCardSector=0`, κωδικοί εντύπων, σχήματα
   στο `schemas/` (μόνο GET — **καμία υποβολή**). Το συνοδευτικό zip δεν χρειάστηκε (τα σχήματα έρχονται από το API).
2. ✅ **Φάση 1 — Εργαζόμενοι + Άδειες** (έγκριση, email λογιστή, ημερολόγιο, υπόλοιπο, τοπικές αργίες, ρόλος `ergani`). Χωρίς ΕΡΓΑΝΗ API· ICS feed → BACKLOG.
3. **Φάση 2 — ΕΡΓΑΝΗ client + κάρτα** (panel κουμπί + αποστολή + ιστορικό + ειδοποίηση), validated στο trial.
4. **Φάση 3 — QR tablet + σελίδα προσωπικού** (κινητό).
5. ✅ **Φάση 2 (έγινε νωρίτερα) — υποβολή Αδειών στο ΕΡΓΑΝΗ** (`LeaveErganiSubmitter`, §7)· μένει **υπερωρία** + ημερήσιος έλεγχος ασυμφωνιών (ωράριο ↔ χτυπήματα ↔ άδειες).

## 6. Αποφάσεις (ιδιοκτήτης, 2026-09-25)

- **Υποβολή αδειών**: στο ΕΡΓΑΝΗ φτάνει το ίδιο έντυπο (`WTOLeave`) όποιος κι αν το υποβάλει. Το ekdosi υποβάλλει
  (μετά την έγκριση) και **ενημερώνει τον λογιστή με email** (αριθμός πρωτοκόλλου) ώστε να **μην** το ξαναδηλώσει —
  μία πηγή, όχι διπλή υποβολή. Μέχρι να δοκιμαστεί στο trial: μόνο email.
- **Self-service**: ό,τι βολεύει → **panel user με περιορισμένο ρόλο `employee`** (επαναχρησιμοποιεί auth/roles/tenancy·
  βλέπει μόνο «Οι άδειές μου» + «Κάρτα»).
- **Ημερολόγιο**: το απλούστερο → **ημερολόγιο μέσα στο ekdosi** (σελίδα panel, μήνας × εργαζόμενοι)· το ICS feed
  μένει προαιρετικό extra. Το Google Calendar «MyIP – Adeies» σταματά να είναι η πηγή.
- **Κωδικοί trial**: e-ΕΦΚΑ λογαριασμός του ιδιοκτήτη — **ΟΧΙ στο repo/docs**· θα μπουν κρυπτογραφημένοι ανά tenant
  (`ergani:set-credentials`), όπως τα myDATA.

## Πηγές
- Εγκύκλιος 25291/23-09-2026 — https://www.taxheaven.gr/circulars/55399/25291-23-09-2026
- ΚΑΔ φάσης 2 — https://www.taxheaven.gr/news/73985/pshfiakh-karta-ergasias-nea-apofash-me-kad-gia-entaxh-apo-29-ioynioy-2026
- ΚΑΔ φάσης 1 — https://www.pim.gr/enimerosi/ergasiaka/arthra/entaksi-neon-kladon-stin-psifiaki-karta-ergasias-epikairopoiisi-kad-i-apofasi-sto-fek
- Πλήρης πίνακας ΚΑΔ — https://germanlis.gr/psifiaki-karta/pinakas-kad/
- Οδηγός API (PDF) — https://static-ypakp-gr-gefufeabdmg3ggcs.a01.azurefd.net/staticfiles/trialv2/
- SDK αναφοράς — https://github.com/withlogicco/ergani-python-sdk

## 7. Επαληθευμένα στο δοκιμαστικό ΕΡΓΑΝΗ (2026-09-25)

Φανταστικός εργαζόμενος «ΔΟΚΙΜΑΣΤΙΚΟΣ ΥΠΑΛΛΗΛΟΣ», ΑΦΜ 123456783, «προσλήφθηκε» ΜΟΝΟ στο trial (`WebE3N`, ΑΚ - ΑΠ3904) — για
end-to-end δοκιμές. Τα trial δεν έχουν τους πραγματικούς εργαζόμενους (EX_BASE_04/05 κενά) → χωρίς πρόσληψη: «Δεν υπάρχει
σχέση εργασίας στις ημερομηνίες…».

- **WTOLeave**: μία `ErgazomenoiWTO` εγγραφή **ανά ημέρα** (`f_date`)· το ΕΡΓΑΝΗ καταγράφει ΜΟΝΟ τις ημέρες που στέλνεις (δεν
  ελέγχει ότι καλύπτουν το εύρος). `f_req_days` = **δικαιούμενες** ημέρες (3 ψηφία, «020»). Απάντηση
  `200 [{"id":"359880","protocol":"ΑΚ - ΟΡ359880","submitDate":"25/09/2026 11:47"}]` (το πρωτόκολλο έχει κενά).
- **CancelSubmittedDocument**: `{"TypeOfDocument":"WTOLeave","Protocol":"ΑΚ - ΟΡ359881","SubmittedDate":"20260925"}` →
  `200 "Η ακύρωση ολοκληρώθηκε επιτυχώς"` (γυμνό JSON string). Ο τύπος = το κείμενο (όχι «84»)· για `WTOLeaveC` → «WTOLeaveC».
  Ημερομηνία ΜΟΝΟ `yyyymmdd`. Ξανά-ακύρωση → `400 No objects found`.
- **PDF**: `GET Documents/WTOLeave?protocol=…&submittedDate=yyyymmdd` → `{"message":null,"document":"<base64>"}`.
- **WTOLeaveC** (ορθή επανάληψη): `f_rel_protocol` + `f_rel_date` (dd/mm/yyyy) του αρχικού· **αντικαθιστά** το αρχικό (το
  αρχικό γίνεται «No objects found»)· ακύρωση του C ΔΕΝ επαναφέρει το αρχικό. (Δεν χρησιμοποιείται ακόμα.)
- **WebE3N** (πρόσληψη, μόνο για δοκιμές): σειρά πεδίων = XSD sequence (το GET template έχει λάθος σειρά γύρω από
  `f_kyria_asfalisi`/`EpikourikiSelections`)· code lists μέσω `EX_BASE_03` με `Parameter` = Sepe, Oaed, Stakod,
  KallikratisKoinothta, Doy, Step92, Nationality, TyposTaytotitas, EpipedoMorfosis, WorkTimeType …
- Τα μηνύματα σφάλματος περιέχουν literal `\n` μεταξύ γραμμών.

### WRKCardSE (κάρτα) — επαληθευμένο στο trial (2026-09-25)
- Γίνεται δεκτή ΚΑΙ για εργοδότη εκτός κλάδου (`IsInCardSector=0`) — αρκεί ο εργαζόμενος να είναι δηλωμένος «με ένδειξη
  κάρτας» (`WebE3N.f_working_card = 1`)· αλλιώς `400 «Χωρίς Ένδειξη Κάρτας Εργασίας.»`.
- Δεύτερος φανταστικός εργαζόμενος στο trial για κάρτες: «ΚΑΡΤΑΣ ΔΟΚΙΜΗ», ΑΦΜ 234567897 (ΑΚ - ΑΠ3909, `f_working_card=1`).
- `f_date` = ISO με ms + offset (`2026-09-25T21:16:00.231+03:00`), `f_reference_date` = `Y-m-d`, `f_type` 0/1,
  `f_aitiologia` null ή κωδικός (001 ρεύμα · 002 συστήματα εργοδότη · 003 ΕΡΓΑΝΗ) όταν > 15'.
- Απάντηση `200 [{"id":"5817437","protocol":"ΑΚ - ΚΑΡ575589","submitDate":"25/09/2026 21:16"}]`. Η κάρτα ΔΕΝ ανακαλείται.

### Tablet «ρολόι» (κάρτα χωρίς κινητό/login)
- Ο admin συνδέεται ΜΙΑ φορά στο tablet → «Σημείο κάρτας» → «Ενεργοποίηση αυτής της συσκευής» (όνομα) → αποσύνδεση. Η
  συσκευή κρατά httpOnly cookie με τυχαίο token (στη βάση μόνο SHA-256, `work_card_kiosk_devices`), κυλιόμενο ~400 ημέρες.
- `/card-kiosk` είναι κοινό URL — την εταιρεία την ξέρει η ΣΥΣΚΕΥΗ. Χωρίς ενεργή συσκευή: μόνο οδηγία ενεργοποίησης
  (κανένα όνομα/QR). Μία συσκευή = μία εταιρεία.
- Χτύπημα: όνομα → PIN (4–6, hash) → WorkCardService::punchWithPin (source «kiosk»). Κλείδωμα εργαζομένου 15'·2^(n−1)
  (έως 24h) ανά 5 λάθη· παύση ΜΟΝΟ του tablet μετά από 20 λάθη/15' (+ καμπανάκι admin).
- Επανενεργοποίηση του πυλώνα ΕΡΓΑΝΗ επαναφέρει τις (μη ανακλημένες) συσκευές — για «σβήσιμο» → «Απενεργοποίηση όλων».
