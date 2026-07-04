# PLAN — Ekdosi ως σταδιακή αντικατάσταση του WHMCS

> Master roadmap. Στρατηγικό έγγραφο, **όχι** ticket list — τα εκτελέσιμα items
> ζουν στο `docs/BACKLOG.md`, το «τι χτίστηκε» στο `FEATURES.md`. Εδώ ζει το
> **όραμα, η σειρά, και οι κλειδωμένες αρχιτεκτονικές αποφάσεις**.
>
> Πλαίσιο: MyIP θέλει να «βγει» σταδιακά από το WHMCS, **αργά και σταθερά**,
> χωρίς big-bang. Δεν ξαναγράφουμε τα 40 provisioning modules ούτε τους 30
> τρόπους πληρωμής του WHMCS — μόνο ό,τι χρησιμοποιεί όντως η MyIP, πίσω από ένα
> **modular / plugin** seam (νέος registrar / gateway / provisioning target =
> «μία κλάση + μία γραμμή config»).

---

## 0. Ετυμηγορία (TL;DR)

**Είναι doable — και το δύσκολο έχει ήδη γίνει.** Το ekdosi κάνει ήδη invoices/
παραστατικά, myDATA, CMR/ΔΑ, **recurring services** (πλήρες engine), υπόλοιπα/
Καρτέλα, πιστωτικά, και έχει multi-tenant + ρόλους. Αυτό που κρατά ακόμα το WHMCS
είναι το **hosting-business layer**: domains, online χρέωση, provisioning
αυτοματισμοί, και portal πελάτη.

**Η σειρά:** `Domains → Payment gateways → Provisioning modules → Customer portal`.

**Ο τρόπος:** **strangler-fig**, όχι big-bang. Το WHMCS μένει authoritative ανά
περιοχή μέχρι κάθε πυλώνας να αποδειχθεί· το `billing_connections` (Phase 0 ήδη
χτισμένο) επιτρέπει ekdosi-native και WHMCS να συνυπάρχουν per tenant.

**Γιατί είναι κυρίως καλωδίωση, όχι green-field:** το ekdosi έχει ήδη **ένα
modular pattern, επαναλαμβανόμενο 5×**, και **δύο από τους τέσσερις πυλώνες
έχουν ήδη seam** στο δέντρο (`ProvisioningModule` contract υπάρχει·
`docs/payment-connectors.md` είναι ήδη σχεδιασμένο). Το recurring-billing engine
προβλέφθηκε ρητά για domains (`ServiceContract` migration φέρει ήδη `domain`,
`server_id`, `provisioning_module`, `module_meta`).

---

## 1. Τι έχει ΗΔΗ το ekdosi vs τι κρατά το WHMCS

| Το ekdosi **έχει ήδη** | Το WHMCS **κρατά ακόμα** |
|---|---|
| Invoices / παραστατικά + numbering per type | **Domains** (registrar APIs, WHOIS, NS/DNSSEC, contacts) |
| **myDATA** submit / reconcile / audit (`mydata_marks`) | **Online payment capture** (κάρτα/IRIS/PayPal) |
| CMR + Ψηφιακά ΔΑ (lifecycle) | **Provisioning** αυτοματισμοί (cPanel/DA/Proxmox…) |
| **Recurring services** (`ServiceContract` + renewals + **dunning**) | **Customer self-service portal** |
| Πληρωμές + Καρτέλα + aged receivables | |
| Πιστωτικά, προσφορές | |
| `PaymentMethod` **incl. myDATA §8.12 type** (μετρητά/κάρτα/πίστωση…) | |
| WHMCS inbox/bridge (draft-first), `billing_connections` seam | |
| Multi-tenant (`company_id`) + Shield ρόλοι (super/admin/operator) | |

**Οι 4 πυλώνες = ακριβώς αυτό το κενό.** Τίποτα παραπάνω — δεν χτίζουμε ό,τι δεν
χρειάζεται η MyIP.

