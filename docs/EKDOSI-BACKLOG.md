# Ekdosi — Backlog (τι μένει) vs Epsilon Smart

> Roadmap «τι μας λείπει vs ο ανταγωνισμός». **Εδώ μένει ΜΟΝΟ ό,τι εκκρεμεί** — ό,τι έχει γίνει ζει στο
> `CHANGELOG.md` / `FEATURES.md`. Tech-debt → `docs/BACKLOG.md`. Το «γιατί» μιας απόφασης → `docs/CLAUDE-history.md`.
> (Αρχική VERIFIED σύγκριση 16/09/2026, διασταυρωμένη με τον κώδικα — file:line.)
>
> **Προτεραιότητα:** 🔴 P0 · 🟠 P1 · 🟡 P2   ·   **Effort:** S = ώρες · M = 1–3 μέρες · L = εβδομάδα+

## ✅ Shipped (λεπτομέρειες στο CHANGELOG/FEATURES)
#2 Έσοδα ανά κατηγορία (report + widget) · #3 Έξοδα «αταξινόμητο» (Βιβλίο fallback) · #4 Ισοζύγιο Πελατών + Ειδών/Υπηρεσιών ·
#5 Dunning Φάση A · #7 Αναφορές (cache + € + Ανανέωση + empty states) · #8 Dashboard widgets (top είδη/έσοδα ανά
κατηγορία/top προμηθευτές/αξία pipeline) · #9 Καρτέλα polish v2 · #10 Ταμειακό ημερολόγιο ·
#11 Analytics ανά συμβόλαιο + retro-link.

---

## 🔴 P0

### 1. Ηλεκτρονική τιμολόγηση B2B (UBL / EN 16931 / PEPPOL BIS 3.0)
✅ **Phase 1 (παραγωγή UBL + κουμπί «Λήψη UBL» + MCP preview) — shipped.** Ο `PeppolInvoiceDocument` χτίζει
έγκυρο EN16931/PEPPOL BIS 3.0, country-agnostic (GR σήμερα, myip/nexon). myDATA filing = ήδη ΟΚ (InvoSign).
**Μένει — Phase 2 (L), η αποστολή**, που είναι **ΤΟ ΙΔΙΟ πράγμα με το `ee-peppol` (Nixpal/Estonia)**: ο UBL
builder είναι κοινός· το Phase 2 τον ανάβει για GR-send ΚΑΙ EE (σήμερα `ee-peppol` → NullSubmitter stub).
- [ ] Διερεύνηση: τι υποστηρίζει ο InvoSign σε EN16931-UBL send/receive (μπορεί να καλυπτόμαστε downstream)
- [ ] Access-Point transport + delivered/rejected ανά παραστατικό + λήψη εισερχομένων + πεδία endpoint/GLN πελάτη
- [ ] Επιβεβαίωση legal deadline (τρίτων πηγών: 1/10/2026 «λοιποί») με λογιστή/πάροχο πριν χρονοπρογραμματιστεί

---

## 🟠 P1

### 2. Έσοδα/έξοδα ανά κατηγορία — follow-ups
- [ ] Backfill ιστορικών WHMCS γραμμών (description→package→group→category)· πριν = «Αταξινόμητα»
- [ ] Bulk-assign κατηγορίας/tags στη λίστα ειδών + φίλτρο
- [ ] Ίδιο για **έξοδα** (report + dashboard widget)· bonus: MRR/churn ανά κατηγορία
- [ ] P2: WHMCS-map — «Κατηγορία ekdosi» χωρίς §8.6 → η γραμμή χάνεται (coupling)· per-row validation notice
- [ ] P2: `RevenueByCategory` — footer round ±1λεπτό vs γραμμές· name fallback `description_short?:description?:#id`·
  CSV «100,00» όταν total≤0· `header_discount_percent≥100` guard (τώρα σιωπηλά ≤0 έσοδα)

### 3. Έξοδα «αταξινόμητο» — follow-ups (προαιρετικά/P2)
- [ ] auto-rule πρόταση χαρακτηρισμού από επαναλαμβανόμενο προμηθευτή
- [ ] split ποσών ανά κατηγορία (τώρα και τα δύο paths χαρακτηρίζουν όλο το doc σε μία κυρίαρχη· μεγαλύτερο)

