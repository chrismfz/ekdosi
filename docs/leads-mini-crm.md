# Leads / mini-CRM — αναλυτικό design (pre-build)

> **STATUS: DESIGN — αποφάσεις κλειδωμένες (2026-09-01), έτοιμο για L0 όταν ανοίξει χώρος.**
> Πλάνο + ανάλυση για να «υπάρχει κάπου» μέχρι να κλείσουν τα audits / bug-fix sessions. Twin των `docs/domains/README.md` /
> `docs/payment-connectors.md` (design-first, gates, build-όπου-θες).
>
> **Γείωση:** κάθε αναφορά σε υπάρχον ekdosi symbol παρακάτω είναι πραγματική (ελέγχθηκε
> στο tree 2026-09-01). Ό,τι είναι ΝΕΟ σημειώνεται ρητά.

---

## 0. TL;DR

Ένα **`Leads`** entry στο μενού δίπλα στους Πελάτες: υποψήφιοι πελάτες με **όσα στοιχεία έχουμε**
(μόνο η επωνυμία υποχρεωτική), ένα **χρονολόγιο επαφών** (append-only γραμμές: τηλέφωνο / email /
ραντεβού / σημείωση, με ημερομηνία, ποιος, τι ειπώθηκε, τι έπεται), **κατάσταση** (νέο → επικοινωνία →
ενδιαφέρον → προσφορά → **κερδισμένο** / χαμένο / μην ξαναενοχλήσετε), **«Μετατροπή σε πελάτη»** που
δημιουργεί τον `Customer` και κρατά τον **αμφίδρομο δεσμό** (ο πελάτης «θυμάται» από ποιο lead ήρθε,
ποιος τον έφερε, πότε), και μία **σελίδα απολογισμού ανά χειριστή/εβδομάδα** (πόσα τηλέφωνα, πόσα
emails, πόσες μετατροπές) — η απάντηση στο «δούλεψε ο άνθρωπος;». Πατά σε ό,τι ήδη υπάρχει
(`BelongsToCompany`, `TracksActivity`, `HasInternalNotes`, `HasTags`, `HasAttachments`, Quotes χωρίς
πελάτη, DB-notification reminders). **Keep it simple:** ρόλος = ο υπάρχων `operator`, όλοι βλέπουν
όλα, ελεύθερη επεξεργασία, μόνο χειροκίνητη καταχώρηση. **Ό,τι ΔΕΝ είναι πωλήσεις (email sync,
kanban, lead scoring, shared `Contact`) μένει εκτός v1.** Χτίζεται σε **4 gates L0–L3**.

---

## 1. Πλαίσιο & scope

**Γιατί:** αποκτούμε άτομο που «κυνηγάει» πελάτες. Θέλουμε (α) να μη χάνουμε ποιος βρέθηκε / τι
ειπώθηκε / πού καταλήξαμε, (β) να **μην ξαναζαλίζουμε** όποιον είπε όχι ή είναι ήδη πελάτης,
(γ) όταν κάποιος γίνει πελάτης να ξέρουμε **από πού ήρθε**, (δ) να βλέπουμε remote **τι έγινε
σήμερα / αυτή την εβδομάδα** ανά χειριστή.

**Τι ΕΙΝΑΙ:** mini-CRM «pre-customer». Ο `Lead` ζει ΜΕΧΡΙ τη μετατροπή· μετά η αλήθεια είναι ο
`Customer` (ledger, παραστατικά, επαφές) και ο lead γίνεται read-only ιστορικό με link.

**Τι ΔΕΝ είναι:** δεν αγγίζει money-path, myDATA, παραστατικά. Δεν αντικαθιστά τις
`customer_contacts`. Δεν συγχρονίζει mailbox. Δεν είναι pipeline με drag-drop (v1).

**Κλειδωμένες αποφάσεις (Q&A με τον ιδιοκτήτη 2026-09-01 — βλ. §9):**