> **Σημείωση για «τρόπους πληρωμής»:** οι *τρόποι* πληρωμής (`PaymentMethod` +
> myDATA §8.12 + Filament CRUD) **υπάρχουν ήδη πλήρως**. Το κενό είναι τα *online
> payment **gateways*** (Πυλώνας B) — άλλο πράγμα.

---

## 2. Το επαναχρησιμοποιήσιμο θεμέλιο (το «modular seam» που ήδη υπάρχει)

Κάθε «pluggable provider» στο ekdosi ακολουθεί **ΤΟ ΙΔΙΟ idiom** (5 φορές ήδη).
**Κάθε νέος πυλώνας είναι η 6η/7η υλοποίησή του — όχι νέα εφεύρεση:**

1. **Contract** στο `app/Contracts/` με `key()` + μεθόδους συμπεριφοράς.
2. **Config-driven registry** — `config/ekdosi.php` χάρτης `key => Class`
   («νέο X = μία γραμμή config + μία κλάση, κανένα core edit»).
3. **Null object** για την περίπτωση `none`/off.
4. **Per-tenant επιλογή** — είτε στήλη στο `companies`, είτε registry table
   (`billing_connections`) όταν ο tenant θέλει πολλά.
5. **Encrypted creds** μέσω `MaybeEncrypted` cast → immutable value object.
6. **Per-tenant factory** που ρίχνει typed `*NotConfigured`.

**Υπάρχουσες υλοποιήσεις (τα templates που αντιγράφουμε):**

| Concern | Contract | Registry / config key |
|---|---|---|
| E-invoice submitter (ΠΟΙΟΣ υποβάλλει) | `EInvoiceSubmitter` | `EInvoiceSubmitterFactory` (routing σε `einvoice_provider`) |
| Provider transport (ΠΩΣ μιλά σε πάροχο) | `EInvoiceProviderTransport` | `ekdosi.einvoice.providers` |
| Billing sources (bridges) | `BillingSource` | `ekdosi.billing.sources` |
| **Provisioning** (service lifecycle) | `ProvisioningModule` | `ekdosi.provisioning.modules` |
| Backup destinations | `BackupDestination` | `ekdosi.backup.destinations` |

**Τα πιο σχετικά αρχεία-πρότυπα:**
- **Transport πίσω από contract** (ο πλησιέστερος ανάλογος ενός registrar API
  client): `app/Contracts/EInvoiceProviderTransport.php` +
  `app/Services/EInvoice/ProviderTransportRegistry.php` +
  `app/Support/EInvoice/ProviderCredentials.php` +
  `app/Services/EInvoice/Transports/InvoSignTransport.php`.
- **«Μία εταιρεία → πολλοί εξωτερικοί λογαριασμοί»**:
  `app/Models/BillingConnection.php` + `billing_connections` +
  `app/Services/Billing/BillingSourceRegistry.php`.
- **Lifecycle hooks**: `app/Contracts/ProvisioningModule.php`
  (`create/suspend/unsuspend/terminate`).
- **Creds at-rest**: `app/Casts/MaybeEncrypted.php`.
- **Gating / scheduler house rules**: §8.

---

## 3. Πυλώνας A — Domains (ο αναλυτικός· πρώτος στη σειρά)

Ξεκινάμε με **Openprovider** (τον χρησιμοποιούμε ήδη· υπάρχει open-source WHMCS +
Blesta module για να δανειστούμε δομή), μετά **GR-Forth** (.gr/.ελ). Το seam
είναι αρκετά modular ώστε αργότερα .eu / ResellerClub / Tucows / Enom / Namecheap
= νέο adapter, όχι rewrite.

### 3.1 Data model — dedicated `Domain` (απόφαση κλειδωμένη)

