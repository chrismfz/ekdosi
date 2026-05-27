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

### 2. Widen `tblinvoices.invoiced` if needed

AADE MARKs are 15-digit positive integers. WHMCS's default
`tblinvoices.invoiced` column is `SMALLINT(5)` (max 65535) which
truncates real MARKs to garbage. Run this BEFORE activating the
addon:

```sql
ALTER TABLE tblinvoices MODIFY invoiced BIGINT NULL DEFAULT 0;
```

If the table already holds legacy values from `prepare_for_ekdosi`
(0 or 1, never a MARK because the legacy plugin only flipped between
those two), the ALTER is non-destructive.

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

The legacy plugin can stay activated alongside this one — they use
different module names and different paths. Once you've verified
bridge-driven filings work end-to-end on a tenant, deactivate
`prepare_for_ekdosi` (Addons → Manage → deactivate). Don't delete
the directory immediately; if you need to roll back, just reactivate.

## Security model

- **Shared HMAC secret** on the ekdosi-side `companies.whmcs_webhook_secret`
  AND on this addon's config. ALL request bodies (or, for GET, the
  request path) are HMAC-SHA256-signed.
- Both sides verify with `hash_equals` (constant-time compare).
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

`tblinvoices.invoiced` is still SMALLINT. Run the ALTER TABLE from
step 2 of deployment.

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
| `lib/Admin/Controller.php`              | Admin module page actions (index/show/push/reset)     |
| `lib/EkdosiClient.php`                  | HMAC-signed HTTP client to ekdosi's webhooks          |

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
