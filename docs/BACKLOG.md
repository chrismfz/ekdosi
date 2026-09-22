# Backlog / Roadmap — what's left + ideas (SINGLE SOURCE)

Ό,τι **συνειδητά δεν έχει χτιστεί ακόμα** (roadmap) + αποφάσεις που δεν ξανανοίγουμε (guardrails) + docs index.
**Κανόνας:** shipped → `FEATURES.md` + `CHANGELOG.md`, **σβήσ' το από εδώ**. Μικρά θέματα που ίσως δαγκώσουν δεν
καταγράφονται — θα βρεθούν τότε (logs / repro / MCP).
Pruned 2026-09-22 (2280→212 lines): done/minor items removed. Σχόλια κώδικα/docs που λένε «βλ. `docs/BACKLOG.md`»
για P2/tradeoff αναφέρονται στην **πριν-το-prune έκδοση**: `git show 631078d:docs/BACKLOG.md`.

---

## 🎯 Priorities

Ο κανόνας της σειράς: **η προτεραιότητα ενός finding δεν είναι ιδιότητά του — είναι finding × αυτή η επιχείρηση ×
αυτή η ημερομηνία.** Λεπτομέρειες ανά item στο «🗺️ Roadmap».

1. **Τώρα (δικό του PR):** `TRUSTED_PROXIES` + `trustHosts()` hardening (→ Security/ops).
2. **Delivery notes (ΔΑ)** — inbound inbox **4b**, μετά πλήρη **9.1 / 9.2**.
3. **Migration / money tooling:** Generic CSV importer → Bank-statement import → Dunning ladder → Cashflow /
   recurring-expenses (accountant-gated).
4. **Strategic epic «Αντικατάσταση WHMCS» → `PLAN.md`:** Domains (A4/A5) → Payment connectors → Provisioning →
   Portal transactional surfaces (largely greenfield).
5. **Parked / blocked-on-external:** PEPPOL Phase 2 (review 2027) · PROV-003 archive (InvoSign endpoint) · POS-1(c) ·
   «Εισερχόμενα από Πύλη» (ερώτημα λογιστή) · 2ος GR πάροχος.
6. **Ideas / low-commitment:** κεντρικός editor κειμένων (owner-requested) · setup profiles ανά κλάδο ·
   multi-currency · shared Contacts CRM · Bridges Phase 1 · AI Phase 2c.

---

## 🗺️ Roadmap

### 🔐 Security / ops
- **`TRUSTED_PROXIES` + Host pinning (planned as its own PR).** Το `bootstrap/app.php` διαβάζει `env()` μέσα στο
  `withMiddleware` → αγνοείται όταν είναι set μόνο στο `.env` (verified). Fix = `config/trustedproxy.php` + keyword
  `local` (loopback + `SERVER_ADDR`) + trust μόνο `X-Forwarded-For/Proto/Port`· μαζί με `trustHosts()` (το Host header
  δεν είναι pinned — reset-link poisoning υπό sync queue). Λύνει και τα throttles (`throttle:oauth`/portal login/
  webhooks) που αλλιώς κλειδώνουν στο edge IP πίσω από CDN.

### 💳 Payments / money
- **POS-1(c) — πραγματική POS διασύνδεση (ν.5073/2023).** Σύλληψη `ProvidersSignature` + `tid` + `transactionId` από το
  Cardlink/Eurobank vPOS return → πέρασμα σε `InvoSignDocument`/`AadeInvoiceDocument` ώστε να φιλάρει το type-7.
  Μέχρι τότε card/vPOS/PayPal → §8.12 **1**, 3, 6 ή 8.
- **Payment connectors — IRIS + card-POS** → `payment-connectors.md` (IRIS πρώτα· card-POS/Stripe μετά).
- **Bank-statement import → match πληρωμών** — ανέβασμα κίνησης (CSV/MT940) → auto-match σε ανοιχτά τιμολόγια
  (ποσό/ημερομηνία/ΑΦΜ) → προτεινόμενες `Payment` εγγραφές προς έγκριση.
- **Dunning ladder (τιμολόγια — σήμερα υπάρχει μόνο το `ServiceDunning`)** — κλιμακωτές υπενθυμίσεις 3/7/15/30 ημ.
  πάνω στο auto-email + `InvoiceBalance` (templates ανά σκαλί, opt-out ανά πελάτη)· ενέργεια→task με ημ/νία+υπεύθυνο
  + ιστορικό επαφών στην Καρτέλα· κλιμάκωση με το `send_customer_statement`.
