# GR e-invoicing providers — integration survey

> Compiled 2026-06. How an external ERP integrates with each ΥΠΑΗΕΣ provider.
> **Honesty caveat:** several keep full specs behind a partner login/NDA — marked
> "behind login". No field names invented.

## The decisive finding (validates the architecture)
**The accepted document format genuinely DIFFERS per provider** — so each needs its
own thin **serializer + transport + auth**, behind a common interface. But the
**response contract is uniform** (MARK + authenticationCode/signature + UID + QR
URL + errors) → a single shared `ProviderResult` DTO is realistic.

| Format family | Providers | Adapter implication |
|---|---|---|
| **AADE `InvoicesDoc` XML passthrough** | **SBZ Systems**, the AADE baseline spec | serialize to AADE XML (firebed `InvoicesDocWriter`) + POST |
| **Proprietary JSON** | **SoftOne** (s1services SALDOC), **SoftOne ECOS / IMPACT** (`/invoice/json`) | bespoke JSON builder, NOT AADE XML |
| **Proprietary XML (non-AADE)** | **Entersoft** (`InputXMLAsString`) | bespoke XML builder |
| **AADE-XML core + extension** | **InvoSign** (AADE InvoicesDoc + `API_*` block) | firebed XML + appended extension (see invosign ref) |
| **Mixed JSON + AADE-XML** | **Primer** | either path |
| **PEPPOL BIS3 / UBL EN16931** | B2G leg (IMPACT runs own AP → ΚΕΔ) | separate UBL serializer (see peppol ref) |

**Auth also varies** (session-clientID handshake / `API-KEY` header / `aade-user-id`
+`ocp-apim-subscription-key` / subscription+user creds / unknown token) → reinforces
per-adapter transport. ⇒ Confirms plan §2: `{serializer + http-client + credentials}`
bundle per provider, shared canonical DTO + shared `ProviderResult`.

## Baseline: the AADE-mandated provider REST API
Every certified πάροχος implements this on its **upstream** side to AADE: accepts
**`InvoicesDoc` AADE XML**, HTTPS POST, auth headers `aade-user-id` +
`ocp-apim-subscription-key`, returns `ResponseDoc` with `invoiceUid` (40-char),
`invoiceMark` (MARK), `authenticationCode` (provider signature), `statusCode`,
`errors`. Providers re-expose variations of this to ERPs. (Community mirror of the
provider doc v1.0.10; AADE technical-specs hub.)

## Per-provider notes
- **SoftOne** — two surfaces: (1) **Soft1 Web Services** `/s1services` REST/JSON,
  session auth (`login`→`authenticate`→clientID), proprietary SALDOC payloads,
  demo `demo.oncloud.gr`; (2) **ECOS e-invoicing** `POST einvoiceapi.impact.gr/
  invoice/json` (UAT `einvoiceapiuat.impact.gr`), proprietary JSON, QR from
  `einvoice-portal.s1ecos.gr/v/<sig>`. **ECOS is hosted on IMPACT infra** — one JSON
  adapter may cover both. Sandbox: yes.
- **IMPACT (Mydata Connect)** — the e-invoicing engine behind ECOS. REST/JSON
  `/invoice/json`, UAT yes. **Operates its OWN PEPPOL Access Point** (BIS3 → ΚΕΔ) →
  the natural candidate for the **B2G** leg. Auth header not public.
- **Entersoft (Retail Link)** — connector: XML documents (`ESRPC_FIImportDocument`,
  `InputXMLAsString`, myDATA type field `fADMyDataDocumentTypeCode`) + JSON for
  entities. Business Suite creds. B2G/BIS3 claimed. Sandbox not public.
- **Primer** — public PDF (v1.7, scanned): REST/HTTPS, `form-data`+`raw-json` AND
  AADE-schema-compatible XML. Activation-based auth. Field names unverified (scan).
- **SBZ Systems (clean public reference)** — REST, **AADE `InvoicesDoc` XML only**
  (`application/xml`), auth header **`API-KEY`**, explicit sandbox vs production
  endpoints (`api.sbz.gr/sign/sendinvoice.php?action=sandbox|production`), returns
  `invoiceMark`/`invoiceUid`/`authenticationCode`/`statusCode`/`InvoiceUrl`/
  `myDATAUrl`. Best example of the AADE-XML-passthrough type.
- **Epsilon Net (Epsilon Digital)** — **no public spec / behind login.** Marketing
  only; do NOT hard-bake field names.
- **ILYDA (Meg myData, code 008)** — **no public spec / on request.** "WEB API".
- **Elorus** — public myDATA REST dev docs (developer.elorus.com).

## Two realities for the codebase
1. **SoftOne ECOS ≡ IMPACT backend** (`einvoiceapi.impact.gr`) → one JSON adapter
   likely covers both.
2. **Epsilon Net + ILYDA expose no public spec** → the interface must tolerate
   "spec-on-request" providers; don't bake public providers' field names onto them.

### Sources
AADE provider doc (community mirror v1.0.10) · softone.gr/ws · developers.s1ecos.com ·
einvoiceapi.impact.gr · learn.microsoft.com connectors (soft1, entersoft) ·
primer.gr MyDataAPIDocumentation v1.7 PDF · sbzsystems.com REST API doc ·
developer.elorus.com · ilyda.com · epsilonnet.gr.
