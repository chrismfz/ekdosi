# ekdosi MCP server

One MCP endpoint (`POST /mcp`) that exposes the **same tenant-safe tool registry
as the in-app «Βοηθός»** to external MCP clients — Claude Desktop, the claude.ai
remote connector, another agent — so ekdosi is drivable from **outside** the
Filament panel, not just inside it.

This is a **second transport over one registry**, exactly as
`docs/ai-assistant-blueprint.md` (Phasing §4) planned: the in-app chat uses the
Anthropic Messages API tool-loop (`AssistantRunner`); this endpoint uses
`laravel/mcp`. Both call the **same** `App\Services\Assistant\ToolRegistry`, so a
capability is written **once** (an `AssistantTool`) and works on both channels.

Modelled on cfm-web's MCP gateway (`cfm-web/MCP.md`) — same package, same
universal-auth shape — adapted for ekdosi's two differences: **multi-tenancy**
(the token binds the company) and **write tools** (propose-only externally).

---

## 1. As-built

```
MCP client ──Bearer <ekdosi Sanctum token, tenant-bound>──▶  ekdosi  /mcp  (EkdosiMcpServer)
                                                                │
   business tools (tenant-scoped) ─────────────────────────────┤  AssistantMcpTool → ToolRegistry
     count_sales · recent_invoices · vat_summary               │    → Gate::can(perm) for the token's USER
     outstanding_receivables · list_top_debtors · find_customer│    → CompanyContext::actAs(token's COMPANY)
     recent_activity · app_version                             │    → the SAME AssistantTool the panel runs
     send_customer_statement · create_reminder  (PROPOSE-ONLY) ┘
                                                                │
   ops / debug tools (super_admin, cross-tenant) ──────────────┤  native MCP tools
     app_health (ops:health) · failed_jobs · log_tail          ┘
```

- **Endpoint:** `POST /mcp`, in `routes/ai.php` (Laravel MCP auto-loads it — no
  `bootstrap/app.php` change). Always-on, protected by `auth:sanctum` (+ `auth:api`
  once Passport is installed): a request without a valid token is rejected, and the
  route can't mount without `laravel/mcp` installed — so there is no enable flag.
