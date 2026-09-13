<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Combined ΤΔΑ (Τιμολόγιο–Δελτίο Αποστολής) — Slice 3a
 * (`docs/combined-tda-design.md` §5).
 *
 * A ΤΔΑ is a MONETARY invoice (myDATA type 1.1) that is ALSO a delivery note
 * (`isDeliveryNote=true`) carrying a movement header. So `invoices` gains the
 * same issue-time movement header + lifecycle-cache columns a `delivery_notes`
 * row has — money stays where it already is; only the movement is net-new.
 *
 * Mirrors `delivery_notes` column names for the shared MovableDocument contract
 * (3c), MINUS three deliberate omissions:
 *   - recipient point + qrUrl/state/mark — ALREADY on invoices (counterpart
 *     snapshot + counterpart_branch + mydata_url/mydata_state/mydata_mark);
 *   - `third_party_collection` — a PAYMENTS field the spec accepts only on 8.4/8.5
 *     (name-collision with the DN logistics flag), AADE-invalid on a 1.1 (§3);
 *   - `outcome_mark`/`reject_mark` — the recipient's/carrier's marks, NEVER written
 *     by the issuer flow (#531); the outcome/rejection surface in
 *     `delivery_note_events`, not an issuer cache column.
 *
 * All nullable; a plain (non-ΤΔΑ) invoice leaves them NULL/false. Reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            // Per-DOCUMENT flag (ERP spec note 13): a 1.1 that is also a ΔΑ.
            $t->boolean('is_delivery_note')->default(false)->after('mydata_url');

            // Issue-time movement header (InvoiceHeader, filed WITH the 1.1).
            $t->unsignedTinyInteger('move_purpose')->nullable()->after('is_delivery_note'); // §8.14 σκοπός
            $t->string('other_move_purpose_title', 120)->nullable()->after('move_purpose'); // when move_purpose=19
            $t->dateTime('dispatch_at')->nullable()->after('other_move_purpose_title');
            $t->string('vehicle_number', 40)->nullable()->after('dispatch_at');

            // Loading (issuer) + delivery (recipient) POINTS — street/number split
            // to match firebed's Address model (no lossy join). The recipient PARTY
            // is the existing counterpart snapshot; these are the movement ADDRESSES.
            $t->string('loading_street', 120)->nullable()->after('vehicle_number');
            $t->string('loading_number', 20)->nullable()->after('loading_street');
            $t->string('loading_postcode', 10)->nullable()->after('loading_number');
            $t->string('loading_city', 60)->nullable()->after('loading_postcode');
            $t->unsignedInteger('start_shipping_branch')->nullable()->after('loading_city');
            $t->string('delivery_street', 120)->nullable()->after('start_shipping_branch');
            $t->string('delivery_number', 20)->nullable()->after('delivery_street');
            $t->string('delivery_postcode', 10)->nullable()->after('delivery_number');
            $t->string('delivery_city', 60)->nullable()->after('delivery_postcode');
            $t->unsignedInteger('complete_shipping_branch')->nullable()->after('delivery_city');

            // Transport (RegisterTransfer, filed AFTER the 1.1, keyed by qrUrl).
            $t->unsignedTinyInteger('transport_type')->nullable()->after('complete_shipping_branch');
            $t->string('carrier_afm', 20)->nullable()->after('transport_type');

            // Lifecycle cache (written by the movement service via forceFill). Only the
            // ISSUER-written marks (#531): RegisterTransfer + ConfirmDeliveryReturn.
            $t->string('delivery_state', 30)->nullable()->after('carrier_afm'); // §8.22
            $t->string('transfer_mark', 50)->nullable()->after('delivery_state');
            $t->string('return_mark', 50)->nullable()->after('transfer_mark');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn([
                'is_delivery_note',
                'move_purpose', 'other_move_purpose_title', 'dispatch_at', 'vehicle_number',
                'loading_street', 'loading_number', 'loading_postcode', 'loading_city', 'start_shipping_branch',
                'delivery_street', 'delivery_number', 'delivery_postcode', 'delivery_city', 'complete_shipping_branch',
                'transport_type', 'carrier_afm',
                'delivery_state', 'transfer_mark', 'return_mark',
            ]);
        });
    }
};
