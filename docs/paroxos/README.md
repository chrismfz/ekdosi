# `paroxos/` — Πάροχος Ηλεκτρονικής Τιμολόγησης (ΥΠΑΗΕΣ) + PEPPOL

Όλο το υλικό για την **έκδοση τιμολογίων μέσω Παρόχου** (GR ΥΠΑΗΕΣ, υποχρεωτικό
B2B από Οκτ. 2026· B2G ήδη μέσω PEPPOL) σε έναν φάκελο.

## Περιεχόμενα
| Αρχείο | Τι είναι |
|---|---|
| **`implementation-plan.md`** | **Το σχέδιο υλοποίησης** — *πώς* το χτίζουμε: transport-adapter abstraction (πολλοί πάροχοι, ο καθένας με δικό του API), per-tenant provider+credentials κρατώντας τα myDATA creds, «Πάροχος Console», B2G/PEPPOL, οι δηλώσεις A.1258/2020, πρώτος transport (InvoSign). **Ξεκίνα από εδώ.** |
| `regulatory-blueprint.md` | Το *γιατί/κανονιστικό* — ο ρόλος του παρόχου πέρα από myDATA, το AADE provider XSD field-by-field, Α.1112/2025 πιστοποίηση, ιδιοπάροχος, PEPPOL/ViDA. |
| `reference/aade-provider-invoicesDoc-v0.6.1.xsd` | Το AADE **provider** invoice schema (`InvoicesDoc`/`AadeBookInvoiceType` + `authenticationCode`/`transmissionFailure`). |
| `reference/A.1258-2020-declarations-decision.pdf` | ΑΑΔΕ απόφαση: οι **δηλώσεις opt-in** (Αποκλειστικής Έκδοσης μέσω Παρόχου / Αποδοχής Λήψης / Ανάκλησης). |
| `reference/manual-paroxoi-2020-12-17.pdf` | ΑΑΔΕ εγχειρίδιο: υποβολή των δηλώσεων στο bookkeeper-web (εξουσιοδότηση Παρόχου). |
| `reference/aade-A.1112.2025-provider-application-form.docx` | Αίτηση άδειας πλήρους παρόχου ([1] Χονδρ./Λιαν., [2] Χονδρ., [3] Λιαν.). |
| `reference/aade-A.1112.2025-self-provider-idioparochos-application-form.docx` | Αίτηση **ιδιοπαρόχου** (δικά μας παραστατικά, χονδρική-only). |

## Η μία γραμμή
Με πάροχο, **στέλνεις** το τιμολόγιο στον πάροχο (γυρίζει ΜΑΡΚ+auth+QR)· τα
**myDATA credentials μένουν** ως read path για reconciliation/έξοδα/Ε3 (οι υποβολές
του παρόχου προσγειώνονται στο δικό σου myDATA → ανεξάρτητος έλεγχος). Το seam
(`EInvoiceSubmitter` + `EInvoiceSubmitterFactory`) **υπάρχει ήδη** — η δουλειά είναι
ένας generic `GrProviderSubmitter` + ένας transport adapter ανά πάροχο.

> **Status:** design-locked, **κανένας κώδικας ακόμη**. Σειρά υλοποίησης: βλ.
> `implementation-plan.md` §6 (P0 factor-out → … → P5 InvoSign → P6 PEPPOL).
