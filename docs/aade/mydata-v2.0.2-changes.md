# myDATA API v2.0.2 — τι άλλαξε & τι πρέπει να wire-άρει το ekdosi

**Πηγή:** επίσημη AADE προδιαγραφή v2.0.2 (Σεπτέμβριος 2026), όπως την υλοποιεί το
`firebed/aade-mydata` **5.12.0** (αναβάθμιση από 5.10.4 — PR #525).
**Επίσημα specs in-repo** (στο `docs/aade/` — το aade.gr μπλοκάρει το αυτόματο download, τα
ανέβασε ο owner):
- `myDATA_API_Documentation_v2.0.2_official_erp.pdf` **+ `.md`** (full text) — **το βασικό για εμάς**.
- `myDATA_API_Documentation_v2.0.2_official_providers.pdf` — πληροφοριακό (βλ. §0).
- Αυτό το αρχείο (`mydata-v2.0.2-changes.md`) = το **actionable delta**: τι αλλάζει + τι κάνει το ekdosi.

> **Backwards-compatible:** όλα τα νέα πεδία είναι optional· ο υπάρχων κώδικας
> παράγει το ΙΔΙΟ myDATA XML. Καμία αλλαγή ροής δεν επιβλήθηκε από την αναβάθμιση —
> μόνο ένα crash-fix (§1) + ένα test-count update.

---

## 0. ERP vs «για παρόχους» — πού κάθεται ο InvoSign

Δύο **διαφορετικά** πρωτόκολλα της ΑΑΔΕ:

- **ERP** = το ekdosi μιλά **απευθείας** στο myDATA (AADE creds). Το υλοποιεί το
  **firebed**. Είναι το doc που μας αφορά.
- **Providers (παρόχων)** = το πρωτόκολλο που ένας αδειοδοτημένος πάροχος (InvoSign,
  Epsilon…) υλοποιεί **προς την ΑΑΔΕ**. Ο InvoSign **δεν αναφέρεται ονομαστικά** —
  το doc ορίζει το AADE-facing πρωτόκολλο *που αυτός* υλοποιεί. Εμείς καλούμε το
  **δικό του** proprietary API (`docs/paroxos/research/invosign-api-reference.md`).

**Split διακίνησης στο ekdosi** (CLAUDE.md · `docs/archive/delivery-provider-split-brain.md`):

| Ενέργεια | Διαδρομή | Spec |
|---|---|---|
| **Έκδοση + Ακύρωση** ΔΑ | μέσω **παρόχου** (InvoSign) | InvoSign API + Providers |
| **Movement lifecycle** (register transfer, confirm outcome, **έλεγχος κατάστασης**, **return**) | **απευθείας myDATA** | **ERP** ← *εδώ πέφτουν τα περισσότερα νέα* |

---

## 1. Ήδη έγιναν στην αναβάθμιση (PR #525) ✅

- **`DeliveryStatus::IN_TRANSIT_RETURN` (9) — crash-fix (P1).** Πριν, status 9 από την
  ΑΑΔΕ ήταν `tryFrom()=null` → το `null` arm το έπιανε· τώρα resolve-άρει σε πραγματική
  enum τιμή, οπότε το `match($status)` στο `DeliveryLifecycleService::deliveryStateFromAade()`
  (**χωρίς default**) θα πετούσε `UnhandledMatchError` στον «Έλεγχο κατάστασης» ενός return.
  Fix: explicit `IN_TRANSIT_RETURN → 'in_transit'` + `default => null` (forward-safe) +
  totality test σε όλα τα `DeliveryStatus::cases()`.
- **`ExpenseClassificationType` +`E3_881_001–004`** (Πωλήσεις για λ/σμό Τρίτων) →
  `Codes::expenseClassTypeOptions()` 88→92· ενημερώθηκε το assert. Πρακτικά ~μηδέν για
  τους τωρινούς tenants, αλλά πλέον αναγνωρίζονται σωστά.
- **`FuelCode` +14/15/33–38** → το ekdosi **δεν** αναφέρει `FuelCode`· καλύτερο reading
  τιμολογίων καυσίμων γενικά. Καμία ενέργεια.
- **Fixed (firebed-internal): `reverseDeliveryNotePurpose` cast typo, `FuelCode` labels** →
  το ekdosi δεν διαβάζει αυτά τα πεδία → καμία ενέργεια.

---

## 2. Νέα δυνατότητα → gap → σχέδιο wiring (ανά σημείο)

### A. Ψηφιακό ΔΑ — movement lifecycle (ERP, δικό μας έδαφος)

**A1. `ConfirmDeliveryReturn` + `DeliveryReturn` + `Response::getDeliveryReturnMark()`** — *NEW.*
✅ **WIRED — Slice 1 (2026-09-12).**
Ο εκδότης δηλώνει ολοκλήρωση διακίνησης **επί επιστροφής**· η απόκριση φέρει
`deliveryReturnMark`.
- **ekdosi:** **Αυτός ήταν ακριβώς ο durable attempt-record που περίμενε το DEP-001**
  (MYD-026/PROV-002) — πλέον wired στο direct-myDATA μονοπάτι.
- **Έγινε:** `DeliveryLifecycleService::confirmReturn()` (ίδιο dispatch/persistEvent pattern με
  `registerTransfer`/`confirmDelivery`) → `CONFIRM_RETURN` γραμμή στο `delivery_marks` (κίτρινο
  badge + request/response XML) + guarded cache στήλη `delivery_notes.return_mark` (forceFill,
  δίπλα στα `transfer_mark`/`outcome_mark`) + state `in_transit → returned` (τερματικό· ο «Έλεγχος
  κατάστασης» δεν το πατάει πίσω). UI action «Δήλωση επιστροφής» στο `ViewDeliveryNote`· rehearsal
  flag `delivery:test-lifecycle --return`. Migration `2026_09_20_000001_add_return_mark_to_delivery_notes`.
- **Ακόμη TODO:** το **provider μονοπάτι (PROV-002)** — ο `confirmReturn()` καλύπτει μόνο το
  direct-myDATA (firebed) transport· ο provider seam χρειάζεται δικό του wiring σε επόμενο slice.

**A2. `DeliveryStatus::IN_TRANSIT_RETURN` (9)** — crash ήδη λυμένος (§1). Το **πλήρες
return-state** (δικό του `delivery_state` αντί για «in_transit») είναι μέρος του epic. → Slice 2.

**A3. `DeliveryEventType::CONFIRM_RETURN` / `REGISTER_TRANSFER_RETURN`** — *NEW event types.*
- **ekdosi:** `DeliveryNoteEvent::summary()` τα πιάνει στο `default` arm (no crash) αλλά
  χωρίς σωστό label/summary.
- **Wire:** labels + summaries μαζί με το return lifecycle. → Slice 2.

**A4. `RequestDeliveryNoteStatus::handleUsingQrUrl()`** — *NEW* εναλλακτικό lookup με `qrUrl`
αντί για `mark`.
- **ekdosi:** το `refreshStatus()` ρωτά με `(mark, issuerAfm)`· κρατάμε ήδη `mydata_url` (qrUrl).
- **Optional:** fallback σε qrUrl lookup όταν λείπει το numeric mark (π.χ. provider-issued
  όπου έχουμε qrUrl αλλά όχι mark). Χαμηλή προτεραιότητα. → Optional.

**A5. `TransportDetails::packingsDeclaration` (list of `PackagingDetail`)** — *NEW* optional για
δηλώσεις συσκευασιών μεταφορέα.
- **ekdosi:** χτίζει `TransportDetails` (register transfer) χωρίς αυτό· έχουμε `packaging_type`
  concept αλλά όχι την unbounded λίστα.
- **Optional:** wire στο `registerTransfer` αν οι operators το χρειάζονται. → Optional/scope.

### B. Δελτίο Ποσοτικής Παραλαβής (Receiving Note, τύποι 10.1 / 10.2) — *NEW οικογένεια*

`CancelReceivingNote`, `ReceivingNotePurpose` (1–7), `InvoiceHeader`:
`receivingNotePurpose`, `otherReceivingNotePurposeTitle`, `nonObligatedRecipient`,
`withoutDigitalTransportTracking`.
- **ekdosi:** το `Codes` **ήδη** ξέρει 10.1/10.2 ως τύπους (baked · `InvoiceTypeClassSuggester`
  · `CodeReference`) — αλλά **μόνο** για classification/reading. **ΔΕΝ** εκδίδουμε/ακυρώνουμε
  δελτία παραλαβής.
- **⚠ SCOPE DECISION πρώτα:** παραλαμβάνουν αγαθά οι tenants μας και χρειάζονται έκδοση
  ποσοτικής παραλαβής; Αν ναι → νέα ροή (issue + cancel + τα header purpose πεδία). Αν όχι
  (εγχώριες υπηρεσίες) → out-of-scope για τώρα. → Slice 4 (**gated**).

### C. Changed

**C1. `InvoiceType::supportsDeliveryNote()` → πλέον επιτρέπει 1.4 / 3.1 / 3.2 / 11.5.**
- **ekdosi:** ΔΕΝ καλούμε το firebed `supportsDeliveryNote()`· τα ΔΑ μας είναι **standalone
  9.x** documents (`DeliveryNoteSubmitter` απαιτεί `mydata_type` 9.x). Το `supportsDeliveryNote`
  αφορά **combined** τιμολόγιο-με-διακίνηση (τύποι που κουβαλούν κ ΔΑ) → δένει με το BACKLOG
  item **«Combined ΤΔΑ»**, που **δεν** έχει χτιστεί.
- **Action:** μέρος του combined-ΤΔΑ item — αν/όταν το πιάσουμε, ευθυγράμμιση της δικής μας
  λογικής τύπων με το v2.0.2 σετ. → Slice 3 (gap-analysis).

**C2. XSD v2.0.2 bundled** — firebed-internal· το ekdosi δεν κάνει in-app XSD validation →
καμία ενέργεια.

---

## 3. Προτεινόμενα slices (κάθε slice: κώδικας εδώ → **sandbox rehearsal στο dev** → merge)

Το dev έχει mydata + invosign **sandbox creds** → κάθε slice δοκιμάζεται πραγματικά με:
`php artisan delivery:test-submit`, `delivery:test-lifecycle`, `delivery:sandbox-validate`
(+ `mydata:test-submit --execute`). Κανένα slice δεν merge-άρει χωρίς πράσινο sandbox.

1. **Slice 1 — `ConfirmDeliveryReturn` / `deliveryReturnMark`** *(P1, ο λόγος που περιμέναμε).*
   ✅ **DONE (2026-09-12)** — ξεκλείδωσε DEP-001 / MYD-026 (direct-myDATA). Μικρό, καθαρά ERP, δικό
   μας (βλ. §A1). Το **provider μισό του PROV-002** μένει για επόμενο slice.
2. **Slice 2 — Return-leg lifecycle** — `IN_TRANSIT_RETURN` ως δικό του state +
   `CONFIRM_RETURN`/`REGISTER_TRANSFER_RETURN` events + labels/UI.
3. **Slice 3 — Combined ΤΔΑ / `supportsDeliveryNote` alignment** — gap-analysis + τύποι
   1.4/3.1/3.2/11.5 (μεγαλύτερο· δένει με το «Combined ΤΔΑ» BACKLOG).
4. **Slice 4 (SCOPE-GATED) — Receiving Note 10.1/10.2** — μόνο αν οι tenants εκδίδουν
   ποσοτικές παραλαβές.
5. **Optional** — `packingsDeclaration`, `handleUsingQrUrl()`.

> Οι υπόλοιπες TIER-1 delivery εργασίες (MYD-023 strict-refusal / already-cancelled, MYD-019,
> STOCK-001 follow-ups, 9.1/9.2) είναι **ανεξάρτητες** του v2.0.2 — ήταν απλώς «κρατημένες» στο
> ίδιο block· βλ. `docs/BACKLOG.md` TIER-1 #1.
