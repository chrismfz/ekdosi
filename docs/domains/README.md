# Domains — αναλυτικό design (Πυλώνας A του `PLAN.md`)

> **STATUS: DESIGN — pre-build, NO code yet.** Το solid σχέδιο πριν το A0, μετά από
> Q&A με τον ιδιοκτήτη + τα πραγματικά WHMCS screenshots + research στο Openprovider
> module/API και στο WHMCS registrar function set / .gr registry. Είναι ο αναλυτικός
> «ξεδίπλωμα» του `PLAN.md §3` (domains) — ο twin του `docs/payment-connectors.md`.
>
> **Γείωση:** κάθε αναφορά σε υπάρχον ekdosi symbol είναι πραγματική· κάθε Openprovider
> endpoint/field από το επίσημο swagger (`openprovider/api-documentation`) + το module
> source (`openprovider/Openprovider-WHMCS-domains`)· οι κανόνες .gr από EETT/FORTH.

---

## 0. TL;DR

Native domain management, **modular ανά registrar** (ίδιο pattern με το `EInvoiceProviderTransport`).
v1 registrars: **`openprovider`** (gTLDs/ccTLDs) + **`grepp`** (direct .gr EPP — το «Forth»),
με **routing ανά TLD**. Feature parity με το WHMCS domain module **φραγμένη στο API**. Το billing
πατά 100% στο υπάρχον **`ServiceContract`** engine (renewals + dunning). Per-tenant opt-in
(`enable_domain_management`), operator-gated (ποτέ auto-file στην ΑΑΔΕ). Χτίζεται σε **6 gates
A0–A5** — σταματάς όπου θες.

---

## 1. Πλαίσιο & scope

**Γιατί:** η MyIP αντικαθιστά σταδιακά το WHMCS (`PLAN.md`, strangler-fig). Τα domains είναι ο
πρώτος πυλώνας. Το ekdosi κάνει ήδη το δύσκολο (invoices/myDATA/recurring/υπόλοιπα)· εδώ
προσθέτουμε registrar integration + domain lifecycle, πατώντας στο υπάρχον billing.

**Κλειδωμένες αποφάσεις (από το Q&A):**

| Θέμα | Απόφαση |
|---|---|
| Registrars v1 | `openprovider` + `grepp` (.gr), **routing ανά TLD** |
| Registrant | **ο πελάτης** (full contacts registrant/admin/tech/billing) |
| DNS | **NS delegation μόνο** v1 (zone hosting + email-forwarding = deferred, flags μένουν) |
| DNSSEC | **ΝΑΙ** (DS records, delegation-level) |
| Operations | **πλήρης κύκλος** incl. transfers in **+** out (async state machine) |
| Currency | **EUR** = settlement/invoice/myDATA· USD = display-only |
| Money timing | register = **post-payment**· renew = **on-issue** |
| Import | **και τα δύο** — WHMCS `tbldomains` (linkage) + registrar sync (verify) |
| Transfer-out | **operator-gated** v1· portal self-service αργότερα |
| Contacts source | **modular** — `domain_contacts` ξεχωριστή οντότητα, pre-fill από Customer με ρητή επιβεβαίωση |

**Per-tenant opt-in:** `companies.enable_domain_management` (boolean, super_admin Toggle στο
`CompanyForm` όπως `ai_assistant_enabled`). Μόνο η MyIP το ενεργοποιεί → οι άλλοι tenants δεν
βλέπουν καθόλου το «Domains» (nav gating, §8).

**Non-goal v1:** DNS zone/record hosting, email forwarding, customer portal (Πυλώνας D), CentralNic.

---

## 2. Registrars & routing

**Δύο adapters v1**, ο καθένας υλοποιεί το ίδιο `DomainRegistrar` contract (§4):
- **`openprovider`** — gTLDs + ccTLDs (.com/.net/.org/.eu/.io/.de/…). REST API, price-sync ✅.
- **`grepp`** — ΟΛΑ τα `.gr` (`.gr/.com.gr/.net.gr/.org.gr/.edu.gr/.gov.gr`), **direct EPP** στο
  μητρώο (FORTH). Το «Forth» του ιδιοκτήτη = το grEPP module που ήδη τρέχει στη WHMCS του (άρα
  **EETT-accredited με FORTH EPP** — static-IP whitelist + OT&E). Manual pricing (χωρίς sync).

