# Go-live / cutover runbook (per tenant)

The sequence to flip ONE tenant from the legacy C++Builder/Firebird app to ekdosi.
The automated gate (`ekdosi:go-live-check`) covers the config/data checks; the
**manual steps below bracket it** because they can't (or must not) be automated:
the Firebird-source probes need the legacy DB (not on the app host), and a real
AADE production submit files a legally real document.

> Read alongside: `docs/go-live-usage-checks.sql.md` (the Firebird probes),
> `INSTALL.md` (host provisioning), `php artisan ops:health` (infra posture).

## 0. Before you touch prod — on the ETL host (manual, Firebird)
Run the **dead-code/usage probes** from `docs/go-live-usage-checks.sql.md` against
the tenant's legacy `.fdb`/`.fbk`. They confirm whether the gap features
(withholding, 0% VAT, goods `<quantity>`, payment-method map, ΣΔΕΠ/cumulative)
were *actually used* in the source — i.e. whether anything we deliberately
deferred is real for THIS tenant. **Not automatable here** (the Firebird source
isn't on the app host; `pdo_firebird` lives only on the ETL host).

## 1. Import (ETL host)
```bash
php artisan migrate:firebird --company="MyIP" --slug=myip --fdb=… --host=… --fbuser=… --fbpass=…
php artisan invoices:recompute-balances --company=myip   # money cache after import
```

## 2. Credentials (production)
```bash
php artisan mydata:set-credentials --tenant=myip            # PRODUCTION key (hidden prompt)
```
Then set `mydata_mode = production` on the tenant (CompanyResource → myDATA tab).

## 3. Automated readiness gate ✅ (this is the one command)
```bash
php artisan ekdosi:go-live-check --tenant=myip      # 0 = ready, 2 = blockers, 1 = bad args
php artisan ekdosi:go-live-check --tenant=myip --json
```
Fix every **✗ (FAIL)** before proceeding — those are legal/correctness blockers
(AADE would reject, or documents can't be numbered/issued). **⚠ (WARN)** are
advisory (sandbox mode, no backups, dead queue worker); judge each. **– (SKIP)**
= not applicable to this tenant's provider (the myDATA gates skip for Estonian/
PEPPOL tenants). The gate reuses `mydata:preflight`'s code-table checks + the
`ops:health` infra slice, and adds the go-live-specific ones (production creds as
a hard FAIL, totals-drift golden check).

## 4. Manual AADE production smoke-test (human-gated — files a REAL document)
```bash
php artisan mydata:test-submit <invoiceId> --execute   # one real submission
php artisan mydata:reconcile-sales --tenant=myip        # confirm it landed at AADE
```
**Never automated** — it issues a legally real document. Do it once, on a
throwaway/known invoice, confirm the MARK + reconciliation, then (if it was a test
doc) cancel it through the normal lifecycle.

## 5. Flip live
- Confirm `ops:health` is green (queue worker + scheduler + backups).
- Announce to operators; the legacy app becomes a read-only archive.

---

### What's automated vs manual (so nobody re-litigates)
| Step | Automated? |
|------|-----------|
| Firebird dead-code/usage probes (§0) | ❌ manual (source DB off-host) |
| Config / VAT / numbering / credentials / totals-drift / infra | ✅ `ekdosi:go-live-check` |
| Real AADE production submit (§4) | ❌ manual (files a real document) |
