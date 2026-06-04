# E-invoicing Providers (ΥΠΑΗΕΣ) — implementation plan

> **Status: PLAN / design-locked, no code yet.** The *why/regulatory* reference
> stays in **`regulatory-blueprint.md`** (provider role beyond myDATA,
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

  > **De-risk (verified in-repo):** το `firebed/aade-mydata` v5.10.4 που ήδη
  > χρησιμοποιούμε **ήδη μοντελοποιεί** τα provider πεδία — `Models\
  > ProvidersSignature` (`SigningAuthor` = αριθμός Άδειας ΥΠΑΗΕΣ Παρόχου,
  > `Signature`, `EndToEndReferenceID`), `Enums\TransmissionFailure` (1–4, πιο
  > πλούσιο από τα 2 του XSD v0.6.1), και `Xml\InvoicesDocWriter`. Άρα για
  > παρόχους που δέχονται **AADE invoicesDoc**, ο serializer είναι **σχεδόν
  > έτοιμος** — προσθέτεις `ProvidersSignature`/`transmissionFailure` στο
  > υπάρχον `Invoice` model, δεν γράφεις XML από το μηδέν.
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

- `regulatory-blueprint.md` — regulatory/why (Α.1112/2025, ιδιοπάροχος,
  provider role beyond myDATA, AADE provider XSD field-by-field).
- `reference/aade-provider-invoicesDoc-v0.6.1.xsd` — provider invoice schema.
- `app/Contracts/EInvoiceSubmitter.php` + `app/Services/EInvoiceSubmitterFactory.php`
  — το seam που επεκτείνουμε.
- `app/Services/MyDataSubmitter.php` — `buildAadeInvoice` (το P0 factor-out source).
- `app/Services/SalesReconciler.php` + `MyDataConsole` — ο read path που ξαναχρησιμοποιείται.
- `reference/A.1258-2020-declarations-decision.pdf` + `reference/manual-paroxoi-2020-12-17.pdf`
  — η ΑΑΔΕ διαδικασία opt-in δηλώσεων (§11· A.1258 ιστορική — βλ. A.1129/2025).
- **`research/`** (έρευνα 2026-06, με πηγές):
  `invosign-api-reference.md` (ακριβές request/response schema),
  `providers-survey.md` (6 πάροχοι — §13),
  `peppol-b2g-reference.md` (PEPPOL/ΚΕΔ/Εσθονία — §7),
  `aade-regulatory-update.md` (διορθώσεις: license, timeline, stale XSD).

---

## 11. Out-of-band προϋπόθεση: οι δηλώσεις ΑΑΔΕ (A.1258/2020)

> **⚠ Ενημέρωση 2026-06 (`research/aade-regulatory-update.md`):** η A.1258/2020
> **καταργήθηκε από 31/10/2025**· το καθεστώς δηλώσεων μεταφέρθηκε στην
> **A.1129/2025** (άρθρο 71Θ ν.4172/2013). Ο *μηχανισμός* παρακάτω ισχύει — απλώς
> cite **A.1129/2025** ως τρέχουσα (το A.1258 PDF μένει ιστορική αναφορά).
>
> **Timeline υποχρεωτικού B2B (A.1128/2025, ΦΕΚ Β' 4937/16-09-2025 + ν.5193/2025 +
> Council Impl. Dec. (EU) 2025/502):** Φάση Α **2/2/2026** (τζίρος 2023 > €1M) ·
> Φάση Β **1/10/2026** (όλοι οι υπόλοιποι). Κανάλι: πιστοποιημένος **Πάροχος** Ή το
> δωρεάν **«timologio»** της ΑΑΔΕ (άρα ένας `none` tenant έχει fallback).

**Κρίσιμο που έλειπε από το αρχικό σχέδιο** (το φέρνουν τα δύο PDF του χρήστη).
Πριν εκδώσεις **έστω ένα** τιμολόγιο μέσω παρόχου, ο tenant κάνει χειροκίνητα,
**εκτός ekdosi**, στο **bookkeeper-web** (`https://www1.aade.gr/saadeapps2/
bookkeeper-web`, login TAXISnet):

1. **Εξουσιοδότηση Παρόχου** — η Οντότητα-Εκδότης εξουσιοδοτεί τον Πάροχο· ο
   Πάροχος **αποδέχεται** την εξουσιοδότηση (manual §p3).
2. **«Δήλωση Αποκλειστικής Έκδοσης Στοιχείων μέσω Παρόχου»** (A.1258/2020, αρ.1–2)
   — δηλώνεις ΑΦΜ/επωνυμία οντότητας, **ΑΦΜ + επωνυμία + αριθμό Άδειας Παρόχου**,
   ημ/νία σύμβασης, χονδρική/λιανική. Καλύπτει **όλα** τα παραστατικά· ξεκλειδώνει
   τα ευεργετήματα του άρθρου 71ΣΤ' ν.4172/2013. Ανακαλείται με «Δήλωση
   Ανάκλησης».
3. (Ως λήπτης) **«Δήλωση Αποδοχής Λήψης Ηλεκτρονικών Τιμολογίων»** — προαιρετικό
   για το issue path· σχετικό όταν λαμβάνουμε e-invoices (έξοδα).

**Συνέπειες για το ekdosi (όχι κώδικας — ρύθμιση/δεδομένα):**
- Το `einvoice_provider_config` (§3) πρέπει να κρατά **ΑΦΜ Παρόχου + αριθμό
  Άδειας** — όχι μόνο API creds — γιατί αυτά είναι που δηλώθηκαν στην ΑΑΔΕ και
  πρέπει να ταιριάζουν.
- Το **preflight (§5)** αποκτά ένα **μη-τεχνικό checklist item**: «έχει υποβληθεί
  η Δήλωση Αποκλειστικής Έκδοσης;» (ναι/όχι/ημερομηνία — operator-confirmed flag,
  π.χ. `companies.einvoice_provider_declared_at`). Δεν μπορούμε να το ελέγξουμε
  αυτόματα, αλλά το **κιτρινίζουμε** ώσπου ο operator το επιβεβαιώσει — αλλιώς
  «αποκλειστική έκδοση» χωρίς δήλωση = μη-συμμόρφωση.
- **Αποκλειστικότητα = kill-switch λογική:** όταν δηλωθεί «αποκλειστική έκδοση
  μέσω Παρόχου», ο άμεσος `MyDataSubmitter` δεν επιτρέπεται καθόλου γι' αυτόν τον
  tenant — που είναι ήδη το double-filing guard του §4/§9, απλώς τώρα έχει και
  **νομικό** λόγο, όχι μόνο τεχνικό.

---

## 12. Πρώτος υποψήφιος transport — InvoSign (grounding του P5)

**InvoSign = ΕΝΑΣ από πολλούς** που θα υποστηρίξουμε — απλώς έχει εύκολο δημόσιο
documentation, οπότε τον παίρνουμε ως **δείγμα / reference impl** για να κλειδώσει
το abstraction. Οι υπόλοιποι (SoftOne, Epsilon, Entersoft/Retail Link, ILYDA,
Primer/Orian…) μπαίνουν ο καθένας ως **άλλο ένα transport adapter + μία γραμμή
registry** (§2.3), χωρίς να αλλάξει τίποτα στο `GrProviderSubmitter`/lifecycle.

InvoSign (`https://invosign.gr/site/help_site/`, API Guide v1.0.1) — αδειοδοτημένος
GR πάροχος με Online + δημόσιο REST API. Διασταυρώθηκε· χρήσιμο γιατί **επιβεβαιώνει
ΚΑΙ διορθώνει** το σχέδιο:

**Τι ταιριάζει απόλυτα** — η απάντηση έκδοσης γυρίζει **ακριβώς** τα πεδία που
σχεδιάσαμε για το `ProviderResult` (§2.2):
| `ProviderResult` πεδίο | InvoSign response |
|---|---|
| `mark` | `invoiceMark` (το myDATA ΜΑΡΚ) ✅ |
| `authenticationCode` | `authenticationCode` (σφραγίδα παρόχου) ✅ |
| `qrUrl` | `qrUrl` ✅ |
| `uid` | `invoiceUid` ✅ |
| status re-fetch | `invoice_status.php` (ανακτάς ΜΑΡΚ μετά από lost connection) ✅ |

⇒ Επιβεβαιώνει τον §0: **το ΜΑΡΚ έρχεται από τον πάροχο**, με status-refetch
fallback — ακριβώς το «retry» σενάριο που προβλέψαμε στο §4.

**Transport specifics** (γεμίζουν το `EpsilonTransport`-στυλ adapter, εδώ
`InvoSignTransport`):
- Auth = **plain `token`** ως form field (όχι header/OAuth) → `ProviderCredentials`
  κρατά απλώς `{base_url, token, demo_base_url, demo_token}`.
- `POST application/x-www-form-urlencoded`, `xml_arxeio=<XML>` + `token`.
- Endpoints: issue `iNVOSign_Api.php`, cancel `iNVOSign_CancelDeliveryNote.php`
  (`mark`+`token`), status `invoice_status.php`. **Per-client base URL** (ιδιωτικό).
- **Sandbox: ναι** (`demo_base_url`+`demo_token`) → δένει με το
  `einvoice_provider_mode=sandbox` του §3.
- PEPPOL Access Point / B2G: **δεν τεκμηριώνεται δημόσια** — άρα InvoSign καλύπτει
  το **B2B leg**, όχι (αποδεδειγμένα) το Δημόσιο. Το PEPPOL leg (§7) μένει χωριστό.

**⚠ Διόρθωση μετά το deep-dive (`research/invosign-api-reference.md`):** η InvoSign
**ΔΕΝ** είναι fully-bespoke — δέχεται το **πλήρες, αμετάβλητο AADE `InvoicesDoc`**
και απλώς **προσθέτει** ένα `<API_InvoiceDetails>` block + per-line `api_*` twins
(printout fields). Άρα ο serializer της = **firebed `InvoicesDocWriter` + appended
extension** (thin decorator), όχι ξαναγράψιμο. Όμως το γενικό συμπέρασμα μένει —
**ο άξονας «ΤΙ» δεν είναι ΕΝΑΣ κοινός serializer**: το providers-survey δείχνει ότι
άλλοι θέλουν **proprietary JSON** (SoftOne/IMPACT), **proprietary XML** (Entersoft),
**AADE-XML passthrough** (SBZ), ή **PEPPOL UBL** (B2G). Σωστό μοντέλο:

```
canonical ekdosi invoice (DTO)
        │
        ├─ AadeInvoiceDocument   → AADE invoicesDoc XML   (πάροχοι που το δέχονται)
        ├─ InvoSignDocument      → InvoSign bespoke XML    (InvoSign)
        └─ PeppolUblDocument     → UBL/EN16931             (B2G + EU)
```

Δηλαδή **κάθε transport adapter δηλώνει ποιον serializer θέλει** (ο περισσότερος
κώδικας μοιράζεται — το canonical DTO + τα `Codes`/VAT/income-classification
mappings· αλλάζει μόνο η τελική σειριοποίηση). Αυτό **ενισχύει** το split «ΤΙ/ΠΩΣ»
αντί να το σπάει: ο registry του §2.3 δίνει `{serializer, http-client}` ζευγάρι ανά
πάροχο. Το `GrProviderSubmitter` μένει ένας — απλώς ζητά από το adapter «σειριοποίησε
+ στείλε», χωρίς να ξέρει το schema.

**Πρακτικό:** ο InvoSign είναι **εξαιρετικός πρώτος πραγματικός transport** (P5) —
δημόσιο doc, sandbox, καθαρό response με ΜΑΡΚ+auth+QR. Ξεκινάμε απ' αυτόν, sandbox-
validated όπως το myDATA 2026-05-28, και είναι το **reference impl** που αποδεικνύει
ότι το seam δέχεται πάροχο με δικό serializer χωρίς να αγγίξει lifecycle/reconciliation.

---

## 13. Validated architecture (από το `research/providers-survey.md`)

Έρευνα 6 παρόχων (+ baseline AADE spec) **επιβεβαιώνει το design**:

**(α) Το request format ΔΙΑΦΕΡΕΙ ανά πάροχο** → per-provider serializer + transport
+ auth είναι αναγκαίο, όχι over-engineering:
| Format | Πάροχοι | Adapter |
|---|---|---|
| AADE `InvoicesDoc` XML passthrough | SBZ, AADE baseline | firebed writer + POST |
| AADE-XML **+ extension** | InvoSign | firebed writer + appended block |
| Proprietary JSON | SoftOne, IMPACT/ECOS | bespoke JSON builder |
| Proprietary XML | Entersoft | bespoke XML builder |
| Mixed JSON+XML | Primer | είτε/είτε |
| PEPPOL UBL | B2G (IMPACT AP→ΚΕΔ) | UBL serializer (§7) |

**(β) Το auth ΔΙΑΦΕΡΕΙ** (session-clientID / `API-KEY` header / `aade-user-id`+sub-key
/ token) → ζει στο `ProviderCredentials` + στο transport, ποτέ hardcoded.

**(γ) Το response είναι ΟΜΟΙΟΜΟΡΦΟ** — όλοι γυρίζουν `mark`+`authenticationCode`/
signature+`uid`+`qrUrl`+errors → **το `ProviderResult` DTO (§2.2) είναι ρεαλιστικά
κοινό** για όλους. Αυτό είναι το σταθερό σημείο πάνω στο οποίο κουμπώνει το
`GrProviderSubmitter`.

**Πρακτικές παρατηρήσεις:**
- **SoftOne ECOS ≡ IMPACT backend** (`einvoiceapi.impact.gr`) → ένα JSON adapter
  πιθανότατα καλύπτει **και τους δύο**.
- **IMPACT τρέχει δικό του PEPPOL Access Point** → φυσικός υποψήφιος για το **B2G** leg.
- **Epsilon Net + ILYDA: μηδέν public spec** → το interface πρέπει να ανέχεται
  «spec-on-request» παρόχους· **μην bake-άρεις** public field names πάνω τους.
- **firebed v5.10.4 ήδη μοντελοποιεί** `ProviderSignature`/`TransmissionFailure`/
  `invoiceDeliveryStatus` (§2.1) — ο AADE-XML πυρήνας είναι σχεδόν δωρεάν· **όμως ο
  committed XSD είναι v0.6.1, stale** — re-pull v1.0.9–v1.0.12 πριν το build
  (`research/aade-regulatory-update.md`).