Ξεχωριστό `Domain` model, **συνδεδεμένο με `ServiceContract`** για το billing
clock (reuse renewals + dunning ως έχουν). Τα domain-specific ζουν σε **κανονικές
στήλες**, όχι JSON. Όλοι οι πίνακες tenant-owned (surrogate `id`, `company_id` FK
cascade, `legacy_id` nullable unique-per-tenant, `timestamps`, `softDeletes`,
tenant-first composite indexes, `decimal(14,2)` money, **χωρίς** per-migration
charset — όπως ΟΛΑ τα υπάρχοντα migrations).

- **`domain_registrar_connections`** — per-tenant registrar *λογαριασμοί* (mirror
  του `billing_connections`· superset που μπορεί να «μαζευτεί» σε στήλη αργότερα).
  `company_id, registrar(40)` = key (`openprovider`/`gr-forth`/`none`), `label`,
  `is_active`, `mode(20)` (sandbox/production/off), `config` **encrypted JSON**
  (`MaybeEncrypted::class.':array'`). **Χωρίς** unique σε `(company_id,
  registrar)` — ο tenant μπορεί να έχει δύο λογαριασμούς ίδιου registrar.
  Επεξεργασία credentials = **super_admin only** (όπως τα einvoice provider creds).
- **`domain_tlds`** — per-tenant κατάλογος TLD + **κανόνες**: `registrar_connection_id`
  (ποιος registrar χειρίζεται το TLD), `tld(30)` (`gr`,`com`,`eu`,`ελ`…),
  `min_years, max_years` (.gr → min **2**), `min_chars, max_chars` (generic min
  **3**), `allow_transfer`, `allow_idn`, `grace_period_days`,
  `redemption_period_days`, `is_active`, `notes`. Unique `(company_id, tld)`.
- **`domain_tld_prices`** — τιμή ανά TLD **ανά operation** (η ασυμμετρία
  register/renew/transfer/restore που το `ProductBillingPrice` δεν εκφράζει):
  `domain_tld_id`, `operation(20)` ∈ `register|renew|transfer|restore|redemption`,
  `cost(14,2)` (αγορά από registrar), `price(14,2)` (πώληση), `currency(3) EUR`,
  `is_enabled`. Τιμή ανά έτος (πολλαπλασιάζεται)· restore/redemption = flat.
  Unique `(domain_tld_id, operation)`.
- **`domains`** — ο πυρήνας: `customer_id` (FK restrictOnDelete),
  `service_contract_id` (FK nullOnDelete — **το billing clock**), `domain_tld_id`,
  `registrar_connection_id`, `sld(190)`, `tld(30)`, `fqdn(190)` unique
  `(company_id, fqdn)`, `status(30)` ∈ `active|pending_register|pending_transfer|
  expired|grace|redemption|cancelled|deleted`, `registered_at(date)`,
  `expires_at(date` — **η αλήθεια του REGISTRAR**, το sync clock`)`, `auto_renew`,
  `transfer_lock`, `whois_privacy`, `dnssec_enabled`, `epp_code` **encrypted**
  nullable, `registrar_domain_id`, `last_synced_at`, `sync_error`,
  `module_meta(json)`. Traits: `BelongsToCompany, HasFactory, SoftDeletes,
  TracksActivity, HasAttachments, HasInternalNotes` + `company()` BelongsTo.
- **`domain_nameservers`** — relational (όπως το `customer_contacts`, όχι JSON):
  `domain_id`, `host(190)`, `ipv4`, `ipv6`, `sort_order`. Καλύπτει «own/private
  nameservers» + glue.
- **`domain_contacts`** — registrant/admin/tech/billing (το EPP θέλει contact
  handles): `domain_id`, `type(20)`, `name`, `org`, `email`, `phone`,
  `address1/2`, `city`, `postcode`, `country`, `registrar_contact_handle`.
  Default από `CustomerContact`· split μόνο όταν ο registrar θέλει διακριτά
  handles.
- **(προαιρετικό) `domain_registrar_logs`** — audit των registrar API calls
  (mirror του `mod_ekdosi_bridge_log`)· ξεκινάμε με `TracksActivity` +
  `sync_error` και προσθέτουμε τον πίνακα μόνο αν το debugging το ζητήσει.