- **Ταμειακή εικόνα / cashflow — «τα έξοδα που δεν έρχονται μόνα τους»** _(ιδέα 2026-07-12· **θα το δει με τον
  λογιστή πρώτα**)._ Διοικητική/ταμειακή εικόνα, **ΟΧΙ τα βιβλία του λογιστή**. Κουβάδες εξόδων: **Α.** myDATA GR
  (λυμένο, `ExpenseImporter`) · **Β.** foreign B2B (AWS/Hetzner/cPanel…, ορατά μόνο αν αυτο-δηλώνονται 14.x) ·
  **Γ.** μη-τιμολόγια (μισθοδοσία/ΕΦΚΑ/δάνεια/δώρα — ποτέ στο myDATA). Τα Β+Γ είναι κυρίως recurring. Φάσεις:
  (1) μητρώο «Πάγια / Επαναλαμβανόμενα έξοδα» → ο scheduler φτιάχνει πρόχειρο `Expense` ανά περίοδο, ο operator
  επιβεβαιώνει· (2) ημερολόγιο εποχικών (δώρα/επίδομα αδείας/ετήσιες ασφάλειες)· (3) widget «Τι μου περισσεύει»
  (Έσοδα − Έξοδα = καθαρή ροή + σωρευτικό αποθεματικό). **⚠ Κίνδυνος διπλομέτρησης:** πρότυπο «ΔΕΗ» + myDATA
  τιμολόγιο ΔΕΗ = 2× → κανόνας «το πρότυπο μετράει μόνο αν ΔΕΝ βρεθεί myDATA παραστατικό τον μήνα». Ερώτημα
  λογιστή: ποια foreign δηλώνονται ήδη. Λείπουν μόνο recurring-templates + cashflow widget + anti-double-count.

### 🚚 Delivery notes (Ψηφιακό ΔΑ)
- **Inbound «Εισερχόμενα Διακίνησης» (4b)** — inbox Resource + Reject/Refresh/Acknowledge πάνω στο υπάρχον
  direct-myDATA path (core + two-party sandbox ✅). Το 4c (qrUrl Confirm-outcome) μένει DEFERRED.
- **Πλήρη 9.1 / 9.2** — 9.1 (συσχετιζόμενο) θέλει correlated MARKs (`addCorrelatedInvoice` + επιλογή σχετικών)·
  9.2 (συγκεντρωτικό) μοντέλο σύνοψης κινήσεων. Σήμερα κρυμμένα + μπλοκαρισμένα (MYD-012) — ξεμπλόκαρε με
  προσθήκη στο `Codes::SUPPORTED_DELIVERY_TYPES` όταν χτιστεί το μοντέλο.
- **Issuer-side `confirmDelivery()` = dead-end** ([833]/[817]/[814] — το outcome είναι μόνο του παραλήπτη/μεταφορέα)
  → κρύψ' το από τη ροή του εκδότη (punch-list, TIER-1 delivery).

### 📥 Import / onboarding
- **Generic CSV importer (προϊόντα / πελάτες / supplier έξοδα)** — έχουμε CSV *export* (`CsvEntityExporter`), λείπει
  το *import*: column-map + dry-run preview + tenant-scope (+ supplier CSV → `source=import`). Το μεγαλύτερο win
  για μεταφορά καταλόγου/πελατολογίου.
- **Setup profiles + curated tax-presets ανά κλάδο** (λιανική / εστίαση / ξενοδοχείο / υπηρεσίες) — bundle σε ένα
  κλικ: invoice types + default ΦΠΑ + «πρότυπα τελών» + payment methods + withholding/Ψηφιακό Τέλος presets
  (+ %-ανά-προϊόν, όχι μόνο €/τεμ). Πάνω στο υπάρχον seeding.
- **Durable native portable key (μετά το legacy_id sunset)** — `uuid`/`public_id` ανά portable πίνακα ως ΤΟ
  idempotency key του `CompanyImporter` (uuid→legacy_id→signature) + ΑΦΜ-dedup. Μόνο για sync/merge μεταξύ ζωντανών
  ekdosi — όχι για μεταφορά-σε-VM.

