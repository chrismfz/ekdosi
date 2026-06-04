# E-invoicing Providers (ΥΠΑΗΕΣ) — implementation plan

> **Status: PLAN / design-locked, no code yet.** The *why/regulatory* reference
> stays in **`docs/einvoice-provider-bridge.md`** (provider role beyond myDATA,
> the AADE provider XSD, Α.1112/2025 certification, ιδιοπάροχος). This doc is the
> *how we build it* — it answers the three operator questions directly:
> **(α)** πολλούς παρόχους, ο καθένας με δικό του API· **(β)** per-company
> επιλογή παρόχου + credentials, **κρατώντας τα myDATA credentials**·
> **(γ)** μία «Πάροχος Console» — **τι στέλνουμε / τραβάμε ΜΑΡΚ από myDATA;**

---

## 0. The one-paragraph answer (read this first)

Από Οκτώβριο 2026 το **B2B μέσω παρόχου** γίνεται υποχρεωτικό· για **B2G
(Δημόσιο)** ισχύει ήδη μέσω **PEPPOL Access Point**. Με πάροχο **αλλάζει το ΠΟΥ
στέλνεις**, όχι το ΤΙ: αντί να κάνεις εσύ `SendInvoices` στο myDATA, στέλνεις το
**ίδιο σχεδόν έγγραφο** στον πάροχο· **ο πάροχος** το σφραγίζει (authentication
code), **το υποβάλλει στο myDATA για λογαριασμό σου** και σου **γυρίζει πίσω το
ΜΑΡΚ + QR + authentication code + απόδειξη παράδοσης**. Άρα:

- **Το ΜΑΡΚ έρχεται από την απάντηση του παρόχου** (authoritative, άμεσο). Δεν το
  «τραβάς» από το myDATA για να εκδώσεις.
- **Τα myDATA credentials ΜΕΝΟΥΝ** — αλλά πλέον είναι **read-only**: οι υποβολές
  του παρόχου **προσγειώνονται στο δικό σου myDATA**, οπότε η υπάρχουσα
  reconciliation (`SalesReconciler` → `RequestTransmittedDocs`), τα Έξοδα
  (`RequestDocs`), το Ε3/ΦΠΑ console — όλα δουλεύουν **ακριβώς όπως τώρα** και
  γίνονται ο **ανεξάρτητος έλεγχος** ότι ο πάροχος όντως υπέβαλε. Δηλαδή το ΜΑΡΚ
  το έχεις **και** από τον πάροχο (write path) **και** το βλέπεις στο myDATA (read
  path) — και τα δύο, εκ σχεδιασμού, πρέπει να συμφωνούν.
- **WRITE path = πάροχος. READ path = myDATA.** Αυτός είναι ο διαχωρισμός.

Το seam (`EInvoiceSubmitter` + `EInvoiceSubmitterFactory` + `companies.
einvoice_provider`) **υπάρχει ήδη**. Η δουλειά είναι: σπάμε το «έγγραφο» από τη
«μεταφορά», γράφουμε **έναν** generic `GrProviderSubmitter` + **έναν transport
adapter ανά πάροχο**, και προσθέτουμε per-tenant ρυθμίσεις + μία Console.

---

## 1. Ναι — το stub γίνεται abstraction (η ερώτηση του χρήστη)

Σήμερα υπάρχουν **δύο** «θέσεις» στο `EInvoiceSubmitterFactory`: `gr-mydata`
(χτισμένο) και `ee-peppol` (stub → `NullSubmitter`). Ο χρήστης σωστά το πιάνει:
ο πίνακας παρόχων της ΑΑΔΕ (Epsilon, SoftOne, Entersoft/Retail Link, ILYDA,
Primer/Orian/… + **PEPPOL Access Point** για το Δημόσιο) σημαίνει ότι χρειαζόμαστε
**πολλούς** πάροχους επιλέξιμους per-tenant, όχι έναν.

Το κλειδί: **ένας πάροχος ≠ ένας submitter**. Αν φτιάχναμε `EpsilonSubmitter`,
`SoftOneSubmitter`, … θα διπλασιάζαμε τη μισή `MyDataSubmitter` κάθε φορά. Αντ'
αυτού **σπάμε σε δύο άξονες**:

```
                       EInvoiceSubmitter  (το υπάρχον seam — submit/cancel/test)
                                │
              ┌─────────────────┴──────────────────┐
        ΤΙ φτιάχνουμε                          ΠΩΣ το στέλνουμε
   (document serializer)                     (transport adapter)
   ──────────────────────                    ──────────────────────
   AadeInvoiceDocument  ───┐            ┌──  EpsilonTransport
   (factor out of          ├─ canonical┤    SoftOneTransport
    MyDataSubmitter)       │   XML/UBL  │    EntersoftTransport
   PeppolUblDocument    ───┘            ├──  PrimerPeppolTransport (Access Point)
   (new, EN16931)                       └──  …  (μία κλάση + 1 config γραμμή ο καθένας)
```

- **Το έγγραφο είναι σχεδόν standard** (AADE provider `invoicesDoc` v0.6.1 για GR·
  PEPPOL UBL/EN16931 για EU/Δημόσιο). Το γράφουμε **μία φορά** ανά οικογένεια.
- **Το API είναι ανά-πάροχο.** Κάθε `*Transport` ξέρει μόνο endpoint + auth +
  πώς να ανεβάσει το XML και να διαβάσει `mark`/`authenticationCode`/`qrUrl`/
  delivery — **τίποτα** από τη λογική τιμολόγησης.

Έτσι «πολλοί πάροχοι αναλόγως το API του καθενός» = **N μικρά transport adapters**
πάνω σε **1–2 serializers**, κάτω από **1 submitter**, πίσω από **το υπάρχον seam**.

---

## 2. Layering — οι κλάσεις και πού κάθονται

### 2.1 Document serializers (το «ΤΙ»)
- **`App\Services\EInvoice\AadeInvoiceDocument`** — factor-out του
  `MyDataSubmitter::buildAadeInvoice`. Βγάζει το canonical AADE
  `AadeBookInvoiceType` (το **ίδιο** που ήδη στέλνουμε στο myDATA) + τα
  provider-only πεδία όταν χρειάζονται (`authenticationCode`,
  `transmissionFailure` — §2 του blueprint). **Καμία αλλαγή σημασιολογίας** — ο
  `MyDataSubmitter` συνεχίζει να το χρησιμοποιεί για το άμεσο myDATA path.
- **`App\Services\EInvoice\PeppolUblDocument`** (νέο, για PEPPOL/B2G + Nixpal):
  canonical invoice → **UBL 2.1 / EN 16931**. Ξεχωριστός serializer, ίδιο input.

> **Γιατί όχι Document-interface generalization τώρα:** ίδια γραμμή με το quotes
> plan — το AADE builder είναι βαθιά δεμένο με τα `Codes`/`InvoiceVatBreakdown`/
> golden rounding. Πρώτα **factor-out** (μετακίνηση, όχι re-abstraction), μετά —
> όταν μπει ο 2ος serializer — βγαίνει φυσικά το κοινό `InvoiceDocument` interface.

### 2.2 Transport adapters (το «ΠΩΣ»)
- **`App\Contracts\EInvoiceProviderTransport`** (νέο interface):
  ```php
  interface EInvoiceProviderTransport
  {
      public function key(): string;                 // 'epsilon' | 'softone' | 'primer-peppol' | …
      public function send(string $documentXml, ProviderCredentials $c): ProviderResult;
      public function cancel(string $mark, ProviderCredentials $c, string $reason = ''): ProviderResult;
      public function status(string $mark, ProviderCredentials $c): ProviderResult;  // optional / retrieve
      public function ping(ProviderCredentials $c): bool;                            // test connection
  }
  ```
- **`App\Support\EInvoice\ProviderResult`** (DTO): `mark`, `uid`,
  `authenticationCode`, `qrUrl`, `cancellationMark`, `deliveryState`, `raw`
  (response για audit), `errors[]`. **Αυτό είναι που γυρίζει το ΜΑΡΚ** στο
  submitter.
- **`App\Support\EInvoice\ProviderCredentials`** (DTO): api key / client id /
  secret / endpoint / sandbox-flag — διαβασμένα από τα per-tenant encrypted
  columns (§3).
