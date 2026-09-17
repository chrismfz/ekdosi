# Changelog — ekdosi_bridge (WHMCS addon)

All notable changes to the **WHMCS-side plugin** (`whmcs-plugin/ekdosi_bridge/`).
Format: [Keep a Changelog](https://keepachangelog.com/), versions track the
`'version'` in `ekdosi_bridge.php`.

> **Discipline (from v0.21.0 on):** every plugin change adds a line here and
> bumps the version in `ekdosi_bridge.php`. Entries ≤ v0.20.0 were
> reconstructed from `CLAUDE.md` + git history, so they're headline-level only.

## [Unreleased]

## [0.50.0] — 2026-09-17
### Changed
- **Η σελίδα του addon έγινε ΜΙΑ σελίδα (Direct Access).** Η αρχική είναι πλέον απευθείας η
  **λίστα «Τιμολόγια WHMCS → Ekdosi»** με ένα **λεπτό status strip** στην κορυφή — όχι πια ένα
  ενδιάμεσο landing με κουμπιά. Το strip συμπυκνώθηκε: η γραμμή **«Γέφυρα»** δείχνει σε μία σειρά
  `κατάσταση · τελευταίο ερώτημα ekdosi (ago-badge, ακριβής ώρα στο hover) · έκδοση` (αντί για
  ξεχωριστές γραμμές «Έκδοση plugin» + «Τελευταίο ερώτημα ekdosi» με ολόκληρο timestamp), και
  **αφαιρέθηκε** η γραμμή «— Παλαιά παραστατικά (historical)» (ο διακόπτης μένει στο Configure).
- **Τα δευτερεύοντα εργαλεία πήγαν footer:** «Προτιμήσεις τρίτων» και «Bridge logs» εμφανίζονται
  πλέον κάτω-κάτω. Το inspect-box της αρχικής **αφαιρέθηκε** (η λίστα έχει ήδη δικό της jump-by-id).
- **Το κουμπί «Sync from legacy timologia» αποσύρθηκε από το UI** (εφάπαξ εργασία, ολοκληρωμένη).
  Η `sync()` action παραμένει προσβάσιμη μέσω URL για σπάνιο χειροκίνητο re-run.

### Added
- **Οι καταχωρημένες γραμμές τονίζονται με ανοιχτό πράσινο.** Στη λίστα, ένα τιμολόγιο κλεισμένο
  στο AADE παίρνει ελαφρύ πράσινο row-tint (`class="success"`) — καθρέφτης του κόκκινου των
  «άμεσων/αφιλτράριστων». Το tint ακολουθεί ΑΚΡΙΒΩΣ το πράσινο badge «Καταχωρημένο» (forward: pending
  `filed`· legacy filing) — όχι απλώς `mydata_state=VALID` (μια VALID+draft γραμμή δείχνει μπλε badge,
  οπότε δεν πρασινίζει). Το ακυρωμένο (CANCELLED) ΔΕΝ πρασινίζει· το «χρειάζεται ενέργεια» κόκκινο
  υπερισχύει του πράσινου.

## [0.49.0] — 2026-09-16
### Added
- **Η λίστα «Τιμολόγια WHMCS → Ekdosi» δείχνει πλέον πλήρη WHMCS στοιχεία** — τρεις στήλες
  ημερομηνιών (**«Ημ/νία»** έκδοσης, **«Λήξη»** = `duedate`, **«Ημ. πληρωμής»** = `datepaid`) και
  **«Τρόπος πληρωμής»** (friendly name του gateway από `tblpaymentgateways`, με fallback στο slug).
  Parity με το ekdosi panel + εύκολος διαχωρισμός «πότε/πώς πληρώθηκε» χωρίς να φεύγεις από το addon.
  Οι κενές ημερομηνίες (`0000-00-00` = ανοιχτό/μη-πληρωμένο) εμφανίζονται ως «—». Ένα batched query
  για τα ονόματα gateway ανά σελίδα· καμία επιπλέον κλήση στο ekdosi.

## [0.48.0] — 2026-09-16
### Changed
- **Το inbox feed (`op=invoices`, `paid_unfiled`) φιλτράρει πλέον κατά ΗΜΕΡΟΜΗΝΙΑ ΠΛΗΡΩΜΗΣ
  (`datepaid`), όχι έκδοσης (`date`).** Το per-tenant cut-over (`whmcs_invoice_min_date`) σημαίνει
  τώρα «ό,τι **πληρώθηκε** από την ημερομηνία κι έπειτα». Έτσι ένα renewal που εκδόθηκε πριν το
  cut-over αλλά πληρώθηκε μετά (αργός πελάτης, 2 μήνες αργότερα) **εμφανίζεται τη μέρα που πληρώνεται**,
  ενώ το ιστορικό (πληρωμένο πριν το cut-over) **δεν πλημμυρίζει** το inbox. Τα άλλα status modes
  (Paid/Unpaid/…) κρατούν το creation-date bound.
### Added
- **Το feed φέρνει τις πληρωμές (`tblaccounts`) ανά τιμολόγιο** — `transactions[]` με `transid`
  (gateway txn id), `gateway`, `date`, `amount`. Το ekdosi τα δείχνει στο modal («Ημ. πληρωμής» +
  «Transaction ID») και τα εκθέτει στο `whmcs_inbox_list` MCP tool (insights). Ένα batched query/σελίδα.

## [0.47.1] — 2026-09-15
### Changed
- **Το status «Απορρίφθηκε» εμφανίζεται πλέον ως «Αρχειοθετήθηκε»** στο WHMCS admin (badge στη λίστα
  παραστατικών + «Σημείωση αρχειοθέτησης» στο status block), εναρμονισμένο με το ekdosi panel όπου το
  «Απόρριψη» έγινε «Αρχειοθέτηση». Ουδέτερο γκρι badge (`label-default`) αντί για κόκκινο. Αλλαγή κειμένων
  μόνο — το εσωτερικό status `rejected` του ekdosi δεν αλλάζει.

## [0.47.0] — 2026-09-04
### Added
- **«Εκδοθέντα Παραστατικά»: historical (pre-bridge) παραστατικά + context-aware verify label.**
  Η σελίδα δείχνει πλέον ΚΑΙ τα εισαγμένα (pre-bridge) παραστατικά του πελάτη: το plugin στέλνει τα
  δικά του WHMCS invoice ids (`tblinvoices.userid`) στο (πλέον **POST**) `issued-for-client`, και το
  ekdosi τα ματσάρει ντετερμινιστικά με `invoices.whmcs_invoice_id` — leak-proof (boundary = τα invoices
  **που πλήρωσε ο ίδιος**, όχι ο ΑΦΜ τρίτου). Το κουμπί επαλήθευσης είναι πλέον context-aware μέσω
  `verify_kind`: «Προβολή παρόχου (ΥΠΑΕΣ)» για provider-signed vs «Επαλήθευση ΑΑΔΕ» για direct-myDATA.

## [0.46.0] — 2026-09-04
### Added
- **Client-area σελίδα «Εκδοθέντα Παραστατικά».** Νέο link στο «Τιμολόγηση» dropdown του πελάτη
  (ανεξάρτητος διακόπτης `show_client_issued` + προαιρετικό `issued_pilot_clients`, default OFF).
  Ο reseller βλέπει σε πίνακα τα ekdosi παραστατικά που εκδόθηκαν γι' αυτόν μέσω της γέφυρας —
  **στο όνομά του ΚΑΙ σε τρίτους που δρομολόγησε ο ίδιος** — με ημ/νία, ΤΠΥ, τύπο, κατάσταση, ΜΑΡΚ,
  επίσημο PDF και σύνδεσμο επαλήθευσης ΑΑΔΕ/παρόχου. Η λίστα έρχεται live από το ekdosi
  (`EkdosiClient::getIssuedForClient`, scoped στον `$_SESSION['uid']`). Το **PDF περνά proxy**
  (`EkdosiClient::getIssuedDocPdf` → stream των bytes· το signed URL του ekdosi δεν εκτίθεται στον
  browser). Ο ίδιος `Gate` προστατεύει και το navbar link και τους handlers (κανένα URL-guessing).

## [0.45.0] — 2026-09-03
### Added
- **Invoice-feed carries the payment gateway (`paymentmethod`).** Each bridge invoice
  payload now includes `tblinvoices.paymentmethod` (the gateway system name:
  banktransfer / stripe / paypal …). Lets ekdosi map a WHMCS gateway → an ekdosi
  payment method (§8.12 type + term) so a card/bank-paid invoice no longer files
  under the invoice-type cash default. The native `GetInvoice` path already carried
  it; this brings the bridge feed to parity (`fetch` + `invoice` ops).

## [0.44.0] — 2026-09-02
### Added
- **Invoice-feed line enrichment: `whmcs_product_id` + `whmcs_group_id`.**
  Each HOSTING line in the bridge invoice feed now carries its WHMCS product id
  (`tblhosting.packageid`) and product group id (`tblproducts.gid`), batched (relid→packageid→gid). Lets
  ekdosi classify WHMCS lines to §8.6 income categories **by group** («Web Hosting → υπηρεσία»),
  with new packages inheriting the group's choice (MYD-006 bridge mapping). Domains/addons/ad-hoc
  lines resolve to 0 → ekdosi falls back to the invoice-type default, unchanged.

## [0.43.0] — 2026-07-14
### Added
- **`resolve.php` op `add_payment` — outbound mark-paid (ekdosi → WHMCS).** Delegates to
  WHMCS `localAPI('AddInvoicePayment')` so gateway logs / activity / the auto-Paid transition
  behave natively. HMAC-signed like every resolve op; requires `{invoice_id, amount, transid}`
  (transid = idempotency handle, WHMCS rejects a duplicate transid+gateway). Feeds the ekdosi
  Phase-2 outbound push for **bridge tenants** (native tenants call the WHMCS API directly).
  Only needed if `companies.whmcs_push_payments` is on for a bridge tenant.
  **Clamps the payment to the WHMCS invoice's real remaining balance** (`total − Σ tblaccounts.amountin`)
  so an ekdosi-computed amount that exceeds what WHMCS still owes can never over-pay it into a credit
  balance; `remaining ≤ 0` returns ok (already settled), unknown id returns 404.

## [0.42.0] — 2026-07-11
### Fixed
- **WH-9: the `paid_unfiled` feed no longer re-walks already-filed invoices.** Post
  legacy-cutover, `tblinvoices.invoiced` stops being maintained, so paid invoices
  ekdosi already filed (a row in `mod_ekdosi_invoice_marks`) stayed `invoiced=0` and
  kept reappearing in the inbox feed forever — an ever-growing, pointless O(N) walk.
  The feed now adds `whereNotExists` on `mod_ekdosi_invoice_marks.invoiceid = tblinvoices.id`
  to the `paid_unfiled` branch only (the explicit `Paid`/`All` diagnostics and the
  single-invoice `fetchOne` push path are unchanged), keeping the feed bounded to the
  genuinely-unfiled set.

## [0.41.0] — 2026-06-15
### Added
- **`phonenumber` in the invoice feed** (`InvoiceFeed`) — the bridge feed now carries
  the client's phone alongside email/address, matching the native
  `getInvoiceWithClient` shape. Lets ekdosi populate `customers.phone1` when it
  creates a customer from a staged invoice (was a silent no-op for bridge-feed
  tenants, since only the native API path carried the phone).

## [0.40.0] — 2026-06-11
### Added
- **«Άμεσο» (immediate-invoice) red row** in the `?module=ekdosi_bridge&action=invoices`
  list — the WHMCS-side mirror of the ekdosi inbox's red badge. A client flagged
  «άμεση τιμολόγηση» (the legacy γκρινιάρης custom field, resolved by NAME like the
  existing «θέλω τιμολόγιο» field — and EXCLUDING that field so the two never collide)
  gets a red «⚡ Άμεσο» badge on their name; the row turns red while the invoice is
  still **unfiled** (shows «Αποστολή»), so the operator spots what needs sending NOW.
  Best-effort: no distinctly-named immediate field → nothing lights up (no false reds).
  One extra batch query per page (`griniarisByClient`).

## [0.39.0] — 2026-06-04
### Added
- **`op=custom_fields` (Plugin-API):** read-only catalogue of the WHMCS client
  custom fields (`id` + `fieldname` + `adminonly`) so ekdosi can MAP role→field
  via a picker instead of hand-typed integer ids. Powers the new Company-form
  «Άντληση & αντιστοίχιση πεδίων WHMCS» selector — the fix for the silently-empty
  `whmcs_custom_field_map` that made AFM + invoice-intent vanish from the inbox.

## [0.38.0] — 2026-06-03
### Fixed
- **Second-review batch** (regressions the first fix-batch introduced).
  `InvoiceMarkStore::ensureTable()` now clears the per-request `$columnCache` at
  the end — a fallback ALTER (on MariaDB without `ADD COLUMN IF NOT EXISTS`) could
  add a column AFTER `hasColumn()` cached it absent, dropping a same-request
  `state='cancelled'` write. Client-area PDF hook now uses one `row()` read.
  `inbound.php` logs a breadcrumb when it rejects a `pdf_url` on host mismatch
  (no more silent missing link). `row()` is null-safe for invoices with no mark.
  Stale `lastInboundPollAt()` docblock corrected.

## [0.37.0] — 2026-06-03
### Fixed
- **Independent-review batch.** (1) Cancelled («ΑΚΥΡΩΜΕΝΟ») invoice no longer
  shows the «Επίσημο παραστατικό» button (admin + client-area) — the public route
  also 404s it. (2) The «last inbound poll» freshness banner now counts ONLY the
  bulk `op=invoices` feed — a one-off push/`use-bridge` probe (`op=invoice`) can't
  falsely turn it green during a real outage. (3) `resolve.php` only logs a
  bridge-log row for a plausible bridge call (POST+body); a scanner GET / empty
  probe no longer writes rows (auth-failures 401/422 still log). (4) `pdf_url`
  write-back is host-validated against `ekdosi_base_url` (not just scheme) —
  blocks a trusted-looking phishing link. (5) client-area PDF URL is emitted via
  `json_encode` (correct JS-string context). (6) `InvoiceMarkStore`: per-request
  column-probe cache + single-row `row()` read (admin badge was 4 queries + 3
  probes → 1+cached). (7) `SchemaGuard::ensureSilently` runs the heavy path at
  most once per request.

## [0.36.0] — 2026-06-03
### Added
- **#5b — official PDF in the CLIENT AREA, behind a knob.** New addon config
  switch **«Show official PDF to customers»** (`show_pdf_client_area`, default
  OFF — admin-only). When ON, a «Επίσημο παραστατικό (ΑΑΔΕ)» button is injected
  on the client-area invoice view page (next to WHMCS's Download link) via two
  cooperating hooks (`ClientAreaPageViewInvoice` resolves the signed ekdosi PDF
  URL + re-checks ownership; `ClientAreaFooterOutput` injects the button) — no
  theme edit. Customers only ever see their own invoices' link.

## [0.35.0] — 2026-06-03
### Added
- **Official παραστατικό PDF link (`mod_ekdosi_invoice_marks.pdf_url`).** The
  write-back now carries a signed public URL to the invoice's PDF (hosted on
  ekdosi — source of truth, no file copy); `inbound.php` accepts `pdf_url`
  (http(s)-only) and the **admin manage-invoice** sidebar shows a «Επίσημο
  παραστατικό (ΑΑΔΕ)» button next to the MARK. New `pdf_url` column (SchemaGuard
  probe now expects invcode+state+pdf_url; idempotent ALTER, no reactivation);
  `InvoiceMarkStore::set()` + `pdfUrlFor()`. (Customer-area button is a separate
  follow-up.)

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