### 🧾 myDATA / classification
- **Υποβολή χαρακτηρισμών για λογαριασμό τρίτου (λογιστής, `entityVatNumber` [323])** — το ekdosi **ετοιμάζει**
  τους χαρακτηρισμούς (rules-engine ✅), ο λογιστής (δικό του login + ΑΦΜ + έγκριση) τους **στέλνει**· θέλει
  διερεύνηση ρόλων/δικαιωμάτων. (+ `RequestMyExpenses` sanity totals.)
- **Type↔reason cross-check στην έκδοση** — warn/block αν η §8.3 αιτία γραμμής αντιφάσκει με τον τύπο (π.χ. αιτία 4
  σε εγχώριο 1.1)· αντίστοιχο του `assertCounterpartCountryMatchesType`.
- **Κατηγορίες εσόδων/εξόδων** — backfill ιστορικών WHMCS γραμμών (description→package→group→category) · bulk-assign
  κατηγορίας/tags στη λίστα ειδών · ίδιο report + widget για **έξοδα**.

### 🌐 PEPPOL / πάροχοι
- **PEPPOL Phase 2 — Access-Point transport + EE AP (PARKED, review 2027).** Phase 1 (UBL builder + «Προβολή/Λήψη UBL»
  + `invoice_ubl` + `peppol:test-submit`) ✅. Δεν επείγει: ViDA cross-border 2030 · Estonia 2027 (proposed) · GR
  καλύπτεται από InvoSign. ΕΝΑ AP (Telema/Billberry/Finbite/Unifiedpost) = GR+EE send — **check αν ο InvoSign κάνει
  ήδη PEPPOL send**· επιβεβαίωσε legal deadline με λογιστή. **Προϋποθέσεις:** buyer από το **frozen snapshot**
  (`counterpartAfm()/counterpartName()/counterpartCountryForFiling()` + `frozenPartyColumns()` στον PEPPOL submitter,
  όχι ζωντανός `customer`)· **9933 endpoint bare-vs-EL** — κλείδωσέ το με τον πραγματικό AP/Schematron.
  `paroxos/regulatory-blueprint.md §7`.
- **2ος GR πάροχος / SBZ** — ο InvoSign είναι live· το blueprint (`paroxos/`) μένει για δεύτερο πάροχο (θέλει creds + sandbox).
- **PROV-003 archive half (BLOCKED στον InvoSign)** — ανάκτηση + ιδιωτική αρχειοθέτηση του επίσημου PDF παρόχου
  (SHA-256, immutable, retry μόνο download, `evidence_pending`)· ξεμπλοκάρει μόνο με download/retention API.

### 🌍 Αντικατάσταση WHMCS (σταδιακή) → **`PLAN.md`**
- **Master epic** — strangler-fig (όχι big-bang· `billing_connections` επιτρέπει συνύπαρξη). Σειρά: **Domains →
  Payment gateways → Provisioning → Portal** (+ Support core ✅). Πλήρες σχέδιο + phase gates: `PLAN.md`.
- **Multi-party SPLIT write-back στο WHMCS** — ένα MARK ≠ N invoices.

### 🌐 Domains (Πυλώνας A — `docs/domains/README.md`)
- **A4 — 2ος registrar grEPP** (.gr/.ελ direct EPP· 2ετία min, no privacy/lock) — αποδεικνύει το abstraction.
- **A5 — polish + registrar↔local reconciliation** (mirror του myDATA reconcile): bulk availability, portfolio
  dashboard· **cross-tenant guard και στο READ path** (`DomainSyncService::sync` by-name adopt σε κοινό reseller
  account)· reconciler inputs: renew logs με `short_of_target=null`/κάτω από `target_expiry`, orphan unconsumed renewals.
- **Εκτός v1 (συνειδητά):** DNSSEC key management · restore billing (σήμερα χειροκίνητα) · approve-transfer/resend-FOA.

### 🛠️ Services / provisioning / portal
- **Real provisioning modules** (cPanel/Mailcow/license server) — σήμερα μόνο `NullProvisioningModule` (= Πυλώνας C).
- **Multi-line service contracts** — v1 = single-line.
- **ΠΡΟΤ στην πύλη — renew/upgrade → convert** — recurring ΠΡΟΤ (filing OFF) → ο πελάτης επιλέγει
  ανανέωση/upgrade/downgrade/ακύρωση → πληρωμή (gateway) → **convert σε νόμιμο τιμολόγιο** → mark paid.
