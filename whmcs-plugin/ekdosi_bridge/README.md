# Ekdosi-Bridge — WHMCS addon

PHP plugin that bridges WHMCS and the modern ekdosi backend (Laravel +
Filament). Replaces the legacy `prepare_for_ekdosi` plugin, but can
run side-by-side during the rollout so testing doesn't disturb the
live one.

## What it does

1. **Outbound push**: WHMCS admin sees a "Send to ekdosi for review"
   button on each invoice. Click → HMAC-signed POST to ekdosi's
   webhook → row appears in ekdosi's inbox.
2. **Status display**: same admin page can query ekdosi for the
   current state of an invoice (pending / filed with MARK X /
   rejected / held).
3. **Inbound write-back** (`inbound.php`): when ekdosi files an
   invoice at AADE, it POSTs the MARK (+ its ΤΠΥ `invcode`) back to this
   endpoint, which stores both in our own `mod_ekdosi_invoice_marks`
   table — keyed by WHMCS invoice id. We do **not** touch
   `tblinvoices.invoiced`. The admin badges show
   "Στο AADE · ΤΠΥ ΑΠΥ423 · ΜΑΡΚ …".
4. **Reset to unfiled**: drops our MARK row (rare cancel-at-AADE case).
5. **Legacy-invoiced flag** (`resolve.php` op `invoiced_flags`,
   read-only): serves the legacy `tblinvoices.invoiced` value for a
   batch of invoice ids so the ekdosi inbox can warn "already invoiced
   in the old app" during the dual-run (and offer a filter on it).
6. **Historical link (deterministic)** — the legacy auto-invoicer wrote
   the legacy ekdosi `INVOICE_ID` into `tblinvoices.invoiced`, and the
   ETL kept that same id as `invoices.legacy_id`. So
   `tblinvoices.invoiced === invoices.legacy_id` is an **exact key**
   (not heuristic). Two read-only surfaces use it:
   - both the **consolidated invoice list** («Τιμολόγια WHMCS → Ekdosi»)
     and the per-invoice admin page resolve a filed-in-legacy invoice to
     its **ΤΠΥ + ΜΑΡΚ** (`POST .../invoices-by-legacy-id` on the ekdosi
     side), lighting up the thousands of imported invoices with no
     re-import (badge «Στο AADE (legacy)»);
   - `resolve.php` op `legacy_invoice_links` pages `(whmcs_id, invoiced)`
     for ekdosi's `whmcs:backfill-invoice-ids`, which stamps
     `invoices.whmcs_invoice_id` so ekdosi knows each invoice's WHMCS
     origin too.

> **`tblinvoices.invoiced` is the legacy app's column — we never write
> it.** Earlier versions widened it to BIGINT to stuff the MARK in,
> which broke the legacy ekdosi app (it reads `invoiced` as a SMALLINT
> {0,1} flag). v0.14.0 fixes this: the MARK lives in our own table, and
> activation **restores** `invoiced` to SMALLINT. We only **read**
> `invoiced` now — to show "Invoiced in legacy app" during the dual-run.

7. **relid check / «Μηδενισμός relid»** (v0.21.0) — read-only per-line
   visibility on the admin invoice page: a badge «⚠ N γραμμές με relid»
   (red when some are **already renewed** = next due in the future) plus
   an «Έλεγχος relid» button. It opens a per-line table (description /
   type / linked domain·service / **next due** / relid) where the
   operator can **zero the relid** on the lines they pick — the safe,
   **audited** (WHMCS activity log) successor to the legacy
   `relid_remover`. Why: WHMCS re-runs renewal/activation for every line
   with `relid > 0` when an invoice is marked PAID; for a partner who
   renews domains by hand and pays one accumulated invoice later, that's
   a **double renewal**. Zeroing the relid before Mark Paid prevents it.
   Reads `tblinvoiceitems`/`tbldomains`/`tblhosting` only; never touches
   ekdosi/AADE. Deploy = upload the plugin folder (adds
   `lib/RelidInspector.php`); **no DB change, no reactivation needed**.

The plugin **never talks to AADE directly** — all AADE communication
goes through the ekdosi backend.

## Deployment

### 1. Upload

Copy this entire `ekdosi_bridge/` directory to the WHMCS server:

```
{whmcs_root}/modules/addons/ekdosi_bridge/
├── ekdosi_bridge.php
├── inbound.php
├── hooks.php
├── lib/
│   ├── EkdosiClient.php
│   ├── InvoiceMarkStore.php   (our AADE MARK table — mod_ekdosi_invoice_marks)
│   └── Admin/
│       ├── AdminDispatcher.php
│       └── Controller.php
└── README.md  (this file)
```

### 2. MARK storage + `invoiced` rollback (automatic on activation)

The 15-digit AADE MARK is kept in our own table
`mod_ekdosi_invoice_marks` ( `invoiceid` PK, `mark` VARCHAR ). We do
**not** use `tblinvoices.invoiced` — that is a SMALLINT flag the
**legacy ekdosi app** owns.

**Earlier versions (≤ 0.13) widened `invoiced` to BIGINT** to store the
MARK there. That broke the legacy app, which expects `invoiced` to be
SMALLINT. **v0.14.0 activation rolls that back automatically** — it
inspects `information_schema`, and if `invoiced` is `BIGINT` it:

```sql
-- ensure our table exists (no-op if it already does)
CREATE TABLE IF NOT EXISTS mod_ekdosi_invoice_marks (
  invoiceid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  mark VARCHAR(40) NOT NULL,
  invcode VARCHAR(60) NULL DEFAULT NULL,
  updated_at DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- move any MARK out of invoiced into our table
INSERT INTO mod_ekdosi_invoice_marks (invoiceid, mark, updated_at)
  SELECT id, invoiced, NOW() FROM tblinvoices WHERE invoiced > 65535
  ON DUPLICATE KEY UPDATE mark = VALUES(mark);
-- reset those rows to the legacy "filed" flag (SMALLINT-safe) + NULLs to 0
UPDATE tblinvoices SET invoiced = 1 WHERE invoiced > 65535;
UPDATE tblinvoices SET invoiced = 0 WHERE invoiced IS NULL;
-- restore the original type
ALTER TABLE tblinvoices MODIFY invoiced SMALLINT(5) NOT NULL DEFAULT 0;
```

If the WHMCS DB user lacks `ALTER` privilege (some managed hosts),
activation still succeeds but the message contains a
`WARNING: could not auto-restore ...` line carrying this same SQL
(table-create included, so it's self-contained) — hand it to your DBA.
Idempotent: on an already-SMALLINT column it's a no-op.

> **Upgrading from ≤ 0.13?** After replacing the files, **deactivate +
> reactivate** the addon once so the rollback runs (or run the SQL
> above). The legacy app works again the moment `invoiced` is SMALLINT.

### 3. Activate + configure

1. WHMCS admin → Addons → Manage Addons → activate "Ekdosi Bridge"
2. Grant access to your admin role
3. Addons → Ekdosi Bridge → fill in:
   - **Ekdosi base URL** — e.g. `https://ekdosi.example.com`
   - **Ekdosi tenant slug** — matches `companies.slug` on the ekdosi
     side (e.g. `myip`)
   - **Shared HMAC secret** — generate a random 32+ char string, paste
     the SAME value into ekdosi's `companies.whmcs_webhook_secret`
     for this tenant. The shared secret is bidirectional: ekdosi
     authenticates inbound writes with it, the bridge authenticates
     outbound pushes with it.

### 4. Verify

From the bridge admin page, paste a known invoice id → click
"Inspect". You should see:

- The ekdosi MARK (from `mod_ekdosi_invoice_marks`) + a read-only
  "Invoiced in legacy app" line (from `tblinvoices.invoiced`)
- A live "Ekdosi status" block (will say "no row yet" until you push)
- Three action buttons

Push a test invoice. Confirm:

- On the WHMCS side: green "Staged in ekdosi" alert with a pending id
- On the ekdosi side: a new row in the WHMCS Inbox with operator
  review pending

File it on the ekdosi side. The bridge will receive a POST to
`inbound.php`, store the MARK in `mod_ekdosi_invoice_marks`, and log
to WHMCS's activity log.

### 5. Coexistence with `prepare_for_ekdosi` / the legacy app

The legacy plugin/app can stay active alongside this one during the
dual-run — and it is now **safe**, because we never write
`tblinvoices.invoiced` anymore (the MARK lives in our own table). The
legacy side owns `invoiced`; we only read it. Safety nets:

1. The bridge's `inbound.php` refuses (409
   `already_filed_with_different_mark`) to overwrite an existing MARK
   in our table with a *different* one — only a fresh write or an
   idempotent repeat of the same MARK is allowed.