**Routing ανά TLD:** το `domain_tlds.registrar_connection_id` ορίζει ποιός registrar χειρίζεται
κάθε TLD (= το «Auto Registration» dropdown της WHMCS οθόνης). Το ίδιο domain πάντα μέσω του TLD
του → ο registrar προκύπτει ντετερμινιστικά.

**CentralNic = legacy/out.** Το Admin-Notes log ενός domain δείχνει `[CNIC→OPENPROVIDER] migration`
→ η MyIP **έφυγε** από CentralNic προς Openprovider. Δεν είναι v1 target (το capability model
επιτρέπει να μπει αργότερα ως 3ος adapter, one class + one config line).

**Capabilities (mirror του `App\Support\Billing\SourceCapabilities`):** ένα readonly value object
`DomainRegistrarCapabilities` με flags — `supportsPricingSync`, `supportsPrivacy`, `supportsDnssec`,
`supportsTransferLock`, `supportsTransfer`, `supportsGlueHosts` — ώστε το UI/import να προσαρμόζεται
**χωρίς `if ($registrar === 'openprovider')` αλυσίδες**. π.χ. Openprovider `supportsPricingSync=true`,
grEPP `false`· .gr `supportsPrivacy=false`, `supportsTransferLock=false` (§5).

---

## 3. Data model

Όλοι οι πίνακες tenant-owned, house conventions: surrogate `id`, `company_id` FK
`constrained()->cascadeOnDelete()`, `legacy_id` nullable `unsignedInteger` unique-per-tenant,
`timestamps`, `softDeletes` (στα user-facing), tenant-first composite indexes, `decimal(14,2)`
money, **χωρίs** per-migration charset (connection default). Πρότυπο: `customers` migration.

### 3.1 `domain_registrar_connections` — per-tenant registrar λογαριασμοί
Mirror του `billing_connections` (superset· μπορεί να μαζευτεί σε στήλη αργότερα).

| col | τύπος | σημείωση |
|---|---|---|
| `company_id` | FK | |
| `registrar` | string(40) | key: `openprovider` \| `grepp` \| `none` |
| `label` | string | «Openprovider MyIP», «FORTH EPP» |
| `is_active` | bool | |
| `mode` | string(20) | `sandbox` \| `production` \| `off` |
| `config` | **encrypted** json | `MaybeEncrypted::class.':array'` — creds (username/password ή EPP host/user/pass/static-IP) |
| timestamps, softDeletes | | |

**Χωρίς** unique σε `(company_id, registrar)` — ο tenant μπορεί να έχει 2 λογαριασμούς ίδιου
registrar. Επεξεργασία creds = **super_admin only** (όπως τα einvoice provider creds).

### 3.2 `domain_tlds` — κατάλογος TLD + κανόνες (γειωμένο στην WHMCS οθόνη)

| col | σημείωση |
|---|---|
| `company_id`, `tld`(30) | `gr`,`com`,`eu`,`ελ`… · unique `(company_id, tld)` |
| `registrar_connection_id` | FK — **ποιός registrar** (= «Auto Registration») |
| `min_years`, `max_years` | .gr → `min_years=2` (§5) |
| `min_chars`, `max_chars` | generic min 3 |
| `allow_idn`, `allow_transfer` | bool |
| `grace_period_days`, `grace_fee`(14,2) | .gr: 15 / 0 |
| `redemption_period_days`, `redemption_fee`(14,2) | .com: 30 / €96.50 |
| `dns_management`, `email_forwarding`, `id_protection`, `epp_code` | capability flags (per-TLD) |
| `is_active` | |

_(.gr row από το screenshot: DNS/Email/ID = off, EPP = on, grace 15 / redemption 0 → επιβεβαιώνει.)_

### 3.3 `domain_tld_prices` — τιμή ανά TLD × operation × έτος × currency
Η ασυμμετρία που το `ProductBillingPrice` δεν εκφράζει (register≠renew≠transfer≠restore).