- **Λήξη προσφοράς + cascade στην υπηρεσία** — μηχανή καταστάσεων με ημερομηνίες (`offer_expires_at`, χάρη ~1 εβδ.,
  scheduled job → suspend → terminate)· «Δεν το χρειάζομαι» = επιτάχυνση. Ανοιχτό: πληρωμή μέσα στη χάρη → ξεπαγώνει;
- **«Εισερχόμενα από Πύλη» (BLOCKED στον λογιστή)** — επιφάνεια απόφασης πάνω στα πληρωμένα προτιμολόγια (ΤΠΥ/ΑΠΥ/
  ακύρωση/αρχειοθέτηση), χωρίς staging table. Ερώτημα: πόσος χρόνος από την είσπραξη ως την έκδοση;
- **Πύλη — transactional surfaces (Πυλώνας D)** — πλήρωσε → B · domains → A · services → C, ανά πυλώνα (`PLAN.md §6`).
- **Πύλη — self-register (design locked, NOT built)** — μόνο **tier-2 CLAIM** (ΑΦΜ+email που ταιριάζει σε `customers`
  → email verification → grant εγκρίνεται από operator), ποτέ open signup. Μόνο όταν το ζητήσει tenant.
- **Multi-domain πύλη — Option B «hard scope»** — το custom host φιλτράρει docs/logins μόνο στον tenant του (αλλάζει
  feed + login-gating + edge cases πελάτη-σε-δύο-tenants).

### 🤖 AI / MCP / connectors (`ai-assistant-blueprint.md`)
- **External MCP follow-ups** — per-tenant OAuth binding (claude.ai multi-company) · `connection_health` tool
  (WHMCS/myDATA freshness).
- **Βοηθός Phase 2c (χαμηλή προτ.)** — per-company κλειδί UI (`companies.ai_api_key` + per-key billing) ·
  `ai_conversations` persistence (+ UI επιλογής) · streaming απαντήσεων (SSE/Livewire).
- **Bridges/Connectors Phase 1** — πραγματική 2η πηγή (WooCommerce/Blesta…) → `bridges-connectors.md`. **Χτίσ' το
  μόνο όταν υπάρξει πραγματική 2η πηγή** (αλλιώς το contract κουβαλά WHMCS-isms).

### 💡 UX / ERP-parity ideas
- **Κεντρικός editor κειμένων/ετικετών (ζητήθηκε 2026-09-19)** — «Ρυθμίσεις → Κείμενα/Ετικέτες» για όλα τα
  customer-facing strings (`PdfLabels::MAP`, `lang/{el,en}/mail.php`, `lang/{el,en}/portal.php`)· πίνακας
  `label_overrides` (`company_id` nullable=global) + override-layer με **fallback στα code defaults**.
- **Multi-currency invoicing** — `currency` υπάρχει (EUR hardcoded)· FX + στρογγυλοποίηση + εμφάνιση (myDATA θέλει EUR ισοτιμία).
- **Επαφές (shared CRM)** — κοινή `Contact` ↔ many customers με ρόλους (π.χ. λογιστής πολλών πελατών). DEFERRED —
  να μη σπάσει το per-customer `customer_contacts` που χρησιμοποιεί ο Sendable statement.

---

## 🧭 Guardrails — decided, don't re-open without a NEW reason

