# Backlog / Roadmap — what's left + ideas (SINGLE SOURCE)

The **one** place for what is **deliberately not built yet** + new ideas/thoughts.
What IS built lives in **`FEATURES.md`** (the catalogue) and **`CHANGELOG.md`** (per
change) — those two are the cross-check for «τι είναι χτισμένο».

**Lifecycle of a shipped item (keep it tidy — μην χανόμαστε):**
**α)** add/confirm it in `FEATURES.md` → **β)** record it in `CHANGELOG.md` → **γ)**
delete it from here → **δ)** if it was a «known latent» note in `CLAUDE.md`, unmark it.
(Don't backfill old `CHANGELOG` versions for things shipped long ago — just ensure
`FEATURES.md` has them and drop them from here.)

Don't re-pick the **«Done recently»** or **«looks like a gap but isn't»** lists below.

> **`known-issues.md` was folded into this file (2026-09-04)** and deleted. It was a
> 283 KB readiness ledger that was **~65% already-DONE/DISARMED**; its Go-live triage
> and its ~16 genuinely-open entries live here now (see «Cutover gate» + «Provider/
> myDATA — folded from known-issues» + «Parked» below). The DONE detail sections stay
> in git history. The BUILT design-docs `leads-mini-crm.md`, `cmr-international-delivery.md`
> and `delivery-provider-split-brain.md` moved to `docs/archive/`.

---

## 🎯 Master priority index (start here — 2026-09-04)

The one ordered view of what's left. Each tier links to the detailed section below.
The rule that governs the order (learned from the ten-round P2 PRs): **a finding's
priority is not a property of the finding — it is the finding × this business × this
date.** Cutover (1 Oct provider obligation) sorts everything.

> *The **Cutover gate** (ex-TIER 0) is **CLOSED** — all bucket-A code shipped, MYD-006/007
> decided, and a live prod check (2026-09-05) found **0 real myDATA discrepancies** (the
> earlier «92/2» came from a stale devbox backup, never real). The daily dry-run on the VM
> is the operators' routine, not a backlog task. Kept as a closed record under «Cutover gate».*

> **Sequencing (owner, 2026-09-05):** the **delivery-note family is HELD until the myDATA
> API v2.0.2** ships (DEP-001) — don't start it before then. **MYD-011 (country→ISO) ✅ DONE**;
> the remaining **actionable-now** item is **PROV-005** (vendor-blocked but worth chasing the
> endpoint). TIER 2 is **not urgent** («δεν καιγόμαστε»). The **AI «Βοηθός» Phase 2c** is
> **✅ COMPLETE** (α read tools · ε ai_usage · β record_payment · ζ knowledge_search).

**TIER 1 — Real in-scope code work, next deadline (delivery-note family + provider):**
1. **Delivery-note family** — ⏳ **HELD until myDATA API v2.0.2** (DEP-001). Before the
   ψηφιακή-διακίνηση deadline, NOT 1 Oct: MYD-023 strict-refusal + already-cancelled
   adoption on ΔΑ/provider paths (**P1**, ties to PROV-015) · MYD-019 · MYD-026 · PROV-002 ·
   STOCK-001 follow-ups · unblock 9.1/9.2 + combined ΤΔΑ. One block with a 9.3 sandbox rehearsal.
2. **MYD-011 country→ISO normalization** — ✅ **DONE** (Option B): νέα καθαρή στήλη
   `country_code` σε πελάτες/προμηθευτές + ISO picker + `IsoCountry::syncCountryCode`
   (save-hook) + `ekdosi:backfill-country-codes` + ETL alignment + `suppliers.country`
   nullable/no-default (τέλος το silent-GR freeze). Οι resolvers έκδοσης διαβάζουν
   `isoCountryCode()` (zero-regression fallback). Βλ. «Done recently».
3. **PROV-005** (**P1**, vendor-blocked) — authenticated provider credential/quota
   probe; `ping()` is an unauthenticated GET. Needs an InvoSign non-issuing endpoint.
4. **WHMCS bridge Phase 2 — outbound payment sync** (money-write, opt-in, design-first).

**TIER 2 — High-value net-new features / migration tooling:**
5. **Generic CSV importer** (products/customers) — «biggest win for catalog/customer
   migration»; column-map + dry-run + tenant-scope.
6. **Cashflow / recurring-expenses** epic (accountant-gated; anti-double-count vs myDATA).
7. **Dunning ladder** (escalating 3/7/15/30-day reminders on the existing auto-email).
8. **Bank-statement import → payment match** (CSV/MT940 → proposed `Payment` rows).

**TIER 3 — Strategic epic «Αντικατάσταση WHMCS» (`PLAN.md`, largely greenfield):**
9. **Πυλώνας A — Domains** (A0→A5; design-only today, first pillar).
10. **Πυλώνας B — Payment gateways** (IRIS first → card-POS; `payment-connectors.md`).
11. **Πυλώνας C — Provisioning modules** (real cPanel/DA/… on the existing seam).
12. **Πυλώνας D — Customer portal** (2nd panel; deliberately last, needs A+B).