| col | σημείωση |
|---|---|
| `domain_tld_id` | FK |
| `operation` | `register` \| `transfer` \| `renewal` \| `restore` \| `redemption` |
| `years` | 1–10 — **ρητή** τιμή ανά έτος (όχι πάντα 1yr×N· proof: .eu 2yr=26.50 ≠ 13.50×2) |
| `currency`(3) | default `EUR` |
| `cost`(14,2) | synced από registrar (→ EUR) |
| `price`(14,2) | τιμή πώλησης (derived/editable) |
| `is_enabled` | `transfer` συνήθως 1-year μόνο· `-1`/disabled ανά term |

Unique `(domain_tld_id, operation, years, currency)`.

### 3.4 `domains` — ο πυρήνας

| col | σημείωση |
|---|---|
| `company_id`, `legacy_id` | `legacy_id` = WHMCS `tbldomains.id` (import upsert) |
| `customer_id` | FK `restrictOnDelete` |
| `service_contract_id` | FK `nullOnDelete` — **το billing clock** |
| `domain_tld_id`, `registrar_connection_id` | FK (routing· `domains` value wins, TLD = default hint) |
| `sld`(190), `tld`(30), `fqdn`(190) | unique `(company_id, fqdn)`· `fqdn` = derived, authoritative = `sld`+`tld` |
| `status`(30) | `active\|pending_register\|pending_transfer\|expired\|grace\|redemption\|cancelled\|deleted` |
| `registered_at`, `expires_at` (date) | **`expires_at` = REGISTRAR truth** (sync clock) |
| `auto_renew`, `transfer_lock`, `whois_privacy`, `dnssec_enabled`, `consent_publish` | bool |
| `registrar_domain_id` | **Openprovider numeric `{id}`** (όχι το fqdn· resolve via `?full_name=`) |
| `idn_script` | για .ελ / IDN |
| `last_synced_at`, `sync_error` | |
| overrides (nullable) | `grace_days_override`, `redemption_days_override`, `fee_override`, `price_override` → fallback στο TLD |
| `module_meta` json, timestamps, softDeletes | |

Traits: `BelongsToCompany, HasFactory, SoftDeletes, TracksActivity, HasAttachments, HasInternalNotes`
+ `company()` BelongsTo + `customer()`, `serviceContract()`, `tld()`, `registrarConnection()`,
`nameservers()`, `hosts()`, `contacts()`, `reminders()`, `logs()`.

**Δύο ορθογώνια clocks** (όπως `local_status` × `mydata_state`): `domains.expires_at` (registrar
truth, pull από sync) vs `ServiceContract.next_due_date` (billing). Reconciled, ποτέ conflated.

### 3.5 `domain_nameservers` — delegation NS
`company_id, domain_id`(cascade)`, host`(190)`, sort_order`. Τα nameservers που **χρησιμοποιεί** το
domain (ns1.myip.gr…).

### 3.6 `domain_hosts` — child/glue hosts («δικά μου nameservers»)
`company_id, domain_id`(cascade)`, host`(190)`, ipv4, ipv6, sort_order`. Glue records
**registered ΣΤΟ registry** για `ns1.thisdomain.gr → IP` (≠ delegation· WHMCS
`RegisterNameserver/ModifyNameserver/DeleteNameserver`).

### 3.7 `domain_contacts` — registrant/admin/tech/billing (MODULAR)
Ξεχωριστή first-class οντότητα που **κατέχει το domain** (όχι το CustomerContact).
`company_id, domain_id`(cascade)`, type`(20 = `registrant|admin|tech|billing`)`, name, org, email,
phone, address1/2, city, postcode, country, registrar_contact_handle`. Το `registrar_contact_handle`
κρατά το **Openprovider handle** (`AB123456-XX`) + κρατάμε δικό μας αντίγραφο των πεδίων.
**Pre-fill από τον ekdosi Customer με ρητή επιβεβαίωση** ανά καταχώρηση — ο Customer είναι
convenience default, NOT hardwired· τα contacts μπορούν να αποκλίνουν.

### 3.8 `domain_reminders` — sent-log υπενθυμίσεων λήξης
`company_id, domain_id, reminder`(π.χ. `15_days`/`10_days`/`5_days`)`, to_email, sent_at`. Reuse του
auto-email infra. (WHMCS: 15/10/5-days-before-expiry με history.)