**Scope / product**
- **Απορρίπτονται:** αξιόγραφα/επιταγές (καμία από το 2007) · αποθήκη/απογραφή · λιανική (Χ/Ζ) · τα ~120 settings του Epsilon.
- **Per-product τιμοκατάλογος — DROPPED:** per-line έκπτωση + per-customer default discount αρκούν (re-open μόνο με πραγματικό use case).
- **Η/Τ B2B (κύματα 2/2/2026 · 1/10/2026) = ιστορικό:** η παραγωγή ΗΔΗ φιλάρει μέσω παρόχου (InvoSign — invoicer.myip.gr, ekdosi.nexon.gr).
- **«Μοιάζει κενό αλλά δεν είναι»:** E3 overview υπάρχει (`MyDataE3Overview`) · `TenantScopedUnique` redundant (DB unique) · stock/ΣΔΕΠ/WHMCS sentinels = dead legacy code.
- **RequestVatInfo «ΦΠΑ cross-check» + E3↔local classification diff — deferred ON PURPOSE:** μετράει το Φ2 *deductible* → μόνιμη ψεύτικη διαφορά (η ΦΠΑ picture `MyDataVatAggregator` ΕΙΝΑΙ χτισμένη).
- **Multi-branch (MYD-010):** issuer `branch=0` είναι η αλήθεια· counterpart branch per-invoice (`invoices.counterpart_branch`)· το πραγματικό fix είναι child table `customer_branches`.
- **In-app update apply = DISARMED** (`deploy/update.sh <tag>` είναι το path)· re-arm μόνο με UPD-001…004 + κοινό resolver repo/token (UI override→env) και για τα δύο μονοπάτια.
- **IA:** clusters ανά πυλώνα (βάθος, όχι πλάτος)· 2ο Filament panel μόνο αν αλλάζει το κοινό (`docs/menu-ia.md`).
- **Support:** in-app KB DROPPED (BookStack) · SLA timers = δικό τους slice · **ΟΧΙ per-department ticket dedup** (δοκιμάστηκε, revert — duplicate-on-redelivery).
- **Credit-use bell — ΑΠΟΡΡΙΦΘΗΚΕ:** η χρήση πίστωσης είναι χρήμα που ήδη έφτασε (το «Ιστορικό» έχει causer=πελάτης)· bell μόνο για νέο χρήμα από gateway.

**myDATA / provider**
- **MYD-023:** υιοθέτηση «ήδη ακυρωμένου» σε ΔΑ + πάροχο **ΠΡΙΝ** την αυστηρή άρνηση Success-χωρίς-ΜΑΡΚ-ακύρωσης (αλλιώς stranding όπως MYD-021).
- **InvoSign contact fields (`CounterpartTaxOffice/Phone/Email`) = ζωντανά by design** — όχι νομική ταυτότητα· αν χρειαστούν αναπαραγώγιμα → δικές τους snapshot στήλες.
- **ΔΑ σε εξωτερικό παραλήπτη χωρίς ΑΦΜ = ανοιχτό ερώτημα ΑΑΔΕ/λογιστή** (η σεντινέλα `000000000` είναι για ενδοδιακίνηση)· μην αυτοσχεδιάσεις.
- **Issuer name/address snapshot — deferred:** `[219]`/`[220]` απαγορεύουν issuer name στο τιμολόγιο· ξανανοίγει αν tenant αλλάξει πραγματικά έδρα.
- **Filing-policy snapshot (MYD-018) — δεν είναι μονόγραμμη:** το `mydata_type` στηρίζει fallback του `SalesReconciler` (null σε ETL rows).
- **Provider exactly-once:** ο InvoSign κάνει dedup (`recoverViaStatusCheck` wired)· ξανασήκωσέ το για πάροχο που ΔΕΝ κάνει dedup.
- **PROV-005 — κανένα authenticated probe:** κάθε InvoSign call κοστίζει credits· `ping()` = μόνο reachability.
- **PROV-009 — delivery-fail warn / quota poll = infeasible** (κανένα callback ή non-issuing endpoint).
- **PROV-019 — ΟΧΙ 5-state correction machine:** `reissued_from_invoice_id` + soft-warn· hard-block toggle μόνο αν φανεί double-turnover.
- **Gapless-at-send ΑΑ:** `release()` μόνο τον αριθμό που κράτησε ΑΥΤΟ το submit· gapless μόνο υπό serial issuance (ok στα ~70 docs/μήνα).

