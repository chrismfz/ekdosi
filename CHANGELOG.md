# Changelog — ekdosi

Notable changes to the **ekdosi app** (Laravel + Filament). Format:
[Keep a Changelog](https://keepachangelog.com/), **SemVer** `X.Y.Z` (app semantics:
major = milestone, minor = a new feature, patch = fixes). New work accrues under
`[Unreleased]`; a release is cut with `php artisan ekdosi:release` (auto-infers minor/patch
from `[Unreleased]`; `--major` explicit for milestones).

> **The WHMCS-side plugin has its own log:**
> `whmcs-plugin/ekdosi_bridge/CHANGELOG.md`.
> **Deep archive** (dated inspection notes, per-PR review logs):
> `docs/CLAUDE-history.md`. This file starts at 2026-06 — older history lives
> in that archive + git.
>
> **Discipline (from 2026-06 on):** every PR adds a line here under
> `[Unreleased]` (Added / Changed / Fixed / Removed). On a release cut, rename
> `[Unreleased]` to the dated/versioned heading.

## [Unreleased]

### Security
- **Περιορισμός endpoint παρόχου e-τιμολόγησης (PROV-017)** — το base URL του παρόχου
  (InvoSign) ήταν ελεύθερο κείμενο και ο transport έστελνε εκεί το token + το πλήρες XML
  τιμολογίου· ένα `http://`, ένα URL με `user:pass@`/query, ή ένα εσωτερικό host μπορούσε να
  διαρρεύσει διαπιστευτήρια/δεδομένα ή να χτυπήσει εσωτερική υπηρεσία (SSRF). Νέος
  `ProviderEndpointGuard` (μόνο public **https**, χωρίς userinfo/query/fragment, θύρα μόνο 443,
  όχι private/loopback/link-local host) επιβάλλεται στο **service choke-point** (`InvoSignTransport::resolve`
  — καλύπτει CLI/API), στο **provider preflight** και στη **φόρμα**. Επιπλέον, οι κλήσεις παρόχου
  γίνονται πλέον `withoutRedirecting()` ώστε κακόβουλο endpoint να μη μπορεί να ανακατευθύνει
  το token+payload αλλού. (Deferred hardening — TOCTOU DNS-pin, endpoint-profile registry — στο BACKLOG.)

### Changed
- **Ορολογία «Ψηφιακό Τέλος Συναλλαγής» + σωστές §8.x παραπομπές (MYD-020)** — τα
  operator-facing labels (φόρμα προϊόντος/παραστατικού, PDF, presets «Τυπικά τέλη/φόροι»)
  έλεγαν ακόμα «Χαρτόσημο»· πλέον χρησιμοποιούν τον ισχύοντα όρο **Ψηφιακό Τέλος
  Συναλλαγής** (taxType 4). Διορθώθηκαν και οι μετατοπισμένες παραπομπές ενοτήτων:
  **§8.5** Λοιποί Φόροι (type 3), **§8.6** Ψηφιακό Τέλος (type 4), **§8.7** Τέλη
  (type 2). Ευθυγραμμίστηκαν και τα docs καταλόγου (`FEATURES.md`/`docs/BACKLOG.md`)
  που ακόμα ανέφεραν «χαρτόσημο» ή «§8.5» για τα τέλη. Καμία αλλαγή στο payload ή στα
  columns `stamp_duty_*` (διατηρούνται για συμβατότητα δεδομένων· τα αποθηκευμένα ποσά
  εκπέμπουν το ίδιο σωστό taxType 4).

### Fixed
- **Αφαίρεση παραπλανητικού «ΤΔΑ» από το seed (MYD-002)** — ο seeded τύπος «ΤΔΑ /
  Δελτίο Αποστολής» υποσχόταν combined τιμολόγιο+δελτίο, αλλά εκδιδόταν ως σκέτο 1.1
  (χωρίς `isDeliveryNote`/movement data). Αφαιρέθηκε από το `INVOICE_TYPE_SEED` (fresh
  installs)· η πώληση 1.1 καλύπτεται από το ΤΙΜ. Υπάρχοντες tenants κρατούν το ΤΔΑ τους
  (ο seeder δεν διαγράφει) — μπορούν να το κρύψουν. Διορθώθηκε και το κείμενο του modal
  «Εισαγωγή τυπικών» που ακόμη διαφήμιζε τον αφαιρεμένο τύπο. Το πραγματικό combined
  ΤΔΑ → BACKLOG.
- **Μόνο υποστηριζόμενοι τύποι ΔΑ φιλάρονται — allowlist (MYD-012)** — το 9.1 (συσχετιζόμενο)
  προσφερόταν χωρίς μοντέλο συσχέτισης (correlated MARKs) και το 9.2 (συγκεντρωτικό)
  χωρίς μοντέλο σύνοψης· κανένα δεν φιλάρεται σωστά. Πλέον επιτρέπεται μόνο ό,τι μπορούμε
  να εκδώσουμε σήμερα μέσω **allowlist** `Codes::SUPPORTED_DELIVERY_TYPES = ['9.3']`
  (αντί για denylist 9.1/9.2 — έτσι ένα μελλοντικό 9.4 μένει κρυφό μέχρι να χτιστεί).
  Ο ίδιος κανόνας οδηγεί τον picker των Δελτίων Αποστολής **και** τον guard του
  `DeliveryNoteSubmitter` (χωρίς drift). Επιπλέον, το `defaultDeliveryTypeId()` ελέγχει
  ότι η συντόμευση «ΔΑΠ» δείχνει σε υποστηριζόμενο τύπο πριν το προεπιλέξει (μια σειρά
  ΔΑΠ κακο-χαρτογραφημένη σε 9.1 δεν γίνεται πια μη-εκδόσιμη προεπιλογή). Το πλήρες
  9.1/9.2 → BACKLOG.
- **Τα Δελτία Αποστολής (9.x) έξω από _όλη_ τη ροή τιμολογίων (MYD-003)** — οι movement-only
  τύποι 9.x εμφανίζονταν στον picker του παραστατικού και μπορούσαν να σταλούν από τον
  monetary builder. Πλέον αποκλείονται από **κάθε** monetary selector μέσω ενός κοινού
  `InvoiceType::scopeMonetary()` (null-safe, prefix `9.`): κύριος invoice picker
  (`PickerOptions`), μετατροπή προσφοράς σε Παραστατικό **και** σε Υπηρεσία (`ViewQuote`),
  τύπος ανανέωσης συμβολαίου (`ServiceContractForm`). Επιπλέον, **defence-in-depth στο
  χοκ-πόιντ**: ο `InvoiceNumberer::allocate()` —απ' όπου περνούν ΟΛΟΙ οι creators
  (CreateInvoice, IssueCreditNote, ConvertQuoteToInvoice, StageServiceRenewal,
  WhmcsInvoiceFiler)— πετάει σφάλμα για τύπο 9.x πριν το bump του μετρητή (χωρίς κενό ΑΑ),
  ώστε ούτε non-UI caller να μπορεί να εκδώσει κίνηση ως τιμολόγιο. Ο
  `AadeInvoiceDocument::build()` κρατά τον δικό του guard. Τα κινήσεως πάνε μόνο από τη
  ροή «Δελτία Αποστολής».
- **Έλεγχος ημερομηνίας έκδοσης για online έκδοση μέσω παρόχου (PROV-020)** — η κανονική
  online έκδοση μέσω InvoSign απαιτεί `IssueDate = σημερινή` (error 238), αλλά το Ekdosi
  δεχόταν backdated/future `issued_at` και το έστελνε — εγγυημένη απόρριψη. Νέος
  service-level guard (`ProviderIssueDateGuard`, ώρα Ελλάδας/Europe-Athens) στα provider
  paths (τιμολόγιο + δελτίο διακίνησης): μη-σημερινή ημερομηνία **μπλοκάρεται τοπικά πριν
  από κάθε outbound request**, με σαφές μήνυμα. Το direct myDATA (που δέχεται backdating
  εντός ορίων AADE) δεν επηρεάζεται· η νόμιμη offline/backdated οδός (Transmission Failure)
  παραμένει το PROV-008.
- **Έγκυρη μονάδα μέτρησης στα δελτία διακίνησης (MYD-016)** — ο submitter «διόρθωνε»
  σιωπηλά μια απούσα/άκυρη μονάδα σε 1 (τεμάχια), αλλάζοντας το νόημα της γραμμής (π.χ.
  κιλά → τεμάχια). Πλέον μια απούσα ή μη υποστηριζόμενη μονάδα (εκτός §8.13 1–6)
  **μπλοκάρει την υποβολή** με σαφές μήνυμα. Ο τύπος 7 (Τεμάχια_Λοιπές Περιπτώσεις) — που απαιτεί
  `otherMeasurementUnitQuantity/Title` (μη υλοποιημένα) — μπλοκάρεται ρητά στον submitter
  και δεν προσφέρεται πλέον σε νέες γραμμές (υπάρχουσες γραμμές με 7 εξακολουθούν να το
  εμφανίζουν, ώστε ένα edit να μην το χάνει σιωπηλά)· το full support παραμένει στο BACKLOG.
- **Υποχρεωτικός τρόπος μεταφοράς στην έναρξη διακίνησης (MYD-013)** — το
  `DeliveryLifecycleService::registerTransfer` παρέλειπε σιωπηλά έναν άκυρο/κενό
  `transportType` (κατέληγε σε απόρριψη από AADE) και φίλαρε placeholder αριθμό
  μεταφορικού. Πλέον το service (και όχι μόνο η φόρμα) **απαιτεί** έγκυρο
  transportType 1–7 και αριθμό μεταφορικού μέσου για κάθε τύπο εκτός του 7 «Άνευ»,
  με σαφές τοπικό μήνυμα — καλύπτει και τους non-UI callers (console/API/import).
- **Ετικέτα ΦΠΑ κωδικού 10 (MYD-004, μερικό)** — ο §8.2 κωδικός 10 εμφανιζόταν ως
  «ΦΠΑ νήσων 4%», ενώ το επίσημο table τον λέει «ΦΠΑ συντελεστής 4% (αρ.31
  ν.5057/2023)» — χωρίς «νήσων» (οι νησιωτικοί μειωμένοι είναι οι κωδικοί 4/5/6).
  Διορθώθηκε η ετικέτα + τα σχετικά σχόλια. (Το υπόλοιπο MYD-004 — κοινός VAT
  resolver, seed κωδικού 9, ρητή επιλογή 6-vs-10, blocking 0% — παραμένει ανοιχτό.)
- **myDATA E3 code για τρίτες χώρες (MYD-001)** — οι πωλήσεις εμπορευμάτων/υπηρεσιών
  προς τρίτες χώρες (τύποι 1.3 / 2.3) κατατάσσονταν λανθασμένα με τον ενδοκοινοτικό
  κωδικό `E3_561_005`· τώρα χρησιμοποιούν τον σωστό `E3_561_006` («Εξωτερικού Τρίτων
  Χωρών»). Τα 1.2 / 2.2 (ενδοκοινοτικά) παραμένουν σε `E3_561_005`. Αφορά fresh seeds
  (η fill-empty λογική του seeder δεν πατάει operator edits).
- **Πρόσημο επιστροφής POS 8.5 στην «Εικόνα από myDATA» (MYD-015)** — ο τύπος 8.5
  (Απόδειξη Επιστροφής POS) προστίθετο με θετικό πρόσημο, φουσκώνοντας έσοδα/ΦΠΑ εκροών
  αντί να τα μειώνει. Πλέον μετράει αρνητικά στην εικόνα myDATA μέσω του `documentSign`
  (νέα λίστα `Codes::REDUCING_EXTRA_TYPES`, ξεχωριστή από το `CREDIT_NOTE_TYPES` ώστε η
  ταυτότητα του πιστωτικού να μένει ακριβής). Μια είσπραξη 100€ (8.4) με επιστροφή 40€
  (8.5) δίνει καθαρά 60€, όχι 140€.

## [1.15.0] - 2026-08-30

### Added
- **In-app updates + one-click rollback from GitHub (Phase 2)** — a super_admin «Εγκατάσταση ενημέρωσης»
  action on «Υγεία συστήματος» applies a new release from the panel: DB snapshot → maintenance →
  `git checkout` → `composer install` (from the committed lock — never `composer update`) → `migrate`
  → `optimize` → shield → `queue:restart` → opcache flush → `ops:health`. **Shared-hosting-first:
  NO sudo/systemd/root** — runs as the app's own user, applied out-of-band by the cron scheduler
  (`ekdosi:self-update`, gated on a queued run) so the app can restart itself safely. New `UpdateRun`
  model + super_admin `UpdateRuns` resource (live-poll progress + phase + captured output + history);
  signed `/internal/opcache-flush` route; token-authenticated `git fetch` (one PAT covers the check +
  the pull). No arming flag — the button appears whenever an update is visible (for a private repo
  that needs a valid token, so «URL/token → yes»); every apply is super_admin-only + confirmed.
  `EKDOSI_UPDATE_STRATEGY` = `php` (portable) or `script` (wrap `deploy/update.sh` on a VPS). **Phase B:
  one-click «Επαναφορά»** — reverts a finished (or failed) update by checking out the previous commit
  and restoring the pre-update DB snapshot (destructive, red-confirmed; takes a safety snapshot first).
  A failed apply lifts maintenance so the operator can reach the panel to roll back. Design:
  `docs/versioning-and-updates.md`.
- **Περισσότερες ρυθμίσεις από το UI** ώστε μια φρέσκια εγκατάσταση να μη χρειάζεται
  `.env` edit + redeploy:
  - **Σύστημα → Ρυθμίσεις συστήματος**: Ειδοποιήσεις σφαλμάτων (on/off + email + throttle),
    AI «Βοηθός» καθολικός διακόπτης, Έλεγχος ενημερώσεων on/off.
  - **Σύστημα → Χρονοπρογραμματιστής**: νέα ενότητα «Χρονισμός» — cron (5 πεδίων) ή ώρα
    ΩΩ:ΛΛ ανά εργασία, με validation στο save **και** ασφαλές fallback στο
    `routes/console.php` (μη έγκυρη τιμή αγνοείται → προεπιλογή· μια χαλασμένη row δεν
    σπάει τον scheduler).
  Ίδιο μοτίβο παντού: env/config = default, DB row (`system_settings`) = override,
  επαναφορά στην προεπιλογή σβήνει τη row, audited. Οι readers (ExceptionNotifier,
  ExceptionAlertRecipients, AssistantRunner, Assistant, UpdateChecker, ScheduleTiming)
  διαβάζουν όντως το override — όχι διακοσμητικοί διακόπτες.

### Changed
- **«Επανυπολογισμός υπολοίπων» μετακόμισε στη λίστα Παραστατικά.** Ήταν το μοναδικό
  κουμπί που είχε απομείνει στην άδεια σελίδα «Εργαλεία» (τα myDATA εργαλεία της είχαν
  ήδη φύγει στην Κονσόλα myDATA)· τώρα είναι header action στη λίστα Παραστατικά, εκεί
  που ζουν τα χρήματα. Ίδια ασφαλής/idempotent εντολή (`invoices:recompute-balances`
  για την τρέχουσα εταιρία), gated admin-only (`View:CompanySettings` — ίδιο κοινό).
- **`.env.example`**: τα knobs που πλέον έχουν UI (error-alerts, AI master switch,
  update-check on/off) έγιναν pointers προς το UI — env = μόνο τα defaults.

### Removed
- **Σελίδα «Εργαλεία» (`MaintenanceTools`)** + το blade της — κέλυφος με ένα κουμπί,
  που μετακόμισε (πάνω). Μετά το deploy: `shield:generate` + `shield:sync-super-admin`
  (φεύγει το πλέον αχρησιμοποίητο `View:MaintenanceTools` permission).
- **Stale `EKDOSI_MCP_ENABLED`** από το `.env.example` — το `/mcp` endpoint είναι
  σκόπιμα πάντα-ενεργό (auth-protected, `routes/ai.php`)· κανείς δεν διάβαζε το flag
  (το config key ήταν ήδη αφαιρεμένο).

## [1.14.0] - 2026-08-26

### Changed
- **`.env.example` σε δίαιτα (239 → 154 γραμμές).** Αφαιρέθηκε dead-weight που η app δεν διαβάζει
  ποτέ (Redis/Memcached, τα live-κενά `AWS_*`, `BROADCAST_CONNECTION`, `APP_MAINTENANCE_*`,
  `PHP_CLI_SERVER_WORKERS`, `VITE_APP_NAME`)· συμπτύχθηκε ο **διπλογραμμένος** `EKDOSI_SCHEDULE_*`
  block· τα knobs που ρυθμίζονται πλέον από το UI (scheduler on/off, υποχρεωτικό 2FA, ειδοποίηση
  backup) έγιναν pointer προς «Σύστημα → Χρονοπρογραμματιστής / Ρυθμίσεις συστήματος». Locale
  defaults → `el`/`el_GR` (καθαρά ελληνική εφαρμογή· ίδια τιμή με τον installer). Μια φρέσκια
  εγκατάσταση δεν χρειάζεται `.env` edit γι' αυτά — μόνο τα core keys που γράφει ο installer.
### Added
- **MCP per-company selection (`company` / `company: "all"`).** The tenant-scoped MCP tools now take
  an optional `company` (slug) — or `"all"` to fan out across every company the caller may access
  (per-company map, no merge) — so a super_admin can drive any/all companies over one (claude.ai/OAuth)
  connection, cfm-style (`node`/`node="all"`). Selection is validated server-side (`McpTenantResolver`,
  member → own only, super_admin → all), never trusted from prose; a `--tenant`-bound Sanctum token
  stays locked to its company. New `list_companies` tool lists the valid slugs. Write tools refuse
  `"all"` (blast-radius) and stay propose-only. The in-app «Βοηθός» is unchanged (session tenant).

### Changed
- **MCP endpoint is now always-on** — removed the `EKDOSI_MCP_ENABLED` kill-switch (and its config
  block). Access is already gated by auth (a token is required) and by `class_exists` (needs
  `laravel/mcp`), so the flag only added a foot-gun. Delete the `.env` line; it is now ignored.

### Fixed
- **`clean.sh` aborted every deploy that followed a nightly backup.** The step-1b "sudden
  collapse" guard compared the new zip against *the previous zip in the folder*, but that folder
  holds two kinds: the nightly cron backup (files+DB, ~44 MB) and clean.sh's own pre-deploy one
  (`--only-db`, ~1.4 MB). A db-only backup next to a full one always read as a 97% collapse →
  false-positive abort, leaving the deploy half-applied (new code, stale caches → 500s). The guard
  now classifies each zip (db-only = nothing but `db-dumps/` entries) and compares against the newest
  older backup **of the same kind**.
- **Operator health disk probe reported the backups directory as "missing".** It hard-coded
  `storage/app/{name}` while spatie backups land under Laravel 11's `local` disk root
  (`storage/app/private/{name}`). Both `OperatorHealthReport::disk()` and `localBackups()` now resolve
  the path the same way (shared `backupRoot()`), so `app_health`/`ops:health` show real backup disk use.
- **Filament 5 regression: `Filament\Notifications\Actions\Action` was removed** — three call sites
  still imported it and threw «Class not found» when they built a bell notification with an action:
  `invoices:notify-overdue` (failing daily on the scheduler since the Filament 5 upgrade),
  `AiActionExecutor` (AI «Βοηθός» reminder delivery), and the per-company backup «Λήψη» notification.
  All now use `Filament\Actions\Action` (unified actions). Added a non-dry-run regression test that
  exercises the send path (the existing test only covered `--dry-run`, which skips the action).

## [1.13.0] - 2026-08-26

### Added
- **External MCP server** (`POST /mcp`, `EkdosiMcpServer`) — exposes the SAME tenant-safe tool
  registry as the in-app «Βοηθός» to external MCP clients (Claude Desktop, the claude.ai connector,
  another agent), so ekdosi is drivable from outside the panel too. Auth is universal (Sanctum bearer
  always; OAuth 2.1 once Passport is installed), the company is **bound to the token**
  (`ekdosi:mcp-token --tenant=slug`, `McpTenantResolver`) never named by the model, and per-tool
  Shield permission still applies. Business tools are the existing ones over a thin adapter
  (`AssistantMcpTool`); the two write tools are **propose-only** (stage an `AiPendingAction` the
  operator confirms in-app). New ops/debug tools for remote troubleshooting: `app_health`
  (`ops:health`), `failed_jobs` (exception heads), `log_tail` (level/substring filters) —
  super_admin, read-only. New state tools `app_version` (update-available) and `recent_activity`
  (audit trail) work on both channels. Hard kill-switch `EKDOSI_MCP_ENABLED` (default OFF). See `MCP.md`.
- **Web-based first-run installer** (`/install`). Drop the files on a fresh host (empty VM or
  cPanel/DirectAdmin) with only an empty DB + user created, and visiting the URL runs a wizard that
  collects the `.env` basics (app name/URL/env/locale/timezone + DB + optional SMTP), generates the
  `APP_KEY`, tests the DB connection live («Δοκιμή σύνδεσης»), builds the schema (`migrate`), wires
  Shield + the standard Greek AADE lookups, and creates the first super-admin + company — then shows an
  OS-level post-install checklist (cron, queue worker, PHP extensions). **Fail-closed & self-disabling:**
  gated by `EnsureInstalled` global middleware that runs before the session/APP_KEY stack — it routes a
  pristine host into the wizard and, once an `APP_KEY` exists (or a completion marker is dropped), makes
  `/install` permanently inert (302 → `/admin`). A **filesystem-token gate** (a file written under
  `storage/app/install/`, re-verified on every POST) proves server access, and the migrate step **refuses
  any non-empty target database by default** (a foreign DB — WHMCS, another app — or a partial prior
  attempt; explicit operator override to finish a partial). Per-tenant secrets (myDATA/WHMCS/GSIS) stay
  out of the installer — set later per-company in the panel.
- **Installer preflight «Έλεγχος συστήματος»** — a read-only requirements check at the top of `/install`
  that never changes anything (the privileged `composer install`/extension-enabling stays at the shell;
  the installer only verifies the result). **Hard** requirements (PHP ≥ 8.4, writable `storage/` +
  `bootstrap/cache/`, and the extensions a normal panel/issue flow ERRORS without: `pdo_mysql`,
  `mbstring`, `openssl`, `ctype`, `tokenizer`, `dom`, `xml`, `fileinfo`, `intl` — Filament `->money()`
  throws without it — and `soap`) render red and **disable the «Εγκατάσταση» button**, with the fix
  command shown per row; the `run()` POST re-checks them server-side and refuses before touching the DB.
  **Soft** requirements only warn and name the one feature that won't work — `pdo_firebird` → Firebird
  ETL, `gd` → the printed QR (the invoice still issues without it), `curl` → HTTP has a stream fallback,
  `zip` → backups, `bcmath`, `proc_open`, the upload/memory ini ceilings for imports, HTTPS.

### Changed
- **Domains design doc** (`docs/domains/README.md`, πυλώνας A — still pre-build): αδέσποτα
  (un-assigned) domains ως νόμιμη κατάσταση (`customer_id` nullable, εκτός billing μέχρι ανάθεση)
  + action «Ανάθεση σε πελάτη», νέα στήλη `transferred_at`, tab «Domains» στην καρτέλα πελάτη
  (`DomainsRelationManager`), import που δεν μπλοκάρει σε unmatched πελάτες.

## [1.12.1] - 2026-07-13

### Fixed
- **`ekdosi:release` read the current version from the CACHED config** (`config('app.version')`), so on
  a deploy box with a stale `config:cache` it computed the wrong base — once trying to bump 1.12.0 →
  «1.11.1» (a downgrade). It now parses the version from `config/app.php` directly (the same file it
  writes), cache-immune, with a `config()` fallback.

### Changed
- **`tag-release.sh --tag` is now one safe atomic step** (was: manual `ekdosi:release` → commit →
  tag, which let you tag BEFORE committing → a tag on the wrong commit, e.g. `v1.12.0` on a `1.11.0`
  commit). `--tag` now: pull main → cut the release if `[Unreleased]` has changes → commit → preview
  + confirm (`-y` skips) → push main → tag → push. Refuses on a dirty tree and verifies
  `HEAD:config/app.php` == the tag version before tagging. Bare `sh tag-release.sh` stays read-only.

## [1.12.0] - 2026-07-13

### Added
- **Firebird import: ζωντανή σύνδεση (host/credentials) + «Έλεγχος σύνδεσης».** Πέρα από το ανέβασμα
  `.fbk`/`.fdb`, νέο tab «Ζωντανή σύνδεση» στη φόρμα εισαγωγής που συνδέεται **απευθείας** στη ζωντανή
  legacy Firebird (IP + διαπιστευτήρια + διαδρομή `.fdb` στον remote) — χωρίς gbak/upload. Κουμπί
  **«Έλεγχος σύνδεσης»** που, πριν το import, επιβεβαιώνει σύνδεση/πόρτα/διαπιστευτήρια και **μετρά τους
  βασικούς legacy πίνακες** (CUSTOMER/INVTYPE/INVOICE/PRODUCT) → «✅ X πελάτες, Y τιμολόγια», με σαφή
  διάγνωση σφάλματος (λείπει pdo_firebird / auth / unreachable / λάθος βάση). Μόνο ανάγνωση· ο κωδικός
  μένει στη μνήμη (ποτέ στη γραμμή εισαγωγής). Το job τρέχει `migrate:firebird --host --fdb` απευθείας.
- **WHMCS: outbound σήμανση πληρωμένου (Phase 2 — ekdosi → WHMCS mark-paid).** Όταν ένα επί-πιστώσει
  τιμολόγιο εξοφληθεί **στο ekdosi**, το WHMCS του πελάτη σημαίνεται πληρωμένο (`AddInvoicePayment`) —
  **αυτόματα** (queued `PushWhmcsPaymentJob` μόλις κλείσει η οφειλή) **και** με κουμπί «Σήμανση Paid στο
  WHMCS» (στο τιμολόγιο + στη λίστα «Προς ενημέρωση» της σελίδας «Συγχρονισμός πληρωμών»). Γράφει σε
  εξωτερικό σύστημα, οπότε **opt-in ανά tenant** (`companies.whmcs_push_payments`, default OFF) με τέσσερα
  φρένα: idempotent (marker `whmcs_payment_pushed_at` + claim-before-write + `ekdosi-paid:{id}` transid),
  **anti-echo** (ποτέ δεν γυρίζει πίσω πληρωμή που ήρθε ΑΠΟ το WHMCS), live/credit-term-only, query-first
  (skip αν το WHMCS το έχει ήδη Paid). Native `AddInvoicePayment` για native tenants· bridge plugin
  `op=add_payment` (v0.43.0) για bridge tenants. Dashboard tile + σελίδα δείχνουν και τις δύο κατευθύνσεις.
- **WHMCS: κεντρικός «Συγχρονισμός πληρωμών» (Phase 1 — inbound εντοπισμός).** Νέα σελίδα
  «Συγχρονισμός πληρωμών» (ομάδα Data) + dashboard tile που δείχνουν **εύκαιρα** ποια ανοιχτά
  (επί πιστώσει) τιμολόγια έχει πλέον πληρώσει το WHMCS, ώστε ο χειριστής να κλείνει την οφειλή με
  **ένα κλικ** («Καταγραφή πληρωμής» — επιβεβαιώνει ζωντανά στο WHMCS και γράφει **μόνο στο ekdosi**,
  ίδια idempotent/only-if-open λογική). Ο εντοπισμός γίνεται από read-only `whmcs:reconcile-payments`
  (scheduled, default OFF) που κασάρει τη worklist (μόνο invoice-ids, ποτέ ποσά) και στέλνει
  **durable bell notification** για κάθε νέα εκκρεμότητα. Καμία εγγραφή χρήματος στον εντοπισμό —
  και το outbound σκέλος (ekdosi → WHMCS mark-paid) έρχεται στη Φάση 2.

## [1.11.0] - 2026-07-13

### Added
- **WHMCS γέφυρα: inbound συγχρονισμός πληρωμών (WHMCS → ekdosi).** Όταν ένα τιμολόγιο που εκδόθηκε
  **επί πιστώσει** (ανοιχτή οφειλή — η ροή «τιμολόγιο πρώτα, πληρωμή μετά») πληρωθεί στο WHMCS, η
  προγραμματισμένη `whmcs:sync-payments` **κλείνει την οφειλή στο ekdosi** καταγράφοντας Payment για
  το ανοιχτό υπόλοιπο. Poll-based (καμία αλλαγή plugin)· money-write **μόνο στο ekdosi** (ποτέ στο
  WHMCS του πελάτη)· idempotent (**only-if-open** — δεν over-pay-άρει ποτέ cash-term/εξοφλημένο — +
  dedup `transaction_id`). **Ανάβει/σβήνει από το UI** («Ρυθμίσεις χρονοπρογραμματιστή», default OFF)
  — κανένας operator δεν χρειάζεται κονσόλα· η καταγεγραμμένη πληρωμή φαίνεται στην Καρτέλα/τιμολόγιο
  και το task στο `ops:health`/health page. **Δύο on-demand κουμπιά** (χωρίς αναμονή για το cron):
  (α) στο WHMCS inbox, header action «Συγχρονισμός πληρωμών τώρα» (bulk για τον πελάτη)· (β) στο
  τιμολόγιο, «Έχει πληρωθεί στο WHMCS;» πάνω σε ανοιχτό (επί πιστώσει) WHMCS-συνδεδεμένο παραστατικό —
  ίδια idempotent/only-if-open λογική, ασφαλή σε επαναλαμβανόμενο κλικ. Το outbound σκέλος (ekdosi
  payment → WHMCS mark-paid) παραμένει design-only στο BACKLOG.

## [1.10.0] - 2026-07-13

### Added
- **WHMCS γέφυρα: paid/unpaid-aware τιμολόγηση (Phase 1 — inbound).** Το «Δημιουργία Παραστατικού»
  προ-επιλέγει πλέον τον τύπο βάσει της **κατάστασης πληρωμής του WHMCS τιμολογίου**: ΑΠΛΗΡΩΤΟ →
  νέος «Προεπιλεγμένος τύπος για ΑΠΛΗΡΩΤΑ (επί πιστώσει)» ώστε να μείνει σωστά **ανοιχτή οφειλή**
  (π.χ. Α.Ε./Δημόσιο που θέλει πρώτα τιμολόγιο)· ΠΛΗΡΩΜΕΝΟ → cash-term τύπος (τιμολόγιο/απόδειξη κατά
  πρόθεση), εξοφλημένο στην έκδοση. Ο χειριστής πάντα κάνει override. **Badge «Πληρωμή WHMCS»
  (Πληρωμένο/Απλήρωτο)** στο inbox + ρητό σήμα «ΑΠΛΗΡΩΤΟ» μέσα στο modal «Δημιουργία Παραστατικού»·
  το tripwire της καρτέλας WHMCS ελέγχει τώρα και το unpaid-slot (warn αν είναι cash-term). **Το
  `whmcs:auto-issue` πλέον ΚΡΑΤΑ (hold) ρητά τα ΑΠΛΗΡΩΤΑ rows** (paid-only enforced στον κώδικα, όχι
  μόνο μέσω του staging feed) — ένα απλήρωτο δεν εκδίδεται ποτέ αυτόματα ως εξοφλημένο. (Phase 2 —
  Ekdosi payment → WHMCS mark-paid — παραμένει design-only στο BACKLOG.)

## [1.9.0] - 2026-07-13

