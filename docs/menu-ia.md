# Menu / Information Architecture — target for the full WHMCS-scope panel

> **Status: design / target — not built.** The concrete plan behind the `docs/BACKLOG.md`
> «Menu / IA architecture» item. The question this answers: *when tickets + reports + domains +
> services + servers + server-groups + domain-pricing + registrars + TLDs + whois settings + …
> all land, where does it all go without the nav becoming an encyclopedia?*

## The five principles (orientation-agnostic — sidebar OR top-nav both work on top of this)

1. **≤ ~9 top-level entries.** A menu is scannable at ~7±2. Everything else is a **sub-item inside
   a Cluster**, never a top-level row. (Filament `Cluster` = the `MyDataCluster` pattern we already
   ship: one nav entry → its own sub-navigation + breadcrumb.)
2. **Ops vs Config split (the real scaling move).** Each domain's **daily** screens live in its
   domain Cluster; each domain's **configuration** goes to the **Settings** Cluster, sub-grouped by
   domain. Config is the *bulk* (WHMCS's whole «Configuration» sidebar) — it must not pollute daily
   nav. WHMCS confirms this split (see `docs/ticket-system-eval.md` «WHMCS parity»).
3. **Per-tenant gating is what makes it "fit".** Every pillar sits behind a `companies.enable_*`
   flag (already the plan for Domains → `enable_domain_management`). **No tenant ever sees all of
   it** — a mainland invoicing-only tenant sees ~5 clusters; a full-reseller tenant sees them all.
   The menu is *per-tenant*, not global.
4. **Two tiers max.** Cluster → items. No third level. Settings is the one place with an internal
   per-domain grouping (section headers inside the one Settings Cluster).
5. **Global search (Cmd+K)** is the flat escape hatch, so depth never hurts findability.

## Target top-level map (every CURRENT screen has a home; FUTURE ones slot in)

Legend: plain = built today · _(future)_ = a pillar not yet built · **⚑gated** = per-tenant flag.

```
📊 Επισκόπηση            Dashboard · Αναφορές (Reports/SalesActivity) · Ληξιπρόθεσμα (AgedReceivables)
                        · Ροή δραστηριότητας (ActivityFeed) · AI Βοηθός (Assistant)

👥 Πελάτες              Πελάτες (Customers) · Προμηθευτές (Suppliers) · Χρήστες πύλης (CustomerUsers)
                        · Leads (+ Board + Calendar) · Ετικέτες (Tags)

🧾 Τιμολόγηση           Παραστατικά (Invoices) · Προσφορές (Quotes) · Πληρωμές (Payments)
                        · Έξοδα (Expenses) · CMR · Βιβλίο Εσόδων-Εξόδων (LedgerBook)

📦 Είδη & Υπηρεσίες     Προϊόντα (Products) · Κατηγορίες (ProductCategories)
                        · Συμβόλαια υπηρεσιών (ServiceContracts) · Αποθήκη

🇬🇷 myDATA              Κονσόλα (MyDataConsole/Expenses/E3) · Αντιπαραβολή (Reconciliation)
                        · Ψηφιακή Διακίνηση (DeliveryNotes) · MARK detail · Πάροχος (ProviderConsole)

🔌 Διασυνδέσεις         WHMCS Εισερχόμενα (WhmcsInbox) · Bridges · Log πύλης (PaymentGatewayEvents)
                        · Payment intents

🌐 Domains ⚑            _(Πυλώνας A)_ Τα domains · Availability/WHOIS · Μεταφορές · NS/Contacts
🖥️ Υπηρεσίες/Servers ⚑  _(Πυλώνας C)_ Υπηρεσίες πελατών · Servers · Server groups · Provisioning log
🎫 Υποστήριξη ⚑         _(Πυλώνας E)_ Tickets · Predefined replies · Knowledgebase · Announcements

⚙️ Ρυθμίσεις            ← the whole config bulk, sub-grouped by domain (below)
```

## The Settings Cluster (WHMCS «Configuration» — where the bulk hides)

One top entry, internally sectioned by domain. Each pillar drops its config here as it lands:

```
⚙️ Ρυθμίσεις
  Εταιρεία        Ρυθμίσεις εταιρείας (CompanySettings) · Γενικές (GeneralSettings)
                  · Εταιρείες (Companies, super-admin) · Χρήστες (Users) · Ρόλοι
  Τιμολόγηση      Σειρές/Τύποι (InvoiceTypes) · Τρόποι πληρωμής (PaymentMethods)
                  · Κατηγορίες ΦΠΑ (VatCategories) · Μονάδες (MetricUnits)
                  · Τραπ. λογαριασμοί (BankAccounts) · Σκοποί/κλάσεις (DistributionAims,
                    ExpenseClassificationRules)
  Πληρωμές        Συνδέσεις πυλών (PaymentGatewayConnections — creds)
  myDATA          Credentials (on Company) · Preflight · MyDataConfigCheck · Οδηγός κωδικών
  WHMCS           WhmcsIncomeMapping · WhmcsPaymentMapping · WhmcsPaymentSync · field maps
  Σύστημα         Υγεία (SystemHealth) · Scheduler (ScheduleSettings) · ETL (FirebirdImportRuns)
                  · Ενημερώσεις (UpdateRuns) · Λογαριασμοί (Accounts)
  Support     ⚑   _(future)_ Departments · Ticket statuses · Escalation · Spam
  Domains     ⚑   _(future)_ TLD pricing · Registrars (creds) · Domain settings
  Servers     ⚑   _(future)_ Server definitions · Server groups · Provisioning modules
```

## Why this "fits" — per-tenant, not global

Because of gating (principle 3), the count each operator actually sees is small:

| Tenant profile | Clusters shown |
|---|---|
| Mainland invoicing-only (myip, nexon) | Επισκόπηση · Πελάτες · Τιμολόγηση · Είδη · myDATA · Ρυθμίσεις = **6** |
| + WHMCS bridge | + Διασυνδέσεις = 7 |
| Full WHMCS-replacement reseller | + Domains + Servers + Υποστήριξη = 10 |

10 is the ceiling, reached only by a tenant that actually sells all of it — and even then it's 10
scannable Clusters, not 60 flat rows.

## Migration path (incremental — no big-bang)

1. ✅ **DONE (2026-09-06) — Settings Cluster.** The 13-item «Ρυθμίσεις» flat group is now the
   `App\Filament\Clusters\SettingsCluster` — one nav entry (bottom, in the «Σύστημα» admin zone,
   since Filament renders ungrouped items at the TOP so a standalone-bottom entry needs a group)
   that opens a dedicated settings area with sub-navigation. Members: the 11 lookup Resources +
   `CompanySettings` + `MyDataCodeGuide` + `WhmcsIncomeMapping` + `WhmcsPaymentMapping` (the last two
   register only for WHMCS-integrated tenants — easy to miss); URLs moved under `/settings/…`;
   `MenuStructureTest` uses a WHMCS-integrated tenant so a stray `getNavigationGroup('Ρυθμίσεις')` fails it.
   **Still to fold in (a follow-up):** the config-ish «Σύστημα» items (GeneralSettings/Preflight/
   ScheduleSettings/UpdateRuns/Companies/Users) → one clean Settings zone.
   _(P2, deferred — review #492): `SettingsCluster` + `MyDataCluster` share identical
   `canAccess = canAccessClusteredComponents` boilerplate; when a 3rd cluster lands, extract an
   `abstract BaseNavCluster` to hold it once.)_
2. **Fold** the current 9 flat groups into the ~6 domain Clusters above (mechanical: set
   `$cluster` on each Resource/Page instead of `$navigationGroup`).
3. **Each new pillar is BORN as a Cluster** (Support/Domains/Servers), gated — so it never adds a
   flat pile; it adds exactly one top entry (hidden unless enabled).
4. **Orientation last & reversible:** with ~9 Clusters, `->topNavigation()` gives the WHMCS
   horizontal look for one line, or keep the sidebar. A *custom* multi-column mega-menu is NOT worth
   it (custom Blade + `panel.css` with no Tailwind + maintenance) — native top-nav is ~80% of the
   look for ~0 cost.

## Open decisions (for when we build it)
- Dashboard: its own top entry, or the first item of «Επισκόπηση»? (lean: Επισκόπηση.)
- Do Suppliers live under «Πελάτες» or «Είδη & Υπηρεσίες»? (lean: Πελάτες — they're a party.)
- Sidebar vs top-nav — decide after the Cluster fold (both then work); prototype `topNavigation()`
  behind a flag to eyeball it.
