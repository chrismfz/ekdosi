# Changelog — ekdosi_bridge (WHMCS addon)

All notable changes to the **WHMCS-side plugin** (`whmcs-plugin/ekdosi_bridge/`).
Format: [Keep a Changelog](https://keepachangelog.com/), versions track the
`'version'` in `ekdosi_bridge.php`.

> **Discipline (from v0.21.0 on):** every plugin change adds a line here and
> bumps the version in `ekdosi_bridge.php`. Entries ≤ v0.20.0 were
> reconstructed from `CLAUDE.md` + git history, so they're headline-level only.

## [Unreleased]

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