| Θέμα | Απόφαση |
|---|---|
| Οντότητα | Ξεχωριστός πίνακας `leads` (ΟΧΙ flag `is_lead` στους `customers`) — οι πελάτες έχουν money-semantics (Καρτέλα, receivables, sync από myDATA) που ένας υποψήφιος δεν πρέπει να «μολύνει» |
| Χρονολόγιο | Ξεχωριστός πίνακας `lead_activities` (ΟΧΙ reuse του `notes`) — θέλει τύπο/κατεύθυνση/αποτέλεσμα/ημερομηνία για να βγαίνει το reporting· τα free-form `HasInternalNotes` μένουν ΚΑΙ αυτά διαθέσιμα |
| Δεσμός lead↔customer | Μία στήλη `leads.converted_customer_id` (unique) — ίδιο μοτίβο με `quotes.converted_invoice_id` / `Invoice::convertedFromQuote()`: μία εγγραφή, δύο κατευθύνσεις, καμία νέα στήλη στους `customers` |
| Μετατροπή | Action `ConvertLeadToCustomer` σε transaction, idempotent· ή **σύνδεση με υπάρχοντα** πελάτη αν ταιριάζει ΑΦΜ (ποτέ διπλός πελάτης) |
| Ρόλος | **Ο υπάρχων `operator`** — κανένας νέος ρόλος. Leads = ένα ακόμα resource στο `OPERATOR_PERMISSION_MAP` |
| Reporting | Read-only page «Απολογισμός πωλήσεων» πάνω στο `lead_activities` (group by user × εβδομάδα) — ΟΧΙ νέος πίνακας |
| Reminders | `leads.next_action_at` + scheduled `leads:notify-due` → Filament DB notification στον `assigned_user_id` (μοτίβο `invoices:notify-overdue` / `ai:dispatch-reminders`) |
| Immutability | **Ελεύθερη** επεξεργασία/διαγραφή γραμμών χρονολογίου (καμία policy) — η λογοδοσία καλύπτεται από το `TracksActivity` (ποιος άλλαξε/έσβησε τι φαίνεται στο «Ιστορικό» + `ActivityFeed`) |
| Ορατότητα | **Όλοι βλέπουν όλα** τα leads του tenant — κάποιος συμπληρώνει επικοινωνία πάνω σε lead άλλου όταν λείπει/είναι σε άδεια. Το `assigned_user_id` είναι πληροφορία, όχι φραγή |
| Εταιρείες | **Παντού** (multi-tenant, `company_id`) — χωρίς toggle ανά εταιρεία |
| Είσοδος | **Μόνο χειροκίνητα** — χωρίς CSV/Excel import (όχι τώρα) |

---

## 2. Data model (ΝΕΟ — 2 πίνακες + 1 στήλη)

### 2.1 `leads`
Όλα nullable εκτός `company_id`, `name`, `status`. «Δεν μας πειράζει που δεν έχουμε ΑΦΜ».

| Στήλη | Τύπος | Σημείωση |
|---|---|---|
| `id`, `company_id` | | `BelongsToCompany` (CompanyScope, όπως όλα) |
| `name` | string | Επωνυμία / όνομα — **το μόνο υποχρεωτικό** |
| `contact_person` | string | Με ποιον μιλάμε |
| `phone`, `mobile`, `email`, `website` | string | |
| `afm` | string(20) | nullable· index (ΟΧΙ unique — ένας παλιός «χαμένος» lead και ένας νέος μπορεί να συνυπάρχουν· το dedupe είναι warning, βλ. §4) |
| `address1`, `city`, `postcode`, `country` | | ίδια ονόματα με `customers` για 1:1 αντιγραφή στη μετατροπή |
| `occupation` | string | κλάδος/δραστηριότητα (ίδιο όνομα με `customers.occupation`) |
| `source` | string(30) | `cold_call` / `referral` / `website` / `event` / `existing_customer` / `other` (enum `LeadSource`) |
| `referred_by_customer_id` | FK→customers nullable | «ποιος μας τον σύστησε» — μεταφέρεται ως-έχει στο `customers.referred_by_customer_id` (υπάρχει ήδη) |
| `status` | string(20) | enum `LeadStatus` — βλ. §3 |
| `lost_reason` | string | υποχρεωτικό όταν status ∈ {lost, do_not_contact} |
| `assigned_user_id` | FK→users nullable | ο «κυνηγός» |
| `next_action_at` | datetime nullable | επόμενο βήμα (τροφοδοτεί reminders + «ληξιπρόθεσμα» φίλτρο) |
| `last_activity_at` | datetime nullable | **cache** — γράφεται μόνο από τον `LeadActivityObserver` (μοτίβο `paid_total`: όχι fillable, `forceFill`) |
| `converted_customer_id` | FK→customers nullable **unique** | ο δεσμός· `converted_at` datetime δίπλα |
| `notes` | text | γενική περιγραφή (τι θέλει, τι έχει σήμερα) |
| `timestamps`, `softDeletes` | | |