### 3.9 `domain_registrar_logs` — API history («Bridge logs»-style)
`company_id, domain_id`(nullable)`, registrar, operation, request`(json/text)`, response`(json/text)`,
`status, created_at`. Πλήρες request/response ανά registrar κλήση — audit + debugging. Ίδιο pattern
με `mod_ekdosi_bridge_log` + `mydata_marks`. First-class, όχι optional.

---

## 4. Το `DomainRegistrar` contract (modular seam)

Copy του `app/Contracts/EInvoiceProviderTransport.php` idiom: contract + config registry +
credentials value-object + factory + Null.

### 4.1 `App\Contracts\DomainRegistrar`
Ο σκελετός γεννιέται 1:1 από το **επίσημο WHMCS registrar function index** (κάθε method
αντιστοιχεί σε Filament action):

```
key(): string
capabilities(): DomainRegistrarCapabilities
ping(creds): bool

checkAvailability(fqdn, creds): AvailabilityResult
register(DomainRegisterRequest, creds): RegistrarResult
renew(domain, years, creds): RegistrarResult
transfer(DomainTransferRequest, creds): RegistrarResult   // create
transferApprove(domain, creds): RegistrarResult           // outgoing/incoming approve
requestDelete(domain, creds): RegistrarResult

getNameservers(domain, creds) / setNameservers(domain, ns[], creds)     // delegation
registerHost/modifyHost/deleteHost(domain, host, creds)                 // child/glue
getContacts(domain, creds) / setContacts(domain, contacts, creds)       // → handles
getEppCode(domain, creds)
getLock(domain, creds) / setLock(domain, bool, creds)
toggleIdProtect(domain, bool, creds)
getDnssec(domain, creds) / setDnssec(domain, keys[], creds)   // OUR addition (WHMCS has none)
getDns(domain, creds) / setDns(domain, records[], creds)      // DEFERRED addon (flag only v1)

syncDomain(domain, creds): DomainSyncResult      // {active, expired, cancelled, transferredAway, expirydate}
syncTransfer(domain, creds): TransferSyncResult  // completed(+expirydate) | failed(+reason) | pending
getTldPricing(creds): TldPricingResult[]         // ImportItem-shape → pricing-sync
```

Value objects (immutable): `AvailabilityResult`, `RegistrarResult`, `DomainSyncResult`,
`TransferSyncResult`, `TldPricingResult` — ενιαία σε όλους τους registrars.

### 4.2 Registry + creds + factory
- `App\Services\Domains\DomainRegistrarRegistry` — config-driven `config('ekdosi.domains.registrars')`
  (νέο sibling των `billing.sources`/`provisioning.modules`/`einvoice.providers`). Idiom
  **«Null που ρίχνει»** (όπως `ProviderTransportRegistry`) — register/renew = money/state path.
- `App\Support\Domains\DomainRegistrarCredentials` — **αυτούσια η λογική του `ProviderCredentials`**:
  free-form encrypted key/value bag + sandbox flag, από το `domain_registrar_connections.config`·
  νέος registrar ποτέ δεν θέλει schema change.
- `App\Services\Domains\DomainRegistrarFactory` — `for(DomainRegistrarConnection): DomainRegistrar`,
  typed `DomainRegistrarNotConfigured`.
- `App\Services\Domains\NullDomainRegistrar` — no-op/throws → καταχώρηση domains by hand χωρίς API (A1).

### 4.3 Openprovider adapter — endpoint mapping (από module source + swagger)
- **Auth:** `POST /v1beta/auth/login` (username + **plaintext** password/HTTPS — το MD5 hash/token
  είναι legacy XML-RPC, ΟΧΙ REST) → bearer, **~24h TTL** (cache + re-auth σε 401). Prod
  `https://api.openprovider.eu`.
- **Sandbox:** `http://api.sandbox.openprovider.nl:8480/v1beta/` (HTTP, :8480, `.nl`) + **ξεχωριστό**
  sandbox account. Το παλιό `*.cte.openprovider.eu` αποσύρθηκε. Επιλογή = swap base URL + creds →
  κουμπώνει στο `DomainRegistrarCredentials` sandbox flag.

