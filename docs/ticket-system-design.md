# Support / Ticket system — implementation design (Πυλώνας E)

> **Status: design — NOT built.** Turns the decision in `docs/ticket-system-eval.md`
> (build our own thin domain + borrow the mail libs) into a concrete schema, state
> machine, mail flow, two-UI plan and PR breakdown. The eval is the *why*; this is the
> *what/how*. Verify package versions + run the Phase-0 spike before writing code.

## 0. The decisions this design inherits (from the eval — not re-litigated)
- **Build our own thin ticket domain** (multi-tenancy is native here and nobody else has it);
  `jeffersongoncalves/laravel-service-desk` is a **MIT design reference**, not a dependency.
- **Borrow only the mail layer:** `webklex/php-imap` (poll our own `mail.myip.gr`) +
  `willdurand/email-reply-parser` (strip quotes/signature from a reply). No provider/webhook/cost.
- **Two worlds:** operators on the **Filament panel** (a Support **Cluster**), customers on the
  **Flux portal** (`/user`). Config (departments, canned replies) lives in the **Settings Cluster**
  (a new «Υποστήριξη» sub-section — the sub-grouping we just shipped).
- **IMAP-poll one mailbox per department** (WHMCS parity: each dept has its own address + poll).

## 1. Big win — how much we DON'T build (reuse map)
The domain is thin because the cross-cutting concerns already exist as polymorphic traits/tables:

| Concern | Reuse (already in the app) | So the ticket domain… |
|---|---|---|
| Attachments | `HasAttachments` → `attachments` morph (`attachable`) | adds NO `ticket_attachments` table; a `Ticket` **and** a `TicketMessage` are `attachable`. |
| Tags | `HasTags` → `taggables` morph + `TagResource` vocab | adds NO tags table; `Ticket` uses `HasTags` (WHMCS-parity tags for free). |
| Audit «Ιστορικό» | `TracksActivity` (`activity_log`, tenant-scoped) | `Ticket` logs status/assignee/department changes with the same «Ιστορικό» tab. |
| Tenancy | `BelongsToCompany` + `CompanyScope` (ambient `CompanyContext`) | every ticket table carries `company_id`; panel queries auto-scope. |
| Counter atomicity | `InvoiceNumberer` pattern (lockForUpdate) | **not needed** — ticket refs are random/unguessable, not a gapless legal sequence (see §4). |
| Customer ↔ money context | `CustomerLedgerBuilder` / `InvoiceBalance` / Καρτέλα | the ticket view shows the requester's invoices + balance inline — WHMCS **can't**, we can. |
| Scheduler | `routes/console.php` + `config/ekdosi.php` `EKDOSI_SCHEDULE_*` gate | `tickets:poll-imap` slots in like the other scheduled jobs. |
| Per-tenant gating | `Company::hasWhmcsIntegration()` / `einvoice_provider` style | new `companies.support_enabled` + `Company::hasSupport()` gates the whole pillar. |

**Genuinely new:** 6 tables (2 config + 1 pivot), ~5 models, a state machine, an IMAP poller,
a TicketResource, portal Flux pages. Internal notes = a flag on messages, not a new table.

## 2. Schema (tenant-scoped; `company_id` + softDeletes where it makes sense)

### `ticket_departments`  *(config — Settings Cluster)*
`id`, `company_id`, `name`, `email` (the `support@/sales@/info@` address — detects inbound &
sends outbound), `imap_host`, `imap_port` (default 993), `imap_username`, `imap_password`
(**encrypted** cast), `imap_encryption` (`ssl|tls|none`), `imap_folder` (default `INBOX`),
`clients_only` (bool — accept only from a registered `Customer`), `autoresponder` (bool),
`feedback_on_close` (bool), `prevent_client_closure` (bool), `is_hidden` (bool),
`sort`, `is_active`, timestamps, softDeletes.
`unique(company_id, email)`, `unique(company_id, name)`.
Pivot **`department_user`** (`department_id`, `user_id`) = which operators own/watch the dept.

### `tickets`
`id`, `company_id`, `reference` (`TK-xxxxxxxx`, unguessable — §4), `department_id` (FK),
`customer_id` (**nullable** FK — null ⇒ GUEST), `requester_email` (always stored — the `From:`),
`requester_name`, `subject`, `status` (enum §3), `priority` (`low|normal|high`),
`assigned_to` (nullable FK `users`), `opened_via` (`portal|email|operator`),
`last_reply_at`, `last_reply_role` (`customer|operator`), `closed_at`, timestamps, softDeletes.
Indexes: `unique(company_id, reference)`, `(company_id, status)`, `(company_id, department_id)`,
`(company_id, customer_id)`, `(company_id, assigned_to)`. Uses `HasTags`, `TracksActivity`,
`HasAttachments`, `BelongsToCompany`.