**Δύο ορθογώνια clocks** (όπως `local_status` × `mydata_state`):
`domains.expires_at` = η αλήθεια του **registrar** (pull από sync)·
`ServiceContract.next_due_date` = το **billing** clock. Reconciled, ποτέ
conflated — «domain προς ανανέωση» = billing event· «domain λήγει στον
registrar» = sync fact. **Χρειάζεται worklist σελίδα** σαν τον myDATA reconciler
(§3.6/A5) — μην το ξεχάσουμε.

### 3.2 Registrar module abstraction (αντιγραφή του `EInvoiceProviderTransport`)

- `App\Contracts\DomainRegistrar` — `key()`, `checkAvailability(fqdn, creds)`,
  `whois(fqdn, creds)`, `register(request, creds)`, `renew(domain, years, creds)`,
  `transfer(request, creds)`, `setNameservers`, `setContacts`, `setDnssec`,
  `setTransferLock`, `setPrivacy`, `syncExpiry(domain, creds)` (pull
  expiry/status/NS), `ping(creds)`. Ενιαία value objects
  `RegistrarResult`/`AvailabilityResult`/`WhoisResult` σε όλους τους registrars.
- `App\Services\Domains\DomainRegistrarRegistry` — config-driven μέσω νέου
  `config('ekdosi.domains.registrars')` (sibling των `billing.sources` /
  `provisioning.modules` / `einvoice.providers`)· idiom **«Null που ρίχνει»**
  (όπως `ProviderTransportRegistry`), γιατί register/renew είναι money/legal/state
  path.
- `App\Support\Domains\DomainRegistrarCredentials` — **αυτούσια η λογική του
  `ProviderCredentials`**: free-form encrypted key/value bag + sandbox flag, από
  το `domain_registrar_connections.config`· νέος registrar ποτέ δεν θέλει schema
  change.
- `App\Services\Domains\DomainRegistrarFactory` — `for(DomainRegistrarConnection):
  DomainRegistrar`, typed `DomainRegistrarNotConfigured`.
- `App\Services\Domains\NullDomainRegistrar` — no-op/throws → επιτρέπει να
  **καταχωρείς by hand domains που ήδη κατέχεις**, χωρίς API.

### 3.3 WHOIS

`App\Services\Domains\WhoisLookup`: χάρτης whois-server ανά TLD (config· π.χ.
`.gr` → Forth, `.com` → Verisign) με **RDAP** όπου υπάρχει, **+ registrar-API
fallback** (`$registrar->whois()`, που Openprovider/Forth εκθέτουν). Ξεκινάμε με
registrar-API WHOIS + γενικό port-43/RDAP fallback.

### 3.4 Lifecycle, ανανεώσεις & sync (reuse `ServiceContract` 100%)

- Ένα `Domain` δένει σε ένα `ServiceContract`· όταν έρθει το `next_due_date` →
  `App\Actions\StageServiceRenewal` κόβει **πρόχειρο** τιμολόγιο ανανέωσης
  (operator-gated, **ποτέ auto-AADE** — ίδια πειθαρχία με κάθε νομικά σημαντικό
  έγγραφο). Ο operator το οριστικοποιεί → myDATA· ο `InvoiceObserver` προωθεί τον
  cursor. Ένα **domain post-issue/paid hook** μετά καλεί `$registrar->renew()`.
  `auto_renew` gate-άρει το αυτόματο staging.
- **Grace/redemption:** μεταβάσεις `active → expired(grace) → redemption →
  deleted`, οδηγούμενες από `expires_at` + nightly sync· χρέωση μέσω
  `domain_tld_prices` operation (`restore`/`redemption` flat).
- **Nightly `domains:sync --tenant=SLUG`**: φιλτράρει
  `Company::where('enable_domain_management', true)`, per-domain
  `$registrar->syncExpiry()` → ενημερώνει `expires_at`/`status`/NS, catch+log στο
  `sync_error`. Καταχώριση σε **3 σημεία** (§8).

