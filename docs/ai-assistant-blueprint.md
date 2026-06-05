# AI Assistant («Βοηθός») — feasibility + blueprint

> Status: **idea / future**. Nothing built. This is the design note the user asked
> for: how hard is an in-app chat assistant for operators, and how would it be
> tenant- and permission-safe. Read alongside `CLAUDE.md` (tenancy + service layer).

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

- **Default `claude-opus-4-8`** for quality. For a high-volume operator chat,
  `claude-sonnet-4-6` (cheaper, 1M ctx) or `claude-haiku-4-5` (cheapest) are sane
  per-tenant config knobs — but default to Opus unless cost forces otherwise.
- **Adaptive thinking** (`thinking: {type: "adaptive"}`) + `effort: "medium"` is a
  good balance for a Q&A/agent chat.
- **Prompt caching** the (frozen) system prompt + (deterministic) tool definitions
  → ~0.1× input cost on the stable prefix across turns. Keep volatile bits (the
  tenant name, today's date) *out* of the system prompt — inject them as a
  `role:"system"` mid-conversation message or in the user turn (else the cache
  invalidates every request).
- **Token-spend cap** per conversation via `max_tokens` + optionally Task Budgets;
  per-tenant monthly ceiling enforced in `AssistantRunner`.

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
