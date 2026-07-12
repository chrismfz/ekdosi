# AI Assistant («Βοηθός») — feasibility + blueprint

> Status: **Phase 1 SHIPPED** (was «idea / future» when written). The in-app «Βοηθός»
> is live — `App\Filament\Pages\Assistant` + `AssistantRunner` + a tool registry incl.
> write actions (with confirm), usage metering (`AiUsageLog`/`AiUsageMeter`); see
> `FEATURES.md §16β`. The body below is the ORIGINAL design note (kept as the rationale
> record); read it as «why it's shaped this way», not «what's left». Read alongside
> `CLAUDE.md` (tenancy + service layer).

## Decision (locked)

- **ONE global Anthropic key**, set by a `super_admin` (env / a single Setup field
  — not per company).
- **Isolation** is enforced by the tool layer (`Gate` + `CompanyContext::actAs`),
  NOT by the key — a per-company key adds nothing for security.
- **Billing/limits are per company**: `ai_usage_log` meters tokens stamped with
  `company_id`; `companies.ai_monthly_token_cap` stops a tenant overdoing it.
- Per-company `ai_api_key` stays a **nullable escape hatch** only (a tenant that
  contractually wants its own Anthropic account); default is the global key.

### Cap behaviour («για να μην το παρακάνουν»)

Enforced in `AssistantRunner`, summing the month's `ai_usage_log` for the tenant
*before* each call:

- **Soft warn** at ~80% of `ai_monthly_token_cap` — a banner in the chat
  («πλησιάζετε το μηνιαίο όριο»), assistant keeps working.
- **Hard stop** at 100% — refuse politely («εξαντλήθηκε το μηνιαίο όριο AI για τον
  μήνα· επικοινωνήστε με τον διαχειριστή»); a `super_admin` can raise the cap.
- **Global backstop cap** across all tenants (config) — a runaway-loop safety net
  independent of any single tenant's cap.
- Per-user sub-limits are a later refinement (the log already carries `user_id`).

## TL;DR — how hard?

**Moderate, and most of the hard part is already done.** The genuinely difficult
problem in a "chat that can touch the books" — making every data read and every
action **tenant-scoped and permission-checked** — is *already solved* in ekdosi:

- **Tenant isolation** → `App\Support\Tenancy\CompanyContext::actAs($company, fn …)`
  + the `CompanyScope` global scope on all 22 tenant-owned models.
- **Per-user access** → `spatie/laravel-permission` + Shield policies, reached via
  `Gate::can('View:Invoice', …)` (the exact pattern the 8 ex-`auth()->check()`
  screens already use — clean deny, never a throw).
- **A rich, vetted service layer** → `InvoiceBalance`, `SalesReconciler`,
  `RecomputeInvoiceTotals`, `WhmcsInvoiceFiler`, `BridgeLogStore`,
  `spatie/laravel-backup`, etc. These are exactly the "tools" an assistant calls.

So the assistant is **a thin tool-calling loop over services that already exist**,
not a new data layer. The new code is: a Filament chat page, a tool registry, and
the Claude API loop. Estimate: **~1 week** for read-only Q&A (Phase 1), another
week to add guarded actions (Phases 2–3).

## "MCP-Connector" — clarifying what you actually want

Two different things get called "MCP":

1. **An in-app chat window** inside the Filament panel that answers questions and
   (optionally) performs actions. The model calls **tools** = your PHP functions.
   This is what your examples describe ("σύγκρινε έσοδα/έξοδα", "κόψε το παραστατικό",
   "πότε πήραμε backup"). **This is the recommendation.** It does NOT need MCP.
2. **An MCP server** exposing ekdosi to an *external* MCP client (Claude Desktop,
   the Claude app, another agent). Useful later if you want to drive ekdosi from
   outside, but it's a second delivery channel, not the chat window.

The good news: **both share the same core** — a registry of tenant-safe,
permission-checked tools. Build the tool registry once; expose it (a) to the
in-app chat via the Messages API tool-use loop, and (b) later via a thin MCP
server (`POST /mcp`) if you ever want #2. Start with #1.

### «Πρέπει πρώτα να φτιάξω agent;» — όχι, για το #1