> **Προγραμματιστικό API τιμολόγησης** (για κάθε renew/register→invoice):
> `App\Actions\CreateInvoice` **ΔΕΝ υπάρχει**. Χρησιμοποίησε το idiom
> `InvoiceNumberer::allocate` → `Invoice::create` → `InvoiceLine::create` →
> `RecomputeInvoiceTotals` **μέσα σε ΕΝΑ `DB::transaction`** — αντίγραψε το
> `app/Actions/StageServiceRenewal.php` σχεδόν αυτούσιο.

### 3.5 Filament UI (gated)

- `App\Filament\Resources\Domains\DomainResource` (+ Pages List/Create/Edit/View,
  `Schemas/DomainForm`, `Tables/DomainsTable`, RelationManagers για nameservers/
  contacts + κοινά `ActivityLog`/`Attachments`/`InternalNotes` RMs). Filament-5
  idioms: `Schema`/`configure`, `recordActions`/`toolbarActions`,
  `extends BaseListRecords`, strip `SoftDeletingScope` στο `getEloquentQuery`.
- **Lookup resources:** `DomainTldResource` (+ related `DomainTldPrice`) =
  company_admin/operator· `DomainRegistrarConnection` creds = super_admin only.
- **Actions:** «Έλεγχος διαθεσιμότητας», «WHOIS», «Καταχώρηση», «Ανανέωση»,
  «Μεταφορά», «Sync τώρα», «Nameservers», «DNSSEC on/off», «Privacy on/off»,
  «Transfer lock». Widget «Domains που λήγουν» (mirror `UpcomingRenewalsTable`).

### 3.6 Σειρά χτισίματος domains (phase gates — μπορείς να σταματήσεις σε κάθε gate)

| Φάση | Τι | Gate (done when…) |
|---|---|---|
| **A0 Θεμέλιο** | `enable_domain_management` flag + nav-gating trait + `DomainRegistrar` contract + registry + credentials + `NullDomainRegistrar` + config map + `domain_registrar_connections` (super_admin creds UI). Κανένας πραγματικός registrar. | Ενεργοποιείς έναν tenant και βλέπεις **κενή, gated** περιοχή «Domains». |
| **A1 Data model + manual CRUD** | Όλοι οι πίνακες + `Domain` resource + σύνδεση με `ServiceContract`. | Ο operator **καταχωρεί το υπάρχον portfolio της MyIP με το χέρι**, βάζει expiry, βλέπει ανανεώσεις. Μηδέν API — de-risk του μοντέλου, άμεσα χρήσιμο. |
| **A2 Openprovider read-only** | `checkAvailability`, `whois`, `syncExpiry` (sandbox). | Το nightly `domains:sync` «ανάβει»· expiry/NS έρχονται από registrar. Καμία state-changing εγγραφή. |
| **A3 Openprovider write** | register/renew/transfer/NS/contacts/DNSSEC/privacy/lock· wire renewal billing + grace/redemption. | Κόβεις/ανανεώνεις domain end-to-end (sandbox→prod), το invoice βγαίνει operator-gated. |
| **A4 GR-Forth registrar** | 2ος registrar (.gr/.ελ, 2ετία min, Forth rules). | Το abstraction αποδεικνύεται — 2ος πάροχος = adapter, όχι rewrite. |
| **A5 Polish** | bulk availability search, portfolio dashboard, **registrar↔local reconciliation** (mirror myDATA reconcile), pricing-rule UX. | Δύο clocks reconciled· worklist ασυμφωνιών. |

### 3.7 Import υπάρχοντος portfolio

`domains:import` από WHMCS `tbldomains` (όπως το Firebird ETL, upsert σε
`(company_id, legacy_id)`), ή από το registrar portfolio (Openprovider list).

---

## 4. Πυλώνας B — Payment gateways