2. We never write `tblinvoices.invoiced`, so the legacy app's flag is
   never clobbered by the bridge.

There is no longer any rush to deactivate `prepare_for_ekdosi` — the
two no longer collide. Deactivate it only when you finish the cutover.

## Security model

- **Shared HMAC secret** on the ekdosi-side `companies.whmcs_webhook_secret`
  AND on this addon's config. Every request is HMAC-SHA256-signed:
  - **POST** (`invoice-paid` push, `inbound.php` write-back): sign the
    raw request body.
  - **GET** (`invoice-status`): sign the canonical string
    `"{slug}:{whmcs_invoice_id}"` (NOT the URL path). The canonical
    string is transport-independent, so a reverse proxy that rewrites
    the path or a slug needing URL-encoding can't break verification.
- Both sides verify with `hash_equals` (constant-time compare).
- The admin module's state-changing forms (push, reset) carry a WHMCS
  CSRF token (`generate_token`/`check_token`) so a logged-in admin
  can't be CSRF'd into mutating invoice state.
- The same secret authenticates BOTH directions. Rotate it on both
  sides at once.
- The bridge endpoints throttle at the HTTP layer (rate-limit on
  ekdosi side, no rate-limit on this side because WHMCS doesn't
  natively expose throttling for addon endpoints — the inbound
  endpoint is unauthenticated until the signature verifies, which
  is cheap to do).
- The webhook secret is stored encrypted on the ekdosi side
  (`Company::$casts['whmcs_webhook_secret'] = 'encrypted'`). On the
  WHMCS side it lives in `tbladdonmodules` as plain text — WHMCS
  doesn't have a native encrypted-config story for addon settings.
  If you need encryption on the WHMCS side too, the standard
  workaround is to read the secret from a file outside the document
  root rather than tbladdonmodules.

## Troubleshooting

**"Push failed: invalid_signature" / "Status query failed: invalid_signature"**

The HMAC secret doesn't match between WHMCS and ekdosi. Compare the
addon's `webhook_secret` setting against ekdosi's
`companies.whmcs_webhook_secret` for the matching tenant.

**"Push failed: tenant_not_found"**

The "Ekdosi tenant slug" doesn't match any `companies.slug` on the
ekdosi side. Check the slug spelling.

**"Push failed: whmcs_upstream_failure"**

Ekdosi tried to fetch the invoice from THIS WHMCS install (using its
own outbound WHMCS API credentials) and got auth or network failure.
Different from the HMAC secret — this is the API
identifier+secret pair on ekdosi's `companies.whmcs_api_identifier`
and `whmcs_api_secret`. Check those.

**`inbound.php` returns 500 "db_update_failed: ..."**

The MARK table couldn't be created/written — usually the WHMCS DB user
lacks `CREATE`/`INSERT` privilege. Create it manually:
`CREATE TABLE IF NOT EXISTS mod_ekdosi_invoice_marks (invoiceid BIGINT
UNSIGNED NOT NULL PRIMARY KEY, mark VARCHAR(40) NOT NULL, invcode
VARCHAR(60) NULL, updated_at DATETIME NULL);` (the addon's activation
does this — see step 2). `invcode` (the ekdosi ΤΠΥ) was added in
v0.15.0; activation `ALTER`s it onto a pre-existing table.

**`inbound.php` returns 409 "already_filed_with_different_mark"**

The invoice already carries a MARK different from the one ekdosi is
trying to write. This is the idempotent-write guard: it refuses to
silently overwrite an existing MARK (protects the WHMCS-side audit
trail and guards against double-filing). If the prior MARK is stale
(e.g. the invoice was cancelled at AADE and re-filed under a new
MARK), use "Reset to unfiled" on the bridge admin page first, then
re-file from ekdosi.

**Status block on the bridge admin page says "Bridge not configured"**

Module config is incomplete. Re-open the addon config and fill in
all three fields.

## Files

