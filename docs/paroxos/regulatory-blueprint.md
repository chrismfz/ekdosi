# E-invoice provider bridge — blueprint (GR ΥΠΑΗΕΣ + EU PEPPOL)

> **Status: BLUEPRINT / deferred.** No code yet. Captures the design so the work
> is a known quantity if/when Greek e-invoicing **μέσω παρόχου** (Υ.ΠΑ.Η.Ε.Σ.)
> becomes mandatory, or we decide to issue via a provider, or Estonia (Nixpal
> OÜ) needs PEPPOL. Grounded in the AADE provider schema committed alongside
> (`reference/aade-provider-invoicesDoc-v0.6.1.xsd`), the **Α.1112/2025**
> certification forms, and **PEPPOL BIS Billing 3.0** (Nov 2025 release,
> https://docs.peppol.eu/poacc/billing/3.0/).

## TL;DR
- ekdosi is **architecturally ready**: `App\Contracts\EInvoiceSubmitter` +
  `EInvoiceSubmitterFactory` already route per `companies.einvoice_provider`
  (`gr-mydata` → `MyDataSubmitter`, `ee-peppol` → stub, `none` → `NullSubmitter`).
  A new path slots in as **one more submitter** — no surgery.
- The **payload we already build** (`MyDataSubmitter::buildAadeInvoice`) is ~95%
  of the provider schema. The provider XSD is the **same** `AadeBookInvoiceType`
  plus two provider-only fields (below).
- There is **no single "universal provider API"** for the ERP→provider hop —
  each provider has its own REST API. What *is* standard is the **document**
  (AADE invoice XML for GR; **EN 16931 / PEPPOL UBL** for the EU).
- **Ιδιοπάροχος** (self-provider) is a real, lighter category — you can be your
  own provider, **wholesale-only**, with **no customers** (see §4).

---

## 1. What a provider (Υ.ΠΑ.Η.Ε.Σ.) actually does beyond myDATA
Plain `SendInvoices` → ΜΑΡΚ+QR is the baseline everyone already does (us
included). A **certified provider** adds, on top of that:
1. **Ψηφιακή σφραγίδα / αυθεντικοποίηση** — guarantees *authenticity of origin*
   and *integrity of content* (the EU 2014/55 definition of an e-invoice). In
   the schema this surfaces as **`authenticationCode`** (Συμβολοσειρά
   Αυθεντικοποίησης Παρόχου).
2. **Replaces ταμειακή / ΕΑΦΔΣΣ for retail** — the provider IS the legal
   issuance mechanism (the modern successor to the dropped legacy `EAFDSS_SCRIPT`).
3. **Guaranteed delivery to the recipient** + structured format + archiving
   (the "4-corner" interchange, PEPPOL-like).
4. **Tax incentives** (Ν.4172/2013 — reduced statute of limitations, faster VAT
   refunds, extra depreciation) unlock when issuing via a provider.
5. **Offline tolerance** — `transmissionFailure` (1 or 2) signals the provider
   couldn't reach AADE in real time, with prescribed offline-issuance modes.

So the data overlaps with myDATA; the **legal guarantee + retail-hardware
replacement + delivery + incentives** are the added value.

---

## 2. The AADE provider schema vs what we already emit
`reference/aade-provider-invoicesDoc-v0.6.1.xsd` (`InvoicesDoc` →
`AadeBookInvoiceType`). It is the **same shape** as our myDATA submit payload —
`issuer` / `counterpart` / `invoiceHeader` / `invoiceDetails` (per-line net /
vatCategory / vatAmount / vatExemptionCategory / income+expense classification) /
`taxesTotals` / `invoiceSummary` — i.e. exactly what `MyDataSubmitter` already
builds. **Provider-only additions** (top of `AadeBookInvoiceType`):

| Field | Meaning | ekdosi today |
|---|---|---|
| `authenticationCode` | Συμβολοσειρά Αυθεντικοποίησης Παρόχου — the provider's seal/auth string | new (provider returns it, or WE mint it as ιδιοπάροχος) |
| `transmissionFailure` (1\|2) | Αδυναμία Επικοινωνίας Παρόχου — offline-issuance flag | new |
| `mark` / `cancelledByMark` / `uid` | as today | already mapped |

⇒ The mapping work for a GR provider is **small**: reuse `buildAadeInvoice`, add
`authenticationCode` + (rare) `transmissionFailure`, change only the *transport*.

> **⚠ STALE XSD (corrected 2026-06):** the committed `aade-provider-invoicesDoc-
> v0.6.1.xsd` is a very early draft. **AADE is now at provider v1.0.9–v1.0.12**
> (the provider `InvoicesDoc` carries `ProviderSignatureType`,
> `EndToEndReferenceID`, `invoiceDeliveryStatus` — and `firebed/aade-mydata`
> v5.10.4 **already models these**, see implementation-plan §2.1). **Re-pull the
> current provider XSD** from the AADE technical-specs hub before building; diff
> enums and pin to what the provider/AADE endpoint demands.

---

## 3. ekdosi integration points (where the code lands)
```
companies.einvoice_provider   →  EInvoiceSubmitterFactory::for($tenant)
  'gr-mydata'                 →  MyDataSubmitter        (built)
  'ee-peppol'                 →  PeppolSubmitter        (stub → §5)
  'none'                      →  NullSubmitter          (built)
  'gr-provider'  (NEW)        →  GrProviderSubmitter    (§4)
```
- `App\Contracts\EInvoiceSubmitter` is the seam (`submit()` / `cancel()`),
  already returning the ΜΑΡΚ and persisting the mark row. A provider submitter
  honours the same contract; lifecycle / reconciliation / PDF / QR are unchanged.
- **Reuse**, don't rebuild: `MyDataSubmitter::buildAadeInvoice` (payload),
  `mydata_marks` / `MyDataMark` (audit; add an `authentication_code` column or
  reuse the existing one on invoices), `Codes` (§8 tables), `InvoiceVatBreakdown`.
- **Internal representation → 2 serializers** is the clean target: one canonical
  ekdosi invoice → (a) AADE provider XML, (b) PEPPOL UBL. Today only the AADE
  one exists (inside `MyDataSubmitter`); factor it out when the 2nd is needed.

### Two strategic options
**(A) Bridge to an EXTERNAL provider** (someone else is the ΥΠΑΗΕΣ; we POST to
them). Lowest effort. A `GrProviderSubmitter` adapter per provider — there's no
universal API, but the generic surface is:
| Op | Typical endpoint | Returns |
|---|---|---|
| issue | `POST /invoices` (our AADE/UBL XML) | `mark`, `uid`, `authenticationCode`, `qrUrl` |
| cancel | `POST /invoices/{mark}/cancel` | cancellation `mark` |
| status / retrieve | `GET /invoices/{mark}` | state |
| (delivery proof) | provider-specific | — |
Auth is provider-specific (API key / OAuth). Pick ONE provider → one adapter +
per-tenant credential columns (mirror the `mydata_*` credential pattern).

**(B) BECOME the provider** (ιδιοπάροχος or full) — see §4. The *technical*
core (build XML, sign/seal, transmit to myDATA, archive) ekdosi largely has;
the rest is **regulatory** (certification), not code.

---

## 4. Becoming a provider — Α.1112/2025

> **⚠ Corrected 2026-06 — see `research/aade-regulatory-update.md` for sources.**
> The earlier "three license tiers" reading below was **WRONG** and is struck out.
> A.1112/2025 (ΦΕΚ Β' 4206/1.8.2025, **replaces A.1035/2020**) establishes a
> **single ΥΠΑΗΕΣ suitability license**. The only categorical split is **Πάροχος**
> (serves third parties) vs **Ιδιοπάροχος** (self-provider, own B2B only).
> Requirements: **ISO 27001** (or equivalent), **5-year** license, **≥99% uptime/
> quarter**, 5-member Suitability Committee, penalty-points (100 → revocation),
> **no παράβολο/εγγυητική amount specified**.

~~Three **Άδειες Καταλληλότητας**: [1] Χονδρικές/Λιανικές, [2] Χονδρικές,
[3] Λιανικές.~~ *(struck — no such tiers; single license.)*

### 🔑 Ιδιοπάροχος (self-provider) — "πάροχος μόνο για τον εαυτό μου"
- For issuing your **OWN** documents — **own wholesale (B2B) only**.
- **⚠ Hard bar: ≥ €50,000,000 gross income** (last fiscal year) + permanent
  establishment in Greece. → **Not realistic for Nexon/MyIP**; the strategy is
  unambiguously **bridge to an external certified provider** (implementation-plan §8),
  NOT becoming a provider. The ιδιοπάροχος application form stays as reference only.

### Dossier (Παράρτημα Α1 + Άρθρο 4 παρ.2) — both provider & ιδιοπάροχος
- Καταστατικό οντότητας.
- **ISO-27001** (ή άλλο ισοδύναμο *κατά την κρίση της Επιτροπής* για τήρηση
  ψηφιακών δεδομένων + αυθεντικοποίηση) — a security certification is
  unavoidable, though "ισοδύναμο" leaves a sliver of flexibility.
- Συνοπτική αναφορά χαρακτηριστικών ΥΠΑΗΕΣ.
- Φορολογική ενημερότητα · ασφαλιστική ενημερότητα (μη οφειλή).
- Πιστοποιητικά: μη-αίτηση πτώχευσης, μη-πτώχευση, μη-εκκαθάριση.
- **Έλεγχος ακεραιότητας / αυθεντικότητας**.
- **Τεχνική μεθοδολογία έκδοσης** (αρ.15 ν.4308/2014) · **διεπαφές λογισμικού**
  (Οντότητας-Παρόχου ή Φυσικών σημείων έκδοσης-Ιδιοπαρόχου) · **δείγματα
  παραστατικών** · **διαβίβαση δεδομένων διεπαφής myDATA**.
- Full provider ALSO: **σχέδιο πρότυπης σύμβασης ΥΠΑΗΕΣ** + (if retail)
  **διασύνδεση ταμειακών** (Α.1155/2023).

What ekdosi **already covers technically**: myDATA transmission ✅, AADE invoice
XML ✅, sample documents ✅, audit/archival of request/response XML ✅. The gaps
are **regulatory** (ISO-27001, the certificates, the committee process) — not a
feature backlog.

> ⚠️ The full Α.1112/2025 decision PDF (committee, timelines, validity,
> guarantee/εγγυητική, fees) is in the uploaded zip but couldn't be parsed in
> this environment. **Verify the current process on aade.gr/ΥΠΑΗΕΣ before
> deciding** — rules + timelines change often; this section is from the
> application *forms*, not the full decision text.

---

## 5. EU / PEPPOL side (Nixpal OÜ, Estonia) — and the convergence
- **PEPPOL BIS Billing 3.0** (EN 16931 CIUS, UBL 2.1 syntax) is the EU standard
  invoice. Estonia mandates structured e-invoicing; Nixpal OÜ will need a
  **PEPPOL Access Point** (4-corner: sender AP → recipient AP) — you don't run
  your own AP, you go through a certified one (a "provider" by another name).
- `PeppolSubmitter` (today a stub behind `ee-peppol`) becomes: canonical ekdosi
  invoice → **PEPPOL UBL** → AP transport. Same `EInvoiceSubmitter` contract.
- **Convergence**: EU **ViDA** pushes mandatory EN16931 B2B e-invoicing; the
  Greek provider model and PEPPOL are heading to the same place. So the
  "internal representation → 2 serializers (AADE XML / PEPPOL UBL)" design pays
  off twice — GR provider and EE PEPPOL share the serializer split and the
  4-corner/Access-Point transport concept.

---

## Reference files (committed under `reference/`)
- `aade-provider-invoicesDoc-v0.6.1.xsd` — the AADE **provider** invoice schema
  (`InvoicesDoc` / `AadeBookInvoiceType`; carries `authenticationCode` +
  `transmissionFailure`).
- `aade-A.1112.2025-provider-application-form.docx` — full-provider licence
  application (Άδεια [1]/[2]/[3]).
- `aade-A.1112.2025-self-provider-idioparochos-application-form.docx` —
  **ιδιοπάροχος** application (own docs, wholesale-only).
- `A.1258-2020-declarations-decision.pdf` — the AADE decision defining the
  **opt-in declarations** (Δήλωση Αποκλειστικής Έκδοσης μέσω Παρόχου / Αποδοχής
  Λήψης / Ανάκλησης) an entity files to issue via provider — see the
  implementation plan §11 (out-of-band prerequisite).
- `manual-paroxoi-2020-12-17.pdf` — AADE user manual for filing those
  declarations in **bookkeeper-web** (TAXISnet login; authorize-provider flow).
- NOT committed: the main Α.1112/2025 decision PDF (the AADE copy is **corrupt**
  — won't open anywhere; re-add when AADE publishes a valid one) and the 2.6 MB
  annex-templates zip (binary boilerplate, not needed for the blueprint).

## 6. Deferred TODO (when it's time)
1. Factor the AADE-invoice builder out of `MyDataSubmitter` into a reusable
   serializer (prep for a 2nd serializer).
2. `GrProviderSubmitter` + per-tenant provider credentials (pick a provider) —
   OR pursue **ιδιοπάροχος** certification if self-issuing via provider becomes
   required.
3. `PeppolSubmitter` (UBL serializer + Access Point client) for Nixpal OÜ.
4. Persist `authenticationCode` (+ `transmissionFailure`) on the mark/invoice.
5. Validate the provider XSD enum drift (v0.6.1 vs v2.0.0) before going live.
6. Re-confirm Α.1112/2025 specifics on aade.gr (this doc is forms-based).