- **Server:** `App\Mcp\Servers\EkdosiMcpServer` (`laravel/mcp`).
- **Adapter:** `App\Mcp\Tools\Concerns\AssistantMcpTool` — one thin base that
  makes any `AssistantTool` an MCP tool. Its `name()`/`description()`/`schema()`
  come from the wrapped tool (no duplication; `McpSchema` converts the raw
  input-schema to Laravel MCP's builder), `shouldRegister()` offers the tool only
  if the token's user holds its Shield permission, and `handle()` runs it through
  `ToolRegistry` (permission + `CompanyContext::actAs`) — the identical harness to
  the in-app chat. A concrete wrapper (e.g. `CountSalesMcpTool`) is a 3-line
  pointer at which `AssistantTool` it wraps.

### Why a bare `tools/call` works with no handshake

Laravel MCP's streamable handler seeds a default initialized session per request
in stateless JSON mode, so a single `tools/call`/`tools/list` POST is accepted
without an `initialize` round-trip. The POST must send `Content-Type:
application/json` and an `Accept` header listing **both** `application/json` and
`text/event-stream`. (Standard MCP clients do this for you.)

---

## 2. Tenancy — the company is named explicitly, but validated server-side

cfm-web is single-tenant; ekdosi is multi-tenant, so the cardinal rule of
`docs/ai-assistant-blueprint.md` holds on this channel too — **a company is never
trusted from free text the model invents.** But the MCP channel has no session
tenant (unlike the panel), so the target must be *selected*. This is the cfm
`node`/`node="all"` model applied to companies (`App\Mcp\Support\McpTenantResolver`):

- **`company` (slug)** on the tenant-scoped tools → that ONE company.
- **`company: "all"`** → fan out over every company the caller may access; the
  adapter runs the tool per company and returns a **per-company map**
  (`{myip: {...}, nexon: {...}}`) — no merge, no collision.
- **omitted** → a tenant-bound Sanctum token's company; else the caller's sole
  company; else refuse and list the options. `list_companies` reports the valid
  slugs.

Every selection is validated: a **member** reaches only their own companies; a
**system super_admin** reaches every tenant (exactly what the panel's tenant
switcher already gives them — no more). "nexon" from a member of only "myip" is
refused, not leaked. So a cross-tenant read stays **impossible**, and it's
enforced by server-side validation, not by the absence of a parameter.

- `php artisan ekdosi:mcp-token <email> --tenant=<slug>` mints a **Sanctum token
  LOCKED to one company** (a `tenant:{id}` ability) — it can neither fan out nor be
  pointed elsewhere; the binding *is* the scope. Use these for desktop/CLI.
- The **claude.ai/OAuth** connection is unbound, so `company`/`all` drive it —
  which is how a super_admin reaches any/all companies over that connector.
- **Writes never fan out.** `send_customer_statement` / `create_reminder` refuse
  `company: "all"` (blast-radius) and require a specific company; they stay
  propose-only regardless (mirrors cfm's node="all" write guard).
- The in-app «Βοηθός» is unchanged: it keeps the session tenant and has **no**
  `company` parameter — the selector is MCP-only.

---

## 3. Security model

- The MCP client authenticates with **ekdosi's own** credential (a Sanctum token,
  or an OAuth access token) — never anything downstream.
- **Per-user permission still applies.** Every tool is offered/executed only if
  the token's user holds its Shield permission (`ToolRegistry::userMay`) — the
  assistant's capability is the intersection of "what tools exist" and "what this
  user may do", same as the panel. `super_admin` bypasses via `Gate::before`.
- **Writes are propose-only.** `send_customer_statement` / `create_reminder` do
  **not** act over MCP: they stage an `AiPendingAction` and return its id; the
  email/reminder fires only after an **operator confirms it inside the ekdosi
  panel**. Legally-significant / outward-facing actions stay human-in-the-loop
  cross-channel (mirrors the WHMCS-inbox philosophy). There is no external
  auto-confirm.
- **Ops/debug tools are super_admin-only** (`app_health`, `failed_jobs`,
  `log_tail`). They read cross-tenant infrastructure and their output (log lines,
  exception traces) can carry business data and is egressed to the connected MCP
  client — so they are gated to a system super_admin and bounded in size. The
  caller is an operator who could already read these on the box.
- **Audit:** tool calls run through the same services the panel audits.

---

## 4. Tool catalogue

**Business (tenant-scoped, Shield-gated, offered only if the user may):**

| Tool | Does | Gate | R/W |
|---|---|---|---|
| `count_sales` | invoice count + turnover over a window | `View:Invoice` | R |
| `recent_invoices` | latest invoices (+ view links) | `View:Invoice` | R |
| `vat_summary` | output-VAT per rate for a period | `View:Invoice` | R |
| `outstanding_receivables` | total owed | `View:Customer` | R |
| `list_top_debtors` | top debtors (+ Καρτέλα links) | `View:Customer` | R |
| `find_customer` | search by name/ΑΦΜ (+ links) | `View:Customer` | R |
| `recent_activity` | the audit trail (who changed what) | `View:ActivityFeed` | R |
| `app_version` | deployed build + update-available | — | R |
| `send_customer_statement` | **propose** emailing a Καρτέλα | `View:Customer` | **W→propose** |
| `create_reminder` | **propose** a reminder | — | **W→propose** |

**Ops / debug (super_admin, cross-tenant infra, read-only) — for debugging the
deployment from here:**

| Tool | Does |
|---|---|
| `app_health` | deploy health: queue worker + pending/failed jobs, scheduler/cron per-task status + run history, backups, mail, WHMCS, myDATA, disk, distilled `severity` (same data as `php artisan ops:health`). Start here. |
| `failed_jobs` | recent failed queue jobs with the head of each exception — "why did the background job / mail / WHMCS / myDATA submit fail?" |
| `log_tail` | tail `storage/logs/laravel*.log` (optional `level` / `contains` filters) for the actual error text |

---

## 5. Install (deploy host)

```bash
composer install                                # composer.lock already carries mcp + sanctum
php artisan migrate --force                     # creates personal_access_tokens (migration ships in repo)
php artisan config:clear                         # (or optimize) if config is cached
php artisan queue:restart
```

The endpoint is always-on once the package is installed — no env flag to set.
Access still requires a minted token (§6), so mounting it is harmless.

`routes/ai.php`, `EkdosiMcpServer`, the tools, `McpTenantResolver`, the
`ekdosi:mcp-token` command, `HasApiTokens` on `User`, **and the
`personal_access_tokens` migration** all ship in this repo — a plain
`composer install` + `migrate` is enough (no `install:api` needed). All MCP code
is `class_exists`-gated, so the app boots fine even before the packages are
installed (the endpoint simply isn't mounted).

`composer.json` pins `laravel/mcp: ^0.9.3` and `laravel/sanctum: ^4.0`; the lock
already carries them (`laravel/mcp` v0.9.4, `laravel/sanctum` v4.3.3).

> **Note — the `laravel/boost` bump.** ekdosi already had `laravel/mcp` **v0.8.2**
> as a *transitive dev* dependency of `laravel/boost` (the `boost:mcp` dev tool).
> v0.8.2 lacks the request-aware `shouldRegister()` and OAuth `oauthRoutes()` this
> server uses, and older boost capped `laravel/mcp` at `^0.8`. So the lock also
> bumps **`laravel/boost` v2.4.12 → v2.6.0** (dev-only; it now allows
> `laravel/mcp ^0.9.0`) and its dep `laravel/roster` → v1.0.0. No production runtime
> deps beyond `laravel/mcp` + `laravel/sanctum` changed.

---

## 6. Mint a client token (tenant-bound)

```bash
php artisan ekdosi:mcp-token you@example.com --tenant=myip           # prints the bearer ONCE
php artisan ekdosi:mcp-token you@example.com --tenant=myip --name ci # a second, labelled token
php artisan ekdosi:mcp-token you@example.com --tenant=myip --revoke --name ci
```

`--tenant` may be a slug or id; it is optional only when the user belongs to
exactly one company. Give the printed value to your MCP client as
`Authorization: Bearer <token>`.

---

## 7. Test

```bash
php artisan test --filter=Mcp                    # feature tests (needs the packages installed)

# Interactive, against the live endpoint:
php artisan mcp:inspector mcp
#   → add header  Authorization: Bearer <token from §6>
#   → call app_version; count_sales {}; then (super_admin) app_health {}, failed_jobs {limit: 5}
```

---

## 8. OAuth 2.1 (Passport) — for the claude.ai remote connector

The `/mcp` endpoint is **universal**: Sanctum bearer (always on — desktop / CLI /
curl) **or** an OAuth 2.1 access token. The OAuth path exists for the **claude.ai
remote connector**, which only speaks OAuth 2.1 + Dynamic Client Registration and
can't take a static bearer.

The repo already carries the wiring, all **Passport-gated by `class_exists`** so
nothing breaks before the package is installed:

- `routes/ai.php` — `Mcp::oauthRoutes()` + guard `auth:api,sanctum`, only once
  Passport is present; Sanctum-only until then.
- `config/auth.php` — an `api` guard (`driver: passport`), inert until the driver
  is registered.
- `AppServiceProvider::boot()` — registers the consent view (`mcp.authorize`,
  a **self-contained** blade — no Vite/Tailwind, per the no-build-CSS rule).

### Install (deploy host)

```bash
composer require laravel/passport
php artisan install:api --passport      # generates RSA keys + migrations + a client
php artisan migrate --force
./... config:clear + queue:restart
```

`install:api --passport` writes OAuth keys under `storage/` — secrets, gitignored;
never commit them. On multi-node, share the same keypair
(`PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY` or a shared `storage/`).

### Connect claude.ai

Add a custom connector at `https://<ekdosi-host>/mcp`. claude.ai discovers OAuth
from the `401 WWW-Authenticate … resource_metadata` response, registers itself
(DCR), and sends the operator to the consent screen — approve while logged into
ekdosi.

### Two things to confirm live (Passport is version-sensitive; not exercisable in CI here)

1. **`User` may need `Laravel\Passport\Contracts\OAuthenticatable`.** Recent
   Passport wants the authenticatable to implement it. We did **not** add it (nor
   Passport's `HasApiTokens`, which collides with Sanctum's) — add the interface
   only if the installed version requires it. The OAuth *token* path needs no
   trait on `User`.
2. **Discovery header.** Confirm an unauthenticated `/mcp` still returns the
   RFC 9728 `WWW-Authenticate` pointer claude.ai follows. A multi-company OAuth
   user has no `tenant:{id}` binding, so they select per call with `company` /
   `company: "all"` (§2) — `list_companies` shows the slugs.

---

## 9. Roadmap

- **Per-company output cap on fan-out.** `company: "all"` runs the tool per
  company; the tools already return bounded data, but a hard per-company byte cap
  (like cfm's 64 KiB/node) would harden it if the fleet grows past a few tenants.
- **A consent-screen company chooser** (optional): let the OAuth approval pin a
  default company into the session, so a multi-company operator needn't pass
  `company` each call. `company`/`all` already cover the functional need.
- **More read tools on the same rail.** `connection_health` (WHMCS bridge
  freshness + `mydata:preflight`), a myDATA reconcile summary — each is one
  `AssistantTool`, both channels.
- **When a real WRITE tool ships externally** (beyond propose-only): keep it off
  the OAuth path unless explicitly granted; consider Sanctum abilities /
  per-tool scopes. Today the only writes are propose-only + operator-confirmed.
