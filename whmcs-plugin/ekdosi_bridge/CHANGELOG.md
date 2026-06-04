# Changelog — ekdosi_bridge (WHMCS addon)

All notable changes to the **WHMCS-side plugin** (`whmcs-plugin/ekdosi_bridge/`).
Format: [Keep a Changelog](https://keepachangelog.com/), versions track the
`'version'` in `ekdosi_bridge.php`.

> **Discipline (from v0.21.0 on):** every plugin change adds a line here and
> bumps the version in `ekdosi_bridge.php`. Entries ≤ v0.20.0 were
> reconstructed from `CLAUDE.md` + git history, so they're headline-level only.

## [Unreleased]

## [0.34.0] — 2026-06-03
### Added
- **State in the write-back (`mod_ekdosi_invoice_marks.state`).** `inbound.php`
  now accepts an optional `state` ('active'/'cancelled') alongside the MARK, and
  the manage-invoice badge shows **«… · ΑΚΥΡΩΜΕΝΟ»** (red) when ekdosi cancels at
  AADE — instead of a stale "valid" MARK. New `state` column (added by
  `InvoiceMarkStore::ensureTable` + the SchemaGuard probe, idempotent &
  privilege-safe, no reactivation); `InvoiceMarkStore::set()` gains the param and
  `stateFor()` reads it. The idempotent guard is unchanged — cancel re-pushes the
  SAME mark (only `state` differs), so it's never a 409.

## [0.33.0] — 2026-06-03
### Added
- **«Bridge logs» tab + Plugin-API request log (`mod_ekdosi_bridge_log`).** Every
  call ekdosi makes to `resolve.php` is recorded (op, IP, HTTP status, short
  result — count / found / auth-failure reason) via a shutdown-function recorder
  that fires even on early `exit`, so success, 401/422 auth-failures, unknown ops
  and exceptions are ALL captured with one chokepoint. New `BridgeLogStore`
  (created by `SchemaGuard`, no reactivation; best-effort — never throws into a
  response; ~30-day self-pruning). The admin landing gains a **«Τελευταίο ερώτημα
  ekdosi»** freshness row + a **«Bridge logs»** button; the tab shows a freshness
  banner (no inbound poll in >1h → red — the silent-outage tripwire that would
  have caught the ~1.5-day stall from the WHMCS side), a 401/422 secret-mismatch
  note, 24h totals, and the recent request table.

## [0.32.0] — 2026-06-03
### Added
- **Plugin-API `op=invoice` (single-invoice feed)** — the single-id twin of
  `op=invoices`. Returns the SAME rich payload (invoice + client + customfields +
  line items, `with_routing` optional) for ONE invoice id, with NO status filter
  (the push path targets a specific invoice the operator chose); `invoice: null`
  when the id is unknown (200, so the client needs no 404 handling). Lets ekdosi's
  push path («Αποστολή στο Ekdosi» → invoice-paid webhook) fetch the canonical
  payload from US instead of the native WHMCS API — one HMAC path, the Plugin-API,
  for both pull (`invoices`) and push (`invoice`). `InvoiceFeed` refactored: the
  per-invoice payload builder is now shared by `fetch()` and the new `fetchOne()`.

## [0.31.0] — 2026-06-03
### Added
- **One-click straight-to-edit on the invoice list** (kills the WHMCS 8.9+
  view-only «Manage Invoice» extra click). A footer script — LIST page only —
  repoints each row's invoice link from the view-only target (the new
  `/billing/invoices/N` path or legacy `invoices.php?action=view|manage&id=N`)
  to the legacy editable URL `invoices.php?action=edit&id=N`. That page is the
  ONLY place our `AdminInvoicesControlsOutput` buttons render (the hook fires
  neither on view-only nor on the new billing URL), so one click now lands on
  the editable page WITH the ekdosi/relid buttons. Defensive: only rewrites
  known view-only URL shapes, silently no-ops otherwise (native per-row «Edit»
  link stays the fallback), all in try/catch.

## [0.30.0] — 2026-06-02
### Changed
- **show() reads tblinvoices once** (review NIT). The fetched invoice row is now
  passed into `ekdosiSummaryCompact()` and the userid into `relidSection()`,
  instead of each re-querying it — one row read per page render instead of three.
- **Live status call fails fast** (review NIT). `getInvoiceStatus()` now uses
  short cURL timeouts (connect 2s / total 6s) instead of the default 20s/5s, so a
  slow or down ekdosi degrades to the friendly «status query failed» note quickly
  instead of hanging the unified invoice page. `httpRequest()` gained optional
  `$timeout`/`$connectTimeout` params (default 20/5 — push/write-back unchanged).

