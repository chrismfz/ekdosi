# myDATA SUBMIT path — AADE sandbox validation

**Date:** 2026-05-28
**Endpoint:** AADE **dev/sandbox** (`mydataapidev.aade.gr`) — no production touched
**Branch:** `claude/mydata-submit-validate`
**Outcome:** ✅ All four invoice types the tenants issue (1.1, 2.1, 11.2, 5.1) + cancel **accepted by AADE — zero rejections, no code changes needed.**

The previously-validated scope was a single retail ΑΠΥ (11.2, PR #57). This run
extends the validation to B2B, service, retail, and credit-note types against the
live sandbox.

---

## 1. Headline results

| Invoice type | Scenario | Result | MARK |
|---|---|---|---|
| **1.1** | B2B τιμολόγιο πώλησης (counterpart w/ AFM) | ✅ filed | `400001964395579` |
| **2.1** | service ΤΠΥ (counterpart w/ AFM) | ✅ filed | `400001964395580` |
| **11.2** | retail ΑΠΥ (no counterpart) | ✅ filed | `400001964395581` |
| **5.1** | credit note (correlated to the 1.1) | ✅ filed | `400001964395582` |
| 2.1 | **CANCEL** of MARK `…580` | ✅ cancelled | (cancel accepted; state → CANCELLED) |

Local state after the run mirrors AADE exactly:

```
13517 SBTIM (1.1)  state=VALID      mark=400001964395579  local_status=active
13518 SBTPY (2.1)  state=CANCELLED  mark=400001964395580  local_status=cancelled
13519 SBAPY (11.2) state=VALID      mark=400001964395581  local_status=active
13520 SBPIS (5.1)  state=VALID      mark=400001964395582  local_status=active
```

---

## 2. Preflight output (config audit, read-only, no AADE calls)

### 2.1 `myip` — `php artisan mydata:preflight --tenant=myip` (exit 2)

```
Tenant: MyIP Networks O.E. (#1)

Invoice types (16):
 ✗ [INV] Invoice
 ERROR mydata_type missing (cannot file — [204]/[223])
 ✗ [ΑΚΑΠΥ] Ακυρωτική απόδειξη παροχής υπηρεσιών
 ERROR mydata_type missing (cannot file — [204]/[223])
 ✗ [ΑΚΤΔΑ] Ακυρωτικό τιμολόγιο - δελτίο αποστολής
 ERROR mydata_type missing (cannot file — [204]/[223])
 ✗ [ΑΚΤΠΥ] Ακυρωτικό τιμολόγιο παροχής υπηρεσιών
 ERROR mydata_type missing (cannot file — [204]/[223])
 ✗ [ΑΛΠ] Απόδειξη λιανικής πώλησης
 ERROR mydata_type missing (cannot file — [204]/[223])
 ✓ [ΑΠΥ] Απόδειξη παροχής υπηρεσιών
 ✗ [ΔΑΠ] Δελτίο αποστολής
 ERROR mydata_type missing (cannot file — [204]/[223])
 ✗ [ΔΠΡ] Δελτιο Παραλαβής
 ERROR mydata_type missing (cannot file — [204]/[223])
 ✓ [ΠΙΣ] Πιστωτικό τιμολόγιο
 ✗ [ΣΔΑΠ] Συγκεντρωτικό Δελτίο Αποστολής
 ERROR mydata_type missing (cannot file — [204]/[223])
 ✗ [ΣΔΕΠ] Συγκεντρωτικό δελτίο επιστροφης
 ERROR mydata_type missing (cannot file — [204]/[223])
 ✗ [ΤΔΑ] Τιμολόγιο Δελτίο Αποστολής
 ERROR mydata_type missing (cannot file — [204]/[223])
 ✗ [ΤΙΜ] Τιμολόγιο πώλησης
 ERROR mydata_type missing (cannot file — [204]/[223])
 ✓ [ΤΠΒ] Τιμολόγιο Παροχής υπηρεσιών (Σειρά 2)
 ✓ [ΤΠΥ] Τιμολόγιο παροχής υπηρεσιών
 ✗ [ΤΠΧ] ΤΠΧ Τιμολόγιο Παροχής υπηρεσίων
 ERROR mydata_type missing (cannot file — [204]/[223])

VAT categories (3):
 ✗ 10% — ΜΕΙΩΜΕΝΟ ΦΠΑ 9%
 ERROR rate 10% maps to no AADE VAT category (§8.2)
 ✓ 24% — 24%
 ✓ 24% — ΚΑΝΟΝΙΚΟ ΦΠΑ 24%


Summary: 13 error(s), 0 warning(s).
```

**Interpretation:** every invoice type myip *actually files* is clean (✓). The 13
errors are all on **dormant / never-filed legacy types** and one mis-typed VAT row.
Verified impact: `0` lines use the 10% VAT category, and `0` filed invoices use any
type that lacks `mydata_type`. Nothing here blocks filing.

myip invoice types actually used (DB-verified):

| Type | mydata_type | filed invoices | preflight |
|---|---|---|---|
| ΤΠΥ Τιμολόγιο παροχής υπηρεσιών | 2.1 | 6,551 | ✓ |
| ΑΠΥ Απόδειξη παροχής υπηρεσιών | 11.2 | 83 | ✓ |
| ΤΠΒ Τιμολόγιο Παροχής (Σειρά 2) | 2.1 | 43 | ✓ |
| ΠΙΣ Πιστωτικό τιμολόγιο | 5.2 | 9 | ✓ |
| INV / ΔΑΠ / ΤΠΧ (+ unused cancellation/delivery types) | NULL | 0 filed | ✗ dormant |

**Two config items left for operator decision (not fixed — ambiguous, touch real data):**
1. VAT row `rate=10.00` named "ΜΕΙΩΜΕΝΟ ΦΠΑ 9%" — there is no 10% Greek VAT rate;
   likely a typo for 9% (island-reduced → AADE category 5). 0 invoices use it.
2. 12 legacy types have no `mydata_type` (delivery notes, cancellation types,
   generic INV). Never filed. Map them only if myip will ever file them.

> Note: myip's ΠΙΣ credit note maps to **5.2** (non-correlated); the sandbox test
> validated **5.1** (correlated). If myip credit notes should correlate to their
> originals, that is a separate config/flow decision.

### 2.2 `nixpal` — `php artisan mydata:preflight --tenant=nixpal` (exit 0)

```
Tenant: nixpal (#2)
 mydata_mode is Off — no submissions will be attempted
 myDATA credentials not set (mydata_aade_id / mydata_subscription_key)

Invoice types (0):
 no invoice types configured

VAT categories (0):
 no VAT categories configured


Summary: 0 error(s), 4 warning(s).
```

**Interpretation:** unconfigured / dormant tenant, `mode=off`. Nothing to file.

### 2.3 `sbx` (sandbox test tenant) — `php artisan mydata:preflight --tenant=sbx` (exit 0)

```
Tenant: SANDBOX myDATA Test (#4)

Invoice types (4):
 ✓ [SBAPY] Sandbox ΑΠΥ (11.2)
 ✓ [SBPIS] Sandbox Πιστωτικό Συσχ. (5.1)
 ✓ [SBTIM] Sandbox Τιμολόγιο Πώλησης (1.1)
 ✓ [SBTPY] Sandbox Τιμολόγιο Παροχής (2.1)

VAT categories (1):
 ✓ 24% — ΚΑΝΟΝΙΚΟ ΦΠΑ 24%


✓ Pre-flight clean — no configuration issues found.
```

---

## 3. Filed payload shapes (verified accepted by AADE)

Common shape across all types (the PR #57 shape, confirmed correct for B2B / service /
retail / credit):

- issuer `vatNumber` + `country=GR` + `branch=0`
- counterpart **present** for 1.1 / 2.1 (GR VAT, **no** name/address per `[219]/[220]`),
  **omitted** for 11.2 retail
- `invoiceHeader`: series, aa, issueDate, invoiceType, `currency=EUR`
- `paymentMethods`: one detail, `type=3` (cash), `amount = gross`
- per-line: `netValue`, `vatCategory=1` (24%), `vatAmount`, income classification
  (`E3_561_xxx` + `categoryN_x`) — **no `<quantity>`** (optional per spec §line 1249)
- `invoiceSummary`: net, vat, the **five zero tax-total fields**
  (`totalWithheldAmount`/`totalFeesAmount`/`totalStampDutyAmount`/
  `totalOtherTaxesAmount`/`totalDeductionsAmount`), gross, aggregated income classification
- **no `<uid>`** (AADE rejects it — `[273]`)
- **no `<taxesTotals>`** (VAT lives per-line, not as a taxType)

### Credit note 5.1 — the correlation (the new bit)

```xml
<invoiceHeader>
  <series>SBPIS</series>
  <aa>1</aa>
  <issueDate>2026-05-28</issueDate>
  <invoiceType>5.1</invoiceType>
  <currency>EUR</currency>
  <correlatedInvoices>400001964395579</correlatedInvoices>   <!-- ← original 1.1 MARK -->
</invoiceHeader>
```

The credit note correctly correlates to the 1.1 original's INSERT MARK.

---

## 4. Reconciliation — `php artisan mydata:reconcile-sales --tenant=sbx` (exit 2)

```
Tenant : SANDBOX myDATA Test (#4)
Window : 28/05/2026 – 28/05/2026

+----------------------+-------+
| Bucket               | Count |
+----------------------+-------+
| AADE total           | 5     |
| Local total          | 4     |
| Matched              | 4     |
| State mismatch       | 0     |
| Missing at AADE      | 0     |
| Missing locally      | 1     |
| Duplicate local MARK | 0     |
+----------------------+-------+

Missing locally:
 ΑΠΥ 999001 MARK=400001964394607 Υπάρχει στο AADE αλλά δεν βρέθηκε τοπικά
 (πιθανή υποβολή από άλλο σύστημα ή χαμένη εγγραφή).

1 discrepancies found.
```

**Interpretation:** all 4 of our filed docs **matched**; the cancelled 2.1 shows the
correct cancel-state on both sides (`State mismatch: 0`); nothing missing at AADE. The
single **Missing locally** (`ΑΠΥ 999001`) is a pre-existing doc filed earlier to this
shared-sandbox AFM by another tester — exactly what that bucket is designed to surface,
not a bug.

---

## 5. Method / scope notes

- Filing was done from the purpose-built **`sbx`** tenant (`gr-mydata`, `mode=sandbox`,
  AFM `800561849`), **not** from `myip` — so myip's real invoice numbering is untouched.
  `sbx` already had the 4 matching invoice types + income classifications + 24% VAT and
  3 drafts; the 4th (credit note) was created for this test.
- `sbx` shares myip's AFM, so myip's working **sandbox** credentials were copied
  DB-to-DB (never displayed/logged) to let `sbx` file with a matching issuer. `sbx` is
  now self-sufficient for future sandbox filing tests.
- No `MyDataSubmitter` changes were required — the submitter is correct as-is for these
  types. `php artisan test --filter=MyDataSubmitter` → 13 passed.
- All work is on AADE **sandbox**; every tenant remained `mode=sandbox`/`off`. No
  production submissions.

## 6. Still-deferred submitter follow-ups (NOT bugs — none hit by these 4 types)

- 0% VAT exemption path (`vatExemptionCategory`, `[217]`)
- 4% / island VAT regime (AADE category 6 vs 10 ambiguity)
- conditional per-line `<quantity>` for goods invoice types
- `taxesTotals` for withholding / fees / stamp-duty invoices
- per-`PaymentMethod` → myDATA payment-type map (currently hardcoded `type=3` cash)
