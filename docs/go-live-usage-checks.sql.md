# Go-live usage checks — does any tenant actually use the gap features?

Read-only probes to decide whether the myDATA-filing gaps are **blockers** or
just **correctness insurance**. Run them against each tenant's **legacy
Firebird DB** *before* cutover; the matching MariaDB queries verify the same
after import.

Grounded in the real legacy schema (`legacy/ekdosi-schema.sql`):
`INVOICE` (`WITHHOLD_AMOUNT`, `INVTYPE`, `INVDATE`, `CONV_INVOICE_ID`,
`PAYMETH_ID`), `INVLINES` (`VATPERCENT`), `INVTYPE` (`INVTYPE_ID`, `NAME`),
`CUSTOMER` (`WITHHOLD_TAX`), `PAYMENT_METHOD`.

## How to run

These are plain **Firebird SQL** — they work the same whether you:

- **restore a `.fbk`** then point `isql` at the `.fdb`:
  ```bash
  gbak -r ekdosi.fbk fresh.fdb -user SYSDBA -password masterkey
  isql -user SYSDBA -password masterkey fresh.fdb -i go-live-usage-checks.sql
  ```
- or run against a **live `.fdb`** directly (read-only — `SELECT`s only):
  ```bash
  isql -user EKDOSI -password <FB_PASSWORD> /opt/Data/ekdosi-myip.fdb
  ```

> Re "do these also stand for direct imports?" — yes. They target the Firebird
> **source**, independent of whether you then ETL it. The ETL itself doesn't
> change the source. After import, run the **MariaDB** block at the bottom to
> confirm the same counts landed in ekdosi.

Run once per tenant `.fdb` (myip, nixpal, …); a feature is a **blocker** only
for tenants where its count is non-zero.

---

## G1 — Withholding (παρακράτηση)  → needs `taxesTotals` + `withhold_category`

```sql
-- (1a) How many invoices carry a withholding amount, and the date span?
SELECT COUNT(*) AS withhold_invoices, MIN(INVDATE) AS first_seen, MAX(INVDATE) AS last_seen
  FROM INVOICE
  WHERE WITHHOLD_AMOUNT IS NOT NULL AND WITHHOLD_AMOUNT > 0;

-- (1b) Which invoice types use it (maps to which ekdosi invoice_types need a
--      withhold_category at file time)?
SELECT i.INVTYPE, t.NAME, COUNT(*) AS n, SUM(i.WITHHOLD_AMOUNT) AS total_withheld
  FROM INVOICE i LEFT JOIN INVTYPE t ON t.INVTYPE_ID = i.INVTYPE
  WHERE i.WITHHOLD_AMOUNT > 0
  GROUP BY i.INVTYPE, t.NAME
  ORDER BY n DESC;

-- (1c) Customers flagged for withholding (the legacy auto-20% trigger source).
SELECT COUNT(*) AS withhold_flagged_customers FROM CUSTOMER WHERE WITHHOLD_TAX = 1;
```
**Interpretation:** (1a) = 0 → G1 is insurance, not a blocker. > 0 → set the
right §8.4 `withhold_category` on those invoice flows before filing.

---

## G4 — 0% / VAT-exempt lines  → needs `vatCategory=7` + a `vat_exemption_category`