## [0.29.0] — 2026-06-02
### Changed
- **SchemaGuard skips DDL on the hot admin path** (review follow-up). `ensureSilently()`
  now runs a single cheap `information_schema` probe first and only falls through to
  the `CREATE/ALTER IF NOT EXISTS` steps when a table/column is actually missing (or
  `tblinvoices.invoiced` is still BIGINT). Previously every admin page load issued ~4
  DDL statements — which on MySQL/MariaDB implicitly COMMIT any open transaction and
  add needless load. Common case is now one metadata SELECT, no DDL. `_activate()`
  still force-runs `ensure()`.

## [0.28.0] — 2026-06-02
### Added
- **«Επαναφορά relid» (auto-resolve + preview)** — restore the link on a line
  whose relid was zeroed by mistake. Zeroed lines now get a checkbox + a preview
  of the resolved target in the «Σύνδεση» column («→ domain `#id`»), shown ONLY
  when the line resolves to exactly one of the client's domains/services
  (`RelidInspector::restoreCandidate`: domain-token-from-description + userid
  match). The «Επαναφορά relid» button re-resolves server-side (never trusts the
  client), applies only to still-`relid=0` lines, and skips/report ambiguous
  ones. Audited. Zeroing stays one form with two buttons (Μηδενισμός / Επαναφορά
  via `formaction`); select-all toggles only the active (zero) group.

## [0.27.0] — 2026-06-02
### Added
- **relid table: «Λήξη (registry)» column** — for domain lines, the real registry
  `expirydate` next to the renamed «Επόμενη χρέωση (WHMCS)» (`nextduedate`), so
  the operator sees when a domain actually expires vs what WHMCS will bill (the
  «already renewed?» judgement). Hosting has no registry expiry → «—».
  (`RelidInspector::items` now also returns `expiry`.)

## [0.26.0] — 2026-06-02
### Changed
- **ONE unified invoice manager (`action=show`).** Folded the separate relid
  manager into the invoice page: `show` now renders the ekdosi headline
  (ΜΑΡΚ/ΤΠΥ + «Τιμολογήθηκε στη legacy» + «Αποστολή»), the live ekdosi status,
  AND the full per-line relid table + «Μηδενισμός relid» — all on one page. No
  more «Πλήρες Inspect» hop. The relid table body moved to a private
  `relidSection()`; `relidCheck` is now a thin alias → `show` (old bookmarks
  still work). Every relid entry point (invoice-list column, manage-invoice
  «Έλεγχος relid», relidReset back-link) lands on the unified page.

## [0.25.0] — 2026-06-02
### Added
- **ekdosi headline on the relid manager (`action=relidCheck`).** The relid
  manager now opens with the same compact ekdosi state the native manage-invoice
  sidebar shows — ΜΑΡΚ/ΤΠΥ (ekdosi/AADE), the «Τιμολογήθηκε στη legacy»
  resolution (→ ΤΠΥ/ΜΑΡΚ when known), a «Αποστολή στο ekdosi» button (while
  unfiled), and a «Πλήρες Inspect» link. So the relid page isn't a dead-end: you
  see the invoice's ekdosi context right there. Compact + cheap (no live-status
  round-trip; that stays on Inspect). New private `ekdosiSummaryCompact()`.

## [0.24.0] — 2026-06-02
### Added
- **relid surfaced everywhere — the «unify» pass.**
  - **Inspect (`action=show`) is now the one rich per-invoice view:** it gained
    the relid block (⚠ N γραμμές με relid / «καθαρό» + «Έλεγχος relid» link to the
    relidCheck manager), alongside the ekdosi ΜΑΡΚ/ΤΠΥ state, the legacy-filed
    resolution, the live ekdosi status, and Send/Reset — i.e. everything the
    native manage-invoice sidebar shows, in one place.
  - **Invoice list gained a «relid» column:** ⚠ N (warning) that links straight
    to the relid manager, «—» when clean. Computed in ONE batch query for the
    whole page (`RelidInspector::activeCountsForInvoices`) so it's cheap across
    Εξοφλημένα/Ανεξόφλητα/Όλα.

## [0.23.0] — 2026-06-02
### Added
- **Self-healing schema guard (`lib/SchemaGuard.php`).** The schema steps that
  used to live only in `_activate()` (create `mod_ekdosi_*` tables, restore
  `tblinvoices.invoiced` to SMALLINT) now ALSO run on every admin page load via
  `SchemaGuard::ensureSilently()`. Stateless — no stored version to lose — and
  cheap (CREATE-IF-NOT-EXISTS + one metadata lookup; the BIGINT→SMALLINT
  narrowing only fires if we ever widened it). Privilege-safe (a missing grant
  degrades to a note, never a fatal). `_activate()` now just calls
  `SchemaGuard::ensure()` and reports its notes.
