# Known issues and readiness ledger

This file is the working source of truth for installer, first-run setup, myDATA,
scheduler/queue and production-readiness work. Keep completed entries in the file:
change their status to **DONE**, add the implementing commit/PR and record the date
in the change log.

## Audit baseline

- Repository: `chrismfz/ekdosi`
- Audited branch: `main`
- Audited commit: [`7c75cd35a6ea38bb5a11b04b818a6585baeea869`](https://github.com/chrismfz/ekdosi/commit/7c75cd35a6ea38bb5a11b04b818a6585baeea869)
- Audit date: 2026-08-29
- AADE production specification checked: [myDATA ERP v2.0.1](https://www.aade.gr/sites/default/files/2026-03/myDATA%20API%20Documentation%20v2.0.1_official_erp.pdf)
- Digital Delivery Note specification checked: [Delivery Note v2.0.1](https://www.aade.gr/sites/default/files/2026-03/myDATA%20API%20Documentation_DeliveryNote_v2.0.1_official.pdf)

This is a source audit. It confirms what the repository installs and schedules; it
cannot prove that a particular host actually has the required OS crontab, worker,
TLS certificate or off-site backup credentials.

## Status and priority

Statuses:

- **OPEN** — verified issue, not implemented.
- **IN PROGRESS** — implementation has started.
- **VERIFY** — implementation exists but acceptance criteria have not all passed.
- **WATCH** — currently correct; re-check when an upstream dependency/spec changes.
- **DONE** — fixed and verified; retain the entry for history.

Priorities:

- **P0** — can file incorrect data, expose the wrong filing path or falsely declare go-live readiness.
- **P1** — blocks or materially confuses a normal first-time operator.
- **P2** — operations, resilience, documentation or coverage gap.

## Work board

| ID | Priority | Status | Area | Summary |
|---|---:|---|---|---|
| MYD-001 | P0 | OPEN | Classification | Third-country 1.3/2.3 use the intra-EU E3 code |
| MYD-002 | P0 | OPEN | ΤΔΑ | Seeded ΤΔΑ is not a combined invoice/delivery-note payload |
| MYD-003 | P0 | OPEN | Delivery notes | 9.x types are exposed in the monetary invoice picker |
| MYD-004 | P0 | OPEN | VAT validation | 3% and 0% can pass preflight but fail at submit time |
| MYD-005 | P1 | OPEN | Quantity units | Standard invoice XML omits myDATA measurementUnit |
| MYD-006 | P1 | OPEN | Classifications | Seed defaults are not correct for every business activity |
| SETUP-001 | P1 | OPEN | Onboarding | Fresh tenant is not guided to a first valid invoice |
| SETUP-002 | P1 | OPEN | Issuer identity | Installer accepts insufficient legal/myDATA issuer data |
| SETUP-003 | P1 | OPEN | Payment | Missing payment method silently becomes cash in XML |
| SETUP-004 | P2 | OPEN | Estonia | EE tenant skips even non-AADE standard lookups |
| OPS-001 | P1 | OPEN | Scheduler/queue | Installer does not provision or prove OS cron and worker |
| OPS-002 | P2 | OPEN | Installer | Writable env/application root is not a hard preflight |
| OPS-003 | P2 | OPEN | Shared hosting | No cPanel/shared-hosting queue recipe or direct completion link |
| TEST-001 | P2 | OPEN | Tests/CI | No full web installer success-path test; inspected CI was not green |
| DEP-001 | P2 | WATCH | Dependency | firebed/aade-mydata is current; watch AADE v2.0.2 |

## Detailed issues

### MYD-001 — Third-country sales use the wrong E3 code

**Status:** OPEN · **Priority:** P0

**Evidence**

- [`Codes::TYPE_DEFAULTS`](app/Support/MyData/Codes.php) maps both 1.3 and 2.3 to
  `E3_561_005`.
- AADE v2.0.1 defines `E3_561_005` as external intra-community sales and
  `E3_561_006` as external third-country sales.
- The intra-community mappings 1.2/2.2 to `E3_561_005` are correct.

**Required change**

- Map 1.3 and 2.3 to `E3_561_006`.
- Add exact seeder/default tests for all four cross-border types.
- Confirm an operator override is still preserved by the fill-empty seeding policy.

**Acceptance**

- A fresh GR install seeds 1.2/2.2 as `561_005` and 1.3/2.3 as `561_006`.
- Existing operator-edited classifications are not overwritten.

### MYD-002 — Seeded ΤΔΑ is not a combined delivery document

**Status:** OPEN · **Priority:** P0

**Evidence**

- [`MyDataLookupSeeder`](app/Services/MyData/MyDataLookupSeeder.php) names ΤΔΑ
  «Τιμολόγιο Πώλησης / Δελτίο Αποστολής» but maps it as ordinary type 1.1.
- [`AadeInvoiceDocument`](app/Services/EInvoice/AadeInvoiceDocument.php) does not
  emit `isDeliveryNote=true`, loading/delivery addresses, dispatch date/time,
  vehicle or the complete goods-movement header.
- AADE treats 1.1 with `isDeliveryNote=false` as a monetary invoice, not a
  digital delivery document.

**Required change**

Choose one explicitly:

1. Implement the full combined invoice/delivery-note payload and lifecycle; or
2. Rename/remove ΤΔΑ from the standard seed until that implementation exists.

**Acceptance**

- A document labelled ΤΔΑ passes the official delivery-note XSD and sandbox
  lifecycle, including `isDeliveryNote` and required movement data; or no such
  label is offered to the operator.

### MYD-003 — Standalone 9.x delivery types appear in the invoice form

**Status:** OPEN · **Priority:** P0

**Evidence**

- Seeded ΔΑΣ/ΣΔΑ/ΔΑΠ (9.1/9.2/9.3) have `show_on_menu=true`.
- [`PickerOptions::invoiceTypeOptions()`](app/Filament/Support/PickerOptions.php)
  includes every visible type without excluding 9.x.
- [`InvoiceForm`](app/Filament/Resources/Invoices/Schemas/InvoiceForm.php) uses
  that picker.
- Invoice submission resolves through
  [`EInvoiceSubmitterFactory`](app/Services/EInvoiceSubmitterFactory.php) and
  [`MyDataSubmitter`](app/Services/MyDataSubmitter.php), which build
  [`AadeInvoiceDocument`](app/Services/EInvoice/AadeInvoiceDocument.php).
- The correct coded quantity/unit delivery flow exists separately in
  [`DeliveryNoteSubmitter`](app/Services/Delivery/DeliveryNoteSubmitter.php).

**Required change**

- Exclude delivery-only 9.x types from monetary invoice selectors/actions.
- Keep them available only through the Delivery Notes resource.
- Add UI/query tests that assert the separation.

**Acceptance**

- An operator cannot select 9.1/9.2/9.3 from Create Invoice.
- The standalone delivery-note resource still exposes and submits the supported
  types through `DeliveryNoteSubmitter`.

### MYD-004 — VAT preflight has false-green cases

**Status:** OPEN · **Priority:** P0

**Evidence**

- [`Codes::vatCategorySeedRows()`](app/Support/MyData/Codes.php) seeds official
  VAT categories 1–7: 24%, 13%, 6%, 17%, 9%, 4%, 0%.
- Fresh install does not seed code 9 (3%) or the newer code 10 (4%).
- [`AadeInvoiceDocument::vatCategoryFor()`](app/Services/EInvoice/AadeInvoiceDocument.php)
  has no automatic 3% mapping; 3% needs explicit `mydata_vat_category=9`.
- [`MyDataConfigAudit`](app/Services/MyData/MyDataConfigAudit.php) can accept the
  official 3% match without proving that the submitter can serialize it.
- A 0% row without an exemption reason produces only a warning in readiness
  checks but throws at actual submission.
- [`MyDataPreflight`](app/Console/Commands/MyDataPreflight.php) exits successfully
  when only warnings exist.

**Required change**

- Use one shared «fileable VAT» decision in audit, go-live and submission.
- Treat a used/configured 3% rate without explicit code 9 as failure.
- Treat a used 0% rate without an exemption reason as failure.
- Add an explicit guided choice for the two official 4% categories (6 vs 10);
  never guess between them.
- Add tests proving that green preflight implies successful XML build.

**Acceptance**

- Every seeded/selected VAT rate that passes `mydata:preflight` builds valid XML.
- 3% without code 9 and 0% without exemption reason fail before invoice issue.

### MYD-005 — Standard invoice XML loses pieces/kilos semantics

**Status:** OPEN · **Priority:** P1

**Evidence**

- Standard lookups correctly seed ΤΕΜ, ΥΠΗΡΕΣΙΑ, ΩΡΑ, ΜΗΝΑΣ, ΕΤΟΣ, ΚΙΛΟ,
  ΛΙΤΡΟ, ΜΕΤΡΟ, Μ² and Μ³.
- Standard invoices emit numeric `quantity` when required.
- [`AadeInvoiceDocument`](app/Services/EInvoice/AadeInvoiceDocument.php)
  intentionally omits `measurementUnit`; the catalogue value is free text.
- Standalone delivery notes already use the official coded quantity types.

AADE allows quantity and measurement unit to be omitted on ordinary invoice rows,
so this is not necessarily an XSD rejection. It is a loss of meaning: AADE can
receive «10» without knowing whether it means pieces or kilos.

**Required change**

- Add an explicit catalogue-unit → AADE quantity-type mapping.
- Emit `measurementUnit` when a safe mapping exists.
- Do not invent a code for service/time units that do not map cleanly.

### MYD-006 — Classification defaults are a baseline, not universal accounting truth

**Status:** OPEN · **Priority:** P1

**Evidence**

- Goods types default to `category1_1` (merchandise/resale).
- A producer/manufacturer can require `category1_2` (own products).
- Credit types currently use service-first defaults and require operator review.

**Required change**

- During onboarding ask the business activity/default income category.
- Apply that choice to the seeded document types or present a mandatory review.
- Keep all operator edits protected from future idempotent seeds.

### SETUP-001 — No guided first-valid-invoice onboarding

**Status:** OPEN · **Priority:** P1

**Evidence**

- Production install correctly avoids demo customer/product data.
- The invoice form requires a customer, including for ΑΛΠ.
- Customer cannot be created inline and no «Λιανική» customer is seeded.
- A product can now be created inline; product categories, delivery methods and
  standard lookups are already seeded.
- [`install/done.blade.php`](resources/views/install/done.blade.php) presents
  infrastructure reminders, not a business onboarding flow.
- `mydata_mode=off` is the correct safe install default, but there is no guided
  transition through credentials → sandbox → production.

**Required change**

Add a resumable first-run checklist/wizard:

1. Complete issuer identity.
2. Configure myDATA credentials and choose sandbox/production deliberately.
3. Review business classification and VAT defaults.
4. Choose a default payment method.
5. Create first customer (or retail customer policy).
6. Create first product/service.
7. Build and submit a sandbox test document.
8. Run go-live/preflight and show actionable failures.

### SETUP-002 — Installer accepts insufficient issuer data

**Status:** OPEN · **Priority:** P1

**Evidence**

- The web installer captures company name, country and optional AFM.
- A direct myDATA submission cannot succeed without issuer AFM.
- Delivery notes and correct legal/PDF identity need complete address data.

**Required change**

- Either require AFM for a GR myDATA tenant during install, or make «Complete
  issuer details» an unavoidable onboarding gate.
- Require name, AFM, address, city, postcode and country before declaring filing
  readiness.
- Keep pure PDF/offline tenants possible through an explicit choice.

### SETUP-003 — Missing payment method silently becomes cash

**Status:** OPEN · **Priority:** P1

**Evidence**

- All eight official methods are seeded.
- `payment_method_id` is optional in [`InvoiceForm`](app/Filament/Resources/Invoices/Schemas/InvoiceForm.php).
- [`AadeInvoiceDocument`](app/Services/EInvoice/AadeInvoiceDocument.php) falls
  back to myDATA payment method 3 (cash) when no valid mapping is available.

**Required change**

- Require a payment method before filing, or set a visible tenant/type/customer
  default.
- Do not silently convert an unknown/unmapped selection to cash.
- Add a pre-submit validation test.

### SETUP-004 — EE tenants skip neutral lookups

**Status:** OPEN · **Priority:** P2

**Evidence**

- [`Install`](app/Console/Commands/Install.php) calls standard lookup seeding
  only when `country=GR`.
- EE therefore receives neither AADE-specific rows nor neutral units, payment
  methods, delivery methods and product categories.

**Required change**

Split the seed into:

- country-neutral commercial lookups; and
- GR/myDATA-specific VAT, invoice and classification lookups.

Run the neutral portion for EE.

### OPS-001 — Scheduler and queue are implemented but not provisioned/proved

**Status:** OPEN · **Priority:** P1

**Verified implementation**

- [`routes/console.php`](routes/console.php) contains the real Laravel schedule.
- [`config/ekdosi.php`](config/ekdosi.php) provides per-task enable/timing defaults.
- [`ScheduleSettings`](app/Filament/Pages/ScheduleSettings.php) exposes DB overrides.
- Default queue connection is `database`.
- A tiny queued heartbeat runs every five minutes and `ops:health` can detect a
  dead scheduler/worker.
- Scheduled jobs use bounded `withoutOverlapping` locks and per-tenant isolation
  where appropriate.
- [`INSTALL.md`](INSTALL.md) documents a VPS cron and systemd queue service.

**External runtime contract**

The repository does not run any scheduled task until the host has:

~~~cron
* * * * * cd /var/www/ekdosi && php artisan schedule:run >> /dev/null 2>&1
~~~

It also needs a continuously supervised worker, for example:

~~~text
php artisan queue:work --queue=default --tries=3 --max-time=3600
~~~

The installer cannot prove those host-level services are active merely by writing
configuration.

**Required change**

- Make the completion screen distinguish «application installed» from
  «scheduler/worker verified».
- Poll the scheduler and queue heartbeats after provisioning and keep the
  go-live status non-green until both are fresh.
- Link directly to the relevant `INSTALL.md`/runbook sections.
- Consider generating copy/paste service definitions using the actual PHP binary
  and application path.

### OPS-002 — Env/application-root writability is not a hard preflight

**Status:** OPEN · **Priority:** P2

**Evidence**

- `EnvWriter` performs an atomic env-file replacement.
- Migrations and `ekdosi:install` run before the final env write/installed marker.
- `RequirementsChecker` validates extensions and storage/cache paths but does
  not prove that the application root/`.env` target is writable.

This means the env write can fail after the database already contains migrated
schema/company/admin data. Retry is designed to be idempotent, but the complete
install is not one atomic transaction.

**Required change**

- Add a safe writability/replacement preflight for the exact env target directory.
- Surface a hard blocker before any DB mutation.
- Add a failure/retry test proving no duplicate tenant/admin is created.

### OPS-003 — Shared-hosting/cPanel deployment path is incomplete

**Status:** OPEN · **Priority:** P2

**Evidence**

- VPS/systemd instructions exist in `INSTALL.md`.
- The short go-live runbook does not contain a shared-hosting worker recipe.
- The install completion page does not link directly to a cPanel-specific path.

**Required change**

Document and test a shared-hosting fallback, for example a once-per-minute cron
with overlap protection running:

~~~text
php artisan queue:work --stop-when-empty --queue=default --tries=3
~~~

This is less responsive than a supervised worker but is appropriate where
systemd/Supervisor is unavailable. Document PHP binary discovery, application
path, lock/overlap protection, scheduler cron and log location.

### TEST-001 — Missing end-to-end installer proof and independent green CI

**Status:** OPEN · **Priority:** P2

**Evidence**

- Installer guard, requirement, env writer and CLI command tests exist.
- There is no complete browser/HTTP success-path test proving:
  `POST /install → migrations → ekdosi:install → env → installed marker`
  against a real MariaDB target.
- The most recent inspected PR #383 workflow ended before runner allocation
  (no steps/logs). That is an Actions/runner infrastructure failure, not evidence
  of an application regression, but it also is not a green proof for this baseline.

**Required change**

- Add a disposable-MariaDB web installer success test.
- Cover empty DB, allowed existing DB, env-write failure/retry and final route lock.
- Restore a completed green GitHub Actions run on main.

### DEP-001 — firebed/aade-mydata upstream status

**Status:** WATCH · **Priority:** P2

**Checked 2026-08-29**

| Item | Result |
|---|---|
| Composer constraint | `firebed/aade-mydata: ^5.10` |
| Locked version | `v5.10.4` |
| Locked commit | [`6572afb6779cfcb31bdc8fb7cc2a7721eb7ace79`](https://github.com/firebed/aade-mydata/commit/6572afb6779cfcb31bdc8fb7cc2a7721eb7ace79) |
| Upstream default branch | `5.x` |
| Upstream HEAD | `6572afb6779cfcb31bdc8fb7cc2a7721eb7ace79` |
| Compare v5.10.4…HEAD | identical; ahead 0, behind 0 |
| Supported schema | myDATA v2.0.1 XSDs |
| Upgrade required now | **No** |

Notes:

- GitHub's latest formal Release object is v5.10.0, but upstream tags continued
  through v5.10.4. The lock is on the current v5.10.4 tag and exact branch HEAD.
- The package contains the v2.0.1 invoice and Digital Goods Movement XSDs/APIs.
- Being current does not resolve MYD-001–MYD-005 automatically: Ekdosi owns custom
  lookup defaults, UI routing and [`AadeInvoiceDocument`](app/Services/EInvoice/AadeInvoiceDocument.php).
- AADE already publishes a preofficial v2.0.2 in the
  [test environment](https://www.aade.gr/mydata-ilektronika-biblia-aade/mydata/dokimastiko-periballon).
  Keep this item in WATCH until v2.0.2 becomes production/final and the package
  publishes corresponding support.

Re-check this entry when any of the following happens:

- AADE changes the production technical-specification version.
- `firebed/aade-mydata` publishes a new tag.
- Composer changes the locked commit.
- A new delivery-note lifecycle endpoint is adopted by Ekdosi.

## Scheduler inventory

The code-side schedule exists. The current defaults below still require the
external once-per-minute `schedule:run` invocation.

| Task group | Default cadence | Default |
|---|---|---|
| Queue heartbeat | every 5 minutes | ON |
| Mail orphan sweep | every 15 minutes | ON |
| Failed invoice email retry | hourly at :30 | OFF |
| WHMCS fetch | every 15 minutes | ON |
| WHMCS auto issue | every 15 minutes | OFF |
| WHMCS payment sync/reconcile | every 30 minutes | OFF |
| myDATA sales reconciliation | daily 06:00 Europe/Athens | ON |
| myDATA VAT-picture refresh | every 4 hours | ON |
| myDATA expenses refresh | every 6 hours | OFF |
| myDATA console warmup | every 6 hours | OFF |
| Overdue notifications | daily 07:30 Europe/Athens | OFF |
| Service renewal staging | daily 07:00 Europe/Athens | OFF |
| Service dunning sweep | daily 08:00 Europe/Athens | ON; product opt-in still required |
| AI reminder dispatch | every minute | ON; feature master switch still applies |
| Whole-DB backup run/clean/monitor | 02:00 / 02:30 / 08:00 | ON |
| Per-company backups | hourly due sweep | OFF |
| Pending self-update | every minute | ON; no-op unless queued |
| Failed queue prune | daily, retain 14 days | ON |

## Verified working baseline — do not regress

These are not open issues:

- Pre-APP_KEY web installer access is token protected and does not depend on
  session/CSRF state.
- Database test and explicit non-empty-DB override protect against accidental use
  of the wrong database.
- Env replacement itself is atomic.
- Migrations, `ekdosi:install --force`, standard GR lookup seeding, Shield
  generation and tenant role provisioning are wired.
- The installed marker closes `/install` after completion.
- GR seed includes standard VAT rows 1–7, all eight payment methods, commercial
  metric units, delivery methods, product categories and 15 document-type rows.
- Core domestic mappings for ΤΙΜ 1.1, ΤΠΥ 2.1, ΑΛΠ 11.1 and ΑΠΥ 11.2 are
  sensible baseline defaults.
- Lookup seeding fills empty fields and preserves operator edits.
- Standalone Delivery Notes have a separate coded quantity/unit submission path.
- `mydata_mode=off` is an intentional safe install default, not a defect.
- `firebed/aade-mydata` is currently fully up to date with its upstream 5.x branch.

## Change log

| Date | Change | Commit/PR |
|---|---|---|
| 2026-08-29 | Initial combined installer/myDATA/cron/dependency audit ledger | pending initial commit |