### Added
- **Άντληση εξόδων από myDATA: επιλογή διαστήματος.** Το picker modal ξεκινά στο τρέχον τρίμηνο
  (όπως πριν), αλλά έχει πλέον dropdown «Διάστημα» (τρέχον/προηγούμενο τρίμηνο, τρέχον/προηγούμενο
  εξάμηνο, τρέχον/προηγούμενο έτος) που κάνει live re-fetch στη θέση του — ώστε αν το τρίμηνο είναι
  κενό να φέρεις π.χ. όλη τη χρονιά χωρίς να φύγεις από το modal. Κοινός `ExpensePickerWindow` για τα
  calendar boundaries (semester-safe).

## [1.8.0] - 2026-07-13

### Added
- **WHMCS καρτέλα: ρητή δήλωση + ζωντανός έλεγχος «0 ημέρες πίστωσης» στους προεπιλεγμένους τύπους.**
  Οι δύο selectors (τύπος τιμολογίου/απόδειξης αυτόματης έκδοσης) εξηγούν πλέον ΓΙΑΤΙ ο τρόπος πληρωμής
  τους πρέπει να είναι cash-term, και μια ζωντανή προειδοποίηση ανάβει αν ο επιλεγμένος τύπος έχει
  `due_days > 0` (τα ήδη-πληρωμένα WHMCS τιμολόγια θα εμφανίζονταν ως ανοιχτές οφειλές). Cash-term
  (0 ημέρες Ή κανένας τρόπος) = καθαρό. Από το pre-go-live audit (finding F1).

### Fixed
- **WHMCS inbox: `whmcs:fetch-pending --limit>ceiling` δεν «παγώνει» πλέον στην 1η σελίδα.** Το
  `WhmcsClient::getPendingInvoices` σταματούσε σε short page (`returned < limit`) — αν ο caller
  έδινε limit πάνω από το server-side page ceiling (~100), η σελίδα 1 γύριζε ceiling < limit και
  ο walk κοβόταν σε μία σελίδα (η κλάση WH-6 «stuck at N»). Τερματίζει πλέον μόνο σε ΚΕΝΗ σελίδα,
  όπως το αδελφό `getInvoicesForClient`. + regression test.
- **WHMCS ingest: concurrent webhooks δεν βγάζουν πια spurious 500 σε MariaDB.** Δύο ταυτόχρονα
  ingest του ίδιου `(company_id, whmcs_invoice_id)` υπό REPEATABLE READ (default της MariaDB)
  κάνουν InnoDB deadlock (όχι unique-violation) που ξέφευγε ως 500· το `DB::transaction(..., 3)`
  κάνει retry — στο retry η γραμμή υπάρχει → clean existing-row path. Το unique index απέτρεπε
  πάντα διπλή γραμμή· αυτό διορθώνει μόνο τον θόρυβο/500.

## [1.7.0] - 2026-07-13

### Added
- **Έξοδα: «Σημειώσεις» ανά παραστατικό.** Ιδιωτικές σημειώσεις χειριστή (π.χ. «εισιτήριο Aegean»),
  διαθέσιμες και στα read-only έξοδα από myDATA — γράφουν ΜΟΝΟ το πεδίο `notes`, χωρίς να θίγουν τα
  δεδομένα-καθρέφτη της ΑΑΔΕ (το πλήρες Edit μένει σκόπιμα μόνο για χειροκίνητα έξοδα). Εμφανίζονται
  στην προβολή εξόδου.
- **Συμπλήρωση επωνυμιών «αδέσποτων» προμηθευτών από ΑΑΔΕ** — κουμπί «Συμπλήρωση επωνυμιών από
  ΑΑΔΕ» στη λίστα Προμηθευτές (προσβάσιμο από operators, bounded batch ανά κλικ) + CLI
  **`suppliers:backfill-names`** για μαζική σάρωση· γεμίζει επωνυμία/ΔΟΥ/διεύθυνση σε προμηθευτές
  που είχαν δημιουργηθεί μόνο με ΑΦΜ πριν το fix (fill-only-empty, best-effort). Κοινή υπηρεσία
  `SupplierNameBackfiller`.
- **Per-company διακόπτης «Αυτόματη άντληση εξόδων»** στις «Ρυθμίσεις εταιρείας» (ο company_admin
  ελέγχει τον ΔΙΚΟ του tenant). Two-key με τον καθολικό διακόπτη του χρονοπρογραμματιστή: όταν τρέχει
  η read-only εργασία `mydata:refresh-expenses --auto-only`, ανανεώνει ΜΟΝΟ τους tenants που το
  άναψαν (`companies.mydata_auto_fetch_expenses`, default OFF). Χειροκίνητο τρέξιμο ανανεώνει όλους.

### Fixed
- **Άντληση εξόδων από myDATA: νέος ΕΛ προμηθευτής ερχόταν χωρίς επωνυμία (μόνο ΑΦΜ → «παύλα»).**
  Το ελληνικό myDATA παραστατικό δεν φέρει το όνομα του εκδότη ([219]/[220]) — μόνο το ΑΦΜ. Ο
  `ExpenseImporter` πλέον αντλεί την ταυτότητα από το μητρώο ΑΑΔΕ (GSIS) όταν δημιουργεί νέο ΕΛ
  προμηθευτή, όπως ήδη κάνει ο συγχρονισμός προμηθευτών· best-effort (σε αποτυχία GSIS δημιουργείται
  ο προμηθευτής μόνο με ΑΦΜ). Κοινός `SupplierGsisEnricher` για importer + sync.

## [1.6.0] - 2026-07-12

### Added
- **Αναφορές: «Εισπράξεις ανά μήνα — φέτος vs πέρσι».** Ταμειακή εικόνα ανά μήνα (με βάση την ημερομηνία
  είσπραξης, καθαρά από επιστροφές), το επιλεγμένο έτος vs το έτος σύγκρισης — για να ξεχωρίζουν εύκολα οι
  εποχικά αδύναμοι μήνες (π.χ. καλοκαίρι).
- **Αναφορές: «ΦΠΑ εκροών ανά συντελεστή × τρίμηνο».** Βοηθητικός πίνακας για την περιοδική δήλωση ΦΠΑ:
  φορολογητέα βάση + ΦΠΑ ανά 24/13/6/0%, ανά τρίμηνο, καθαρά από πιστωτικά (ίδια output-VAT σημασιολογία
  με το `VatPeriodReport`, σπασμένη ανά συντελεστή). Δεν αποτελεί επίσημη δήλωση.

### Changed
- **Πίνακας ελέγχου: αφαιρέθηκε ο επιλογέας «Περίοδος».** Δεν οδηγούσε πλέον τίποτα ορατό — οι «κάρτες
  περιόδου» είχαν αφαιρεθεί, και τα δύο γραφήματα (Έσοδα ανά μήνα / Σύγκριση ετών) είναι αυτο-αγκυρωμένα
  στο «τώρα» (trailing 12 μήνες / φέτος vs πέρσι). Η φιλτραρόμενη-ανά-έτος ανάλυση ζει ήδη στη σελίδα
  «Αναφορές & Στατιστικά».

### Removed
- Νεκρός κώδικας `App\Support\Dashboard\PeriodFilter` (+ το test του) — μοναδικός καταναλωτής ήταν ο
  αφαιρεμένος επιλογέας περιόδου του dashboard.

## [1.5.0] - 2026-07-12

### Added
- **Widget «Καθημερινές εργασίες» (γρήγορες ενέργειες) στην κορυφή του Πίνακα ελέγχου.** Curated launch
  panel με τις 8 πιο συχνές ενέργειες του χειριστή: Νέο Παραστατικό / Νέα Είσπραξη / Νέα Προσφορά / Νέος
  Πελάτης + WHMCS Εισερχόμενα (με badge εκκρεμών) / Παραστατικά / Κονσόλα myDATA / Ηλικίωση οφειλών. Κάθε
  κουμπί είναι permission-gated στο ίδιο δικαίωμα με τον προορισμό του (`canCreate`/`canViewAny`/page
  `canAccess`), οπότε ένας χειριστής βλέπει μόνο ό,τι μπορεί όντως να κάνει. Στατικό/curated (per-user
  bookmarks = σκόπιμο follow-up, δεν χτίστηκε).

## [1.4.0] - 2026-07-12

