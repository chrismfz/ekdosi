# Changelog — ekdosi_bridge (WHMCS addon)

All notable changes to the **WHMCS-side plugin** (`whmcs-plugin/ekdosi_bridge/`).
Format: [Keep a Changelog](https://keepachangelog.com/), versions track the
`'version'` in `ekdosi_bridge.php`.

> **Discipline (from v0.21.0 on):** every plugin change adds a line here and
> bumps the version in `ekdosi_bridge.php`. Entries ≤ v0.20.0 were
> reconstructed from `CLAUDE.md` + git history, so they're headline-level only.

## [Unreleased]

## [0.22.0] — 2026-06-02
### Changed
- **Unified per-invoice inspect into the invoice list.** Dropped the standalone
  «Ή επιθεώρησε ένα συγκεκριμένο τιμολόγιο» form from the addon landing; the
  invoice list (`action=invoices`) now carries a «Μετάβαση σε τιμολόγιο #…»
  jump-box that reaches any invoice, including ones outside the current
  status/period filter. One door to the per-invoice detail (`action=show`) —
  same target as a row's «Άνοιγμα». Per-invoice badges on the native WHMCS
  invoice page (`AdminInvoicesControlsOutput` hook) are unchanged.

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