| Contract method | Openprovider API |
|---|---|
| `ping` | `POST /v1beta/auth/login` |
| `checkAvailability` | `POST /v1beta/domains/check` |
| `register` | ensure handles (`POST /v1beta/customers`) → `POST /v1beta/domains` |
| `renew` | `POST /v1beta/domains/{id}/renew` |
| `transfer` | `POST /v1beta/domains/transfer` (+ `…/{id}/transfer/approve`, `…/send-foa1`) |
| `syncDomain` | `GET /v1beta/domains/{id}` → `expiration_date`, `status`, `is_locked`, `autorenew` |
| `setNameservers` / lock / privacy / dnssec / contacts / autorenew | **`PUT /v1beta/domains/{id}`** (ένα call τα κάνει όλα: `name_servers`, `is_locked`, `is_private_whois_enabled`, `is_dnssec_enabled`+`dnssec_keys[]`, `owner/admin/tech/billing_handle`) |
| `getEppCode` | `GET /v1beta/domains/{id}/authcode` (reset: `POST …/authcode/reset`) |
| registrant change | `POST /v1beta/domains/trade` (ICANN IRTP) |
| restore/redemption | `POST /v1beta/domains/{id}/restore` |
| glue hosts | `GET|POST /v1beta/dns/nameservers`, `PUT|DELETE …/{name}` |
| `getTldPricing` | `GET /v1beta/domains/prices`, `GET /v1beta/tlds/{name}` (requirements/additional-data) |

**3 gaps για σχεδίαση:** (α) **κανένα arbitrary WHOIS** endpoint — μόνο availability + το δικό-σου
domain → πραγματικό WHOIS = ξεχωριστή port-43/RDAP πηγή (`App\Services\Domains\WhoisLookup`).
(β) **Αλλαγή registrant σε gTLD = ICANN IRTP/`trade`** (FOA email + 60-day lock), ΟΧΙ σιωπηλό
`setContacts` — το μοντελοποιούμε ρητά. (γ) **Contacts = reusable handles**, όχι inline: `setContacts`
= «ensure-or-create handle πρώτα → reference `*_handle`».
**Free upside vs WHMCS:** trade, restore, retry-last-op (`…/last-operation/restart`), approve-transfer,
resend-FOA, NS/DNS templates, autorenew flag.

### 4.4 grEPP adapter (.gr direct EPP)
Ξεχωριστός EPP client (RFC 3730-3735 + FORTH custom extensions), όχι REST — το contract κρύβει τη
διαφορά. `capabilities`: `supportsPricingSync=false`, `supportsPrivacy=false`, `supportsTransferLock=false`.
**Build-time unknown:** τα ακριβή FORTH EPP host/port + OT&E δίνονται μόνο σε accredited registrars —
η MyIP τα έχει (τρέχει ήδη grEPP). Τα .gr rules (§5) ζουν στο **validation layer**, όχι στον adapter.

---

## 5. Κανόνες .gr / .ελ (validation layer — ισχύουν για OP *και* grEPP)

Πηγές: EETT (regulator) + ICS-FORTH (registry operator).
- **2ετία ΥΠΟΧΡΕΩΤΙΚΗ** (1 έτος ΔΕΝ επιτρέπεται) → `domain_tlds.min_years=2`, πολλαπλάσια 2ετίας.
- **Καμία WHOIS privacy** → `id_protection` off/disabled για .gr (ταιριάζει με το screenshot).
- **Κανένα transfer lock** → κρύψε `setLock` για .gr.
- **Το auth code το βγάζει το REGISTRY και το στέλνει email στον REGISTRANT** — ο gaining registrar
  δεν το τραβά· το transfer-in UX λέει στον πελάτη «πάρε τον κωδικό από το email του μητρώου».
- **Έλεγχος .gr ↔ .ελ homograph** (`.ελ` = `xn--qxam`, ξεχωριστό ccTLD· cross-check για confusion).
- Label rules: 1(2)–63 chars, alnum/hyphen, όχι leading/trailing/consecutive hyphens.

**Build-time unknowns (verify):** ακριβή μηχανική/τέλη «trade» (registrant change) στο .gr· αν το
public .gr WHOIS auto-redacts natural-person data υπό GDPR (το «no privacy service» είναι βέβαιο).

---

## 6. Lifecycle

