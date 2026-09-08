# PEPPOL / B2G (Δημόσιες Συμβάσεις) + Estonia — reference

> Compiled 2026-06. For the **public-sector leg** (the `PeppolSubmitter` slot) and
> the Estonian (Nixpal OÜ) future. Authoritative rule set = the PEPPOL+EN16931
> **Schematron**, not prose. Uncertainty flags at the end.

## 0. The design takeaway (refines implementation-plan §7)
**PEPPOL UBL is a genuinely separate mapping from myDATA — NOT a reshuffle of
`MyDataSubmitter`.** PEPPOL needs full descriptive lines (item name, **quantity
always present**, allowances/charges) + **EN VAT category letters** (S/Z/E/AE/K/G/O);
myDATA needs **income/expense classification codes** (`E3_*`) and often **omits
quantity/description**. So the `PeppolUblDocument` serializer (plan §2.1) is real
new code over the **same canonical `Invoice` DTO** — confirming the "ΤΙ/ΠΩΣ" split:
shared model, different serializer + different transport (a certified Access Point,
not a REST POST to a provider).

## 1. PEPPOL BIS Billing 3.0 — what to emit
- Spec: https://docs.peppol.eu/poacc/billing/3.0/ (Nov-2025 release, self-versioned
  3.0.x). It is a **CIUS of EN 16931**, syntax **UBL 2.1**. A compliant instance is
  EN16931-compliant.
- Roots: **`ubl:Invoice`** (`InvoiceTypeCode` 380…) and **`ubl:CreditNote`**
  (`CreditNoteTypeCode` 381…) — two separate documents.
- **Two fixed identifiers (load-bearing — exact strings):**
  | Element | Value |
  |---|---|
  | `cbc:CustomizationID` | `urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0` |
  | `cbc:ProfileID` | `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0` |
- Data model = EN16931 **business terms `BT-1…150`** / **groups `BG-1…25`**. CIUS
  may tighten EN-optional → mandatory, never loosen mandatory. Authority for
  exactly which are required = the Schematron
  (`docs.peppol.eu/poacc/billing/3.0/files/PEPPOL-EN16931-UBL.sch` + EN16931 sch),
  **not** prose.
- **Routing identity:** structured seller/buyer with PEPPOL **`EndpointID` + scheme**.
  EAS scheme **`9933` = Greece VAT** → recipient `9933:EL123456789` (⚠ confirm `EL`
  prefix formatting with the chosen AP). Estonia = its registry/VAT EAS code (verify).

### PEPPOL vs AADE myDATA invoicesDoc (conceptual)
| | PEPPOL BIS 3.0 | AADE myDATA invoicesDoc |
|---|---|---|
| Purpose | the **delivered legal invoice** (B2G/B2B) | **tax-reporting summary** to AADE |
| Model/syntax | EN16931 CIUS, UBL 2.1 | proprietary AADE schema |
| Counterparty | full party + EndpointID for routing | GR B2B = VAT only, no name/addr (`[219]/[220]`) |
| Line | descriptive, **quantity always**, allowances | netValue+vatCategory+income class; quantity often omitted (`[205]`) |
| VAT | EN category letters + `BG-23` breakdown | `vatCategory` ints + exemption + 5 zero tax-totals |
| Classification | none (commercial) | `E3_*` income/expense **tax-ledger** codes |
| Result token | buyer receipt / MLR | **MARK** (+QR/UID) |

## 2. 4-corner + Access Points (the transport)
- **C1 sender → C2 sender-AP → C3 recipient-AP → C4 recipient**, AP↔AP over **AS4**.
- **You transmit through a CERTIFIED AP; you do NOT run your own** without OpenPeppol
  certification. For a PHP ERP → integrate a certified AP's API (the AP does AS4 +
  SML/SMP discovery). ⇒ `PeppolTransport` = adapter to a certified AP's API, exactly
  like a provider transport but the "provider" is an Access Point.