Traits: `BelongsToCompany`, `SoftDeletes`, `TracksActivity` (`loggedAttributes()` = τα business
πεδία, ΠΟΤΕ `last_activity_at`), `HasInternalNotes`, `HasTags`, `HasAttachments`.
Relations: `company()`, `assignedTo()`, `referredBy()`, `customer()` (belongsTo via
`converted_customer_id`), `activities()` (hasMany, `orderBy happened_at desc`), `quotes()` (L1).
Στο `Customer`: `originLead(): HasOne` (`leads.converted_customer_id`).

### 2.2 `lead_activities` — το χρονολόγιο
Append-only γραμμές «τι κάναμε, πότε, τι βγήκε».

| Στήλη | Τύπος | Σημείωση |
|---|---|---|
| `id`, `company_id`, `lead_id` | | |
| `user_id` | FK→users | ποιος το έκανε (null = σύστημα, π.χ. status-change από τη μετατροπή) |
| `type` | string(20) | enum `LeadActivityType`: `call` / `email` / `meeting` / `note` / `status_change` / `quote` / `converted` |
| `direction` | string(10) nullable | `outbound` / `inbound` (τηλεφωνήσαμε vs μας πήραν· στείλαμε vs απάντησε) |
| `outcome` | string(30) nullable | για `call`: `answered` / `no_answer` / `callback` / `wrong_number` / `not_interested`· για `email`: `sent` / `replied` / `bounced` |
| `happened_at` | datetime | default now, επεξεργάσιμο (καταχώρηση εκ των υστέρων) |
| `body` | text | τι ειπώθηκε / τι απάντησε |
| `meta` | json nullable | π.χ. `{from: 'new', to: 'contacted'}` για status_change, `{quote_id}` για quote |
| `timestamps` | | |

Traits: `BelongsToCompany`, `TracksActivity` (`body`, `type`, `outcome`, `happened_at`).
Observer: `saved` → `lead.last_activity_at = max(happened_at)`· αν η γραμμή έχει «επόμενο βήμα»
(πεδίο του modal, όχι στήλη) → γράφει `lead.next_action_at`.

### 2.3 `quotes.lead_id` (L1)
FK nullable. Οι προσφορές ΗΔΗ δέχονται μηδέν πελάτη (`quotes.customer_id` nullable + party snapshot
`company_name`/`vat_no`/… — βλ. `QuoteForm`), άρα ένας lead μπορεί να πάρει προσφορά ΠΡΙΝ γίνει
πελάτης. Η μετατροπή γεμίζει `customer_id` στις προσφορές του lead. Στην έκδοση προσφοράς από lead
προσυμπληρώνεται το snapshot από τον lead (ίδια `afterStateUpdated` λογική με τον πελάτη).

---

## 3. Κύκλος ζωής — `LeadStatus`

```
new ──► contacted ──► interested ──► quoted ──► won  (= converted_customer_id set)
 │          │             │            │
 └──────────┴─────────────┴────────────┴──► lost            (lost_reason)
                                        └──► do_not_contact (lost_reason, ΤΕΡΜΑΤΙΚΟ)
                          not_now ◄── οποιοδήποτε (με next_action_at = «ξαναδές το τότε»)
```

- Ελληνικές ετικέτες: Νέο · Επικοινωνήσαμε · Ενδιαφέρεται · Στάλθηκε προσφορά · **Πελάτης** ·
  Χάθηκε · Όχι τώρα · **Μην ξαναενοχλήσετε**.
- `won` γράφεται ΜΟΝΟ από το `ConvertLeadToCustomer` (όχι χειροκίνητα) — ίδια αρχή με το
  `local_status` που το γράφει ο submitter.
- `lost` / `not_now` / `do_not_contact` → `contacted` επιτρέπεται (re-open) — απλώς γράφει
  `status_change` γραμμή στο χρονολόγιο· το `do_not_contact` ζητά επιβεβαίωση στο modal.
- Κάθε αλλαγή status = αυτόματη `status_change` γραμμή στο χρονολόγιο (observer στο `Lead::updated`
  όταν `isDirty('status')`) — ώστε το χρονολόγιο να είναι η πλήρης ιστορία, όχι μόνο οι επαφές.

---

## 4. «Να μην ξαναζαλίζουμε κόσμο» — dedupe warning