- Ένα concrete transport ανά πάροχο: `App\Services\EInvoice\Transports\
  EpsilonTransport`, `SoftOneTransport`, `EntersoftTransport`,
  `PrimerPeppolTransport`, … **Stub πρώτα** — υλοποιείται ο πάροχος που έχει
  πραγματικό tenant (Nexon vs MyIP διαφέρουν → ο καθένας ο δικός του).

### 2.3 Registry + submitter (η σύνδεση)
- **`App\Services\EInvoice\ProviderTransportRegistry`** — `for(string $key):
  EInvoiceProviderTransport`, **config-driven** (`config/ekdosi.php →
  einvoice.providers`), **ίδιο pattern** με το `ProvisioningModuleRegistry` και
  το `EInvoiceSubmitterFactory`. Νέος πάροχος = **μία γραμμή config + μία κλάση**,
  κανένα core change· άγνωστο key → loud error (ποτέ σιωπηλό λάθος filing).
- **`App\Services\GrProviderSubmitter implements EInvoiceSubmitter`** — **ένας**
  για ΟΛΟΥΣ τους GR παρόχους:
  1. `AadeInvoiceDocument` φτιάχνει το XML (reuse).
  2. `ProviderTransportRegistry->for($tenant->einvoice_provider_key)` δίνει το
     transport.
  3. `transport->send($xml, $creds)` → `ProviderResult`.
  4. Γράφει **την ίδια `mydata_marks` row** που γράφει ο `MyDataSubmitter`
     (source of truth), με το ΜΑΡΚ από την απάντηση + νέα στήλη
     `authentication_code` + `provider_key`. Συγχρονίζει τα `invoices.mydata_*`
     cache columns **ακριβώς όπως** ο MyDataSubmitter (VALID→active κ.λπ.) — ο
     downstream κώδικας (lifecycle/reconciliation/PDF/QR) **δεν αλλάζει**.
- **`PeppolSubmitter`** (το σημερινό stub) → ξαναγράφεται με τον ίδιο σκελετό
  πάνω σε `PeppolUblDocument` + ένα Access-Point transport. Ίδιο contract.

### 2.4 Factory routing (επέκταση, όχι ξαναγράψιμο)
`EInvoiceSubmitterFactory::for()` αποκτά:
```
einvoice_provider = 'gr-mydata'   → MyDataSubmitter      (direct, ως τώρα)
einvoice_provider = 'gr-provider' → GrProviderSubmitter  (NEW· transport = einvoice_provider_key)
einvoice_provider = 'ee-peppol'   → PeppolSubmitter      (NEW build)
einvoice_provider = 'none'        → NullSubmitter        (ως τώρα)
```
Ένας tenant μετακινείται από άμεσο myDATA σε πάροχο **αλλάζοντας ένα dropdown** —
`gr-mydata` → `gr-provider` + επιλογή `einvoice_provider_key`. Τίποτα άλλο.

---

## 3. Per-company: επιλογή παρόχου + credentials, **κρατώντας myDATA**

**Καμία στήλη myDATA δεν πειράζεται.** Τα `mydata_aade_id_*` /
`mydata_subscription_key_*` / `mydata_mode` μένουν — γίνονται **το read path**
(§4). Προσθέτουμε **additive** στο `companies`:

| Στήλη | Τύπος | Ρόλος |
|---|---|---|
| `einvoice_provider` | (υπάρχει, varchar20) | + νέα τιμή `gr-provider` |
| `einvoice_provider_key` | varchar(40) null | ποιος πάροχος (`epsilon`/`softone`/`primer-peppol`/…) — δείχνει στο registry |
| `einvoice_provider_config` | text null, **`encrypted` cast** | JSON: `{api_key, client_id, secret, endpoint, sandbox}` — ανά πάροχο πεδία· **ίδιο pattern** με `mydata_subscription_key`/`gsis_password`/`mail_smtp_password` |
| `einvoice_provider_mode` | varchar(16) default 'off' | `off`/`sandbox`/`production` — δίδυμο του `mydata_mode`, ώστε να δοκιμάζεις πάροχο χωρίς live filing |

