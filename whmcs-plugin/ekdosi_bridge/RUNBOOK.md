# Ekdosi Bridge — Operator Runbook

Operator-facing guide for the **ekdosi_bridge** WHMCS addon: what it does, how
to install it, and how to run it day-to-day. Keep this file in the plugin
directory so it ships with the addon.

> Deep technical detail (HMAC, column-widening, error envelopes) lives in
> `README.md` next to this file. This runbook is the "how do I operate it"
> companion.

---

## 1. What it does

The bridge connects this WHMCS install to **ekdosi** (the Laravel/Filament
invoicing app that files invoices to **AADE / myDATA**). Three jobs:

1. **Push invoices to ekdosi.** From a WHMCS admin invoice page you send an
   invoice to ekdosi's review inbox; ekdosi files it at AADE and writes the
   **MARK** back into our `mod_ekdosi_invoice_marks` table (NOT
   `tblinvoices.invoiced`, which stays the legacy app's SMALLINT flag).
2. **Third-party invoicing ("Παραστατικά σε τρίτους" / timologia v2).** A
   reseller can route a specific service's invoice to a **different billing
   party** (the end customer). The bridge serves that routing to ekdosi so the
   invoice is issued to the end customer, not the reseller.
3. **Reseller flagging.** ekdosi can mark which customers route invoices to
   third parties, so an operator can double-check whose invoices are whose.

It is the single successor to the older `prepare_for_ekdosi`, `afm2name`,
`timologia`, `transfer_invoice`, and `relid_remover` plugins.

---

## 2. Install

1. **Upload** the whole `ekdosi_bridge/` folder to
   `<whmcs_root>/modules/addons/ekdosi_bridge/`.
2. **Activate**: *Admin → System Settings → Addon Modules → Ekdosi Bridge →
   Activate*. On activation the addon:
   - creates its own tables `mod_ekdosi_invoice_marks` (the AADE MARK store),
     `mod_ekdosi_contacts` + `mod_ekdosi_routing`;
   - **restores `tblinvoices.invoiced` to SMALLINT** if a previous version
     widened it to BIGINT (moving any MARK into `mod_ekdosi_invoice_marks`
     first) — so the legacy ekdosi app keeps working.
   The activation message reports what it did. If it warns it lacked privileges
   (managed hosting), run the SQL it prints, then re-activate.
3. **Configure** (same page → *Configure*):
   - **Ekdosi base URL** — e.g. `https://ekdosi.example.com` (host only).
   - **Ekdosi tenant slug** — must match `companies.slug` in ekdosi (e.g. `myip`).
   - **Shared HMAC secret** — a 32+ char random string; paste the **same**
     value into ekdosi's `companies.whmcs_webhook_secret`.
   - **Show client v2 page** — leave **OFF** for now (see §6).
   - **v2 pilot client IDs** — leave blank for now (see §6).
4. **Grant access**: *Configure → Access Control* → tick the admin roles that
   should see the bridge.

> ekdosi only needs the **one** URL (its `whmcs_api_url`, ending
> `/includes/api.php`). It derives the bridge endpoints (`inbound.php`,
> `resolve.php`) from it — you don't configure those paths anywhere.

---

## 3. Operate — filing invoices

From any WHMCS **admin invoice page** there's an *"Open in Ekdosi Bridge"*
button → the bridge admin page for that invoice, where you can:

- **Send to ekdosi for review** — stages the invoice in ekdosi's inbox. An
  operator reviews and files it at AADE on the ekdosi side.
- **Show ekdosi status** — pending / filed (with MARK) / rejected / held.
- **Reset to unfiled** — drops our MARK row in `mod_ekdosi_invoice_marks`
  (rare; only after you cancelled the AADE filing first). Does not touch the
  legacy `tblinvoices.invoiced` flag.

The MARK is written back automatically once ekdosi files at AADE.

---

## 4. Operate — third-party invoicing (sync)

The third-party routing originally lives in the legacy `timologia` tables
(`mod_timologia*`). The bridge keeps its **own** copy and imports from legacy:

1. Bridge admin page → **"Sync from legacy timologia"**. This imports contacts
   + routing into `mod_ekdosi_*`. It is **read-only against the legacy tables**
   and **re-runnable** (safe to click again any time the legacy data changes).
2. Verify the reported counts (contacts/routes inserted/updated/skipped).

Re-run the sync whenever resellers change their legacy routing, up until you
retire the legacy plugin.

> Rows created in the **v2 client page** (§6) are kept separate (they have no
> legacy id) and are **never overwritten** by a sync.

---

## 5. Operate — enabling third-party billing in ekdosi

Billing the end customer is gated **per ekdosi tenant** by a kill-switch
(`companies.whmcs_third_party_enabled`, default OFF). Until you flip it on,
ekdosi bills the reseller exactly as before — so you can deploy + sync safely
first and validate with no behaviour change.

Recommended order (run on the ekdosi host):

```bash
# READ-ONLY: see how one real invoice would resolve (no changes made)
php artisan whmcs:resolve-third-party <whmcs_invoice_id> --tenant=<slug>

# READ-ONLY: list resellers (clients with >=1 third-party route)
php artisan whmcs:resolve-third-party --tenant=<slug> --resellers
```

When the resolution looks right, set `whmcs_third_party_enabled = true` for that
tenant. Then, as invoices are ingested:

- **single third party** → ekdosi bills the end customer (created/matched by ΑΦΜ);
- **multi-party** (one WHMCS invoice mixing parties) → parked **held** in the
  inbox; an operator uses **"Διαχωρισμός σε προσχέδια"** to create one draft per
  party, then files each;
- **no routing** → bills the WHMCS client as before.

Update the reseller badges any time:

```bash
php artisan whmcs:sync-resellers --tenant=<slug>
```

---

## 6. Operate — the client v2 page (hidden by default)

The client-area page **"Παραστατικά σε τρίτους (v2)"** lets a reseller manage
their own contacts + per-service routing. It is **hidden from customers** until
you turn it on, so you can test it during quiet hours without anyone noticing.

**Off-hours test recipe:**

1. Bridge config → **v2 pilot client IDs** = *your own* WHMCS client id (so only
   you see it).
2. Bridge config → **Show client v2 page** = **On**.
3. Log in to the **client area** as that client → *Billing → Παραστατικά σε
   τρίτους (v2)*. Add a contact, route a service, toggle απόδειξη.
4. When done, set **Show client v2 page** back to **Off** (or clear the pilot
   list). Customers see nothing again.

Notes:
- With the switch **on and the pilot list empty**, **all** clients see it — use
  the pilot list to limit exposure.
- The v2 page writes the bridge's **own** tables only; a pilot client's edits are
  **not** mirrored to the legacy `timologia` plugin. Manage a given client's
  routing in one place at a time during the parallel run.

---

## 7. Coexistence & cutover

- The bridge runs **safely alongside** the legacy `prepare_for_ekdosi` /
  `timologia` plugins and the legacy ekdosi app during the dual-run — it no
  longer writes `tblinvoices.invoiced` (the MARK lives in
  `mod_ekdosi_invoice_marks`), so the legacy flag is never clobbered.
- **Cutover**: once you trust the bridge end-to-end, deactivate
  `prepare_for_ekdosi`; once resellers are managed in the v2 page, retire the
  legacy `timologia` plugin (do a final **Sync** first).

---

## 8. Troubleshooting (quick)

| Symptom | Check |
|---|---|
| Status/push says "not configured" | Base URL / slug / secret all set on the config page? Secret matches ekdosi exactly? |
| Write-back fails / MARK not in WHMCS | Does `mod_ekdosi_invoice_marks` exist? (re-activate, or run the CREATE TABLE from §2). |
| Legacy app says "invoiced is not SMALLINT" | A pre-0.14 version widened it. Update the files and **re-activate** so the rollback runs (or run the SQL in README §2). |
| `resolve-third-party` shows no routing | Did you click **"Sync from legacy timologia"**? Are the legacy `mod_timologia*` tables present? |
| Client can't see the v2 page | **Show client v2 page** on? If a pilot list is set, is the client's id in it? |
| Customers can see v2 unexpectedly | Switch is on with an **empty** pilot list → it's visible to all. Add ids or switch off. |

Deeper diagnostics, error codes, and the security model: see `README.md`.
