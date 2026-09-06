# Support / Ticket system — package evaluation & decision (Πυλώνας E)

> **Status: research/decision — NOT built.** Deeper follow-up to the `docs/BACKLOG.md`
> «Πυλώνας E» bullet. Grounded from official package docs 2026-09-06. Verify versions +
> a sandbox spike before committing code.

## Frame it as TWO layers (this is the key insight)

A ticket system is not one package — it's **(1) a ticket domain** (departments, statuses,
SLA, internal notes, ticket↔customer/service links) **+ (2) a mail ingestion layer** (turn an
inbound email into a ticket / a reply into a ticket message). They're chosen separately.

Our constraints steer both:
- **Multi-tenant** (`company_id` + `CompanyScope` on every table) — NONE of the ticket packages
  have this; it's a retrofit cost wherever we don't build.
- **Two-worlds UI** — operators on the **Filament panel**, customers on the **Flux portal**
  (`/user`). So an operator-only Filament plugin only does half; a **headless** domain or our
  own model lets us own both UIs.
- **Self-hosted mail** (`mail.myip.gr`, our own IMAP/SMTP) → ingestion is **IMAP polling**, NOT
  a paid provider webhook (Mailgun/SES/Postmark are for routing mail through a third party — we
  don't need or want that).
- Tickets tie **deeply** into our existing `Customer`/service/company model + `TracksActivity`.

---

## Layer 2 — mail ingestion (the genuinely hard part worth borrowing)

| Option | What it is | Fit for us |
|---|---|---|
| **`webklex/php-imap`** | Pure IMAP client — poll a mailbox, read messages/attachments | ⭐ **Best fit** — polls our own `mail.myip.gr` directly, no third party, no cost. A scheduled `imap:poll` command (we already run a scheduler). |
| `directorytree/imapengine` | Modern IMAP client (newer alternative to webklex) | Viable alt to webklex; either works. |
| **`beyondcode/laravel-mailbox`** | Mature (1.1k★) inbound-email router: `Mailbox::from(...)` → your closure. Drivers: Mailgun/SES/Postmark/SendGrid webhooks + IMAP + log | Great API, but its sweet spot is **provider webhooks**. Keep as the alt **if** we ever move inbound mail to a provider. Overkill for polling our own box. |
| **`willdurand/email-reply-parser`** | Strips quoted history + signatures from a reply body (GitHub's `email_reply_parser` port) | ⭐ **Always needed** — this is what makes "reply to a ticket email" produce a clean message instead of the whole quoted thread. Pair with whichever IMAP lib. |

**Verdict (layer 2):** `webklex/php-imap` (poll our own mailbox on the scheduler) +
`willdurand/email-reply-parser` (clean the reply). No provider, no webhook, no cost.

---

## Layer 1 — the ticket domain

| Option | Type | Multi-tenant | UI | Verdict |
|---|---|---|---|---|
| **`jeffersongoncalves/laravel-service-desk`** | headless domain package (MIT, **Laravel 11/12/13**, PHP 8.2+) | ❌ single user/operator model | none (headless — «integrate with Filament/Livewire/…») | **Design reference / candidate.** Rich model (tickets, departments, agents, statuses Open→Closed, priorities, auto-ref, SLA + breach/escalation, KB, service catalog, watchers, attachments) + IMAP-poll **and** webhook ingestion baked in. Cons: **not multi-tenant** (retrofit `company_id`/`CompanyScope` onto its migrations/models), **young** (8★, ~40 commits → bus-factor), reply/quote parsing not documented. |
| `rasmuscnielsen/laravel-support-tickets` | old package | — | — | ❌ **Drop** — Laravel 5.x era, abandoned. |
| Umnidev Helpdesk / `jeffersongoncalves/filament-help-desk` / Padmission / Creators Ticketing | **Filament plugins** (operator UI) | ❌ | Filament only | Only if tickets become **operator-only**. They don't solve the customer-facing **Flux** side and we'd fight their Filament assumptions + retrofit tenancy. Unlikely (WHMCS has customer tickets). |
| Faveo | standalone helpdesk app | n/a | own app | ❌ Too heavy — a separate application, not a library. |
| **Build-our-own thin model** | our code | ✅ native (like everything else) | native Filament + Flux | ⭐ **Recommended** — see below. |

---

## Recommendation

**Build our own thin ticket domain, borrow the mail-ingestion libs, use `laravel-service-desk`
as a MIT design reference (schema + state machine) — not as a dependency.**

Why build the domain rather than adopt:
- **Multi-tenancy is non-negotiable and nobody has it.** Retrofitting `company_id`/`CompanyScope`
  onto a package's migrations + every query is often MORE work than modelling ~4 clean tables the
  way we model everything else — and it's a permanent maintenance tax on someone else's schema.
- **We already own the two-worlds UI pattern** (Filament resources for operators, Flux blades for
  the portal), `TracksActivity` audit, the scheduler, and mail config. A package's UI-agnostic
  domain buys us little that our own model doesn't, and its state machine we can copy in an afternoon.
- **Tickets couple tightly** to `Customer` (and later `ServiceContract`/`Domain`) — native FKs +
  `CompanyScope` beat bridging to a foreign package's `User`/`morph` relations.
- The **only** expensive, error-prone part is email ingestion/threading — and that we borrow
  (`webklex/php-imap` + `willdurand/email-reply-parser`), not reinvent.

Proposed thin schema (mirrors WHMCS/`service-desk`, tenant-scoped):
`ticket_departments` · `tickets` (company_id, customer_id, department_id, subject, status,
priority, reference `TK-…`, assigned_to, last_reply_at) · `ticket_messages` (ticket_id, author
morph or {customer|user|system}, body, **is_internal_note**, via {portal|email|operator}) ·
`ticket_attachments` · `canned_replies`. Statuses = Open/Pending/Answered/Closed. `TracksActivity`
on tickets. Operator UI = a Filament **Cluster** «Υποστήριξη» (per the Menu/IA backlog item);
customer UI = Flux pages under `/user`.

Mail flow: scheduled `tickets:poll-imap` → `webklex/php-imap` reads the support mailbox → match
`In-Reply-To`/`References` or a `TK-…` token in the subject → `email_reply_parser` cleans the body
→ append `ticket_message` (or open a new ticket, matching sender → `Customer` by email). Outbound
replies via our existing mailer, stamping `Message-ID`/`References` for threading.

**Before writing code — a 1-2 day spike:**
1. Stand up `laravel-service-desk` in a throwaway branch: read its migrations + state machine +
   IMAP poller (MIT — legitimate to learn from). Measure the honest cost of a tenancy retrofit.
2. Prove `webklex/php-imap` polls `mail.myip.gr` and `email_reply_parser` cleans a real reply.
3. Decide: adopt service-desk (if tenancy retrofit is cheap) **or** build the thin model (likely).
   Either way the mail layer + the two UIs are ours.

## WHMCS parity — what already works there that we KEEP (from the live panel, 2026-09-06)

The tenant's real WHMCS Support module is the spec. Observed, and worth replicating:

**Departments** (`Support Departments`) — each department = a routing unit with:
- its own **email address** (`support@`, `sales@`, `info@` on `myip.gr`) — the address both
  detects inbound and sends outbound for that dept.
- per-department **mail import** (POP3/IMAP `Mail Provider`: host `mail.myip.gr`, port `995`,
  user, pass, «Test Configuration») — confirms our **IMAP-poll-our-own-mailbox** decision, one
  mailbox per department.
- **Assigned admin users** (which operators own the dept), + toggles: **Clients Only**, Pipe
  Replies Only, No Autoresponder, **Feedback Request** on close, **Prevent Client Closure**,
  **Hidden**.

**«Mail κλειδωμένο με πελάτη/εταιρία» — the requester↔customer binding.** WHMCS matches the
sender email to a registered client → shows an **OWNER** badge; an unknown sender = **GUEST**.
The **«Clients Only»** dept toggle locks a department so a ticket/reply is accepted ONLY when the
sender address belongs to a registered client/user/contact. This is exactly the «κλειδωμένο σε
πελάτη-εταιρία» we want: for us = match inbound `From:` → `Customer` (by email) within the tenant;
GUEST otherwise; a per-department «μόνο πελάτες» flag. (Our `Customer` already keys on email.)

**Service/context panel inside the ticket** — the single biggest operator win. The ticket view
lists the requester's **Products/Services** (e.g. `Domain – ptks.gr €19/2yr`, `Starter – ptks.gr
€100/biennial`, next-due, status) + «Change Associated Service», so the operator answers WITH the
customer's account in front of them. **Our native advantage:** we can show not just services but
the customer's **ekdosi invoices / «Καρτέλα» / balance** inline — WHMCS can't, we can (same
`CustomerLedgerBuilder`).

**Predefined / canned replies** in **categories** — and the tenant's are already invoicing-shaped
and Greek: `Invoices → InvoiceSend`, `ΑπόδειξηΠαροχής`, `Επιβεβαίωση πληρωμής`, `Τραπεζικοί
λογαριασμοί` (IBANs). These tie straight into OUR domain — a canned reply that pastes the invoice/
receipt/bank-account text (even templated from the linked invoice). Keep categories + templating.

**Ticket operations to keep** — customizable **Ticket Statuses** (seen: Open / Answered /
Customer-Reply / Awaiting Reply / Closed), **priority**, **assigned-to**, **staff participants**,
**watchers**, **CC recipients**, **tags**, **internal notes** (Add Note), **custom fields**, «Other
Tickets» (same-client history), **merge**, **pin**, **Block Sender & Delete**, scheduled actions,
attachments, «Insert Predefined Reply». Plus **Escalation Rules** and **Spam Control**.

**Mail piping — two methods** WHMCS offers, both relevant: (a) email-forwarder **pipe** to
`pipe.php` (instant), OR (b) **POP3/IMAP cron** `*/5 * * * * … pop.php` (poll every 5'). We chose
(b) = IMAP poll on our scheduler; keep the *option* of a pipe/forwarder later for instant capture.

**Menu / IA — WHMCS confirms our «Settings Cluster» call.** WHMCS splits it: the **configuration**
(Support Departments, Ticket Statuses, Escalation Rules, Spam Control) lives under the left
**«Configuration»/Setup** sidebar, while the **operational** Support (Tickets, Predefined Replies,
Knowledgebase, Announcements) sits under the **top «Support»** menu. That is precisely the
«Settings tucked away, WHMCS/Blesta style» pattern in the Menu/IA backlog item → for us: a Support
**Cluster** for daily ops + config under the **Settings Cluster**, not one flat pile.

## Sources
- laravel-service-desk: https://github.com/jeffersongoncalves/laravel-service-desk
- laravel-mailbox: https://github.com/beyondcode/laravel-mailbox
- webklex/php-imap: https://github.com/Webklex/php-imap
- email-reply-parser: https://github.com/willdurand/EmailReplyParser
- Filament plugin landscape: https://filamentphp.com/plugins?categories=support (Umnidev Helpdesk, etc.)