**Ήδη σχεδιασμένο:** `docs/payment-connectors.md`. Περίληψη:
- **IRIS πρώτα** (online, χωρίς hardware/PCI, εκτός POS-interconnect mandate) →
  μετά Viva/Cardlink card-POS → μετά Stripe/PayPal.
- Όλα πίσω από `App\Contracts\PaymentTerminal` / `IrisRequest` +
  `PaymentConnectorRegistry` (config `ekdosi.payments.connectors`) + **signed
  webhooks** που γράφουν το υπάρχον `Payment` model (rail-agnostic πυρήνας).
- Επιτυχία **μόνο** μέσω webhook — ποτέ synchronously, ποτέ από client flag.
- Προσοχή στο ΑΑΔΕ **POS↔ERP interconnection** mandate για κάρτα.

**Γιατί εδώ στη σειρά:** μετά τα domains (το χρειάζεται λιγότερο επειγόντως), και
**πριν** το portal — το portal πρέπει να μπορεί να εισπράττει.

---

## 5. Πυλώνας C — Provisioning modules

Το seam **υπάρχει ήδη**: `app/Contracts/ProvisioningModule.php`
(`create/suspend/unsuspend/terminate`), οδηγούμενο από `ServiceDunning`· σήμερα
μόνο `NullProvisioningModule`. Ο χάρτης `ekdosi.provisioning.modules` έχει ήδη
commented placeholders (`cpanel`/`mailcow`).

**Σχέδιο:** πραγματικά modules για **cPanel / DirectAdmin / Centova / Proxmox**,
config-mapped, με `provisioning_module` σε Product/ServiceContract, creds μέσω
`MaybeEncrypted` + value object. **Best-effort:** αποτυχία transport ΔΕΝ κάνει
roll back το local status (κατά το docblock του contract). Μπαίνει φυσικά μετά τα
gateways — τα services έχουν ήδη το billing+dunning loop.

---

## 6. Πυλώνας D — Customer portal

Το **τελευταίο** κομμάτι — χρειάζεται domains + services + payments να είναι
πραγματικά πρώτα. Custom Blades σε **ξεχωριστή public-facing περιοχή / 2ο
Filament panel** (το operator panel μένει internal, κατά CLAUDE.md
«operators-only»). Ο πελάτης βλέπει τα domains του (WHOIS/NS/ανανέωση), τις
υπηρεσίες, τα παραστατικά + myDATA QR, το υπόλοιπο Καρτέλας, και **πληρώνει μέσω
Πυλώνα B**. Auth = customer guard διακριτό από τους operator users.

Εδώ «δένουν όλα σε custom blades σε ένα interface για τον πελάτη» — μεγάλη νέα
επιφάνεια, γι' αυτό τελευταία.

---

## 7. Σειρά & γιατί

`Domains → Gateways → Provisioning → Portal`

- **Domains πρώτα** — το μεγαλύτερο κενό αξίας· η MyIP ήδη χρησιμοποιεί
  Openprovider· υπάρχει open-source WHMCS/Blesta module για δομή.
- **Gateways πριν το portal** — το portal πρέπει να εισπράττει.
- **Provisioning** — reuse του υπάρχοντος dunning seam· χαμηλότερη επείγουσα αξία.
- **Portal τελευταίο** — εξαρτάται από τους άλλους τρεις.

**Strangler-fig:** το WHMCS μένει authoritative ανά περιοχή μέχρι κάθε πυλώνας να
αποδειχθεί· το `billing_connections` μοντελοποιεί ήδη τη συνύπαρξη.

---

## 8. Cross-cutting κανόνες (ισχύουν σε κάθε πυλώνα)

- **Per-tenant opt-in:** `companies.enable_domain_management` boolean (migration
  + `$fillable` + `'boolean'` cast) — super_admin `Toggle` στο `CompanyForm`
  (όπως `ai_assistant_enabled`). **Two-key gating** (house style): per-tenant
  flag **ΚΑΙ** `View:Domain` permission **ΚΑΙ** (για cron)
  `EKDOSI_SCHEDULE_DOMAIN_SYNC`.