- **Γιατί JSON config κι όχι στήλη-ανά-πεδίο:** κάθε πάροχος θέλει άλλα keys
  (Epsilon ≠ SoftOne ≠ Access Point). Ένα encrypted JSON blob = κανένα migration
  ανά πάροχο (ίδια λογική με `module_meta` στα servers). Το transport ξέρει ποια
  keys διαβάζει.
- **Encryption:** Laravel `encrypted` cast (ίδιο APP_KEY, ίδιο pattern με όλα τα
  άλλα secrets — βλ. CLAUDE.md «Per-tenant encrypted credentials»). Ποτέ
  plaintext API keys.
- **myDATA creds = πάντα παρόντα** όταν `einvoice_provider='gr-provider'`: τα
  χρειάζεσαι για το read path. Το preflight (§5) το **απαιτεί**.

Company form («Πάροχος» section, κάτω από το myDATA section): provider dropdown
(από registry keys), τα config πεδία (δυναμικά ανά πάροχο — schema του transport),
mode select, «Test connection» action → `transport->ping()`.

---

## 4. Reconciliation — γιατί τα myDATA creds μένουν (η απάντηση στο «τραβάμε ΜΑΡΚ;»)

```
   ΕΚΔΟΣΗ (write)                         ΕΛΕΓΧΟΣ (read)
   ─────────────                          ──────────────
   ekdosi ──XML──▶ Πάροχος ──▶ myDATA     ekdosi ──RequestTransmittedDocs──▶ myDATA
            ◀── ΜΑΡΚ + auth + QR ──┘                 ◀── οι ίδιες υποβολές ──┘
   (το ΜΑΡΚ ΕΔΩ, άμεσα)                    (cross-check: ο πάροχος όντως υπέβαλε;)
```

- Επειδή ο πάροχος υποβάλλει στο **δικό σου** myDATA, **κάθε** τιμολόγιο που
  εκδίδεις μέσω παρόχου **εμφανίζεται** στο `RequestTransmittedDocs`. Άρα ο
  υπάρχων `SalesReconciler` / `MyDataConsole` / `mydata:reconcile-sales`
  **δουλεύει αυτούσιος** και γίνεται ο **ανεξάρτητος επαληθευτής**: ΜΑΡΚ από
  πάροχο **==** ΜΑΡΚ στο myDATA; αν λείπει → ο πάροχος δεν υπέβαλε → alert.
- Ομοίως **Έξοδα** (`RequestDocs`), **Ε3** (`RequestE3Info`), **ΦΠΑ**
  (`VatPeriodReport`) — όλα read paths, **αμετάβλητα**.
- **Άρα δεν «τραβάς ΜΑΡΚ από myDATA για να εκδώσεις»** — το ΜΑΡΚ το δίνει ο
  πάροχος στην έκδοση. Το myDATA read είναι ο **έλεγχος**, όχι η πηγή έκδοσης.
  (Το μόνο σενάριο που «τραβάς» ΜΑΡΚ από myDATA είναι αν ένας πάροχος **δεν**
  το επιστρέφει sync — τότε poll `status()` ή reconcile· σπάνιο, το χειρίζεται
  το retry.)

**Διπλο-filing guard (κρίσιμο):** όταν `einvoice_provider='gr-provider'`, ο
άμεσος `MyDataSubmitter` path **δεν** καλείται (το factory επιστρέφει
`GrProviderSubmitter`). Έτσι **δεν** υποβάλλεις δύο φορές (μία εσύ, μία ο
πάροχος). Ο `MyDataSubmitter` μένει μόνο για tenants `gr-mydata` (άμεσο).

---

## 5. «Πάροχος Console» — mirror του MyData console

Νέα Filament page **`App\Filament\Pages\ProviderConsole`** (admin-gated, δίπλα στο
`MyDataConsole`), 3 λειτουργίες — αντιγραφή της δομής του myDATA console:

1. **Preflight (read-only audit):** πάροχος επιλεγμένος; `einvoice_provider_key`
   στο registry; credentials set + `ping()` ok; **myDATA creds επίσης set** (για
   το read path); invoice-type → provider doc-type mapping πλήρες. Exit/badge
   πράσινο/κόκκινο. Command-δίδυμο `php artisan einvoice:preflight --tenant=`
   (mirror του `mydata:preflight`).