- Discovery: sending AP → **SML** (DNS) → recipient **SMP** → receiving-AP endpoint +
  accepted doc types + cert. (We don't implement this; the AP does.)

## 3. Greek B2G flow + legislation
**Flow:** supplier ERP → **certified provider/AP** (preliminary validation via ΚΕΔ
web services) → PEPPOL network → **ΚΕΔ (Κέντρο Διαλειτουργικότητας, ΓΓΠΣΨΔ)** = the
State's single national OpenPeppol-certified entry AP → **αναθέτουσα αρχή**
(contracting authority). myDATA validates tax compliance alongside.
**Legislation:** ν.4601/2019 (transposes **EU 2014/55/EU**); **ΚΥΑ 63446/2021** =
the National e-invoice format (Greek PEPPOL BIS profile); ΚΥΑ 98979/2021 = B2G
procedures. Mandatory B2G phased (≈12/9/2023 broad public sector; 1/6/2024 goods/
services tier — ⚠ tiered by authority, confirm per tenant).
**The obligation tie (matters for ekdosi):** once a tenant files the **«Δήλωση
Αποκλειστικής Έκδοσης Στοιχείων μέσω Παρόχου»** (§11), B2G invoices to the State
**must** go electronically via the provider **+ PEPPOL + ΚΕΔ** — not optional.
The GR AP must be a tax-certified provider whose PEPPOL AP **passed ΚΕΔ operational
tests** (the user's announcement). Official: gsis.gr/.../e-invoice (+ providers list).

## 4. Estonia (Nixpal OÜ)
- Accounting Act mandates **EN 16931**, no national CIUS beyond it; reaches recipients
  via a **PEPPOL AP**. B2G mandatory since **1/7/2019**.
- **From 1/7/2025:** any entity registered as an e-invoice recipient can **require**
  EN16931 e-invoices from suppliers; EN16931 is the default absent agreement.
- **Planned full B2B mandate ~2027** (⚠ planned, not firmly enacted — re-check).
- ⇒ Same `PeppolSubmitter` + any EN16931-capable AP (no ΚΕΔ for EE). One investment,
  three uses: GR-B2G, EU-B2B, Estonia.

## 5. PHP implementation notes
- **Emit:** UBL 2.1 Invoice/CreditNote with the two fixed URNs, EndpointID+scheme,
  descriptive lines, EN VAT letters, totals + `BG-23` breakdown.
- **Validate OUT-OF-PROCESS:** PHP's XSL ext is **XSLT 1.0 only**; PEPPOL Schematron
  compiles to **XSLT 2.0** → cannot validate in pure PHP. Use an external validator
  (Java / the AP's validation API / OpenPeppol tools). Build a validation step.
- **Candidate PHP UBL libs** (all *generate*, none bundle Schematron validation):
  `num-num/ubl-invoice` (PEPPOL BIS 3.0, PHP 8.x — most referenced),
  `easybill/e-invoicing` (EN16931 UBL+CII, Peppol/XRechnung/ZUGFeRD),
  `darvis/ubl-peppol`.
- **Fit:** `PeppolSubmitter` (the `ee-peppol` slot, reused for `gr-b2g`) = map
  canonical `Invoice` → PEPPOL UBL (new) → validate out-of-process → hand to a
  certified AP API. Same `EInvoiceSubmitter` contract.

## 6. Uncertainty flags (re-verify before building)
1. Exact mandatory BT-/BG- set → use Schematron, not prose. 2. GR EndpointID `EL`
prefix + EE EAS code → confirm with chosen AP. 3. GR B2G phase-in is tiered →
confirm per tenant. 4. EE 2027 full mandate = planned, not enacted. 5. gsis.gr
HTML + B2G spec PDF v1.2 blocked automated fetch → open in a browser to confirm the
National Format details.

### Sources
peppol BIS https://docs.peppol.eu/poacc/billing/3.0/ · CustomizationID/ProfileID
pages · EAS list https://docs.peppol.eu/poacc/billing/3.0/codelist/eas/ · GR flow
taxheaven /news/59655 · gsis.gr e-invoice (+ providers list, B2G spec PDF v1.2 — 403,
browser-confirm) · ν.4601/2019, EU 2014/55, ΚΥΑ 63446/2021 · EE: EU Commission
eInvoicing-in-Estonia, Sovos, vatcalc · PHP: github num-num/ubl-invoice,
easybill/e-invoicing, packagist darvis/ubl-peppol.
