# Provider integration checklist — τι ζητάμε από κάθε πάροχο

> Για τους shortlisted (**SBZ Systems**, **InvoSign**) και κάθε μελλοντικό. Στόχος:
> να πάρουμε **με μία επικοινωνία** όλα όσα χρειάζεται ο `GrProviderSubmitter` +
> ο `InvoSignTransport`/`SbzTransport`, χωρίς δεύτερο γύρο emails.

## Α. Τι ζητάμε (email template)

> Είμαστε ERP (ekdosi) και θέλουμε να εκδίδουμε ΜΑΡΚ μέσω εσάς για λογαριασμό
> πελατών μας. Παρακαλώ για:
>
> 1. **Δοκιμαστικό περιβάλλον (sandbox/demo):** endpoint URL(s) + credentials, και
>    αν διαφέρει από το production μόνο στο URL ή και στα keys.
> 2. **Authentication:** τι στέλνουμε (API key σε header; token σε body; OAuth;) και
>    με ποιο όνομα πεδίου/header.
> 3. **Document format:** δέχεστε το **AADE myDATA `InvoicesDoc` XML** ως έχει; Αν
>    όχι, ποιο σχήμα (JSON/δικό σας XML) — και υπάρχει XSD/JSON-schema/δείγμα;
> 4. **Endpoints:** έκδοση, ακύρωση, status/ανάκτηση — paths + HTTP method + content-type.
> 5. **Response:** ποια πεδία επιστρέφονται (ΜΑΡΚ, authentication code, QR url, uid),
>    και το σχήμα σφάλματος (codes/messages) — ένα δείγμα success + ένα error.
> 6. **Idempotency / επανυποβολή:** πώς αποφεύγεται διπλό filing σε timeout/retry;
>    υπάρχει status-check για να ανακτήσουμε ΜΑΡΚ αν χαθεί η απάντηση;
> 7. **Offline / transmissionFailure:** υποστηρίζεται η offline έκδοση (σημαία 1–4);
> 8. **Onboarding:** χρειάζεται η οντότητα να σας εξουσιοδοτήσει στο bookkeeper-web +
>    «Δήλωση Αποκλειστικής Έκδοσης» (A.1129/2025) πριν το go-live; (ποια η σειρά).
> 9. **PEPPOL / B2G:** είστε πιστοποιημένο PEPPOL Access Point για Δημόσιες Συμβάσεις;
> 10. **Rate limits / SLA / κόστος** ανά παραστατικό ή συνδρομή.

## Β. Τι ξέρουμε ήδη (από το research — διασταύρωσέ το στην απάντηση)

| | SBZ Systems | InvoSign |
|---|---|---|
| Format | καθαρό AADE `InvoicesDoc` XML | AADE XML + `<API_InvoiceDetails>` extension |
| Auth | `API-KEY` header | `token` (form field) |
| Issue endpoint | `api.sbz.gr/sign/sendinvoice.php?action=sandbox\|production` | `[base_url]/iNVOSign_Api.php` |
| Cancel | (ρώτησε) | `iNVOSign_CancelDeliveryNote.php` (`mark`+`token`) |
| Status | (ρώτησε) | `invoice_status.php` (issuer/series/aa/date) |
| Response | `invoiceMark`/`authenticationCode`/`invoiceUid`/`InvoiceUrl`/`myDATAUrl` | `invoiceMark`/`authenticationCode`/`qrUrl`/`invoiceUid` |
| Sandbox | ✅ ρητό action=sandbox | ✅ `demo_base_url`+`demo_token` |
| Public doc | ✅ | ✅ (`invosign.gr/site/help_site`) |

Λεπτομέρειες: `research/providers-survey.md`, `research/invosign-api-reference.md`.

## Γ. Τι κάνουμε μόλις έρθουν sandbox creds (→ P5)

1. Αποθήκευση creds στο `companies.einvoice_provider_config` (encrypted) + set
   `einvoice_provider='gr-provider'`, `einvoice_provider_key`, `_mode='sandbox'`.
2. Γράφουμε τον `*Transport` (send/cancel/status/ping) — SBZ ≈ POST του υπάρχοντος
   `AadeInvoiceDocument::toXml()` + `API-KEY`· InvoSign = + το extension block.
3. `GrProviderSubmitter` (persist `mydata_marks` + provider columns, lifecycle sync).
4. **Sandbox-validate** τους ίδιους τύπους όπως το myDATA (1.1/2.1/11.x/5.1 + cancel)
   — όπως το report 2026-05-28. Διασταύρωση ΜΑΡΚ με myDATA read path.
5. Health banner + status-check-before-retry (§14).

## Δ. Κριτήριο επιλογής «νικητή» για πρώτο live
Όποιος δώσει **sandbox πρώτος** → πρώτο reference impl. Αν και οι δύο, προτεραιότητα
**SBZ** (μηδέν serializer, πιο γρήγορο sandbox-validate), μετά InvoSign (extension).
Για tenant που κάνει **Δημόσιο** → ξεχωριστή αξιολόγηση PEPPOL AP (π.χ. IMPACT).