2. **Test-submit (dry-run):** `AadeInvoiceDocument` για ένα invoice → δείξε το XML
   που θα πήγαινε στον πάροχο· `--execute` → πραγματική υποβολή σε **sandbox**
   πάροχο, δείξε `ProviderResult` (mark/auth/qr/errors). Mirror του
   `mydata:test-submit`.
3. **Per-invoice provider audit:** ανά τιμολόγιο — provider doc id, ΜΑΡΚ,
   `authentication_code`, delivery state, **+ διασταύρωση με myDATA** (το ΜΑΡΚ
   βρέθηκε στο `RequestTransmittedDocs`; ✅/⚠). Αυτό είναι το «για εξακρίβωση» που
   ζήτησε ο χρήστης: η Console δείχνει **και** την απάντηση του παρόχου **και** την
   επιβεβαίωση από myDATA, δίπλα-δίπλα.

**Τι «στέλνουμε εκεί»:** στον πάροχο στέλνεις το **AADE invoice XML** (το ίδιο
έγγραφο). Στη myDATA δεν στέλνεις τίποτα όταν έχεις πάροχο — μόνο **διαβάζεις**
για έλεγχο.

Audit storage: επεκτείνουμε **`mydata_marks`** (source of truth) με
`provider_key` + `authentication_code` + `delivery_state` + `provider_doc_id`
(αντί νέου πίνακα — οι marks είναι ήδη το νομικό audit με full request/response
XML· ο πάροχος απλώς γεμίζει μερικά πεδία παραπάνω). `MyDataMark::action` αποκτά
τιμές `PROVIDER_INSERT`/`PROVIDER_CANCEL` ώστε να ξεχωρίζει το path.

---

## 6. PR breakdown (όταν ξεκινήσει — design-locked, όχι τώρα)

- **P0 — factor-out (μηδενική αλλαγή συμπεριφοράς):** `AadeInvoiceDocument` βγαίνει
  από τον `MyDataSubmitter`· ο submitter τον καλεί. Golden tests πράσινα
  (byte-ίδιο XML). *Καθαρό refactor, αυτο-ασφαλές.*
- **P1 — seam + config:** `EInvoiceProviderTransport` interface + `ProviderResult`/
  `ProviderCredentials` DTOs + `ProviderTransportRegistry` (config-driven, **μόνο
  ένα Null/echo transport wired**) + migration (`einvoice_provider_key`/`_config`/
  `_mode`) + `mydata_marks` provider columns + factory routing για `gr-provider`.
  **Κανένας πραγματικός πάροχος** — η υποδομή στέκει δίπλα στο live myDATA, no-op.
- **P2 — `GrProviderSubmitter`** πάνω στο seam + double-filing guard + unit tests
  (mock transport → ΜΑΡΚ persist + cache sync + lifecycle).
- **P3 — Company form (provider section, encrypted config, Test connection) +
  `einvoice:preflight` command.**
- **P4 — ProviderConsole** (preflight/test-submit/per-invoice audit + myDATA
  cross-check), reuse `SalesReconciler` για το read leg.
- **P5 — πρώτος πραγματικός transport** (ο πάροχος που έχει tenant — Epsilon ή
  SoftOne κατά περίπτωση), sandbox-validated όπως το myDATA 2026-05-28.
- **P6 (παράλληλα/αργότερα) — PEPPOL:** `PeppolUblDocument` + Access-Point
  transport για **B2G/Δημόσιο** + Nixpal OÜ. Ίδιο seam· διαφορετικός serializer +
  4-corner transport. (Public-sector απαιτεί certified Access Point — βλ. §7.)

---

## 7. B2G / Δημόσιο + PEPPOL (η προσθήκη του χρήστη)

Η ανακοίνωση ΑΑΔΕ: για **συμβάσεις Δημοσίου** ο πάροχος πρέπει (α) πιστοποιημένος
ΥΠΑΗΕΣ, (β) **πιστοποιημένο PEPPOL Access Point**, (γ) επιτυχείς δοκιμές με το
ΚΕ.Δ της ΓΓΠΣΨΔ. Συνέπειες για το σχέδιο:

- **B2G = PEPPOL leg**, όχι το GR provider leg. Ένα τιμολόγιο προς Δημόσιο
  σειριοποιείται ως **UBL/EN16931** και φεύγει μέσω **Access Point** (4-corner),
  ενώ ένα B2B μέσω παρόχου φεύγει ως AADE XML. **Το ίδιο seam**, δύο serializers +
  δύο transports — γι' αυτό ο διαχωρισμός «ΤΙ/ΠΩΣ» του §1 πληρώνει διπλά (GR
  provider **και** PEPPOL μοιράζονται τον σκελετό).
- **Δεν τρέχεις δικό σου Access Point** — περνάς μέσα από certified (πάροχο που
  *είναι* AP, π.χ. Primer/Orian, ή τον δικό σου αν γίνεις ΥΠΑΗΕΣ+AP). Ο
  `PrimerPeppolTransport` είναι ακριβώς αυτό: adapter προς certified AP.
- **EU convergence (ViDA):** το ίδιο PeppolUbl leg εξυπηρετεί και Nixpal OÜ
  (Εσθονία). Μία επένδυση, τρεις χρήσεις: GR-B2G, EU-B2B, Estonia.

---

## 8. Strategic call (per-tenant, η απάντηση στο «β»)

| Tenant | Σήμερα | Πρόταση |
|---|---|---|
| MyIP (GR, myDATA live) | `gr-mydata` άμεσο | → `gr-provider` + ο πάροχός τους, **όταν** ενεργοποιηθεί το mandate· ως τότε μένει άμεσο |
| Nexon (GR, μικρός) | `gr-mydata` | → `gr-provider` + **ο δικός του** πάροχος (διαφορετικό API/creds — γι' αυτό per-tenant config) |
| Nixpal (EE) | `ee-peppol` stub | → `PeppolSubmitter` (P6), όταν το επιβάλλει η εσθονική προθεσμία |

**Bridge to external provider (Option A του blueprint) = ο δρόμος.** Δεν γινόμαστε
ΥΠΑΗΕΣ/ιδιοπάροχος τώρα (regulatory βάρος: ISO-27001, πιστοποιήσεις — §4 blueprint).
Συνδεόμαστε σε **υπάρχοντες** πιστοποιημένους παρόχους, **έναν ανά tenant**, με
δικά τους credentials. Αν ποτέ χρειαστεί ιδιοπάροχος, ο **ίδιος** `AadeInvoiceDocument`
+ ένα «mint our own authenticationCode» transport το καλύπτει — το seam δεν αλλάζει.

---

## 9. Risks / invariants

- **Διπλο-filing:** το factory ΠΟΤΕ δεν επιστρέφει και τους δύο (`MyDataSubmitter`
  **ή** `GrProviderSubmitter`, αποκλειστικά ανά `einvoice_provider`). Test που το
  κλειδώνει.
- **myDATA creds = read-only όταν provider:** preflight απαιτεί να υπάρχουν· το
  write path δεν τα αγγίζει.
- **ΜΑΡΚ source of truth:** μένει το `mydata_marks` — provider γεμίζει επιπλέον
  πεδία, δεν φτιάχνει παράλληλο audit.
- **XSD enum drift** (provider v0.6.1 vs myDATA v2.0.0 — §2 blueprint): diff τα
  enums πριν το live, pin στο ό,τι απαιτεί ο πάροχος.
- **Reconciliation αμετάβλητη:** ο read path δεν ξέρει/δεν νοιάζεται αν εκδόθηκε
  άμεσα ή μέσω παρόχου — βλέπει την ίδια myDATA εικόνα. Critical isolation test.

---

## 10. Reference

- `docs/einvoice-provider-bridge.md` — regulatory/why (Α.1112/2025, ιδιοπάροχος,
  provider role beyond myDATA, AADE provider XSD field-by-field).
- `docs/reference/aade-provider-invoicesDoc-v0.6.1.xsd` — provider invoice schema.
- `app/Contracts/EInvoiceSubmitter.php` + `app/Services/EInvoiceSubmitterFactory.php`
  — το seam που επεκτείνουμε.
- `app/Services/MyDataSubmitter.php` — `buildAadeInvoice` (το P0 factor-out source).
- `app/Services/SalesReconciler.php` + `MyDataConsole` — ο read path που ξαναχρησιμοποιείται.
</content>
</invoke>
