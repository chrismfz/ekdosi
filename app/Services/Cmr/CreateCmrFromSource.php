<?php

namespace App\Services\Cmr;

use App\Models\CmrNote;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Support\IsoCountry;
use App\Support\TransliterateGreek;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «Δημιουργία CMR» from one of our documents — the Προσφορά→Παραστατικό analogue
 * for transport: take an Invoice or a Δελτίο Αποστολής, transliterate its Greek
 * party/goods text to Latin, and stage an editable DRAFT CmrNote (+ lines). The
 * operator then corrects the English and prints. Never auto-finalised.
 *
 * Standalone CMRs don't come through here — they're created blank in CmrResource.
 */
class CreateCmrFromSource
{
    public function fromInvoice(Invoice $invoice): CmrNote
    {
        $invoice->loadMissing(['lines', 'company', 'customer']);
        $company = $invoice->company;
        if (! $company instanceof Company) {
            throw new RuntimeException('Το παραστατικό δεν έχει εταιρεία.');
        }

        return DB::transaction(function () use ($invoice, $company) {
            // The CMR is a PAPER consignment note, not an AADE payload: prefer the
            // normalised ISO-2 code, but keep the raw text when it doesn't resolve —
            // dropping the country line outright is worse than printing 'ΙΤΑΛΙΑ'.
            // `invoices.country` is nullable and blank on ETL-imported rows, so the
            // customer is a display-only last resort: this is a draft the operator
            // edits before printing, and a consignment note with NO country line at
            // all is worse than a stale one. Never used for a filed payload.
            $rawCountry = $invoice->country ?: $invoice->customer?->country;
            $country = IsoCountry::tryNormalise($rawCountry) ?: $rawCountry;

            $cmr = $this->makeDraft($company, $invoice, [
                'customer_id' => $invoice->customer_id,
                'reference_no' => $invoice->invcode,
                'consignee_text' => $this->party(
                    $invoice->company_name ?: $invoice->customer?->name,
                    $invoice->address1, $invoice->address2, $invoice->city, $invoice->postcode,
                    $country, $invoice->vat_no,
                ),
                'delivery_text' => $this->address(
                    $invoice->address1, $invoice->address2, $invoice->city, $invoice->postcode,
                    $country,
                ),
            ]);

            foreach ($invoice->lines as $line) {
                $cmr->lines()->create($this->lineFrom($company->id, $line->product_descr, $line->qty));
            }

            return $cmr->refresh();
        });
    }

    public function fromDeliveryNote(DeliveryNote $note): CmrNote
    {
        $note->loadMissing(['lines', 'company', 'customer']);
        $company = $note->company;
        if (! $company instanceof Company) {
            throw new RuntimeException('Το δελτίο δεν έχει εταιρεία.');
        }

        return DB::transaction(function () use ($note, $company) {
            // MYD-011: the consignee is resolved through the SAME shared helpers the
            // AADE payload uses — name, ΑΦΜ and country together. Reading the raw
            // columns made the paper note disagree with the filing in both
            // directions: customer?->country printed GR for a foreign consignee, a
            // stale recipient_country printed «DE» on an ενδοδιακίνηση filed as GR,
            // and the raw recipient_afm hid a customer's ΑΦΜ that WAS submitted.
            $internal = $note->isInternalMovement();

            // Paper, not a payload: an unresolvable value prints raw rather than
            // vanishing, and unlike recipientCountryIso() the customer fallback is
            // NOT cut off once the note is filed — a stale country on an editable
            // draft beats a blank line on a consignment note that crosses a border.
            $rawCountry = $note->recipient_country ?: $note->customer?->country;
            $country = $internal
                ? 'GR'                                   // recipient IS the issuer
                : ($note->recipientCountryIso() ?: $rawCountry);

            $consigneeName = $internal
                ? $company->name
                : ($note->recipient_name ?: $note->customer?->name);

            $consigneeAfm = $note->externalRecipientAfm() ?: ($internal ? $company->afm : null);

            $cmr = $this->makeDraft($company, $note, [
                'customer_id' => $note->customer_id,
                'reference_no' => $note->invcode,
                'consignee_text' => $this->party(
                    $consigneeName,
                    $note->delivery_street, $note->delivery_number, $note->delivery_city, $note->delivery_postcode,
                    $country, $consigneeAfm,
                ),
                'delivery_text' => $this->address(
                    $note->delivery_street, $note->delivery_number, $note->delivery_city, $note->delivery_postcode,
                    $country,
                ),
                'taking_over_place' => self::tr(trim(implode(' ', array_filter([
                    $note->loading_street, $note->loading_number, $note->loading_city, $note->loading_postcode,
                ])))),
                'taking_over_at' => $note->dispatch_at,
                'carrier_name' => $note->carrier_afm ? 'ΑΦΜ '.$note->carrier_afm : null,
                'tractor_plate' => $note->vehicle_number,
            ]);

            foreach ($note->lines as $line) {
                $cmr->lines()->create($this->lineFrom($company->id, $line->product_descr, $line->qty));
            }

            return $cmr->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeDraft(Company $company, Model $source, array $attributes): CmrNote
    {
        return CmrNote::create(array_merge([
            'company_id' => $company->id,
            'status' => CmrNote::STATUS_DRAFT,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'issued_at' => now(),
            'sender_text' => $this->senderFor($company),
            'established_place' => self::tr($company->city_en ?: $company->city),
            'established_on' => now()->toDateString(),
        ], $attributes));
    }

    private function senderFor(Company $company): string
    {
        // Prefer the company's official English identity; fall back to a
        // transliteration of the Greek fields (operator can still correct it).
        $name = $company->name_en ?: self::tr($company->name);
        $addr = $company->address_en ?: self::tr($company->address);
        $city = $company->city_en ?: self::tr($company->city);

        return $this->joinLines([
            $name,
            trim($addr.' '.$company->postcode),
            trim($city.' '.($company->country_code ?: 'GR')),
            $company->afm ? 'VAT: '.$company->afm : null,
        ]);
    }

    private function party(?string $name, ?string $a1, ?string $a2, ?string $city, ?string $postcode, ?string $country, ?string $vat): string
    {
        return $this->joinLines([
            self::tr($name),
            $this->address($a1, $a2, $city, $postcode, $country),
            $vat ? 'VAT: '.$vat : null,
        ]);
    }

    private function address(?string $a1, ?string $a2, ?string $city, ?string $postcode, ?string $country): string
    {
        $street = trim(implode(' ', array_filter([$a1, $a2])));

        return self::tr(trim(implode(', ', array_filter([
            $street,
            trim(implode(' ', array_filter([$postcode, $city]))),
            // NO 'GR' fallback: fabricating Greece on an INTERNATIONAL consignment
            // note is the MYD-011 misreport in print. An unresolvable country is
            // simply omitted rather than asserted (MYD-011 review).
            $country,
        ]))));
    }

    /**
     * @return array<string, mixed>
     */
    private function lineFrom(int $companyId, ?string $descr, mixed $qty): array
    {
        $packages = (is_numeric($qty) && (float) $qty == (int) $qty && (int) $qty > 0) ? (int) $qty : null;

        return [
            'company_id' => $companyId,
            'nature_en' => self::tr($descr),
            'packages_count' => $packages,
        ];
    }

    /**
     * @param  list<?string>  $parts
     */
    private function joinLines(array $parts): string
    {
        return implode("\n", array_filter(array_map('trim', array_filter($parts, fn ($p) => filled($p)))));
    }

    private static function tr(?string $text): string
    {
        return TransliterateGreek::toLatin($text);
    }
}