### `ticket_messages`
`id`, `company_id` (denormalised for scope), `ticket_id` (FK, cascade),
`author_role` (`customer|operator|system`), `author_id` (nullable FK — `customers.id` or
`users.id` per role; system ⇒ null), `body` (text — cleaned), `body_original` (text nullable —
raw with quotes, audit only), `is_internal_note` (bool — operator-only, never emailed/shown to
customer), `via` (`portal|email|operator|system`), `email_message_id` (nullable — the RFC
`Message-ID` we sent/received, for threading). Index `(ticket_id, id)`. Uses `HasAttachments`.

### `canned_reply_categories` / `canned_replies`  *(config — Settings Cluster)*
category: `id`, `company_id`, `name`, `sort`.
reply: `id`, `company_id`, `category_id` (nullable FK), `title`, `body` (text — supports tokens
`{{customer.name}}`, `{{invoice.code}}`, `{{company.iban}}` …), `sort`, `is_active`, timestamps.
Seed the tenant's real WHMCS categories/replies (`Invoices→InvoiceSend`, `ΑπόδειξηΠαροχής`,
`Επιβεβαίωση πληρωμής`, `Τραπεζικοί λογαριασμοί`).

## 3. State machine (clean 5-state; maps to WHMCS)
`open` (new, needs an operator) → `answered` (operator replied, awaiting customer) →
`customer_reply` (customer replied, back in the operators' queue) → `on_hold` (paused, e.g.
waiting on a third party) → `closed`. Plus `closed → customer_reply` (a reply reopens a closed
ticket, unless `prevent_client_closure`/policy says otherwise).
Transitions are driven by **who** posts, not typed by hand:
- customer/GUEST message ⇒ `customer_reply` (or `open` on first message); reopens if `closed`.
- operator (non-note) message ⇒ `answered`. Internal note ⇒ **no** status change.
- explicit operator actions: Κλείσιμο (`closed`), Σε αναμονή (`on_hold`), Ανάθεση (`assigned_to`).
Enum lives in `App\Enums\TicketStatus` (Filament colours/labels), Greek operator labels.

## 4. Reference = open-date + unguessable tail (not a counter)
Tickets are **not** legal documents → no gapless ΑΑ, no `InvoiceNumberer` lock. `reference` =
**`TK-YYYY-MM-DD-xxxxxx`** — the **open date** (so we read «πότε άνοιξε» straight off the ref) +
a **random** base32 tail (≥6 chars), `unique(company_id, reference)` with retry-on-collision.
The random tail is what makes it safe: the ref is the **email subject token**
`[TK-2026-09-06-abc123]` and the threading key — a fully sequential ref (`TK-000123`) would let a
crafted reply land on another ticket; the random tail closes that. The date prefix is public
info (adds no guessability), pure human sugar. Display shows the same ref.

## 5. Mail ingestion flow  *(Phase 3)*
Scheduled `tickets:poll-imap [--tenant=SLUG] [--department=ID]`, gated by
`EKDOSI_SCHEDULE_TICKETS_IMAP` (like the other scheduled jobs), `withoutOverlapping`. Per active
department mailbox:
1. `webklex/php-imap` fetches UNSEEN messages (per-dept host/creds).
2. **Thread match**, in order: (a) `In-Reply-To`/`References` → a `ticket_messages.email_message_id`
   we sent; (b) `[TK-xxxxxxxx]` token in the subject; (c) neither ⇒ **new ticket**.
3. **Customer binding:** match `From:` → `Customer` (this tenant, `email` or `secondary_email`).
   Match ⇒ OWNER (`customer_id` set); no match ⇒ GUEST (`customer_id` null, `requester_email` kept).
   If the dept is `clients_only` **and** no match ⇒ reject (log + optional bounce), don't open.
4. `email_reply_parser` strips quoted history + signature → `body`; keep raw in `body_original`.
   Attachments → `attachments` morph on the `TicketMessage`.
5. Append `ticket_message` (`via=email`) or open a new `ticket` (`opened_via=email`, `open`).
   Apply the §3 status transition. Ring the operators' bell (reuse the portal-settle bell pattern).
