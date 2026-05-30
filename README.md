# ekdosi — Laravel 13 / Filament 5 / MariaDB

Modern rewrite of a legacy **C++Builder (VCL) + Firebird** invoicing app
("ekdosi"). Multi-tenant (one MariaDB, `company_id` on every table),
operator-only Filament panel, Greek **myDATA** e-invoicing via
`firebed/aade-mydata`, a **WHMCS** billing bridge over the WHMCS API, and
a path to **Estonian PEPPOL** for the non-Greek tenant. `CLAUDE.md` holds
the full design history, decisions, and per-PR notes; this file is the
high-level map + current status.

---

## Status at a glance (2026-05-31)

The core operator workflow — **issue an invoice → file at myDATA → PDF →
email → record payment / credit note → reconcile with AADE** — is built,
unit-tested, and sandbox-validated. Since then the app has moved **past the
legacy**: the **Έξοδα/expenses** phase, the **scheduler**, the **draft-first
WHMCS bridge with bi-directional visibility** (the WHMCS operator sees the
MARK + a WHMCS#→ΤΠΥ→ΜΑΡΚ map), third-party invoicing, and myDATA console
polish (orphan bucketing, cached fetches, MARK detail, E3 income/expense split)
are all in. What remains is mostly niche legacy flows (mostly dead code) and a
few tracked follow-ups. See `docs/Comparison.md`.

**✅ Done (built + unit-tested):**
- Tenancy (Company tenant), roles/permissions (Shield), audit-ready models.
- Customers (+ AADE/GSIS lookup), Products (+ price tiers), all 7 lookup tables.
- **Καρτέλα πελάτη** — full customer financial ledger (aging, yearly, running balance, WHMCS cross-ref, AADE διασταύρωση).
- Invoices: create/edit/view, race-safe numbering, VAT/discount/rounding math, QR, PDF.
- **Invoice lifecycle** — `local_status` (draft/active/cancelled) orthogonal to the AADE `mydata_state`.
- **myDATA submit + cancel + dry-run** (the firebed wrapper) — submit path **validated against the AADE sandbox** (PR #57: a retail ΑΠΥ filed & accepted; payload fixes grounded in the legacy MARK request).
- **myDATA sales reconciliation** ("Κονσόλα myDATA") — live `RequestTransmittedDocs` cross-check (Phase 2; **sandbox-verified 2026-05-28** — parser needed no changes).
- **Payments** (per-invoice + on-account, balances, payment status) and **credit notes / πιστωτικά**.
- **WHMCS bridge** — API client, webhook ingest, operator inbox, file-at-AADE, MARK write-back; plus the **`ekdosi_bridge` WHMCS-side plugin**.
- PDF rendering, per-tenant email + send-log, dashboard + metrics widgets.
- Re-runnable **Firebird → MariaDB ETL** (`migrate:firebird`) + an in-panel import UI.

**🚧 Partial / needs finishing:**
- **Auto-email** — both issue paths covered (myDATA-VALID + non-myDATA finalize), per-customer opt-out; remaining gap is a **batch mail sweep** (bulk / failure re-send).
- **PDF templates** — one adaptive Blade template vs. the 8 legacy FastReport designs (ΑΠΥ/ΤΠΥ/ΣΔΕΠ/ΣΔΑΠ/…).
- **WHMCS `ekdosi_bridge` plugin** — now `v0.5.0` (inbox push/status, draft-first, third-party, MARK/list badges, 3-way client map); still under-reacts to some distinct ekdosi error responses; no bulk push.

**✅ Since 2026-05-28 (now done):** scheduler wired (`routes/console.php`);
**Έξοδα/expenses** phase (suppliers, RequestDocs, classification, ΦΠΑ, Ε3);
**WHMCS third-party invoicing** (resolve + block-and-split); **griniaris**
auto-issue (`whmcs:auto-issue`, two-key armed); **gross-price edit**, **G1/G4**
(withholding / 0%-exempt) and the filing-correctness set; **draft-first WHMCS
inbox** + **WHMCS-side visibility** (MARK badge, invoice-list badge, "Send to
ekdosi", 3-way client map); myDATA console polish + auto DB backup on deploy.

**❌ Not yet / tracked follow-ups:**
- **WHMCS write-back on lifecycle-filed drafts** — `invoiced=MARK` + pending→filed
  fire on the direct path only; wire off `MyDataSubmitter`'s VALID persist (also
  fixes split drafts).
- **Stock / inventory movements** — *was an empty stub in legacy* (not a real gap); build only if a live tenant needs it.
- **ΣΔΕΠ / cumulative invoices** — *dead code in legacy*; confirm `.fbk` usage first.
- **"Assigned invoices" (`invoiced=-333/-1000`)** sentinels — purpose unconfirmed.
- **Live VIES/AFM** realtime validation (GSIS lookup is native; VIES live not wired).
- **Estonian PEPPOL** — provider selectable but submission is a no-op stub.
- **activitylog** wiring; **batch mail sweep**; PDF attach + sales-orphan import (see `docs/expenses-phase-plan.md`).

**🗑️ Deliberately dropped:** CS-Cart bridge, EAFDSS signing, `FMysqlSync` MySQL mirror, `GET_COMB_*` cross-DB procs, FastReport `.fr3` (replaced by Blade PDF), `afm2name` WHMCS plugin (ekdosi does GSIS natively).

See **`CLAUDE.md` → "Where we stand — Legacy vs New + roadmap"** for the detailed breakdown and per-item impact.

---

## What's here
- `app/`, `database/`, `config/`, … — Laravel 13 app at the repo root.
  Currently **48 migrations**, 21 models, 15 Filament resources, 3 custom
  pages, ~36 service classes, 6 artisan commands.
- `app/Console/Commands/MigrateFromFirebird.php` — re-runnable ETL (`php artisan migrate:firebird`).
- `whmcs-plugin/ekdosi_bridge/` — **our** WHMCS-side plugin (deployed into the tenant's WHMCS install; NOT legacy).
- `/legacy/` — read-only reference from the old stack:
  - `/legacy/ekdosi-schema.sql` — `isql -x` schema dump.
  - `/legacy/ekdosi-main/` — C++Builder source (VAT/rounding/discount math + workflow reference).
  - `/legacy/ekdosi-main/db_backup/ekdosi.fbk` — Firebird gbak for sandboxed ETL dev.
  - `/legacy/whmcs/` — the three **archived** legacy WHMCS plugins (afm2name, prepare_for_ekdosi, timologia).

## Key decisions (and why)
- **Multi-tenant, not per-DB.** Superset: deploys per-DB later if needed; the reverse can't.
- **Surrogate keys + `legacy_id`.** Legacy integer PKs collide across companies
  (CUST_ID=1 exists in myip and nixpal). Every table gets a fresh `id`; `legacy_id`
  (unique per company) is kept for audit and to make the ETL re-runnable. FKs are
  rewired via in-memory `legacy_id → new_id` maps during import.
- **Numbering: continuous counter per type.** `INVTYPE.INVCOUNT` is the running ΑΑ,
  bumped after each insert → `invoice_types.invcount`, copied as-is, so **no fiscal-boundary
  cutover**. myDATA terms: `invoice_types.code` → series, `invoices.code` → ΑΑ. Increment
  under `lockForUpdate()` in a transaction (legacy serialised this via a trigger).
- **myDATA: `mydata_marks` is the source of truth.** Full `request`/`response` XML kept
  (legal audit). `invoices.mydata_*` are a denormalised cache of the latest state (mirrors
  the legacy `MARK_AI0` trigger).
- **Two orthogonal statuses.** `invoices.local_status` (business intent) vs `mydata_state`
  (the AADE truth) — never desynced; reconciled, not conflated.
- **Money is one service.** `App\Services\InvoiceBalance` is the single source for
  paid/credited/balance/status; cache columns are written only by it.
- **Domains → concrete types:** CURRENCY→decimal(14,2), QUANTITY→decimal(9,3),
  PERCENTAGE→decimal(5,2), BIG_NUMERIC→decimal(15,4), T_BOOLEAN→boolean, BLOB TEXT→text.

## Charset — the one thing that bites
The source DB is declared **WIN1253**. The ETL connects `charset=UTF8` so the Firebird
client transliterates on read (UTF8 is a superset → no transliteration errors). `clean()`
is a final safety net stripping stray invalid bytes. If your FB client lib misbehaves,
switch to `charset=NONE` + `iconv('Windows-1253','UTF-8//IGNORE',$v)` per string field.

## Run order
```bash
php artisan migrate
php artisan shield:generate          # (re)sync resource permissions after new resources land

# ETL — one tenant per legacy DB, re-runnable (needs pdo_firebird on the artisan host)
php artisan migrate:firebird --company="MyIP" --slug=myip \
    --fdb="/opt/Data/ekdosi-myip.fdb" --host=10.23.22.5 \
    --fbuser=EKDOSI --fbpass=ekdosi1234
php artisan invoices:recompute-balances --company=myip   # backfill money cache after import

# Operational helpers (also run on the wired scheduler — see INSTALL.md §11; safe to run manually)
php artisan whmcs:fetch-pending --tenant=myip            # stage paid+unfiled WHMCS invoices into the inbox
php artisan mydata:reconcile-sales --tenant=myip         # cross-check local invoices vs AADE
php artisan mydata:preflight --tenant=myip               # READ-ONLY config audit vs AADE code tables
```

**Deploy:** the scheduler is wired (`routes/console.php`, toggles in
`config/ekdosi.php`) but inert until the **OS cron** + a **queue worker** are
running — see **INSTALL.md §11**.

## Roadmap (suggested order)
1. ~~**Sandbox-verify Phase 2** against AADE dev creds~~ ✅ **done 2026-05-28** — reconciliation parser confirmed; the run also fixed the SendInvoices submit payload (PR #57). Remaining myDATA follow-ups: payment-method→type map, conditional per-line quantity for goods types, `taxesTotals` for withholding/fees invoices, a SendInvoices mock-Guzzle integration test.
2. ~~**Scheduler**~~ ✅ **done** — `whmcs:fetch-pending` + `mydata:reconcile-sales` + `mail-log:sweep-orphans` wired (`routes/console.php`); needs the OS cron + worker live (INSTALL.md §11). Unblocks griniaris routing.
3. **`mod_timologia` third-party (reseller) invoicing** — route specific services to the end customer instead of the reseller. Map + re-implementation spec: `docs/whmcs-legacy-plugin-map.md`.
4. **Confirm-then-build** the usage-dependent legacy features: stock movements, ΣΔΕΠ cumulative invoices, `invoiced=-333` — grep the production `.fbk` first.
5. **Auto-email on issue + audit BCC**; **gross-price-edit** on lines.
6. **Έξοδα / expenses** phase — inbound `RequestDocs`, suppliers, ΦΠΑ εκροών−εισροών.
7. **PDF template fidelity** (per-invoice-type designs) if the operator needs it.
8. **Estonian PEPPOL** submitter when the deadline forces it.

## Golden test (before trusting the import)
The legacy DB stores per-line/per-invoice results (`PRICE`, `PRICEWVAT`). After import,
recompute over the imported inputs and assert it reproduces the stored totals — this is
where VAT/rounding port bugs surface:
```sql
SELECT il.invoice_id, il.id, il.net_price, il.gross_price
FROM invoice_lines il
WHERE ABS(il.gross_price - ROUND(il.net_price * (1 + il.vat_percent/100), 2)) > 0.01;
```
