# Backlog / Roadmap — deferred ideas

Cross-cutting TODOs that are **deliberately not built yet**. Captured so they
don't get lost in drift. (Per-feature plans live in their own docs:
`services-quotes-roadmap.md`, `expenses-phase-plan.md`,
`paroxos/` (regulatory-blueprint.md + implementation-plan.md). The myDATA-filing gaps + tech-debt list live in
`CLAUDE.md`.)

---

## UX — customer/product pickers: tags + favourites-first dropdown

> **UPDATE 2026-06-02 — favourites-first SHIPPED (boolean, not tags).** After
> the accountant walkthrough, the core ask landed on the **invoice form**:
> a per-row `is_favorite` boolean on `invoice_types` / `customers` / `products`
> (inline ⭐ ToggleColumn + «Αγαπημένα» filter in each list), and the three
> pickers now show **favourites first, then auto-top (most-used)** on open,
> before falling through to the normal search-on-type (`InvoiceForm::
> {invoiceType,favouriteCustomer,favouriteProduct,searchCustomer,searchProduct}
> Options()`). Also shipped in the same slice: **inline product/service create**
> from the line picker, the **Είδος → Σκοπός/τρόπος-πληρωμής/αποστολής
> auto-fill**, the **«Νέο Παραστατικό» button on the Καρτέλα** (reverse flow,
> `?customer_id=` preset), and **full Greek labels** on the invoice form.
> **Still deferred below:** the «Show all / browse beyond search» affordance.
>
> **UPDATE 2026-06-02 (b) — tags + QuoteForm SHIPPED too.** QuoteForm's
> customer + product pickers now share the favourites-first providers
> (`App\Filament\Support\PickerOptions`, used by both Invoice + Quote forms).
> And the **tags system landed**: tenant-scoped `tags` + `taggables` morph
> pivot (custom, not spatie), `App\Models\Concerns\HasTags` on Customer /
> Supplier / Product / Invoice, a `TagResource` (Setup) to manage the
> vocabulary + pin tags, and one shared `App\Filament\Support\Tags\TagControls`
> giving every list a **multi-select tag filter** + **Έξοδα-style fast-filter
> tabs** for *pinned tags that are actually used on that entity* + a badge
> column, plus a **bulk «Ετικέτες» action** on Invoices (to tag filed
> invoices that can't be edited via the form). **Deploy:** `php artisan
> migrate` then `php artisan shield:generate` + `shield:sync-super-admin` so
> the new `Tag` resource permissions exist and the role maps pick them up.

**Asked for, deferred 2026-05-31.** On the invoice/quote line forms (and the
header customer picker), the operator wants the dropdowns to surface the
common customers/products first instead of only showing results after typing.

Decided shape (NOT a plain `is_favorite` boolean — go with tags so we can also
filter the list tables):

1. **Tags on `customers` and `products`** — a reusable tag/label system (e.g.
   «συχνός», «χονδρική», «hardware»). Must also be **filterable in the
   Customers / Products list tables**, not just used by the pickers.
   - Open question: dedicated `tags` + pivot, or `spatie/laravel-tags`
     (already in the Laravel ecosystem), or a simple per-model JSON column.
     Tags need to be tenant-scoped (`company_id`) either way.

2. **Picker behaviour: «Αγαπημένα/tagged πρώτα + Show all»**
   - On open (before typing): show the tagged/favourite set.
   - Typing: normal `getSearchResultsUsing` search **as today** — keep it
     working unchanged; just bias tagged rows to the top
     (`orderByDesc(<tagged>)`).
   - A «Show all» affordance to browse beyond search (for occasional
     full-catalogue browsing without preloading thousands of rows).
   - Applies to: InvoiceForm + QuoteForm line `product_id`, and the header
     `customer_id` on both.

**Why deferred:** tags are a small feature of their own (schema + tag CRUD UI +
table filters + picker wiring). Not worth bolting on mid-form-redesign;
revisit as a focused slice.

---