6. Mark the IMAP message `\Seen` / move to a `Processed` folder (idempotent — re-poll won't dup).

**Outbound** (operator or customer reply): send via the department's SMTP identity, stamping a
fresh `Message-ID` + `In-Reply-To`/`References` chain so the recipient's client threads it; persist
`email_message_id`. Internal notes are **never** sent. (Optional later: a `pipe.php`
forwarder for instant capture — the eval's method (a); we ship poll = method (b) first.)

## 6. The two UIs
**Operator — Filament Support Cluster «Υποστήριξη»** (new top entry, gated by `hasSupport()`):
- `TicketResource` list: filter by status/department/assignee/priority; «Τα δικά μου» + «Χωρίς
  ανάθεση» quick filters; SLA-ish «last reply» age column.
- Ticket **view** = the thread (messages + internal notes interleaved, notes visually distinct) +
  a reply composer with **canned-reply picker** (token-expanded) + «Εσωτερική σημείωση» toggle +
  actions (Ανάθεση, Κλείσιμο, Σε αναμονή, Τμήμα, Ετικέτες). 
- **Context side panel** — the native advantage: the requester's invoices, «Καρτέλα» balance and
  latest payments inline (reuse `CustomerLedgerBuilder`/`InvoiceBalance`), so the operator answers
  with the account in front of them. GUEST ⇒ panel shows «μη συνδεδεμένος πελάτης» + a link to bind.
- **Config in the Settings Cluster** (new «Υποστήριξη» sub-section): `TicketDepartmentResource`
  (incl. IMAP «Test σύνδεσης» action + assigned admins) + `CannedReplyResource` (+ categories).

**Customer — Flux portal `/user`** (guard `portal`): «Τα αιτήματά μου» (list + status badges),
«Νέο αίτημα» (department + subject + body + attachment), thread view + reply. Only non-internal
messages; only the customer's own tickets (portal user ↔ `Customer`). Respects `is_hidden`
departments (not offered) and `clients_only` (portal user is always a known customer).

## 7. Menu / IA placement (confirms and uses what we just built)
- **Support Cluster «Υποστήριξη»** = one new **top** entry, **hidden unless `support_enabled`**
  (pillar-born-as-cluster, per `docs/menu-ia.md`). Members: Tickets (+ future KB/Announcements).
- **Config** → the **Settings Cluster**, new sub-section **«Υποστήριξη»** (Τμήματα, Έτοιμες
  απαντήσεις) — exactly the sub-grouping pattern shipped in #494.
This mirrors WHMCS (operational Support on the top menu, its config under Configuration/Setup).

## 8. Packages to add
`composer require webklex/php-imap willdurand/email-reply-parser` (pin versions; confirm
Laravel 13 / PHP 8.4 compatibility in the spike). No provider SDKs, no webhooks.

## 9. Phased build (each phase = its own PR + review gate + changelog/features line)
- **Phase 0 — spike (throwaway, OPTIONAL, ~½–1 day):** a *scratch* branch that never ships — only to
  confirm `webklex/php-imap` can talk to `mail.myip.gr` (port/TLS quirks) and `email_reply_parser`
  cleans a real Greek reply, on PHP 8.4 / Laravel 13. It would use temporary `.env` creds **purely
  as scaffolding**. **The shipped system NEVER hardcodes mail settings** — they live per-department in
  `ticket_departments` (encrypted, edited in the Settings Cluster «Υποστήριξη» → Τμήματα) and are read
  by the poller in Phase 3. Since Phase 1 touches **no mail at all**, we can **skip Phase 0** entirely
  and fold the webklex/parse validation into the first task of Phase 3, against a real department's
  stored config — no hardcoding anywhere, ever.
- **Phase 1 — domain + operator UI, NO mail:** migrations, models (reusing the traits), `TicketStatus`
  enum + transitions, `support_enabled` gate + `hasSupport()`, Support Cluster + `TicketResource`
  (list/view/reply/internal note/canned replies), department + canned-reply config in Settings
  Cluster, context panel, `TracksActivity`. Tickets created manually. Full tests.
- **Phase 2 — customer portal:** Flux pages under `/user` (list/open/reply/attach), operator bell +
  email notification on new customer message, portal↔Customer scoping tests.
- **Phase 3 — mail ingestion:** `tickets:poll-imap`, threading + customer binding + `clients_only`,
  outbound `Message-ID`/`References` threading, scheduler flag, fixture-based tests.
- **Phase 4 — parity polish (as needed):** **watchers/CC + operator bell = SHIPPED** (customer public
  message → durable bell to dept-agents/assignee/watchers; operator auto-watches on reply; email watchers
  Cc'd on replies) + **feedback-on-close = SHIPPED** (customer rates 1–5 from the portal on a closed ticket
  of a `feedback_on_close` department; operator sees ★ n/5). SLA timers **deliberately deferred** (own slice,
  not worth it yet). Still open: ticket merge, spam/block-sender, inbound-CC → watcher auto-capture,
  email-invite on close. **KB + Announcements** = a **separate** later slice, not v1.

## 10. Decisions — CONFIRMED (2026-09-06)
1. **Scope order** — Phase 1+2 (operator + portal, manual tickets) first, then IMAP in Phase 3. ✅
2. **Internal notes** — inline `is_internal_note` messages in the thread (WHMCS-style). ✅
3. **Reference** — **`TK-YYYY-MM-DD-xxxxxx`** = open-date + random tail (§4). ✅
4. **KB / Announcements** — deferred to a later slice, not v1. ✅
5. **Gate** — `companies.support_enabled` + `Company::hasSupport()` (like `hasWhmcsIntegration`). ✅
6. **Departments/emails** — the real `support@/sales@/info@ myip.gr` boxes are **Phase-3 config in
   the Settings Cluster** (`ticket_departments`, encrypted), **never hardcoded**. Phase 0 (any `.env`
   scaffolding) is an optional throwaway we will likely **skip** — Phase 1 is mail-free. ✅

## Sources
Carried from `docs/ticket-system-eval.md` §Sources (service-desk, webklex/php-imap,
email-reply-parser, laravel-mailbox, Filament plugin landscape) + the live WHMCS Support module.