| File                                    | Purpose                                                |
|-----------------------------------------|--------------------------------------------------------|
| `ekdosi_bridge.php`                     | Addon entry point: config, activate, output           |
| `inbound.php`                           | Ekdosi → WHMCS write-back endpoint (HMAC-authenticated)|
| `hooks.php`                             | Per-invoice admin sidebar button hook                  |
| `lib/Admin/AdminDispatcher.php`         | Action router (mirrors prepare_for_ekdosi pattern)     |
| `lib/Admin/Controller.php`              | Admin module page actions (index/show/push/reset/sync) |
| `lib/EkdosiClient.php`                  | HMAC-signed HTTP client to ekdosi's webhooks          |
| `resolve.php`                           | Ekdosi → WHMCS read-only ops (HMAC): third-party resolution + `invoiced_flags` (legacy flag) |
| `lib/ThirdPartyStore.php`               | Own `mod_ekdosi_*` tables + sync/resolve/resellers + client CRUD |
| `lib/Client/Gate.php`                   | Hide/reveal gate for the v2 client page (switch + pilot allowlist) |
| `lib/Client/Controller.php`             | Client-area v2 page (contacts CRUD + per-service routing) |
| `templates/clientpage.tpl`              | Client-area page shell (renders the controller HTML)   |

## Third-party invoicing (Παραστατικά σε τρίτους — timologia v2)

The legacy `timologia` plugin lets a reseller route a service's invoice to a
third party (the end customer). That data lives in custom tables the WHMCS API
can't expose, so the bridge serves it to ekdosi over HMAC.

- **Own tables.** On activation the addon creates `mod_ekdosi_contacts` +
  `mod_ekdosi_routing` (it does NOT write the legacy `mod_timologia*`).
- **Sync.** Admin page → **"Sync from legacy timologia"** imports the legacy
  contacts + routing into the own tables. Re-runnable + idempotent (keyed on
  the legacy id; rows created on the v2 side are never touched; legacy tables
  are only READ). Re-run whenever the legacy data changes, until cutover.
- **Resolve.** `resolve.php` (read-only, HMAC) answers ekdosi's
  `op=resolve` (per-invoice line routing) and `op=resellers` (clients with
  routing) from the own tables. ekdosi then bills the third party instead of
  the reseller for single-party invoices, and parks multi-party invoices for an
  operator split.
- **ekdosi side is gated** by the per-tenant `whmcs_third_party_enabled` flag
  (default OFF) — so the endpoint can be deployed before any behaviour changes.

### Client-area v2 page (hideable)

The bridge adds a client-area page **"Παραστατικά σε τρίτους (v2)"** where a
reseller manages their contacts (alternate billing identities) and routes each
service to one — writing to the **own** `mod_ekdosi_*` tables (NOT the legacy
`mod_timologia*`). Two admin config knobs gate visibility:

- **Show client v2 page** (`show_client_v2`, default **OFF**) — master switch.
  While off, customers see nothing. Flip on for an off-hours test, then off again.
- **v2 pilot client IDs** (`v2_pilot_clients`) — optional comma-separated WHMCS
  client ids; when set, ONLY those clients see/use v2 (everyone else sees
  nothing even with the switch on).

The same gate (`Client\Gate::visibleTo`) guards both the navbar link AND the
page handler, so a hidden page can't be reached by URL-guessing. All CRUD is
scoped to the logged-in client id and CSRF-protected. The "(v2)" label keeps it
distinct from the legacy timologia link during the parallel run.

> **Write direction:** v2 writes the own tables only. A pilot client's edits in
> v2 are NOT mirrored back to the legacy `mod_timologia*` (the legacy plugin +
> desktop app keep their own copy). Manage a pilot client's routing in one
> place at a time.

## Not in scope (yet)

- **Multi-tenant on the WHMCS side**: one WHMCS install pushes to
  ONE ekdosi tenant. If you run separate WHMCS instances per ekdosi
  tenant (typical), each WHMCS install configures the bridge with
  ITS tenant's slug + secret. If you ever need ONE WHMCS pushing to
  MULTIPLE ekdosi tenants, the addon config schema needs per-route
  rules (deferred).
- **Bulk push**: per-invoice push only. Bulk action over a tblinvoices
  date range would be a follow-up addon page.
- **Cancel-at-AADE from WHMCS**: today operators cancel via the ekdosi
  Filament UI. A "Cancel ekdosi MARK" button could be added here later;
  for now, "Reset to unfiled" is the closest workflow (it drops our
  MARK row, doesn't touch AADE or the legacy `invoiced` flag).