- **Nav visibility:** κοινό `canAccess()`/`shouldRegisterNavigation()` (σε **ΕΝΑ**
  trait/base για να μη διαφύγει) = `Filament::getTenant() instanceof Company &&
  tenant->enable_domain_management && auth()->user()?->can('View:Domain')`.
  **Δεν υπάρχει group-level toggle** — κάθε Domains resource/page το κουβαλά.
- **Permissions / Shield:** ο `company_admin` κληρονομεί αυτόματα τα νέα `Domain*`
  perms (default-allow μέσω `ADMIN_FORBIDDEN_RESOURCES = ['User','Company',
  'Role']`)· ο `operator` θέλει ρητή εγγραφή στο `OPERATOR_PERMISSION_MAP`. Μετά
  deploy: **`shield:generate` + re-provision** (`shield:sync-super-admin`).
- **Scheduler — 3 σημεία:** `config/ekdosi.php` (`schedule` block + env default),
  `routes/console.php` (`$trackSchedule(... ->when($scheduleEnabled(
  'domain_sync_enabled')) ->withoutOverlapping(30))`, per-tenant loop με φίλτρο το
  flag), και `ScheduleSettings::TASKS`/`SECTIONS` για τον UI διακόπτη. Το cron
  τρέχει **χωρίς ambient tenant** → `CompanyScope` = no-op → πέρνα `company_id`/
  `--tenant` ρητά.
- **Creds** πάντα μέσω `MaybeEncrypted` + immutable value object· per-tenant
  factory με typed `*NotConfigured`.
- **Docs discipline** (CLAUDE.md): κάθε shipped slice ενημερώνει `FEATURES.md` +
  `CHANGELOG.md` + μετακινεί το `docs/BACKLOG.md` item· το βαθύ «γιατί» →
  `docs/CLAUDE-history.md`. Όταν ξεκινήσει το A1, ένα dedicated `docs/domains/`
  design doc (σαν το `docs/paroxos/`) μπορεί να αποσχιστεί από αυτή την ενότητα.

---

## 9. Non-goals & ρίσκα

- **ΟΧΙ** τα 40 provisioning + 30 gateways του WHMCS — μόνο το σετ της MyIP
  (Openprovider + Forth· IRIS/Viva· cPanel/DA/Centova/Proxmox).
- Τα νομικά έγγραφα μένουν **operator-gated** (ποτέ auto-file στην ΑΑΔΕ) — οι
  ανανεώσεις είναι πρόχειρα, ακριβώς όπως το WHMCS inbox + οι ανανεώσεις
  `ServiceContract` σήμερα.
- **Strangler-fig cutover** — κανένα big-bang· παράλληλη λειτουργία με WHMCS ανά
  περιοχή.
- Τα registrar/gateway payloads **καρφώνονται σε ΕΝΟΣ παρόχου sandbox** στο build
  time (τα contracts μένουν provider-agnostic) — sandbox-first + mock-HTTP tests
  (mirror του `MyDataSubmitterSafetyTest`).
- **Two-clock reconciliation** (registrar expiry vs billing due) θέλει worklist
  σελίδα σαν τον myDATA reconciler — δηλωμένο εδώ ώστε να μην παραλειφθεί.

---

## Παραπομπές

- Payment gateways → `docs/payment-connectors.md`
- Bridges/connectors seam → `docs/bridges-connectors.md`
- Provisioning contract → `app/Contracts/ProvisioningModule.php`
- Recurring billing template → `app/Actions/StageServiceRenewal.php`
- Provider-transport template → `app/Contracts/EInvoiceProviderTransport.php` (+
  `app/Support/EInvoice/ProviderCredentials.php`)
- Multi-account registry template → `app/Models/BillingConnection.php`
- Ανοιχτά items / phases → `docs/BACKLOG.md` (epic «Αντικατάσταση WHMCS»)