**TIER 4 — Blocked-on-external (not actionable now — keep parked, don't re-pick):**
- **GR Πάροχος live** + **PEPPOL Phase 2** — need real provider creds + sandbox.
- **PROV-003 archive half** — no InvoSign download endpoint. **PROV-011** — versioned
  InvoSign API contract (empirically resolved already).

**TIER 5 — Out of current tenants' scope (re-raise if scope changes):** exotic VAT
(island/ν.5057), multi-branch (MYD-010), B2G/POS (PROV-012), offline/Transmission
Failure (PROV-008), fresh-install onboarding (SETUP-001/002, OPS-001, TEST-001),
PROV-004/013/015/016. → see «Parked» below.

**TIER 6 — Tech-debt / P2 pile** (DB-state-correct; cosmetic/perf/edge). Mostly leave;
knock off the genuine one-liners opportunistically → see «Tech debt / latent». Real
small bugs worth doing: `ExpenseClassificationSubmitter` non-fillable `'date'` ·
`resolveWhmcsCustomField` `is_array` guard · `AgedReceivables` missing `escape:` (PHP 8.4).

**TIER 7 — Ideas / low-commitment** (multi-currency, shared Contacts CRM, setup
profiles per industry, AI «Βοηθός» Phase 2c). Reference only.

---

## 🚀 Cutover / go-live gate — bucket A: ✅ CLOSED (closed record)

**Cutover:** ekdosi replaces the legacy C++Builder app for real invoicing; the
ΥΠΑΗΕΣ/provider obligation lands **2026-10-01**. Delivery notes (9.x) follow on their
own ψηφιακή-διακίνηση deadline — that family is TIER 1, **not** gated on 1 Oct.

**All bucket-A code is DONE** (PROV-010, OBS-001, PROV-003-print #406, MYD-004 0% #410,
MYD-006 #413, MYD-007 code #410, PROV-006 retail-via-provider sandbox-verified 2026-09-03),
and the two residual decisions are made:
- **MYD-007 — decided.** The intra-community 0% was essentially one large invoice to
  Estonia; per-line §8.3 field + `VatExemptionGuidance` ship (GR→EE service = code 4).
  Preflight still FLAGS any reason-less/wrong 0% rows for review (no auto-guess).
- **MYD-006 — chosen.** `business_activity_type` selected per tenant (go-live gate forces
  it); income class also configurable per product-category. Per-product override = UI follow-up.
- **Discrepancies — none real.** Live prod check **2026-09-05**: **myip 0**. The earlier
  «92/2» came from a **stale devbox backup** measured while prod moved ahead (myip filing
  again, last MARK 2026-09-04; nexon's 2 were the same stale artifact) — never a real
  backlog. Watch with `app_health` / `mydata_discrepancies` going forward.
- **Cutover dry-run — ongoing, NOT a task.** Two operators run every day-one document type
  (ΤΠΥ, ΤΙΜ, ΠΙΣ + αποδείξεις λιανικής 11.x) plus edge cases on the dev/test VM **daily** —
  that IS the rehearsal, and it's enough. **PROV-007** rides along (verify InvoSign discount
  semantics cent-for-cent on one discounted invoice); no separate project.

*Runtime facts that de-risk the provider path (measured, sandbox 2026-07-07): the
InvoSign channel **de-duplicates** a blind re-POST of the same (series, ΑΑ), and
`invoice_status.php` answers in real time — so a blind retry cannot create a second
legal document on this provider. A **provider-filed 2.1/11.x cannot be cancelled at
all** (AADE `[249]`, InvoSign `[283]`); reversal is a 5.1 credit, gated to 9.3 only.*

---

## 🅱️ Provider / myDATA — folded from known-issues (after-cutover, in-scope)

Open entries from the old ledger not already tracked in their own sections below.
The dupes (MYD-023, MYD-024, PROV-001/003/005/009/017/018, delivery family) live in
the myDATA/Provider sections lower down — not repeated here.

- **PROV-011 (P2/VERIFY)** — ask InvoSign for a **versioned/written** API contract; the
  cancellation-endpoint ambiguity is already resolved empirically (`[283]`). *Blocked on vendor.*
- **DEP-001 (WATCH)** — AADE **v2.0.2** delivery-lifecycle spec gates a durable
  attempt-record for MYD-026/PROV-002; only a protocol-agnostic cache-lock is safe until then.
- **MYD-005 (P2)** — ordinary invoice XML omits the optional myDATA `measurementUnit`
  (data-fidelity enhancement; goods-tenant-conditional).
- **SETUP-004 (P2)** — Estonian (EE) tenant skips even non-AADE neutral lookups.
- **SETUP-003 (P2, was «stale»)** — only a *null* payment method defaults to cash
  silently (an unmapped-but-chosen one already warns + surfaces in preflight). Remaining
  = one config check that each tenant's methods are mapped. The originally-required
  hard-block was judged wrong.
- **OPS-003 (P2)** — shared-hosting cron/worker recipe + `ops:cron` done; the install
  completion-page deep-link is a UI follow-up.
- **TEST-001 (P2)** — no full web-installer success-path test (fresh-install quality).

---

## 🅲 Parked — out of current tenants' scope (re-raise if scope changes)

Genuinely correct findings that **cannot occur for these two single-establishment,
domestic-services tenants**. Not deleted — parked with the trigger that reactivates them.

- **Exotic VAT** — 3% (code 9), island 4% (code 6) vs ν.5057 4% (code 10), goods-export
  exemptions → the non-0% half of MYD-004 and the goods rows of MYD-007. *No island/ν.5057
  activity.* (The intra-community 0% case IS in scope — MYD-007, TIER 0.)
- **Multi-branch** — **MYD-010** (WATCH). Both tenants single-establishment; `branch=0` is truth.
- **B2G / POS scopes** — **PROV-012** (public contracts, All-in-one POS). Requirement is
  only that ekdosi not *claim* them — it doesn't.
- **Offline / Transmission Failure** — **PROV-008**. «Design with InvoSign, do not
  improvise»; at ~70 docs/month a provider outage is handled by *waiting*.
- **Provider cancellation-evidence / historical-channel freeze** — **PROV-015 / PROV-016**.
  A provider-filed 2.1/11.x can't be cancelled at all (see above), so almost no live surface.
- **Credit-compatibility matrix** — **PROV-004** (5.1/5.2/11.4). Practical fix: set ΠΙΣ = 5.1,
  one credit type configured; enforce in code later.
- **Sandbox acceptance matrix (~30 rows)** — **PROV-013**. Superseded in practice by the
  bucket-A dry-run on the document types these tenants issue. Keep as aspiration.
- **Fresh-install onboarding** — **SETUP-001 / SETUP-002 / OPS-001**. Host is installed,
  provisioned, green (`ops:health`). Product-quality for the *next* installation.
- **The whole UPD-* family** — already DISARMED; `deploy/update.sh <tag>` is the path.
  Re-arming needs UPD-001…004 closed first (see `versioning-and-updates.md`).

---

## 📚 Kept design / reference docs (indexed here, not deleted)
These are genuine specs / architecture blueprints / ops runbooks / historical records —
kept on their own, with their current status. The forward-looking work in them is
surfaced in the open-items sections further down.

- **`PLAN.md`** (repo root) — master roadmap «Ekdosi ως σταδιακή αντικατάσταση WHMCS»
  (4 πυλώνες: **Domains → Payment gateways → Provisioning → Portal**, strangler-fig).
  Domains **OPEN** (Φάσεις A0–A5, βλ. epic «Αντικατάσταση WHMCS» παρακάτω)· Πυλ. B/C έχουν
  ήδη blueprint/seam (`payment-connectors.md` · `ProvisioningModule`), Πυλ. D = νέο.
- **`domains/README.md`** — Πυλώνας A **αναλυτικό design** (pre-build): data model + `DomainRegistrar`
  contract + Openprovider endpoint mapping + .gr/grEPP rules + rich per-domain View + phase gates
  A0–A5. **DESIGN, no code yet.**
- **`paroxos/regulatory-blueprint.md`** + **`paroxos/implementation-plan.md`** — GR
  ΥΠΑΗΕΣ provider + EU PEPPOL. PEPPOL Phase 1 (UBL builder, `peppol:test-submit`) **DONE**;
  provider P0–P5 built/gated (mode=off); **PEPPOL Phase 2 + live provider = OPEN**.
- **`payment-connectors.md`** — card-POS + IRIS design. **NOT-STARTED** (blueprint).
- **`archive/leads-mini-crm.md`** — **Leads / mini-CRM** (υποψήφιοι πελάτες + χρονολόγιο επαφών +
  μετατροπή σε πελάτη + απολογισμός ανά χειριστή). **L0 + L1 + L2 + L3-όψεις (kanban/ημερολόγιο) DONE**
  (§10)· email-από-lead + AI `lead_summary` = **συνειδητά ΟΧΙ** (owner 2026-09-02). **BUILT → archived.**
- **`payments` (AR)** — core **DONE** (cockpit/allocator/bank-accounts/refunds); deferred
  connectors → `payment-connectors.md`.
- **`bridges-connectors.md`** — multi-billing-source. Phase 0 (registry seam) **DONE**;
  Phase 1 (real 2nd source) **OPEN**.
- **`ai-assistant-blueprint.md`** — in-app «Βοηθός» + external MCP. In-app chat **DONE** (§16β);
  **external MCP server DONE** (`MCP.md`, §16γ — same registry, tenant-bound token, propose-only
  writes, ops/debug tools). **OPEN follow-ups:** per-tenant OAuth binding (claude.ai multi-company),
  `connection_health` tool (WHMCS/myDATA freshness), curated-KB `knowledge_search` (item ζ below).
- **`whmcs-legacy-plugin-map.md`** — legacy WHMCS plugins → `ekdosi_bridge`. T-1/T-2 **DONE**;
  T-3 cutover **OPEN**.
- **`archive/delivery-provider-split-brain.md`** — ΔΑ provider-vs-direct-myDATA routing (architecture
  lock, **RESOLVED + provider-confirmed in writing → archived**).
- **`archive/cmr-international-delivery.md`** — CMR διεθνής φορτωτική ως αυτόνομο έγγραφο
  (Phases 1–4 **BUILT** → archived· Phase 0 = φορολογική απόφαση own-gear, non-blocking).
- **`operator-health.md`** · **`dr-without-app-key.md`** · **`go-live-usage-checks.sql.md`** —
  ops runbooks (reference).
- **`archive/`** — closed historical records (the sandbox-validation reports, the
  2026-07 production audit) — the «what happened / evidence» trail, moved out of the
  live `docs/` tree.
- **`aade/`** — the AADE myDATA + Delivery-Note specs.

---

## ✅ Done recently (so we don't re-pick them)
- **PEPPOL Phase 1** (PR #253) — provider-independent BIS 3.0 UBL builder + `peppol:test-submit`.
- **DR / «work without APP_KEY»** (PR #254) — `MaybeEncrypted` cast + `secrets:reencrypt`; default plaintext.
- **`$hidden` on secret models** (PR #255).
- **Expense classification → AADE** (PR #256) — `SendExpensesClassification` + per-line + `expenses:test-classify`.
- **FK-aware delete guard** (PR #258) — `GuardedDeleteAction`.
- **«Σύστημα» area — 3 slices** (2026-06-10): «Υγεία συστήματος» page · durable `scheduled_task_runs` + queue retry · «Ρυθμίσεις χρονοπρογραμματιστή» (audited toggles, `system_settings`).
- **«Ρυθμίσεις συστήματος» page** — global knobs (`require_2fa`, backup-alert on/off + email) ως audited live toggles· at-rest encryption + mailer status read-only.
- **Expenses polish** (2026-06-10): χειροκίνητη καταχώριση εξόδου (`source=manual`, γραμμές, tab «Χειροκίνητα», edit μόνο για manual) + ιδιωτικό PDF/scan attachment με signed download.
- **Δίγλωσσο/EN PDF** (2026-06-10): γλώσσα ανά invoice/quote (GR/EN/δίγλωσσο, default από χώρα πελάτη)· `PdfLabels` dictionary· localizes μόνο ετικέτες.
- **Withholding/fees count toward owed** (2026-06-11): `invoices.payable_total` (= gross + AADE [208] adjustment)· owed/balance/Καρτέλα/receivables/dashboard + PDF «Πληρωτέο» = payable· `invoices:backfill-payable-total`· money-consistency proven με τιμολόγιο παρακράτησης.
- **«Υπόλοιπο πελάτη» στο PDF** (2026-06-11): legacy «ΝΕΟ ΥΠΟΛΟΙΠΟ» — snapshot-at-issue (`invoices.customer_balance_snapshot`, capture στον `InvoiceObserver`), block Προηγούμενο+παραστατικό=Νέο, opt-in per-tenant (`show_customer_balance_on_pdf`) + override per-customer (`show_balance_on_pdf`)· μόνο επί πιστώσει/πιστωτικά.
- **Καρτέλα «αναλυτική παρακράτηση»** (2026-06-11): στο AR-ledger row, όταν το εισπρακτέο διαφέρει από την αξία εγγράφου (παρακράτηση/τέλη), εμφανίζεται detail «Αξία εγγράφου 1.240 · Παρακράτηση φόρου 200» κάτω από την αναφορά (page + statement PDF). Display-only — debit/credit/υπόλοιπο μένουν = payable (chose inline-detail αντί synthetic rows ώστε running-balance + paid/unpaid filters να μη χαλάνε).
- **Onboarding** (2026-06-10): DEMO seeder · `ekdosi:install` wizard (+ lookup seeding via `MyDataLookupSeeder`) · global+per-company «Δοκιμή SMTP» · export/import χωρίς passphrase.
- **myDATA console unification** (PR #272) — one «Κονσόλα myDATA» cluster (Πωλήσεις/Έξοδα/Ε3) + redirects.
- **Expenses fetch** (PR #272) — «Άντληση από myDATA» κουμπί στη λίστα Έξοδα + tip + read-only `mydata:refresh-expenses` cron (UI toggle, default OFF).
- **Already shipped earlier — docs were stale, now corrected:** ΔΑ **movement lifecycle**
  (έναρξη/παράδοση/έλεγχος μέσω `DeliveryLifecycleService`: `registerTransfer`/`confirmDelivery`/`refreshStatus`) ·
  **§8.13 μονάδες μέτρησης** + seeder (`MyDataLookupSeeder::seedMetricUnits`, `MetricUnit`) ·
  **enrich/QR από MARK** (`EnrichInvoiceFromAade`).
- **Sandbox round 2 ✅** (2026-06-10) — ΔΑ lifecycle + νέοι taxTypes (fees/stamp/deductions) +
  product-linked taxes + 4% override, όλα AADE-accepted (`sandbox-results.txt`).
- **Ηλικίωση οφειλών** (PR #308) — aged-receivables page (0-30/31-60/61-90/90+ ανά πελάτη, σύνολα,
  drill στην Καρτέλα, CSV· reuse Καρτέλα FIFO aging). FEATURES §12.
- **Βιβλίο Εσόδων-Εξόδων → myDATA period report** — ΜΑΡΚ+κατάσταση στήλες, Έσοδα/Έξοδα+σύνολα,
  period presets, **PDF οριζόντιο A4**, exports CSV/XLSX/JSON. FEATURES §12. (Πλήρως κλεισμένο.)
- **Panel utility CSS (no-build)** — `resources/css/panel.css` μέσω `FilamentAsset::register` →
  `filament:assets`· όλα τα custom blade utilities πλέον styled, χωρίς npm/Vite/theme.
  _(Maintenance: νέο utility σε blade → πρόσθεσέ το εκεί.)_
- **Sendable customer statement (επαφή-aware)** (2026-06-17) — Καρτέλα → PDF/email σε πελάτη +
  τις επαφές του (role-labelled) + ελεύθερα extras (validate/dedupe). FEATURES §7.
- **Καρτέλα — όψη περιόδου + ομαδοποίηση header actions** (2026-06-17) — φίλτρα περιόδου πάνω από
  τον πίνακα + **σύνολα έτους** (τζίρος/εισπράξεις/υπόλοιπο)· header actions σε dropdowns + **global
  fix** στο overflow (`.fi-header-actions-ctn` wrap, αφορά όλες τις σελίδες με πολλά actions). FEATURES §7.

---

## 🛑 Looks like a gap — but it is NOT (don't re-open without a NEW reason)
- **RequestVatInfo «ΦΠΑ cross-check»** — **deferred ON PURPOSE.** Μετράει το Φ2 *deductible*
  της ΑΑΔΕ — **άλλο πράγμα** από το δικό μας «άθροισμα ΦΠΑ εξόδων» → μόνιμη ψεύτικη διαφορά.
  **NB:** η **ΦΠΑ picture** (dashboard «Εικόνα από myDATA» + box στην Ε3) **ΕΙΝΑΙ χτισμένη**
  (sum-of-docs, `MyDataVatAggregator`) — μην τη μπερδεύεις με το RequestVatInfo cross-check.
- **E3 ↔ local classification diff** — ίδια οικογένεια· deferred μέχρι να υπάρξει πραγματική ανάγκη.
- **E3 overview** — **already done** (`MyDataE3Overview`, νούμερα της ΑΑΔΕ).
- **`TenantScopedUnique`** — redundant (DB `unique(company_id,…)` constraints).
- **Manual «off-the-books» expense entry** — βλ. «myDATA/expenses completeness» (deferred, όχι «gap»).
- **Stock movements / ΣΔΕΠ / WHMCS `-333/-1000` sentinels** — DEAD legacy code· build μόνο αν το prod `.fbk` δείξει πραγματική χρήση.

---

## 🟠 myDATA / expenses completeness
- **`mydata:backfill-config` §8.3 προεπιλογή = «verify» default (P2, από review)** — το `--exemption-default`
  γράφει τον seed-κωδικό 4 (ενδοκοινοτική υπηρεσία) σε ΜΙΑ reason-less 0% κατηγορία, αλλά ο νόμιμος λόγος
  απαλλαγής **δεν βγαίνει από την κατηγορία μόνη της** (μπορεί να είναι εξαγωγή=8, reverse-charge=16…). Οι
  δικλείδες: opt-in flag, μόνο single-category (2+ = ambiguous), skip vatCategory-8, dry-run, ρητό output
  «ΠΡΟΕΠΙΛΟΓΗ — επιβεβαίωσέ την». Συνειδητό trade-off (ο owner ζήτησε ρητά τον default). Η πλήρης λύση
  (per-category scenario picker) είναι ο `VatExemptionGuidance` scenario-picker που ήδη τρακάρεται εδώ.
- **`mydata_read_env` override: gate στο aade-id, όχι στο subscription-key (P2, από review)** — το
  `Company::mydataReadMode()` τιμά το override (και η auto-λογική διαλέγει slot) με `filled(aade_id)`,
  **όχι** το key. Άρα override=production με production aade-id αλλά κενό key → `canReadMyData()=true`,
  μα το `FirebedCredentials::init()` πετάει στο fetch (αντί να πέσει σε sandbox). **Συνειδητά deferred:**
  (α) η υπάρχουσα auto-λογική έχει ΤΗΝ ΙΔΙΑ ασυμμετρία (id-only) — αλλαγή μόνο στο override θα ήταν
  ασυνεπής· (β) ο έλεγχος του key απαιτεί **decrypt κατά την πλοήγηση**, ακριβώς αυτό που το docblock
  αποφεύγει (crash σε rotated APP_KEY). Το `mydata_settings` MCP tool ήδη δείχνει `*_subscription_key`
  presence χωριστά, οπότε η μισο-ρυθμισμένη κατάσταση είναι ορατή. Πλήρης λύση: κοινός helper που ελέγχει
  ΚΑΙ τα δύο slots + surfacing στο UI — για ΟΛΑ τα read paths μαζί, όχι μόνο το override.
- **`mydata_read_env` override αγνοεί το gr-mydata «Off» (P2, από review)** — το override αξιολογείται
  ΠΑΝΩ από το `gr-mydata → Off→null` gate, οπότε ένας gr-mydata tenant με myDATA σκόπιμα Off + leftover
  creds + ρητό override ξαναποκτά ΑΝΑΓΝΩΣΗ (μπαίνει στα scheduled read jobs). **Συνειδητά deferred:** το
  «Off» στο `MyDataMode` σημαίνει «καμία **υποβολή**» — οι αναγνώσεις είναι ορθογώνιες (ο πάροχος διαβάζει
  ενώ mydata_mode=off), και το override είναι **ρητό opt-in** δύο ενεργειών (set env + creds), read-only.
  Defensible· αν φανεί surprising στην πράξη, το gate γίνεται «override δεν ξυπνά reads σε gr-mydata Off».
- **Η σελίδα ΜΑΡΚ δείχνει το XML της ΤΕΛΕΥΤΑΙΑΣ ανταλλαγής, όχι της έκδοσης (P2, από review MYD-023)** —
  το `MyDataMarkDetail::load()` κάνει `where('mark', …)->latest('id')`, οπότε όταν υπάρχει γραμμή
  CANCEL με το ίδιο ΜΑΡΚ, το panel request/response XML δείχνει την **ακύρωση** αντί για την αρχική
  υποβολή. Δεν είναι regression: έτσι συμπεριφερόταν ήδη η **απευθείας** διαδρομή (γράφει το ΜΑΡΚ
  έκδοσης στη γραμμή CANCEL από πάντα) — το MYD-023 απλώς έφερε και τη διαδρομή **παρόχου** στην ίδια
  συμπεριφορά. Σωστό θα ήταν να δείχνει το XML της γραμμής INSERT/PROVIDER_INSERT (ή και τα δύο, με
  διαχωρισμό «έκδοση / ακύρωση»), αλλά αυτό αλλάζει υπάρχουσα συμπεριφορά που δουλεύει → χωριστό item.
- **Αυστηρή άρνηση σε `Success` ακύρωσης ΧΩΡΙΣ ΜΑΡΚ ακύρωσης — μαζί με υιοθέτηση
  «ήδη ακυρωμένου» σε ΔΑ + πάροχο (υπόλοιπο MYD-023, P1)** — το MYD-023 έκλεισε την
  **αποθήκευση** (τα δύο ΜΑΡΚ σε ξεχωριστές στήλες παντού, + το ΜΑΡΚ ακύρωσης στο
  `STATE_SYNC`). Δεν έκλεισε το «μια κανονική επιτυχία χωρίς ΜΑΡΚ ακύρωσης να ΜΗΝ γίνεται
  τελική». **Δεν είναι μικρή αλλαγή μόνη της:** η απευθείας διαδρομή τιμολογίου έχει δίχτυ
  (self-heal στο `[251]` «ήδη ακυρωμένο»), αλλά η διαδρομή **δελτίων** και η διαδρομή
  **παρόχου** ΔΕΝ έχουν καμία υιοθέτηση ήδη-ακυρωμένου. Άρνηση εκεί = το παραστατικό
  ακυρωμένο στην ΑΑΔΕ και VALID τοπικά, με το retry να πετάει για πάντα — ακριβώς το
  stranding που ξηλώθηκε τρεις φορές στο MYD-021. Άρα: **πρώτα** υιοθέτηση
  ήδη-ακυρωμένου στις δύο διαδρομές, **μετά** η αυστηρή άρνηση — ένα κοινό item, όχι δύο.
  Δένει με PROV-015.
- **PEPPOL buyer = ζωντανός πελάτης, όχι snapshot (follow-up MYD-009)** — ο
  `PeppolInvoiceDocument` χτίζει τον αγοραστή εξ ολοκλήρου από το `customer`, ενώ η ελληνική
  διαδρομή (AADE + πάροχος) χτίζει πλέον τη νομική ταυτότητα από το **παγωμένο snapshot** του
  τιμολογίου. Δεν είναι bug σήμερα: ένας tenant είναι είτε `gr-mydata` είτε `ee-peppol`, οπότε
  κανένα μεμονωμένο παραστατικό δεν μπορεί να διαφωνεί με τον εαυτό του. Γίνεται όμως πραγματικό
  θέμα **μόλις ζωντανέψει το PEPPOL** (αλλαγή στοιχείων πελάτη θα ξαναγράφει την ταυτότητα
  περασμένου τιμολογίου). Θέλει τα ίδια helpers — `counterpartAfm()/counterpartName()/
  counterpartCountryForFiling()` — και το `frozenPartyColumns()` στο persist του PEPPOL submitter.
- **Στοιχεία επικοινωνίας αντισυμβαλλόμενου στο InvoSign — ζωντανά by design (follow-up MYD-009)** —
  τα νομικά πεδία (επωνυμία/ΑΦΜ/επάγγελμα/διεύθυνση) χτίζονται πλέον από το **παγωμένο snapshot**
  του τιμολογίου, αλλά τα `CounterpartTaxOffice` / `CounterpartPhone` / `CounterpartEmail`
  διαβάζουν σκόπιμα τον **ζωντανό** πελάτη: δεν είναι μέρος της νομικής ταυτότητας, λείπουν
  εντελώς από το AADE payload, και ο πάροχος τα χρησιμοποιεί για αποστολή/εκτύπωση — οπότε το να
  φτάνει το σημερινό email είναι το σωστό. **Αν** ποτέ χρειαστεί να είναι αναπαραγώγιμα (π.χ.
  «τι email είχε τότε;» σε έλεγχο), θέλουν **δικές τους στήλες snapshot** — όχι σιωπηλό πάγωμα
  μέσα στον builder. Καταγράφεται ως συνειδητή απόφαση, όχι ως παράλειψη.
  - **`CounterpartPhone` ungated (P2, από review)** — ο διακόπτης `einvoice_include_customer_email`
    (default OFF) πυλώνει μόνο το `CounterpartEmail`, όπως ζητήθηκε ρητά. Το `CounterpartPhone` μένει
    πάντα ζωντανό. Σήμερα ο InvoSign παραδίδει με email (όχι SMS), οπότε δεν είναι footgun· **αν** ποτέ
    προστεθεί SMS-παράδοση, το τηλέφωνο γίνεται ανάλογη περίπτωση → είτε δεύτερος διακόπτης είτε
    επέκταση του ίδιου (rename σε `einvoice_include_customer_contact`).
- **Χώρα πελάτη/προμηθευτή σε ISO picker (MYD-011) — ✅ DONE (Option B).** Η λύση που δούλεψε:
  αντί να μπει `Select` πάνω στην ωμή free-text στήλη (που έβαζε implicit `in` rule και **μπλόκαρε
  το save** σε κάθε legacy «ΙΤΑΛΙΑ» — γι' αυτό είχε αναιρεθεί), προστέθηκε **νέα καθαρή στήλη
  `country_code`** (πάντα null ή έγκυρο ISO) και ο picker δένεται εκεί. `IsoCountry::syncCountryCode`
  (save-hook, μία λογική για τα δύο models· `isDirty` ξεχωρίζει «picker set code» από «label changed»
  ώστε να μη μένει stale code), `ekdosi:backfill-country-codes` (idempotent), ETL γράφει την cache,
  και `suppliers.country` έγινε nullable/no-default → τέλος το silent-GR freeze. Το `country` μένει ως
  legacy/mirror label· οι resolvers έκδοσης διαβάζουν `isoCountryCode()` (zero-regression fallback).
  **Conscious tradeoffs (review P2, αποδεκτά):** (α) ένα deliberate re-pick στον picker **αντικαθιστά**
  το legacy free-text spelling («ΙΤΑΛΙΑ»→`IT`) ώστε να μη διίστανται οι δύο στήλες — το `country` δεν
  εμφανίζεται ως label πουθενά στην εφαρμογή (grep: κανένα table/infolist) και το πρωτότυπο μένει στο
  activity log/git· (β) αλλαγή του label σε μη-αναγνωρίσιμη τιμή **μηδενίζει** το derived code (→ ο
  submitter αρνείται) αντί να κρατά stale code που το νέο label δεν δικαιολογεί — safe by design.
  **Follow-up (P2, review):** τα money-adjacent predicates `ReverseCharge::isEuNonGreek`, το
  `PeppolEndpoint` και το EU-hint στο `InvoiceForm` διαβάζουν ακόμη το ωμό `customer->country` (όχι
  `isoCountryCode()`). Δεν είναι regression (έτσι ήταν πριν, και ο mirror κρατά το `country`
  resolvable), αλλά το root-fix θα ήταν να περάσουν κι αυτά από `isoCountryCode()` — εκτός MYD-011
  scope (αγγίζει VAT reverse-charge logic), οπότε ξεχωριστό βήμα με δικά του tests.
- **ΔΑ σε εξωτερικό παραλήπτη χωρίς ΑΦΜ — ανοιχτό ερώτημα ΑΑΔΕ (MYD-011 residual)** — όταν
  υπάρχει επώνυμος εξωτερικός παραλήπτης **χωρίς ΑΦΜ**, το μόνο διαθέσιμο placeholder είναι η
  σεντινέλα `000000000`, που όμως η Α.1123/2024 την ορίζει για **ενδοδιακίνηση**. Σήμερα:
  πελάτης-linked → `000000000` + GR (η domestic populace που αλλιώς θα κολλούσε)·
  manual/προμηθευτής → **άρνηση** αν δεν δοθεί χώρα. Χρειάζεται επιβεβαίωση από ΑΑΔΕ/λογιστή
  ποια είναι η σωστή δήλωση για ιδιώτη/μη-υπόχρεο παραλήπτη, και ίσως ξεχωριστό πεδίο αντί για
  τη σεντινέλα.

- **`invoice_taxes` table** — πολλές κατηγορίες ανά taxType σε **ΕΝΑ** τιμολόγιο (σήμερα μία/τύπο
  αλλιώς throw). **Χαμηλή προτεραιότητα/σπάνιο** — το ΦΠΑ ανά γραμμή παίζει ήδη· αυτό αφορά
  μόνο 2+ διαφορετικές κατηγορίες **ειδικού τέλους** (§8.x) στο ίδιο παραστατικό. _(Η money-core
  απόφαση «μετράνε τα τέλη/παρακράτηση στο οφειλόμενο;» **λύθηκε ✅** = `payable_total` — βλ. «Done recently».)_
- **Expenses — λογιστής/`entityVatNumber`** third-party submission (για tenants που μπλοκάρει
  η ΑΑΔΕ με [323]) + `RequestMyExpenses` (sanity totals) + **supplier CSV import** (`source=import`).
- **`SalesOrphanImporter`** — νέο τοπικό τιμολόγιο **πώλησης** από sales-orphan MARK + **line/E3
  backfill** στο enrich όταν το τοπικό δεν έχει γραμμές. _Χαμηλή αξία — πώληση εκδομένη από
  άλλο πρόγραμμα συνήθως απλώς αναγνωρίζεται. (Το expense-orphan import υπάρχει.)_
- **Expenses polish (remaining):** per-row import action (+ «held/needs-review» state) στην
  κονσόλα-Έξοδα. _(Χειροκίνητη καταχώριση + PDF/scan attachment: ✅ shipped — βλ. «Done recently».)_
- **§8.13 quantity/units για ΔΑ αγαθών** — οι μονάδες υπάρχουν· τυχόν goods-tenant ειδικά
  (π.χ. `<quantity>` per-line σε goods invoice types) ανοίγουν μόνο αν έρθει goods tenant.
- **Combined Τιμολόγιο–Δελτίο Αποστολής (ΤΔΑ)** — το ΤΔΑ ΔΕΝ είναι ξεχωριστός τύπος:
  είναι ένα 1.1 με `isDeliveryNote=true` + πλήρη movement header (σκοπός, μεταφορικό,
  διευθύνσεις φόρτωσης/παράδοσης). Ο `AadeInvoiceDocument` δεν εκπέμπει combined payload,
  οπότε το seeded «ΤΔΑ» αφαιρέθηκε (MYD-002). Χτίσε το combined document (payload +
  validation + lifecycle) και ξανα-πρόσφερέ το ως τύπο. **Εξάρτηση itemDescr:** το opt-in
  `mydata_send_item_descr` στον monetary builder (`AadeInvoiceDocument`) εκπέμπει `<itemDescr>`
  μόνο για τύπους που το επιτρέπει η ΑΑΔΕ (9.x) — αλλά υπό MYD-003 ο monetary builder
  απορρίπτει κάθε 9.x, οπότε ο κλάδος είναι πλέον μη-προσβάσιμος (τα καθαρά ΔΑ εκπέμπουν
  itemDescr μέσω `DeliveryNoteSubmitter`). Όταν μπει το `isDeliveryNote`, το
  `Codes::allowsItemDescr()` πρέπει να ελέγχει ΑΥΤΟ το flag (combined 1.1) αντί του 9.x τύπου.
- **Πλήρη 9.1 / 9.2 Δελτία Αποστολής** — το 9.1 (συσχετιζόμενο) θέλει payload με
  correlated MARKs (`addCorrelatedInvoice` + επιλογή σχετικών παραστατικών) και το 9.2
  (συγκεντρωτικό) μοντέλο σύνοψης πολλαπλών κινήσεων. Προς το παρόν είναι κρυμμένα από τον
  picker + μπλοκαρισμένα στον submitter (MYD-012, μόνο το 9.3 φιλάρεται μέσω allowlist).
  Ξεμπλόκαρέ τα όταν χτιστεί το μοντέλο (προσθήκη στο `Codes::SUPPORTED_DELIVERY_TYPES`).
- **measurementUnit = 7 (Τεμάχια_Λοιπές Περιπτώσεις) στα ΔΑ** — απαιτεί
  `otherMeasurementUnitQuantity` + `otherMeasurementUnitTitle` (§8.13 note 9, υποχρεωτικά).
  Δεν μοντελοποιούνται ακόμη → το 7 είναι σκόπιμα **μπλοκαρισμένο** (service throw) + κρυμμένο
  από τα line pickers (MYD-016). Full support = νέες στήλες σε `delivery_note_lines` + form fields
  + payload· άνοιξέ το αν το ζητήσει tenant με «λοιπές» μονάδες συσκευασίας (π.χ. παλέτες).
- **CMR (διεθνής φορτωτική)** — _✅ BUILT (Φάσεις 1–4): αυτοτελές `cmr_notes`/`cmr_lines`,
  `CmrResource` (standalone) + action «Δημιουργία CMR» σε Τιμολόγιο/ΔΑ (pre-fill + μεταγραφή
  ΕΛΟΤ-743), editable draft, `CmrPdf` 24-box, αγγλικά στοιχεία εταιρείας._ **Εκκρεμεί Φάση 0**
  (φορολογικά own-gear: move_purpose/ΦΠΑ = λογιστής, ΔΕΝ μπλοκάρει) + προαιρ. Φάση 5 («πακέτο
  εξαγωγής»). **`docs/archive/cmr-international-delivery.md`**. _Μετά deploy: `shield:generate`._

---

## 💶 Ταμειακή εικόνα / cashflow — «τα έξοδα που δεν έρχονται μόνα τους»
_Ιδέα 2026-07-12 (chrismfz). **Θα το δει με τον λογιστή πρώτα.** Επιλέχθηκε ρητά η
προσέγγιση «recurring templates + cashflow view», ΟΧΙ αυτόματη σύλληψη των πάντων._

**Πρόβλημα:** τα **έσοδα** είναι πεντακάθαρα (όλα κόβονται ηλεκτρονικά → «ό,τι κόβεται =
έσοδο»). Τα **έξοδα** ΟΧΙ: ο operator ξέρει τζίρο αλλά όχι μηνιαία εκροή, άρα δεν ξέρει «τι
του περισσεύει», πώς χτίζει αποθεματικό, ποιους μήνες να προσέχει (δώρα/άδειες/13ος-14ος).

**Framing (μη το ξεχάσουμε):** αυτό είναι **διοικητική/ταμειακή εικόνα, ΟΧΙ τα βιβλία του
λογιστή** (μισθοδοσία/ΕΦΚΑ/αποσβέσεις = δικά του). Σκοπός = cash-visibility, όχι δεύτερο
λογιστήριο — αλλιώς φουσκώνει σε κάτι που δεν συντηρείται.

**3 κουβάδες εξόδων** (κατά «το ξέρει το σύστημα μόνο του;»):
- **Α. myDATA GR e-invoiced** (Synapsecom, Alfanet, ΔΕΗ/ΟΤΕ/Vodafone, ΓΡ προμηθευτές) → **λυμένο**,
  `ExpenseImporter`/`ExpenseReconciler` τα τραβάει ήδη.
- **Β. Foreign B2B** (AWS, Hetzner, cPanel, WHMCS, Vultr, DirectAdmin, CloudLinux, JetBackup…) →
  **μόνο αν αυτο-δηλώνονται** (τύπος 14.x)· αλλιώς αόρατα.
- **Γ. Μη-τιμολόγια** (μισθοδοσία, ΕΦΚΑ ιδίων+παιδιών, δάνειο Εθνικής/στεγαστικό γραφείου, δώρα/
  έκτακτα/βλάβες) → **ποτέ** στο myDATA· πρέπει να δηλωθούν χειροκίνητα.

Το κλειδί (το είπε ο operator): **τα περισσότερα Β+Γ είναι recurring με μικρές αυξομειώσεις**
(π.χ. cPanel licenses που παίζουν λόγω accounts). Άρα: «όρισέ το μία φορά, ξαναγίνεται μόνο του».

**Φάσεις:**
1. **Μητρώο «Πάγια / Επαναλαμβανόμενα έξοδα».** Πρότυπο: όνομα, κατηγορία (Μισθοδοσία/ΕΦΚΑ/ΔΕΚΟ/
   Δάνειο/Foreign subs…), ποσό (+flag «κυμαινόμενο»), συχνότητα (μηνιαίο/τριμηνιαίο/ετήσιο), ημέρα,
   περίοδος ισχύος. Ο **scheduler (ήδη live)** φτιάχνει **πρόχειρο `Expense`** ανά περίοδο →
   operator επιβεβαιώνει/διορθώνει ποσό. Γράφεται σαν κανονικό `Expense` (νέο `source` ή `manual`)
   → μπαίνει «τζάμπα» στο `VatPeriodReport` + στο cashflow view.
2. **Ημερολόγιο εποχικών/έκτακτων.** Δώρα Χριστ./Πάσχα, επίδομα αδείας, 13ος/14ος, ετήσιες
   ασφάλειες = πρότυπα ετήσια/εξαμηνιαία αγκυρωμένα σε μήνα → οι «βαριοί μήνες».
3. **Widget «Τι μου περισσεύει»** (Αναφορές, δίπλα στα Έσοδα/μήνα + Εισπράξεις YoY): ανά μήνα
   **Έσοδα − Έξοδα (τιμολογημένα + πάγια) = καθαρή ροή**, γραμμή σωρευτικού **αποθεματικού**,
   σημαδεμένες οι κορυφές. Το payoff.

**⚠️ Ο ένας πραγματικός κίνδυνος — διπλομέτρημα:** πρότυπο «ΔΕΗ» **+** myDATA τιμολόγιο ΔΕΗ = 2×.
Λύση: match/reconcile βήμα, ή κανόνας «το πρότυπο μετράει μόνο αν ΔΕΝ βρεθεί myDATA παραστατικό
τον μήνα». Ίδιο για foreign που ήδη δηλώνονται (14.x). **Ερώτημα για τον λογιστή:** ποια foreign
δηλώνονται ήδη (καθορίζει πόσα μπαίνουν χειροκίνητα). **Υποδομή έτοιμη:** `Expense`+CRUD+import
υπάρχουν· λείπουν μόνο (α) recurring-templates, (β) cashflow widget, (γ) το anti-double-count.

---

## 🔵 Big features (blueprints kept — see index above)
- **PEPPOL Phase 2** — Access-Point transport («send»). Phase 1 (UBL) DONE· θέλει EE provider +
  sandbox creds (Billit/Finbite/Telema…). `paroxos/regulatory-blueprint.md §7`.
- **GR Πάροχος live** — P2–P5 built/gated (mode=off)· θέλει πραγματικά provider creds + sandbox
  (InvoSign/SBZ). `paroxos/`.
- **PROV-003 archive half** (print ✅ #406· snapshot + invoice-page evidence ✅ 2026-09-03) —
  απομένει **(α) ανάκτηση + ιδιωτική αρχειοθέτηση του επίσημου PDF παρόχου**: SHA-256, immutable πρώτη
  έκδοση, retry ΜΟΝΟ download (ποτέ re-file), allowlisted hosts/bounded size (anti-SSRF),
  `evidence_pending` state όσο λείπει artifact — χωρίς να κάνει fail ένα VALID filing. **BLOCKED στον
  πάροχο:** το InvoSign API (v1.0.1) επιστρέφει μόνο `invoiceMark`/`invoiceUid`/`authenticationCode`/
  `qrUrl` — **κανένα download endpoint/canonical URL εγγράφου**· το `qrUrl` είναι η landing σελίδα
  `viewinvoice.php`, που το spec ΑΠΑΓΟΡΕΥΕΙ να αρχειοθετηθεί ως το επίσημο έγγραφο. Ξεμπλοκάρει μόνο αν
  ο πάροχος εκθέσει download/retention API. Η υποχρέωση διατήρησης του εκδότη (ν.4308/2014) καλύπτεται
  ήδη (δεδομένα + MARK + UID + auth + request/response XML + ο δείκτης verification URL). Επίσης
  απομένει **(γ) το πλήρες «compare» panel** (τοπικό snapshot × AADE reconciliation) στην καρτέλα.
  _(β snapshot αδείας-εν-ισχύ ανά παραστατικό: ✅ DONE — `mydata_marks.provider_identity`.)_
  _(PROV-003 — archive half is TIER 4, blocked on an InvoSign download endpoint.)_
- **Provider endpoint hardening (PROV-017 follow-ups)** — το core URL guard (public-https-only,
  no userinfo/query/port≠443, no private/loopback/link-local/CGNAT host, no credentialed redirects)
  ✅ SHIPPED. Είναι **best-effort accident-prevention** (το URL το βάζει έμπιστος operator). Deferred
  hardening για πλήρη anti-SSRF: (α) **resolver-consistency + IP-pin** — ανάλυση με τον ΙΔΙΟ resolver
  (getaddrinfo/`/etc/hosts`, όχι μόνο `dns_get_record`) και POST στην ήδη-ελεγμένη IP (CURLOPT_RESOLVE),
  ώστε να κλείσει το fail-open (κενή ανάλυση = δεν μπλοκάρει) και το TOCTOU/DNS-rebinding· (β) parse_url
  host-confusion — ο έλεγχος γίνεται με `parse_url`, ο connect με curl (πιθανή απόκλιση σε crafted URLs)·
  (γ) provider-managed endpoint-profile registry αντί ελεύθερου URL (vendor-confirmed hosts)· (δ)
  μη-blocking DNS (το `dns_get_record` είναι σύγχρονο στο hot path/form-save).
- **Bridges/Connectors Phase 1** — πραγματική 2η πηγή (WooCommerce/Blesta…). `bridges-connectors.md`.
  _Phase 0.5 ✅ (presentation-only): source-neutral «Εισερχόμενα» + source badge · «Γέφυρες» page
  (honest status, no fake toggle). Phase 1 = move `companies.whmcs_*` → `billing_connections.config`,
  ExternalDocument DTO, generic ingest dispatcher, real is_active gating, + the 2nd connector —
  build WHEN a real 2nd source exists (designing the contract against WHMCS+guesswork bakes in WHMCS-isms)._
- **AI «Βοηθός»** — _✅ Phase 1 SHIPPED 2026-06-17: read-only chat (σελίδα + floating widget, κοινό
  `AssistantRunner`), tool layer isolation + per-tool permission, governance web/DB (on/off · model · token
  cap · per-company key) + `ai_usage_log` metering + caps. ✅ Phase 2a SHIPPED 2026-06-17: 6 read-only
  insight tools (`count_sales`/`outstanding_receivables`/`list_top_debtors`/`find_customer`/`recent_invoices`/
  `vat_summary`) + **clickable same-origin links** (Καρτέλα/view/νέο παραστατικό) μέσω `ChatMarkup` +
  prompt-caching toggle. ✅ Phase 2b SHIPPED 2026-06-17: **WRITE tools με operator-confirm** (ποτέ
  αυτόματα) — `send_customer_statement` (επαφή-aware) + `create_reminder`· staging σε `ai_pending_actions`,
  confirm/cancel κάρτες, `AiActionExecutor` (re-validate, scoped tenant+user), reminders → Filament DB
  notifications μέσω `ai:dispatch-reminders`._
  **Phase 2c (open) — ιδέες/σημειώσεις (καμία δέσμευση, χαμηλή προτεραιότητα):**
  - **(α) Περισσότερα read tools** — **✅ SHIPPED (3/4): `income_vs_expense`, `top_products`,
    `whmcs_inbox`** (dual-surface, chat + MCP). **Remaining: `backups_status`** — αφέθηκε γιατί
    τα backups είναι super-admin/global (αδέξιο σε tenant-scoped operator chat)· καλύπτεται ήδη
    μερικώς από το super-admin `app_health` MCP tool. Χτίσε το μόνο αν χρειαστεί operator-facing.
  - **(β) Περισσότερα write tools με confirm** — **✅ `record_payment` SHIPPED (2026-09-05):**
    «καταχώρισε είσπραξη» (reuse `PaymentAllocator::allocate`, `TYPE_RECORD_PAYMENT`, propose-only,
    μονοσήμαντος πελάτης, gate `Create:Payment`, chat + MCP). **Remaining — «κόψε πρόχειρο
    παραστατικό» (deferred):** τέμνει ανοιχτό design question — ένα draft «καίει» ΑΑ (βλ. pro-forma/
    ΠΡΟΤ-N item παρακάτω) και θέλει πολλά structured inputs (τύπος + γραμμές/είδη/ΦΠΑ). Χτίσε το ΜΕΤΑ
    την pro-forma απόφαση, με το ίδιο `ai_pending_actions` pattern.
    _(P2 cleanup: το fuzzy customer-match (name/ΑΦΜ like) υπάρχει πλέον σε 3 tools —
    `SendCustomerStatementTool`/`CreateReminderTool`/`RecordPaymentTool` με λίγο διαφορετικά
    return shapes· ένας κοινός `AssistantCustomerResolver` (found/ambiguous/none) θα αφαιρούσε το drift.)_
  - **(γ) Per-company κλειδί/βοηθός ξεχωριστά** — η στήλη `companies.ai_api_key` υπάρχει
    (στο `$hidden`)· λείπει το UI exposure (στο `CompanySettings` ή super-admin only) +
    per-key billing separation (κάθε εταιρεία δικός της Anthropic account/DPA).
  - **(δ) Persistence συνομιλιών** — `ai_conversations` table (ιστορικό + πολλές
    συνομιλίες ανά χρήστη, αντί session) — απαιτεί και UI επιλογής συνομιλίας.
  - **(ε) Usage dashboard + `ai_usage` tool — tokens/κόστος ανά εταιρεία. ✅ SHIPPED (2026-09-05).**
    Super-admin σελίδα «Χρήση & κόστος AI» (cross-tenant) **+ `ai_usage` chat/MCP tool** (per-tenant·
    MCP `company="all"` → ανά-εταιρεία fan-out). Follow-up: per-tenant self-view UI για company_admin. Ιστορικό:
    Χτίζεται ως **super_admin σελίδα «Χρήση & κόστος AI» ΜΕΣΑ στην περιοχή «AI Βοηθός»**
    (group «Σύστημα», δίπλα στο «Βοηθός AI») — **ΟΧΙ** στο κεντρικό dashboard (owner). Τα
    ΔΕΔΟΜΕΝΑ ΥΠΑΡΧΟΥΝ ΗΔΗ: το `ai_usage_log` κρατά input/output/cache tokens +
    `cost_estimate` ανά εταιρεία/χρήστη/συνομιλία/μοντέλο (source of truth για τα caps, βλ.
    `AiUsageMeter`). Surface: `sum(tokens)`/`sum(cost)` group-by μήνα × εταιρεία (ποιος
    πληρώνει, ποιος κοντά στο όριο) + per-user + μηνιαία τάση, προαιρετικά CSV. Read-only.
    _Follow-up: per-tenant self-view για company_admin (η δική του κατανάλωση vs cap)._
  - **(στ) Streaming απαντήσεων** — τώρα είναι «σκέφτομαι…» μέχρι να ολοκληρωθεί το
    tool-loop· streaming θα ήθελε SSE/Livewire polling (μεγαλύτερη αλλαγή στο surface).
  - **(ζ) Helper / «βοήθεια & συμβουλή» με curated knowledge base. ✅ SHIPPED (2026-09-05).**
    `knowledge_search` tool + `KnowledgeBase` (RAG-lite) πάνω στο `docs/assistant-kb/` (chat + MCP),
    ΑΥΣΤΗΡΟ grounding («ρώτα λογιστή» όταν δεν καλύπτεται). **Follow-up:** πλούτισε το KB (ο λογιστής
    προσθέτει επιβεβαιωμένες φορολογικές ενότητες με ημερομηνία ισχύος). _P2 follow-up:_ το
    `knowledge_search` είναι **global** (δεν αγγίζει tenant data) αλλά μέσω MCP περνά από τον
    `McpTenantResolver` — άρα multi-company χρήστης πρέπει να δώσει ένα (αδιάφορο) `company`. Θέλει
    ένα «no-tenant» μονοπάτι στο `AssistantMcpTool` (framework· το in-app chat δεν επηρεάζεται).
    _Το αρχικό σχέδιο:_ Δύο ΞΕΧΩΡΙΣΤΑ
    πράγματα: **(i) app how-to** («πού βλέπω τι μου χρωστάνε;», «πώς κόβω πιστωτικό;») —
    ασφαλές, γνώση της εφαρμογής· **(ii) domain advisory** («τι ΦΠΑ για Σκόπελο;», «τι
    παραστατικό για αποστολή δικού μου εξοπλισμού στο datacenter;», «ποιον τύπο να
    διαλέξω;») — ΕΠΙΚΙΝΔΥΝΟ αν απαντηθεί από γενική γνώση του μοντέλου (μειωμένα νησιά
    άλλαξαν πολλές φορές· λάθος = λάθος ΦΠΑ/ΑΑΔΕ). **Σχέδιο:** curated KB σε markdown
    (`docs/assistant-kb/`) που γράφεις εσύ/ο λογιστής + νέο tool `knowledge_search`
    (RAG-lite: επιστρέφει σχετικά αποσπάσματα) → ο βοηθός στηρίζεται ΑΥΣΤΗΡΑ σε αυτό,
    «δεν καλύπτεται → ρώτα λογιστή», ΠΟΤΕ εφευρεμένος φορολογικός κανόνας + πάντα
    disclaimer για φορολογικά. **Κουμπώνει με τα έτοιμα:** links (π.χ. «πώς στέλνω
    εξοπλισμό» → εξήγηση ΔΑ + link «Νέο Δελτίο Αποστολής»), `vat_categories` της
    εταιρείας (δείξε τις ρυθμισμένες, μη μαντεύεις). Ίδιο grounding-discipline με τα
    tools — απλώς προστίθεται μία ΕΓΚΕΚΡΙΜΕΝΗ πηγή δίπλα τους.
  - _Σχεδιαστικά κλειδωμένα ήδη (μην ξανασυζητηθούν): tool-layer isolation (κανένα `company`
    param), per-tool Shield permission, `#[Locked]` messages/transcript, `ChatMarkup`
    same-origin links, writes ΠΟΤΕ auto (operator-confirm). Engine = Laravel HTTP/Messages
    API χωρίς SDK. prompt-caching ✅ έγινε (2a)._ `ai-assistant-blueprint.md`
  (πλέον καλύπτει: **«δεν χρειάζεται Console agent»** για το in-app chat — μόνο API key +
  Messages API tool-loop· **abuse/resource safeguards** = no-code-execution + per-request
  max_tokens/tool-loop/timeout/history caps + per-tenant/user rate-limit + monthly token caps +
  audit· **grounding** system prompt (ξέρει ότι είναι ekdosi, ποια εταιρεία, off-task refusal)·
  **υποψήφιο μοντέλο = Sonnet 4.6 default**, Haiku 4.5 cheap tier, Opus 4.8 για βαριά ανάλυση).
- **Payment connectors** — IRIS + card-POS. `payment-connectors.md`.
- **Leads / mini-CRM** (2026-09-01, ιδέα ιδιοκτήτη — «αποκτούμε άτομο να κυνηγάει πελάτες») —
  ξεχωριστός `leads` πίνακας (όσα στοιχεία έχουμε, μόνο επωνυμία υποχρεωτική) + `lead_activities`
  χρονολόγιο (τηλέφωνο/email/ραντεβού/σημείωση, append-only, ποιος/πότε/τι ειπώθηκε/επόμενο βήμα) +
  status (νέο→…→πελάτης / χάθηκε / μην ξαναενοχλήσετε) + **dedupe warning** vs υπάρχοντες πελάτες &
  παλιά leads («να μην ξαναζαλίζουμε κόσμο») + **`ConvertLeadToCustomer`** με αμφίδρομο link
  (`leads.converted_customer_id`, μοτίβο quote→invoice) + section «Προέλευση» στον πελάτη +
  **«Απολογισμός πωλήσεων»** page (τηλέφωνα/emails/μετατροπές ανά χειριστή×εβδομάδα — «δούλεψε ο
  άνθρωπος;») + `next_action_at` reminders. **DESIGN ONLY, αποφάσεις κλειδωμένες** (§9: ρόλος =
  `operator`, όλοι βλέπουν όλα, παντού/multi-tenant, ελεύθερη επεξεργασία, μόνο χειροκίνητα, keep it
  simple) → `archive/leads-mini-crm.md`. **L0 + L1 ✅ SHIPPED** (resource + χρονολόγιο + καταστάσεις + dedupe +
  μετατροπή σε πελάτη + «Προέλευση» + προσφορά από lead, FEATURES §7β). **L2 ✅ SHIPPED**
  (`SalesActivityReport` + CSV, `leads:notify-due`, dashboard widget). **Μένει (προαιρετικά):** εβδομαδιαίο
  digest email του απολογισμού στον company_admin (μοτίβο backup-failure alert). **L3 όψεις (kanban +
  ημερολόγιο) ✅ SHIPPED**· email-από-lead + AI `lead_summary` = συνειδητά ΟΧΙ (owner: «too much»).

---

## 🌐 Αντικατάσταση WHMCS (σταδιακή) — master epic → βλ. **`PLAN.md`** (root)
Στόχος: το ekdosi να αντικαταστήσει σταδιακά το WHMCS (**strangler-fig**, όχι big-bang·
`billing_connections` επιτρέπει συνύπαρξη). Σειρά: **Domains → Payment gateways →
Provisioning → Portal**. Το «δύσκολο» (invoices/myDATA/recurring/υπόλοιπα) ήδη γίνεται·
κάθε πυλώνας = 6η/7η υλοποίηση του υπάρχοντος contract+registry pattern. Πλήρες σχέδιο +
data model + phase gates: **`PLAN.md`**.
- **Πυλώνας A — Domains** _(OPEN, πρώτο)_ — **αναλυτικό design: `docs/domains/README.md`** (data
  model, `DomainRegistrar` contract + OP endpoint mapping, .gr/grEPP rules, rich per-domain View,
  «Μεταφορά ιδιοκτησίας», API history). Dedicated `Domain` ↔ `ServiceContract` billing clock·
  registrar modules à la `EInvoiceProviderTransport`: **Openprovider** (gTLDs) + **grEPP** (.gr,
  direct EPP), routing ανά TLD. Φάσεις (stop σε κάθε gate):
  - **A0** θεμέλιο — `companies.enable_domain_management` flag + nav-gating trait +
    `DomainRegistrar` contract/registry/creds/Null + `config('ekdosi.domains.registrars')` +
    `domain_registrar_connections` (super_admin creds).
  - **A1** data model + manual CRUD (`domains`/`domain_tlds`/`domain_tld_prices`/
    `domain_nameservers`/`domain_contacts`) — καταχώριση υπάρχοντος portfolio, μηδέν API.
  - **A2** Openprovider read-only — availability/WHOIS/`domains:sync` (expiry pull).
  - **A3** Openprovider write — register/renew/transfer/NS/DNSSEC/privacy/lock + renewal
    billing (reuse `StageServiceRenewal`) + grace/redemption.
  - **A4** 2ος registrar **grEPP** (.gr/.ελ direct EPP· 2ετία min, no privacy/lock) — αποδεικνύει το abstraction.
  - **A5** polish — bulk availability search, portfolio dashboard, **registrar↔local
    reconciliation** (mirror myDATA reconcile).
- **Πυλώνας B — Payment gateways** → `payment-connectors.md` (IRIS πρώτα· card-POS/Stripe μετά)· πριν το portal.
- **Πυλώνας C — Provisioning modules** → seam `app/Contracts/ProvisioningModule.php` ήδη (βλ. «Services / Provisioning» κάτω).
- **Πυλώνας D — Customer portal** — **foundation ✅ SHIPPED** (identity/grants/reset/session-security +
  προβολή παραστατικών + «Η καρτέλα μου», όλα read-only, στο `/user`). ΔΕΝ ήταν τελικά μονολιθικά «τελευταίο»:
  η foundation δεν χρειαζόταν A/B/C. Μένουν τα **transactional** surfaces (πλήρωσε → B· domains → A·
  services → C), που προσκολλώνται ανά πυλώνα. Βλ. `PLAN.md §6`.

## 🟢 Services / Provisioning
- **Real provisioning modules** (cPanel/Mailcow/license server) — σήμερα μόνο `NullProvisioningModule`. _(= Πυλώνας C του `PLAN.md`.)_
- **Multi-line service contracts** — v1 = single-line.
- **Non-fiscal «προτιμολόγιο» series (ΠΡΟΤ) — WHMCS-style freedom (2η φάση, δένει με portal + gateway + recurring).**
  Μια σειρά/invoice-type με **filing OFF** (`submitsElectronically()` → false) που ΠΟΤΕ δεν φεύγει σε ΑΑΔΕ/πάροχο:
  ο operator μπορεί να τη μαρκάρει «πληρωμένη» ή να την αφήσει ανοιχτή ελεύθερα, χωρίς νόμιμο έγγραφο — το μοντέλο
  «φίλοι/reference υπηρεσίες που δεν πληρώνουν ποτέ, αλλά θέλω να ξέρω τι έχασα» (what-if, χωρίς να το κάνω «0/free»).
  Στο portal γίνεται ο «λογαριασμός σου»: recurring auto-issue ΠΡΟΤ → ο πελάτης βλέπει/επιλέγει ανανέωση/upgrade/
  downgrade/ακύρωση → πληρωμή (gateway) → **convert σε νόμιμο τιμολόγιο** με τον τρόπο πληρωμής → mark paid → money
  trail. Η αρχιτεκτονική το σηκώνει (`local_status` ⟂ `mydata_state`)· η «Καρτέλα μου» το εμφανίζει αυτόματα (ίδιο
  `CustomerLedgerBuilder`). Θέλει και τη numbering απόφαση κάτω ↓.
- **Pro-forma numbering (draft = προτιμολόγιο)** — σήμερα ο ΑΑ εκχωρείται στη ΔΗΜΙΟΥΡΓΙΑ (`InvoiceNumberer`, `CreateInvoice`), οπότε κάθε draft «καίει» έναν αριθμό της νόμιμης σειράς τιμολογίων → gap αν διαγραφεί/δεν πληρωθεί. Για service-manager pro-forma ροή (στέλνεις πολλά προτιμολόγια, πληρώνονται κάποια) χρειάζεται **δικό τους reference**: είτε ξεχωριστός μετρητής «ΠΡΟΤ-N» (ο πραγματικός ΑΑ μπαίνει στην έκδοση/πληρωμή), είτε το σταθερό `id`. Σημαίνει μετακίνηση εκχώρησης ΑΑ create→issue (ο `InvoiceNumberer` συνειδητά το απέφυγε — τεκμηρίωση εκεί). Το immediate «ο πελάτης χρειάζεται αριθμό-αναφορά» ΗΔΗ καλύπτεται (το draft έχει `invcode` + banner «ΠΡΟΧΕΙΡΟ» στο PDF). Surfaced από το MON-5.

## 🟣 WHMCS loose ends (βλ. `whmcs-legacy-plugin-map.md`)
- **T-3 cutover** — legacy timologia → ekdosi Customers (match `gr_vatno`, upsert, back-ref).
- **Multi-party SPLIT write-back** στο WHMCS (ένα MARK ≠ N invoices).
- **T-4 manual split tools** (transfer_invoice / relid_remover) — χαμηλή προτεραιότητα.
- **«All of a client's third parties» 2ο dropdown** (θέλει `contacts-by-userid` bridge endpoint).
- **«Εκδοθέντα Παραστατικά» — πλήρες pagination (P2, από review)** — το `issued-for-client` endpoint επιστρέφει
  bridge-derived (capped **500** newest) + historical (capped **500** newest· τα δικά του WHMCS ids capped **2000**
  newest στο plugin) σε έναν αδιαίρετο client-area πίνακα. Για reseller με χιλιάδες τιμολόγια θέλει σελιδοποίηση
  (endpoint `limit`/`cursor` + plugin UI)· τα caps αποτρέπουν το pathological memory/latency αλλά **σιωπηλά κόβουν**
  παλαιότερα (>2000 WHMCS invoices ή >500 historical rows) — η σελιδοποίηση είναι follow-up.
- **Declined (από review): tenant-slug existence oracle στο `issued-doc-pdf`** — το tenant lookup προηγείται
  του signature check (404 vs 401), όπως σε ΟΛΑ τα sibling webhook controllers· τα slugs δεν είναι μυστικά και
  το πραγματικό auth (HMAC secret) δεν επηρεάζεται. Αφήνεται συνεπές με το υπάρχον pattern.
- **«Εκδοθέντα» — PDF proxy για historical (P2, follow-up)** — τα historical rows επιστρέφουν `has_pdf:false`
  (το GET `issued-doc-pdf` εξουσιοδοτεί μόνο μέσω pending rows, που τα pre-bridge δεν έχουν). Για proxied PDF
  και στα historical θα χρειαστεί POST variant του PDF endpoint που δέχεται τα δικά του WHMCS ids (ίδιο leak-proof
  boundary με τη λίστα). Σήμερα τα historical δείχνουν το verify link (για ΥΠΑΕΣ = η επίσημη προβολή) χωρίς PDF.
- **Declined (από review): `verify_kind` ανά-tenant, όχι ανά-invoice** — παράγεται από `company.einvoice_provider`,
  σωστό για single-provider tenant· μπερδεύει μόνο σε tenant που ΑΛΛΑΞΕ πάροχο με mixed ιστορικό (cosmetic label,
  ο σύνδεσμος δουλεύει). Per-invoice θα ήθελε `latestProviderMark()` = N+1. Αφήνεται.
- **Declined (από review): historical is_own fallback → «own» όταν ο reseller δεν έχει linked customer** — καθαρά
  cosmetic grouping (και τα δύο του ανήκουν, το party_name φαίνεται)· το «fix» (seed από pending.customer_id)
  ρισκάρει το αντίστροφο mislabel σε single-third-party. Default «own» = σωστό στη συνήθη περίπτωση (pre-bridge
  ιστορικό = κυρίως δικά του).
- **WHMCS-inbox resolver memoization (P2, από review)** — ο `WhmcsPaymentMethodResolver` (και ο δίδυμος
  `WhmcsIncomeClassifier`) χτίζονται per-`map()` call, οπότε preview+persist και κάθε split-party κάνουν
  ξεχωριστό query. Invoice-invariant → θα μπορούσαν να περνιούνται μία φορά από τον caller. Αμελητέο (ένα
  indexed query/κλήση)· κοινό pattern με το income map, γι' αυτό αφήνεται μαζί.
- **WHMCS gateway key με τελεία σπάει το Livewire `wire:model` binding (P2, από review)** — η σελίδα
  «Αντιστοίχιση WHMCS (πληρωμές)» κάνει `wire:model="choice.{gateway}"`· ένα gateway module name με `.`
  (ασυνήθιστο — τα WHMCS modules είναι `[a-z0-9_]`) θα γινόταν nested path. Θέλει index-based binding ή
  sanitised key. Χαμηλή πιθανότητα· άνοιξέ το αν εμφανιστεί τέτοιο gateway.
- **`customfields` ως scalar → TypeError στο `resolveWhmcsCustomField` (P2, από review — pre-existing)** —
  αν κάποιο WHMCS/bridge response έδινε ποτέ το `customfields` ως scalar αντί για array, το
  `array_is_list($scalar)` θα πετούσε TypeError. Κοινή παραδοχή όλου του parsing (`wantsInvoice`/`whmcsAfm`/
  matcher/`wantsImmediateInvoice`), **μη προσβάσιμη από κανένα πραγματικό feed path** (native + bridge δίνουν
  πάντα array). Στον γκρινιάρη-mirror το καταπίνει το best-effort `\Throwable` catch· στο create-seed
  (`WhmcsCustomerCreator`) όχι. Fix = ένας κοινός guard `is_array($customfields)` στην κορυφή του reader.

## 💳 Paid/unpaid-aware WHMCS γέφυρα (αμφίδρομη) — epic
_Ιδέα 2026-07-13 (chrismfz). Money-sensitive· Phase 2 γράφει χρήμα στο WHMCS → design-first._

**Πρόβλημα:** σήμερα ο όρος πληρωμής του εκδοθέντος τιμολογίου βγαίνει **αποκλειστικά από τον
τύπο** (`WhmcsInvoiceMapper` → `payment_method_id = invoiceType->payment_method_id`)· το **WHMCS
paid/unpaid status αγνοείται** και **δεν καταγράφεται Payment**. Άρα ένα ΑΠΛΗΡΩΤΟ WHMCS τιμολόγιο
(π.χ. Α.Ε./Δημόσιο/Δημοτική που θέλει «πρώτα τιμολόγιο, μετά πληρωμή») που εκδίδεται κάτω από τον
(cash-term) default τύπο φαίνεται λανθασμένα **εξοφλημένο**, ενώ είναι πραγματική ανοιχτή οφειλή.
Το payload **έχει ήδη** `status`/`datepaid`/`balance` (τα διαβάζει το `CustomerWhmcsLedger`).

**Phase 1 — inbound — ✅ SHIPPED (v1.9.x):** WHMCS `status` από το payload → badge «Πληρωμή WHMCS»
στο inbox· νέα ρύθμιση **«Προεπιλεγμένος τύπος για ΑΠΛΗΡΩΤΑ (επί πιστώσει)»** (`whmcs_default_unpaid_type_id`)·
το «Δημιουργία Παραστατικού» προ-επιλέγει τύπο βάσει status+πρόθεσης (`PendingWhmcsInvoice::suggestedInvoiceTypeId`),
override πάντα· tripwire status-aware (warn και για cash-term unpaid-slot). Auto-issue paid-only.
_(Το `datepaid`/`balance` snapshot δεν χρειάστηκε — το `status` αρκεί· read-on-demand από το payload.)_

**Inbound payment sync (WHMCS → ekdosi) — ✅ SHIPPED (v1.11.x):** `whmcs:sync-payments` (opt-in
scheduled) κλείνει την οφειλή στο ekdosi όταν ένα επί-πιστώσει WHMCS τιμολόγιο πληρωθεί στο WHMCS —
poll-based, money-write μόνο στο ekdosi, only-if-open + lockForUpdate re-read + `transaction_id` dedup
+ AADE-cancel-safe (`WhmcsPaymentSyncer`). **Γνωστά όρια** (από το review): (α) σερβίρει μόνο tenants
που φτάνουν σε `FILED` (myDATA-filing· off-mode drafts μένουν `DRAFTED` → follow-up)· (β) πριν το enable
σε tenant με legacy on-account πληρωμές, επιβεβαίωσε ότι κανένα legacy τιμολόγιο δεν έχει `FILED` pending
row (default OFF = συνειδητό opt-in)· (γ) bridge-only tenant χωρίς native creds εξαιρείται από το loop.

**Phase 2 — outbound (money-write· opt-in· design-first):** Ekdosi payment (σε WHMCS-sourced
τιμολόγιο) → WHMCS `AddInvoicePayment`/mark-paid, ΜΟΝΟ αν όχι-ήδη-πληρωμένο. Κίνδυνοι + δικλείδες:
- **Διπλή πληρωμή** (ο πελάτης πλήρωσε και μέσω WHMCS gateway) → `GetInvoice` status=Unpaid **πριν** το push.
- **Feedback loop** (WHMCS InvoicePaid hook → πίσω στο Ekdosi) → absorb από το **audit-freeze** (filed row → `touch`, όχι δεύτερη εγγραφή).
- **Μερική πληρωμή** → push **ακριβούς ποσού**· < balance μένει Unpaid (σωστό).
- **Retry διπλασιασμός** → **transaction_id** από το Ekdosi Payment για dedup WHMCS-side.
- Νέο plugin op (`add_payment` σε `inbound.php`, HMAC-guarded)· native `AddInvoicePayment` για plugin-less.
- **Opt-in per tenant** + ίσως χειροκίνητο κουμπί «Δήλωση πληρωμής στο WHMCS» αντί πλήρως αυτόματο.

**Υποδομή έτοιμη:** payload έχει status· mapper/draft/CompanyForm/writeback υπάρχουν. Λείπουν: (Φ1)
status-capture + inbox badge + unpaid-default-type + status-aware draft· (Φ2) το outbound payment op.

## 🆕 Settings-in-UI — widen (scheduler + global pages shipped)
- **Role-scoped per-company knobs:** _✅ SHIPPED (trimmed) — «Ρυθμίσεις εταιρείας»
  (`CompanySettings`, `View:CompanySettings`): company_admin self-serves the SAFE subset
  (PDF branding · invoice-mail templates/from · auto-email toggles · backup enable+cadence),
  audited, explicit-whitelist save._ **Still deferred (deliberately super_admin):** the
  credential/infra knobs (myDATA/GSIS/WHMCS/SMTP secrets, e-invoice provider, backup
  passphrase/destinations/retention, tenant identity). `whmcs_auto_issue` stays two-key
  (UI + `companies.whmcs_auto_issue_immediate`). Widen only if a real per-tenant admin
  needs a specific credential delegated — don't bulk-move secrets into company_admin reach.

## 🧹 Deploy/rollback + leads-calendar links — P2 από το review (untracked-deadlock PR)
Δεν μπλοκάρουν τίποτα· καταγραφή για να μη χαθούν.
- **Το rollback path δεν έχει τις νέες εγγυήσεις.** `SelfUpdate::runRollback()` και
  `deploy/rollback.sh` κάνουν `git checkout --force` ΧΩΡΙΣ ούτε τον έλεγχο tracked-dirty ούτε το
  `protectUntracked()` — άρα ένα untracked αρχείο που το target ref το έχει tracked αντικαθίσταται
  χωρίς αντίγραφο. Ίδιο μοτίβο με το update path· μικρό port.
- **Ο φάκελος αντιγράφων γράφεται πριν τους μεταγενέστερους ελέγχους.** Στο `update.sh` το
  copy-aside τρέχει πριν το downgrade-guard και το ΑΦΜ pre-flight, οπότε ένα deploy που ματαιώνεται
  εκεί αφήνει πίσω ένα `storage/app/deploy-untracked/<ts>/` ανά προσπάθεια (και τυπώνει «Copies
  kept…» για deploy που δεν έγινε). Είτε μετακίνηση μετά τους ελέγχους, είτε retention/καθάρισμα.
- **Το tab του link είναι χοντρότερη κοπή από τον αριθμό δίπλα του** (ημερολόγιο leads): το
  `overdueBeforeGrid()` μετράει μόνο τα ΠΡΙΝ το πλέγμα αλλά ανοίγει ΟΛΑ τα ληξιπρόθεσμα, και το
  `withoutNextStep()` ανοίγει `tab=open` (που περιέχει κυρίως leads που ΕΧΟΥΝ επόμενο βήμα). Η
  διάσταση χειριστή συμφωνεί πλέον· η χρονική/πεδίου όχι. Θέλει είτε αποκλειστικά tabs είτε
  ρητότερο κείμενο στο banner.

## ✅ ~~`UpdateRun` authorization boundary (ΜΗ shield-generated policy)~~ — ΕΓΙΝΕ
- **Έκλεισε.** `app/Policies/UpdateRunPolicy.php` γραμμένη ΣΤΟ ΧΕΡΙ (view μόνο για system super
  admin, κάθε mutation `false`) **+** το `UpdateRun` μπήκε στο `ADMIN_FORBIDDEN_RESOURCES`, με
  Gate-level tests (`UpdateRunAuthorizationTest`). Ποτέ stock `shield:generate` template εδώ — δίνει
  CRUD βάσει `*:UpdateRun` permissions, ακριβώς αυτό που απορρίφθηκε στο review του PR #389.
  Έκλεισε ταυτόχρονα και το deploy deadlock: όσο ΔΕΝ υπήρχε αρχείο policy, το `shield:generate`
  (deploy + seeder) το ξανάγραφε ως untracked και το pre-flight του `update.sh` αρνιόταν το επόμενο
  deploy. Φύλακας: `ShieldPolicyDriftTest` (κανένα resource χωρίς committed policy).
- **Υπόλοιπο (μικρό):** τα άλλα δύο global (`$isScopedToTenant = false`) resources — `Company`,
  `User` — είναι ήδη στο `ADMIN_FORBIDDEN_RESOURCES` αλλά έχουν **stock** shield policies. Αξίζει
  ίδιο πέρασμα (super-admin-only, mutations `false`) όταν ακουμπήσουμε ξανά τα δικαιώματα.

## 🔒 Backup / DR / Portability
- **Operator export/import role queries are N+1** _(P2, from the operators-in-bundle review)._
  `CompanyExporter::exportUsers()` reuses `TenantRoleProvisioner::roleInCompany()` (up to 3 `userHoldsRole`
  queries per user); `planUsers`/`importUsers` add ~1 query per user each. A 40-operator tenant is ~120+ tiny
  indexed queries per export/import. Deliberately kept as REUSE of the team-aware role helper over a hand-rolled
  ranking join (export/import are manual, infrequent; tenants have single-digit operators). Collapse to one
  `model_has_roles→roles` join filtered to the company if a tenant ever grows a large operator roster.
- **Operators in bundle: leads/activities lose operator attribution on --full import** _(P2, from the review)._
  Now that operators travel, `leads.assigned_user_id` / `lead_activities.user_id` COULD be restored, but
  `FK_REWIRES` still nulls them (no user-id map — the users section carries email/name/role, no source id).
  Only bites `--full` (transactional) bundles, not the settings-bundle install path. Fix = carry the source
  user id in the users section + an email→new-user-id patch pass over the two lead FK columns.
- **--into restore can null a carried-but-unresolvable invoice_type default** _(P2, rare, from the review)._
  On `--into`, a `whmcs_default_*_type_id` the bundle carries but whose target invoice_type isn't in the dumped
  set (soft-deleted / dangling source FK) is nulled at save and the patch can't re-resolve it → the target's
  live default is lost with no warning. Same-company dumps normally include all types, so rare. Fix = on --into,
  keep the target's pre-import value (or at least log) when a carried type FK can't be remapped.
- **Durable native portable key (μετά το legacy_id sunset).** Ο `CompanyImporter` κλειδώνει
  το idempotent matching σε `legacy_id` (+ content-signature fallback). Όταν σβήσει το legacy
  (Delphi/Firebird), τα native rows (legacy_id NULL) δεν συγκλίνουν αξιόπιστα σε re-import-πάνω-
  σε-υπάρχουσα-εταιρία (το `--new`/fresh-copy ΟΚ — FK rewiring μέσω surrogate `id`). Λύση: ένα
  `uuid`/`public_id` ανά portable πίνακα, παραγόμενο στο create, ως ΤΟ idempotency key (uuid→
  legacy_id→signature)· + ΑΦΜ-dedup για πελάτες. Χρειάζεται μόνο για sync/merge μεταξύ ζωντανών
  ekdosi — όχι για μεταφορά-σε-VM.
- **Portability Phase 5** — envelope-key (option 4) — optional future (το plaintext-at-rest
  καλύπτει cross-VM σήμερα).
- **Backup encryption** (app-level) — deferred (βασιζόμαστε σε SFTP/S3 access control).
- **No-password (un-encrypted) exports/backups — συνεπές & εμφανές παντού.** _✅ SHIPPED 2026-06-17:
  per-company `secrets_mode` «Χωρίς κρυπτογράφηση» toggle στο export ΚΑΙ στα αυτόματα αντίγραφα, με
  σαφή plaintext προειδοποίηση και στα δύο· global spatie encryption status (read-only, env
  `BACKUP_ARCHIVE_PASSWORD`) εμφανές με «⚠ χωρίς κωδικό» στις «Ρυθμίσεις συστήματος»._ (Live global
  toggle = .env edit, σκόπιμα read-only — όχι νέα μηχανική.)

## ⚙️ Tech debt / latent (also `CLAUDE.md` «Known latent items»)
- **Customer portal — acting device's remember-me after own password change (P2, pre-existing UX).** When a
  customer changes their OWN password (profile), `remember_token` is rotated (kills «remember me» on every
  device, incl. this one) but the acting device's recaller cookie is NOT re-issued — so once its session
  cookie expires, the very device that changed the password is asked to log in again instead of being
  remembered. Pre-existing (the rotation predates the session-invalidation slice). Proper fix re-issues the
  current recaller (à la `logoutOtherDevices`), but the obvious path re-fires the Login event; deferred rather
  than widen the change. Security-neutral (only a remembered convenience is lost on the acting device).
- **Customer portal — invited-claim vs revoked grant (reviewed, BY DESIGN — not a bug).** If an operator
  invites a login (invited + grant) then revokes the grant before the invitee claims, the claim still flips
  invited→active and the login can authenticate (seeing NOTHING, since grants gate the documents view). This is
  the deliberate orthogonality: login STATUS (can authenticate) ⟂ GRANTS (what it sees). To neutralize a login
  the operator **suspends** it (then the claim no-ops); revoking a grant only removes data access. An ungranted
  active login is harmless (empty portal). Revisit only if a product decision wants «no grant ⇒ can't activate».
- **Customer portal — self-register concept (design locked, NOT built).** Reset-password + the invited-login
  «claim» shipped (operator opens an invited login → customer self-sets password via `/user/forgot-password`),
  which IS the safe near-term onboarding — no open form, operator decides who gets a login. If open-ish
  self-registration is ever wanted, go **tier-2 CLAIM only, never open signup (tier-3)**: a form where the
  visitor proves they are an EXISTING customer (ΑΦΜ + email that matches a `customers` row, or an invoice
  number) → **email verification** → the row is created, but **the data grant is still operator-approved**
  (or deterministically WHMCS-derived), never auto. Key safety fact: because of the grants boundary a login
  with **no grant sees nothing**, so even a successful spam/bot registration is inert — the real vectors are
  email-bombing (mitigate with the same throttle+honeypot pattern as reset), DB junk (email-verify before the
  row is «real»), and impersonation (mitigated by the ΑΦΜ-match + operator grant approval). Do not build until
  a tenant actually asks; reset-password covers onboarding for now.
- **Customer portal — Slice 2 documents view: P2/P3 survivors (from the adversarial gate).**
  _(consciously deferred)._ The security boundary (grant-scoped reads, fail-closed PDF authz, per-request
  status re-check) passed with no P0/P1. Fixed in the same PR: PDF-route throttle, `target=_blank`
  `rel=noopener`, a «newest 500» notice when the per-group cap is hit, and a strengthened suspended-mid-session
  test (real login + `forgetGuards()` → genuine DB-reload path). **Parked:** (a) **`CustomerDocumentFeed::forLogin()`
  runs one query per active grant with no aggregate per-response cap** — fine at today's 1–3 grants/login, but a
  login with many grants would hydrate up to 500 rows × N groups into one non-paginated page; add pagination /
  a response ceiling if a reseller login ever holds many grants. (b) **`documentsFor()` hard-caps at 500 newest
  docs per (company,customer)** with only a notice, no pagination — a customer with >500 live invoices can't
  reach the oldest; add pagination when a real tenant approaches the cap. **Deliberate (not a bug):** a
  `reseller`-role grant exposes the granted customer's FULL live-document history, not only reseller-brokered
  docs — the operator explicitly links a login to a customer/ΑΦΜ, so the grant IS the configured relationship;
  narrow it only if a product decision says a reseller should see a subset.
- **Gapless-at-send (ΑΑ Phase 1 invoices + Phase 2 δελτία) — P2 survivors of the review loop**
  _(consciously deferred)._ The gapless-at-send numbering change (real ΑΑ allocated at transmission,
  provisional «ΠΡΟΣ-…» until then) passed the adversarial gate with two P1s fixed (finalize gate → mode-aware
  `submitsElectronically()`; a build/config error after `assign()` now `release()`s the number). **Phase 2
  (delivery notes) shipped** — `delivery_notes` migrated (code/invcode nullable+widened), `CreateDeliveryNote`
  provisional, `DeliveryNoteSubmitter` assigns/releases via `InvoiceNumberer::assignDelivery`/`releaseDelivery`.
  The same three items stay parked, and (a)+(b) now apply to BOTH invoices and δελτία: **(a) Concurrency
  gap** — the decrement-if-top `release`/`releaseDelivery` is gapless only under SERIAL issuance; if two
  documents of the same series are submitted from two concurrent FPM requests and the earlier-numbered one
  is rejected, its number is no longer the top and a rare gap remains (negligible at ~70 docs/month; a
  fully-gapless guarantee needs safe renumbering). **(b) Draft XML preview** — a draft has `code=null`, so
  the AADE payload can't be built (it guards `code < 1`); the two preview actions now show a clear «issue
  first» message instead of the raw builder error, but a true preview needs the next counter value as a
  non-consuming placeholder aa. `delivery:sandbox-validate` sidesteps this by keeping allocate-at-create for
  its throwaway test note. **(c) Provisional on finalized-unsent actives** — a live AADE tenant that finalises
  but hasn't transmitted shows «ΠΡΟΣ-…» in the ledger/PDF filename until it sends (correct per the model, but a
  small «(προσωρινό)» badge would remove any surprise). **(d) Second write per draft** — the provisional
  invcode is keyed on the surrogate id, so it needs a post-INSERT `saveQuietly` (INSERT+UPDATE per draft
  create). Negligible at these volumes; a derived/read-time label would avoid it but invcode is a real,
  queried column. **(e) `down()` is best-effort** — the migration's rollback restores NOT NULL / varchar(15),
  which fails while any provisional row exists; deploy rollback uses a DB snapshot (`ekdosi:db-restore`), not
  `migrate:rollback`, so this is documented, not load-bearing. _(Review round 2 P0 fixed at root:
  `release()`/`releaseDelivery()` now fire only for the number THIS submit reserved — `assign()` returns
  whether it allocated — so an already-numbered doc, e.g. finalized-at-'off'-then-live or a legacy import,
  is never renumbered by a rejection it merely triggered.)_
  _Round 2 (Phase 2) P2 survivors, all documented not fixed: **(f) off-tenant ΔΑ stays provisional** — a
  non-transmitting tenant ('off'/'none') has no delivery-note local-issuance path (no finalize action; «Έκδοση»
  is gated on `submitsElectronically()`), so its ΔΑ drafts keep «ΠΡΟΣ-ΔΑΠ-{id}» forever. Acceptable: a compliant
  ΔΑ is always transmitted (e-transport mandate), the note was never issuable at an off tenant even before Phase 2,
  and the «ΠΡΟΧΕΙΡΟ» banner signals it. If a local-issuance ΔΑ flow is ever wanted, add a finalize→`assignDelivery`
  path like the invoice one. **(g) Pre-send local-failure gap** — a failure AFTER assign but before the wire send
  (initFirebed missing-creds, armInDoubt DB failure, provider issue-date/transport-resolution) is outside the
  release try/catch, so the number isn't returned; but the far more likely RETRY reuses it (assign no-ops on the
  set code), so a gap needs infra-error + abandon. Shared with the invoice submitters. **(h) CMR from a provisional
  draft** — «Δημιουργία CMR» is available on a draft, so `reference_no` records the provisional «ΠΡΟΣ-…»; the CMR
  is an editable ΠΡΟΧΕΙΡΟ the operator fixes before printing._
  _Round 3 (whole-ΑΑ holistic review, Phase 1+2 together) — two edge bugs FIXED at root, three P2 kept:
  **fixed:** a concurrent-reservation GAP on the lock-less local-issuance paths (finalize/NullSubmitter) —
  `reserve()` now re-reads the row's `code` under a row lock so a loser adopts the winner's number
  (counter bumped once); and finalize now `assign()`s BEFORE the `local_status→active` flip so a failed
  allocation leaves a recoverable draft, not an active-but-unnumbered document. **P2 kept: (i) provisional
  LABEL type-segment can go stale** — the invcode «ΠΡΟΣ-ΤΠΥ-{id}» is frozen by the `created` hook and not
  refreshed if the draft's `invoice_type` is changed (EditInvoice), and `revert()` rebuilds it from the
  current type; the real ΑΑ at send is always correct and the «ΠΡΟΣ» marker signals non-final, so this is
  cosmetic (id-keyed → unique either way). Dropping the type segment from the format would remove it but the
  operator explicitly chose «ΠΡΟΣ-ΤΠΥ-6885». **(j) `ProvisionalCode::is()`** is exercised by the numbering
  tests (not dead); app readers use the equivalent `code === null` (the authoritative DB signal) — both valid._
  _Rounds 4–5 (verify-the-fix passes) hardened the round-3 fixes: `reserve()` now THROWS (not burns) if the
  row vanished mid-operation; the adopt path syncs only the three number columns (a caller's other pending
  edit survives); `revert()` locks document→invoice_types in the SAME order as `reserve()` (provably
  deadlock-free); finalize wraps assign+flip in one transaction (savepoint rollback undoes the counter bump).
  One P2 accepted, not fixed: **(k) transient stale in-memory model after a rolled-back finalize** — if the
  status-flip fails, the outer transaction reverts code/invcount in the DB but the in-memory $record still
  shows them; the action errored (no redirect, no success toast) and the row refetches on the next load, so
  there is no persisted corruption. Severity converged P1→P1→P2→P2 across the rounds (no P0/P1 in round 5)._
- **OPS-001 cron↔worker attribution — inherent 5-min boundary ambiguity** _(P2, DECLINED across the OPS-001
  review loop — documented, not a bug to keep patching)._ `OperatorHealthSeverity` disambiguates «cron down»
  from «worker down» via the queue heartbeat (a job cron dispatches): when cron is down it suppresses the
  worker finding unless the beat is staler than `cronAge + 5` (proof the worker failed WHILE cron was alive).
  Two states are inherently unresolvable by timing and are consciously accepted: (a) a worker that died within
  ~5 min of cron dying is indistinguishable from a healthy idle worker, and (b) cron `missing` (never ticked →
  no age to compare) attributes any worker staleness to cron. In both, cron is already reported non-ok
  (the actionable root) and the true worker state SELF-HEALS the moment cron is fixed (a still-stale beat with
  cron now fresh → worker reported). Chosen over slack tweaks that false-alarm «worker down» on every sustained
  cron outage. Strictly better than pre-OPS-001 (which had NO cron signal and always blamed the worker). Only
  revisit with a SECOND independent worker-liveness signal (not more timing math on the same two keys).
- **`OperatorHealthReport` heartbeat ages are floats (`Carbon::diffInMinutes`)** _(P2, from the OPS-001
  review round 2; pre-existing)._ Both `queue()` and the new `cron()` compute `age_minutes` via
  `Carbon::parse($ts)->diffInMinutes(now())`, which under Carbon 3 (Laravel 11+) returns a **signed float**
  — so the operator-facing «age» line and the JSON `age_minutes` can read a fractional value, and forward
  clock skew yields a tiny negative. Harmless for the thresholds (`≤ 10` / `> 30` / the severity `+slack`
  all hold on floats), but for display/threshold cleanliness cast to a non-negative int minute count in ONE
  place shared by both slices (keep `queue()`/`cron()` in parity — don't fix only one). Untouched here to
  preserve that parity.
- **PROV-020 auto-stamp — P2 survivors of the review loop** _(consciously deferred)._ The change that
  auto-stamps `issued_at`=today at Send (provider + ΔΑ) passed the gate with the delivery-path ordering
  bug fixed (stamp now runs BEFORE the XML build; guarded by an XML-content test). Three P2s parked:
  (a) **Cross-day ambiguous retry** — if a Day-1 send times out AFTER the provider filed (no local MARK),
  a Day-2 retry re-stamps `issued_at`=Day-2 before `recoverViaStatusCheck` adopts the Day-1 MARK, so the
  local date can diverge from the filed date. **Not a regression** — the same mismatch was reachable
  before (the operator had to re-date past the block to retry); it is the PROV-001 exactly-once
  limitation (no durable in-doubt marker). Fix belongs with PROV-001: on adopt, reconcile `issued_at` to
  the adopted MARK's `mark_date`. (b) **Recompute/audit side effect** — the stamp's `update()` fires the
  InvoiceObserver balance recompute (due-date shifts to today — correct) and an «issued_at changed»
  activity row on every filing (truthful, but an operator could read it as a manual back-office edit);
  optionally tag it distinctly. (c) **Provisional draft date** — a draft on a provider tenant shows
  «Ημερομηνία έκδοσης» = its prep date, which changes to today at Send; a small «οριστικοποιείται στην
  αποστολή» hint on the draft/Send view would remove the surprise (also covers a *finalized* doc whose
  operator-set date is silently re-dated to today at Send). (d) **Timezone unification (masked)** — the
  stamp writes `now()` (app tz), the payload serializes `Carbon::parse(issued_at)->toDateString()` (app
  tz), and the guard validates in hardcoded `Europe/Athens`; all agree only while `APP_TIMEZONE=Europe/
  Athens` (which this Greek app always uses). If it ever isn't, unify the issue-date tz across stamp +
  payload (`AadeInvoiceDocument::setIssueDate`) + guard. (e) **`created_at` on legacy rows** — the new
  «Ημερομηνία δημιουργίας» shows the ETL import timestamp for Firebird-imported invoices (not the original
  prep date), so a historical record can read as «created after issued»; cosmetic, hide/relabel for
  `legacy_id`-set rows if it confuses.
- **«Έλεγχος ετοιμότητας» (Preflight) — P2 survivors of the review loop** _(consciously deferred)._
  Two P2s survived the 2-round adversarial gate (round 1 = the tenant double-scoping P1, fixed at root
  with `CompanyContext::actAs`; round 2 = no P0/P1). (a) **Query fan-out**: `ReadinessReport::build()`
  issues ~13 sequential `count()` queries per company (7 lookups + 2 products + 2 whmcs + the audit's
  per-type/VAT loads); negligible at 3 tenants behind the 30s cache, but scales linearly per tenant on
  each cache miss — collapse to grouped `count() … GROUP BY company_id` (or a `withoutGlobalScope` sweep)
  if the tenant count grows. (b) **Stale window**: the report is cached 30s under a single global key with
  no config-change invalidation, so a just-fixed tenant can read «Μπλόκο» for up to 30s until the TTL
  lapses; the «Ανανέωση» button is the escape hatch and this matches the «Υγεία συστήματος» precedent.
  DECLINED (not deferred): routing the fix through `->withoutGlobalScope(CompanyScope::class)` instead of
  `actAs` — the reused `MyDataConfigAudit` runs its OWN internal queries that `ReadinessReport` can't
  scope from the outside, so re-pinning the ambient context (`actAs`) is the only remedy that also fixes
  the audit; `withoutGlobalScope` on our own queries would leave the audit mis-scoped.
- **PROV-009 remainder — explicit `evidence_pending` indicator** _(P2)._ The core (quota + reception
  capture, widget, low-quota warn) shipped. What's left: when a filing is adopted via a MARK-only
  recovery (the myDATA read returns MARK+QR but not the provider UID/auth), the provider evidence is
  incomplete — surface that explicitly (a DERIVED predicate «has uid+auth?» + a console/invoice-box
  badge «στοιχεία παρόχου ελλιπή», not a stored state to drift). It must NEVER trigger a re-file merely
  to fill those fields (the finding is explicit). Two related asks are **not feasible**, not deferred:
  a delivery-failure warn (the issue response carries no delivery outcome — the provider emails the
  customer afterwards with no callback) and a scheduled quota poll (no non-issuing endpoint, and the
  quota already refreshes on every filing). Also parked: **shared-account quota** — the widget/warn read
  the tenant's OWN latest reading, correct for one InvoSign contract per tenant (our setup); if a single
  provider account ever backed several tenants, each would see a per-tenant partial view of the shared
  quota. Revisit only if a reseller/accountant shared-account setup appears.
- **PROV-005 remaining half — authenticated provider credential probe** _(P1, vendor-blocked)._
  The local config false-greens are fixed (issuer-field completeness + active-env credential pairing,
  in `ProviderPreflight` + go-live). What stays: `InvoSignTransport::ping()` is an **unauthenticated**
  GET to the base URL, so «Έλεγχος σύνδεσης» can read green with a dead/invalid token; and the preflight
  cannot prove contract/declaration activation or remaining quota. All three need an **InvoSign-approved
  non-issuing status/credential endpoint** (never a dummy production invoice). Wire the real probe once
  the vendor confirms the endpoint; until then the cutover dry-run (bucket A) is the backstop.
- **STOCK-001 follow-ups — remainder-aware, recompute-style stock reversal** _(P2 survivors of the
  STOCK-001 review; the reachable P1 order-regression was fixed in that PR)._ Three residual edges, all
  the SAME root — the reversal fires incremental deltas at each cancel event while the "correct"
  reverse for one document depends on the LIVE state of others (the money side avoids this by
  recomputing from the live set, e.g. `RecomputeReturnedQuantities`):
  (a) **δελτίο double-count** — `reverseSaleForDeliveryNote` reverses the full line qty with no
  `qty_returned` remainder check, because a returned qty is tracked on the linked INVOICE line, not the
  δελτίο line. If a Πώληση δελτίο *carried* the sale (issued before its linked invoice) AND a credit
  note against the linked invoice returned the goods, cancelling the δελτίο double-counts the return.
  Contrived (δελτίο-first sale group + credit against the linked invoice + δελτίο-only cancel = an
  already-inconsistent business state).
  (b) **soft-delete-a-credit-note path** _(pre-existing, unchanged by STOCK-001)._ `deleted()` runs
  `RecomputeReturnedQuantities` (which frees `qty_returned`, LIVE-scoped) but does NOT reverse the
  credit note's `+qty` return movement (the reversal is wired to the `local_status='cancelled'`
  transition, not to `deleted()/restored()`), so delete-credit-then-cancel-invoice can inflate. Needs
  the delete/restore path to compensate stock too (or a recompute).
  (c) **unify the three reversal loops** — `reverseSaleForInvoice` / `reverseSaleForDeliveryNote` /
  `reverseReturnForCreditNote` are near-identical (iterate lines · skip untracked · guard on
  source-reason + REASON_CANCEL · record REASON_CANCEL). A single `reverseLineMovement(source, reason,
  sign, remainderFn)` — or better, a recompute-from-live-documents pass — collapses the divergence and
  makes (a)/(b) impossible to forget.
  (d) **trigger-predicate asymmetry** — the return-reversal fires on the credit note's
  `local_status='cancelled'` observer transition, but `qty_returned` is freed on the broader
  `InvoiceScope::live()` (which ALSO excludes an AADE-only-cancelled doc, `mydata_state=CANCELLED`
  with `local_status` still active). If a credit note ever reached that divergent state, `qty_returned`
  would free while the `+qty` return-IN stayed → a later original-cancel could inflate. Latent: no
  in-app path flips `mydata_state` without `local_status` (both cancel choke-points sync the two). The
  `reverseReturnForCreditNote` skip-when-original-cancelled guard has the mirror caveat: it assumes the
  INVOICE owns the sale (false for a δελτίο-first linked group → overstate).
  (e) **revive re-application — ✅ FIXED (PROV-018 PR).** «Επαναφορά» (`ViewInvoice::revive`,
  `local_status: cancelled → active`) used NOT to re-apply stock — `recordSaleForInvoice`/
  `recordReturnForCreditNote` skipped because the base `REASON_SALE`/`REASON_RETURN` movement still
  existed, while the cancel's compensation stood → net 0 (credit note understated, invoice overstated).
  Now they detect an outstanding cancel compensation and record a signed `REASON_REVIVE` that zeroes it,
  and the reverse* «already reversed» guards became NET-based (`compensationBalance`, not existence) so a
  cancel→revive→re-cancel cycle stays idempotent (`StockService`, tests in `StockSaleTest`).
  (f) **delivery-cancel reversal durability** _(from the PROV-018 review, still deferred)._
  `DeliveryLifecycleService::persistCancellation` records `reverseSaleForDeliveryNote` best-effort AFTER
  the cancel transaction commits; if the process dies between commit and the reversal a retry cancel
  throws «ήδη ακυρωμένο στη myDATA» and never re-runs it (and the remote-cancel/`refreshStatus` path —
  MYD-019, currently frozen on the ΔΑ v2.0.2 spec — does not reverse stock at all yet, so the changelog's
  «reused by MYD-019» is the method being ready, not wired).
  A recompute-from-live-documents pass closes (a)–(d) + (f) at once (or move (f)'s reversal inside the txn).
  Stock is informational (never blocks a sale, self-corrects with a manual adjustment), so these
  are genuinely P2.
- **PROV-018 micro-cleanup** _(P2 from the PROV-018 review, accepted)._ `IssueCreditNote::reverseRemaining`'s
  per-line `remainingQty()` read duplicates the loop's inline `qty_returned` read, and a full reversal runs
  2N `return_invoice_extras` reads (resolver builds the selection, then the loop re-validates) where N would
  do. Deliberate: the resolver reads pre-mutation while the loop re-reads under its own mid-loop writes
  (correctness over micro-opt); a single keyed prefetch reused by both would collapse it. Negligible on real
  invoice line counts — revisit only if a reversal ever touches many lines under contention.
- **PROV-019 declined-scope + soft-warn posture** _(conscious calls in the PROV-019 change, 2026-09-03)._
  Two deliberate decisions, recorded so they aren't re-litigated blindly: **(a)** the original required-change
  proposed a full 5-state «correction bundle» state machine (`credit_draft|credit_pending|credit_failed|
  reversed|replacement_ready`) linking original+credit+replacement — NOT built; disproportionate at ~70
  docs/month. The shipped fix (a `reissued_from_invoice_id` link + `isLegallyReversed()` + a soft-warn)
  covers the real duplicate-turnover risk without the machine. Revisit only if a tenant with routine,
  high-volume provider credits needs a richer correction workflow. **(b)** The replacement gate is a
  **soft-warn**, not a hard-block (operator choice): the operator can file a replacement while the reversed
  original still stands, after an explicit confirmation, and the act is logged. If double-turnover incidents
  ever show up in reconciliation, a per-tenant hard-block toggle is the escalation — cheap to add on top of
  the existing `replacementReversalPending()` predicate.
- **PROV-019 review P2 edge-notes** _(robustness-only, no live-flow impact; from the PROV-019 adversarial
  review)._ **(1)** `isLegallyReversed()` degenerate case: a VALID original whose `credited_total` came from
  a stale/ETL-written cache with NO live correlated credits reads as «ακυρώθηκε» (the "all live credits
  VALID" test is vacuously true). Harmless — recompute guarantees live credits exist when
  `credited_total ≥ payable`, and it matches pre-PROV-019 behavior — but a `->exists()` guard on
  `creditNotes()` would harden it. **(2)** Soft-deleting a still-standing original clears the warn:
  `reissuedFrom()` is a `belongsTo` on a SoftDeletes model, so a soft-deleted original resolves to null →
  `replacementReversalPending()` returns false while the AADE double-turnover risk persists. Edge (deleting a
  filed original isn't a normal flow); `->withTrashed()` on the relation would close it if it ever matters.
- **MYD-019 follow-up — 2-way delivery reconciliation (VALID/un-cancel direction)** _(P2, declined in the
  MYD-019 review as a conscious scope call)._ `refreshStatus()` auto-applies only the terminal AADE
  `CANCELLED` direction; it does NOT un-cancel a δελτίο that is locally `CANCELLED` while AADE reports it
  valid — the symmetric, delicate direction that `SyncInvoiceStateFromAade` does for invoices behind an
  operator-confirmed reconciliation action. Not reachable for δελτία today (a delivery `mydata_state`
  only becomes `CANCELLED` via a real AADE cancel or the new remote-sync, so there is no "wrongly
  cancelled" state to strand), so no repair path is needed yet. If a delivery-side reconciliation console
  ever lands, give it the same operator-confirmed un-cancel as the invoice twin.
- **MYD-019 concurrency/diagnostic cosmetics** _(P2 survivors of the MYD-019 review, all
  concurrency/staleness-only, DB state always correct)._ (a) On two racing `refreshStatus` calls (a
  double-click, or a manual «Έλεγχος κατάστασης» overlapping the scheduler), the LOSER re-reads the
  note as already-terminal under the lock and returns `state_synced=false`, so `ViewDeliveryNote` shows
  the green «Καμία αλλαγή» toast instead of the remote-cancellation warning — while `refreshFormData`
  flips the on-screen state to cancelled (mildly contradictory for that one toast; the winner's toast is
  correct). (b) `applyRemoteCancellation`'s post-commit `Log::info` uses the pre-transaction `$logFrom`
  snapshot (in-memory `$note`) while the STATE_SYNC audit row records the from-values read fresh under
  the lock — under a stale `$note` the diagnostic log and the audit row can disagree on the pre-sync
  state (the audit row is authoritative). Both fixable by having the winner branch return the applied
  outcome + the locked from-values; not worth the extra plumbing for a rare race.
- **Strict tenant scope** — _audited 2026-06-11: **0 live leaks** σε ~54 entry points· το no-op default είναι σωστό/load-bearing. Έγινε το φθηνό hardening (StockService explicit company_id· SweepOrphanMailLogs explicit withoutGlobalScope· CLAUDE.md rule). Το enforcement (null→throw) **deferred**: naive flip σπάει ~18 ασφαλή explicit-where paths· execution-time tripwire false-positives σε relation/eager-load FK queries. Re-open μόνο αν εμφανιστεί πραγματικό leak ή μεγαλώσει πολύ το CLI surface._
- **WHMCS outbound push — «claimed-but-lost» recovery** _(from the 2-way payment-sync double review, M1)._
  `WhmcsPaymentPusher` claims the `whmcs_payment_pushed_at` marker **before** the WHMCS write (prevents a
  double mark-paid under a manual-vs-auto race) and releases it on a caught failure. If the worker is
  **hard-killed** (OOM/deploy) in the µs window between the claim and the HTTP send, the marker stays set
  but WHMCS was never paid → the invoice drops off every worklist and no UI can re-drive it (fix today =
  manual `whmcs_payment_pushed_at = null`). Very low probability (the WHMCS `transid` dedup already makes a
  re-push double-pay-safe). Options if it ever bites: an operator «Επανάληψη push» action that clears the
  marker, or a reconcile pass that resets a stale-claimed row still Unpaid at WHMCS.
- **ΑΦΜ identity — P2 survivors of the PR #394 review loop** _(consciously parked, per the
  per-priority round cap in CLAUDE.md)._ (a) `Afm::uniqueKey('VAT123456789')` (label glued to the
  number, no separator) keeps the letters → key `VAT123456789`, so such a legacy row is not deduped
  against `123456789` (same as pre-PR; the migration does not refuse it). Fold it only if the prod
  `.fbk` shows the pattern — `SELECT afm FROM customers WHERE afm REGEXP '^(VAT|AFM|TIN)[0-9]'`.
  (b) `CompanyImporter` reads the tenant's customers 3× per phase (existingIndex / afmKeyIndex /
  customerOwners) — one `get()` could feed all three; only matters at tens of thousands of customers.
- **CHANGELOG `[Unreleased]`: δύο `### Fixed` blocks** (προϋπάρχει στο main) — το `ekdosi:release`
  τα ρολάρει αυτούσια στη δατεδ έκδοση. Ένωσέ τα στο επόμενο release cut (όχι σε PR feature, γιατί
  μετακινεί ~50 άσχετες γραμμές και θάβει το diff).
- **Bulk συγχώνευση πελατών** _(follow-up του `customers:merge`)._ Σήμερα ένα ζευγάρι τη φορά (σωστό:
  ο χειριστής αποφασίζει ποιος επιζεί). Αν ποτέ βρεθεί βάση με δεκάδες διπλά, ένα `--all --yes` που
  τρέχει τη default επιλογή (ο «γεμάτος» επιζεί) για κάθε ομάδα του `customers:afm-duplicates`.
- **Operator picker helper** _(P2 από το review του Leads L3)._ Το `$tenant->users()->orderBy('name')
  ->pluck('users.name','users.id')` ζει σε ~6 σημεία (LeadForm/LeadsTable/SalesActivityReport ×2/
  `InteractsWithLeadViews`)· το LeadForm προσθέτει και τον τρέχοντα super_admin (δεν είναι στο pivot).
  Ένα `Company::operatorOptions()` όταν ξαναπιαστεί κάποιο από αυτά.
- **CSV export helper** _(P2 από το review του Leads L2)._ `AgedReceivables::exportCsv` και
  `SalesActivityReport::exportCsv` κουβαλούν το ίδιο BOM + formula-guard + `fputcsv(';')`· το
  `AgedReceivables` δεν περνά `escape:` (E_DEPRECATED ανά γραμμή σε PHP 8.4). Ένα κοινό
  `App\Support\Csv::stream()` όταν προστεθεί τρίτη σελίδα με CSV.
- **ETL (`migrate:firebird`) — διπλό ΑΦΜ ΜΕΣΑ στη legacy πηγή = hard stop** _(από το review του
  ΑΦΜ unique constraint, PR #394)._ Το `assertNoDuplicateLegacyAfm` σταματά όλο το run (τίποτα δεν γράφεται)
  αν δύο CUST_IDs μοιράζονται ένα ΑΦΜ· λύνεται μόνο στη legacy βάση (συγχώνευση/διόρθωση εκεί — η legacy
  εφαρμογή είναι ζωντανή μέχρι το cutover). **Συνειδητά ΟΧΙ** «κράτα τον πρώτο, προειδοποίησε για τους
  υπόλοιπους»: η επιλογή νικητή είναι νομικά σημαντική (παραστατικά/υπόλοιπα κρέμονται και από τους δύο)
  και το ETL δεν την παίρνει μόνο του. Αν η parallel-run εβδομάδα το κάνει ενοχλητικό: ένα `--afm-keep=CUST_ID`
  per ΑΦΜ (ρητή απόφαση χειριστή) + το άλλο row εισάγεται με `afm_key=NULL` και ⚠ στο log.
- **Issuer name/address snapshot — το υπόλοιπο του MYD-024 (P2, συνειδητά deferred)** _(η **σειρά**
  πάγωσε 2026-09-02· το ΑΦΜ/ΓΕΜΗ βγάζει πλέον προειδοποίηση· MYD-024)._
  Το issuer block **τιμολογίου** στην ΑΑΔΕ είναι μόνο `vatNumber + country + branch` (τα `[219]`/`[220]`
  **απαγορεύουν** όνομα/διεύθυνση για ελληνικό μέρος), οπότε αλλαγή επωνυμίας/έδρας/ΔΟΥ/ΚΑΔ **δεν**
  μπορεί να ξαναγράψει υποβληθέν τιμολόγιο. Μένουν δύο πραγματικά αλλά περιορισμένα σημεία: (α) το
  **9.x δελτίο αποστολής** ΟΝΤΩΣ φέρει issuer name+address, (β) τα **PDF** παράγονται από το ζωντανό
  `Company`, οπότε επανεκτύπωση παλιού παραστατικού μετά από μετακόμιση δείχνει τη νέα διεύθυνση.
  Αλλάζει η **αναπαράσταση** ενός παρελθόντος εγγράφου, όχι η κατατεθειμένη ταυτότητά του — το
  απομακρυσμένο αρχείο μένει ανέπαφο. Ξανα-άνοιγμα όταν κάποιος tenant αλλάξει πραγματικά έδρα.
- **Filing-policy snapshot — το υπόλοιπο του MYD-018 (P2, συνειδητά deferred)** _(η **σειρά** πάγωσε
  2026-09-02· MYD-018)._ Δεν παγώνουν ακόμα: `mydata_type`, income
  classification ανά γραμμή, quantity flag, payment-method mapping, κωδικός ΦΠΑ/απαλλαγής. Αυτά
  αλλάζουν το **περιεχόμενο** του payload, όχι την **ταυτότητά** του — δεν μπορούν να προκαλέσουν
  διπλή υποβολή ή χαμένο recovery (αυτό ήταν το P0 και έκλεισε), είναι συνειδητές πράξεις
  παραμετροποίησης, και το `mydata:preflight` ήδη τα ελέγχει. Ειδικά το `mydata_type` **δεν είναι
  μονόγραμμη**: ο `SalesReconciler` τεκμηριώνει fallback που στηρίζεται στο ότι είναι null για
  ETL-imported γραμμές, οπότε το να γράφεται νωρίτερα απαιτεί να ξαναγίνει κι εκείνο το μονοπάτι
  στην ίδια αλλαγή. Ξανα-άνοιγμα αν χειριστής αλλάξει `mydata_type` σε τύπο με ανοιχτά πρόχειρα.
- **Provider-side exactly-once — το υπόλοιπο του MYD-021 (→ PROV-001)** _(από το review round 5 του
  MYD-021/022)._ Το `GrProviderSubmitter` πήρε **lock** (κλείνει το double-click/overlapping
  auto-issue), αλλά **όχι** τον durable pre-POST marker: εκεί ο marker είναι άχρηστος χωρίς
  **provider-side επαλήθευση** — δεν έχει νόημα να σημάνεις «σε αμφιβολία» αν δεν μπορείς να
  ρωτήσεις τον πάροχο τι έγινε. Το `EInvoiceProviderTransport::status()` **υπάρχει ήδη** (InvoSign
  `invoice_status.php`), οπότε το PROV-001 είναι κυρίως wiring. Δεύτερο σκέλος: το **9.x δελτίο μέσω
  παρόχου** ανακτάται σήμερα διαβάζοντας **ΑΑΔΕ**, αλλά ένα provider-filed δελτίο φτάνει εκεί μετά
  το relay του ΥΠΑΗΕΣ — άρα το grace των 10' (κουρδισμένο για το άμεσο ERP feed) μπορεί να είναι
  μικρό και να οδηγήσει σε δεύτερη έκδοση. Το `status()` δέχεται `Invoice`, οπότε χρειάζεται
  delivery-note δίδυμο. **Μέχρι τότε**: το lock + το `unverifiableInDoubt()` (άρνηση μέσα στο
  παράθυρο) καλύπτουν το ρεαλιστικό σενάριο· ένα hard kill σε provider tenant παραμένει ακάλυπτο.
- **`ExpenseClassificationSubmitter` γράφει μη-fillable `'date'`** _(από το review round 3 του MYD-025)._
  Το `expense_marks.mark_date` μένει έτσι πάντα NULL για **δικές μας** υποβολές χαρακτηρισμού, οπότε
  δεν συνεισφέρουν στο εύρος ημερομηνιών του `LegalEvidence::describe()`. Κοσμητικό (η μέτρηση είναι
  σωστή), αλλά είναι γνήσιο bug στον submitter — μία γραμμή, εκτός scope του MYD-025.
- **Legal-retention hardening — το υπόλοιπο του MYD-025 (P2)** _(η σιωπηλή διαγραφή έκλεισε
  2026-09-02)._ Δεν έγιναν: (α) **`restrictOnDelete`** στα `mydata_marks`/`delivery_marks` αντί για
  cascade — χρειάζεται πιο ακριβή κανόνα απ' ό,τι εκφράζει ένα FK, γιατί ένα **DRY_RUN mark** θα
  έκανε ένα απλό πρόχειρο αδιάγραφο· (β) **archival/soft-delete tenant** (ο finding ζητά «αρχειοθέτηση
  αντί διαγραφής») — είναι feature, όχι δικλείδα, και η διαγραφή είναι πλέον συνειδητή· (γ) UI action
  για το `company:export-pdfs` (σήμερα CLI — σωστά, γιατί χιλιάδες PDF δεν χωράνε σε HTTP request·
  θα ήθελε queued job + download). Ξανα-άνοιγμα αν χρειαστεί αρχειοθέτηση tenant χωρίς διαγραφή.
- **Bulk-delete guard** — single-record guarded (PR #258)· `DeleteBulkAction`/`ForceDeleteBulkAction` αφύλακτα.
- **Soft-deleted FK rows render blank** — `withTrashed()` label + «deleted» badge για rows πριν τον guard.
- _**`GrProviderSubmitter::cancel()` non-9.3 guard** — ✅ SHIPPED 2026-07-07: service-level hard-refuse με μήνυμα «έκδοσε πιστωτικό (5.1)» για κάθε τύπο ≠ 9.3, ώστε μη-UI callers (automation/bulk) να μη χτυπούν opaque `[283]`. (Το UI ήδη γκρεϊτάρει το `cancel_at_mydata` σε 9.3-only.) Βλ. `mydata-sandbox-myd2-retry-2026-07-07.md`._

## 💡 PDF / UX & ideas
- **«Πρόχειρα»: legacy-imported stuck-drafts δεν φαίνονται στην καρτέλα, αλλά ΕΙΝΑΙ editable αλλού
  (P2, από review draft-edit flow)** — το section «Πρόχειρα» στην καρτέλα πελάτη χρησιμοποιεί το κοινό
  `onlyUnissuedDrafts` (αποκλείει `legacy_id`, όπως το dashboard tile & τα money totals), ενώ ο πίνακας
  Παραστατικά, το κουμπί «Επεξεργασία» στο ViewInvoice και το `EditInvoice::mount` επιτρέπουν
  επεξεργασία **οποιουδήποτε** draft (μόνο `local_status`/`mydata_state`), ανεξαρτήτως `legacy_id`. Άρα
  ένα legacy-imported παραστατικό με κολλημένο `local_status='draft'` επεξεργάζεται από τη λίστα αλλά
  ΔΕΝ βρίσκεται από την καρτέλα του πελάτη. Επιλογή: είτε να δείχνει η καρτέλα και τα legacy stuck-drafts
  (χαλαρώνει το κοινό scope — αγγίζει dashboard/money semantics), είτε να αποκλείει το edit-gate τα
  legacy drafts (αλλαγή συμπεριφοράς που ίσως κάποιος βασίζεται). Edge case (κανονικά δεν υπάρχουν
  legacy drafts)· χρειάζεται απόφαση προϊόντος πριν αγγίξουμε το κοινό `onlyUnissuedDrafts`.
- _(G10 «ένα template αντί 8»: **non-issue** — τα 8 legacy FastReport δεν πορτάρονται· έχουμε
  ήδη καθαρά Blade ανά τύπο. **Δίγλωσσο/EN output: ✅ shipped** — βλ. «Done recently».)_
  Προαιρετικό μελλοντικό cleanup: κοινό layout partial στα 4 PDF templates (χαμηλή αξία).
- **«Show all / browse» picker** (search-beyond-typing) στους product/customer pickers —
  να ξεφυλλίζεις όλον τον κατάλογο χωρίς πληκτρολόγηση. _(Tags σε **γραμμές**: dropped — δεν
  έχει use case· μια γραμμή δεν είναι οντότητα που ταξινομείς. Tags σε **πελάτες/προϊόντα**
  ήδη υπάρχουν.)_
- **Curated tax-presets** expansion ανά κλάδο + **%-ανά-προϊόν** (όχι μόνο €/τεμ).
- **Seeder «προϊόντα με θεσμικό τέλος»** — _✅ SHIPPED 2026-06-17: «Πρότυπα τελών» selective-import στη
  λίστα Προϊόντων (`LeviedProductTemplates` + `ImportLeviedProducts`) — σακούλα €0,07 / πλαστικά €0,04 /
  ανακύκλωσης €0,08 / διαμονής, προ-ρυθμισμένα με myDATA Τέλη §8.7· idempotent._
- **`clear:right`** σε single-word doc-types (PDF tweak).

## 🧰 Setup / onboarding helpers (from-zero — sweep 2026-06-17)
_Ήδη: **web installer `/install`** (from-zero σε φρέσκο host: `.env`+`APP_KEY`+`migrate`+super-admin,
fail-closed/self-disabling, filesystem-token gate — βλ. FEATURES §17) · `ekdosi:install` wizard (CLI) ·
`MyDataLookupSeeder` (VAT/invoice types/payment-delivery methods/aims/units, με one-click
`StandardLookupSeedAction` ανά resource) · `DemoCompanySeeder` · GSIS/VIES lookup · `suppliers:sync` ·
«Πρότυπα τελών». Ιδέες για ευκολότερο στήσιμο από το 0:_
- **`ekdosi:install --bundle` partial-failure recovery** _(P2, from the install-bundle review)._ If the
  importer COMMITS the company but its post-commit role provisioning throws, `installFromBundle` fails before
  attaching the admin, and a re-run can't finish: `--new` refuses an existing slug. The importer already prints
  «τρέξε shield:sync-super-admin», and manual attach + that command recover it, but there's no clean re-run path.
  Fix = on a `--force` re-run where the slug exists, attach the admin + re-provision (or route through `--into`)
  instead of refusing. Rare (role provisioning seldom throws after a clean company commit).
- **Web installer — follow-ups:** (α) προαιρετικό `CREATE DATABASE` όταν ο DB χρήστης έχει δικαίωμα (τώρα
  απαιτεί προ-δημιουργημένη κενή βάση — το σωστό default σε shared hosting)· (β) ~~auto-detect writable
  dirs / PHP extensions ως preflight βήμα~~ **DONE** (`RequirementsChecker`, incl. `env_writable` OPS-002)·
  (γ) optional «γράψε το cron line / systemd unit» helper αντί για απλή λίστα ελέγχου (μερικώς: `ops:cron`).
- **OPS-002 residual — exotic-host writability false-positive** _(P2, from the OPS-002 round-2 review)._ The
  `env_writable` preflight is a read-only `is_dir`+`is_writable` stat, which `access()` can misreport on a
  few host classes (POSIX ACL mask, SELinux/AppArmor, NFS `root_squash`, a remounted-ro overlay): it passes,
  the DB gets built, then `EnvWriter::write()` fails — the exact half-install OPS-002 guards, on those hosts
  only. **Bounded + backstopped:** the retry is idempotent (no duplicate tenant/admin) and the operator gets
  a clear «διόρθωσε δικαιώματα ρίζας και ξαναπροσπάθησε» message. A truly faithful test needs an actual
  temp-file+`rename()` write-probe, which was deliberately NOT taken (side-effects on the code root, per the
  OPS-002 round-1 review). Revisit only if a real host hits it.
- **Generic CSV importer (προϊόντα / πελάτες)** — bulk onboarding από άλλο σύστημα (έχουμε CSV *export*
  `CsvEntityExporter`· λείπει το *import*). Column-map + dry-run preview + tenant-scope. _Το μεγαλύτερο
  win για μεταφορά καταλόγου/πελατολογίου._
- **Setup profiles ανά κλάδο** (λιανική / εστίαση / ξενοδοχείο / υπηρεσίες) — bundle σε ένα κλικ: invoice
  types + default ΦΠΑ + σχετικά «πρότυπα τελών» (ξενοδοχείο → διαμονής· λιανική → σακούλα/ανακύκλωσης) +
  payment methods. Πάνω στο υπάρχον seeding.
- **Curated tax-presets** (βλ. PDF/UX ideas) — withholding/Ψηφιακό Τέλος Συναλλαγής presets ανά κλάδο για το per-invoice
  «Τυπικά τέλη/φόροι».
- **Κατάλογος συνήθων υπηρεσιών** (hosting/domain/SSL…) για WHMCS-style tenants — προαιρετικό template.

## 📡 myDATA sync — insights & next (sweep 2026-06-16)
_Από το interface sweep. Το **#1 Outbox** + **dashboard tiles** + **#2 διερεύνηση** πιάνονται
ΤΩΡΑ (βλ. `claude/polish-touches`)· τα παρακάτω είναι το follow-up._
- **#2 console split — «εκτός τρέχοντος καναλιού» vs «πραγματικά ανεπιβεβαίωτο».** _✅ SHIPPED (ήταν
  ήδη χτισμένο, η σημείωση ήταν stale): το `missingAtAade` σπάει mode-aware σε **imported legacy ΜΑΡΚ**
  (`legacy_id` set → prod MARK που το sandbox δεν επιστρέφει = ενημερωτικό) vs **native** (πραγματική
  ασυμφωνία). `SalesReconciliationResult::{imported,real,noise}MissingAtAade()` + `discrepancyCount()`
  εξαιρεί το sandbox-noise· η κονσόλα δείχνει banner + «Λείπουν από AADE» (μόνο real) + collapsed
  «Εισαγμένα (ΜΑΡΚ άλλου καναλιού)»· 3 unit tests (`SalesReconciliationResultTest`)._
- **#5 χαρακτηρισμός εισροών — rules-engine ✅ SHIPPED** («Κανόνες χαρακτηρισμού» + `ExpenseClassifier`:
  auto-apply στο import + bulk «Εφαρμογή κανόνων» + worklist «Προς χαρακτηρισμό» + «Δημιουργία κανόνα»).
  **Μένει deferred:** η **υποβολή για λογαριασμό τρίτου** (λογιστής) που θέλει `entityVatNumber` [323]
  (βλ. «Expenses — λογιστής/`entityVatNumber`»). Ιδέα: ekdosi **ετοιμάζει** τους χαρακτηρισμούς, ο
  λογιστής (δικό του login + ΑΦΜ + έγκριση) τους **στέλνει** — θέλει διερεύνηση ρόλων/δικαιωμάτων.
  _(#6 Βιβλίο→period report + Panel utility CSS: ✅ SHIPPED — βλ. «Done recently».)_

## 🔎 MCP forensics για το cutover — **OBS-001** (bucket A, SHIPPED)
**✅ SHIPPED** τα 5 read-only tools (`invoice_filing`, `mydata_failures`, `stuck_documents`,
`mydata_discrepancies`, `preflight`, βάση `ForensicMcpTool`) — βλ. `CHANGELOG.md` [Unreleased] +
`FEATURES.md §16γ` + `MCP.md`. Τα στοιχεία υπήρχαν ήδη (byte-exact XML ανά προσπάθεια)· προστέθηκε η
πρόσβαση. **Μένει (προαιρετικό, φθηνό):**
- _(**δομημένη INFO γραμμή ανά ΕΠΙΤΥΧΗ έκβαση**: ✅ SHIPPED — `App\Support\EInvoice\FilingLog::filed`,
  καλείται από το ένα success choke-point κάθε submitter (direct myDATA + πάροχος): invcode+ΜΑΡΚ στο
  ίδιο το μήνυμα (grep σε οποιοδήποτε), κανάλι/τύπος/διάρκεια στο context. Τώρα το
  `log_tail --contains=<invcode>` βρίσκει και τις ΕΠΙΤΥΧΕΙΣ υποβολές, όχι μόνο τις αποτυχίες.)_
- **delivery-mark forensics**: το `invoice_filing`/`mydata_failures` καλύπτουν τα `mydata_marks`
  (τιμολόγια)· τα `delivery_marks` (ΔΑ) έχουν δικό τους ιστορικό — να επεκταθούν όταν μπει η ΔΑ ροή.

## 🧾 MYD-007 follow-ups (per-line §8.3 model shipped)
Το per-line snapshot + guidance helper + preflight block **✅ SHIPPED**. Μένουν:
- **Type↔reason cross-check στην έκδοση** — προειδοποίηση/μπλοκ αν η αιτία γραμμής αντιφάσκει με τον
  τύπο (π.χ. αιτία 4 σε εγχώριο 1.1, ή αιτία 14 σε υπηρεσία 2.2). Υπάρχει ήδη `assertCounterpartCountryMatchesType`
  για χώρα↔τύπος· να προστεθεί το αντίστοιχο αιτία↔τύπος.
- **WHMCS inbox mapper** — οι προγραμματιστικά δημιουργημένες 0% γραμμές δεν ορίζουν per-line αιτία
  (πέφτουν στο tenant-wide fallback)· να περνά την αιτία από τον τύπο/κατηγορία.
- **Cleanup υπαρχόντων tenants** — το preflight ΕΠΙΣΗΜΑΙΝΕΙ τις reason-less/λάθος 0% κατηγορίες· να
  γίνει operator-review pass (χωρίς auto-guess ιστορικών αιτιών).
- **Ιστορικές γραμμές**: το migration `..._000021_backfill_zero_vat_line_exemption` γεμίζει την αιτία
  ανά γραμμή για tenants με ΜΙΑ μόνο αιτία 0% (unambiguous)· tenants με πολλές/καμία → preflight review.
- **Scenario-picker UI** — το `VatExemptionGuidance::scenarioOptions()`/`SCENARIOS` (πλήρης αναφορά,
  test-guarded) να συνδεθεί σε picker «Τι είδους 0%;» στη φόρμα κατηγορίας ΦΠΑ (σαν το DeliveryGuidance),
  ώστε ο χειριστής να διαλέγει σενάριο αντί για ωμό κωδικό. Σήμερα wired μόνο το `recommendForType()`.
- **Auto-suggest ordering** — η πρόταση §8.3 στη γραμμή ενεργοποιείται στην αλλαγή συντελεστή· αν ο
  χειριστής βάλει 0% ΠΡΙΝ επιλέξει τύπο, το πεδίο μένει κενό (υποχρεωτικό → μπλοκάρει save· ασφαλές).
  Follow-up: re-suggest και στην αλλαγή `invoice_type_id`.

## 🧾 MYD-006 follow-ups (business classification policy shipped)
Το `business_activity_type` + `ClassificationGuidance` + go-live gate + credit inheritance **✅ SHIPPED**. Μένουν:
- **Per-LINE income-class snapshot** — ✅ **SHIPPED** (WHMCS-bridge PR): στήλες
  `invoice_lines.mydata_income_class(+_category)`, ο `AadeInvoiceDocument::resolveIncomeClass` τις διαβάζει
  ΠΡΩΤΕΣ, ο WHMCS mapper τις γεμίζει, ΚΑΙ ο `IssueCreditNote` τις αντιγράφει στη γραμμή του πιστωτικού
  (ώστε ένα credit να αντιστρέφει την ΙΔΙΑ §8.6 κατηγορία). Manual invoices δεν επηρεάζονται (null → παλιά
  resolution). Μένει προαιρετικό: UI για χειροκίνητο per-line override στη φόρμα παραστατικού.
- **Mixed per-category enforcement** — το go-live gate κάνει PASS-με-υπενθύμιση για μικτό tenant (η
  ΕΠΙΛΟΓΗ πολιτικής είναι το blocking act· η ανά-κατηγορία ταξινόμηση είναι καθοδήγηση, όχι hard block —
  μια all-services κατηγορία νόμιμα μένει null). Δεν κάνει FAIL/WARN σε count of null overrides (θα ήταν
  θόρυβος). Follow-up: αν χρειαστεί σκλήρυνση, ένα goods/services flag στο ProductCategory θα επέτρεπε
  ακριβή απαίτηση «κάθε goods κατηγορία ταξινομημένη».
- **Policy re-application σε πιστωτικά** — η πολιτική εφαρμόζεται στις γραμμές πιστωτικού ΟΠΩΣ και στο
  αρχικό (product_id αντιγράφεται από τον `IssueCreditNote`, οπότε product-linked γραμμές κληρονομούν
  μέσω override· free-text→free-text παίρνουν και τα δύο την πολιτική) → συνεπές. Η ΜΟΝΗ απόκλιση: αν ο
  tenant ΑΛΛΑΞΕΙ `business_activity_type` μεταξύ έκδοσης αρχικού και πιστωτικού. Ο οριστικός fix
  (per-line income-class snapshot, παραπάνω) το κλείνει· μέχρι τότε αποδεκτό (mid-life αλλαγή είδους
  δραστηριότητας είναι σπάνια + αμφιλεγόμενο ποιο είναι το «σωστό»).
- **WHMCS «Εισερχόμενα» γραμμές → κατηγορία εσόδων** — ✅ **SHIPPED** (group-first χάρτης): σελίδα
  «Αντιστοίχιση WHMCS (έσοδα)» + `WhmcsIncomeMap` (scope group|product) + `WhmcsIncomeClassifier` +
  per-line stamp· plugin feed v0.44.0 δίνει `whmcs_product_id`/`whmcs_group_id`. Μένουν:
  - **Product-level override UI** — το schema (scope=product) + ο resolver το υποστηρίζουν ήδη· λείπει
    ΜΟΝΟ το UI (δεύτερο section στη σελίδα: πακέτα μιας ομάδας με δικό τους select). Group-only σήμερα.
  - **Non-hosting lines** — το plugin enrichment λύνει pid/gid μόνο για HOSTING γραμμές· domains (δεν
    είναι προϊόντα) + addons μένουν χωρίς gid → πέφτουν στον τύπο. Τα domains είναι υπηρεσίες ούτως ή
    άλλως (category1_3 από τον τύπο)· addon-mapping = follow-up αν χρειαστεί.
  - **Native (plugin-less) tenants** — ο εμπλουτισμός γραμμής γίνεται στο plugin feed· ένας tenant που
    δεν χρησιμοποιεί το bridge για το inbox δεν παίρνει pid/gid ανά γραμμή (πέφτει στον τύπο). Ο κατάλογος
    (`GetProducts`) δουλεύει για όλους· το enrichment ανά γραμμή θέλει το bridge.
- **Seeder δεν εφαρμόζει την πολιτική** — στο fresh install το `business_activity_type` είναι null, οπότε
  ο seeder δεν μπορεί να εφαρμόσει την πολιτική στις seeded κατηγορίες προϊόντων· το go-live gate εξαναγκάζει
  την επιλογή. Follow-up (προαιρετικό): κατά την επιλογή πολιτικής, auto-apply το goods bucket στις
  Εμπορεύματα/Προϊόντα seeded κατηγορίες.

## 🖥️ Console/interface polish (B — sweep 2026-06-16)
- _(**Auto-refresh-on-stale** στην Κονσόλα myDATA: ✅ SHIPPED 2026-06-17 — stale banner >6h + opt-in
  `mydata:refresh-console` scheduled warmer (όλα τα snapshots, default OFF, σαν το VAT picture). FEATURES §3.)_
- **Per-row import + «held/needs-review» state** στην κονσόλα-Έξοδα (ήδη στο «myDATA/expenses completeness»)
  — τώρα που υπάρχει το selective picker στη λίστα Έξοδα, το ίδιο μοτίβο ταιριάζει και στην κονσόλα.
- _(**MARK lifecycle chip** — DROPPED 2026-06-17: η πληροφορία ήδη φαίνεται (badge `mydata_state` + ΜΑΡΚ
  + link σε MARK detail/XML + κουμπιά lifecycle + «Ιστορικό»)· ένα γραμμικό chip θα **αντέφασκε** με το
  μοντέλο των δύο ορθογώνιων καταστάσεων `local_status` × `mydata_state` — π.χ. ακυρωμένο τοπικά αλλά
  ακόμα VALID στην ΑΑΔΕ δεν χωράει σε ευθεία ακολουθία. Χαμηλή αξία + κίνδυνος σύγχυσης.)_

## 🧾 ERP-parity ideas (C — sweep 2026-06-16)
_Έχουμε ήδη: balances/Καρτέλα, τραπεζικοί λογαριασμοί, κανάλια είσπραξης (IRIS/vPOS/μετρητά), πληρωμές,
πιστωτικά, προσφορές, recurring services (v1)._
- **Dunning ladder** — κλιμακωτές αυτόματες υπενθυμίσεις ληξιπρόθεσμων (3/7/15/30 ημ.) πάνω στο υπάρχον
  auto-email + `InvoiceBalance` (σήμερα: single resend). Templates ανά σκαλί + opt-out ανά πελάτη.
- **Bank-statement import → match πληρωμών** — ανέβασμα κίνησης (CSV/MT940) → auto-match σε ανοιχτά
  τιμολόγια (ποσό/ημερομηνία/ΑΦΜ) → προτεινόμενες `Payment` εγγραφές προς έγκριση.
- **Per-customer εκπτώσεις/τιμές** — _✅ SHIPPED 2026-06-17 (έκπτωση + τρόπος-πληρωμής ανά πελάτη
  εφαρμόζονται στην έκδοση· τύπος-wins fallback)._ Το **per-product τιμοκατάλογο** (default τιμή
  μονάδας ανά προϊόν×πελάτη) **DROPPED** σκόπιμα: η **per-line έκπτωση** τη στιγμή της έκδοσης +
  το per-customer default discount καλύπτουν την ανάγκη· πίνακας `customer_product_prices` =
  over-engineering χωρίς πραγματικό use case (re-open μόνο αν εμφανιστεί).
- **Multi-currency invoicing** — `currency` υπάρχει στο payload (EUR hardcoded)· πραγματικό FX +
  στρογγυλοποίηση + εμφάνιση. (myDATA θέλει EUR ισοτιμία — προσοχή.)
  _(Aged-receivables + Sendable customer statement: ✅ SHIPPED — βλ. «Done recently».)_
- **Επαφές (shared CRM)** — κοινή οντότητα `Contact` ↔ many customers με ρόλους (π.χ. ένας λογιστής/
  γραφείο που εξυπηρετεί πολλούς πελάτες-πελάτη), αντί για τις σημερινές per-customer `customer_contacts`.
  Σκόπιμα DEFERRED («κρατάμε τις επαφές per customer να μην μπλέξουμε») — future CRM phase· να μη σπάσει
  το per-customer μοντέλο που χρησιμοποιεί ήδη ο Sendable statement.
