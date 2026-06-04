# AADE ΥΠΑΗΕΣ — regulatory update + corrections (2026-06)

> **Corrects several premises in `regulatory-blueprint.md`.** Live provider tables
> could not be auto-fetched (aade.gr/gsis.gr return 403) — pull names/license
> numbers from the official URLs, do NOT hardcode. No license numbers invented.

## ⚠ Corrections to the blueprint
1. **No 1/2/3 license tiers.** A.1112/2025 establishes a **single ΥΠΑΗΕΣ
   suitability license** (Άδεια Καταλληλότητας λογισμικού). The only categorical
   split is **Πάροχος** (serves third parties) vs **Ιδιοπάροχος** (self-provider,
   own B2B only). The "[1] Χονδρ./Λιαν., [2] Χονδρ., [3] Λιαν." tiering in the old
   blueprint is wrong (likely a ΦΗΜ-scheme confusion) — **dropped**.
2. **Ιδιοπάροχος has a hard bar: ≥ €50,000,000 gross income** (last fiscal year) +
   permanent establishment in Greece, **own wholesale (B2B) only**. → For our
   tenants (Nexon/MyIP) ιδιοπάροχος is **not realistic**; the strategy is
   unambiguously **bridge to an external certified provider** (plan §8).
3. **A.1035/2020 → replaced by A.1112/2025.** Existing valid licenses stay in force.
4. **A.1258/2020 (exclusive-issuance declarations) → repealed from 31 Oct 2025.**
   The declaration regime moved into **A.1128/2025 + A.1129/2025** (art. 71Θ
   ν.4172/2013). So implementation-plan §11's *mechanism* still holds (you declare
   exclusive issuance via provider), but cite **A.1129/2025**, not A.1258/2020, as
   current. A.1258 PDF stays as historical reference.
5. **Provider XSD is stale — but two different version numbers were conflated.**
   - **Providers API *documentation* version = v1.0.9–v1.0.12** (the PDF that
     describes the provider endpoints/fields). NOT a schema version.
   - **InvoicesDoc *XSD* version = v2.0.1** (the actual invoice schema). **firebed
     v5.10.4 already targets `InvoicesDoc-v2.0.1.xsd`** (`Invoice::VERSION='v2.0.1'`)
     and its `Invoice` model carries `ProvidersSignature` — so the current-version
     XML with provider-signature support is **already emittable from the library**.
   - The committed `aade-provider-invoicesDoc-v0.6.1.xsd` is an **obsolete
     standalone provider draft** — provider fields are now folded into the main
     v2.0.x InvoicesDoc. **Treat v0.6.1 as obsolete reference only;** rely on
     firebed's v2.0.1 target, and (couldn't auto-fetch — aade.gr WAF-blocks this
     host with 403) **manually download the current Providers API doc v1.0.12 + the
     v2.0.x InvoicesDoc XSD from the AADE technical-specs hub in a browser** if a
     byte-exact schema is needed before go-live.

## A.1112/2025 — verified facts (provider licensing & obligations)
- **ΦΕΚ Β' 4206 / 1.8.2025.** Title: «Υποχρεώσεις Παρόχων Υπηρεσιών Ηλεκτρονικής
  Έκδοσης Στοιχείων…». PDF `aade.gr/sites/default/files/2025-08/a_1112_2025.pdf`
  (403 to fetch — browser/diavgeia for the ΑΔΑ). Readable mirror:
  taxheaven.gr/circulars/50780/a-1112-2025.
- **Certification:** **ISO 27001** (or Committee-accepted equivalent) over
  confidentiality/integrity/availability in retention + transmission.
- **License validity 5 years**, unlimited renewals. **SLA ≥ 99% uptime/quarter.**
- **Suitability Committee** = 5 members (3 AADE + 1 commerce/industry + 1 IT/ΣΕΠΕ).
- **Δήλωση Έναρξης** via myAADE within **10 days** of the contract; keep the entity
  continuously informed of transmitted docs; retention per ν.4308/2014 (ΕΛΠ).
- **Penalty points** 5–25/infraction; 75 → warning, 100 → revocation.
- **No παράβολο/εγγυητική amount** specified (the €2M "εγγυητική" search hits are
  private-universities noise). Inspection costs borne by applicant.

## B2B mandatory timeline (verified) — supersedes vague "Οκτ 2026"
- **Legal basis:** **Council Implementing Decision (EU) 2025/502** (derogation, valid
  **1/7/2025 → 31/12/2027**) + **ν.5193/2025** + **A.1128/2025** (ΦΕΚ Β' 4937 /
  16-09-2025) sets dates; art.14 ν.4308/2014.
- **Phase A — 2 Feb 2026:** entities with **2023 gross revenue > €1,000,000**
  (adaptation window 2/2 – 31/3/2026).
- **Phase B — 1 Oct 2026:** **all other** obligated entities (window 1/10 – 31/12/2026).
- **Scope:** B2B within Greece + to non-EU foreign entities (excl. retail); B2G
  already covered.
- **Channel:** via a **certified Πάροχος (ΥΠΑΗΕΣ)** OR AADE's free **«timologio»**
  app. → a `none`-provider tenant has the timologio fallback; doesn't force us.
- **Incentive:** adopt ≥2 months early → 100% enhanced depreciation on hw/sw +
  100% uplift on issuance/transmission/archiving costs for the first 12 months.

## Official URLs (authoritative — fetch live)
- Providers hub: aade.gr/en/mydata/e-invoicing-service-providers
- **Licensed software (certified-providers table):** aade.gr/en/mydata/licensed-software-e-invoicing-providers
- Certification procedure: aade.gr/en/mydata/procedure-providers-certification
- **GSIS B2G/PEPPOL providers table:** gsis.gr/.../e-invoice/parohoi-ypiresion-ilektronikis-timologisis
- GSIS PEPPOL (Greece = National PEPPOL Authority): gsis.gr/.../e-invoice/peppol
- **myDATA/provider technical specs (XSD hub):** aade.gr/en/mydata/technical-specifications-versions-mydata
  - Provider API v1.0.9 PDF + ERP API v1.0.9 PDF (2024-10); v1.0.12 landing aade.gr/en/version-v1012

## Decisions map
| Decision | Scope | Status |
|---|---|---|
| Α.1035/2020 | provider obligations/audit | **replaced by A.1112/2025** |
| Α.1138/2020 | myDATA transmission (art.15Α ν.4174/2013) | in force (amended) |
| Α.1258/2020 | exclusive-issuance-via-provider declarations | **repealed from 31/10/2025** |
| Α.1155/2023 | ΦΗΜ↔POS↔PSP↔AADE interconnection | in force |
| **Α.1112/2025** | **provider licensing & obligations** | **current** (ΦΕΚ Β'4206/1.8.2025) |
| **Α.1128/2025** | **B2B mandatory scope + dates** | **current** (ΦΕΚ Β'4937/16.9.2025) |
| Α.1129/2025 | declarations of use (art.71Θ ν.4172/2013) | current — successor to A.1258 |

## Flags
ΑΔΑ of A.1112/2025 unconfirmed (PDF 403). Live provider tables not auto-fetched —
use official URLs. GR B2G phase-in tiered by authority — confirm per tenant.