```sql
-- (4a) Count of 0%-VAT invoice lines.
SELECT COUNT(*) AS zero_vat_lines FROM INVLINES WHERE VATPERCENT = 0;

-- (4b) Distinct invoices with a 0% line, by type + date span.
SELECT i.INVTYPE, t.NAME, COUNT(DISTINCT i.INVOICE_ID) AS invoices_with_zero_line,
       MIN(i.INVDATE) AS first_seen, MAX(i.INVDATE) AS last_seen
  FROM INVOICE i
  JOIN INVLINES l ON l.INVOICE_ID = i.INVOICE_ID
  LEFT JOIN INVTYPE t ON t.INVTYPE_ID = i.INVTYPE
  WHERE l.VATPERCENT = 0
  GROUP BY i.INVTYPE, t.NAME
  ORDER BY invoices_with_zero_line DESC;

-- (4c) Every distinct VAT rate actually used (also surfaces island rates
--      4/9/17 → relevant to the 4%-ambiguity follow-up).
SELECT VATPERCENT, COUNT(*) AS lines FROM INVLINES GROUP BY VATPERCENT ORDER BY VATPERCENT;
```
**Interpretation:** (4a) = 0 → G4 is insurance. > 0 → for each tenant with 0%
lines, set the exemption reason (§8.3) on its 0%-rate VAT category. If (4b)
shows several *different* exempt reasons mixed in one tenant, flag it — the
submitter currently resolves a single tenant exemption (documented limit).

---

## G5 — Goods invoice types  → would need per-line `<quantity>`

```sql
-- (5a) Invoice types in use + volume — judge which are GOODS vs services.
SELECT i.INVTYPE, t.NAME, COUNT(*) AS invoices
  FROM INVOICE i LEFT JOIN INVTYPE t ON t.INVTYPE_ID = i.INVTYPE
  GROUP BY i.INVTYPE, t.NAME
  ORDER BY invoices DESC;

-- (5b) The full invoice-type lookup (names + myDATA income class hint at
--      goods vs services).
SELECT INVTYPE_ID, NAME, MYDATA_INCOME_CLASS, MYDATA_INCOME_CLASS_CATEGORY FROM INVTYPE;
```
**Interpretation:** services-only (the validated 1.1/2.1/11.2 path) → G5 is
insurance. Any goods sales type in real use → AADE will reject without per-line
quantity; build G5 first for that tenant.

---

## G9 — Payment-method → myDATA type  (submitter hardcodes type 3 = cash)

```sql
-- (9a) Which payment methods are actually used on invoices?
SELECT i.PAYMETH_ID, COUNT(*) AS invoices
  FROM INVOICE i GROUP BY i.PAYMETH_ID ORDER BY invoices DESC;

-- (9b) The payment-method lookup (to map each → a myDATA §8.12 type 1–8).
SELECT * FROM PAYMENT_METHOD;
```
**Interpretation:** if everything is effectively cash → hardcoded type 3 is
fine. If bank transfer / card / on-credit appear, build the G9 map so the
filing's `paymentMethods.type` is factually correct.

---

## ΣΔΕΠ / cumulative invoices  (suspected dead code — confirm before building)

```sql
-- Any invoice actually linked into a cumulative/ΣΔΕΠ chain?
SELECT COUNT(*) AS cumulative_links FROM INVOICE WHERE CONV_INVOICE_ID IS NOT NULL;
```
**Interpretation:** 0 → confirms the legacy ΣΔΕΠ machinery is dead; do NOT build
it. > 0 → there's real cumulative data; revisit the LOW-priority ΣΔΕΠ item.

---

## After import — MariaDB equivalents (verify the same on the ekdosi side)

Run per tenant (`--where company_id = <id>`); these confirm the import carried
the same usage and that the new columns are populated where expected.

```sql
-- G1: withholding invoices now in ekdosi (+ how many still miss a category).
SELECT COUNT(*) AS withhold_invoices,
       SUM(withhold_category IS NULL) AS missing_category
  FROM invoices WHERE withhold_amount > 0 AND company_id = ?;

-- G4: 0% lines + whether the tenant has an exemption reason configured.
SELECT COUNT(*) AS zero_vat_lines FROM invoice_lines l
  JOIN invoices i ON i.id = l.invoice_id
  WHERE l.vat_percent = 0 AND i.company_id = ?;
SELECT id, description, vat_exemption_category
  FROM vat_categories WHERE rate = 0 AND company_id = ?;

-- G9: payment methods seen on issued invoices.
SELECT payment_method_id, COUNT(*) FROM invoices
  WHERE company_id = ? GROUP BY payment_method_id ORDER BY 2 DESC;
```