Στο create/edit του lead (`afterStateUpdated` σε `afm` / `email` / `phone`) + μία φορά στο
`creating`:
1. Ψάξε `customers` (tenant) με ίδιο ΑΦΜ **ή** ίδιο email/τηλέφωνο → banner «**Είναι ήδη πελάτης:**
   Χ (link)». Αποθήκευση επιτρέπεται (μπορεί να είναι upsell) αλλά ο lead παίρνει αυτόματα
   `source=existing_customer` + `referred_by_customer_id`.
2. Ψάξε `leads` (incl. soft-deleted, incl. `lost`/`do_not_contact`) με ίδιο ΑΦΜ/email/τηλέφωνο →
   banner «**Υπάρχει ήδη ως lead:** Χ — κατάσταση *Χάθηκε* (2026-03, λόγος: …), χειριστής: Υ» με
   link. Αν είναι `do_not_contact` → **κόκκινο** και η αποθήκευση ζητά επιβεβαίωση.
3. Σε λίστα: φίλτρο «Ήδη πελάτες» (leads με match) για καθάρισμα.

Helper: `App\Services\Leads\LeadMatcher::findExisting(Lead|array): LeadMatch` (καθαρή κλάση,
unit-testable, ίδια normalisation τηλεφώνου/ΑΦΜ με ό,τι χρησιμοποιεί ο `CustomerSyncFromMyData`).

---

## 5. Μετατροπή — `App\Actions\ConvertLeadToCustomer`

Καθρέφτης του `ConvertQuoteToInvoice` (lock → validate → create → link → recompute), σε `DB::transaction`:

1. Refuse αν `converted_customer_id` ήδη set (idempotent, «έχει ήδη μετατραπεί σε πελάτη #N»).
2. **Δύο δρόμοι** (modal): (α) **Νέος πελάτης** — δημιουργεί `Customer` αντιγράφοντας
   `name/afm/address1/city/postcode/country/phone→phone1/email/occupation`, `referred_by_customer_id`
   από τον lead, `type` από ύπαρξη ΑΦΜ· αν υπάρχει ΑΦΜ προσφέρει το υπάρχον «Άντληση από ΑΑΔΕ»
   (`AadeRegistryLookup`) για ΔΟΥ/έδρα/επωνυμία. (β) **Σύνδεση με υπάρχοντα** — picker πελάτη
   (προεπιλογή: το ΑΦΜ-match του §4)· ΔΕΝ δημιουργεί τίποτα.
3. Γράφει `converted_customer_id`, `converted_at`, `status=won`.
4. Αντιγράφει tags (`taggables`) στον πελάτη· `contact_person` → μία `CustomerContact` (primary)
   αν δόθηκε. Attachments/internal notes **μένουν στον lead** (ορατά μέσω link — όχι morph re-point,
   να μη χαθεί το «πριν»).
5. `quotes.lead_id = lead` → `customer_id = νέος πελάτης` (L1).
6. Γραμμή `converted` στο χρονολόγιο (`meta.customer_id`), `TracksActivity` καταγράφει και τα δύο.
7. Επιστρέφει τον `Customer`· το UI κάνει redirect στον πελάτη με notification «Από lead #N».

**Στον πελάτη:** section «Προέλευση» στο `CustomerForm` (read-only, μόνο αν `originLead` υπάρχει):
«Ήρθε από lead **#N** (πηγή: σύσταση από Χ) · κυνηγός: Υ · πρώτη επαφή 2026-09-03 · μετατροπή
2026-09-20 (17 ημέρες, 4 τηλέφωνα, 2 emails)» + link στο πλήρες χρονολόγιο. Στη λίστα πελατών
προαιρετικό φίλτρο «Από leads».

---

## 6. UI (Filament)

**`LeadResource`** (`app/Filament/Resources/Leads/`), ungrouped top-level δίπλα στους Πελάτες
(`navigationSort` ώστε να κάτσει ακριβώς κάτω από «Πελάτες»), icon `Heroicon::OutlinedMagnifyingGlassCircle`
ή `OutlinedFunnel`, labels «Leads» / «lead» / «Leads» (ο όρος είναι ήδη ο καθιερωμένος στα ελληνικά
γραφεία — δεν μεταφράζεται).

- **Λίστα:** tabs ανά status (μοτίβο `ListCustomers`) + «Όλα» + «**Ληξιπρόθεσμα**»
  (`next_action_at < now`, όχι won/lost/dnc) + «**Αδρανή**» (`last_activity_at < now-14d`).
  Στήλες: Επωνυμία · Επαφή · Κατάσταση (badge) · Χειριστής · Τελευταία επαφή (diff-for-humans) ·
  Επόμενο βήμα (κόκκινο αν πέρασε) · #επαφών · Πηγή · tags. Φίλτρα: χειριστής, πηγή, tags, «ήδη πελάτες». Global search: `name`, `afm`, `email`, `phone`.
- **Φόρμα (create/edit):** ένα Section «Στοιχεία» (όλα optional εκτός επωνυμίας) + Section
  «Παρακολούθηση» (status, χειριστής, πηγή, σύσταση από, επόμενο βήμα, lost_reason conditional).
  Dedupe banner (§4) ως `Placeholder` live.
- **Χρονολόγιο:** `ActivitiesRelationManager` πάνω στην edit σελίδα, ταξινομημένο `happened_at desc`,
  **quick-add header actions** «📞 Τηλέφωνο» / «✉ Email» / «🤝 Ραντεβού» / «📝 Σημείωση» — κάθε ένα
  modal με προ-επιλεγμένο `type`, `direction`, `outcome`, `happened_at` (=τώρα), `body`, και
  προαιρετικό «Επόμενο βήμα στις …» που γράφει `lead.next_action_at`. Ελεύθερη επεξεργασία/διαγραφή.
  Μία γραμμή = ένα γεγονός· «στυλ calendar απλό σε γραμμές».
- **Header actions edit:** «Αλλαγή κατάστασης» (select + λόγος), «**Μετατροπή σε πελάτη**»
  (§5, hidden αν won), «Νέα προσφορά» (L1 — ανοίγει `CreateQuote` με `lead_id` + prefill).
- Reuse: `InternalNotesRelationManager`, `AttachmentsRelationManager`, `ActivityLogRelationManager`
  (ιστορικό αλλαγών) — ακριβώς όπως στο `CustomerResource::getRelations()`.
- **Dashboard widget** (μικρό, μόνο για όσους έχουν `ViewAny:Lead`): «Leads: 12 ανοιχτά · 3
  ληξιπρόθεσμα · 2 μετατροπές αυτόν τον μήνα» → link στη λίστα.

Κανένα νέο CSS utility χωρίς εγγραφή στο `resources/css/panel.css` (no-build gotcha).

---

## 7. Απολογισμός — «δούλεψε ο άνθρωπος;»

Page **«Απολογισμός πωλήσεων»** (`app/Filament/Pages/SalesActivityReport.php`, perm
`View:SalesActivityReport` — `company_admin`+ by default, δίνεται και σε operator αν θέλουμε): read-only πίνακας πάνω στο `lead_activities` +
`leads`, φίλτρο περιόδου (presets: σήμερα / εβδομάδα / μήνας — reuse των period presets του
Βιβλίου) × χειριστή:

| Χειριστής | Νέα leads | Τηλέφωνα (απάντησαν) | Emails (απάντησαν) | Ραντεβού | Προσφορές | Μετατροπές | Χάθηκαν | Ανοιχτά |
|---|---|---|---|---|---|---|---|---|

+ «Ημερολόγιο ημέρας»: λίστα γραμμών χρονολογίου της ημέρας ανά χειριστή (ό,τι έκανε σήμερα, με
ώρα). + funnel counts ανά status. + CSV export (`CsvEntityExporter`). Καθαρά aggregate queries,
κανένας νέος πίνακας. Η εβδομαδιαία σύνοψη μπορεί (L2, opt-in `EKDOSI_SCHEDULE_LEADS_DIGEST`) να
φεύγει και με email στον `company_admin` (μοτίβο backup-failure alert).

Το τενάντ-wide `ActivityFeed` πιάνει ΗΔΗ τις αλλαγές του `Lead`/`LeadActivity` μέσω `TracksActivity`
(ποιος άλλαξε status, ποιος έσβησε γραμμή) — μηδέν επιπλέον δουλειά.

---

## 8. Ρόλοι & δικαιώματα (απλά)

- `shield:generate` → `ViewAny/View/Create/Update/Delete:Lead` + `View:SalesActivityReport`. Οι
  γραμμές χρονολογίου ζουν ΜΟΝΟ μέσω του relation manager του Lead (κανένα δικό τους resource/perm).
- **`OPERATOR_PERMISSION_MAP`**: `'Lead' => ['ViewAny','View','Create','Update']` — ο κυνηγός παίρνει
  τον υπάρχοντα ρόλο `operator`, τέλος. Ο `company_admin` τα έχει όλα ούτως ή άλλως.
- Καμία policy ορατότητας/ιδιοκτησίας: το tenant scoping (`CompanyScope`) είναι το μόνο όριο.
- `assigned_user_id` picker: users με ρόλο στο tenant (query του υπάρχοντος user-role join).
- Post-deploy: `shield:generate` + `shield:sync-super-admin` (όπως κάθε νέο resource).

---

## 9. Αποφάσεις ιδιοκτήτη (2026-09-01) — κλειδωμένες

| # | Ερώτηση | Απόφαση |
|---|---|---|
| 1 | Ρόλος | **`operator`** — όχι νέος ρόλος `sales` |
| 2 | Ορατότητα leads μεταξύ χειριστών | **Όλοι βλέπουν όλα** (κάλυψη αδειών/απουσιών) |
| 3 | Ποιες εταιρείες | **Οποιαδήποτε** — multi-tenant, χωρίς toggle |
| 4 | Ονόματα καταστάσεων/πηγών | Τα προτεινόμενα· δεν είμαστε production, breaking changes/migrations δεν ενοχλούν |
| 5 | Immutability χρονολογίου | **Ελεύθερο** — καμία policy, audit μέσω `TracksActivity` |
| 6 | Bulk import (Excel) | **Όχι τώρα** — μόνο χειροκίνητη καταχώρηση |

Γενική οδηγία: **keep things simple.**

---

## 10. Gates (χτίζεται τμηματικά — σταματάς όπου θες)

| Gate | Παραδοτέο | Μέγεθος |
|---|---|---|
| **L0 — MVP** | Migrations (`leads`, `lead_activities`) · enums · `Lead`/`LeadActivity` models + observer (`last_activity_at`, auto `status_change`) · `LeadResource` (λίστα/tabs/φόρμα) · `ActivitiesRelationManager` με τα 4 quick-add · dedupe warning (§4, `LeadMatcher` + tests) · Shield perms + `OPERATOR_PERMISSION_MAP` · `FEATURES.md` §7β + CHANGELOG + `shield:generate` | 1 PR |
| **L1 — Μετατροπή** | `ConvertLeadToCustomer` (+ «σύνδεση με υπάρχοντα») · `Customer::originLead()` + section «Προέλευση» · `quotes.lead_id` + «Νέα προσφορά» από lead + prefill · feature test: convert → customer created, link both ways, idempotent, tags copied, quotes re-pointed | 1 PR |
| **L2 — Λογοδοσία** | `SalesActivityReport` page + CSV export · `leads:notify-due` (scheduled, gated flag, DB notification) · dashboard widget · προαιρετικό weekly digest email | 1 PR |
| **L3 — Προαιρετικά (όχι τώρα)** | αποστολή email ΑΠΟ τον lead (template μέσω `MailTemplateRenderer`/`TenantMailerFactory`, auto-log γραμμή `email/sent` — μοτίβο `QuoteMailLog`) · kanban όψη · AI «Βοηθός» read tool `lead_summary` (ίδιο grounding pattern) | κατά ζήτηση |

Tests που πρέπει να υπάρχουν από L0: `LeadMatcherTest` (ΑΦΜ/email/phone normalisation, match σε
customer vs lead vs dnc), `LeadStatusTransitionTest` (won μόνο μέσω action, auto status_change row),
`LeadActivityObserverTest` (`last_activity_at` cache, auto status_change row). Από L1:
`ConvertLeadToCustomerTest`. Money-consistency test **δεν επηρεάζεται** (καμία money στήλη).

---

## 11. Τι ΔΕΝ κάνουμε (σκόπιμα)

- **Όχι shared `Contact` entity** — ήδη DEFERRED στο BACKLOG («ERP-parity ideas»)· ο lead έχει
  flat `contact_person`/`phone`/`email` όπως είχε ο legacy πελάτης. Αν αργότερα γίνει το shared
  Contact, ο lead απλά αποκτά relation.
- **Όχι IMAP/email sync, όχι click-to-call, όχι lead scoring, όχι external CRM sync, όχι CSV import
  (όχι τώρα), όχι νέος ρόλος, όχι policies ορατότητας/ιδιοκτησίας.**
- **Όχι** `is_lead` flag στους `customers` (§1) και **όχι** reuse του `notes` για το χρονολόγιο.
- **Όχι** επανα-χρήση του `ai_pending_actions` reminder ως lead-reminder — είναι AI-confirm staging·
  ο lead θέλει απλό `next_action_at` + scheduled sweeper.
