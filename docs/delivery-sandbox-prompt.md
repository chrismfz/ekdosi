# Prompt — Sandbox validation Δελτίου Αποστολής (τρέξε στο VM)

Αντίγραψε το παρακάτω στο **Claude Code terminal στο VM** που τρέχει το ekdosi
(όπου ο `myip` είναι σε **sandbox** mode με dev myDATA credentials):

---

Είμαι στο VM που τρέχει το ekdosi (Laravel). Ο tenant `myip` είναι σε **sandbox**
mode με dev myDATA credentials. Θέλω να επικυρώσω **end-to-end** το feature
«Δελτίο Αποστολής / Ψηφιακή Διακίνηση» απευθείας στο AADE **dev** και να μου
δώσεις το report. Κάνε:

1. **Config check (read-only):** `php artisan mydata:preflight --tenant=myip`
   και επιβεβαίωσε ότι ο `myip` είναι σε sandbox + έχει creds.

2. **Dry-run πρώτα (ακίνδυνο, χωρίς AADE):**
   `php artisan delivery:sandbox-validate --tenant=myip`
   — φτιάχνει ένα δοκιμαστικό δελτίο και τυπώνει το XML που θα σταλεί. Δείξε μού το.

3. **Πραγματικό round-trip στο AADE dev:**
   `php artisan delivery:sandbox-validate --tenant=myip --execute`
   — κάνει ΕΚΔΟΣΗ (SendInvoices) → ΕΝΑΡΞΗ (RegisterTransfer) → ΠΑΡΑΔΟΣΗ
   (ConfirmDeliveryOutcome) → ΕΛΕΓΧΟΣ (RequestDeliveryNoteStatus) και γράφει
   report `.txt` στο `storage/app/`.

4. **Δείξε μου το report:** το path το τυπώνει το command — κάνε `cat <path>`
   και βάλ' το ολόκληρο εδώ (περιέχει τα request/response XML κάθε βήματος **και
   το νέο section «ΙΣΤΟΡΙΚΟ ΔΙΑΚΙΝΗΣΗΣ — lifecycleHistory»** με τα γεγονότα που
   επέστρεψε η ΑΑΔΕ στο RequestDeliveryNoteStatus).

5. **Ειδικά για το lifecycleHistory (το νέο feature):** στο section «ΙΣΤΟΡΙΚΟ
   ΔΙΑΚΙΝΗΣΗΣ» του report, επιβεβαίωσε ότι το dev της ΑΑΔΕ **όντως επιστρέφει**
   `lifecycleHistory` και ότι το parser-άρισμα είναι σωστό:
   - οι τύποι γεγονότων (RegisterTransfer/ConfirmOutcome/Rejection) εμφανίζονται
     με σωστή ελληνική ετικέτα·
   - το `actorVat` / `eventTimestamp` / `MARK` γεγονότος γεμίζουν·
   - το summary (όχημα/μεταφορέας ή outcome FULL/PARTIAL/NONE) βγάζει νόημα.
   Αν το section λέει «κανένα γεγονός», σημείωσέ το — μπορεί το dev να μην
   επιστρέφει history άμεσα μετά την έκδοση· σ' αυτή την περίπτωση ξανατρέξε
   λίγο αργότερα `php artisan delivery:test-lifecycle <id> --execute` (το status
   step ξανα-συγχρονίζει το history, idempotent).

6. Αν κάποιο βήμα βγάλει **FAIL / σφάλμα ΑΑΔΕ**, ξεχώρισε το ακριβές μήνυμα +
   το αντίστοιχο request/response XML, ώστε να διορθώσουμε το payload. **Αν το
   live XML του `lifecycleHistory` διαφέρει** από τα firebed stubs (διαφορετικά
   tag names/nesting), δώσε μου το raw RESPONSE XML του status βήματος ώστε να
   προσαρμόσουμε το `syncLifecycleHistory()`.

Μην σβήσεις το δοκιμαστικό δελτίο· μην αλλάξεις τίποτα άλλο στη ρύθμιση.

---

## Σημειώσεις

- **Η ΑΑΔΕ δεν θέλει προετοιμασία** — δεν προ-καταχωρείς πελάτες/προϊόντα· το
  περιβάλλον dev είναι κενό και το γεμίζει το ίδιο το παραστατικό. Αρκεί το δελτίο.
- **Το ΑΦΜ εκδότη πρέπει να ταιριάζει** με το ΑΦΜ των dev credentials (η ΑΑΔΕ
  ελέγχει ότι ο authenticated χρήστης == εκδότης του παραστατικού). Αν τα dev
  creds εκδόθηκαν για άλλο ΑΦΜ, βγάζει auth/issuer error → άλλαξε προσωρινά το
  ΑΦΜ του `myip` σε αυτό των creds.
- Το δοκιμαστικό δελτίο **καταναλώνει ένα ΑΑ** από τον ΔΑΠ counter (στο dev δεν
  πειράζει· ο τοπικός μετρητής απλώς προχωράει).
- **Γύρισε τον `myip` πίσω σε production** μόλις τελειώσεις — όσο είναι σε
  sandbox, ΟΛΗ η myDATA κίνησή του (και τα κανονικά τιμολόγια) πάει στο dev.

## Παραλλαγές

- `--cancel` στο τέλος: `php artisan delivery:sandbox-validate --tenant=myip --execute --cancel`
- Lifecycle σε δελτίο που έφτιαξες από το UI:
  `php artisan delivery:test-lifecycle <id> --execute`
- Μόνο το XML έκδοσης ενός δελτίου: `php artisan delivery:test-submit <id>`
