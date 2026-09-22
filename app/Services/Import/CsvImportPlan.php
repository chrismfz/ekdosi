<?php

namespace App\Services\Import;

/** The dry-run result of an import: how the columns were read and what each row will do. */
final class CsvImportPlan
{
    /**
     * @param  array<string, string>  $mapping  field key => the CSV header it was read from
     * @param  list<string>  $ignored  CSV headers that match no field
     * @param  list<PlannedRow>  $rows
     */
    public function __construct(
        public readonly array $mapping,
        public readonly array $ignored,
        public readonly array $rows,
    ) {}

    public function count(string $action): int
    {
        return count(array_filter($this->rows, static fn (PlannedRow $r): bool => $r->action === $action));
    }

    /** Anything the import would actually write. */
    public function hasWork(): bool
    {
        return $this->count(PlannedRow::CREATE) + $this->count(PlannedRow::FILL) > 0;
    }

    /** @return list<PlannedRow> rows carrying an error or a warning, in file order */
    public function rowsWithNotes(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (PlannedRow $r): bool => $r->errors !== [] || $r->warnings !== [],
        ));
    }
}