What you saw on **platform.claude / the Anthropic Console "Agents"** (the templates
— *Blank agent config*, *Deep researcher*, *Structured extractor*, and the
connector chips: notion / slack / sentry / linear / github…) is a **third,
different delivery model**: an **Anthropic-hosted agent** that you configure in
their UI and which reaches *into your systems through MCP connectors*. That is the
hosted flavour of #2 (an external client driving ekdosi) — **not** what Phase 1
needs.

For the **in-app chat (#1, the recommendation)** you do **not** create any agent in
the Console. You need only:
1. an **API key** (Console → API keys), billed to the global Anthropic account
   (see «Credentials topology» — one global key + per-tenant metering),
2. the **Messages API tool-use loop** in `AssistantRunner` (PHP), where *our* tool
   registry is the "agent". ekdosi IS the harness; the Console agent-builder is a
   competing harness we don't use.

So those templates/connectors are a useful **mental model + a later option** (if a
tenant wants to drive ekdosi from the Claude app via our MCP server), but they are
**not a prerequisite** and add a hosted dependency we don't want for the operator
chat. Skip them for Phase 1.

## Architecture (in-app chat, Phase 1)

```
Filament panel
  └─ Pages/Assistant.php  (Livewire chat UI, tenant-bound like every page)
        │ user message + conversation history
        ▼
  App\Services\Assistant\AssistantRunner
        │  Claude Messages API tool-use loop (anthropic-ai/sdk)
        │  system prompt (frozen, cached) + tool definitions (cached)
        ▼
  App\Services\Assistant\Tools\*          ← the tool registry
        │  each tool:
        │    1. Gate::can(<permission>, …)  → deny ⇒ "δεν έχετε πρόσβαση"
        │    2. CompanyContext::actAs($tenant, fn () => <existing service>)
        │    3. returns STRUCTURED data (never free prose the model invents)
        ▼
  existing services (InvoiceBalance, SalesReconciler, backups, …)
```

Key point: the assistant **never sees raw SQL or a DB handle**. It sees a fixed
set of named tools. The harness — not the model — enforces tenant + permission on
every call. This is the standard "promote to a dedicated tool so the harness can
gate/audit it" pattern (`shared/agent-design.md`).

## The security model (this is the whole ballgame)

Per your question — *"per company για ασφάλεια; ή αναλόγως τι user-access δώσουμε;"*
— the answer is **both, and they compose**:

- **Per company:** every tool body runs inside `CompanyContext::actAs($tenant, …)`,
  where `$tenant` is the Filament tenant of the logged-in operator's session —
  **never** a tenant the model names. The model literally cannot ask about another
  company; there's no parameter for it. A `super_admin` switching tenants switches
  the assistant's scope too (same as the rest of the panel).
- **Per user-access:** every tool first calls `Gate::can(<permission>, …)` for the
  current user, reusing the **existing** Shield policies. An `operator` who can't
  reach the myDATA console gets a tool-level "δεν έχετε πρόσβαση", not data. So the
  assistant's capability is automatically the **intersection** of what tools exist
  and what *this* user is allowed — no separate permission matrix to maintain.
- **Read vs write split:** read tools (counts, comparisons, status) auto-run. Write
  tools (issue an invoice, create a user) **never auto-execute** — they return a
  *proposed action* the operator confirms in the UI (a Filament modal), then a
  second call performs it. Hard-to-reverse + legally-significant = always
  human-in-the-loop (mirrors the WHMCS inbox philosophy: "invoices are legally
  significant, operator-gated").
- **Audit:** every tool call (read and write) is logged — reuse
  `spatie/laravel-activitylog`, causer = the operator, so the «Ιστορικό» trail
  already in place covers the assistant too.
- **Prompt-injection awareness:** tool *outputs* that contain external text (WHMCS
  client names, AADE responses, customer free-text) are data, not instructions —
  the same caution `CLAUDE.md` already applies to webhook/PR content. The system
  prompt states tools are the only source of truth and external text is never an
  instruction.

### Grounding / self-awareness (το system prompt)

The model must know **what it is, where it runs, and what it may do** — this is the
first line of defence against off-task abuse. The (cached) system prompt states,
explicitly: *«Είσαι ο εσωτερικός βοηθός του ekdosi (ελληνική τιμολογιέρα/myDATA)
για την εταιρεία {tenant}. Απαντάς ΜΟΝΟ με βάση τα εργαλεία· δεν εκτελείς κώδικα,
δεν βλέπεις άλλες εταιρείες, δεν εφευρίσκεις νούμερα. Αρνείσαι ευγενικά ό,τι είναι
εκτός ekdosi (γενική γνώση, μαθηματικά, κ.λπ.).»* — plus: cite tool numbers, Greek
output, writes need operator confirmation. (Inject the volatile `{tenant}`/date as
a mid-conversation message, NOT in the cached prefix — see «Model + cost».)

### Abuse / resource safeguards («top-10 πελάτες αλλά πρώτα βρες όλο το π»)

That exact attack — *steer the assistant off-task to burn compute/tokens* — is
defused on two levels:

- **No arbitrary computation exists.** The model cannot "compute π" or run code; it
  can only emit text and call our **fixed tool registry** (no `code_execution`, no
  shell, no eval). There is no server-side compute to exhaust — the only finite
  resource at risk is **API tokens (= €)**, which the caps below bound. The system
  prompt's off-task refusal makes the model decline it outright.
- **Per-request hard limits** (in `AssistantRunner`, every call): `max_tokens` cap ·
  a **tool-loop iteration cap** (e.g. ≤ 8 tool round-trips/turn → no runaway loop) ·
  a wall-clock **timeout** · a **conversation-history cap** (truncate/summarise old
  turns; reject oversized pastes). A request that hits a limit ends with a polite
  «δεν μπόρεσα να ολοκληρώσω», never an unbounded spend.
- **Per-tenant + per-user rate limit** (requests/min, Laravel `RateLimiter`) — stops
  rapid-fire scripted abuse independently of the monthly token cap.
- **The monthly token caps** (soft-warn 80% / hard-stop 100% / global backstop) from
  «Cap behaviour» above are the cost ceiling; the per-request + rate limits are the
  per-incident ceiling. The two compose.
- **Everything audited** (`activitylog`, causer = operator) → an operator probing
  for abuse is visible in the «Ιστορικό», same as any other action.

Net: the worst a malicious prompt achieves is *one capped, rate-limited, audited
request that the model likely refuses anyway* — annoying, not dangerous, and it
counts against that user's own tenant budget.

## Tool catalogue — mapped to your example questions

| Operator asks… | Tool | Backed by | Gate | R/W |
|---|---|---|---|---|
| «σύγκρινε έσοδα/έξοδα 2-3 χρόνια» | `compare_income_expense(period)` | `VatPeriodReport` / dashboard KPIs | `View:Reports` | R |
| «πόσες πωλήσεις το X διάστημα» | `count_sales(from,to,type?)` | `Invoice` + `InvoiceScope::live` | `View:Invoice` | R |
| «πόσα ακυρωτικά / πιστωτικά» | `count_credit_notes(from,to)` | `Invoice` (credit types) | `View:Invoice` | R |
| «διασυνδέσεις δουλεύουν;» | `connection_health()` | `BridgeLogStore` freshness + `mydata:preflight` | `View:MyDataConsole` | R |
| «πότε πήραμε backup τελευταία» | `last_backup()` | `spatie/laravel-backup` status | admin-only | R |
| «ξέχασα να κόψω από το WHMCS inbox» | `list_pending_whmcs(filter)` | `PendingWhmcsInvoice` | `View:PendingWhmcsInvoice` | R |
| «κόψε το και στείλ' το» | `propose_file_whmcs_invoice(id)` → confirm → `file` | `WhmcsInvoiceFiler::createDraft` + lifecycle | `Update:PendingWhmcsInvoice` | **W** |
| «φτιάξε χρήστη» | `propose_create_user(...)` → confirm | User + `TenantRoleProvisioner` | `super_admin` only | **W** |

Each tool's `description` states **when** to call it (prescriptive descriptions
give measurable lift on Opus 4.8). Read tools return JSON the model summarises in
Greek; it must cite the numbers the tool returned, not invent them.

## Model + cost

- **Candidate model — recommend `claude-sonnet-4-6` as the DEFAULT** for this
  workload. An operator Q&A over a *fixed tool registry* is exactly agentic
  tool-use: Sonnet 4.6 picks/sequences tools strongly, reasons well over the
  returned JSON, has 1M ctx, and costs a fraction of Opus with better latency for a
  chat. Tiering (per-tenant config knob `ai_model`):
  - **`claude-haiku-4-5`** — cheapest/fastest· fine for simple read lookups («πότε
    backup», «πόσες πωλήσεις») or as a cheap **router**· may fumble multi-step
    analysis.
  - **`claude-sonnet-4-6`** — **the default**· the sweet spot for tool-use + Greek
    nuance + multi-year comparisons.
  - **`claude-opus-4-8`** — reserve for genuinely hard reasoning/analysis where a
    tenant accepts the cost· overkill (and slower) for routine operator chat.
- **Adaptive thinking** (`thinking: {type: "adaptive"}`) + `effort: "medium"` is a
  good balance for a Q&A/agent chat.
- **Prompt caching** the (frozen) system prompt + (deterministic) tool definitions
  → ~0.1× input cost on the stable prefix across turns. Keep volatile bits (the
  tenant name, today's date) *out* of the system prompt — inject them as a
  `role:"system"` mid-conversation message or in the user turn (else the cache
  invalidates every request).
- **Token-spend cap** per conversation via `max_tokens` + optionally Task Budgets;
  per-tenant monthly ceiling enforced in `AssistantRunner`.

## Credentials topology & why isolation is NOT the API key

**The single most important point: the leak question has nothing to do with the
Anthropic API key.** The key is *transport + billing* — which Anthropic account
pays for the call. It knows nothing about ekdosi companies. Data isolation is
enforced one layer down, in the tool harness, **independently of the key**.

Walk the worry through — `operator@nexon` asks *«ποια τα έσοδα της myip;»*:

1. The chat page is tenant-bound like every Filament page: `AssistantRunner` reads
   the tenant from the **session** (`Filament::getTenant()` = nexon), **never** from
   anything the user types.
2. The model may decide to call `compare_income_expense` / `count_sales`. **None of
   these tools has a `company` parameter** — they operate on the *ambient* tenant
   only, via `CompanyContext::actAs($sessionTenant, …)`. "myip" in the sentence
   maps to no argument; it's just text.
3. So the tool physically returns **nexon's** numbers, or — per the system rule
   below — the model answers *«βλέπω μόνο την τρέχουσα εταιρεία (nexon)· δεν έχω
   πρόσβαση στα δεδομένα της myip.»*

The cross-tenant read is **structurally impossible**, not policy-dependent: there
is no code path, with or without a per-company key, that lets a nexon-bound session
read myip rows. The company is server-side ambient state, not a model-controllable
input. (Same guarantee for a `super_admin`: the assistant's scope is *whichever
tenant they've switched the panel to* — start a fresh conversation on tenant
switch so prior context doesn't carry over.)

A per-company API key would **not** add isolation, because each request's payload
already contains only the bound tenant's data (the tools never return anyone else's).
nexon's request carries nexon's data regardless of which key signs it.

### So which topology? — recommend **one global key + per-tenant metering**

The reason you'd reach for per-company keys is **billing attribution** — but you
get that *better* from **token metering**, without the per-key ops burden. Every
Messages API response carries a `usage` block (`input_tokens`, `output_tokens`,
`cache_read_input_tokens`, `cache_creation_input_tokens`). Log it stamped with
`company_id` after every call, `SUM` per tenant at month-end → you know exactly who
to bill. One global key, full per-tenant attribution, and you keep cross-tenant
prompt-cache sharing (cheaper than isolated per-key caches).

```
ai_usage_log
  company_id, user_id?, conversation_id?, model,
  input_tokens, output_tokens, cache_read_tokens, cache_write_tokens,
  cost_estimate, created_at
```

`AssistantRunner` writes one row per API turn from `$response->usage`, computing
`cost_estimate` from a per-model price map in `config/ekdosi.php` (e.g. Opus 4.8
$5/$25 per 1M in/out; cache read ~0.1×, cache write ~1.25×). Token counts are
authoritative (straight from the response); the **cost is your own calculation** —
reconcile the monthly sum against the single Anthropic invoice as a sanity check.

Why metering beats per-company keys:

- **One key** to manage/rotate, not N.
- **Cross-tenant prompt caching** stays shared (lower total cost).
- **Finer granularity** — per *user*, per *conversation*, per *tool*, not just per
  company. ("Who burned the budget?" → a name, not just a tenant.)
- **In-app caps** — enforce `companies.ai_monthly_token_cap` by summing the log
  *before* each call and refusing politely when over; no dependency on Anthropic's
  per-key console totals.

| Option | Verdict |
|---|---|
| **One global key + `ai_usage_log` metering** (recommended) | Simplest ops, shared cache, *and* exact per-tenant (per-user) billing. |
| **A key per company** | Per-key billing totals, but no isolation benefit, more ops, lost cache sharing, coarser than metering. Keep only for a tenant that contractually wants its own Anthropic account/DPA. |

Keep the per-tenant **config** either way:

- `companies.ai_assistant_enabled` (default off) — kill-switch per tenant.
- `companies.ai_model` (nullable → global default `claude-opus-4-8`).
- `companies.ai_monthly_token_cap` (enforced from `ai_usage_log` in `AssistantRunner`).
- `companies.ai_api_key` (**nullable**) — escape hatch for the billing/compliance
  case only; falls back to the global env key. Resolver mirrors
  `EInvoiceSubmitterFactory` / per-tenant WHMCS creds.

Bottom line: **one global key, isolation by the tool layer, billing by per-tenant
token metering, per-tenant config for model/cap/on-off**, and a nullable per-tenant
key only for the rare own-account tenant.

## PHP specifics

- SDK: `composer require anthropic-ai/sdk` (official; supports `BetaRunnableTool` +
  `toolRunner()`). Key from `config/services.php` (env, never committed).
- **Use the manual tool-use loop**, not the auto tool-runner, so write tools can
  pause for operator confirmation (the runner executes everything automatically —
  fine for read-only, wrong for "issue this invoice"). Read tools can run inline;
  any `propose_*` tool returns a confirmation payload and breaks the loop.
- Network: the deploy host needs outbound HTTPS to `api.anthropic.com` — note this
  against the environment's network policy (the same place WHMCS/AADE egress is
  configured).

## Phasing (de-risked)

1. **Phase 1 — read-only Βοηθός (low risk, ~1wk).** Chat page + ~6 read tools
   (sales/credit counts, income-vs-expense, connection health, last backup, inbox
   list). No writes. Ship it; it's already genuinely useful and can't damage data.
2. **Phase 2 — guarded actions (~1wk).** `propose_* → confirm → execute` for the
   WHMCS-inbox "κόψε & στείλε" and similar, reusing `WhmcsInvoiceFiler` + the
   normal lifecycle (all existing guards intact).
3. **Phase 3 — admin actions.** Create user / assign role (super_admin only),
   behind confirmation.
4. **Later / optional — MCP server.** If you want ekdosi drivable from the Claude
   app or another agent, wrap the *same* tool registry in an MCP endpoint. No new
   data logic — just a second transport.

## Risks & honest caveats

- **Hallucinated figures** — mitigated by tools returning structured data + a
  "cite the tool's numbers, never compute in your head" system rule; for money
  questions, the tool does the arithmetic (`InvoiceBalance`), not the model.
- **Cost drift** — per-tenant model choice + token caps + caching.
- **Scope creep on write tools** — keep the `propose→confirm` gate strict; a write
  tool that "just does it" is the thing to never ship.
- **It's an assistant, not a system of record** — every number it shows must be
  traceable to a tool call that the operator could have run by hand. If it can't be
  grounded in a tool, it doesn't get answered.

## Smallest next step

Phase 1, three tools only (`count_sales`, `last_backup`, `connection_health`),
behind a Shield-gated «Βοηθός» page visible to admins. Proves the
`Gate → CompanyContext::actAs → service → structured result` spine end-to-end with
zero write risk; everything else is more tools on the same rail.
