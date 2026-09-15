<?php

use App\Support\IsoCountry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MYD-011: freeze the delivery recipient's ISO-3166-1 alpha-2 country on the note.
 *
 * The recipient can be a customer, a supplier, or a manual entry; only a customer
 * carried a country (via the customers FK), so a supplier/manual foreign recipient
 * had no country source and DeliveryNoteSubmitter defaulted it to GR — filing a
 * foreign party as Greek. Like recipient_afm/recipient_name, this is a snapshot
 * frozen at issue time.
 *
 * Nullable: existing rows have none, and an internal movement (ενδοδιακίνηση, no
 * external recipient) legitimately leaves it blank — the submitter fills GR there
 * because the recipient IS the (Greek) issuer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->string('recipient_country', 2)->nullable()->after('recipient_afm');
        });

        $this->backfillFromCustomers();
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->dropColumn('recipient_country');
        });
    }

    /**
     * Backfill NOT-YET-FILED notes from their linked customer's country where it
     * resolves to a real ISO code.
     *
     * Without this, every existing note whose customer has a usable country would
     * hit the submitter's new "refuse an external recipient with no country" guard
     * at issue time, even though the country was knowable all along. Notes with no
     * customer (supplier/manual) or an unusable country are deliberately left null
     * — those are exactly the ones an operator must decide, and refusing them is
     * the point of MYD-011.
     *
     * ALREADY-FILED notes (`mydata_mark` set) are skipped on purpose. The column is
     * a snapshot of what was SUBMITTED, and `customers.country` is live: a customer
     * who has since moved would have us write a country the AADE record never
     * carried, and the PDF, infolist and CMR would then all display that wrong value
     * as though it were the filed one. A filed note needs no country anyway (it is
     * never re-submitted; a cancellation carries no counterpart), so leaving it null
     * is both honest and harmless. Recovering the true historical value means
     * reading `delivery_marks.request` — a separate job, not a migration's guess.
     */
    private function backfillFromCustomers(): void
    {
        DB::table('delivery_notes')
            ->select('delivery_notes.id', 'customers.country')
            ->join('customers', 'customers.id', '=', 'delivery_notes.customer_id')
            ->whereNull('delivery_notes.recipient_country')
            // Same predicate as DeliveryNote::hasBeenFiled() — both submit paths
            // write the flag and the MARK together, and a rejected-then-repaired
            // note can carry the flag alone. `mydata_sent` is NULLABLE with no
            // default, so a bare `where(…, false)` would exclude every never-sent
            // row (SQL NULL != false) and backfill nothing.
            ->where(fn ($q) => $q->whereNull('delivery_notes.mydata_sent')
                ->orWhere('delivery_notes.mydata_sent', false))
            ->whereNull('delivery_notes.mydata_mark')
            // …and only where the recipient IS that customer. `customer_id` is a
            // Hidden the form never clears, so a note can name a DIFFERENT party
            // over a stale customer link — freezing the customer's country there
            // would write a foreign recipient as GR and make it permanently
            // invisible (the snapshot then wins over every later repair). Mirrors
            // DeliveryNote::recipientIsTheLinkedCustomer(); the sentinel and a blank
            // both read as "no other party was typed".
            ->where(function ($q): void {
                $q->whereNull('delivery_notes.recipient_afm')
                    ->orWhere('delivery_notes.recipient_afm', '')
                    ->orWhere('delivery_notes.recipient_afm', '000000000')
                    ->orWhereColumn('delivery_notes.recipient_afm', 'customers.afm');
            })
            ->where(function ($q): void {
                $q->whereNull('delivery_notes.recipient_name')
                    ->orWhere('delivery_notes.recipient_name', '')
                    ->orWhereColumn('delivery_notes.recipient_name', 'customers.name');
            })
            ->whereNotNull('customers.country')
            // chunkById, NOT chunk(): chunk() pages by OFFSET, and this loop writes
            // the very column the whereNull filters on — so each page would shrink
            // the result set under the next offset and silently skip rows.
            ->chunkById(500, function ($rows): void {
                // One UPDATE per distinct ISO code instead of one per row.
                $byIso = [];
                foreach ($rows as $row) {
                    $iso = IsoCountry::tryNormalise($row->country);
                    if ($iso !== null) {
                        $byIso[$iso][] = $row->id;
                    }
                }

                foreach ($byIso as $iso => $ids) {
                    DB::table('delivery_notes')
                        ->whereIn('id', $ids)
                        ->update(['recipient_country' => $iso]);
                }
            }, 'delivery_notes.id', 'id');
    }
};