### 6.1 Money timing (per operation)
- **`register` = post-payment** (νέο domain, ρίσκο): invoice → πληρωμή → `$registrar->register()`.
- **`renew` = on-issue** (υπάρχον domain, μη λήξει): ο operator οριστικοποιεί το πρόχειρο → myDATA →
  `InvoiceObserver` προωθεί τον cursor → domain post-issue hook → `$registrar->renew()`.
- **Ποτέ auto-file** στην ΑΑΔΕ — τα renewals είναι πρόχειρα (ίδια πειθαρχία με WHMCS inbox / SC).

### 6.2 Renewals (reuse `ServiceContract` 100%)
Το domain δένει σε `ServiceContract`· `next_due_date` → `App\Actions\StageServiceRenewal` κόβει
**πρόχειρο** invoice ανανέωσης. `auto_renew=on` → auto-stage draft (operator εκδίδει)· `off` → μόνο
worklist «λήγουν σύντομα». First-Payment vs Recurring = SC `setup_fee` + `amount`.

### 6.3 Transfer async state machine (drive από `syncTransfer`)
```
[create transfer] → status: pending_transfer
   nightly domains:sync-transfer → syncTransfer():
     completed(+expirydate) → status: active,  expires_at := expirydate
     failed(+reason)        → status: <prev>,  sync_error := reason,  operator alert
     empty (still pending)  → keep polling (+ FOA resend αν χρειάζεται)
```
Incoming: auth-code + `POST /domains/transfer`. Outgoing (φυγή): **operator-gated** v1 —
«Get EPP Code» + unlock μόνο από operator (anti-abuse, έλεγχος οφειλών)· portal self-service αργότερα.

### 6.4 Sync (nightly `domains:sync`)
Command `domains:sync --tenant=SLUG`, gated `EKDOSI_SCHEDULE_DOMAIN_SYNC`, φιλτράρει
`Company::where('enable_domain_management', true)`, per-domain `$registrar->syncDomain()` →
update `expires_at`/`status`/NS, catch+log στο `sync_error` + `domain_registrar_logs`. Bounded
`withoutOverlapping(30)`. Καταχώρηση σε **3 σημεία** (§8 του PLAN.md).

### 6.5 Grace / redemption (configurable ανά customer/TLD)
Transitions `active → expired(grace) → redemption → deleted`, οδηγούμενες από `expires_at` + sync.
Χρέωση μέσω `domain_tld_prices` (`restore`/`redemption` flat). **Configurable:** άλλοι customers/TLDs
auto-stage restore invoice, άλλοι operator-decides (κρατά τη «ποτέ auto-file» πειθαρχία όπου θες).

### 6.6 Idempotency (money-safety — lands ΜΑΖΙ με A3)
register/renew/transfer = εξωτερικά money+state. **adopt-on-retry** (ίδιο μοτίβο με
`InvoSignTransport::status()` που υιοθετεί MARK αντί να ξαναφάιλάρει): idempotency key +
`syncDomain`/`GET /domains?full_name=` πριν το ξανακαλέσεις, για το «ο registrar χρέωσε, timeout
πριν το καταγράψει το ekdosi» → διπλοχρέωση/διπλο-renew. Ο reconciler (A5) έρχεται ΜΑΖΙ με το A3.

---

## 7. Pricing (cost-sync + margin engine)

3-tier resolution: **TLD price → per-customer discount (υπάρχει) → per-domain override (nullable)**.

**Cost-sync** (registrars με `supportsPricingSync`, δηλ. Openprovider): command
`domains:sync-pricing --tenant --registrar` καλεί `getTldPricing()` → cost (→ **EUR**, system
currency) γράφεται στο `domain_tld_prices.cost`. **Margin engine:** `margin_type`
(percentage/fixed) + `margin_value` (π.χ. 20%) + `round_to` → derived sell `price`, με per-TLD
manual override + toggle «sync grace/redemption fee με το ίδιο markup». grEPP = manual pricing.
Explicit per-year τιμές (1–10). (Ακριβώς το «TLD Import & Pricing Sync» screen της WHMCS.)

---

## 8. Filament UI