### 4. Ισοζύγια — follow-ups
- [ ] P2 perf: το Ισοζύγιο Πελατών κάνει 2 queries/πελάτη σε κάθε αλλαγή περιόδου (αποδεκτό· debounce/pre-agg αν χρειαστεί)
- [ ] P2: «Ισοζύγιο Ειδών/Υπηρεσιών» — προαιρετικό split «Πωλήσεις | Επιστροφές» ανά είδος (τώρα καθαρό, σαν #2)·
  SQL-aggregated variant αντί PHP materialisation αν μεγαλώσει η καρδινότητα (τώρα top-200 on-screen + «Λοιπά»)
- [ ] P2 cleanup: κοινός helper για το per-line net/gross/sign/header-discount query που μοιράζονται
  `RevenueByItem` + `RevenueByCategory` (τώρα duplicated· το drift το πιάνει το reconciliation test)

### 5. Εργασίες Είσπραξης (dunning) — Φάσεις B & Γ
- [ ] **Φάση B (M):** ενέργεια → task με ημ/νία+υπεύθυνο στο ημερολόγιο + ιστορικό επαφών στην Καρτέλα
- [ ] **Φάση Γ (S):** κλιμάκωση +7/+15/+30 με το `send_customer_statement`
- [ ] P2: δικό του `Update`-ability για το collection metadata (τώρα κάτω από `View:AgedReceivables`)

### 6. Ενοποίηση ρυθμίσεων + Wizard νέας εταιρείας — ΟΥΣΙΑΣΤΙΚΑ ΙΚΑΝΟΠΟΙΗΜΕΝΟ (P2 polish μόνο)
Το «ενιαίο settings με tabs» **υπάρχει ήδη σε δύο επίπεδα** (sweep 2026-09-17): (α) nav-level ο `SettingsCluster`
(`/settings`, 17 members σε 4 collapsible υπο-ενότητες Τιμολόγηση&πληρωμές/Είδη&αποστολή/Εταιρεία/Λειτουργία) —
βήματα 1+1.5 του `docs/menu-ia.md` = ✅ DONE· (β) form-level ο `CompanyResource` έχει ήδη `Tabs::make()`
(Identity·myDATA·Πάροχος·GSIS·PDF&Email·WHMCS·AI·…) με progressive disclosure + secret-flagging. Ο company_admin
vs super_admin διαχωρισμός είναι ήδη σωστός (`CompanySettings` whitelist vs super-admin `CompanyResource`/creds).
- [ ] P2 (κοσμετικό, deferred): «Ρυθμίσεις» landing page αντί να πηγαίνει στο πρώτο member (το μόνο ρητά deferred item του IA plan)
- [ ] P2 (χαμηλή αξία): onboarding wizard νέου tenant (`CreateCompany` = απλό CreateRecord)· σπάνιο (προσθέτεις tenant σπάνια),
  το readiness το καλύπτουν ήδη `Preflight` + `ekdosi:go-live-check`

### 7–8. Γραφήματα/Dashboard — υπόλοιπα
- [ ] **Έξοδα ανά κατηγορία widget** (βλ. #2)
- [ ] (προαιρετικό) user-customizable dashboard
- [ ] **P2 deferred (από reviews· «μαζί με το caching/charts cleanup»):**
  - version-keyed cache invalidation + scheduled warm για τα 3 νέα charts (τώρα TTL 30' χωρίς invalidation·
    φθηνή ενδιάμεση: μη-cache του άδειου αποτελέσματος)
  - shared `TopNMoneyChart` base (τα 3 charts μοιράζονται ~όλο το scaffold) + label-collision tooltip
  - header-discount reconciliation: `CustomerTopProducts::forCompany` αθροίζει net γραμμών χωρίς invoice-level
    header discount (διαφορά μόνο όταν υπάρχει· root-fix στο shared service, επηρεάζει Καρτέλα+MCP)
  - pipeline € status-priority (Accepted>Sent>Draft) αντί «τελευταία ζωντανή προσφορά»· + caching/Quote-gate αν χρειαστεί
  - SQL-aggregated variants (RevenueByCategory prior-year· `forCompany`· TopSuppliers) αντί PHP materialisation
  - dashboard revenue-visibility: τα revenue widgets δεν έχουν `canView()` (συνεπές με τα αδελφά· role-gate = dashboard-wide απόφαση)

---

## 🟡 P2

### 10. Ταμειακό ημερολόγιο (Εισπράξεων–Πληρωμών) — ✅ shipped
Νέα αναφορά «Λογιστικά» (`CashJournalReport`): ταμειακές κινήσεις πελατών/περίοδο ομαδοποιημένες **ανά λογαριασμό →
τρόπο πληρωμής**, εισπράξεις vs πληρωμές/επιστροφές + καθαρή ροή + CSV. Λεπτομέρειες στο CHANGELOG/FEATURES.
- [ ] P2 (μελλοντικό): προαιρετική συμπερίληψη εξόδων/πληρωμών προμηθευτών (τώρα μόνο `payments` — τα έξοδα στο Βιβλίο)

### 11. Χρεώσεις ανά συμβόλαιο — follow-ups
- [ ] **(config, όχι κώδικας)** «προσχέδιο N μέρες πριν τη λήξη»: υπάρχει ήδη — `services:stage-renewals
  --lead-days=N` (`EKDOSI_SERVICE_RENEWALS_LEAD_DAYS` + scheduler flag). Set στο deploy.
- [ ] proactive «κουδούνι»/email για συμβόλαια που λήγουν σε N μέρες (τώρα: nav badge 7μ + widget 30μ + auto-προσχέδιο)
- [ ] P2: inline εμφάνιση πιστωτικών στο tab «Ανανεώσεις» (δεν φέρουν `service_contract_id`· τα σύνολα τα αφαιρούν)

> **🔒 Locked — Services module για MyIP/hosting:** κρατάμε **ΕΝΑ** «Υπηρεσίες», εξέλιξη **προσθετικά**
> (service_type/κατηγορίες + ProvisioningModule drivers + addons ως child rows + progressive disclosure), **ΟΧΙ**
> clone σε «Hosting Services». Πλήρες σκεπτικό → `docs/services-module-evolution.md` (+ CLAUDE.md architectural decisions).

### 12. Έργα (projects) (L) — χαμηλή προτεραιότητα, χωρίς χρονοδιάγραμμα
Νόημα μόνο αν ένα έργο μαζεύει έσοδα+έξοδα από πολλά παραστατικά (μικτό περιθώριο). Για καθαρά recurring δεν χρειάζεται.

### 13. Πεδία χρήστη (custom fields) (S) — ιδέα για όταν προκύψει ανάγκη
Τα εσωτερικά notes καλύπτουν σήμερα.

---

## ⚪ Απορρίπτονται
| Τι | Γιατί |
|---|---|
| Αξιόγραφα / επιταγές (+ widgets) | Καμία επιταγή από το 2007 — online & μετρητά μόνο |
| Αποθήκη / απογραφή | Δεν κάνουμε εμπορία φυσικών αγαθών |
| Λιανική (Χ/Ζ, Ευκαιρίες/Εργασίες Λιανικής) | Δεν υπάρχει λιανική· στο Epsilon απλώς διπλασιάζει το μενού |
| ~120 items ρυθμίσεων του Epsilon | Εκεί γίνεται δυσκίνητο — βλ. #6 για τη σωστή εκδοχή |

## Τι έχουμε και ΔΕΝ έχει το Epsilon (να μη χαλάσουν)
Πύλη πελατών · Γέφυρα WHMCS (+ Εισερχόμενα + duplicate audit) · MRR/ανανεώσεις/`ServiceContract` · Διασταύρωση
ΑΑΔΕ + Έλεγχος ΜΑΡΚ + αδέσποτα · **E3 income classification ορατό** · Βοηθός AI με κοστολόγηση · Υγεία συστήματος +
Ενημερώσεις · Multi-tenant · Αναφορές (heatmap/εποχικότητα/πρόβλεψη/DSO/ΦΠΑ ανά συντελεστή) · CMR/φορτωτικές ·
**MCP interface** (κανένας ανταγωνιστής).

## Πηγές (legal — προς επιβεβαίωση με λογιστή/πάροχο)
ΑΑΔΕ / Forin.gr / Taxheaven / lido.app: υποχρεωτική Η/Τ B2B σε δύο κύματα (2/2/2026 τζίρος >1εκ · 1/10/2026 λοιποί).
Τρίτων πηγών — **επιβεβαίωσέ τις πριν χρονοπρογραμματίσεις το #1 Phase 2.**
