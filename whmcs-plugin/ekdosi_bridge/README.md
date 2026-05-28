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
   invoice at AADE, it POSTs the MARK back to this endpoint, which
   sets `tblinvoices.invoiced = <MARK>`. Replaces the legacy plugin's
   manual UI.
4. **Reset to unfiled**: legacy rollback workflow preserved
   (`tblinvoices.invoiced = 0`) for the rare cancel-at-AADE case.

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
│   └── Admin/
│       ├── AdminDispatcher.php
│       └── Controller.php
└── README.md  (this file)
```

### 2. Widen `tblinvoices.invoiced` (automatic on activation)

AADE MARKs are 15-digit positive integers. WHMCS's default
`tblinvoices.invoiced` column is `SMALLINT(5)` (max 65535) which
truncates real MARKs to garbage.

**The addon's activation hook runs this ALTER automatically** —
you don't normally need to do anything. On activation it inspects
`information_schema`, and if `invoiced` isn't already `BIGINT` it
runs:

```sql
ALTER TABLE tblinvoices MODIFY invoiced BIGINT NULL DEFAULT 0;
```

If the WHMCS DB user lacks `ALTER` privilege (some managed hosts),
activation still succeeds but the activation message will contain a
`WARNING: could not auto-widen ...` line with the exact SQL — hand
it to your DBA and run it before filing real MARKs. The ALTER is
idempotent and non-destructive (legacy `prepare_for_ekdosi` only
ever stored 0 or 1).

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

- `tblinvoices.invoiced` value rendered as a badge
- A live "Ekdosi status" block (will say "no row yet" until you push)
- Three action buttons

Push a test invoice. Confirm:

- On the WHMCS side: green "Staged in ekdosi" alert with a pending id
- On the ekdosi side: a new row in the WHMCS Inbox with operator
  review pending

File it on the ekdosi side. The bridge will receive a POST to
`inbound.php`, set `tblinvoices.invoiced = <MARK>`, and log to
WHMCS's activity log.

### 5. Coexistence with `prepare_for_ekdosi`

The legacy plugin can stay activated alongside this one during the
rollout — they use different module names and paths. **But both
write `tblinvoices.invoiced`**, so the activation hook will emit a
`WARNING: prepare_for_ekdosi is also active ...` if it detects the
legacy module. Two safety nets prevent silent clobbering:

1. The bridge's `inbound.php` refuses (409
   `already_filed_with_different_mark`) to overwrite an existing
   MARK with a *different* one — it only allows 0, the legacy `1`
   marker, or an idempotent repeat of the same MARK.
2. The activation warning reminds you to deactivate
   `prepare_for_ekdosi` once bridge-driven filings are verified
   end-to-end.

Once verified, deactivate `prepare_for_ekdosi` (Addons → Manage →
deactivate). Don't delete the directory immediately; if you need to
roll back, just reactivate.

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

**`inbound.php` returns 500 "db_update_failed: ... Out of range value for column 'invoiced'"**

`tblinvoices.invoiced` is still SMALLINT — the auto-widen at
activation didn't run (usually because the WHMCS DB user lacks
`ALTER`). Run it manually: `ALTER TABLE tblinvoices MODIFY invoiced
BIGINT NULL DEFAULT 0;` (see deployment step 2).

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
| `resolve.php`                           | Ekdosi → WHMCS read-only third-party resolution (HMAC) |
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
  for now, the legacy "Reset to unfiled" is the closest workflow
  (it flips invoiced=0 on the WHMCS side, doesn't touch AADE).