### 8.1 Gating (per-tenant + permission)
Shared trait `GatesOnDomainManagement` με `canAccess()`/`shouldRegisterNavigation()` =
`Filament::getTenant() instanceof Company && Filament::getTenant()->enable_domain_management &&
auth()->user()?->can('View:Domain')`. Κάθε Domains resource/page το κουβαλά (δεν υπάρχει
group-level toggle). Πρότυπο: `ExpenseClassificationRuleResource::canAccess()`.

### 8.2 `DomainResource` + η πλούσια per-domain View (RICHER από WHMCS)
Ο ιδιοκτήτης: η WHMCS per-domain οθόνη είναι «φτωχή» (NS + dates + buttons). Η δική μας View
προσθέτει inline:
- **Contacts PARSING** (η βασική ένσταση): registrant/admin/tech/billing **parsed + editable
  inline** (`DomainContactsRelationManager`/structured section από `getContacts`), όχι bare button.
- Identity/dates: registrar, status, registered/expiry/next-due· **NS1-5 + «reset to default»** +
  glue hosts· toggles **Registrar Lock / DNSSEC / ID-Protection** (+ DNS-Management/Email-Fwd
  deferred)· **IDN script**· **«Consent to Publish»** (GDPR redaction, default redacted).
- **Registrar command actions** (1:1 με το contract): Έλεγχος διαθεσιμότητας · Καταχώρηση · Μεταφορά ·
  Ανανέωση · Modify Contacts · Get EPP Code · Request Delete · ID Protection on/off · Auto-renew Sync · Sync.
- **Reminder history** tab (`domain_reminders`) · **API history** tab (`domain_registrar_logs`,
  «Bridge logs»-style) · shared `ActivityLog`/`Attachments`/`InternalNotes` RMs.
- Filament-5 idioms: `Schema`/`configure`, `recordActions`/`toolbarActions`, `extends
  BaseListRecords`, strip `SoftDeletingScope` στο `getEloquentQuery`.

**Lookup resources:** `DomainTldResource` (+ related `DomainTldPrice`, με το «Pricing/margin» panel) =
company_admin/operator· `DomainRegistrarConnection` creds = **super_admin only**. Widget «Domains
που λήγουν» (mirror `UpcomingRenewalsTable`).

### 8.3 Permissions (Shield)
`company_admin` κληρονομεί αυτόματα τα `Domain*` perms (default-allow, `ADMIN_FORBIDDEN_RESOURCES`).
`operator` θέλει ρητή εγγραφή στο `OPERATOR_PERMISSION_MAP` (`'Domain' => ['ViewAny','View','Create','Update']`).
Μετά deploy: `shield:generate` + re-provision.

---

## 9. ekdosi-native features (πέρα από WHMCS parity)

- **«Μεταφορά ιδιοκτησίας»** — action στο `Domain` που αλλάζει `domains.customer_id` (+ του linked
  `ServiceContract.customer_id`, ώστε οι μελλοντικές ανανεώσεις να χρεώνουν τον νέο πελάτη), με search
  πελάτη (όνομα/ΑΦΜ/email). **Τα ιστορικά τιμολόγια μένουν** στον παλιό (νομικό record)· προαιρετικά
  προσφέρει και αλλαγή registrant (= registrar `trade`/IRTP, όχι αυτόματα). Audit-logged.
  **≠** «Μεταφορά μητρώου/registrar» (EPP σε άλλον registrar) — ξεχωριστά ονόματα στο UI.
- **Expiry reminders** — 15/10/5 ημέρες πριν, reuse του auto-email infra, sent-log στο `domain_reminders`.
- **API history** — `domain_registrar_logs` (request/response/status ανά κλήση), «Bridge logs» tab.
- **Import** — command `domains:import --tenant`:
  - **WHMCS `tbldomains`** (customer/τιμή/registrar/reminder linkage, upsert σε `legacy_id`),
  - **+** registrar sync (Openprovider/grEPP) για επαλήθευση expiry/status/NS.
  - Import-first· manual entry (A1) = fallback, όχι main path.

---

## 10. Phase gates (A0–A5 — σταματάς όπου θες)