- **Why it matters:** a future schema change ships as a plain file upload — NO
  deactivate/reactivate. That ends the cycle where WHMCS wiped the saved bridge
  settings (URL / slug / secret) on every deactivate. Activate stays available
  but is no longer required to pick up schema.

## [0.22.0] — 2026-06-02
### Added
- **«Μετάβαση σε τιμολόγιο #…» jump-box on the invoice list** (`action=invoices`):
  reach any invoice — including ones outside the current status/period filter —
  without leaving the list. Same target as a row's «Άνοιγμα» (`action=show`).
### Changed
- The landing's «Ή επιθεώρησε ένα συγκεκριμένο τιμολόγιο» quick-inspect form is
  kept (a free, read-only shortcut to `action=show` straight from the landing)
  and restyled to match the list jump-box. Per-invoice badges + «Έλεγχος relid»
  on the native WHMCS invoice page (`AdminInvoicesControlsOutput` hook) are
  unchanged — full ekdosi/relid access stays available per invoice at manage.
- **Shared HMAC secret field is now plain `text` (visible/copyable)** instead of
  `password`. The addon config page is super-admin-only and the value is
  readable via SQL anyway, so masking added no real protection — but it made
  re-entering the secret painful after WHMCS clears `tbladdonmodules` on a
  deactivate/reactivate. Now you can copy it straight from the field.
  (Reminder: code-only updates do NOT need deactivate/activate — just overwrite
  the plugin files; WHMCS loads addon code fresh each request. Activation is
  only for schema changes, and `_activate()` is idempotent.)

## [0.21.0] — 2026-06-02
### Added
- **relid check + «Μηδενισμός relid»** on the admin invoice page: a per-line
  badge («⚠ N γραμμές με relid», red when some are already renewed) + «Έλεγχος
  relid» button → addon page with a per-line table (description / type / linked
  domain·service / next due / relid) and a guarded, CSRF-protected,
  activity-log-audited action that zeroes `relid` on the selected lines.
  Prevents WHMCS from double-renewing already-renewed domains at Mark Paid.
  The safe successor to the legacy `relid_remover`. Read-only w.r.t. ekdosi/AADE
  (`lib/RelidInspector.php`).

## [0.20.0]
### Added
- **Bridge-fetch**: the plugin serves the ekdosi inbox feed itself
  (`resolve.php` op `invoices`) — a self-contained feed with routing folded in
  and a per-tenant default, so ekdosi pulls staged invoices via the bridge.

## [0.18.0]
### Changed
- Addon invoice list: added «Εβδομάδα» to the date window and made it the default.

## [0.17.0]
### Added
- Historical AADE badges in the addon invoice list; relative admin URL (works on
  a custom admin folder).

## [0.16.0]
### Added
- **Deterministic historical link**: `tblinvoices.invoiced === invoices.legacy_id`
  resolves a filed-in-legacy invoice to its ΤΠΥ + ΜΑΡΚ (no heuristics).

## [0.15.0]
### Added
- **Dual-run visibility**: read-only legacy-invoiced flag + ΤΠΥ invcode on the
  MARK badge («Στο AADE · ΤΠΥ … · ΜΑΡΚ …»).

## [0.14.0]
### Fixed
- Stop widening `tblinvoices.invoiced` (SMALLINT→BIGINT broke the legacy app);
  the MARK now lives in our own `mod_ekdosi_invoice_marks`; activation RESTORES
  `invoiced` to SMALLINT. We only ever READ `invoiced` now.

## [0.13.0]
### Changed
- `EkdosiClient` error classification: distinguish 409 / 502-504 / 401-403 and
  surface the `audit_preserved` success flag instead of a generic "HTTP NNN".

## [0.12.0]
### Added
- Live-deploy hardening: AFM-keyed per-client visibility, invoice-states batch.
### Fixed
- Inbox paging used the wrong WHMCS params (`limit/offset`); switched to
  `limitstart/limitnum` (GetInvoices silently ignored the former) — prod 16→146.

## [0.5.0]
### Added
- First WHMCS-side visibility: per-invoice MARK badge, invoice-list badge,
  «Αποστολή στο Ekdosi» button, per-client 3-way map (WHMCS# → ΤΠΥ → ΜΑΡΚ).
