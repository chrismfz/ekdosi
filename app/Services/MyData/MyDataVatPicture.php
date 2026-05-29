<?php

namespace App\Services\MyData;

/**
 * "Εικόνα από myDATA" — the VAT position computed from the ACTUAL documents
 * AADE holds for a period: output (εκροές, our RequestTransmittedDocs sales)
 * vs input (εισροές, the RequestDocs filed against us), summed. This is the
 * authoritative-source twin of the LOCAL VatPeriodReport.
 *
 * `fetchedAt` is when the snapshot was pulled (the widget shows "ενημερώθηκε…")
 * — the picture is cached and refreshed by a scheduler, never fetched live on
 * a dashboard load.
 *
 * NOTE: `inputVat` is the SUM of VAT on expense docs, not the deductible Φ2
 * figure — a close estimate of "ΦΠΑ που γλιτώνω", not the official return.
 */
final readonly class MyDataVatPicture
{
    public function __construct(
        public float $outputNet = 0,
        public float $outputVat = 0,
        public float $outputGross = 0,
        public int $outputCount = 0,
        public float $inputNet = 0,
        public float $inputVat = 0,
        public float $inputGross = 0,
        public int $inputCount = 0,
        public ?string $fetchedAt = null,   // ISO-8601 of the snapshot
    ) {}

    public function netVat(): float
    {
        return round($this->outputVat - $this->inputVat, 2);
    }

    public function isPayable(): bool
    {
        return $this->netVat() > 0.005;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outputNet' => $this->outputNet,
            'outputVat' => $this->outputVat,
            'outputGross' => $this->outputGross,
            'outputCount' => $this->outputCount,
            'inputNet' => $this->inputNet,
            'inputVat' => $this->inputVat,
            'inputGross' => $this->inputGross,
            'inputCount' => $this->inputCount,
            'fetchedAt' => $this->fetchedAt,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            outputNet: (float) ($data['outputNet'] ?? 0),
            outputVat: (float) ($data['outputVat'] ?? 0),
            outputGross: (float) ($data['outputGross'] ?? 0),
            outputCount: (int) ($data['outputCount'] ?? 0),
            inputNet: (float) ($data['inputNet'] ?? 0),
            inputVat: (float) ($data['inputVat'] ?? 0),
            inputGross: (float) ($data['inputGross'] ?? 0),
            inputCount: (int) ($data['inputCount'] ?? 0),
            fetchedAt: $data['fetchedAt'] ?? null,
        );
    }
}