**Money / payments / portal**
- **Eurobank vPOS return = επαληθευμένο σε production** (myip, intent #5, 2026-09-20: canonical digest ✓, `currency` ✓, 12ψήφιο `txId` ✓)· η μοναδικότητα `txId` θεωρείται δεδομένη (αύξων μετρητής Cardlink) → αν ποτέ εμφανιστεί ψευδές `duplicate_transaction`, N-day window στο `transactionAlreadySettled()`.
- **Payment intents: το expiry ΠΟΤΕ δεν μπλοκάρει settle** (money > tidiness)· expiry = housekeeping/badge μόνο.
- **B1 declined:** κανένα auto-cancel intent σε FAILED/CANCELLED return (retry-CAPTURE στο ίδιο orderid) · δύο `connectionFor()` loaders σκόπιμα (portal refuses inactive, return `withTrashed`).
- **Gateway config keys μοιράζονται ένα `statePath('config')`** → namespace (`config.eurobank.testmode`) ΠΡΙΝ το B2 PayPal/Stripe.
- **WHMCS receipt amount = δικό μας owed**, όχι τα ευρώ του WHMCS (editable money-trail, όχι reconciliation).
- **WHMCS mapper / mass-pay υποθέτουν ΕΝΑ ΦΠΑ rate** — revisit πριν μπει reduced-rate tenant.
- **Πύλη: invited-claim vs revoked grant = by design** — login status ⟂ grants· για εξουδετέρωση → **suspend**, όχι revoke.
- **Πύλη: reseller grant βλέπει ΟΛΟ το document history** του πελάτη — σκόπιμο (ο grant ΕΙΝΑΙ η σχέση).
- **Octane readiness:** `SetPortalLocale` χωρίς locale reset + `View::share('portalCompany')` worker-global → θα διέρρεαν μεταξύ requests/tenants· fix πριν πάμε Octane (FPM = non-issue).

**Security / tenancy / secrets**
- **Legacy secrets (`/legacy/` .dfm/.cfg) — κλειστό:** τα credentials άλλαξαν και το git history καθαρίστηκε (2026-09).
- **Strict tenant scope (null→throw) — deferred:** audit 0 leaks σε ~54 entry points· το no-op default είναι load-bearing (`CLAUDE.md`).
- **Secrets μένουν super_admin**· `CompanySettings` = SAFE whitelisted subset — μην μεταφέρεις credentials μαζικά στον company_admin.
- **Declined:** tenant-slug existence oracle (404 vs 401) στο `issued-doc-pdf` — συνεπές με τα sibling webhooks, τα slugs δεν είναι μυστικά.
- **Declined (security-through-obscurity):** κανένα CSP (panel = Livewire inline· 0 `{!! !!}` στα customer views) · `X-Powered-By` (fix αν ποτέ: `expose_php=Off`).

**Deploy / ops / schema**
- **Deploy guards:** καμία override για dirty tracked tree (το `git stash` ΕΙΝΑΙ το override) · ποτέ δεν καταστρέφουμε αρχείο που δεν μπορέσαμε να αντιγράψουμε.
- **Schema baseline squash:** `migrate:rollback` = no-op για τα baseline migrations· επαναφορά μέσω `ekdosi:db-restore` snapshot· ξανα-strip τα `DROP TABLE` μετά από `schema:dump`.
- **OPS-001 cron↔worker 5-min ambiguity — DECLINED:** μην το ξαναμπαλώνεις με timing math· μόνο με 2ο ανεξάρτητο worker-liveness signal.
- **Domains A2:** επαλήθευσε το OP min-term cost quoting (`max(1, min_years)`) με live creds στο go-live, πριν εμπιστευτείς κόστη πολυετών TLDs.

---

## 📚 Reference docs

- **`PLAN.md`** (root) — master roadmap «Ekdosi ως σταδιακή αντικατάσταση WHMCS» (Domains → Payment gateways →
  Provisioning → Portal, strangler-fig).
- **`domains/README.md`** — Πυλώνας A design: data model + `DomainRegistrar` contract + Openprovider mapping + .gr/grEPP + phase gates.
- **`paroxos/regulatory-blueprint.md`** + **`paroxos/implementation-plan.md`** — GR ΥΠΑΗΕΣ πάροχος + EU PEPPOL. Ο GR
  πάροχος είναι **LIVE στην παραγωγή (InvoSign)**· PEPPOL Phase 1 DONE· ανοιχτό μόνο το **PEPPOL Phase 2**.
- **`payment-connectors.md`** — card-POS + IRIS design (NOT-STARTED, blueprint).
- **`bridges-connectors.md`** — multi-billing-source. Phase 0 DONE· Phase 1 (real 2nd source) OPEN.
- **`whmcs-legacy-plugin-map.md`** — legacy WHMCS plugins → `ekdosi_bridge`. T-1/T-2 DONE· T-3 cutover OPEN.
- **`operator-health.md`** · **`dr-without-app-key.md`** · **`go-live-usage-checks.sql.md`** — ops runbooks (reference).