| Φάση | Τι | Gate (done-when) |
|---|---|---|
| **A0** Θεμέλιο | `enable_domain_management` flag + nav-gating trait + `DomainRegistrar` contract/registry/creds/Null + `ekdosi.domains.registrars` + `domain_registrar_connections` (super_admin creds) | Ενεργοποιείς tenant → βλέπεις **κενή, gated** περιοχή «Domains» |
| **A1** Data model + manual CRUD | Όλοι οι πίνακες + `DomainResource` (rich View) + link σε `ServiceContract` + **import** (`tbldomains`) | Το υπάρχον portfolio φορτώνεται/καταχωρείται, expiry+renewals ορατά, **μηδέν registrar API** |
| **A2** Openprovider read-only | `checkAvailability` + WHOIS (`WhoisLookup`) + `syncDomain`/`syncTransfer` + `getTldPricing` (sandbox) | Nightly `domains:sync` + pricing-sync «ανάβουν»· καμία state-changing εγγραφή |
| **A3** Openprovider write | register(post-pay)/renew(on-issue)/transfer(in+out, state machine)/NS/glue/contacts(handles+trade/IRTP)/DNSSEC/privacy/lock + renewal billing + grace/redemption + **idempotency + reconciler** | Πλήρης κύκλος end-to-end (sandbox→prod), operator-gated invoices |
| **A4** grEPP (.gr) | 2ος adapter (EPP), .gr validation rules (2ετία/no-privacy/no-lock/registry-auth-code/homograph) | .gr/.ελ end-to-end· το abstraction αποδεδειγμένο (2ος registrar = adapter, όχι rewrite) |
| **A5** Polish | bulk availability search, portfolio dashboard, **registrar↔local reconciliation** (mirror myDATA reconcile), «Μεταφορά ιδιοκτησίας», reminders polish | Δύο clocks reconciled· worklist ασυμφωνιών |

---

## 11. Non-goals / deferred / build-time unknowns

**Deferred (flags/hooks μένουν, λειτουργία μετά):**
- **DNS zone/record hosting** (A/AAAA/MX/TXT) + **email forwarding** — capability flags στο
  `domain_tlds` τώρα, `getDns/setDns` στο contract, αλλά UI/λειτουργία post-v1.
- **Customer portal** (Πυλώνας D) — ξεχωριστό doc/phase· εκεί το transfer-out γίνεται self-service.
- **CentralNic** adapter — legacy (η MyIP έφυγε)· μπαίνει αργότερα ως drop-in αν χρειαστεί.
- **Multi-currency invoicing** — EUR settlement v1· USD = display.

**Build-time unknowns (verify πριν το coding):**
- FORTH/grEPP EPP host/port + OT&E credentials (δίνονται σε accredited registrars — η MyIP τα έχει).
- Openprovider bearer token TTL (~24h· cache + re-auth σε 401 ανεξαρτήτως).
- .gr «trade» (registrant change) μηχανική/τέλη· .gr WHOIS GDPR auto-redaction.
- Sandbox accounts (Openprovider `*.sandbox.openprovider.nl` + grEPP OT&E) πριν το A2/A4.

**Πάντα:** operator-gated legal docs (ποτέ auto-AADE)· sandbox-first + mock-HTTP tests (mirror
`MyDataSubmitterSafetyTest`)· κάθε shipped slice → `FEATURES.md` + `CHANGELOG.md` + move BACKLOG item.

---

## Πηγές
- ekdosi templates: `app/Contracts/EInvoiceProviderTransport.php` · `app/Support/EInvoice/ProviderCredentials.php`
  · `app/Services/EInvoice/ProviderTransportRegistry.php` · `app/Models/BillingConnection.php` ·
  `app/Support/Billing/SourceCapabilities.php` · `app/Actions/StageServiceRenewal.php` ·
  `app/Casts/MaybeEncrypted.php` · `app/Services/TenantRoleProvisioner.php` · `config/ekdosi.php`.
- Openprovider: module `openprovider/Openprovider-WHMCS-domains`, swagger `openprovider/api-documentation`
  (`domain/auth/dns/reseller-customer.swagger.json`).
- WHMCS registrar function index: developers.whmcs.com/domain-registrars (Function Index, Domain
  Syncing, TLD & Pricing Sync).
- .gr/.ελ: EETT (regulator) + ICS-FORTH (registry) — 2yr term, no privacy, registry-emailed auth code, .ελ = xn--qxam.