### Added
- **Ορατότητα αρχείων αντιγράφων ΒΔ στην «Υγεία συστήματος».** Πέρα από «το τελευταίο είναι φρέσκο»,
  η σελίδα δείχνει πλέον τα ίδια τα spatie αρχεία: **φάκελο** (`storage/app/private/…`), **πλήθος**,
  **συνολικό μέγεθος** και τα πιο πρόσφατα με **όνομα · μέγεθος · timestamp** (`OperatorHealthReport::
  localBackups`). Ο «Χρονοπρογραμματιστής» απέκτησε header-action **«Αρχεία αντιγράφων (Υγεία)»** που
  δείχνει στο section (#backups). Τα per-tenant backup runs ήταν ήδη ορατά στην καρτέλα κάθε εταιρίας.

## [1.3.1] - 2026-07-12

### Changed
- **`ekdosi:release` βγάζει μόνο του το επίπεδο έκδοσης.** Χωρίς flag, διαβάζει το CHANGELOG
  `[Unreleased]` και επιλέγει **minor** αν υπάρχει `### Added`, αλλιώς **patch** (το `--major` μένει
  ρητό για milestones· `--minor`/`--patch` παρακάμπτουν). Νέα `--check` (μη-καταστροφικό preflight —
  μπήκε στο `clean.sh` βήμα 5 ώστε ένα ξεχασμένο bump να φαίνεται στο deploy) και `--commit --tag`
  (κάνει και το git commit + tag, χωρίς push). Τέλος στο «διάλεξε λάθος επίπεδο» και στο «μείναμε
  στην ίδια έκδοση».
- **`tag-release.sh` — post-merge tagger.** Σκέτο δείχνει **κατάσταση + επιλογές** (τρέχουσα έκδοση,
  αν υπάρχει το tag, branch, αδημοσίευτες αλλαγές)· με `--tag` κάνει pull `main` + δημιουργεί & push
  το `vX.Y.Z` διαβάζοντας την έκδοση από το `config/app.php` (καμία πληκτρολόγηση αριθμού, idempotent).
  Αντικαθιστά το χειροκίνητο `git checkout main && git pull && git tag … && git push --tags`.

## [1.3.0] - 2026-07-12

### Added
- **Φίλτρα υπολοίπου + προτιμολογίων στη λίστα πελατών.** Νέο φίλτρο **Υπόλοιπο** (Χρεωστικοί «μας
  χρωστάνε» / Πιστωτικοί «έχουν πίστωση» / Μηδενικό) που αντικαθιστά το παλιό δυαδικό «Με/Χωρίς
  υπόλοιπο», και φίλτρο **Ανοιχτά προτιμολόγια** (πελάτες με ≥1 πρόχειρο). Ίδια μαθηματικά υπολοίπου
  με το dashboard· το drill-down της κάρτας «Ανεξόφλητα» δείχνει πλέον στο `balance_status=debtor`.

## [1.2.0] - 2026-07-12

### Added
- **Ιστορικό email — per-customer + γενικό (tenant-wide).** Πέρα από το per-invoice ιστορικό
  (ViewInvoice), κάθε πελάτης έχει πλέον tab «Ιστορικό email» (όλες οι αποστολές τιμολογίων του,
  μέσω `Customer::invoiceMailLog`), και υπάρχει read-only σελίδα «Ιστορικό email» για ΟΛΟ τον tenant
  (`InvoiceMailLogResource`) με φίλτρα κατάστασης/τρόπου. Δείχνει παραλήπτη, θέμα, κατάσταση
  (στάλθηκε/απέτυχε/…), χρόνους, ποιος έστειλε — read-only (γράφει μόνο το job). Νέο resource →
  τρέξε `shield:generate` μετά το deploy.
- **Build stamp + read-only έλεγχος ενημερώσεων.** Δίπλα στο όνομα της εφαρμογής (και στο
  `php artisan ekdosi:version`) εμφανίζεται πλέον η ταυτότητα του deployed build: `v{SemVer} ·
  2026.07.11-150101 (sha)` — το build stamp παράγεται **αυτόματα** από το git commit στο deploy
  (`deploy/update.sh` → `storage/app/build.json`, ώρα Ελλάδας), χωρίς per-PR συντήρηση· fallback σε
  live git (dev). Το SemVer μένει σκόπιμο (`ekdosi:release`). Νέα σελίδα «Υγεία συστήματος» δείχνει
  read-only αν υπάρχει νεότερη έκδοση στο GitHub («N commits πίσω», link) — cached 6h, graceful offline,
  **ποτέ apply** (η αναβάθμιση μένει στο `deploy/update.sh`). Config: `config/ekdosi.php → updates`.
  Λεπτομέρειες + η απόφαση «όχι in-app file-swap updater»: `docs/versioning-and-updates.md`.
- **Πλακίδιο «Πρόχειρα (προτιμολόγια)» στο dashboard** (MON-5) — count + αξία των unissued sale-drafts
  (που πλέον ΔΕΝ μετρούν στα έσοδα/εισπρακτέα), clickable στη λίστα φιλτραρισμένη σε πρόχειρα. Ώστε ο
  χειριστής να βλέπει πάντα πόσα πρόχειρα υπάρχουν και την αξία τους — η pro-forma ουρά για μελλοντικό
  service-manager. Νέο `DashboardMetrics::draftsPipeline()`.

### Security
- **AUDIT DOC-8 — markdown injection στο σώμα του email τιμολογίου.** Το body είναι markdown mailable,
  οπότε ένα όνομα πελάτη `[x](http://…)` γινόταν live link (το `e()` κάνει escape HTML, όχι markdown).
  Ο `MailTemplateRenderer` κάνει πλέον backslash-escape του ASCII-punctuation στις interpolated **τιμές**
  (όχι στο operator template ούτε στο verify_url/mark_section)· το subject (plain text) δεν αγγίζεται.

### Fixed
- **AUDIT OPS-15 — το go-live backup gate απαιτεί απόδειξη, όχι μόνο toggle.** Το
  `ekdosi:go-live-check` per-tenant backup gate δεν περνάει πλέον με σκέτο «enabled»: ζητά πρόσφατο
  επιτυχημένο backup run (`company_backup_runs.status=ok`)· καμία/παλιά (>8 ημ.) επιτυχία → WARN που
  παραπέμπει σε φρέσκο backup + restore drill. Ο runbook (`docs/updates-runbook.md`) απέκτησε πίνακα
  cadence για το restore drill (πριν go-live / τριμηνιαίο off-site / μετά από αλλαγή pipeline).
- **AUDIT OPS-13 — ανθεκτικά per-tenant scheduled sweeps + ανίχνευση «κολλημένου» tenant.** Τα
  `whmcs-fetch-all`/`mydata-reconcile-all` δεν σταματούν πλέον σε όλους τους tenants όταν ΕΝΑΣ πετάξει
  exception — απομονώνεται, καταγράφεται ως per-tenant αποτυχία στο health, και το sweep συνεχίζει. Η
  «Υγεία συστήματος» + `ops:health` σημαίνουν πλέον έναν ενεργό sweep που **σταμάτησε να τρέχει** (>26h
  χωρίς καταγραφή) ως «κόλλησε» (warning), ώστε ένα παλιό «ok» να μη διαβάζεται ως υγιές.
- **AUDIT SET-5 — αλλαγή παρόχου σε ελληνική τιμολόγηση σπέρνει τα lookups.** Ένας tenant που
  δημιουργήθηκε ως «none»/PEPPOL και αργότερα γύρισε σε `gr-mydata`/`gr-provider` έμενε με άδειες
  ρυθμίσεις (ΦΠΑ, είδη παραστατικών κ.λπ.)· τώρα το `EditCompany` τρέχει την ίδια σπορά με το
  CreateCompany (νέα aggregate `MyDataLookupSeeder::seedStandardLookups()`, idempotent/fill-empty) όταν
  ο πάροχος γυρίζει σε ελληνικό — notification μόνο όταν όντως προστέθηκε κάτι.
- **AUDIT OPS-12 — τέλος στο διπλό email σε retry.** Κάθε αποστολή φέρει σταθερό `send_key` (uuid,
  serialized ώστε να επιβιώνει στα retries)· αν ένα προηγούμενο attempt με το ίδιο key άφησε `sending`
  (hard crash μετά το SMTP accept) ή `sent`, το retry ΔΕΝ ξαναστέλνει (reconcile → sent). Νέα στήλη
  `invoice_mail_log.send_key`. Το SPF/DKIM fallback warning (tenant SMTP fail → global SMTP) έγινε actionable.
- **AUDIT MON-5 — τα πρόχειρα δεν φουσκώνουν πια τζίρο/εισπρακτέα/ΦΠΑ/Καρτέλα.** Ένα πρόχειρο δεν είναι
  εκδοθέν παραστατικό, οπότε ένα μόλις-δημιουργημένο / WHMCS-staged / renewal draft δεν μετράει πλέον ως
  έσοδο ή εισπρακτέο. Νέο `InvoiceScope::excludeUnissuedDrafts()` σε όλα τα money surfaces (dashboard,
  `Customer::scopeWithOutstandingBalance`, `CustomerLedgerBuilder`) — παραμένουν συνεπή. **Κρατιούνται**
  τα credit-note drafts (μειώνουν το υπόλοιπο, σκόπιμο) και τα legacy-imported drafts (πραγματικά
  ιστορικά). Το immediate reference-number του πελάτη ήδη καλύπτεται (invcode + banner «ΠΡΟΧΕΙΡΟ»).
- **AUDIT MON-7 — προειδοποίηση όταν η «τιμή με ΦΠΑ» δεν κάνει round-trip.** Η καθαρή τιμή αποθηκεύεται σε
  2 δεκαδικά, οπότε 10,00€ @24% γίνεται 8,06€ καθαρό → ξαναχρεώνεται 9,99€. Η φόρμα εμφανίζει πλέον warning
  όταν `|recomputed − entered| ≥ 0,005`, ώστε ο χειριστής να ξέρει και να προσαρμόσει το καθαρό αν θέλει
  ακριβές μικτό. (Η γέφυρα WHMCS WH-9 — feed που εξαιρεί ήδη-φορολογημένα — στο plugin **v0.42.0**.)
- **AUDIT MON-9 — το Dashboard δεν υπερδηλώνει πια τζίρο/ΦΠΑ ούτε αποκλίνει στα receivables σε tenant με legacy ΠΙΣ.**
  Τα turnover/VAT φίλτρα του `DashboardMetrics` (`baseInvoices`, `creditNotesQuery`, top-customers) πέρασαν από
  τον στενό `whereNull('credited_invoice_id')` στον πλήρη `InvoiceScope::excludeCreditNotes()`/`onlyCreditNotes()`
  (πιάνουν και τα ETL-imported legacy `invoice_types.is_credit` χωρίς `credited_invoice_id`)· η «Εικόνα ΦΠΑ»
  κληρονομεί το fix. Στα **receivables** (dashboard headline + `Customer::withOutstandingBalance` → debtor table,
  aged receivables, assistant tools) τα standalone legacy ΠΙΣ **αφαιρούνται** πλέον από το υπόλοιπο (νέος
  `InvoiceScope::onlyStandaloneCreditNotes()`), αφού δεν έχουν original με `credited_total` — ώστε **dashboard,
  per-customer table και ledger να συμφωνούν ακριβώς**. Κοινό `Customer::OUTSTANDING_BALANCE_SQL` (select/
  onlyDebtors/CustomersTable filter μία πηγή, να μη ξαναποκλίνουν). **Convergence sweep** στα υπόλοιπα
  narrow-predicate sites ίδιας κλάσης: **Βιβλίο Εσόδων-Εξόδων** (`LedgerBook` — standalone ΠΙΣ πλέον
  σημαίνεται −1, δεν υπερδηλώνει τζίρο/ΦΠΑ), **ληξιπρόθεσμα** (`Invoice::scopeOverdue` → δεν «κυνηγάει»
  πιστωτικό στο `invoices:notify-overdue`/widget/filter), **dropdown πληρωμής** (`CustomerLedger::openInvoiceOptions`
  → δεν αντιστοιχίζεις πληρωμή σε πιστωτικό), **top προϊόντα** (`CustomerTopProducts`), **`Invoice::isOverdue()`**
  (single-record twin του `scopeOverdue`) και **`PaymentAllocator`** (FIFO + manual — μια πληρωμή δεν
  auto-allocate-άρεται πια πάνω σε ΠΙΣ) — όλα μέσω `InvoiceScope::excludeCreditNotes()`/`isCreditNote()`.
  (Εκκρεμούν ως low-priority cosmetic residual κάποια per-record UI guards — badge «Πιστωτικό», ορατότητα
  action «καταχώριση πληρωμής»/ακύρωσης — που εμφανίζουν ένα standalone legacy ΠΙΣ σαν κανονικό τιμολόγιο·
  εκτός money-math, δεν επηρεάζουν τζίρο/ΦΠΑ/υπόλοιπα.)
- **AUDIT SEC-2 — ελάχιστο μήκος password (8).** Ο κωδικός χρήστη επιβάλλει πλέον `min:8` στη φόρμα (conditional
  ώστε το blank-edit «κράτα τον κωδικό» να μην απορρίπτεται) και στο `ekdosi:install` (πριν το transaction, ώστε
  ένα `--password` flag να μη σπέρνει αδύναμο super_admin).
- **AUDIT SEC-3 (cheap) — ανίχνευση κοινού webhook secret.** Το `ops:health` προσθέτει row «Security → Shared
  webhook secret» + warning όταν δύο tenants μοιράζονται `whmcs_webhook_secret` (συγκρίνει hash του decrypted —
  ποτέ plaintext στο report). Ο κανόνας «ποτέ κοινό webhook secret» + το SEC-4 (μη-ληξιπρόθεσμο public PDF URL)
  τεκμηριώθηκαν στο `docs/security-at-rest.md`. (Το slug/timestamp στο canonical παραμένει deferred.)
- **AUDIT SEC-5 — ρητό `withoutGlobalScope` στα all-tenant sweeps.** Οι δύο cross-tenant σαρώσεις
  (`OperatorHealthReport::mail()`, `RunScheduledCompanyBackups`) δηλώνουν πλέον ρητά `->withoutGlobalScope(CompanyScope::class)`
  αντί να στηρίζονται στο no-op default του CompanyScope εκτός tenant context.
- **AUDIT SET-2 — φύλαξη bulk/force-delete στα lookups.** Η μαζική + οριστική διαγραφή σε lookup πίνακες
  (ΦΠΑ, τύποι, τρόποι πληρωμής/αποστολής κ.λπ.) ήταν αφύλακτη — διαγραφή μιας σε-χρήση εγγραφής έσκαγε
  σε raw 500 (`restrictOnDelete`) ή μηδένιζε σιωπηλά το FK (`nullOnDelete`, κενό Select). Νέα
  `GuardedDeleteAction::bulk()`/`::forceBulk()` που **παραλείπουν** τις σε-χρήση εγγραφές (διαγράφοντας τις
  ελεύθερες) με σύνοψη «Διαγράφηκαν: N · Παραλείφθηκαν: M». Ο dependent map κάθε lookup ενοποιήθηκε σε ένα
  `Resource::dependents()` (single + bulk + force μία πηγή) σε 8 resources· έκλεισε και το κενό
  `whmcs_default_receipt_type_id` στον InvoiceType.
- **AUDIT MON-6 — ο AI Βοηθός δεν μετράει πια πιστωτικά ως πωλήσεις.** Το `count_sales` **εξαιρεί** τα
  πιστωτικά· το `vat_summary` τα **αφαιρεί** (ΦΠΑ εκροών νετάρει — €124 πώληση + πλήρες πιστωτικό = €0 ΦΠΑ,
  όχι €24). Νέα `InvoiceScope::excludeCreditNotes()`/`onlyCreditNotes()` με τον πλήρη predicate (correlated
  `credited_invoice_id` **Ή** `invoice_types.is_credit`), ώστε να πιάνει και τα ETL-imported legacy ΠΙΣ που
  δεν έχουν `credited_invoice_id`. (Το ίδιο πλήρες φιλτράρισμα στο Dashboard = MON-9, ξεχωριστό PR.)
- **AUDIT MON-8 — θετική-τιμής validation στις πληρωμές.** Το `amount` αποθηκεύεται πάντα θετικό (το `kind`
  φέρει το πρόσημο)· προστέθηκε `minValue(0.01)` στη φόρμα πληρωμής και στο «Καταχώριση πληρωμής» του
  παραστατικού, ώστε μια αρνητική «πληρωμή» να μην παρακάμπτει τον μηχανισμό επιστροφών.
- **AUDIT OPS-10 — τέλος στο ατέρμονο resend σε πελάτες χωρίς email.** Το `invoices:resend-failed-emails`
  ξανα-έστελνε σε κάθε sweep τα τιμολόγια πελατών χωρίς email (κάθε προσπάθεια γράφει νέο `failed` row που
  ανανεώνει το `--since` παράθυρο → αιώνια επανεπιλογή, φουσκωμένοι failure counters που κρύβουν πραγματικά
  SMTP σφάλματα). Πλέον το query εξαιρεί πελάτες χωρίς email — αν αποκτήσουν αργότερα, ξαναμπαίνουν αυτόματα.
- **AUDIT OPS-11 — προστασία του Firebird import από false-failure/παράλληλη εκτέλεση.** Το queue
  `retry_after` (90s) είναι πολύ μικρότερο από το `timeout` του import (1800s), οπότε με 2ο worker το τρέχον
  import ξανα-δεσμευόταν στα 90s. Με σκέτο `$tries=1` αυτό αποτύγχανε σε max-attempts ΠΡΙΝ το middleware →
  ψευδο-«failed» run + ψευδο-alert ενώ ο import πετύχαινε. Fix (τριάδα): `retryUntil` (αφήνει τον διπλότυπο
  να φτάσει στο gate αντί να αποτύχει), `WithoutOverlapping`+`dontRelease` (τον ρίχνει χωρίς 2η gbak/migrate),
  `maxExceptions=1` (μία πραγματική προσπάθεια). Ασφαλές ανεξαρτήτως αριθμού workers.
- **AUDIT WH-8 — κλείσιμο under-billing στη γέφυρα WHMCS.** Μια γραμμή WHMCS με ποσό αλλά **κενή
  περιγραφή** droppαρόταν σιωπηλά από τον mapper (μια γραμμή χωρίς περιγραφή δεν είναι νόμιμη γραμμή
  παραστατικού) → στα `createDraft`/`split` paths (που δεν τρέχουν totals-reconcile) η χρέωση χανόταν και
  εκδιδόταν λιγότερο από το οφειλόμενο. Πλέον ο mapper την εκθέτει και το `assertPayloadFilable` (κοινό
  και στα 3 filing paths) ΚΡΑΤΑ τη γραμμή για τον χειριστή· κενή-μηδενική γραμμή (spacer) μένει αβλαβής.
  Επιπλέον ο splitter αποκτά **completeness assertion** (κάθε χρεώσιμη γραμμή του payload σε ακριβώς έναν
  δικαιούχο — πιάνει missing/διπλή/orphan δρομολόγηση).
- **AUDIT WH-6 — pagination στο `getInvoicesForClient()`.** Χρησιμοποιούσε το `limit` param που η WHMCS
  αγνοεί (default σελίδα ~25) → το per-customer ledger έβλεπε μόνο τα ~25 νεότερα τιμολόγια του πελάτη και
  σήμαινε τα υπόλοιπα ως «απόντα». Πλέον σελιδοποιεί με `limitstart`/`limitnum` (ίδιο σχήμα με το frozen-at-16 fix).

- **AUDIT MON-3 — stale `paid_total` κάτω από ταυτόχρονες πληρωμές.** Το `InvoiceBalance::recompute`
  κλείδωνε το invoice row αλλά διάβαζε το SUM των πληρωμών ως plain read → κάτω από REPEATABLE READ ένα
  read view στημένο πριν το lock (π.χ. από outer transaction) μπορούσε να σερβίρει stale άθροισμα και να
  γράψει «χαμένη» πληρωμή. Fix: το SUM πληρωμών + το `hasRecordedPayments` τρέχουν ως **locking reads**
  στο recompute path (`for(..., locking: true)`)· το UI `for()` μένει plain. Το SUM των πιστωτικών μένει
  σκόπιμα plain read (self-heals μέσω observer) — locking εκεί θα έκλεινε deadlock cycle με το credit-note
  filing. Επίσης το `recompute()` **αυτο-τυλίγεται σε transaction** όταν ο caller δεν έχει (π.χ. Filament
  edit path), ώστε το lock να ισχύει παντού. (Adversarial-review findings: deadlock + no-op-outside-tx.)
- **AUDIT DOC-5 — ΜΑΡΚ στο PDF ανεξάρτητα από το QR.** Ένα ETL-imported legacy παραστατικό (VALID +
  `mydata_mark` αλλά χωρίς `mydata_url` → χωρίς QR) έβγαινε χωρίς ΜΑΡΚ και χωρίς QR. Το ΜΑΡΚ τυπώνεται
  πλέον όποτε υπάρχει (με «ΜΑΡΚ:» label όταν λείπει το QR)· και η σειρά «Πιστοποιημένο» στο meta strip δεν
  απαιτεί πλέον `mydata_url` (ένα VALID ΜΑΡΚ είναι πιστοποιημένο ανεξαρτήτως verify-URL).
- **AUDIT DOC-6 — καμία αποστολή email για πρόχειρα/ακυρωμένα.** Gate με το fail-closed predicate του public
  PDF route (`isPubliclyViewable()` = εκδοθέν + όχι AADE-cancelled) σε **ΟΛΑ** τα σημεία: manual + bulk UI
  actions, ΚΑΙ στο ίδιο το job (`SendInvoiceEmail::handle` — ο πραγματικός choke-point, κλείνει το batch
  sweep + το TOCTOU), ΚΑΙ στο query του `invoices:resend-failed-emails` (ώστε να μην ξανα-μπαίνει στην ουρά
  ακυρωμένο = churn). Το mail body δηλώνει «…που εκδόθηκε…», οπότε draft/ακυρωμένο δεν στέλνεται.
- **Batch email trigger enum** — το `invoice_mail_log.trigger` enum δεχόταν μόνο `['auto','manual']`, αλλά
  το `invoices:resend-failed-emails` κάνει dispatch με `trigger='batch'` → το job's log-row insert έσκαγε
  σε enum violation σε **κάθε** batch αποστολή (κρυμμένο: το test του command κάνει `Queue::fake()`).
  Migration: το enum περιλαμβάνει πλέον `'batch'`. (Bonus finding από το DOC-6 review.)

### Changed
- **AUDIT hygiene (SET-4/SET-6/OPS-14/DOC-9) — docs/config καθάρισμα, μηδέν ρίσκο.** `.env.example`:
  προστέθηκαν τα missing env (νεότερα `EKDOSI_SCHEDULE_*`, όλα τα `*_CRON` overrides, `BACKUP_LOCAL_DISK`,
  `MYDATA_INDOUBT_GRACE_MINUTES`, το νέο `EKDOSI_UPDATE_*`+`GITHUB_TOKEN`) + οδηγία log-rotation
  (`LOG_STACK=daily`/`LOG_DAILY_DAYS`). INSTALL.md §Logs με logrotate stanza (OPS-14). Operator label
  «Email PDF to customer» → «Αποστολή PDF στον πελάτη» (DOC-9). Ενημερώθηκαν stale docs
  (CLAUDE.md gross-edit/auto-calc, `DeliveryNoteSubmitter` «✅ sandbox-validated» — SET-6).
- **AUDIT MON-4 — ρητή πολιτική κενών ΑΑ + σκλήρυνση draft-delete.** Τεκμηριώθηκε (στο `InvoiceNumberer`)
  ότι ο ΑΑ δεσμεύεται στη δημιουργία draft και ΔΕΝ επαναχρησιμοποιείται· η διαγραφή draft αφήνει νόμιμο
  μόνιμο κενό (η myDATA ταυτοποιεί με ΜΑΡΚ). Το draft-delete έχει πλέον confirmation που το εξηγεί.

### Added
- **AUDIT WH-7 — επανάληψη αποτυχημένης επιστροφής ΜΑΡΚ στο WHMCS.** Όταν η υποβολή στην ΑΑΔΕ πετύχει
  αλλά η ενημέρωση του WHMCS αποτύχει, το παραστατικό έμενε `whmcs_writeback_state=failed` χωρίς σημείο
  επανάληψης (το badge έμενε «Όχι στο AADE» μέχρι tinker). Νέα: στήλη + φίλτρο «Επιστροφή ΜΑΡΚ» στο WHMCS
  inbox, per-row action **«Επανάληψη επιστροφής ΜΑΡΚ»**, και batch command
  **`php artisan whmcs:retry-writebacks [--tenant=SLUG] [--dry-run]`** (δεν αγγίζει την ΑΑΔΕ — ξαναστέλνει
  μόνο το ήδη εκδοθέν ΜΑΡΚ). Διορθώθηκε και το παραπλανητικό next-step μήνυμα στα logs.
- **AUDIT SET-1 companion — `php artisan ekdosi:create-admin`.** Δημιουργεί (ή κάνει `--reset`
  κωδικού) έναν **system super_admin** χωρίς τον πλήρη installer: prompt/flags για name/email/password,
  τον κάνει μέλος όλων των εταιριών και του αναθέτει super_admin παντού (reuse `shield:sync-super-admin
  --user`). Ασφαλές path όταν δεν υπάρχει admin (χρειάζεται ≥1 εταιρία — αλλιώς `ekdosi:install`).
- **AUDIT SET-3 — MariaDB CI job για την εγγύηση αρίθμησης.** Νέο `numbering-concurrency` job (MariaDB
  service container) τρέχει `test:invoice-numbering-concurrent` με **πραγματικά row locks + forked
  processes** — ένα dropped `lockForUpdate()`/transaction κόβει πλέον το CI. Το phpunit suite μένει sqlite.

### Security
- **AUDIT SET-1 — ο demo seed γίνεται opt-in (τέλος ο γνωστός-password super_admin σε λάθος host).**
  Ο `DatabaseSeeder` (DEMO tenant + `admin@ekdosi.local`) είναι **no-op** εκτός αν `EKDOSI_SEED_DEMO=true`
  — prod-safe **ανεξαρτήτως `APP_ENV`** (ο prod είχε κάποτε λάθος `APP_ENV=local`, οπότε ένα σκέτο
  `isProduction()` guard θα αστοχούσε)· + δεύτερο belt `isProduction()` bail· demo password
  env-overridable. Πραγματικά installs: `ekdosi:install` / `ekdosi:create-admin`.
- **AUDIT MYD-2 (σκέλος γ) — «in-doubt» gate κατά της διπλο-υποβολής μετά από transport timeout.**
  Sandbox-αποδεδειγμένο (2026-07-07) ότι η ΑΑΔΕ **ΔΕΝ** κάνει server-side dedup στο ERP κανάλι:
  blind retry του ίδιου `(series, ΑΑ)` παρήγαγε **δύο διαφορετικά MARK** με ίδιο `invoiceUid` → διπλά
  δηλωμένο έσοδο. Fix: νέα στήλη `invoices.mydata_pending_since`· σε transport failure το παραστατικό
  σημαίνεται «in-doubt», και στο επόμενο `submit()` γίνεται ΠΡΩΤΑ reconcile `(series, ΑΑ)` μέσω
  `RequestTransmittedDocs` → live MARK ⇒ **υιοθέτηση** (self-heal, καμία 2η υποβολή)· τίποτα ⇒ εντός
  grace window (`einvoice.in_doubt_grace_minutes`, default 10) **άρνηση** (το feed της ΑΑΔΕ καθυστερεί
  ~λεπτά — αλλιώς η ίδια καθυστέρηση ξανα-διπλο-υπέβαλλε), μετά το grace υποβολή κανονικά. Το ημερήσιο
  reconcile παραμένει backstop. Το «in-doubt» καλύπτει ΚΑΘΕ αμφίσημη έκβαση, όχι μόνο τα ρητά
  timeout/connection: **άδειο/μη-παρσαρίσιμο HTTP-200 body** (`InvalidResponseException`), **5xx**
  (`TransmissionFailedException`), generic `Throwable`, ΚΑΙ αποτυχία τοπικής εγγραφής (`persistResponse`)
  ΜΕΤΑ από επιτυχές POST — ενώ ένα 429 (rate-limit) και μια ρητή απόρριψη (`MyDataRejected`, χωρίς ΜΑΡΚ)
  σκόπιμα ΔΕΝ σημαίνονται (κανένα ΜΑΡΚ → ασφαλές retry). Επιβεβαιώθηκε επίσης ότι το κανάλι **παρόχου** (InvoSign) **κάνει dedup**
  + έχει real-time `invoice_status.php`, οπότε το `GrProviderSubmitter` είναι ήδη ασφαλές (καμία αλλαγή).
  Πλήρης αναφορά: `docs/archive/mydata-sandbox-myd2-retry-2026-07-07.md`.

### Fixed
- **Provider cancel — καθαρή άρνηση για μη-9.3 (αντί opaque `[283]`).** Το
  `GrProviderSubmitter::cancel()` πλέον αρνείται σε service-level κάθε τύπο ≠ 9.3 με
  μήνυμα «έκδοσε πιστωτικό (5.1)», χωρίς να χτυπά τον πάροχο — η ακύρωση provider-2.1/11.x
  είναι αδύνατη by design (CancelDeliveryNote = 9.3-only· sandbox-observed `[283]`, direct-AADE
  `[249]` «posted by provider»). Καλύπτει τα μη-UI μονοπάτια (automation/bulk/API)· το UI ήδη
  γκρεϊτάρει το κουμπί σε 9.3-only.

### Added
- **AUDIT MYD-5 — per-line myDATA E3 ανά κατηγορία προϊόντος (μικτά τιμολόγια).** Νέα πεδία
  `product_categories.mydata_income_class[_category]`: μια γραμμή δηλώνει το bucket εσόδων της
  κατηγορίας της (π.χ. «Εμπορεύματα» → category1_1, «Υπηρεσίες» → category1_3), ενώ ο E3 **τύπος**
  ακολουθεί το κανάλι του παραστατικού. Το summary εκπέμπει ένα `incomeClassification` ανά distinct
  (τύπο, κατηγορία), αθροίζοντας στο totalNet — τέλος το «ίδιο E3 σε όλες τις γραμμές» για μικτό
  τιμολόγιο αγαθών+υπηρεσιών. Κενό = κληρονομεί τον τύπο (services-only tenants αμετάβλητοι).

### Fixed
- **AUDIT MYD-6 — διασταύρωση χώρας↔τύπου + πραγματική διεύθυνση αντισυμβαλλόμενου.** Πριν την
  υποβολή ελέγχεται ότι η χώρα ταιριάζει στον τύπο (1.1/2.1→GR, 1.2/2.2→ΕΕ-όχι-GR, 1.3/2.3→εκτός ΕΕ)
  με καθαρό μήνυμα αντί για opaque ΑΑΔΕ [242]-[244]· ξένος counterpart χωρίς πλήρη διεύθυνση κάνει
  **hard-fail** αντί να δηλώνει fabricated `'Unknown'/'00000'`.
- **AUDIT MYD-7 — cancel [251] «already cancelled» → self-heal.** Όταν η ΑΑΔΕ έχει ήδη ακυρώσει το
  MARK (προηγούμενο cancel πέτυχε αλλά το τοπικό write απέτυχε), το retry συγχρονίζει το τοπικό state
  σε CANCELLED αντί να throw-άρει επ' άπειρον.
- **AUDIT MYD-8 — override-aware έλεγχος fileable ΦΠΑ.** Νέο `Codes::vatRateFileable(rate, override)`:
  ένα σωστά ρυθμισμένο 3% row (§8.2 override → κατ. 9) δεν σημαίνεται πλέον ψευδώς «μη-fileable» στο
  badge/ETL.
- **AUDIT MYD-9 — έλεγχος `mydata_requires_quantity` vs φύση τύπου.** Το config audit προειδοποιεί
  όταν ένας χειροποίητος τύπος αγαθών δεν ζητά ποσότητα ([204]) ή ένας τύπος υπηρεσιών τη ζητά ([205]).

### Added
- **`AUDIT.md`** — πλήρης έλεγχος ετοιμότητας παραγωγής (2026-07-03): 7 τομεακοί
  έλεγχοι (myDATA/ΑΑΔΕ, χρηματικά, security/tenancy, ops/backups, PDF/email,
  onboarding, WHMCS) με ευρήματα ανά σοβαρότητα (IDs + checkboxes), ετυμηγορία
  go/no-go και προτεινόμενη σειρά εργασιών. Κύρια blockers: MYD-1 (έκπτωση
  κεφαλίδας → απόρριψη [207]/[209]), OPS-1/2 (whole-DB backups), DOC-1 (αιτία
  απαλλαγής ΦΠΑ στο PDF).

### Added
- **AUDIT OPS-3 — ειδοποίηση ανεπίλυτων σφαλμάτων (exception alerting).** Κάθε
  reportable unhandled exception (web / scheduler / queue) στέλνει πλέον email στους
  ops (best-effort, deduped ανά υπογραφή σφάλματος μέσα στο παράθυρο περιορισμού) αντί
  να μένει μόνο στο `laravel.log`. Wired στο `bootstrap/app.php` `withExceptions()->report()`
  (`ExceptionNotifier`) — δεν καταπνίγει το log line, δεν σπάει το request σε αποτυχία
  mail. Recipients: `EKDOSI_ERROR_ALERT_EMAIL` → κοινή αλυσίδα με τα backup alerts
  (`EKDOSI_BACKUP_ALERT_EMAIL` → super_admins). Gated `EKDOSI_ERROR_ALERTS` (default ON),
  throttle `EKDOSI_ERROR_ALERT_THROTTLE_MINUTES` (default 30).
- **AUDIT DOC-4 — ΓΕΜΗ + Δραστηριότητα στην κεφαλίδα του PDF.** Νέο πεδίο `companies.gemi`
  (φόρμα εταιρείας) που τυπώνεται στην κεφαλίδα του παραστατικού **και του δελτίου αποστολής**
  (ν.4919/2022 αρ.22 — υποχρεωτικό για εγγεγραμμένες στο ΓΕΜΗ οντότητες)· τυπώνεται πλέον
  και το `kad_primary` (Δραστηριότητα/ΚΑΔ) που υπήρχε αλλά δεν εμφανιζόταν.
- **AUDIT OPS-4 — `ops:health` επιστρέφει πραγματικό exit code.** Νέο `OperatorHealthSeverity`
  αποστάζει το report σε level+exit **0=ok / 1=warning / 2=critical**· κρίσιμα: worker down (>30′
  σιωπής), backup monitor failed, **δίσκος <2% ελεύθερος**· warnings: failed jobs (24ω), off-site/
  books gap, στημένα email queue, χαμηλός δίσκος (<5%), failed scheduled task, WHMCS/myDATA. Verdict
  banner στο CLI, `severity` στο JSON και στη σελίδα «Υγεία συστήματος» (+ per-tenant off-site/books
  rows, parity με το CLI). Ξεκλειδώνει το gate στο `deploy/update.sh` + cron `ops:health || alert`.
- **AUDIT OPS-9 — ειδοποίηση για αποτυχημένα queue jobs.** `Queue::failing` → ίδιο
  deduped/throttled email channel με OPS-3 (`ExceptionNotifier::reportFailedJob`)· ένα job που
  εξαντλεί τα retries πλέον ειδοποιεί αντί να «κάθεται» σιωπηλά στο `failed_jobs`.

### Changed
- **AUDIT OPS-5 — τα αυτόματα αντίγραφα περιλαμβάνουν τα βιβλία by default.** Το default bucket
  γίνεται `full` (φόρμα + migration) — ένα DR backup που εξαιρεί τιμολόγια/πληρωμές/ΜΑΡΚ ήταν
  ψευδές δίχτυ. Υπάρχοντα rows ΔΕΝ αλλάζουν σιωπηλά· το `ops:health` πλέον προειδοποιεί (bucket-aware).
- **AUDIT OPS-6 — drain του queue worker στο deploy/rollback.** Τα `update.sh`/`rollback.sh`
  σταματούν τον worker ΠΡΙΝ το `migrate`/restore και τον ξεκινούν μετά (`QUEUE_STOP_CMD`/
  `QUEUE_START_CMD` hooks + auto-detect του `ekdosi-queue` unit) — τέλος το «long in-flight job
  γράφει σε μισο-migrated schema».
- **AUDIT OPS-8 — ορατότητα στο health για τα unattended tasks.** Το `$trackSchedule` + TASK_LABELS
  καλύπτουν πλέον `whmcs:auto-issue` (ο μόνος που εκδίδει μόνος του!), per-tenant backups,
  resend-failed-emails, overdue/renewals/dunning, console-refresh.

- **AUDIT SEC-1 — η απόφαση «plaintext secrets at rest» γίνεται ρητή.** Το plaintext-at-rest
  παραμένει το σκόπιμο default (DR χωρίς APP_KEY), αλλά πλέον απαιτεί συνειδητή αποδοχή:
  νέο `EKDOSI_SECRETS_PLAINTEXT_ACKNOWLEDGED`· το `ekdosi:go-live-check` έχει gate «Μυστικά
  at-rest» που κάνει **warn** όσο δεν έχει δηλωθεί (ή κρυπτογραφηθεί) και **pass** μόλις
  δηλωθεί/κρυπτογραφηθεί — ποτέ fail (αποδεκτό trade-off). Νέο `docs/security-at-rest.md`
  με το threat model + escape hatch (`secrets:reencrypt`).

### Fixed
- **AUDIT OPS-7 — clean-slate DB restore.** Το `ekdosi:db-restore` κάνει πρώτα `DROP DATABASE` +
  `CREATE DATABASE` (στη ΒΔ της σύνδεσης, με το charset/collation της) και μετά φορτώνει το
  (DB-agnostic) snapshot — ώστε ορφανός πίνακας κακού migration να μη επιβιώνει (αλλιώς το επόμενο
  deploy έσκαγε «table already exists»)· self-heal σε διακοπείσα επαναφορά, χωρίς baked-in όνομα ΒΔ.
  Το snapshot μεταφέρθηκε ΜΕΤΑ το `artisan down` (κλείνει το παράθυρο χαμένων writes· snapshot-failure
  = clean abort με `up` + worker restart). Hardening (review): το dump ανοίγει ΠΡΙΝ το destructive DROP
  (μη-αναγνώσιμο snapshot δεν αφήνει άδεια ΒΔ)· falsy-guard στο `charset` (κενό `DB_CHARSET` δεν βγάζει
  malformed CREATE).
- **AUDIT MYD-4 — ορατότητα για μη-αντιστοιχισμένους τρόπους πληρωμής (δηλώνονταν σιωπηλά ως
  «Μετρητά»).** Χωρίς `mydata_payment_type` (§8.12), τιμολόγιο με κάρτα/έμβασμα δηλωνόταν στην
  ΑΑΔΕ ως μετρητά (τύπος 3) χωρίς σημάδι. Πλέον: (α) το `MyDataConfigAudit` (preflight /
  «Έλεγχος ρυθμίσεων» / go-live) **προειδοποιεί** με τους μη-αντιστοιχισμένους τρόπους, (β) ο
  submitter **καταγράφει** (`Log::warning`) το fallback σε μετρητά για ρητά επιλεγμένο-αλλά-
  αδιάστατο τρόπο. Σκόπιμα **δεν** μπλοκάρει την έκδοση (θα σταματούσε ζωντανή τιμολόγηση για
  θέμα ποιότητας payload) — η ορατότητα λύνει το «σιωπηλό».
- **AUDIT MYD-2 (μερικώς) — αδύνατη πλέον η ταυτόχρονη διπλή υποβολή του ίδιου παραστατικού.**
  Το `MyDataSubmitter::submit()` παίρνει atomic cache lock ανά παραστατικό (όχι DB row-lock —
  δεν κρατιέται πάνω από το AADE HTTP) και **ξαναδιαβάζει την κατάσταση φρέσκια κάτω από το
  lock**, ώστε δύο χειριστές / double-click / δύο tabs να μην POSTάρουν και οι δύο (2 MARKs =
  διπλά δηλωμένο έσοδο). Το lock auto-expires (120s) — δεν κολλάει το παραστατικό. _Το σκέλος
  «retry μετά από timeout» (in-doubt gate) μένει ανοιχτό, εξαρτάται από το sandbox πείραμα
  uid-dedup της ΑΑΔΕ — βλ. AUDIT MYD-2._
- **AUDIT MON-1 — η ακύρωση πιστωτικού ΔΕΝ «καίει» πλέον τις επιστραφείσες ποσότητες.**
  Το `qty_returned` ήταν μετρητής μόνο-αύξησης χωρίς σύνδεση της γραμμής πιστωτικού με
  την αρχική γραμμή, οπότε η ακύρωση ενός πιστωτικού κλείδωνε οριστικά την ποσότητα και
  δεν ξαναεκδιδόταν πιστωτικό. Νέα στήλη `invoice_lines.original_line_id` (self-FK,
  nullable) συνδέει τη γραμμή πιστωτικού με αυτήν που πιστώνει· νέο
  `RecomputeReturnedQuantities` ξαναϋπολογίζει το `qty_returned` = Σ(ποσοτήτων από **ζωντανά**
  πιστωτικά, `InvoiceScope::live`) — ίδια πειθαρχία «cache = Σ ζωντανών» με το
  `credited_total`. Ακύρωση (τοπική ή ΑΑΔΕ) ή διαγραφή πιστωτικού ελευθερώνει την ποσότητα
  και επιτρέπει επανέκδοση (`InvoiceObserver`). **ETL-safe:** μόνο γραμμές με native
  πιστωτικό (original_line_id) ξαναγράφονται· οι legacy-imported επιστροφές μένουν ανέγγιχτες.
  Portability: ο `CompanyImporter` remap-άρει το self-FK.
- **AUDIT MON-2 — το VAT report αφαιρεί πλέον τα πιστωτικά στις εκροές.** Το «πόσο ΦΠΑ θα
  χρωστάμε» έπαιρνε το output από `DashboardMetrics::income()` που **εξαιρεί** (δεν αφαιρεί)
  τα πιστωτικά → υπερδήλωνε ΦΠΑ εκροών (πώληση 124€ + πλήρες πιστωτικό = 24€ αντί 0€). Νέο
  `DashboardMetrics::outputForVat()` **αφαιρεί** τα ζωντανά πιστωτικά (net/gross/vat),
  ευθυγραμμισμένο με `LedgerBook::vatBalance()` + Καρτέλα· το `income()` (μικτός τζίρος για τα
  dashboard tiles) μένει ως έχει. Ακυρωμένο πιστωτικό δεν μειώνει τις εκροές.
- **AUDIT WH-1/WH-2/WH-3/WH-4/WH-5 — filer-level preflight πριν οπλιστεί η «Άμεση
  τιμολόγηση».** Νέο `WhmcsFilingGuard` (choke-point πάνω στην έξοδο του mapper, ΠΡΙΝ
  δεσμευτεί ΑΑ) κρατά (HOLD) στα Εισερχόμενα ό,τι δεν πρέπει να εκδοθεί αυτόματα:
  **WH-1** μη-EUR τιμολόγιο (το ekdosi εκδίδει μόνο EUR — αλλιώς το $120 θα δηλωνόταν
  €120)· **WH-4** αρνητικές γραμμές (WHMCS promo/credit — η ΑΑΔΕ απορρίπτει αρνητική
  αξία, αφού όμως θα είχε καεί το ΑΑ)· **WH-2** ασυμφωνία συντελεστή ΦΠΑ (ο mapper
  εφαρμόζει τον default· σύγκριση με το `taxrate` του WHMCS πιάνει και την tax-inclusive
  περίπτωση που το gross-check αφήνει)· **WH-5** ασυμφωνία μικτού συνόλου με το WHMCS
  total πέρα από ανοχή στρογγυλοποίησης. currency+αρνητικά τρέχουν σε ΟΛΑ τα paths
  (`file()` / `createDraft()` / splitter — αδιόρθωτα στη φόρμα)· τα **totals/rate reconcile
  ΜΟΝΟ στο `file()`** (unattended) — το χειροκίνητο draft-first αφήνει τον χειριστή να
  διορθώσει τον ΦΠΑ ανά γραμμή (το preview ήδη προειδοποιεί). **WH-3** το `whmcs:auto-issue`
  εξαιρεί πλέον rows που το legacy
  ekdosi έχει ήδη τιμολογήσει (`legacy_invoiced != 0`) από τα candidates + hard guard στον
  filer (`assertCanBeFiled`) — τέλος το παράθυρο διπλής υποβολής στο dual-run. Ο mapper
  εκθέτει `whmcs_currency`/`whmcs_taxrate`/`negative_lines`.
- **AUDIT DOC-2/DOC-3/DOC-7 — τα banners του PDF είναι πλέον συνάρτηση
  `local_status` × `mydata_state` × provider** (`InvoiceBannerState`), όχι μόνο του
  `mydata_state`: (α) τοπικά ακυρωμένο + VALID τυπώνει ΑΚΥΡΩΘΕΝ + «Εκκρεμεί ακύρωση
  στο myDATA» αντί για καθαρό πιστοποιημένο αντίγραφο· (β) τοπικά ακυρωμένο + αδήλωτο
  τυπώνει ΑΚΥΡΩΘΕΝ αντί για ΠΡΟΧΕΙΡΟ· (γ) εκδοθέν-αλλά-αδήλωτο σε myDATA tenant
  τυπώνει «ΕΚΔΟΘΕΝ — ΕΚΚΡΕΜΕΙ ΥΠΟΒΟΛΗ ΣΤΟ myDATA» ενώ σε non-filing tenant
  (none/ee-peppol/mode Off) δεν τυπώνει κανένα banner (το «ΠΡΟΧΕΙΡΟ — ΔΕΝ ΕΧΕΙ
  ΥΠΟΒΛΗΘΕΙ ΣΤΗ myDATA» για πάντα στον εσθονικό tenant τέλος — το draft λεκτικό έγινε
  provider-agnostic «ΔΕΝ ΕΧΕΙ ΕΚΔΟΘΕΙ»)· (δ) DOC-7: το «Πιστοποιημένο» meta row + το
  footer «Πιστοποιημένο στη myDATA — επαληθεύστε» δεν τυπώνονται σε ακυρωμένο έγγραφο
  (QR+ΜΑΡΚ μένουν — η σάρωση δείχνει την αληθινή κατάσταση ΑΑΔΕ).
- **AUDIT MYD-3 — η «Υποβολή στο myDATA» δεν προσφέρεται/εκτελείται πλέον σε τοπικά
  ακυρωμένα παραστατικά**: visibility guard στο `ViewInvoice` + hard guard στο σώμα
  του action (το mountAction δεν ξαναελέγχει visible()) + refusal σε επίπεδο service
  (`MyDataSubmitter::submit`, `GrProviderSubmitter`) ώστε να καλύπτονται ΟΛΟΙ οι
  callers (bulk/console/μελλοντικά automations).
- **AUDIT DOC-1 — η αιτία απαλλαγής ΦΠΑ τυπώνεται πλέον στο PDF.** Κάθε παραστατικό
  με γραμμή 0% τυπώνει στο totals box τη νομική αναφορά της απαλλαγής (verbatim §8.3
  κείμενο, π.χ. «Χωρίς ΦΠΑ - άρθρο 45 του Κώδικα ΦΠΑ» για ενδοκοινοτική) — απαίτηση
  ΕΛΠ ν.4308/2014 αρ.9· η πηγή είναι η ίδια με τον submitter (0% VatCategory →
  `vat_exemption_category`), αλλά non-throwing: αρρύθμιστος tenant τυπώνει χωρίς τη
  σημείωση αντί να σκάει (ο preflight/submitter μένουν οι «θορυβώδεις» φύλακες).
  Δίγλωσσο prefix label («Απαλλαγή ΦΠΑ»/«VAT exemption»)· η νομική αναφορά μένει
  σκόπιμα στα ελληνικά.
- **AUDIT OPS-1/OPS-2 — τα καθολικά (whole-DB) backups υπαρκτά και με πραγματικό
  alerting.** Τα spatie `backup:run`/`clean`/`monitor` πλέον **default ON** (έτρεχαν
  default OFF και το INSTALL.md δεν έλεγε πουθενά να ενεργοποιηθούν → host στημένος
  «by the book» = μηδέν αυτόματα DB backups)· προορισμοί πλέον env-driven
  (`BACKUP_DESTINATION_DISKS`, comma-separated, με οδηγία για off-site)· οι
  ειδοποιήσεις αποτυχίας πάνε στους πραγματικούς παραλήπτες μέσω κοινής αλυσίδας με τα
  per-company alerts (`OpsBackupNotifiable`/`BackupAlertRecipients`: Ρυθμίσεις
  συστήματος → `EKDOSI_BACKUP_ALERT_EMAIL` → super_admins) αντί για το hardcoded
  `your@example.com`, ενώ τα success mails σιωπούν (το `backup:monitor` καλύπτει το
  staleness). Νέο gate «Καθολικό αντίγραφο ΒΔ» στο `ekdosi:go-live-check` (WARN όταν
  OFF ή local-only), sections στο INSTALL.md §11/§14/§15 (off-site + passphrase +
  restore drill· έφυγε και η νεκρή αναφορά σε `app/Console/Kernel.php`), νέα
  `.env.example` τεκμηρίωση (`BACKUP_DESTINATION_DISKS`/`BACKUP_ARCHIVE_PASSWORD`).
- **AUDIT MYD-1 — παραστατικό με έκπτωση κεφαλίδας δεν απορρίπτεται πλέον από την ΑΑΔΕ
  ([207]/[209]).** Το myDATA payload έστελνε per-line `netValue`/`vatAmount` ΧΩΡΙΣ την
  έκπτωση κεφαλίδας ενώ το summary την εφάρμοζε → Σ(γραμμών) ≠ σύνολα → βέβαιη απόρριψη
  (και το πεδίο προσυμπληρώνεται από την έκπτωση πελάτη). Τώρα η έκπτωση κατανέμεται στις
  γραμμές (`AadeInvoiceDocument::allocateDiscountedLineAmounts`) με συμφωνία υπολοίπων
  στρογγυλοποίησης ανά συντελεστή (±1 λεπτό στις μεγαλύτερες γραμμές) ώστε τα αθροίσματα να
  ισούνται ΑΚΡΙΒΩΣ με τα per-rate σύνολα του `InvoiceVatBreakdown`· ίδια κατανεμημένα ποσά
  και στους per-line χαρακτηρισμούς Ε3. Με μηδενική έκπτωση το payload μένει byte-identical
  με το sandbox-validated σχήμα (regression tests). Ισχύει και για το provider channel
  (κοινό `AadeInvoiceDocument`). ⚠ Εκκρεμεί sandbox validation με πραγματική έκπτωση.
- **WHMCS «mass payment» / συγκεντρωτικά τιμολόγια δεν εκδίδονται πλέον λάθος.** Όταν πελάτης
  πληρώνει πολλά ανοιχτά τιμολόγια μαζί, το WHMCS φτιάχνει ΝΕΟ τιμολόγιο με γραμμές-αναφορές σε άλλα
  τιμολόγια (`type='Invoice'`, `relid`, π.χ. «Αρ. Λογαριασμού #31690»), **χωρίς δικό του ΦΠΑ** (0%).
  Δεν είναι πώληση — η έκδοσή του θα διπλομετρούσε τα επιμέρους τιμολόγια και θα δήλωνε το μικτό τους
  με 0% ΦΠΑ (με άμεση τιμολόγηση ON θα υποβαλλόταν αυτόματα στην ΑΑΔΕ). Νέος εντοπισμός
  (`PendingWhmcsInvoice::detectConsolidatedRefs`) σε **3 σημεία**: το ingest το παρκάρει «Σε αναμονή»
  με λόγο που αναφέρει τα επιμέρους #, το `whmcs:auto-issue` αρνείται να το εκδώσει, και το χειροκίνητο
  «Δημιουργία Παραστατικού» το μπλοκάρει με σαφές μήνυμα.
- **`pending_whmcs_invoices.hold_reason` → TEXT** (από `varchar(200)`): το αναλυτικό ελληνικό
  μήνυμα του mass-pay hold ξεπερνά τα 200 chars — σε strict-mode MariaDB θα έριχνε «Data too long»
  μέσα στο ingest transaction (rollback/500). Τώρα χωράει χωρίς όριο.

### Added
- **CMR — διεθνής φορτωτική (αυτοτελές έγγραφο μεταφοράς).** Για διασυνοριακές αποστολές (π.χ.
  GR→Σόφια colocation) που απαιτούν CMR πέρα από το Δελτίο Αποστολής. **Δεν** είναι παραστατικό
  myDATA — αυτοτελές, στα **Αγγλικά**, με **προαιρετική** πηγή (Τιμολόγιο | Δελτίο Αποστολής |
  standalone). Δύο τρόποι: **μενού «CMR» → Νέο** (standalone) ή **«Δημιουργία CMR»** μέσα σε
  Τιμολόγιο/ΔΑ (προ-συμπληρώνει **προσχέδιο** με μεταγραφή ΕΛΟΤ-743 ελληνικών→λατινικών — ο χειριστής
  το διορθώνει πριν εκτυπώσει). Νέα `cmr_notes` + `cmr_lines`, `CmrResource`, `CmrPdf` (πιστή φόρμα
  24 κουτιών), per-company counter (όχι ΑΑ/myDATA). Αγγλικά στοιχεία εταιρείας (`name_en` κ.λπ.) για
  τον Sender. Πλήρης σχεδίαση: `docs/cmr-international-delivery.md`. _Νέο `CmrResource` → τρέξε
  `shield:generate` + re-provision ρόλων μετά το deploy._
- **AI «Βοηθός» — Phase 2b (write actions με operator-confirm).** Δύο εργαλεία που **ΠΡΟΕΤΟΙΜΑΖΟΥΝ**
  (δεν εκτελούν) ενέργειες: **«στείλε ενημερωτικό/καρτέλα»** (`send_customer_statement` — επαφή-aware,
  ίδιοι παραλήπτες με το manual Καρτέλα send) και **«θύμισέ μου / notification»** (`create_reminder`).
  Ο βοηθός **ΠΟΤΕ δεν στέλνει/δημιουργεί μόνος του**: στήνει μια εγγραφή σε `ai_pending_actions` και ο
  χειριστής πατά **«Επιβεβαίωση»/«Άκυρο»** σε κάρτα κάτω από το chat· η εκτέλεση γίνεται server-side
  (`AiActionExecutor`, re-validate από την εγγραφή + permission, scoped tenant+user — δεν εμπιστεύεται
  client input). Οι υπενθυμίσεις παραδίδονται ως Filament database notifications (το «καμπανάκι») όταν
  ωριμάσουν, μέσω `ai:dispatch-reminders` (scheduler, `EKDOSI_SCHEDULE_AI_REMINDERS`, default ON).
- **AI «Βοηθός» — Phase 2a (insights + clickable links).** 4 νέα read-only εργαλεία: **ανάλυση
  οφειλετών** (top debtors + link στην Καρτέλα καθενός — η ανάλυση ανά πελάτη που έλειπε), **αναζήτηση
  πελάτη** (όνομα/ΑΦΜ → υπόλοιπο + link Καρτέλας + «Νέο Παραστατικό»), **πρόσφατα παραστατικά**
  (κατάσταση myDATA/πληρωμής + link), **σύνοψη ΦΠΑ/τζίρου** περιόδου. Τα tools επιστρέφουν deep-links και
  ο βοηθός τα δίνει ως **clickable σύνδεσμοι** (safe renderer: HTML-escape + μόνο same-origin links —
  εξωτερικά URL μένουν inert). «Άνοιξε την καρτέλα του Χ» → link. (Write actions «στείλε ενημερωτικό»/
  reminders = Phase 2b με operator-confirm.)
- **AI «Βοηθός» — Phase 1 (read-only chat).** In-app βοηθός που απαντά για τα δεδομένα της ΤΡΕΧΟΥΣΑΣ
  εταιρείας μέσω εργαλείων (Phase-1: `count_sales`, `outstanding_receivables`). **Δύο surfaces, κοινό
  engine**: dedicated σελίδα «Βοηθός AI» + **floating widget σε κάθε σελίδα** (chat ενώ πλοηγείσαι·
  συνομιλία στο session). **Isolation = tool layer** (κανένα tool δεν έχει `company` param → cross-tenant
  read αδύνατο)· **per-tool permission** (Shield). **Governance όλο web/DB**: per-company on/off, μοντέλο
  (Sonnet/Haiku/Opus), **μηνιαίο όριο tokens**, και προαιρετικό **per-company κλειδί** (escape hatch· global
  key default). **Metering**: `ai_usage_log` (tokens + cost ανά εταιρεία/χρήστη) → cap soft-80%/hard-100% +
  global backstop. Transport = Laravel HTTP (mockable), **όχι** νέα εξάρτηση. Global switch `EKDOSI_AI_ENABLED`,
  default OFF.
- **Συγχρονισμός πελατών από myDATA (+ presets διαστήματος).** Νέο κουμπί «Συγχρονισμός από myDATA»
  στους Πελάτες (καθρέφτης των Προμηθευτών): σαρώνει τις πωλήσεις μας (`RequestTransmittedDocs`),
  μαζεύει τα **ΑΦΜ συναλλασσομένων** και δημιουργεί πελάτες για όσα λείπουν, με **GSIS enrichment** για
  τα GR ΑΦΜ (η λιανική χωρίς ΑΦΜ δεν δημιουργεί πελάτη). CLI: `customers:sync --tenant=… [--months=24]`.
  Και τα δύο sync (πελάτες + προμηθευτές) έχουν πλέον **lookback presets «3/12/24 μήνες»** (default 12,
  αντί του παλιού στενού «1 μήνα») ώστε ένα run να πιάνει χρονιάς ΑΦΜ.
- **«Πρότυπα τελών» — έτοιμα προϊόντα με θεσμικό τέλος.** Νέα ενέργεια στη λίστα Προϊόντων: selective
  import γνωστών «δεμένων» τελών (πλαστική σακούλα €0,07 · εισφορά πλαστικών €0,04 · ανακύκλωσης €0,08 ·
  τέλος διαμονής) **προ-ρυθμισμένων** με το σωστό myDATA τέλος (Fees §8.5 + κατηγορία + €/μονάδα) — ο
  operator δεν ψάχνει §8.x κωδικούς. Idempotent (re-import παραλείπει υπάρχοντα)· τα βάζει σε κατηγορία
  «Τέλη & φόροι». Το τέλος υπολογίζεται μετά αυτόματα ανά παραστατικό (`RecomputeInvoiceTaxes`).
- **Αντίγραφα χωρίς κωδικό — εμφανές & προειδοποιημένο.** Το per-company «Μυστικά: Χωρίς κρυπτογράφηση»
  στα **αυτόματα αντίγραφα** δείχνει πλέον την ίδια **plaintext προειδοποίηση** με την εξαγωγή (και κρύβει
  το πεδίο συνθηματικού στο raw). Στις **«Ρυθμίσεις συστήματος»** προστέθηκε read-only **κατάσταση
  κρυπτογράφησης καθολικών αντιγράφων** (spatie): «🔒 με κωδικό» ή «⚠ ΧΩΡΙΣ κωδικό» ανάλογα με το
  `BACKUP_ARCHIVE_PASSWORD` — ώστε το «χωρίς κωδικό» να μη μένει αόρατο.
- **Pickers — περιήγηση όλου του καταλόγου χωρίς πληκτρολόγηση.** Όταν ο κατάλογος (πελάτες / ενεργά
  προϊόντα) χωράει να ξεφυλλιστεί (≤ 200), ο picker εμφανίζει **ολόκληρη** τη λίστα στο άνοιγμα (ίδια
  σειρά: αγαπημένα → most-used → αλφαβητικά), αντί να κόβει στα 30· μεγάλος κατάλογος κρατά το top
  slice + αναζήτηση. Έτσι φτάνεις και στο σπάνιο προϊόν/πελάτη χωρίς να ξέρεις το όνομα.
- **Per-customer εμπορικά defaults εφαρμόζονται στην έκδοση.** Επιλέγοντας πελάτη σε νέο παραστατικό,
  η **«Default discount %»** του μπαίνει στην έκπτωση κεφαλίδας και ο **default τρόπος πληρωμής** του
  ως **fallback** (ο τύπος παραστατικού υπερισχύει — ο πελάτης συμπληρώνει μόνο αν ο τύπος δεν ορίζει).
  Πριν τα πεδία αποθηκεύονταν αλλά **δεν εφαρμόζονταν** ποτέ. Ισχύει και στο prefill από «Νέο
  Παραστατικό» της Καρτέλας. (Η επιλογή πελάτη επαναφέρει την έκπτωση κεφαλίδας στην προεπιλογή του
  πελάτη — γράψε τυχόν ειδική έκπτωση ΜΕΤΑ την επιλογή πελάτη.)
- **Κονσόλα myDATA — auto-refresh.** (α) **Stale banner**: όταν τα δεδομένα είναι παλαιότερα από 6 ώρες
  (ή λείπουν), η κονσόλα δείχνει διακριτικό «τα δεδομένα είναι από … — πιθανώς παλιά, Ανανέωση όλων»
  αντί για το παθητικό «τελευταία ενημέρωση». (β) **Προγραμματισμένη ανανέωση** (opt-in): νέα εργασία
  `mydata:refresh-console` ζεσταίνει ΟΛΑ τα snapshots (Πωλήσεις/Έξοδα/Ε3/Εικόνα ΦΠΑ) για το τρέχον
  τρίμηνο ανά tenant — όπως το VAT picture cron, resilient per-step, **default OFF** (βαρύ AADE pull),
  toggle στις «Ρυθμίσεις χρονοπρογραμματιστή». READ-ONLY (δεν δημιουργεί εγγραφές).
- **Καρτέλα — όψη περιόδου (φίλτρα + σύνολα).** Τα φίλτρα κινήσεων (Έτος/περίοδος, Τύπος, Κατάσταση)
  εμφανίζονται πλέον **πάνω από τον πίνακα** (όχι κρυμμένα πίσω από το χωνί) — επιλογή «τρέχον/
  προηγούμενο έτος» με ένα κλικ, όπως στο Βιβλίο Εσόδων-Εξόδων. Όταν διαλέξεις έτος, εμφανίζονται και
  **σύνολα περιόδου** (τζίρος καθαρό/με ΦΠΑ, εισπράξεις, υπόλοιπο τέλους έτους + πλήθος παραστατικών),
  από το ίδιο cached per-year breakdown — χωρίς extra query. (Το τρέχον υπόλοιπο μένει full-history.)
- **Αποστολή Καρτέλας με email — επαφή-aware.** Η ενέργεια «Αποστολή στο email» στην Καρτέλα δέχεται
  πλέον **πολλούς παραλήπτες**: επιλογή (checkbox) από το email του πελάτη + τις **επαφές του** με email
  (role-labelled, π.χ. «Λογιστήριο (Μαρία) — …»), προεπιλεγμένος ο πελάτης + η κύρια επαφή, συν πεδίο για
  **ελεύθερα extra emails**. Validation + case-insensitive dedupe· το PDF αποδίδεται μία φορά για όλους.
- **«Ηλικίωση οφειλών» (aged receivables).** Νέα read-only αναφορά (Λογιστικά): ανοιχτό υπόλοιπο ανά
  πελάτη σε buckets **0-30 / 31-60 / 61-90 / 90+**, μεγαλύτεροι οφειλέτες πρώτα, σύνολα ανά στήλη,
  drill στην Καρτέλα + **CSV**. Χρησιμοποιεί το ΙΔΙΟ FIFO aging με την Καρτέλα (`AgedReceivablesReport`
  πάνω σε `CustomerLedgerBuilder`), οπότε τα νούμερα συμφωνούν.
- **Βιβλίο Εσόδων-Εξόδων — εξαγωγή PDF (οριζόντιο A4).** Νέα επιλογή «PDF (οριζόντιο)» στην Εξαγωγή:
  landscape A4 με όλες τις στήλες Έσοδα/Έξοδα + ΜΑΡΚ/κατάσταση + γραμμή συνόλων — «η κόλλα όπως στο
  Excel». Self-contained (DejaVu Sans, ελληνικά) μέσω `barryvdh/laravel-dompdf` — κανένα build.

### Changed
- **Invoice PDF — πολλαπλοί τραπεζικοί λογαριασμοί + συνολική ποσότητα.** Το PDF τυπώνει πλέον
  **όλους** τους τραπεζικούς λογαριασμούς του tenant (IBAN/SWIFT) ως τρόπους πληρωμής, όχι μόνο τον
  συνδεδεμένο — με νέο toggle **«Εμφάνιση στα τιμολόγια»** ανά λογαριασμό (κρύψε π.χ. μισθοδοσία).
  Προστέθηκε γραμμή **«Συνολική ποσότητα»** (άθροισμα τεμαχίων), όπως τα τυπικά ελληνικά τιμολόγια.
- **CMR — οδηγίες συμπλήρωσης (helper + βοηθητικά κείμενα).** Η φόρμα CMR απέκτησε επεξηγηματικό
  πάνελ («Τι είναι το CMR & πώς το συμπληρώνω») + inline helperText σε ΚΑΘΕ πεδίο (ποιο κουτί 1–24,
  τι γράφω, στα Αγγλικά) — κατά το πρότυπο του helper της Ψηφιακής Διακίνησης. Όλα τα κείμενα σε ένα
  σημείο: `App\Support\Cmr\CmrGuidance`.
- **Καρτέλα — ομαδοποίηση header actions.** Τα ~9 κουμπιά του header μαζεύτηκαν σε λίγα dropdowns:
  **Νέο Παραστατικό** (μόνο του), **«Εισπράξεις / Πληρωμές»** (Είσπραξη/Πληρωμή έναντι/Χειροκίνητη
  κατανομή/Χρήση πίστωσης/Επιστροφή), **«Εξαγωγή / Αποστολή»** (PDF/CSV/email — τώρα φαίνεται καθαρά,
  πριν «έπεφτε» εκτός οθόνης), **«Περισσότερα»** (Διασταύρωση ΑΦΜ/Επεξεργασία/Λίστα). Καθαρότερη μπάρα,
  χωρίς overflow.
- **Βιβλίο Εσόδων-Εξόδων — λογιστική όψη.** Το ημερολόγιο εμφανίζει πλέον ομαδοποιημένες στήλες
  **Έσοδα (Καθαρό/ΦΠΑ) / Έξοδα (Καθαρό/ΦΠΑ)** — κάθε γραμμή «πέφτει» στη σωστή πλευρά — με
  **γραμμή συνόλων (`<tfoot>`)** στο τέλος: σύνολα εσόδων/εξόδων + **καθαρό αποτέλεσμα** (έσοδα−έξοδα)
  και ΦΠΑ εκροών−εισροών. (Έσοδα/Έξοδα = σωστή ορολογία για απλογραφικά Β' κατηγορίας.)
  + **συντόμευση περιόδου** (dropdown): τρέχων/προηγούμενος μήνας, τρέχον/προηγούμενο τρίμηνο,
  τρέχον/προηγούμενο έτος, Προσαρμογή — χειροκίνητη αλλαγή ημερομηνίας = «Προσαρμογή».
  Το **export (CSV/XLSX)** ακολουθεί τις ίδιες στήλες Έσοδα/Έξοδα (η πλήρης όψη χωρίς scroll, για Excel).

### Fixed
- **Εισαγωγή πελάτη από ΑΦΜ έσκαγε όταν η ΑΑΔΕ επιστρέφει τεράστια περιγραφή δραστηριότητας.**
  Η περιγραφή κύριας δραστηριότητας (GSIS) μπορεί να είναι 300+ χαρακτήρες, αλλά η στήλη `occupation`
  είναι VARCHAR(120) → η INSERT έσκαγε με SQLSTATE[22001] «Data too long for column 'occupation'» και
  δεν δημιουργούνταν ποτέ ο πελάτης (π.χ. ΑΦΜ 801017172). Πλέον το `AadeRegistryRecord::primaryActivity()`
  κόβει την περιγραφή στο μέγεθος της στήλης (multibyte-safe) — προστατεύει εισαγωγή πελάτη/προμηθευτή,
  myDATA sync και το snapshot που αντιγράφεται στο τιμολόγιο.
- **AI «Βοηθός» — εργαλεία χωρίς ορίσματα έσκαγαν (400).** Όταν το μοντέλο καλούσε εργαλείο χωρίς
  ορίσματα (π.χ. «πόσα μας χρωστάνε» → `outstanding_receivables`), το `input: {}` αποκωδικοποιούνταν ως
  κενό PHP array `[]` και ξανα-στελνόταν ως JSON array → Anthropic 400 «input: Input should be an object».
  Πλέον κανονικοποιείται σε αντικείμενο. (Συν **prompt caching** — automatic· cache reads 0.1× input·
  global toggle `EKDOSI_AI_PROMPT_CACHE`, default ON· το metering ήταν ήδη cache-aware.)
- **AI «Βοηθός» — το log δείχνει την ΑΙΤΙΑ της αποτυχίας.** Σε αποτυχία κλήσης Anthropic, το laravel.log
  κατέγραφε μόνο το status («AI API error: 400»). Πλέον καταγράφει και το **μήνυμα του Anthropic** (π.χ.
  «Your credit balance is too low», «model … not found») — αυτο-εξηγείται. (Το RESPONSE body δεν περιέχει
  κλειδί.) Ο χρήστης συνεχίζει να βλέπει την ευγενική «Προσωρινό σφάλμα».
- **Header actions ξεχείλιζαν εκτός οθόνης σε στενό παράθυρο.** Το `.fi-header-actions-ctn` του
  Filament είναι `flex; flex-shrink:0` χωρίς wrap — σε σελίδα με πολλά header κουμπιά (π.χ. η Καρτέλα:
  Νέο Παραστατικό, εισπράξεις/πληρωμές, εξαγωγή…) τα δεξιά κουμπιά «έπεφταν» εκτός δεξιού άκρου, χωρίς
  να τυλίγονται και χωρίς scrollbar. Global override στο `panel.css` (shrink + `flex-wrap`) ώστε να
  τυλίγονται σε δεύτερη σειρά — διορθώνει όλες τις σελίδες με πολλά actions.
- **Custom Filament σελίδες ήταν άστυλες (no-build CSS fix).** Ο admin panel φορτώνει μόνο το
  component-CSS του Filament (καθόλου Tailwind utility layer) και δεν υπάρχει custom theme/asset
  build — οπότε grids/spacing/πίνακες σε ~23 custom blade σελίδες έμεναν άστυλα (στοιβαγμένες
  κάρτες, κολλημένοι headers· το ledger ήταν το χειρότερο). Νέο `resources/css/panel.css`
  (hand-written utilities, standard Tailwind τιμές + dark/responsive) φορτωμένο μέσω
  `FilamentAsset::register` και δημοσιευμένο από `filament:assets` (composer post-install) —
  **χωρίς npm/Vite**. Όλες οι custom σελίδες αποδίδουν πλέον σωστά + dark-mode.

### Changed
- **Βιβλίο Εσόδων-Εξόδων (#6) — ΜΑΡΚ/κατάσταση myDATA + καθαρότερη εμφάνιση.** Το `/ledger-book`
  αποκτά στήλες **ΜΑΡΚ** + **κατάσταση myDATA** (badge VALID/CANCELLED) στο ημερολόγιο και στα
  exports (CSV/XLSX/JSON). Η σελίδα ξαναγράφτηκε με **self-contained styling** (scoped `<style>`,
  responsive + dark-mode) ώστε να δείχνει σωστά χωρίς custom Tailwind build — ο panel δεν φορτώνει
  custom theme, οπότε τα utility classes έμεναν άστυλα (στοιβαγμένες κάρτες/κολλημένοι headers).

### Added
- **Αυτόματος χαρακτηρισμός εξόδων με κανόνες (#5).** Νέοι «Κανόνες χαρακτηρισμού» (Setup): «προμηθευτής
  (+ προαιρ. τύπος) → χαρακτηρισμός E3». Ο `ExpenseClassifier` τους εφαρμόζει **αυτόματα στο import**
  (recurring supplier docs έρχονται προ-χαρακτηρισμένα), υπάρχει **bulk «Εφαρμογή κανόνων»** στη λίστα
  Έξοδα, **worklist «Προς χαρακτηρισμό»** (tab+badge: ΜΑΡΚ χωρίς χαρακτηρισμό), και **«Δημιουργία κανόνα»**
  από ένα έξοδο (prefill προμηθευτή+χαρακτηρισμού). Τοπικό — η υποβολή στην ΑΑΔΕ μένει ως είναι.
- **myDATA «Outbox» — έτοιμο φίλτρο «Προς υποβολή» + dashboard tiles.** Νέα καρτέλα-φίλτρο «Προς
  υποβολή» στα «Παραστατικά» ΚΑΙ στη «Ψηφιακή Διακίνηση» = ζωντανά έγγραφα που ΘΑ έπρεπε να
  υποβληθούν αλλά δεν έχουν ΜΑΡΚ (πρόχειρα + αποτυχημένες/παραλειφθείσες υποβολές· τα imported
  legacy έχουν ήδη ΜΑΡΚ → δεν εμφανίζονται). Κοινό scope `Invoice/DeliveryNote::scopeAwaitingMyData`.
  Νέο dashboard widget **«Συγχρονισμός myDATA»**: κάρτες «προς υποβολή» (→ το φίλτρο), «τοπικές
  ασυμφωνίες» (→ τοπικός έλεγχος), «διασταύρωση με AADE» (freshness + ασυμφωνίες από το cache του
  scheduled reconcile, → κονσόλα). Όλα cheap COUNT/cache — κανένα live AADE call στο dashboard.
- **«Ανανέωση όλων» — ένα fetch για όλη την Κονσόλα myDATA.** Ένα κουμπί (πρωτεύον σε κάθε tab)
  κατεβάζει ΜΑΖΙ Πωλήσεις + Έξοδα + Επισκόπηση Ε3 + εικόνα ΦΠΑ για το διάστημα (σειριακά, rate-limit
  friendly) και «σπέρνει» την cache κάθε καρτέλας με ένα κλικ. Per-step isolation: αν μία σκάσει
  (π.χ. 429) οι υπόλοιπες συνεχίζουν και ένα toast συνοψίζει. Το per-tab «Έλεγχος» μένει ως
  δευτερεύον (single-source). Νέο `MyDataConsoleRefresh` + στατικοί `refreshSnapshot` σε όλες τις tabs.
- **«Έλεγχος ρυθμίσεων» tab στην Κονσόλα myDATA** — structured, click-to-fix view πάνω σε ένα
  νέο κοινό `MyDataConfigAudit`: ετοιμότητα tenant + κάθε τύπος παραστατικού / κατηγορία ΦΠΑ με
  badge ✓/⚠/✗, το AADE error code του κάθε ευρήματος, και link «Διόρθωση →» στη ρύθμιση. Το ίδιο
  audit τροφοδοτεί πλέον το `mydata:preflight` (thin renderer) ΚΑΙ ένα badge «Ετοιμότητα myDATA»
  στη λίστα Invoice Types — ο μισός έλεγχος ζει εκεί που ζει το config.

### Changed
- **Ενιαίος χαρακτηρισμός εξόδου** — τα δύο κουμπιά («Χαρακτηρισμός» + «ανά γραμμή») ενώθηκαν σε
  ένα με επιλογή «Ενιαίος / Μικτό (ανά γραμμή)» (το «Μικτό» μόνο για 2+ γραμμές)· ατομικό write.
- **Κονσόλα myDATA — ειλικρινές «Λείπουν από AADE» (το «203» insight).** Το `missingAtAade` σπάει
  σε **εισαγμένα** (legacy invoice με ΜΑΡΚ παραγωγής — ένα sandbox κανάλι δεν τα επιστρέφει,
  ενημερωτικό) vs **ανεπιβεβαίωτα native** (το φιλοξενούμε ως υποβληθέν αλλά το AADE δεν το γυρνά →
  πραγματικός έλεγχος). Το `discrepancyCount` (άρα toast + dashboard tile) μετρά ΜΟΝΟ τα native →
  το νούμερο «ασυμφωνίες» γίνεται αληθινό. + banner όταν `mydata_mode=sandbox` εξηγεί το γιατί.
- **Η «Ανανέωση εικόνας ΦΠΑ» έφυγε από τα «Εργαλεία»** → καλύπτεται από το «Ανανέωση όλων» της
  κονσόλας (ο scheduler `mydata:refresh-vat-picture` μένει). Τα «Εργαλεία» κρατούν πλέον μόνο το
  τοπικό «Επανυπολογισμός υπολοίπων».
- **«Άντληση από myDATA» στα Έξοδα = in-place picker, όχι redirect.** Αντί να σε πετάει στην
  Κονσόλα — Έξοδα, ανοίγει modal με τα αδέσποτα (checkbox-list, όλα προεπιλεγμένα) και καταχωρίζει
  ΑΚΡΙΒΩΣ όσα κρατάς τσεκαρισμένα — μένεις στη λίστα. Νέο `ExpenseImporter::importMarks()` (ένα
  fetch, idempotent) για το επιλεκτικό import. Το all-or-nothing του console παραμένει.
- **«Συμφωνία myDATA» → «Τοπικός έλεγχος κατάστασης»** με ρητό banner ότι είναι ΕΣΩΤΕΡΙΚΟΣ
  έλεγχος (δεν ρωτά το AADE) — ώστε να μη φαίνεται αντιφατικό όταν λέει «καμία ασυμφωνία» ενώ
  η ζωντανή Κονσόλα myDATA δείχνει διαφορές (μετράνε διαφορετικά πράγματα).
- **Ο έλεγχος ρυθμίσεων myDATA έφυγε από τα «Εργαλεία»** → στο νέο «Έλεγχος ρυθμίσεων» tab
  (richer από το text-dump κουμπί). Τα «Εργαλεία» κρατούν εικόνα ΦΠΑ + επανυπολογισμό υπολοίπων.
- **Deploy defaults to the current branch tip, not a tag.** `deploy/update.sh` with no arg now
  ships the pushed tip of the branch you're on (`origin/main` on main) and stays ON the branch —
  the `git pull` workflow, no tags to remember. Passing a tag still works (pinned release /
  rollback, checks out detached). A detached HEAD auto-recovers onto the branch. Replaces the
  earlier tag-default that bit by silently deploying an OLD tag.
- **super_admin is now GLOBAL (the operator), not a per-tenant role.** `Gate::before`
  bypasses every policy in EVERY tenant for a user who holds super_admin in ANY tenant
  (`User::isSystemSuperAdmin`, single memoised query). So the owner sees everything in a
  freshly created/restored company the moment they're attached — no per-company super_admin
  assignment, and the role-picker chicken-and-egg is gone. Data isolation is unchanged
  (CompanyScope still filters tenant data; only the permission bypass is global); per-tenant
  company_admin/operator roles are unaffected.

### Fixed
- **Deploy refuses to silently downgrade.** `deploy/update.sh` now aborts if the resolved
  target is an ancestor of the current HEAD (older code) — the failure mode that rolled prod
  BACKWARDS and deleted tracked files the old ref predated (including `update.sh` itself).
  Override for a deliberate rollback: `ALLOW_DOWNGRADE=1` (or prefer `deploy/rollback.sh`).
- **Deploy now runs `shield:generate` before the role sync.** `deploy/update.sh` only ran
  `shield:sync-super-admin`, so a release that added a new resource/page never created its
  `Permission` rows on prod until run by hand — leaving the new screen ungranted. The deploy
  now regenerates permissions (idempotent, `--all`) then re-syncs the tenant role maps, so a
  new resource is granted to company_admin/operator automatically on update. `shield:generate`
  stays deploy-time + code-driven (NOT per-user/per-company).
- **Role picker no longer grants an EMPTY role.** Assigning company_admin/operator to a
  user in a company whose roles were never permission-synced (a fresh box where
  `shield:generate` ran late, or an import `--into` heal which creates rows only) left the
  role with zero permissions → the user saw the tenant but no resources («βλέπει την εταιρία
  αλλά τίποτα μέσα»). `ensureManagedRolesExist` now backfills the baseline permission map for
  any managed non-super role that currently holds NONE (never clobbers a non-empty, manually
  customized role; super_admin still needs none).
- **Role picker: a system super_admin can bootstrap roles in any company.** The
  «Ρόλος» action gated on being super_admin in THAT company, so after restoring/
  creating a tenant you could never give yourself (or anyone) a role there — the
  button was hidden (chicken-and-egg). It now gates on being a system super_admin
  (super_admin in ANY tenant), which is the owner level (per-tenant admins get
  company_admin). Available in BOTH Users → Tenants and Companies → Users.
- **Roles robustness sweep (import/restore/attach/role-picker).** Hardened every
  tenant-role path against spatie's teams-aware Eloquent lookup that can MISS a row
  the unique index still has: role WRITES now resolve a Role OBJECT via raw
  `DB::table` and pass it to assign/remove (no by-name `findByName` → no
  `RoleDoesNotExist` crash in the role picker / standard-role assign); role READS
  (`userHoldsRole`, `roleInCompany`, `isSuperAdminAnywhere`, `hasSuperAdminIn`,
  `assignSuperAdmin`) use a raw `model_has_roles`→`roles` pivot check (no silent
  wrong-reads). Import now provisions roles **only for a NEW company** (an `--into`
  update keeps existing roles + manual permission customization), and a post-commit
  provisioning failure throws a clear «εταιρία εισήχθη — τρέξε shield:sync-super-admin»
  instead of a raw error. `CompanyObserver::deleted` + `ekdosi:prune-orphan-roles`
  now bust the spatie permission cache; the numbering-probe command cleans roles on
  its mass-delete sweep.
- **Orphan tenant roles after a company delete («Duplicate entry … super_admin»).**
  `roles.company_id` (spatie teams mode) has no FK cascade to `companies`, so a
  deleted tenant left orphan roles that collided when a later company reused the
  freed auto-increment id (e.g. on import after a MariaDB restart). `CompanyObserver`
  now deletes a tenant's roles on company delete, and `ekdosi:prune-orphan-roles`
  (dry-run/`--execute`) mops up existing leftovers. Pivots cascade from `roles`.
  `TenantRoleProvisioner` is now truly idempotent — it ADOPTS an existing role on a
  unique violation instead of throwing, so re-provisioning a company id never fails.
- **Company export/import: «Unknown column 'users_count'».** The company row is
  exported via `attributesToArray()`, which carried a non-column aggregate
  (`users_count` from the Companies list's `withCount`) into the bundle → the
  import INSERT failed (SQLSTATE 42S22). Export now keeps only real `companies`
  columns; import filters stray attributes too (so older bundles restore).


### Added
- **Φορητότητα Phase 3: επιλεκτική εξαγωγή CSV ανά entity.** «Εξαγωγή CSV» (Company →
  Αντίγραφα) με checkboxes «τι να τραβήξω» (πελάτες/προϊόντα/παραστατικά/πληρωμές/…) →
  .zip με ένα CSV ανά entity (UTF-8 BOM για Excel). Tenant-scoped, redaction μυστικών,
  + εντολή `company:export-csv --tenant= --only= [--list]`. Διαφορετικό από το
  restore-bundle (`CsvEntityExporter`).
- **Off-site backup verification στο `ops:health`.** Ανά tenant με ενεργά backups: ελέγχει
  αν υπάρχει προορισμός **εκτός VM** (sftp/ftp/s3) και αν πέτυχε η τελευταία off-site
  αποστολή· `backup.companies.offsite_gap` ανάβει για «μόνο τοπικά» ή αποτυχημένο push (CLI +
  «Υγεία συστήματος»). Διακρίνει το «πάρθηκε backup» από το «έφυγε από το μηχάνημα».
- **Ασφαλή updates: `deploy/update.sh` + `deploy/rollback.sh` + DB snapshot/restore.**
  Ένα βήμα για production update από version tag (pre-update DB snapshot → maintenance →
  checkout → `composer install` → `migrate` → `optimize` → `shield:sync-super-admin` →
  `queue:restart` → `ops:health`), με rollback (code + προαιρετική επαναφορά snapshot).
  Νέες εντολές `ekdosi:db-snapshot` (gzip mysqldump, `--keep=N`, password μέσω `MYSQL_PWD`)
  και `ekdosi:db-restore` (guarded, production → `--force`). Runbook: `docs/updates-runbook.md`.
- **WHMCS inbox: «Εισαγωγή πελάτη από ΑΦΜ (ΑΑΔΕ)» μέσα στο «Δημιουργία Παραστατικού».**
  Επεξεργάσιμο πεδίο ΑΦΜ (default το ΑΦΜ του WHMCS) με κουμπί GSIS lookup: αντλεί
  επίσημα στοιχεία ΑΑΔΕ, συμπληρώνει email/τηλέφωνο/διεύθυνση από WHMCS, δημιουργεί &
  συνδέει τον πελάτη χωρίς να φύγει ο χειριστής από το modal. Καλύπτει και γραμμές
  χωρίς/με λάθος ΑΦΜ. Όταν τα στοιχεία ΑΑΔΕ διαφέρουν από όσα δήλωσε ο πελάτης στο
  WHMCS, κρατιέται το επίσημο **με προειδοποίηση** που απαριθμεί τι διορθώθηκε
  (`WhmcsCustomerCreator` + `WhmcsCustomerCreateResult.discrepancies`). Ο creator
  τραβάει πλέον και **τηλέφωνο** (`phone1`) από το WHMCS.
- **Dev tooling: `laravel/boost`** (dev-dependency) — MCP server that grounds the
  AI coding assistant in the real app (DB schema, tinker, version-correct docs).
  Wired for Claude Code via committed `.mcp.json`; only active under
  `APP_ENV=local`/`APP_DEBUG` (zero prod footprint). Setup + the «skip the generic
  skill catalogue» decision in `docs/boost-setup.md`.
- **WHMCS inbox: «άμεση τιμολόγηση» can't-miss alerts + scannable third-party.** A paid
  immediate-invoice row now (a) floats to the top of the inbox, (b) carries the red «Άμεσο»
  bolt badge, (c) flips the nav badge to red, and (d) fires a **durable Filament database
  notification (the bell)** to the tenant's operators when it's staged — so the manual,
  reviewed path is fast enough that unattended auto-issue is optional, not needed. The inbox
  table polls every 30s. New **«Τρίτος»** badge column (single beneficiary name / «Πολλοί (N)»)
  + «Άμεσο»/«Τρίτος» quick filters. Enabled `databaseNotifications` (30s poll) on the panel.
- **WHMCS inbox: per-line third-party routing preview + one-click split.** Clicking the
  «Τρίτος» badge opens a read-only breakdown — *ποια γραμμή → ποιος δικαιούχος (ΑΦΜ) →
  Τιμολόγιο/Απόδειξη* (mirrors the WHMCS «Δρομολόγηση υπηρεσιών» screen). «Διαχωρισμός σε
  προσχέδια» is now a direct row button on multi-party rows (was buried in the «…» menu).

### Fixed
- **WHMCS third-party document-type bug (Απόδειξη vs Τιμολόγιο per party).** The WHMCS
  plugin defaults a line's `is_receipt` to `false` for the customer's OWN (non-routed)
  lines, so the manual guided-split typed a no-ΑΦΜ reseller's own portion as a **Τιμολόγιο**
  (legacy «own portion is always an invoice» bug). Now the own/reseller group's type is
  derived from the PRIMARY customer (no ΑΦΜ → Απόδειξη; has ΑΦΜ + wantsinvoice≠false →
  Τιμολόγιο); routed (third-party) lines keep their explicit per-route flag and go to the
  end-customer they're tied to. A mixed invoice correctly yields one document per party,
  each with the right type (`PendingWhmcsInvoice::ownLinesAreReceipt()`, `WhmcsInvoiceSplitter`).

### Added
- **Type-aware WHMCS auto-issue + default receipt type** (`companies.whmcs_default_receipt_type_id`).
  Auto-issue («άμεση τιμολόγηση») now picks Απόδειξη vs Τιμολόγιο from the row's intent —
  own billing by the customer's ΑΦΜ/wantsinvoice, a single third-party by the route's
  `is_receipt` — instead of always filing the default invoice type. A receipt-intent row with
  no default receipt type configured (or an ambiguous/mixed third-party, or a wants-invoice
  customer with no ekdosi ΑΦΜ) is HELD for the operator, never mis-issued. `TP_MULTI` stays
  held → manual guided split. The run summary now reports a **held count + a warn** so holds
  don't pile up unseen. Migration adds the nullable FK + a form field; backward compatible.
  **Deploy note:** an armed tenant (`whmcs_auto_issue_immediate=true`) that serves no-ΑΦΜ /
  retail immediate customers should set `whmcs_default_receipt_type_id` (Company → WHMCS bridge
  → Auto-issue), else those rows now wait in the inbox instead of auto-filing as invoices.
- **UI rename «γκρινιάρης» → «Άμεση τιμολόγηση» / «Άμεσο»** across the operator-facing strings
  (customer toggle/filter, inbox tooltip, company auto-issue section, CLI output, audit note).
  The WHMCS custom-field **role key `griniaris` is retained** (tenant field-map contract), as
  are the `needs_immediate_invoice` / `whmcs_auto_issue_immediate` columns.
- **`ekdosi:go-live-check --tenant=SLUG [--json]`** — per-tenant cutover-readiness gate
  (read-only). Consolidates the «can this tenant issue real documents?» checks into one
  pass/warn/fail report: provider, invoice-types + income classification, default VAT,
  VAT→AADE mapping, **production myDATA credentials (hard FAIL)**, mode, numbering, a
  **golden totals-drift** recompute vs the stored cache (~1-cent = WARN, more = FAIL), per-
  tenant backups, and the queue/infra slice (delegated to `OperatorHealthReport`). myDATA
  gates SKIP for non-gr-mydata tenants (Estonian/PEPPOL). Exit 0/1/2 (mirrors
  `mydata:preflight`). Pairs with the new `docs/go-live-runbook.md` for the manual steps it
  can't automate (Firebird usage probes, the real AADE production smoke-test).
- **Bridges framing (presentation-only, no pipeline change).** The WHMCS inbox is now
  the source-neutral **«Εισερχόμενα»** with a per-row **source badge** rendered from the
  `BillingSourceRegistry` (so a future WooCommerce/Blesta row reads its own label from one
  place; the `source` column already existed). New **«Γέφυρες»** page (`Bridges`, gated
  `View:Bridges`) lists the registered billing sources with TRUTHFUL status (WHMCS
  «ρυθμισμένο» = credentials present) + a «Ρυθμίσεις» link for those who can configure it —
  deliberately NO on/off toggle (the live pipeline keys off `companies.whmcs_*`, not
  `billing_connections.is_active`, so a toggle would be cosmetic). The genuine enable/disable
  + per-source credentials (`billing_connections.config`) stay Phase 1, for when a real 2nd
  bridge exists. Deploy: `shield:generate` + re-provision (new `View:Bridges` perm).
- **«Ρυθμίσεις εταιρείας» self-service page** (`CompanySettings`, gated
  `View:CompanySettings`). Lets a `company_admin` manage their OWN tenant's safe
  subset — PDF branding (logo/footer/balance-on-PDF), invoice-mail templates +
  from-address/name, the auto-email toggles, and backup enable+cadence — without the
  super_admin-only panel-global CompanyResource. Credentials (myDATA/GSIS/WHMCS/SMTP),
  e-invoice provider, tenant identity, and the sensitive backup policy (passphrase/
  destinations/retention) stay super_admin. `save()` writes an explicit whitelist only
  (no raw mass-assign — a crafted payload can't reach a non-whitelisted column), audited
  to `activity_log` under the tenant (the per-field diff lands in `attribute_changes` so
  it renders in «Ιστορικό»). A backup row first enabled here defaults to `secrets_mode=raw`
  (local-only) — a company_admin can't set a passphrase, so the `passphrase` default would
  make every scheduled run throw. company_admin auto-gets the permission (not in
  `ADMIN_FORBIDDEN_RESOURCES`); operator does not. Deploy: `shield:generate` + re-provision.
- **«Υπόλοιπο πελάτη» στο invoice PDF** (legacy «ΝΕΟ ΥΠΟΛΟΙΠΟ»). On issue
  (draft→active), an invoice that moves the running balance (credit-term sale or
  credit note) captures the customer's total Καρτέλα balance into a new
  `invoices.customer_balance_snapshot` column — stable on reprint (a live recompute
  would drift). The PDF then prints a **Προηγούμενο υπόλοιπο + αυτό το παραστατικό =
  Νέο υπόλοιπο** block, gated by a per-tenant default toggle
  (`companies.show_customer_balance_on_pdf`) with a per-customer override
  (`customers.show_balance_on_pdf`: ναι/όχι/προεπιλογή). Cash-term invoices (settled at
  issue) are skipped. Bilingual labels (EL/EN). App-issued only — the ETL/Epsilon
  raw-write importers bypass the observer.

### Changed
- **CI: dropped the `pint --test` gate** (chronically red — the tree was never
  Pint-formatted). The `Laravel` workflow now runs `php artisan test` only. Added a
  `pint.json` (laravel preset) that excludes `legacy/` and `whmcs-plugin/` so a local
  `vendor/bin/pint` skips the archived/plugin trees.
- **Καρτέλα: «αναλυτική παρακράτηση» στο ledger.** A receivable row whose collectible
  differs from the document value (withholding/τέλη) now shows a detail line «Αξία
  εγγράφου … · Παρακράτηση φόρου …» under the reference (both the page table and the
  statement PDF). Display-only — the Χρέωση/Πίστωση/Υπόλοιπο stay = payable, so the
  running balance and the paid/unpaid filters are unchanged.

### Security
- **Tenant-scope hardening (defense-in-depth).** A full audit of all ~54 CLI/queue/
  observer/webhook entry points found **0 live cross-tenant leaks** (the
  `CompanyScope` no-op-when-no-context design holds). Tightened the one query that
  relied on surrogate-PK uniqueness instead of an explicit filter: `StockService`'s
  sale/return dedup now filters `company_id` explicitly. Declared the intent of the
  deliberately all-tenant `mail-log:sweep-orphans` sweep with an explicit
  `withoutGlobalScope`. Added the CLI/queue tenant-scoping rule to `CLAUDE.md`. The
  strict null→throw enforcement stays deferred (would break ~18 safe explicit-where
  paths / false-positive on relation queries).

## [1.1.0] - 2026-06-11

### Added
- **Withholding/fees count toward what's owed.** New `invoices.payable_total` = the
  COLLECTIBLE (gross_total = net+VAT, PLUS the AADE [208] adjustment: fees/stamp/other
  up, deductions/withholding down — except the informational §8.4 withholding
  categories 8/9/10). `gross_total` stays net+VAT (revenue/turnover); `payable_total`
  is the basis for **owed/balance** everywhere — `InvoiceBalance`, the dashboard
  receivables, `Customer` owed, the overdue widget + digest, the payments cockpit, and
  the **Καρτέλα** (current balance, aging, running balance) — so a service invoice with
  20% παρακράτηση shows the reduced receivable consistently across all surfaces. The PDF
  «Πληρωτέο» now equals `payable_total` (and surfaces τέλη/χαρτόσημο/παρακράτηση lines).
  A single `Invoice::additionalTaxAdjustment()` drives both the AADE gross and the local
  payable, so they can't diverge. **Deploy:** `migrate` then
  `php artisan invoices:backfill-payable-total` (populates existing rows).
- **Bilingual / English PDF** (invoice + quote). A per-document `language` choice
  (Greek / English / **bilingual GR-EN**) drives the PDF field labels via a shared
  `App\Support\Pdf\PdfLabels` dictionary; when unset it auto-resolves from the
  recipient's country (GR → Greek, foreign → bilingual — Greek for the AADE-facing
  copy + English for the foreign reader). Labels only — never amounts/legal content.
  Set on the invoice/quote «Παρατηρήσεις» form. **Deploy:** `migrate`.
- **Manual expense entry + document attachment** (Expenses polish). Supplier docs not
  in myDATA (foreign supplier, cash receipt) can now be keyed in: a `source=manual`
  Create/Edit form with a lines repeater (header totals recomputed from the lines; the
  supplier snapshot + `company_id`/`line_number` stamped by the page), a «Χειροκίνητα»
  list tab, and edit gated to manual (myDATA-sourced expenses stay read-only). Each
  expense can carry a **private PDF/scan** (`expenses.document_path`) downloaded over a
  short-lived **signed, auth + tenant-checked** route (streamed from the local disk).
  **Deploy:** `migrate`.
- **«Ρυθμίσεις συστήματος» page** (the «Σύστημα» area). A super_admin-only page that
  surfaces the deploy-wide global knobs as audited `system_settings` toggles (env =
  default, only deviations stored): **`require_2fa`** (read live by the panel) and the
  **backup-failure alert on/off + recipient email(s)** (read live by
  `company:run-scheduled-backups`). Secrets at-rest encryption + the mailer status are
  shown **read-only** — changing encryption is done safely via `secrets:reencrypt`, not a
  silent one-click flip. **Deploy:** none (config defaults; flip in the UI).
- **«Υγεία συστήματος» page** (the «Σύστημα» area, slice 1). A read-only
  **super_admin-only** Filament page that renders the same `OperatorHealthReport`
  as `php artisan ops:health` — queue-worker heartbeat + failed jobs, scheduled-task
  last-runs/status, backups, mail, WHMCS + myDATA per tenant, disk — so an admin
  without terminal access sees liveness at a glance. Cross-tenant (every company's
  WHMCS/myDATA), so it's gated on super_admin (not a per-tenant shield permission a
  company_admin would hold); the report is short-TTL cached so a refresh can't hang
  on a large storage tree. **Deploy:** `shield:sync-super-admin`.
- **Settings-in-UI — scheduler toggles** (the «Σύστημα» area, slice 3). New
  deploy-wide `system_settings` typed store (`SystemSettings`) + a super_admin-only
  «Ρυθμίσεις χρονοπρογραμματιστή» page that flips any `EKDOSI_SCHEDULE_*` task on/off
  without editing env. `routes/console.php` reads each toggle at run-time via a
  `->when()` filter (env stays the default; the UI can also enable a task env left
  off), so a disabled task is filtered before its hooks fire. Saving stores only
  deviations from the env default (toggling back removes the override) and is audited
  (activity log + `updated_by`). **Deploy:** `migrate`.
- **Durable scheduled-task run history** (the «Σύστημα» area, slice 2). New
  `scheduled_task_runs` table — the scheduler `before`/`onSuccess`/`onFailure`
  hooks now log a row per run (status, exit code, duration, summary), surviving
  `cache:clear` (unlike the latest-only cache snapshot), pruned to the last 50 per
  task. The Υγεία-συστήματος page gains a «Πρόσφατες εκτελέσεις» history table, a
  queue **pending-jobs** count, and a super_admin «Επανάληψη αποτυχημένων»
  (`queue:retry all`) action shown only when jobs have failed. **Deploy:** `migrate`.

- **Άντληση εξόδων από myDATA από τη λίστα Έξοδα** (Πακέτο 2). A «Άντληση από myDATA»
  button on the Έξοδα list does a one-click (read-only) fetch and lands on the
  «Κονσόλα myDATA — Έξοδα» worklist with the αδέσποτα ready to import — no more
  «console → fetch → back» dance; a subheading shows «τελευταία άντληση … · X αδέσποτα».
  New `mydata:refresh-expenses` command keeps that snapshot fresh on a schedule
  (read-only — **creates no expense rows**; import stays operator-gated), **default OFF**,
  toggled from «Ρυθμίσεις χρονοπρογραμματιστή» (no env edit). The button + cron share one
  cache with the console, so all three show the same «last fetched». **Deploy:** none
  (config default; flip the toggle to schedule it).

### Changed
- **myDATA consoles unified under one «Κονσόλα myDATA» menu** (cluster, Πακέτο 1).
  The three live-AADE consoles (Πωλήσεις / Έξοδα / Επισκόπηση Ε3) are now sub-navigation
  tabs of a single `MyDataCluster` instead of three scattered nav items — each page kept
  intact (own fetch actions, `RemembersLastFetch` «τελευταία ενημέρωση», `View:*` perm,
  lazy fetch). The local «Συμφωνία myDATA» stays separate (operator, no AADE call). Old
  URLs (`my-data-console`, `…-expenses`, `my-data-e3-overview`) **301→** the new
  `mydata/{sales,expenses,e3}` paths so bookmarks survive.
- **Docs consolidation — `BACKLOG.md` is now the single source for «what's left».**
  Audited every scattered roadmap/idea `.md` against the code; the realized ones
  (services-quotes-roadmap, payments-ar-roadmap, company-portability-plan,
  aade-mark-enrich-crosscheck, expenses-phase-plan) + the obsolete one-time sandbox
  prompts (delivery-sandbox-prompt, phase2-sandbox-handoff, sandbox-validation-runbook)
  were removed, their residual open items folded into `BACKLOG.md`. Genuine
  specs/blueprints/runbooks are kept and indexed from `BACKLOG.md`. Stale «not built»
  lines for the ΔΑ movement lifecycle, §8.13 units, and MARK-enrich (all long shipped)
  are gone.

## [1.0.0] - 2026-06-10

### Added
- **Versioning + `ekdosi:release`.** The app now carries a canonical SemVer version
  (`config('app.version')`); `php artisan ekdosi:release {--major|--minor|--patch}`
  rolls `[Unreleased]` → a dated `[X.Y.Z]` heading, bumps `config/app.php`, and prints
  the `git tag` command (the level is judgement; the roll is mechanised). Discipline +
  the bump rule are in `CLAUDE.md`. First cut: **v1.0.0**.

### Security
- **BankAccount + ServiceContract now policy-gated** like every other resource. They were
  the only two models without a committed policy, so `shield:generate` regenerated stubs on
  every run AND Filament left them reachable by operators (every other lookup is admin-only).
  Committing the standard shield policies makes them admin-only (super_admin + company_admin)
  — consistent + closes the operator-visibility gap. Run `shield:sync-super-admin` after deploy.

### Changed
- **Docs reorg + CLAUDE.md slimmed.** `FEATURES.md` (root) is now the catalogue of
  what's built; `docs/BACKLOG.md` is what's left + ideas; `docs/Comparison.md`
  retired. `CLAUDE.md` trimmed ~900→~465 lines (64K→32K) by replacing the
  status/roadmap + WHMCS-version + tech-debt narration with pointers — every
  decision / convention / the VAT math / tenant-safety behavior kept.

### Added
- **FK-aware delete guard on the lookup resources** (`App\Filament\Support\GuardedDeleteAction`).
  The lookups soft-delete, so deleting one still in use left its dependents showing a
  BLANK label (and a force-delete would crash a `restrictOnDelete` FK / orphan a
  `nullOnDelete` one). The guard now BLOCKS the delete on the 8 lookup edit pages
  (VAT categories / product categories / invoice types / payment methods / delivery
  methods / distribution aims / metric units / bank accounts) with a friendly
  «χρησιμοποιείται από — προϊόντα: N · τιμολόγια: M …» count instead. Counts are
  withTrashed-aware (`GuardedDeleteAction::count()`) and the dependency maps are
  complete (incl. the non-obvious WHMCS-default + invoice-type default FKs). The
  table bulk `DeleteBulkAction`/`ForceDeleteBulkAction` stay unguarded — a noted
  follow-up.

### Security
- **Secret columns hidden from serialization.** `Company` (myDATA/GSIS/SMTP/WHMCS keys +
  provider config), `Server`/`ServerGroup` (`secret_encrypted`) and `CompanyBackupSetting`
  (`passphrase`) now carry `$hidden`, so `toArray()`/`toJson()`/logs/API never expose them
  (defence-in-depth now that secrets are plaintext at rest by default; `User` already guarded
  2FA via `#[Hidden]`). Attribute access is unchanged; the admin Company form + backup-schedule
  form re-inject the values explicitly so the edit UX is identical.

### Added
- **Expense classification → AADE submit (`SendExpensesClassification`).** The
  «Χαρακτηρισμός» action sets a pulled expense's E3 type + category2_x
  (εμπορεύματα/πάγια/δαπάνες) locally; a new «Υποβολή χαρακτηρισμού» action on
  `ViewExpense` now FILES that classification at myDATA via
  `App\Services\MyData\ExpenseClassificationSubmitter` (the inbound mirror of
  `MyDataSubmitter` — reuses `FirebedCredentials`). Visible only for a myDATA-pulled
  doc (has ΜΑΡΚ) that's classified-but-not-submitted; flips `classification_state`
  to `submitted` + writes an `expense_marks` legal audit row. Mock-Guzzle
  round-trip tested. (Manual arbitrary expense entry stays a separate, deferred
  item — this closes the pull→classify→submit loop.)
  - **Per-LINE classification** — a «Χαρακτηρισμός ανά γραμμή» action (a repeater
    over the lines) lets the same supplier invoice mix εμπορεύματα + πάγια +
    δαπάνες; the submitter prefers each line's own type+category and falls back to
    the document header, so uniform and mixed docs both file correctly.
  - **`expenses:test-classify <id>` dry-run command** — the expense twin of
    `mydata:test-submit`: prints the `SendExpensesClassification` XML (posts
    nothing) by default, `--execute` files it. For sandbox validation from the CLI.
- **DR without APP_KEY — optional at-rest secret encryption (Phase 6).** New
  `App\Casts\MaybeEncrypted` replaces the `encrypted` casts on every secret column
  (companies' myDATA/GSIS/SMTP/WHMCS keys + provider config, server creds, backup
  passphrase, user 2FA), driven by `EKDOSI_ENCRYPT_SECRETS_AT_REST` (**default
  false → plaintext at rest**). So a plain `mysqldump` is self-sufficient — a
  restore on a fresh VM needs NO old APP_KEY (protection = DB/disk access control).
  The cast ALWAYS decrypts legacy ciphertext on read, so flipping the flag never
  breaks existing rows; `php artisan secrets:reencrypt --to=plain|encrypted`
  rewrites them (with a safety net: `--to=plain` skips + fails loudly on ciphertext
  it can't decrypt, so a wrong/lost APP_KEY can't silently freeze a secret).
  Sessions/cookies are a soft dependency (a new key just means re-login). Portability's
  secret-detection routed through `MaybeEncrypted::isSecretCast()` and `servers`/
  `server_groups` `secret_encrypted` is now **redacted** from export bundles (a secret
  must not ride in a portable file — re-enter on the target). Docs: `docs/dr-without-app-key.md`.
- **PEPPOL Phase 1 — BIS Billing 3.0 (EN 16931) UBL builder** (provider-independent).
  `App\Services\Peppol\PeppolInvoiceDocument` maps a local `Invoice` → PEPPOL UBL via
  `josemmo/einvoicing` (we own only the mapping, the lib owns the syntax + EN 16931
  rules — the firebed-equivalent for the EU side). Estonia applies no national CIUS,
  so the same UBL is accepted by every Access Point + the free RIK tool.
  `App\Support\Peppol\PeppolVatCategory` resolves EN 16931 VAT categories
  (S/Z/K/G/E, country-agnostic — domestic / intra-community / export) and
  `PeppolEndpoint` derives the PEPPOL participant id (EAS scheme). New read-only
  `peppol:test-submit <invoiceId>` prints + validates the UBL (dry-run; nothing is
  sent — the Access-Point transport is Phase 2). Adds `josemmo/einvoicing`.

### Changed
- **Default seed is now ONE «DEMO Α.Ε.» tenant** (full demo mode, `mydata_mode=off`)
  + an admin user, replacing the per-developer myip/nixpal/sample-ee fixtures.
  `DemoCompanySeeder` builds a self-contained working company: lookups (VAT /
  invoice types / payment methods / units), a 5-item catalogue (incl. a service
  with a product-linked per-unit fee), 3 customers, 3 invoices (one with 20%
  withholding, one with the product fee), and 2 delivery notes — so a fresh clone
  or a reviewer can log in and see a populated tenant immediately. Idempotent
  (skips if a `demo` company exists). Real tenants come from the install wizard /
  ETL, not the seeder. `php artisan migrate --seed`.
### Added
- **`ekdosi:install` — turnkey first-run command.** Creates the first super_admin
  user + the first company, wires Shield (permissions + per-tenant super_admin /
  standard roles), and seeds the standard Greek AADE lookups (VAT categories +
  classified invoice types + payment methods + units) so a brand-new tenant can
  issue a ΤΠΥ with zero manual Setup. Safeguard: refuses to run if any user
  already exists (a populated install) unless `--force`; idempotent on the company
  slug + admin email. Interactive prompts or fully-flagged
  (`--email/--password/--company/--afm/--no-interaction`). New box from zero →
  `php artisan migrate && php artisan ekdosi:install`.
- **Δοκιμή global SMTP (.env).** A super-admin-only header action on the Companies
  list sends a probe through the app-wide `MAIL_MAILER` mailer (tenant-independent)
  — the counterpart to the existing per-company «Send a test email». Surfaces the
  active mailer + from-address (and warns when `MAIL_MAILER=log`, the common «δεν
  φεύγει τίποτα» case), with the full SMTP error on failure. Lets the operator
  verify `.env` mail works at all, which is what every tenant without its own SMTP
  falls back to.
- **«Εργαλεία» maintenance page (commands → buttons).** A new admin page (Setup
  group, gated on `View:MaintenanceTools`) surfaces safe, re-runnable artisan
  commands as one-click per-tenant buttons — Ανανέωση εικόνας ΦΠΑ
  (`mydata:refresh-vat-picture`), Επανυπολογισμός υπολοίπων
  (`invoices:recompute-balances`), Έλεγχος ρυθμίσεων myDATA (`mydata:preflight`)
  — each scoped to the current company, with the captured command output shown on
  the page. No terminal needed for routine upkeep; only read-only / idempotent
  commands are exposed. **Deploy:** `shield:generate` + `shield:sync-super-admin`
  so the page permission exists.
### Changed
- **Export χωρίς υποχρεωτικό συνθηματικό.** The company «Εξαγωγή ρυθμίσεων» panel
  action now offers a «Μυστικά» mode picker (Κρυπτογραφημένα με συνθηματικό /
  Χωρίς κρυπτογράφηση) — the passphrase is no longer required, so a settings-only
  OR full bundle can be exported with secrets in the clear for a local download
  (a warning shows). Import already accepts raw (no-passphrase) bundles; a raw
  export→import round-trip is now covered end-to-end. Step toward portability that
  works without APP_KEY/encryption.

### Fixed
- **Withholding now reduces `totalGrossValue` (AADE `[208]`).** `AadeInvoiceDocument`
  filed gross = net+vat with the withheld amount *not* subtracted, which the AADE
  sandbox rejected with `[208]` (line-gross sum ≠ total gross) for a category-3
  («Αμοιβές Συμβούλων 20%») invoice. Gross (and the matching paymentMethod amount)
  now subtract withholding for every §8.4 category EXCEPT the informational
  prepaid-tax ones 8/9/10 (architects/engineers/lawyers), mirroring firebed's
  `WithheldPercentCategory::affectsTotalGrossValue()`. Sandbox-confirmed on myip
  2026-06-10 (re-filed → accepted). The 8/9/10 informational path is coded but not
  yet sandbox-round-tripped.
### Changed
- **Repo tidy + docs.** Moved the AADE spec docs to `docs/aade/`, the sample +
  validation report under `docs/` (`docs/samples/`, `docs/`), and one-off
  notes to `docs/archive/` (references updated). New
  `docs/sandbox-validation-runbook.md` (one place to validate ΔΑ + the new
  taxTypes + product-linked + 4% on the AADE sandbox). `docs/BACKLOG.md` now
  carries the full open-items roadmap snapshot. `.env.example` gains a documented
  `EKDOSI_*` block (schedules + backup alerting) + prod notes.
### Added
- **Product-linked myDATA taxes + server-side recompute (closes the «δέσιμο» + «productionise»).**
  A product/service can carry a default fee (`products.mydata_tax_type` + `_category`
  + `_per_unit`, e.g. πλαστική σακούλα €0,07/τεμ, διανυκτέρευση €X/βραδιά). On every
  invoice save `RecomputeInvoiceTaxes` (run from `RecomputeInvoiceTotals`) aggregates
  Σ qty × per_unit per (taxType, category) into the invoice taxesTotals columns — auto,
  always from the real lines. Invoice-level %-taxes now store a `*_rate` (the «Τυπικά
  τέλη/φόροι» preset sets it) and the amount is recomputed as rate × net_total on save,
  so it's never stale (closes the preview's #2/#3). One category per taxType per invoice
  (Phase-1; conflicting products throw — the `invoice_taxes` table is the Phase-2 fix).
  Product form gains a «Δεμένο τέλος/φόρος myDATA» group with a helper explaining it.
  The recompute OWNS the tax columns (product → Σ qty×per_unit, rate → rate×totalNet,
  else → cleared), so removing a driver can't leave a stale «phantom» fee; the rate
  base is `InvoiceVatBreakdown::totalNet` (the submitter's underlyingValue). The
  invoice form now takes a %-rate (+ category) per tax type — no hand-typed amount.
  **Deploy:** `php artisan migrate`.
### Added
- **«Τυπικά τέλη/φόροι» quick-fill (preview).** A curated picker on the invoice form
  (`App\Support\MyData\CommonTaxPresets`) — Χαρτόσημο 1,2/2,4/3,6%, Τέλος διαμονής
  παρεπιδημούντων, Παρακράτηση 20% — that sets the right §8.x category and, for
  percentage-based ones, auto-computes the amount from the line net (header discount
  applied, matching the filed base). Synthetic (not persisted); the underlying
  amount/category fields stay editable, with a «recomputed at pick-time» warning.
  Also: the fees/taxes/withholding category selects now show the AADE descriptions
  (firebed `->label()`) instead of «Κατηγορία N».
### Fixed
- **Invoice PDF «Σχετικά παραστατικά» links only ISSUED delivery notes** (local_status
  active) — a draft/cancelled δελτίο no longer shows on the customer copy (same rule
  as credit notes).
### Added
- **Full myDATA taxesTotals (fees / other taxes / stamp duty / deductions).** Beyond
  withholding (G1, taxType 1), invoices can now carry a fees (2, §8.5 — e.g. τέλος
  ανθεκτικότητας), other-taxes (3, §8.6), stamp-duty (4, §8.7) and deductions (5, §8.8)
  amount + category. The submitter emits a `taxesTotals` block per type with an amount
  and sets the matching `invoiceSummary` total (was hardcoded 0); the category is
  validated against the firebed enum (deductions has none → a positive int) and throws
  when an amount lacks a valid one. New invoice columns + InvoiceForm fields. **Deploy:**
  `php artisan migrate`.
- **4% VAT category override (ν.5057/2023 ambiguity).** A 4% rate maps to AADE
  §8.2 category 6 (pre-existing island) OR 10 (αρ.31 ν.5057/2023); 3%→9. New
  optional `vat_categories.mydata_vat_category` override (Setup → VAT Categories,
  shown for 3%/4%) — the submitter prefers it, else derives from the rate (4%→6).
  Resolver throws on a same-rate disagreement or an invalid §8.2 code. **Deploy:**
  `php artisan migrate`.

### Testing
- **SendInvoices mock-Guzzle integration test** — the submitter's full `submit()`
  round-trip is now covered against a mocked AADE success response (firebed's stub):
  asserts the parsed MARK/qrUrl persist, `mydata_state=VALID`, and the INSERT
  `mydata_marks` row. Closes the gap where only `previewXml` (request-building) and
  the refusal guards were tested.
### Fixed
- **Greek ALL-CAPS in PDFs kept the τόνος (ΠΟΣΌΤΗΤΑ) — wrong + ugly in DomPDF.**
  New `App\Support\GreekText::upper()` + Blade `@gup(...)` deaccent ALL-CAPS labels
  (the Greek convention: ΠΟΣΟΤΗΤΑ, ΤΙΜΟΛΟΓΙΟ), applied across the invoice, delivery-note,
  quote and statement PDFs (dialytika kept). Also: the credit-note doc-type no longer
  wraps around the floated QR (`clear: right`) — «ΠΙΣΤΩΤΙΚΟ»/«ΤΙΜΟΛΟΓΙΟ» split fixed.
### Fixed
- **Invoice/ΔΑ PDF «Σχετικά παραστατικά» review hardening.** The «παραμένει VALID
  στην ΑΑΔΕ» note now shows only when `mydata_state === 'VALID'` (no false claim on
  a cancelled/non-myDATA invoice); a PARTIAL credit reads «Πιστώθηκε (μερικώς) με»
  (not «Ακυρώθηκε»); only ISSUED credit notes appear on the customer PDF (drafts
  hidden); the empty «Σχετικά» box no longer renders when a credit note's original
  was deleted; and the ΔΑ «Υποβολές myDATA» table now lists only real submissions
  (INSERT/PROVIDER_INSERT/CANCEL), excluding lifecycle/failed marks. `DeliveryMark::actionLabel()`
  replaces the inline label map.
### Fixed
- **Delivery-note PDF clipped the myDATA/provider verification URL.** The long
  space-less qrUrl (AADE or InvoSign `viewinvoice.php?…`) overflowed past the page
  edge — DomPDF won't break it. Now a zero-width space is injected every 8 chars so
  it wraps, same fix already applied to the invoice PDF footer.
### Added
- **Printable history / links on PDFs.** The delivery-note PDF prints an
  «Ιστορικό» section (when present): movement lifecycle events
  (`delivery_note_events`) + myDATA submission marks (`delivery_marks`). The
  invoice PDF prints a **«Σχετικά παραστατικά»** block mirroring the Filament
  panel — cancellation↔credit-note links («Ακυρώθηκε με πιστωτικό» + the credit
  note's code, the original it reverses, linked delivery notes) — so the customer
  can tie a cancelled invoice to its credit note. Customer-safe by design (no
  operator names / internal field diffs; the full audit «Ιστορικό» stays in the
  panel). Each renders only when the relation/rows exist.
- **Provider-tab credential UX (Company form).** Provider (π.χ. InvoSign) token
  fields are now pre-filled + `revealable` for copy-paste — parity with the myDATA
  subscription-key inputs (they were blank, so reveal showed nothing). `SendChannelFormBridge::hydrate`
  pre-fills secrets too; blank-submit-keeps still holds via `dehydrated(filled)`.
  Also a notice box on the myDATA tab for provider tenants explaining that the
  **myDATA read environment follows the «Τρόπος αποστολής» mode** (Δοκιμαστικό →
  reads Sandbox, Παραγωγή → Production) — so the coupling isn't a surprise.
- **Backup failure alerting.** A SCHEDULED per-company backup that ends
  failed/partial now emails ops (`ScheduledBackupFailed` notification, queued) and
  is always `Log::error`'d — previously a nightly failure was silent. Recipients:
  `ekdosi.backup.alert_email` (CSV, `EKDOSI_BACKUP_ALERT_EMAIL`), else the
  super_admin users; toggle with `EKDOSI_BACKUP_ALERT_ON_FAILURE` (default on).
  Manual/download runs already surface status in the panel, so only the
  unattended path alerts.

### Fixed
- **Scheduled backups crashed the moment cron ran them.**
  `RunScheduledCompanyBackups` called `CompanyContext::actAs()` statically, but
  it's an instance method on the singleton — a fatal Error on every real run
  (untested in 4a: only `isDue()` had coverage, not the run loop). Now
  `app(CompanyContext::class)->actAs(...)`; the new alert tests exercise the loop.
- **Full company bundle silently dropped ALL transactional data.** `BundleArchive`
  serialised only `setup/` — never `data/` — so a `--full` export / full backup
  produced a settings-only zip while reporting success (the array round-trip was
  tested, the ZIP path wasn't). `write()`/`read()` now carry `data/<table>.json`;
  a zip-roundtrip regression test guards it. (Found by review of the Phase-4a
  `full` backup bucket.)

### Added
- **Company backups — Phase 4b (remote destinations + one-click download).**
  **SFTP / FTP(S) / S3-compatible** `BackupDestination` drivers (B2 / MinIO /
  Spaces via S3), on a shared `DiskBackupDestination` base — each builds a Laravel
  disk on the fly (`Storage::build`) from per-company config in
  `company_backup_settings.destinations` (flat per entry; no `filesystems.php`).
  The «Αυτόματα αντίγραφα» modal gains a **destinations Repeater** (driver-
  conditional fields), «Τοπικά» always implied; **raw secrets to a remote target
  need an explicit acknowledgement** (not forced encryption). New **«Λήψη
  αντιγράφου τώρα»** action runs the policy and hands a short-lived **signed
  download link** (auth + `signed`, `View:Company`); the bundle streams from disk
  via a route (`CompanyBackupDownloadController`) instead of being buffered in
  memory by Livewire — the runs-history «Λήψη» uses the same link. A failed
  remote upload now **throws** (`putFileAs`→false would otherwise be logged as a
  successful backup). **Deploy:** `composer install` (adds
  `league/flysystem-sftp-v3` / `-ftp` / `-aws-s3-v3`).
- **Company backups — Phase 4a (automated local backups + coverage guard).**
  Per-company backup policy (`company_backup_settings`: cadence / bucket / secrets
  mode / retention / destinations) + a run log (`company_backup_runs`).
  `CompanyBackupRunner` builds the bundle (CompanyExporter), fans it out to a
  pluggable `BackupDestination` registry (**Local** driver — the Download source;
  SFTP/FTP/S3 are Slice 4b), prunes per retention, and logs the run; `local` is
  always included so a Download always exists. Scheduled
  `company:run-scheduled-backups` (gated `EKDOSI_SCHEDULE_COMPANY_BACKUPS`, hourly,
  fires each tenant on its own daily/weekly/monthly cadence). Filament (Company
  «Αντίγραφα» group): «Αυτόματα αντίγραφα» (policy form), «Αντίγραφο τώρα», and a
  read-only **«Αντίγραφα ασφαλείας»** runs history with per-row Download.
  **PLUS `CompanyExportCoverageTest`** — fails if ANY `BelongsToCompany` table is
  not classified for export (bucket or `CompanyExporter::INTENTIONALLY_EXCLUDED`),
  so a future tenant table can't silently fall out of backup. Run `php artisan migrate`.

### Fixed
- **ΔΑ μέσω παρόχου (InvoSign) — έκδοση 9.x.** Live InvoSign-sandbox round-trip
  (myip, gr-provider) surfaced two more mandatory-field rejections beyond the
  already-fixed `[88-006]`: the delivery `API_Counterpart` left
  `CounterpartName`/`CounterpartVat` empty for an ενδοδιακίνηση (`[88-001]`), and
  the per-line `api_*` printout twins were omitted entirely (`[88-001]
  api_lineDescription`). `InvoSignDocument::deliveryCounterpartFields` now mirrors
  `DeliveryNoteSubmitter::buildCounterpart`'s fallback chain (issuer name + ΑΦΜ
  `000000000` when no external recipient), and `augmentDelivery` now appends the
  per-line `api_*` fields (monetary fields 0.00, since delivery lines carry no
  value). Full lifecycle (issue → register → confirm → status → provider-cancel)
  now PASSes on the InvoSign sandbox.
### Added
- **Παραστατικά Διακίνησης — ιστορικό διακίνησης (lifecycleHistory timeline).**
  «Έλεγχος κατάστασης (ΑΑΔΕ)» now also captures the §4.1 event history (what the
  carrier & recipient did: RegisterTransfer/ConfirmOutcome/Rejection, with
  timestamp/ΑΦΜ/MARK + transport/outcome/rejection details) into the new
  `delivery_note_events` table, idempotent on re-poll, and shows it as a
  chronological read-only «Ιστορικό διακίνησης» tab on the delivery-note view.
  (Closes the gap left after PR #179, where `refreshStatus` discarded the
  history.) Run `php artisan migrate`.
- **Digital Delivery-Note lifecycle spec committed** at repo root
  (`docs/aade/myDATA_API_Documentation_DeliveryNote_v2.0.1_preofficial.md`) + CLAUDE.md
  Delivery-notes section: records that the full ΔΑ lifecycle (submit + register
  + confirm + status + cancel) is ALREADY built (PR #179), code-complete and
  pending only a live AADE-sandbox round-trip; the one genuine remaining gap is
  the `lifecycleHistory` timeline (carrier/recipient events).
- **Παραστατικά Διακίνησης — invoice-grade View + end-to-end binding (Φάση 1+2).**
  The delivery-note view now mirrors the invoice: a «myDATA / Πάροχος» card (state/
  MARK/QR + provider key & authentication code), a delivery «Lifecycle» card (§8.22
  state + the RegisterTransfer/ConfirmDeliveryOutcome/Reject marks), print remarks,
  and the bottom relation-manager tabs — **Γραμμές** (now an editable-while-draft
  RelationManager), **Ιστορικό υποβολών** (DeliveryMarks), **Σημειώσεις**,
  **Συνημμένα**, **Ιστορικό** — wired by giving `DeliveryNote` the polymorphic
  `HasInternalNotes`/`HasAttachments`/`TracksActivity` concerns. **Two-way related-
  document binding**: a δελτίο shows «Σχετιζόμενα → αφορά την πώληση (ΤΠΥxxxx)» and
  the invoice shows «Δελτία αποστολής → ΔΑΠy» (via the existing
  `delivery_notes.invoice_id`), the delivery analogue of the credit-note↔invoice
  link. Delivery-note changes also surface in the tenant «Δραστηριότητα» feed
  (Greek label + link + filter); `delivery_state` is intentionally NOT audited
  (poll-churned cache column). (Lifecycle completeness — Reject, event-history
  timeline — and the
  correlated/aggregate/quantitative types 9.1/9.2/10.x are separate phases pending
  sandbox + the AADE Ψηφιακό-ΔΑ spec.)
- **Invoice-type classification: smarter hint + one-click apply.** The
  `InvoiceTypeClassSuggester` (the name-based §8.1 guess shown as the list badge +
  form helper) now covers the long tail it missed — 5.2 (μη συσχετιζόμενο),
  9.1/10.1 (συσχετιζόμενα δελτία), 11.3 (απλοποιημένο), 3.1 (τίτλος κτήσης),
  6.1/6.2 (αυτοπαράδοση/ιδιοχρησιμοποίηση), 7.1/8.1 (συμβόλαια/ενοίκια εσόδων).
  New **«Χρήση πρότασης: X.Y»** hint-action on the myDATA-type field applies the
  suggested type in one click AND back-fills the income class/category + the
  goods per-line-quantity flag (G5) — only the empty fields, never overwriting an
  operator pick — with a notification that cues «ορίστε χειροκίνητα την κατηγορία
  εσόδου» for types with no safe default. Display-only stays the rule (the
  operator confirms a legal classification). The classification defaults now live
  in ONE canonical source (`Codes::TYPE_DEFAULTS`, §8.1 code → income/category/
  goods) consumed by BOTH the starter seed and the one-click, so the two write
  paths can never disagree.
- **Invoice-type starter seed extended (7 new §8.1 series).** `MyDataLookupSeeder`
  now also seeds the cross-border SERVICES twins of the goods series it already
  had — **2.2** (ΕΝΥ, ενδοκοινοτική παροχή υπηρεσιών) + **2.3** (ΥΤΧ, παροχή σε
  τρίτη χώρα), both `E3_561_005`/`category1_3` reverse-charge — plus **1.3** (ΕΞΑ,
  εξαγωγή αγαθών γ’ χωρών), **5.2** (ΠΙΜ, μη συσχετιζόμενο πιστωτικό), **11.4**
  (ΠΙΛ, πιστωτικό λιανικής), and the two missing delivery-note kinds **9.1** (ΔΑΣ,
  συσχετιζόμενο) + **9.2** (ΣΔΑ, συγκεντρωτικό). Closes the gap where only the
  goods side of EU/foreign sales had a ready series. Idempotent fill-empty —
  existing tenants get them by re-running the «Δημιουργία τυπικών σειρών» action
  in Setup → Invoice Types (operator edits/counters untouched). The §8.1 code
  table + the form dropdown already knew every type; this only pre-creates the
  common ones.
- **«Ακύρωση μέσω πιστωτικού» + visible ΤΠΥ↔ΠΙΣ binding.** On a provider-filed
  (VALID, non-9.3) invoice the «δεν υποστηρίζεται» info popup became an actionable
  button: its modal explains *why* there's no provider cancel (the help text) and,
  when a credit type is configured, issues a FULL credit note that reverses the
  original in one click (info-only when no credit type — points to Setup). Both
  documents now show their relationship under a new ViewInvoice «Σχετικά
  παραστατικά» section (original → its credit note(s); credit note → the invoice it
  reverses), driven by the existing `credited_invoice_id`. «Ακύρωση & επανέκδοση»
  stays for the reissue case. The local-only «Ακύρωση» is now hidden on a
  provider-filed (VALID) invoice — it would desync from AADE; the credit note is
  the only correct reversal there (direct-myDATA keeps it).
- **Fully-credited invoice now reads as cancelled + offers only «Επανέκδοση».**
  When an original's `credited_total` reaches its gross (`Invoice::isFullyCredited()`),
  the View page shows an «Ακυρώθηκε με πιστωτικό» badge (the credit-note equivalent
  of a myDATA CANCELLED state — without flipping `local_status`, which would double-
  remove it from the ledger), HIDES the now-moot credit/cancel actions («Έκδοση
  πιστωτικού» / «Ακύρωση μέσω πιστωτικού» / «Ακύρωση & επανέκδοση»), and shows a
  single «Επανέκδοση» action that re-bills via a fresh draft copy (`App\Actions\
  ReissueInvoiceAsDraft`, also now the shared reissue half of `StornoAndReissue`).
- **Provider correction flow on a MARKed invoice (no cancel via πάροχο).** A
  MARKed invoice transmitted through a Provider (ΥΠΑΗΕΣ) can NOT be cancelled —
  only a credit note reverses it (general ΥΠΑΗΕΣ rule). ViewInvoice now: gates
  «Ακύρωση μέσω παρόχου» to 9.3 δελτία αποστολής (the lone cancellable type);
  shows an informational «Ακύρωση μέσω παρόχου;» popup explaining WHY there's no
  cancel + routing to the right action; and adds a one-click «Ακύρωση &
  επανέκδοση» (`App\Actions\StornoAndReissue`) that issues a FULL credit note
  (opt-in myDATA filing) AND opens a fresh draft copy of the original to fix and
  re-issue. Direct-myDATA tenants are unchanged (AADE's CancelInvoice still
  cancels a 2.1).

### Fixed
- **Παραστατικά Διακίνησης μέσω παρόχου — `[88-006] Λείπει το API_InvoiceDetails`.**
  `InvoSignTransport::sendDelivery` skipped `InvoSignDocument::augment` (on the
  wrong assumption that delivery notes need no printout extension), so the
  provider-issued δελτίο carried no `<API_InvoiceDetails>` and InvoSign rejected
  it. New `InvoSignDocument::augmentDelivery` appends the mandatory invoice-level
  block (issuer + recipient-as-counterpart, built from the `DeliveryNote`) and
  applies the same icls/ecls→n1/n2 normalisation; the shared `buildApiInvoiceDetails`
  was generalised so invoice & delivery emit an identical block shape. (Per-line
  `api_*` twins stay invoice-only — to be confirmed for goods 9.x on the InvoSign
  sandbox.) **NB:** distinct from the `mark_time` drift below (a DB write on the
  direct-myDATA path) — this is the provider issue path.
- **Παραστατικά Διακίνησης — provider-channel lifecycle resolved (full model).**
  The InvoSign reference confirmed the πάροχος exposes only issue + cancel-DN, so:
  **ακύρωση → μέσω παρόχου** for `gr-provider` tenants (`DeliveryLifecycleService::
  cancelViaProvider` → `iNVOSign_CancelDeliveryNote`; the INSERT-MARK lookup now
  also matches `PROVIDER_INSERT`), **έναρξη/παράδοση/έλεγχος/history → απευθείας
  myDATA** for all (the earlier interim guard was removed; `initFirebed`'s creds
  check gates a provider tenant without myDATA creds). ΔΑ provider payload also
  gained `DocumentDispatchFrom/To` + `DocumentMovePursposeLabel` per InvoSign's ΔΑ
  example. See `docs/delivery-provider-split-brain.md`.
- **Παραστατικά Διακίνησης — `delivery_marks.mark_time` schema drift.** The live
  column had drifted to `TIMESTAMP` (migrated before the create migration's source
  was corrected to `TIME`), so the MARK persist — which writes `now()->toTimeString()`
  (`'HH:MM:SS'`) — failed under MariaDB `STRICT_TRANS_TABLES` (SQLSTATE 22007 / 1292),
  rolled back the issue INSERT, and left the δελτίο never reaching `VALID` (lifecycle
  skipped). New migration realigns it to `TIME`, the twin of the invoice-side
  `mydata_marks` fix. Surfaced by the first live AADE-dev delivery round-trip
  (`delivery:sandbox-validate --execute`), which then passed end-to-end (ΕΚΔΟΣΗ →
  ΕΝΑΡΞΗ → ΠΑΡΑΔΟΣΗ → ΕΛΕΓΧΟΣ, lifecycleHistory parsed). Run `php artisan migrate`.
- **Provider tenants no longer lose the myDATA read surfaces.** Switching a
  company to a ΥΠΑΗΕΣ provider (`gr-provider`) wrongly hid the dashboard «Εικόνα
  από myDATA — ΦΠΑ», the myDATA consoles (έσοδα/έξοδα), the Ε3 overview, the
  live ΜΑΡΚ orphan lookup and the supplier «Συγχρονισμός από myDATA» — even
  though the documents still sit at AADE under the tenant's own ΑΦΜ and are read
  with its own subscription. Split the gate: a new `Company::canReadMyData()` /
  `mydataReadMode()` predicate (read access = has myDATA read credentials; for a
  provider the read environment follows `einvoice_provider_mode` — the same
  sandbox/production twin the rest of the provider stack keys off — so reads land
  on the same env the tenant submits to, with a fallback to the other populated
  slot since a read never writes to AADE) now drives all read-only surfaces,
  while SUBMISSION stays `gr-mydata`-only. `FirebedCredentials` resolves the
  provider's read environment; a shared `Company::myDataReadable()` set feeds the
  `mydata:refresh-vat-picture` + scheduled `mydata:reconcile-sales` +
  OperatorHealth so they never drift from the widget's gate. Submit-side gates
  (dry-run preview, submitter factory, preflight) are untouched.
- **Provider submission history now shows what we ACTUALLY sent + a failed cancel:**
  the «Ιστορικό υποβολών» stored the AADE-core XML (pre-augment) as the request, not
  the real payload the provider received — `ProviderResult` now carries the sent
  payload (InvoSign's augmented `xml_arxeio`) and it's stored on the PROVIDER_INSERT/
  PROVIDER_REJECTED mark. And a REJECTED cancellation (e.g. InvoSign [283] — its
  CancelDeliveryNote is 9.3-only, so a 2.1 invoice can't be cancelled that way) now
  writes a forensic `PROVIDER_CANCEL_REJECTED` row (request + response) and leaves
  the invoice VALID, instead of throwing with no trace.
- **InvoSign sandbox rejection «[88-004] Missing or wrong xmlns:n1»:** firebed emits
  the income/expense classification namespaces as `icls`/`ecls`, but InvoSign's parser
  is prefix-strict and requires `n1`/`n2` (its API sample). `InvoSignDocument` now
  renames the prefixes (URIs unchanged) for the InvoSign payload ONLY — the direct
  myDATA path is untouched (AADE matches by URI). Also aligned `api_quantity` to the
  documented 4-decimal sample (`1.0000`).

### Fixed
- **Invoice lifecycle is provider-aware (the missing last mile):** a `gr-provider`
  tenant now actually sees a working **«Αποστολή στον Πάροχο»** action on the invoice
  (and «Ακύρωση μέσω Πάροχο (…)») — previously the submit/cancel actions were gated
  on `mydata_mode`, which is `off` for a provider tenant, so nothing showed and "it
  didn't send". The gate now uses `Company::submitsElectronically()` (direct myDATA
  OR provider, non-off); the action body was already factory-routed
  (→ GrProviderSubmitter → InvoSign), so the engine was ready. Labels/headings/
  notifications are channel-aware (show the provider name). Added a read-only
  **«Προεπισκόπηση παρόχου (XML)»** action (exactly what would be sent — no network,
  no token), and the submission-history tab now badges PROVIDER_INSERT/CANCEL/REJECTED
  + shows the provider + authentication code. So "τι στείλαμε / τι γύρισε ο πάροχος"
  is visible per invoice. No change to the direct-myDATA behaviour.

### Added
- **Per-company backup — settings + setup export/import (Phase 1).** New
  `company:export --tenant=SLUG` writes a portable `.zip` (manifest + company
  settings + setup/lookup tables + logo) and `company:import --file=… (--new |
  --into=SLUG)` restores it — the per-tenant backup the portability plan (#211)
  describes, so one company can be restored without a full-DB rollback that
  would clobber other live tenants. The 7 encrypted columns are
  **passphrase-sealed** (PBKDF2 + AES-256-GCM via `SecretsCodec`), decoupling
  at-rest APP_KEY encryption from transport; `--raw` opts into a cleartext debug
  dump. Import is **dry-run by default** (`--execute` applies), **idempotent**
  (setup matched by natural key, updated in place — never delete+insert, so
  matched ids survive and transactional FKs don't dangle), re-encrypts secrets
  under the target VM's `APP_KEY`, and rewires intra-setup FKs (invoice-type
  distribution/delivery, server→group, company default invoice type). README
  documents usage. **UI:** the Companies table now has an «Αντίγραφα» group
  («Εξαγωγή ρυθμίσεων» download + «Εισαγωγή ρυθμίσεων» upload-restore with
  dry-run preview) and a toolbar «Εισαγωγή εταιρίας από αρχείο» (create-new) —
  same passphrase flow as the CLI, via the shared `BundleArchive` zip
  reader/writer.
- **Full (`--full`) bundle — transactional data too (Phase 2).** `company:export
  --full` (+ a «Πλήρες» toggle in the UI) adds customers/suppliers/products/
  invoices(+lines/MARKs/extras/mail-logs)/payments/quotes/expenses; the importer
  restores them with every FK rewired to the new ids — incl. the invoice
  credit-note self-reference (nulled on insert, patched after the pass) — and
  drops cross-tenant user refs. A complete per-tenant snapshot for moving a
  company to its own VM. Deferred (v1): delivery notes, service contracts, stock
  movements, WHMCS inbox, activity log, notes/attachments (polymorphic /
  re-derivable). Round-trip test asserts the rewiring + self-ref.
- **Per-company transactional wipe (the clean slate).** `company:wipe
  --tenant=SLUG` (+ «Διαγραφή δεδομένων» in the «Αντίγραφα» menu) deletes a
  tenant's transactional data (invoices/payments/customers/…) while **keeping**
  the company row + settings + the setup/lookups — the safe reset before a
  Firebird re-import. Dry-run by default (`--execute` applies); `--keep-parties`
  preserves customers/suppliers/products, `--reset-counter` rolls ΑΑ counters to
  1; FK order handled via `Schema::withoutForeignKeyConstraints`; **`--force`
  required** when invoices are filed at AADE (a local wipe doesn't cancel them
  there). `CompanyDataWiper` + tests (wipe keeps settings/setup, keep-parties,
  reset-counter, the AADE-filed guard, read-only plan).
- **«Συγχρονισμός κατάστασης από ΑΑΔΕ» (2-way state sync) on the ΜΑΡΚ page.**
  After «Άντληση/έλεγχος από ΑΑΔΕ» finds a *state* divergence, a new
  admin-gated, confirmed action applies AADE's truth to the local invoice:
  AADE `CANCELLED` → local cancelled (mirrors a myDATA-portal cancellation back,
  incl. best-effort WHMCS write-back); AADE `VALID` → local `VALID` and a
  wrongly-`cancelled` `local_status` is un-cancelled to `active`. New
  `SyncInvoiceStateFromAade` service writes a `STATE_SYNC` forensic audit row
  (from→to). `EnrichInvoiceFromAade` stays report-only (never auto-applies state).
- **Cancellation mark captured.** A successful `CancelInvoice` returns its own
  «Μοναδικός Αριθμός Ακύρωσης»; it's now stored in the new
  `mydata_marks.cancellation_mark` (the CANCEL row still keeps the original MARK).
- **Opt-in per-line description to myDATA (`<itemDescr>`).** New
  `companies.mydata_send_item_descr` toggle (myDATA tab, default OFF): when on,
  `AadeInvoiceDocument` emits the line's `product_descr` as `<itemDescr>`
  (256-char clamp). Default OFF keeps the request byte-identical to the sandbox-
  validated shape — the legacy app never sent it (verified against imported
  legacy MARK XML, which carries only the E3 income classification per line).
  AADE accepts `itemDescr` ONLY for delivery-note / shipping types (9.x) and
  REJECTS it on a plain ΤΠΥ/ΤΙΜ (spec line 1287), so emission is also gated by
  document type (`Codes::allowsItemDescr`) — the knob can never produce a
  rejection; on ordinary invoices it's a no-op. Sandbox-validate before flipping
  on for a goods/delivery-note tenant.

### Fixed
- **Rejected myDATA cancellation wrongly marked the invoice CANCELLED.** AADE
  returns HTTP 200 + `ValidationError` (e.g. `[301]` "mark not found") for a
  refused `CancelInvoice`, and firebed does NOT throw on that — so the cancel
  path flipped `mydata_state`/`local_status` to cancelled AND pushed "cancelled"
  to WHMCS for a cancellation AADE never performed (2026-06-05 incident: a
  301-rejected cancel left ΤΠΥ6654 locally cancelled while AADE had no record).
  `MyDataSubmitter::cancel` now checks the response `statusCode === 'Success'`
  (mirroring the INSERT guard); on refusal it records a forensic
  `CANCEL_REJECTED` audit row (no MARK, response XML kept) and throws **without**
  mutating state or touching WHMCS.
- **PDF footer myDATA URL was visually clipped.** The full AADE verification URL
  (one ~150-char token) overflowed the fixed footer and got cut on both sides —
  it READ like a truncated/wrong URL (the real source of the «λάθος URL»
  confusion; the stored value was always correct). Now wraps across lines via
  zero-width break opportunities + overflow-wrap; the value is unchanged.
- **myDATA QR unscannable / misread on the printed PDF.** The ~150-char AADE
  qrUrl produced a dense (v8, 49×49) QR rendered at only 200px with an 8px quiet
  zone, then scaled to 28mm — phones misread it (truncated / wrong host on scan).
  The PDF now renders the QR at 600px with a size-proportional (~4-module) quiet
  zone and prints it at 32mm. Stored data was always correct (the MARK-detail QR
  and the printed URL text were right); this is purely render scannability.
### Added
- **«Έλεγχος ΜΑΡΚ» — first-class page + lookup.** `MyDataMarkDetail` is now in the
  menu (Data group); a «Αναζήτηση ΜΑΡΚ» action lets you check ANY MARK by hand
  (local invoice or live AADE orphan), and a blank landing prompts for one
  instead of 404-ing. The raw request/response XML panels are expanded by default
  and much taller (rows 26, char counts) for debugging visibility; the page
  already shows the QR + a clickable «Σύνδεσμος επισκόπησης ΑΑΔΕ».
- **Clickable MARKs.** The MARK on the invoice LIST (`InvoicesTable`) and the
  invoice VIEW (`InvoiceInfolist`) now LINK to «Έλεγχος ΜΑΡΚ» instead of just
  copying themselves. (The myDATA console, submission-history relation manager and
  latest-invoices widget already linked.)
- **WHMCS Inbox UX.** (1) «Συγχρονισμός τώρα» header action (next to «Έλεγχος
  legacy») — pulls paid+unfiled invoices on demand via `whmcs:fetch-pending`
  (handy for testing, no SSH/cron). (2) The «Δημιουργία Παραστατικού» modal's
  invoice-type picker now shows the tenant's FAVORITE types first (⭐, same
  `PickerOptions::invoiceTypeOptions` ordering as the normal invoice form) instead
  of a flat alphabetical list. (3) A second submit button «Δημιουργία & έλεγχος»
  creates the draft AND redirects straight to the new παραστατικό (the plain
  «Δημιουργία προσχεδίου» still stays in the inbox for creating several in a row).
### Fixed
- **WHMCS invoice line description was dropped from the παραστατικό.**
  `WhmcsInvoiceMapper` emitted the line text under the key `description`, but the
  `invoice_lines` column is `product_descr` — so `InvoiceLine::create` (mass
  assignment) silently discarded it (not fillable). The filed invoice/PDF showed
  «—» for the line while price + VAT came through. The mapper now emits
  `product_descr`; a filer regression test asserts the persisted line carries it.
### Added
- **Πάροχος Console + verification tooling (P4):** a read-only «Πάροχος» Filament
  page (gr-provider tenants) showing the readiness preflight + recent provider
  submissions + a reachability «Έλεγχος σύνδεσης». `App\Services\EInvoice\
  ProviderPreflight` (no-network config audit: provider/transport/creds/AFM/myDATA
  read-path/invoice-types) drives both the page and two commands —
  `einvoice:preflight [--tenant=]` (exit 0 ready / 2 has-fail) and
  `einvoice:provider-test-submit <id> [--execute]` (dry-run prints the exact
  payload incl. the InvoSign extension; `--execute` is a guarded, non-persisting
  provider probe). No secrets are rendered (counts only).
- **InvoSign provider transport (P5):** the first real ΥΠΑΗΕΣ transport —
  `App\Services\EInvoice\Transports\InvoSignTransport` (+ `InvoSignDocument`, which
  DOM-augments the canonical AADE XML with InvoSign's `api_*` line twins +
  `<API_InvoiceDetails>` extension, never touching the AADE core). send/cancel/
  status/ping over the documented form-POST API, parsing the `<ResponseDoc>` into a
  `ProviderResult` (ΜΑΡΚ + authentication code + QR). Sandbox/production creds
  (`demo_base_url`/`demo_token` vs `base_url`/`token`) selected by the channel mode.
  Registered in `config ekdosi.einvoice.providers`, so the factory now routes
  `gr-provider` + `invosign` + non-off → a live InvoSign filing. `EInvoiceProvider
  Transport::send()` now also receives the `Invoice`. Grounded in the captured API
  reference; **sandbox-validate the exact field/price semantics before go-live.**
  Mock-HTTP tested end-to-end (factory → submitter → InvoSign → PROVIDER_INSERT).
- **WHMCS custom-field mapping picker.** A Company-form action «Άντληση &
  αντιστοίχιση πεδίων WHMCS» pulls the WHMCS client custom-field catalogue
  (`WhmcsBridgeClient::listCustomFields` → bridge `op=custom_fields`) and lets the
  operator map each role (vatno / wantsinvoice / taxoffice / occupation /
  griniaris) to a field by NAME via dropdowns — instead of hand-typing fragile
  integer ids. The `whmcs_custom_field_map` KeyValue now shows a ⚠ warning when
  empty (an empty map silently drops AFM + invoice-vs-receipt intent and leaves
  the WHMCS customer «μη συνδεδεμένος» — the root cause just diagnosed in prod).
  Requires ekdosi_bridge v0.39.0.
- **E-invoice provider operator UI (P3):** the Company form gets ONE flat
  «Τρόπος αποστολής παραστατικών» dropdown — `myDATA — Παραγωγή/Δοκιμαστικό`,
  `Καθόλου (μόνο PDF)`, and per-provider `InvoiceSign/SBZ — Δοκιμαστικό/Παραγωγή`
  (from `config ekdosi.einvoice.provider_labels`). It drives the four underlying
  columns + the encrypted provider-config blob via `SendChannel` (pure, tested
  channel↔columns brain) + `SendChannelFormBridge` (page-hook hydrate/dehydrate;
  secrets follow "blank = keep stored"). A conditional «Πάροχος» tab shows labeled
  credential inputs (no raw JSON) + «Έλεγχος σύνδεσης» (→ transport ping; clear
  "δεν έχει ενεργοποιηθεί ακόμη" notice until the transport is wired). The myDATA
  tab now shows for provider tenants too (myDATA creds = the read/reconciliation
  path). Operator-friendly Greek helper text throughout. New-GR-tenant lookup
  seeding now covers `gr-provider` too. No behaviour change to filing. **Deploy:**
  `php artisan migrate` already covered the columns (P1).
- **E-invoice provider submitter (P2):** `App\Services\EInvoice\GrProviderSubmitter`
  — files an invoice through a certified ΥΠΑΗΕΣ provider by reusing the SAME
  `AadeInvoiceDocument` payload and handing the XML to the injected transport.
  Persists a `PROVIDER_INSERT` `mydata_marks` row (+ `provider_key` /
  `authentication_code` / `delivery_state`) and syncs the invoice mirror columns
  exactly like the direct path; `PROVIDER_CANCEL` on cancel, `PROVIDER_REJECTED`
  forensic row on a provider rejection. Pre-submit state guards + **§14.4
  idempotency**: an ambiguous send failure (timeout) status-checks by invoice
  coordinates and ADOPTS an existing MARK instead of double-filing. The factory
  routes `gr-provider` + mode≠off → `GrProviderSubmitter` (transport from the
  registry; mode=off stays staged/NullSubmitter). WHMCS write-back fires on a
  provider filing/cancel too (parity with the direct path — keyed on
  `whmcs_pending_id`, no-op for non-WHMCS); auto-email stays a follow-up. Still no
  real provider wired (InvoSign/SBZ = P5); no behaviour change for existing
  tenants. `EInvoiceProviderTransport::status()` takes an `Invoice` (coordinate
  lookup, not by-MARK).
- **E-invoice provider seam (P1):** generic, no-op infrastructure for filing via a
  ΥΠΑΗΕΣ provider — `App\Contracts\EInvoiceProviderTransport` (+ `ProviderResult` /
  `ProviderCredentials` DTOs) and a config-driven `ProviderTransportRegistry`
  (mirrors the billing/provisioning registries; unknown/empty key →
  `NullProviderTransport`, which throws on send so a misconfig never silently
  not-files). New per-tenant columns `companies.einvoice_provider_key` /
  `einvoice_provider_config` (encrypted JSON, KEEPING the myDATA creds untouched) /
  `einvoice_provider_mode`, and provider audit columns on `mydata_marks`
  (`provider_key` / `authentication_code` / `delivery_state`). No provider wired
  yet (InvoSign / SBZ land at P5) and no behaviour change — `gr-provider` stays
  inert (PDF-only) until `GrProviderSubmitter` (P2). **Deploy:** `php artisan migrate`.

### Changed
- **E-invoice provider groundwork (P0):** factored the AADE payload builder out
  of `MyDataSubmitter` into `App\Services\EInvoice\AadeInvoiceDocument` (`build()`
  + `toXml()`), so the SAME canonical invoicesDoc XML can later feed a provider
  submitter (ΥΠΑΗΕΣ) — `MyDataSubmitter` now owns only the transport. Pure no-op
  refactor (byte-identical XML), guarded by the existing myDATA safety/golden
  tests; `mydata:test-submit --print-only` and the VAT-rate drift test updated to
  the new class. Design: `docs/paroxos/`.

### Fixed
- **Second independent-review batch** (regressions the first fix-batch added):
  official-PDF `Content-Disposition` ASCII filename now restricted to a safe
  charset (an operator invoice-type code with a `"` could break the quoted
  filename); cancel write-back resolves the pending row via two DETERMINISTIC
  lookups (forward link first) instead of one OR that could pick an arbitrary
  duplicate. (Plus plugin-side: column-cache invalidation, pdf_url-reject logging
  — ekdosi_bridge v0.38.0.)
- **Independent-review batch (Plugin-API/#4/#5).** (#1) `PublicInvoicePdfController`
  now serves only `Invoice::isPubliclyViewable()` (active + not AADE-cancelled) —
  fail-closed, so a cancelled or unknown-status invoice 404s instead of streaming
  as a valid παραστατικό; the bridge hides its PDF button too. (#7) the cancel
  write-back resolves the pending row by EITHER link (invoice.whmcs_pending_id OR
  pending.invoice_id) so filer-path invoices also flip to «ΑΚΥΡΩΜΕΝΟ». (#6) the
  Company «Fetch pending» button always delegates to `whmcs:fetch-pending` (one
  source-selection + legacy-refresh path; removed the duplicated native loop).
  (#10) official-PDF `Content-Disposition` uses RFC 5987 `filename*` so a Greek
  invcode survives strict proxies. Plus plugin-side fixes (banner aliasing, log
  hygiene, pdf_url host validation, JS escaping, query batching) in
  ekdosi_bridge v0.37.0.
### Added
- **Official invoice PDF — signed public route + write-back (#5a).** New
  auth-less but `signed` route `GET /invoice/{invoice}/official-pdf`
  (`PublicInvoicePdfController`, refuses drafts, streams via `InvoicePdfRenderer`)
  + `Invoice::publicPdfUrl()` (permanent HMAC-signed). The write-back hands the
  bridge this URL (`WhmcsBridgeClient::setInvoiced(..., $pdfUrl)`), so the WHMCS
  admin manage-invoice page links the official παραστατικό — PDF stays on ekdosi
  (source of truth), no copy. **#5b:** the same link can be shown on the WHMCS
  CLIENT-AREA invoice page too, behind the plugin's «Show official PDF to
  customers» switch (default OFF — admin-only until flipped on for testing).
- **Cancellation write-back to WHMCS (state).** When ekdosi cancels an invoice at
  AADE, `MyDataSubmitter::cancel` now re-pushes the SAME MARK with
  `state='cancelled'` (new `WhmcsWritebackService::syncCancelledFromLifecycle`,
  `WhmcsBridgeClient::setInvoiced(..., $state)`), so the WHMCS bridge badge shows
  «ΑΚΥΡΩΜΕΝΟ» instead of a stale valid MARK. Best-effort + outside the DB
  transaction; no-ops for non-WHMCS / split / never-filed invoices. The VALID
  path now stamps `state='active'`.
- **Plugin-API consolidation — push path fetches via the bridge.** The WHMCS
  invoice-paid webhook (`WhmcsInvoicePaidController`) now pulls the canonical
  invoice payload from the ekdosi_bridge plugin (`resolve.php op=invoice`, via the
  new `WhmcsBridgeClient::fetchInvoice`) for tenants on `whmcs_fetch_via_bridge`,
  instead of the native WHMCS API — so BOTH the inbox pull and the push share one
  HMAC path (the Plugin-API). Native API stays the path for tenants without the
  plugin. A bridge config gap → 422, same as before.
- **`whmcs:use-bridge --tenant=SLUG [--off]`** — guarded switch for a tenant's
  invoice SOURCE (Plugin-API vs native WHMCS API). ENABLING probes the deployed
  plugin for `op=invoice` support first and refuses to flip if it's older than
  v0.32.0 (closes the deploy-ordering trap that would break the push path);
  reversible with `--off`. The flag drives both pull and push; plugin-less
  tenants stay on the native API ("API only when there's no plugin").
- **Company «Fetch pending» button → Plugin-API for bridge tenants.** The admin
  Company form's manual fetch now delegates to `whmcs:fetch-pending` for tenants
  on `whmcs_fetch_via_bridge` (same source + legacy-invoiced refresh as the
  scheduler), instead of its own native-API loop — closing the last spot that
  still hit the WHMCS API on the happy path. Plugin-less tenants keep the native
  loop.
### Fixed
- **Scheduler silent multi-day stall — bounded `withoutOverlapping(30)`.** Every
  scheduled task used the default 24h overlap-lock TTL; a run killed mid-flight
  (reboot/deploy/OOM) orphaned the cache lock and every later `schedule:run`
  SILENTLY skipped the task for a full day — how the WHMCS fetch went dark ~1.5
  days. Now the lock self-heals in ≤30 min (tasks are idempotent, so a rare real
  overlap is benign).
### Added
- **Προσφορές → Υπηρεσία (μετατροπή).** Νέα ενέργεια **«Μετατροπή σε Υπηρεσία»**
  σε αποδεκτή προσφορά: φτιάχνει **recurring service contract** (για τις
  μελλοντικές ανανεώσεις) **+** το **πρώτο πρόχειρο παραστατικό** με ΟΛΕΣ τις
  γραμμές της προσφοράς (εφάπαξ + recurring 1ης περιόδου, με τις πραγματικές
  περιγραφές — τίποτα δεν ισοπεδώνεται σε γενικό setup). Οι recurring γραμμές
  (`product.is_recurring`) ορίζουν το ποσό/προϊόν του συμβολαίου· ο cursor ξεκινά
  στην έναρξη, οπότε η έκδοση του 1ου προχείρου προωθεί έναν κύκλο (period 1 →
  2)· οι επόμενες ανανεώσεις = μόνο η recurring γραμμή. `ConvertQuoteToServiceContract`
  (πρότυπο `ConvertQuoteToInvoice`)· `quotes.converted_service_contract_id`
  provenance + αμφίδρομο ιστορικό· idempotent. **Μηδέν money/AADE** (πρόχειρο +
  contract). `ConvertQuoteToServiceContractTest`. **Deploy:** `php artisan migrate`.
- **Υπηρεσίες/Συμβόλαια (recurring) — data model (PR-A, schema only).** Ο WHMCS-
  style διαχωρισμός: το `products` γίνεται κατάλογος (νέα `is_recurring` +
  `provisioning_module` + `module_meta`) με **per-cycle price matrix**
  (`product_billing_prices`: setup_fee/price/enabled ανά κύκλο), και ο νέος
  `service_contracts` είναι η **per-customer συνδρομή** (customer/product snapshot
  amount+cycle+vat, `invoice_type_id` ανανέωσης, status, start/next_due/end dates,
  domain, server). Νέα enums `BillingCycle` (advance() NoOverflow) +
  `ServiceContractStatus` (state machine με Suspended). **Πρόβλεψη native/WHMCS-
  independent provisioning** από τώρα (schema-only): `servers` + `server_groups`
  (credentials με `encrypted` cast), `service_contracts.server_id`/`module_meta`
  (license key / cPanel user / mailcow domain). `invoices.service_contract_id`
  (provenance). **Μηδέν money impact** — isolation test ότι contracts/servers δεν
  αγγίζουν `InvoiceScope`/receivables. UI + staging σε επόμενα PR (B/C).
  **Deploy:** `php artisan migrate`.
- **Υπηρεσίες/Συμβόλαια (recurring) — UI + lifecycle (PR-B).** `StageServiceRenewal`
  action: για ένα due `ServiceContract`, σε ΕΝΑ `DB::transaction` (mirror του
  `IssueCreditNote`/`createDraft`) δεσμεύει ΑΑ με `InvoiceNumberer` υπό lock,
  φτιάχνει **πρόχειρο** παραστατικό (`service_contract_id`, customer snapshot, μία
  γραμμή από contract.amount=net + vat_percent· **+ γραμμή «Τέλος εγκατάστασης»**
  μόνο στο ΠΡΩΤΟ τιμολόγιο όταν `setup_fee>0`) → `RecomputeInvoiceTotals`·
  **καμία υποβολή AADE/email** (ο χειριστής εκδίδει από το lifecycle). **Το
  `next_due_date` προωθείται στην ΕΚΔΟΣΗ** (draft→active, `InvoiceObserver`, μία
  φορά ανά τιμολόγιο μέσω `last_renewal_invoice_id`) — ΟΧΙ στο stage· έτσι μια
  μη-εκδομένη/απλήρωτη ανανέωση κρατά το `next_due_date` στο παρελθόν (το σήμα του
  dunning) και το open-draft guard κρατά ένα μόνο draft (κανένα pile-up).
  Idempotent ανά περίοδο (cursor + open-draft guard)· LOUD throw χωρίς
  `invoice_type_id`. Νέο top-level resource **«Υπηρεσίες»** (list/create/edit/view
  + nav-badge των ενεργών που λήγουν ≤7 ημέρες, φίλτρα status/cycle/«λήγει σε
  30·60·90»)· lifecycle actions στο ViewServiceContract (Ενεργοποίηση/Αναστολή/
  Επαναφορά/Ακύρωση/Τερματισμός/Επαναφορά + «Δημιουργία παραστατικού τώρα»), με
  **cascade ακύρωσης των μη-εκδομένων πρόχειρων ανανεώσεων** (draft + χωρίς ΜΑΡΚ·
  τα νομικά/MARK'd μένουν άθικτα). Product form: collapsible «Συνδρομή / Recurring»
  (is_recurring toggle, provisioning_module, default suspend/terminate days,
  `billingPrices` price-matrix repeater).
- **Υπηρεσίες/Συμβόλαια (recurring) — automation + visibility (PR-C).** Νέα
  εντολή **`services:stage-renewals`** (`--tenant`/`--dry-run`/`--lead-days=N`):
  per-tenant σάρωση που σταδιάζει **πρόχειρα** παραστατικά ανανέωσης για due
  συμβόλαια (`scopeDue`) μέσω `StageServiceRenewal` — ποτέ AADE, operator-gated
  downstream. Tenant-safe (explicit `company_id`, όχι BelongsToTenant στη CLI),
  per-contract try/catch (ένα κακό συμβόλαιο δεν σταματά το batch), συμβόλαια
  χωρίς `invoice_type_id` μετριούνται «skipped (no type)» αντί να ρίχνουν.
  Scheduler block (`routes/console.php`) + flags `config/ekdosi.php`
  (`service_renewals_enabled` **DEFAULT OFF** — φτιάχνει πραγματικά πρόχειρα·
  `_time`/`_lead_days`) + `.env.example`. **Dashboard:** `ServiceContractStats`
  (StatsOverview — ενεργές/σε αναστολή/ανανεώσεις 30 ημερών/**MRR** μηνιαίο
  επαναλαμβανόμενο έσοδο) + `UpcomingRenewalsTable` (TableWidget — Active με
  next_due εντός 30 ημερών, link στο ViewServiceContract). MRR sum σε testable
  `App\Services\ServiceContractInsights`. **Per-customer:** νέος
  `ServiceContractsRelationManager` (tab «Υπηρεσίες» στον πελάτη, read-mostly +
  «Άνοιγμα») + `Customer::serviceContracts()`. Form: πεδίο `quantity` (default 1,
  min 0.001) + στήλες ποσότητα/«Σύνολο» (qty×amount) στον πίνακα.
- **Υπηρεσίες/Συμβόλαια (recurring) — dunning (PR-D).** Αυτόματη
  **αναστολή/τερματισμός** συμβολαίων με ληξιπρόθεσμη ανανέωση (και
  **επαναφορά** όταν πληρωθεί), με τον overdue σηματισμό να έρχεται ΑΥΤΟΥΣΙΟΣ από
  `Invoice::isOverdue()/dueDate()` (καμία επανεφεύρεση μαθηματικών λήξης). Νέος
  **per-product «διακόπτης»** `products.dunning_enabled` (**DEFAULT OFF** = η
  ασφάλεια· τίποτα δεν συμβαίνει ώσπου ο χειριστής τον ανοίξει) + nullable
  `service_contracts.dunning_enabled` override (null = κληρονομεί). Νέα υπηρεσία
  `App\Services\Services\ServiceDunning` (`evaluate()` αποφασίζει+εφαρμόζει·
  `wouldDo()` ΑΜΙΓΩΣ read-only για το `--dry-run`): terminate>suspend κατά
  προτεραιότητα, μόνο αν το κατώφλι ημερών είναι μη-null ΚΑΙ το state machine
  (`canTransitionTo`) το επιτρέπει· terminate κάνει cascade ακύρωση των
  μη-εκδομένων πρόχειρων ανανεώσεων (ίδιο predicate με το ViewServiceContract).
  **Provisioning seam** (Null-only): `App\Contracts\ProvisioningModule` +
  `NullProvisioningModule` (key 'none', no-op) + `ProvisioningModuleRegistry`
  (config-driven `config/ekdosi.php → provisioning.modules`, unknown→Null+warn,
  ποτέ throw) — best-effort κλήση (αποτυχία module δεν κάνει rollback το local
  status). Νέα εντολή **`services:run-dunning`** (`--tenant`/`--dry-run`):
  per-tenant loop (explicit `company_id`, όχι BelongsToTenant στη CLI),
  per-contract try/catch, exit 2 σε άγνωστο tenant, loud `Log::info` ανά ενέργεια.
  Scheduler block + flags (`service_dunning_enabled` **DEFAULT ON** = ο μηχανισμός
  «πλυγκαρισμένος», αλλά ο πραγματικός διακόπτης είναι το per-product OFF·
  `service_dunning_time`) + `.env.example`. **Activity log** στο `ServiceContract`
  (`TracksActivity`, business πεδία μόνο: status/next_due/amount/suspended_at/
  terminated_at/cancel_reason/dunning_enabled· «Ιστορικό» tab στο resource) ώστε
  χειροκίνητο ΚΑΙ αυτόματο suspend/terminate να είναι auditable. Filament:
  per-product «Αυτόματο dunning» toggle + per-contract «Κληρονομεί/Ναι/Όχι»
  override. **Μηδέν money impact** (μόνο contract status + provisioning).
  **Deploy:** `php artisan migrate` + `shield:sync-super-admin`.
### Changed
- **Πληρωμές — money trail σε cash-term παραστατικά (model refinement).** Ένα
  μετρητοίς/άμεσο τιμολόγιο (`due_days=0`) θεωρείται «εξοφλημένο στην έκδοση»
  **μόνο όσο ΔΕΝ έχει καταγεγραμμένη πληρωμή**. Μόλις ο χειριστής καταχωρίσει
  πραγματική είσπραξη (π.χ. Stripe/POS receipt + transaction_id/τράπεζα για τα
  βιβλία), το τιμολόγιο γίνεται **tracked παντού** (cockpit, Καρτέλα, dashboard,
  receivables) και **κάνει net-to-zero** χρέωση↔πληρωμή — κανένα phantom. Νέο
  πάντα-διαθέσιμο «Καταχώριση πληρωμής» στο cockpit (ακόμη και σε μηδενικό
  υπόλοιπο). Ενιαίος κανόνας «tracked = επί-πιστώσει Ή έχει πληρωμή» σε
  `InvoiceBalance`, `DashboardMetrics`/`Customer` (receivables predicate),
  `CustomerLedgerBuilder`. Τα ~6.7k imported τιμολόγια αμετάβλητα (legacy
  πληρωμές = on-account). `CashTermRecordedPaymentTest` + `MoneyStatusConsistencyTest`.
- **Μενού — οι «Πληρωμές» μετακινήθηκαν** από το τεχνικό group «Data» σε νέο
  group **«Είσπραξη/Πληρωμές»**.
### Added
- **Πληρωμές — Επιστροφές / refunds (#3).** Νέα στήλη `payments.kind`
  (`payment`|`refund`, default `payment`)· μια επιστροφή αποθηκεύεται με **θετικό**
  ποσό αλλά **αφαιρείται** από το paid παντού (`Payment::NET_AMOUNT_SQL`):
  `InvoiceBalance`, dashboard receivables, `Customer::withOutstandingBalance`,
  Καρτέλα (stats/aging/yearly + **γραμμή DEBIT «Επιστροφή χρημάτων»**). UI:
  action «Επιστροφή χρημάτων» στο cockpit τιμολογίου (ανά ΤΙΜ) + στην Καρτέλα
  (customer-level / on-account)· «Τύπος» badge· labels σε ledger/CSV/PDF.
  Κλείνει τον κύκλο «χρήμα πίσω» (μαζί με ακύρωση/πιστωτικό). `RefundTest`.
  **Deploy:** `php artisan migrate`.
- **Πληρωμές — Χρήση πίστωσης (#1) & Χειροκίνητη κατανομή (#2).** Στην Καρτέλα:
  «Χρήση πίστωσης» μετακινεί διαθέσιμη on-account πίστωση πάνω σε ανοιχτό
  τιμολόγιο (re-point των payment rows — **net-zero** στο συνολικό υπόλοιπο,
  capped από υπόλοιπο τιμολογίου & διαθέσιμη πίστωση), «Χειροκίνητη κατανομή»
  ορίζει **ακριβές ποσό ανά τιμολόγιο** (vs FIFO). `PaymentAllocator::applyCredit`
  / `allocateManual` / `availableCredit`. `ApplyCreditAndManualAllocationTest`.
- **Πληρωμές — Ληξιπρόθεσμα / Due (#6).** Ημερομηνία λήξης = `issued_at +
  payment_method.due_days` (μηδέν για μετρητοίς). Νέα `Invoice::dueDate()` /
  `isOverdue()` / `scopeOverdue()` (driver-aware date math, EXISTS σε
  `payment_methods` — μετράει μόνο live, active, μη-πιστωτικά, επί-πιστώσει,
  ανοιχτά (`payment_status` unpaid/partial) με due date στο παρελθόν· μηδέν
  αλλαγή money model). Στη **λίστα τιμολογίων**: στήλη «Λήξη» (κόκκινο
  «Ληξιπρόθεσμο») + filter «Μόνο ληξιπρόθεσμα». **Dashboard**: widget
  «Ληξιπρόθεσμα τιμολόγια» (παλαιότερα πρώτα, link στο παραστατικό).
  **Notifications (bell, ΟΧΙ email)**: `invoices:notify-overdue [--tenant]
  [--dry-run]` — ημερήσιο digest ανά tenant (scheduler flag
  `EKDOSI_SCHEDULE_OVERDUE_NOTIFICATIONS`, default OFF). `OverdueInvoicesTest`.
  **Deploy:** `php artisan migrate` (πίνακας `notifications`).
- **Πληρωμές — Τραπεζικοί Λογαριασμοί (L2).** Νέο lookup `bank_accounts` (ανά
  tenant: τράπεζα, IBAN, δικαιούχος, SWIFT, `is_active`) με δικό του Filament
  resource (Setup → «Τραπεζικοί λογαριασμοί»). Νέο **`payments.bank_account_id`**
  («σε ποιον λογαριασμό μπήκαν τα χρήματα») σε ΚΑΘΕ φόρμα πληρωμής + στο έμβασμα
  (`PaymentAllocator`, ίδιος σε όλες τις γραμμές) μέσω κοινού `BankAccountField`
  (εμφανίζεται μόνο αν ο tenant έχει active λογαριασμό). Νέο
  **`invoices.bank_account_id`** (λογαριασμός κατάθεσης) στη φόρμα παραστατικού →
  **τυπώνεται στο PDF** («Λογαριασμός κατάθεσης: Τράπεζα — IBAN») για πληρωμή με
  έμβασμα. Πληροφοριακό — μηδέν αλλαγή στο money model (`InvoiceBalance`).
  `BankAccountTaggingTest`. **Deploy:** `php artisan migrate` + `shield:generate`
  (νέο resource permission).
- **Πληρωμές — κωδικός συναλλαγής (L1, `transaction_id`).** Προαιρετικό πεδίο σε
  ΚΑΘΕ φόρμα πληρωμής (cockpit τιμολογίου, ViewInvoice «Καταχώριση πληρωμής»,
  Καρτέλα «Πληρωμή έναντι λογαριασμού» + «Είσπραξη/Έμβασμα») για Stripe `pi_…` /
  PayPal txn / ref εμβάσματος τράπεζας. Στο έμβασμα (`PaymentAllocator`) μπαίνει
  **ίδιος σε όλες τις γραμμές** της ομάδας. Column (copyable) στο cockpit·
  audited. AR roadmap + deferred αποφάσεις: `docs/payments-ar-roadmap.md`.
  **Deploy:** `php artisan migrate`.
- **Πληρωμές — ομαδοποίηση εμβάσματος στην Καρτέλα (Φ3).** Τα `Payment` rows ενός
  εμβάσματος (κοινό `reference`) εμφανίζονται ως **ΜΙΑ γραμμή «Έμβασμα €X»** στην
  Καρτέλα κινήσεων, με **drill-down «Κατανομή»** (modal: ποια τιμολόγια πληρώθηκαν +
  τυχόν πίστωση/προκαταβολή). Το **running balance μένει αμετάβλητο** (credit =
  άθροισμα). Μεμονωμένες/legacy πληρωμές (χωρίς reference) μένουν ως έχουν. CSV/PDF
  statement δείχνουν τη σύνοψη κατανομής σε μία γραμμή (`ReceiptAllocationSummary`).
  Display-only — μηδέν αλλαγή σε `InvoiceBalance`/`PaymentObserver`/allocator.
- **Πληρωμές — Είσπραξη/Έμβασμα (Φ2, allocation).** Νέα action «Είσπραξη (έμβασμα)»
  στην Καρτέλα: ένα ποσό **κατανέμεται FIFO** (παλαιότερα ανοιχτά τιμολόγια πρώτα),
  το τελευταίο μπορεί να μείνει μερικώς πληρωμένο, και **ό,τι περισσέψει → on-account
  πίστωση/προκαταβολή**. `App\Services\Payments\PaymentAllocator` φτιάχνει απλά
  `Payment` rows (PaymentObserver recompute) με κοινό `payments.reference` (για
  ομαδοποίηση στη Φ3) — **μηδέν αλλαγή στο `InvoiceBalance`**. Καλύπτει €1200/€1500
  (μερική) και €2000/€1500 (όλα + €500 πίστωση). `PaymentAllocatorTest`. **Deploy:**
  `php artisan migrate`.
- **Πληρωμές — cockpit ανά τιμολόγιο (Φ1).** Νέο tab «Πληρωμές» στο invoice View
  (`InvoicePaymentsRelationManager`): λίστα πληρωμών + **Προσθήκη/Επεξεργασία/
  Διαγραφή**, quick **«Πλήρης εξόφληση»** (προ-συμπληρώνει το υπόλοιπο) + **«Μερική
  πληρωμή»** (warning σε υπερπληρωμή) + **«Σήμανση ως ανεξόφλητο»** (διαγράφει όλες
  τις πληρωμές → υπόλοιπο στο πλήρες — διορθώνει phantom πληρωμές π.χ. από import,
  όπως το ΤΙΜ385). Το money cache επανυπολογίζεται μόνο του (PaymentObserver). Μηδέν
  αλλαγή στο `InvoiceBalance`. Φ2 (έμβασμα σε πολλά τιμολόγια/on-account) ξεχωριστά.
  `InvoicePaymentsCockpitTest` (partial / overpaid / mark-unpaid).
- **Αποθήκη — αναστροφές ακύρωσης/πιστωτικού (S3).** Κλείνει ο κύκλος: όταν ένα
  τιμολόγιο **ακυρώνεται** (τοπικά ή myDATA CANCELLED → `local_status='cancelled'`)
  το stock-OUT της πώλησης **αναστρέφεται** (+ποσότητα πίσω, reason `cancel`,
  idempotent, μόνο για γραμμές που όντως κίνησε), και όταν ένα **πιστωτικό** γίνεται
  active καταγράφεται **επιστροφή** (+ποσότητα, reason `return`). Όλα best-effort
  στον `InvoiceObserver` (η αλλαγή status έχει ήδη γραφτεί — stock hiccup δεν
  εμφανίζεται ως ψεύτικη αποτυχία). **Supplier auto-είσοδος deferred** — τα expense
  lines δεν συνδέονται με προϊόντα (free-text)· η χειροκίνητη «Παραλαβή» (S2.6) το
  καλύπτει μέχρι να μπει βήμα matching. `StockSaleTest` (return + cancel-reverse +
  idempotent).
- **Αποθήκη — αναπαραγγελία, backorders, γρήγορη παραλαβή (S2.6).** (1) Per-product
  **όριο αναπαραγγελίας** (`products.reorder_level`): το badge «Απόθεμα» γίνεται
  **πορτοκαλί** όταν ≤ όριο/εξαντλημένο (κόκκινο σε αρνητικό), ώστε να ξέρεις τι
  να παραγγείλεις ΠΡΙΝ μηδενίσεις. (2) Filter **«Κατάσταση αποθέματος»** στη λίστα
  προϊόντων → «χρειάζεται αναπαραγγελία» / «αρνητικό (backorder)» (what you owe).
  (3) Row-action **«Παραλαβή»** κατευθείαν στη λίστα — καταχώριση εισόδου χωρίς
  να μπεις στην καρτέλα. `StockServiceTest` (filter SQL buckets, sqlite-safe via
  groupBy). **Deploy:** `php artisan migrate`.
- **Αποθήκη — ορατότητα (S2.5).** Το απόθεμα φαίνεται **τη στιγμή που κόβεις**:
  (α) στον picker προϊόντος του τιμολογίου → «· απόθεμα: N» (⚠ αν αρνητικό),
  (β) μη-μπλοκάρον warning στην **Οριστικοποίηση** αν κάποια γραμμή πάει αρνητικό
  («Σε αρνητικό: X (−1). Η έκδοση προχώρησε κανονικά — backorder»),
  (γ) μεγάλος αριθμός «Τρέχον απόθεμα» στην καρτέλα προϊόντος (⚠ σε αρνητικό).
  Read-only UI — ποτέ δεν μπλοκάρει (το −1 = backorder, by design).
- **Αποθήκη — auto έξοδος στην πώληση (S2, whichever-first).** Στο απόθεμα
  μειώνεται **−ποσότητα** αυτόματα όταν ένα τιμολόγιο γίνεται `active`
  (`InvoiceObserver`, μόνο `track_stock` goods· τα πιστωτικά εξαιρούνται = S3
  επιστροφή) ΚΑΙ όταν εκδίδεται **ΔΑΠ με σκοπό «Πώληση»** (μόνο move_purpose=1·
  ενδοδιακίνηση/σέρβις/φύλαξη ΔΕΝ μειώνουν). **Whichever-first dedup:** νέο
  προαιρετικό link `delivery_notes.invoice_id` («Σχετικό τιμολόγιο» στη φόρμα) —
  μια πώληση μετριέται ΜΙΑ φορά (αν το linked τιμολόγιο/δελτίο το κίνησε ήδη, το
  άλλο παραλείπει). Idempotent ανά source-line (re-finalize δεν διπλομετρά).
  `StockService::recordSaleForInvoice/recordSaleForDeliveryNote`· warn-only.
  `StockSaleTest`. **Deploy:** `php artisan migrate`.
- **Αποθήκη / απόθεμα — foundation (S1).** Opt-in stock tracking ανά προϊόν
  (`products.track_stock` — εμπορεύματα ναι, υπηρεσίες όχι· ό,τι δεν είναι tracked
  το αγνοεί ο μηχανισμός) + signed ledger `stock_movements` (τρέχον on-hand =
  SUM, **derived ποτέ cached** όπως το InvoiceBalance· auditable/reversible) +
  `App\Services\Stock\StockService` (current/record, **warn-only — ποτέ δεν
  μπλοκάρει πώληση**, επιτρέπει αρνητικό). UI: στήλη «Απόθεμα» στα Products
  (κόκκινο σε αρνητικό· «—» για μη-tracked· το legacy fractional `reserve`
  ξεχώρισε ως «Reserve (legacy)» για να μη μπερδεύεται) + tab «Κινήσεις
  αποθέματος» ανά προϊόν με χειροκίνητη «Καταχώριση κίνησης»
  (Παραλαβή/Αρχική απογραφή/Διόρθωση). Ledger append-only (διορθώνεις με νέα
  κίνηση). Επόμενα: S2 = auto-έξοδος (τιμολόγιο + ΔΑΠ-Πώληση, whichever-first με
  link/dedup)· S3 = auto-είσοδος προμηθευτή + αναστροφές ακύρωσης/πιστωτικού.
  `StockServiceTest`. **Deploy:** `php artisan migrate`.
- **2FA (TOTP) + root redirect.** Ενεργοποιήθηκε το ενσωματωμένο MFA του Filament:
  `User` υλοποιεί `HasAppAuthentication`(+`Recovery`), νέες encrypted-at-rest στήλες
  `app_authentication_secret`/`_recovery_codes`, και το panel
  `->multiFactorAuthentication([AppAuthentication::make()->recoverable()])`. Το
  enrollment (QR), τα recovery codes και disable/regenerate ζουν **αυτόματα** στη
  σελίδα προφίλ. **Opt-in** by default· `EKDOSI_REQUIRE_2FA=true` το επιβάλλει σε
  όλους στο επόμενο login (αφού πρώτα εγγραφούν). Το `/` πλέον redirect → `/admin`
  (δεν υπάρχει public landing). Οι MFA στήλες είναι `#[Hidden]` (να μην διαρρέουν
  σε serialization) + admin action **«Επαναφορά 2FA»** στη λίστα Users (recovery
  για χαμένη συσκευή — αλλιώς μόνιμο κλείδωμα). **Runbook:** μην κάνεις rotate το
  `APP_KEY` χωρίς να μηδενίσεις πρώτα τις 2 στήλες. **Deploy:** `php artisan migrate`.
  `TwoFactorAndRootTest`.
- **Deploy safety net (ρίζα: ένα `migrate:fresh`/test έσβησε κατά λάθος την prod).**
  Τρία επίπεδα ώστε να μην ξανασυμβεί: (1) `DB::prohibitDestructiveCommands()` στον
  `AppServiceProvider` μπλοκάρει `db:wipe`/`migrate:fresh`/`migrate:refresh`
  **παντού εκτός από το testing env** (δεμένο στο `environment('testing')`, ΟΧΙ
  στο `isProduction()`, γιατί το prod ήταν κατά λάθος `APP_ENV=local` — το απλό
  `migrate` δεν επηρεάζεται)· (2) `clean.sh` κάνει abort αν το backup είναι
  ύποπτα μικρό/καταρρέει (άδειο dump = ψεύτικη ασφάλεια· πιάνει σπασμένη βάση ΠΡΙΝ
  το migrate)· (3) `App\Support\Backup\MinimumBackupSizeInKilobytes` health-check
  μαρκάρει ένα σχεδόν-άδειο backup ως unhealthy. `MinimumBackupSizeHealthCheckTest`.
  **Προσοχή στο deploy host:** βάλε `APP_ENV=production` + `APP_DEBUG=false` στο
  `.env` και τρέξε `php artisan config:clear && php artisan config:cache`.
- **Διακίνηση — «Ιστορικό myDATA» στο δελτίο.** Το `DeliveryNoteResource` απέκτησε
  read-only relation manager (`DeliveryMarksRelationManager`) που δείχνει ΟΛΟΝ τον
  audit trail του δελτίου — INSERT (έκδοση), REGISTER_TRANSFER (έναρξη),
  CONFIRM_OUTCOME (παράδοση), CANCEL, **REJECTED** — με χρωματιστά badges + modals
  request/response XML ανά γραμμή. Πριν δεν φαινόταν πουθενά στο UI ο κύκλος ζωής.
### Fixed
- **«Επί Πιστώσει» έδειχνε ΟΛΑ τα τιμολόγια «Εξοφλημένα» χωρίς πληρωμή (root cause
  του «phantom payment» στο ΤΙΜ385).** Ο `MyDataLookupSeeder` έσπερνε ΟΛΕΣ τις
  μεθόδους πληρωμής με `due_days=0` — και το «Επί Πιστώσει» (§8.12 κωδ. 5). Με
  due_days=0 το `InvoiceBalance` τη θεωρεί cash-term → «εξοφλημένο στην έκδοση,
  paid=owed, balance 0, ΧΩΡΙΣ πληρωμή» (γι' αυτό 0 credit rows στην Καρτέλα· δεν
  υπήρχε πληρωμή να σβηστεί). Πλέον το seed δίνει στο «Επί Πιστώσει» **due_days=30**
  (credit term)· οι υπόλοιπες μένουν 0. Το `due_days` helperText έγινε ελληνικό +
  προειδοποιεί ρητά. **Υπάρχοντες tenants (το seed ΔΕΝ ξαναγράφει υπάρχοντα):**
  Setup → Payment Methods → «Επί Πιστώσει» → due_days>0, μετά
  `php artisan invoices:recompute-balances --company=SLUG` για να φρεσκάρει τα
  cached badges. `PaymentMethodCreditTermSeedTest`.
- **Εικόνα από myDATA — ΦΠΑ: ο μήνας κρίνεται με το ΔΙΚΟ του πρόσημο.** Στην κάρτα
  «Τρίμηνο — Καθαρό ΦΠΑ» η ένδειξη του μήνα δανειζόταν την ετικέτα/χρώμα του
  τριμήνου (`$quarter->isPayable()`) και δειχνόταν ως `abs()` — έτσι μια
  **πίστωση** μήνα (εκροών − εισροών < 0, π.χ. 0 − 9,70 = −9,70 όταν ο μήνας έχει
  μόνο έξοδα) διαβαζόταν ως «Προς απόδοση 9,70 €», σαν οφειλή. Πλέον ο μήνας
  παίρνει δική του λέξη «προς απόδοση / πίστωση / 0,00 €» (`monthVatLabel`)·
  η κάρτα κρατά ένα χρώμα (= το τρίμηνο, που είναι το headline). `MyDataPictureStats`.
- **Panel 403 σε production (λανθάνον — ξεσκεπάστηκε με τη διόρθωση του `APP_ENV`).**
  Το `User` δήλωνε μόνο `HasTenants`, ΟΧΙ το `FilamentUser` contract — οπότε το
  Filament επέτρεπε το panel μόνο σε `APP_ENV=local` και έβγαζε **403 σε
  production**. Δούλευε όλον τον καιρό μόνο επειδή το `.env` ήταν (λάθος) `local`·
  μόλις μπήκε σωστά `production`, 403 για όλους. Το `User` υλοποιεί πλέον
  `FilamentUser` με ρητό `canAccessPanel()` = «ανήκει σε ≥1 εταιρεία» (operators-only
  app· tenancy + Shield policies γκρινιάζουν τα υπόλοιπα). **Ορατότητα (το γυμνό
  403 δεν άφηνε ίχνος):** (α) το deny κάνει `Log::warning` με user/email/panel —
  greppable· (β) custom `errors/403` εξηγεί «δεν έχεις ανατεθεί σε εταιρεία —
  επικοινώνησε με διαχειριστή» + Αποσύνδεση· (γ) η λίστα Users δείχνει badge
  «χωρίς εταιρεία» + filter ώστε ο admin να πιάνει τους ορφανούς πριν κλειδωθούν.
  `PanelAccessTest`.
- **Διακίνηση (myDATA) — απορρίψεις ΑΑΔΕ φαίνονται στο UI.** Ο
  `DeliveryNoteSubmitter` γράφει πλέον forensic `delivery_marks` row
  (`mydata_action='REJECTED'`, null mark, με το response) σε απόρριψη, δίδυμο του
  invoice `recordRejection` — ώστε η απόρριψη να φαίνεται στο «Ιστορικό myDATA»
  του δελτίου (πριν surface-αρόταν μόνο στο CLI report του `sandbox-validate`).
- **Παραστατικά (myDATA) — απορρίψεις ΑΑΔΕ δεν χάνονται πια.** Όταν η ΑΑΔΕ
  απορρίπτει υποβολή τιμολογίου (status ≠ Success), ο `MyDataSubmitter` πετά
  πλέον `MyDataRejected` που κουβαλά το request+response XML ΚΑΙ γράφει μια
  forensic γραμμή `mydata_marks` (`mydata_action='REJECTED'`, χωρίς MARK) — ώστε
  ο χειριστής να βλέπει ΤΙ στάλθηκε και ΓΙΑΤΙ απορρίφθηκε από το «Ιστορικό
  myDATA» του παραστατικού, αντί να χάνεται το round-trip στο throw (πριν: bare
  RuntimeException μόνο με το μήνυμα). Το `mydata:test-submit` τυπώνει το
  request/response σε απόρριψη. Παράλληλο του `DeliveryNoteRejected` της
  διακίνησης. (`MyDataRejected`, `MyDataSubmitter::recordRejection`.)
- **Δελτίο Αποστολής / Ψηφιακή Διακίνηση (9.3) — sandbox-validated end-to-end
  στο AADE dev (2026-06-03).** Το `DeliveryNoteSubmitter` payload διορθώθηκε με
  βάση ζωντανές απορρίψεις: για τύπο 9.x η ΑΑΔΕ **απαγορεύει** `<isDeliveryNote>`,
  `<currency>` και `<thirdPartyCollection>false>` ([205]/[214]) και **απαιτεί**
  πλήρη ταυτοποίηση issuer + counterpart (name + address, [204]) — αντίθετα με
  τον κανόνα μονόδρομου τιμολογίου που τα κρύβει για GR. Πλέον περνά καθαρά όλη η
  αλυσίδα ΕΚΔΟΣΗ→ΕΝΑΡΞΗ→ΠΑΡΑΔΟΣΗ→ΕΛΕΓΧΟΣ (SendInvoices/RegisterTransfer/
  ConfirmDeliveryOutcome/RequestDeliveryNoteStatus).
- **`delivery_marks.mark_time` ήταν `timestamp` αντί `time`** (ο δίδυμος
  `mydata_marks.mark_time` είναι `time`) — έσκαγε το persist του MARK με
  «Incorrect datetime value '03:36:16'». Διορθώθηκε η migration + ALTER.
- **Report writer**: σε απόρριψη AADE, ο `DeliveryNoteSubmitter` πετά πλέον
  `DeliveryNoteRejected` που μεταφέρει request+response XML, ώστε το `.txt`
  report των `delivery:sandbox-validate`/`delivery:test-submit` να τα καταγράφει
  (πριν χάνονταν — η απόρριψη συμβαίνει πριν γραφτεί η `delivery_marks` row).

### Changed
- **Σαφήνεια «σημειώσεων» (εσωτερικές vs εκτυπώσιμες).** Το πεδίο `invoices.notes`
  (που ΕΚΤΥΠΩΝΕΤΑΙ στο PDF/email) ξαναβαφτίστηκε «Παρατηρήσεις (εκτυπώνονται στο
  παραστατικό)» με ρητό ⚠ helper που παραπέμπει στις εσωτερικές σημειώσεις — ώστε
  ένα «κακοπληρωτής» να μην καταλήξει στο χαρτί του πελάτη (form section + view
  infolist ευθυγραμμισμένα στη λέξη «Παρατηρήσεις», όπως ήδη ο τίτλος στο PDF).
  Στον πελάτη, το παλιό «ξερό» free-text tab «Σχόλια» (`customers.details`)
  **αφαιρέθηκε** υπέρ της πλουσιότερης καρτέλας «Σημειώσεις (εσωτερικές)»
  (χρονολογημένες, πολλαπλές, με συντάκτη).
- **`customers.details` → εσωτερικές σημειώσεις (3 φάσεις, idempotent).** Τα
  εισαγόμενα σχόλια ενοποιήθηκαν στο νέο σύστημα σημειώσεων: το `notes` απέκτησε
  πεδίο **`source`** (NULL=χειριστής, `backup`=από import)· νέα υπηρεσία
  `App\Services\Etl\BackupNoteSync` κάνει **upsert μίας** σημείωσης `source='backup'`
  ανά πελάτη (Epsilon `Remarks` / legacy `DETAILS`) — re-runnable χωρίς διπλότυπα,
  σβήνει τη σημείωση αν το σχόλιο αδειάσει στην πηγή, δεν αγγίζει τις χειροκίνητες.
  Μια **data migration** μετέφερε τα υπάρχοντα `details` και μετά η στήλη **έπεσε**
  (`dropColumn`). Στην Καρτέλα + στο tab οι imported σημειώσεις φέρουν badge «από
  backup» και είναι **read-only** (τις διαχειρίζεται το import). Ανθεκτικότητα:
  το sync χειρίζεται soft-deleted backup note (restore αντί για διπλότυπο), η
  drop migration **αρνείται** να ρίξει τη στήλη αν υπάρχει σχόλιο χωρίς backup note,
  και το rollback είναι **μη-καταστροφικό** (η `down` ξαναγράφει τα σχόλια στη
  στήλη πριν σβήσει τις σημειώσεις). **Deploy:** `php artisan migrate` (3 migrations:
  add `source` → migrate data → drop `details`).
- **myDATA consoles — ένα κουμπί «Έλεγχος» αντί για δύο** (έσοδα + έξοδα): οι δύο
  «κατευθύνσεις» (τα-δικά-μας vs αδέσποτα) έκαναν την ΙΔΙΑ κλήση
  (`SalesReconciler`/`ExpenseReconciler`) — τώρα ένα κουμπί κάνει ένα fetch και
  δείχνει **και τις δύο μαζί** συγκεντρωτικά (μισές κλήσεις AADE· καμία απώλεια
  πληροφορίας/bucket). Νέος **preset επιλογέας** διαστήματος (τρέχων μήνας /
  τρέχον τρίμηνο / προηγούμενο τρίμηνο / έτος / προσαρμογή) σε ημερολογιακά =
  φορολογικά όρια (`App\Filament\Pages\Concerns\ResolvesReconcileWindow`). Τα
  write actions των Εξόδων («Λήψη δικών μας εξόδων», «Καταχώριση αδέσποτων»)
  μένουν αυτούσια· το «Καταχώριση αδέσποτων» δεν εξαρτάται πια από mode. Μόνο
  pages + views — καμία αλλαγή στους reconcilers/δεδομένα. `ReconcileWindowPresetTest`
  + ενημερωμένα console tests.
### Added
- **Ψηφιακή Διακίνηση / Δελτίο Αποστολής — κύκλος ζωής διακίνησης (Phase D3, Β' φάση)**:
  `App\Services\Delivery\DeliveryLifecycleService` οδηγεί τον κύκλο ζωής ΠΑΝΩ σε ένα
  ήδη εκδομένο δελτίο — `registerTransfer` (RegisterTransfer, registered→in_transit,
  qrUrl-keyed, αποθηκεύει `transfer_mark`), `confirmDelivery` (ConfirmDeliveryOutcome,
  FULL/PARTIAL/NONE → delivered/partial/failed, `outcome_mark`), `refreshStatus`
  (RequestDeliveryNoteStatus by MARK + ΑΦΜ εκδότη, read-only §8.22→`delivery_state`
  reconcile, χωρίς νέα γραμμή mark) και `cancel` (μέσω `CancelInvoice` by issue MARK,
  όπως τα τιμολόγια — η provider-only CancelDeliveryNote δεν ισχύει στο ERP route).
  Καθρεφτίζει τον `DeliveryNoteSubmitter` (per-tenant `initFirebed`, MockHandler seam,
  try/catch→Greek RuntimeException, forceFill των guarded lifecycle στηλών + γραμμή
  `delivery_marks` σε transaction — actions REGISTER_TRANSFER/CONFIRM_OUTCOME/CANCEL).
  Header actions στο `ViewDeliveryNote` («Έναρξη διακίνησης» / «Δήλωση παράδοσης» με
  Select αποτελέσματος / «Έλεγχος κατάστασης (ΑΑΔΕ)» / «Ακύρωση») με visibility gates
  ανά state + Greek notifications· `delivery_state` badge με Greek label στο infolist.
  **ΔΕΝ έχει επικυρωθεί στο sandbox** (όπως όλο το 9.x/DGM μονοπάτι).
- **Ψηφιακή Διακίνηση / Δελτίο Αποστολής — εκτυπώσιμο PDF + QR (Phase D2.4)**:
  `App\Services\Delivery\DeliveryNotePdf` renders a Δελτίο Αποστολής to PDF bytes
  via DomPDF + `resources/views/delivery-notes/pdf.blade.php` — a value-LESS twin
  of the invoice PDF (no prices/VAT/totals; same DejaVu-Sans Greek font setup,
  A4 portrait, per-render ini guard, and `App\Support\MyData\QrImage` for the
  AADE QR). Εκδότης/Παραλήπτης (or «Ενδοδιακίνηση»), σκοπός/τόπος φόρτωσης→
  παράδοσης/μεταφορικό μέσο/όχημα/μεταφορέας, and a quantities-only lines table
  (μονάδα μέτρησης resolved via new `DeliveryCodes::measurementUnitLabel`, §8.13).
  The MARK + QR footer render only when filed (`mydata_state==='VALID'`); a draft
  shows «ΠΡΟΧΕΙΡΟ — μη διαβιβασμένο» and no QR. A «Εκτύπωση (PDF)» header action
  on `ViewDeliveryNote` streams `deltio-<invcode>.pdf` for both draft + filed
  notes (mirrors ViewInvoice's PDF action).
- **Ψηφιακή Διακίνηση / Δελτίο Αποστολής — data model (Phase D1)**: the schema
  for myDATA e-transport delivery notes. New `delivery_notes` /
  `delivery_note_lines` / `delivery_marks` tables — value-LESS twins of
  invoices/lines/marks (no money/VAT, kept in their own tables like quotes so
  they never touch InvoiceScope or the money services). Models `DeliveryNote` /
  `DeliveryNoteLine` / `DeliveryMark` (`BelongsToCompany`; the `mydata_*` cache +
  the lifecycle `*_mark`/`delivery_state` columns are guarded — written only via
  forceFill by the future submitter/lifecycle service). `App\Support\MyData\
  DeliveryCodes` wraps the firebed e-transport enums (σκοπός διακίνησης §8.14,
  τρόπος μεταφοράς, συσκευασία §8.23, κατάσταση §8.22) and bakes the AADE policy
  that move purposes **6/15/16/17/18 are no longer transmittable** (so 18
  «Διακίνηση Παγίων» is excluded — own-equipment moves use 8 Ενδοδιακίνηση or 19
  Λοιπές). Numbering reuses `InvoiceNumberer` unchanged (a delivery series is
  just a 9.x `invoice_types` row). No UI yet (D2 = submit+form, D3 = lifecycle).
  `DeliveryCodesTest` + `DeliveryNoteModelTest`. **Deploy:** `php artisan migrate`.
- **Ψηφιακή Διακίνηση — myDATA submitter (Phase D2, partial)**:
  `App\Services\Delivery\DeliveryNoteSubmitter` files a value-less Δελτίο
  Αποστολής (9.x) via the SAME `SendInvoices` path as invoices —
  `buildAadeDeliveryNote()` (Issuer + delivery `InvoiceHeader` with
  `isDeliveryNote=true` / `movePurpose` / dispatch / vehicle /
  `otherDeliveryNoteHeader` loading+delivery addresses + GR-rule recipient
  counterpart) + value-less lines (`quantity` + `measurementUnit` + `netValue=0`
  + `vatCategory=8` + `vatAmount=0`) + an all-zero `InvoiceSummary`;
  `previewXml()` for dry-run; `submit()` persists the MARK/qrUrl into the note's
  guarded cache (`mydata_*` + `delivery_state='registered'`) and a
  `delivery_marks` INSERT audit row, idempotent. Self-contained (the proven
  invoice submitter is untouched). Line/summary shape grounded in firebed's 9.3
  reference payload — **flagged for AADE sandbox validation** before go-live.
  `DeliveryNoteSubmitterTest` (build/previewXml + mock-Guzzle submit happy-path).
- **Ψηφιακή Διακίνηση — Filament resource + issue flow (Phase D2, part 3)**:
  `DeliveryNoteResource` (new nav group «Ψηφιακή Διακίνηση», truck icon,
  admin-gated on `View:DeliveryNote` + Company tenant, mirrors Reports/LedgerBook)
  with List/Create/View/Edit pages. The form wires the operator-guidance helpers
  end-to-end: a non-blocking exemption notice (`DeliveryGuidance::EXEMPTIONS_LEAD`
  + `EXEMPTIONS` + `INTRO`), a reactive «Τι θέλω να κάνω;» scenario picker
  (`scenarioOptions()` → fills `move_purpose` + the «Λοιπές» title; UI-only,
  `dehydrated(false)`), `move_purpose`/transport/packaging selects from
  `DeliveryCodes`, per-line measurement-unit from `Codes::QUANTITY_TYPES`, and
  `fieldHelp()` on every field. **Any-party recipient picker** searches BOTH
  customers AND suppliers (prefixed `c:`/`s:` keys) — a supplier recipient (e.g. a
  datacenter) snapshots `recipient_afm`/`recipient_name` and leaves `customer_id`
  null; a manual ΑΦΜ+name fallback covers parties in neither table; empty recipient
  = ενδοδιακίνηση. Mandatory addresses (loading + delivery), transport_type,
  vehicle_number, dispatch_at enforced in-form (last-line submitter guards
  unchanged). Numbering reuses `InvoiceNumberer` under a row lock in
  `CreateDeliveryNote` (identical to CreateInvoice); the type's `mydata_type`
  (9.x, default ΔΑΠ/9.3) is snapshotted at save. The View page's «Έκδοση»
  header action (draft-only) files via `DeliveryNoteSubmitter`. Edit limited to
  drafts. Added a `DeliveryNoteLine::saving` hook to auto-stamp `company_id` from
  the parent note (the Repeater relationship omits it). `DeliveryNoteResourceTest`
  (Livewire create→ΑΑ/draft/lines, required-field validation, issue-action
  draft-only visibility, mock-Guzzle submit→VALID+mark, recipient union search).
  **Deploy:** `php artisan shield:generate` so `View:DeliveryNote` exists.
- **Συνημμένα + εσωτερικές σημειώσεις (polymorphic).** Two reusable, tenant-safe
  tabs available on customers AND invoices (and any future model via a trait):
  - **Συνημμένα** (`attachments` table, `App\Models\Attachment`,
    `HasAttachments`) — upload files to a private disk with metadata + uploader
    audit; authenticated streamed download (never publicly served); force-delete
    removes the bytes, soft-delete keeps them. `AttachmentsRelationManager`.
  - **Σημειώσεις (εσωτερικές)** (`notes` table, `App\Models\Note`,
    `HasInternalNotes`) — operator-only notes that are **NEVER printed on the PDF
    and NEVER sent to AADE** (distinct from the printed `invoices.notes`); pinned
    notes float to the top; author + timestamp captured. `InternalNotesRelation
    Manager`. The Καρτέλα surfaces both contacts and pinned/recent internal notes
    read-only. **Deploy:** `php artisan migrate`.
- **Επαφές πελάτη (Customer contacts).** A customer can now hold multiple named
  contacts (λογιστήριο, τεχνικός, υπεύθυνος…) — each with ρόλος/τμήμα, τηλέφωνο,
  email, σημειώσεις, and an optional «Κύρια» flag (single-primary enforced on the
  model). Managed via a new «Επαφές» tab on the customer Edit page
  (`ContactsRelationManager`, stamps `company_id` like the other tenant-owned
  child managers) and surfaced read-only on the Καρτέλα (primary first). New
  `customer_contacts` table + `App\Models\CustomerContact`. **Deploy:**
  `php artisan migrate`.
- **Καρτέλα: «Συχνά προϊόντα/υπηρεσίες» + πλουσιότερο header.** A new panel on the
  customer Καρτέλα lists what the customer buys most (frequency, total qty, net
  spend, last-bought date) — aggregated from their LIVE sales lines (credit notes
  + cancelled excluded), product-linked or free-text (`App\Services\CustomerLedger\
  CustomerTopProducts`). The identity header now also surfaces fields we already
  store but never showed: τηλέφωνο/email, τρόπος πληρωμής, έκπτωση, σημειώσεις, and
  badges (Άμεση τιμολόγηση / Μεταπωλητής).
- **Λογαριασμοί (ΕΓΛΣ) + νέο group «Λογιστικά»** — a LIGHT, indicative Greek
  chart-of-accounts layer (`App\Support\Accounting\ChartOfAccounts`): the ΕΓΛΣ
  group accounts we reference + a default `category1_x`/`category2_x → account`
  map (70/71/73 income, 20/24/60/61/62/64/66/14 expense, 54 ΦΠΑ). The Βιβλίο
  Εσόδων-Εξόδων now shows a «Λογαριασμός» column (table + per-category subtotals
  + CSV/JSON/xlsx exports) derived from the myDATA category we already store. A
  read-only «Λογαριασμοί» page documents the chart + the mapping (clearly
  flagged INDICATIVE — the accountant's software does the definitive mapping;
  a per-tenant editable chart is a deferred follow-up). The book + λογαριασμοί
  now live in a dedicated «Λογιστικά» navigation group. Admin-gated on
  `View:Accounts` (run `shield:generate` + `shield:sync-super-admin` after
  deploy). `ChartOfAccountsTest` covers the mapping.
- **Βιβλίο Εσόδων-Εξόδων (απλογραφικά / Β' κατηγορίας)** — new read-only page
  «Βιβλίο Εσόδων-Εξόδων»: a chronological book of the tenant's invoices (έσοδα)
  + expenses (έξοδα), classified by the myDATA category we already store
  (`category1_x`/`category2_x` → Greek label via `Codes::e3CategoryLabel`),
  with period/book/category filters, per-category subtotals and the period
  totals (έσοδα, έξοδα, ΦΠΑ εκροών−εισροών). Pure read-model
  (`App\Services\Accounting\LedgerBook` → `LedgerBookResult`/`LedgerRow`) over
  the existing tables — no new persistence, no money/myDATA path change. Live
  scoping follows `InvoiceScope::live()` (sales) + "not AADE-cancelled"
  (expenses); credit notes are listed with NEGATIVE amounts so period sums are
  net. Never-issued drafts (`local_status='draft'` with no `legacy_id`) are
  excluded as not-yet-book-entries; legacy-imported drafts (`legacy_id` set, =
  real historical invoices) are kept. Admin-gated on `View:LedgerBook` (run `shield:generate` +
  `shield:sync-super-admin` after deploy). Full double-entry (γενική λογιστική)
  stays out — exports feed the accountant's software. `LedgerBookTest` covers
  signed credit notes / scoping / filters / totals.
- **Βιβλίο Εσόδων-Εξόδων — εξαγωγές (CSV / Excel / JSON)**: header «Εξαγωγή»
  group on the page renders the current (filtered) period in three formats via
  `App\Services\Accounting\LedgerBookExporter` — CSV (UTF-8 BOM + ';' + comma
  decimal, el-GR-Excel-friendly) and JSON are dependency-free; the **.xlsx**
  uses the already-present `openspout/openspout` (bold header, raw numeric
  amounts so Excel sums/sorts) — no PhpSpreadsheet/maatwebsite needed. All three
  emit the same table + a totals trailer (έσοδα/έξοδα/ΦΠΑ balance).
  `LedgerBookExporterTest` covers CSV/JSON shape + that the xlsx is a real
  workbook. (The accountant's Union import format — Phase C — still TBD.)
- **myDATA — «Άντληση/έλεγχος από ΑΑΔΕ» on ΜΑΡΚ detail**: for a local invoice
  imported with a MARK but no AADE QR (Epsilon/legacy), a live pull by MARK
  (`RequestTransmittedDocs`) now stamps the QR (`qrCodeUrl`) onto
  `invoices.mydata_url` (+ `mydata_marks.invoice_url`) so our reprinted PDF
  shows MARK **and** QR. The same call drives a field-by-field **comparison**
  popup/panel — what agrees (✓) and what differs (⚠) vs AADE. Policy: QR always
  (re)written, everything else **fill-blanks only** (never overwrites a
  populated value on a filed doc), differences reported not auto-applied
  (`App\Services\MyData\EnrichInvoiceFromAade`, `App\Support\MyData\QrImage`,
  `MarkDetail`/`TransmittedDocReader` now carry `qrCodeUrl`). Gated on
  `View:MyDataConsole` (live AADE call). ORPHAN→create-local still deferred.
- **Data Import — Epsilon Smart Sales → invoices** (Phase 2): the «Epsilon
  Smart (JSON)» tab gains a Πωλήσεις (`DataExport-Sales.json`) slot. Each Epsilon
  sale lands as a historical, already-filed invoice — `active` + `mydata_state=
  VALID` + the MARK (leading apostrophe stripped) + a `mydata_marks` audit row
  (`mydata_action=INSERT`, so cancel/credit-note correlation finds it). Lines,
  invoice header and the MARK are written via raw query-builder inserts (mirrors
  the Firebird ETL) so the FILED net/gross values are kept verbatim — the
  `InvoiceLine` recompute hook is bypassed (it would drift by rounding and throw
  on zero-qty lines). Counterpart resolved by ΑΦΜ (resolve-or-create), product
  matched by name, the Epsilon DocNum kept as the ΑΑ, the invoice-type counter
  bumped so new ekdosi invoices continue. **Settled on import:** credit-term
  («Επί Πιστώσει») sales get a full settlement Payment dated at issue so the
  already-paid historical docs aren't phantom receivables; cash-term settle at
  issue via `InvoiceBalance`. Re-runnable by `(company_id, invcode)`; a re-run
  refreshes the header and replaces lines + mark + the settlement payment
  (`EpsilonImporter::importSales`). Known limitations: no AADE QR on the PDF
  (Epsilon exports the UID but not the QR URL); per-line E3 classification not
  stored (derived at submit, as elsewhere); non-GR counterpart defaults to GR.
### Added
- **Data Import — Epsilon Smart (JSON)** (Phase 1): the «Firebird Import» screen
  is renamed «Data Import» and gains a 2nd tab. The Firebird flow is unchanged
  (its own tab); the new «Epsilon Smart (JSON)» tab imports the Τιμολόγηση
  exports — **Customers** (match by ΑΦΜ) and **Items/Services → products** (VAT
  from the Epsilon class, unit, category; WhosalePrice as the net sell price).
  Re-runnable upsert by natural key; resolves against the standard AADE lookups
  the seeder installs (`App\Services\Etl\EpsilonImporter`). Runs synchronously
  (the exports are tiny). Sales→invoices is a planned Phase 2.
### Fixed
- **Data Import no longer 500s when an upload fails to persist.** If a uploaded
  file silently vanished before Filament saved it (temp-dir pruning, storage
  perms, or a request over php.ini's upload/post limits), every file field came
  back empty and `CreateFirebirdImportRun` fell through to the Firebird branch,
  crashing on `Storage::disk('local')->path(null)` (opaque flysystem TypeError).
  It now halts with an actionable Greek notification («Δεν ελήφθη κανένα αρχείο…»)
  and the Epsilon importer checks each staged path exists before reading.
- **Import View page no longer 500s on Epsilon counts.** The «Imported rows»
  infolist assumed the flat Firebird `table => int` shape and crashed on
  `number_format(array)` for the Epsilon importer's nested
  `entity => ['created','updated','skipped']` — so the View page died right after
  a successful Epsilon import. It now renders both shapes (nested → «+N νέα · ~N
  ενημ. · N παράλειψη»).

## 2026-06-02
### Added
- **Fresh-install seeding** (PR #152): a new Greek/myDATA tenant auto-installs
  the standard AADE lookups — VAT categories (§8.2) + invoice types pre-classified
  by-the-book (mydata_type + E3 income class + category) + payment methods (§8.12),
  distribution aims, metric units, delivery methods, product categories. Idempotent
  «Εισαγωγή τυπικών» buttons on each Setup list; runs on UI Create-Company +
  `db:seed`.
- **Tags** (PR #150): tenant-scoped tags on customers / suppliers / products /
  invoices / expenses — multi-select filter + Έξοδα-style fast-filter tabs (count
  badges) + bulk attach; managed via a `TagResource`.
- **Favourites-first pickers** (PR #150): `is_favorite` ⭐ on invoice types /
  customers / products; the invoice + quote pickers show favourites then most-used
  on open. Inline product create, Είδος→dimensions auto-fill, «Νέο Παραστατικό»
  from the customer Καρτέλα, full Greek labels on the invoice form.
### Changed
- ETL `copyLookup` now ADOPTS a pre-seeded lookup row by its natural key (stamps
  its legacy_id) so a re-import converges on one row instead of duplicating the
  seeded «Μετρητά» / «24%» / «ΤΕΜ» (PR #152).
### Removed
- Inert legacy `mailed` / `printed` / `email_sent` flags on invoices (PR #151).

## History
Earlier changes (tenancy/auth, customers + Καρτέλα, invoices + VAT math + QR/PDF,
myDATA submit/cancel/reconcile, payments + credit notes, Έξοδα/Ε3, Quotes, VIES,
roles, activitylog, the WHMCS bridge A–B3 + timologia v2) predate this file —
see `docs/CLAUDE-history.md`, `FEATURES.md`, and git history.

