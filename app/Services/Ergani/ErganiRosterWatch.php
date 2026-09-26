<?php

namespace App\Services\Ergani;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Hr\LeaveWorkflow;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * «Φύλακας προσωπικού» — compares ΕΡΓΑΝΗ's current roster (EX_BASE_05, production,
 * read-only, via ErganiEmployeeImporter::fetch — minimal fields) with the local
 * Εργαζόμενοι, stores the differences on the company and rings the admins ONLY
 * when the picture changes. It never creates, edits or deactivates anyone.
 */
class ErganiRosterWatch
{
    public function __construct(private readonly ErganiEmployeeImporter $importer) {}

    /**
     * @return array{new: list<array{afm: string, name: string}>, inactive: list<array{afm: string, name: string}>, missing: list<array{id: int, name: string}>, no_afm: list<array{id: int, name: string}>}
     */
    public function diff(Company $company): array
    {
        $rows = collect($this->importer->fetch($company))->keyBy('afm');
        $local = Employee::query()->withTrashed()->where('company_id', $company->getKey())->get();
        if ($rows->isEmpty() && $local->contains(fn (Employee $e): bool => ! $e->trashed() && $e->is_active && filled($e->afm))) {
            // Maintenance / a glitch must never read as «everyone left — deactivate them».
            throw new \RuntimeException('Το ΕΡΓΑΝΗ επέστρεψε κενό δυναμικό ενώ υπάρχουν ενεργοί εργαζόμενοι — δεν αποθηκεύτηκε τίποτα. Αν αποχώρησαν όντως όλοι, ορίστε τους «Ενεργός: όχι».');
        }
        $byAfm = $local->filter(fn (Employee $e): bool => filled($e->afm))->keyBy('afm');

        $new = $inactive = [];
        foreach ($rows as $afm => $row) {
            $name = trim($row['last_name'].' '.$row['first_name']);
            $e = $byAfm->get($afm);
            if ($e === null) {
                $new[] = ['afm' => (string) $afm, 'name' => $name];
            } elseif ($e->trashed() || ! $e->is_active) {
                $inactive[] = ['afm' => (string) $afm, 'name' => $e->full_name];
            }
        }

        $active = $local->filter(fn (Employee $e): bool => ! $e->trashed() && $e->is_active);
        $missing = $active->filter(fn (Employee $e): bool => filled($e->afm) && ! $rows->has($e->afm))
            ->map(fn (Employee $e): array => ['id' => (int) $e->getKey(), 'name' => $e->full_name])->values()->all();
        // ΑΦΜ is optional without a work card — only card holders NEED one to be declared.
        $noAfm = $active->filter(fn (Employee $e): bool => blank($e->afm) && $e->has_work_card)
            ->map(fn (Employee $e): array => ['id' => (int) $e->getKey(), 'name' => $e->full_name])->values()->all();

        return ['new' => $new, 'inactive' => $inactive, 'missing' => $missing, 'no_afm' => $noAfm];
    }

    /** Run the check, store it, bell on a CHANGE. Returns the diff (for the command's output). */
    public function check(Company $company): array
    {
        $diff = $this->diff($company);
        $was = is_array($company->ergani_roster_diff) ? $company->ergani_roster_diff : null;

        // Bell only when a difference APPEARS (a shrinking list — e.g. some imported — is quiet),
        // and BEFORE storing: a failed bell is retried next week instead of being swallowed.
        if (array_diff(self::keys($diff), self::keys($was)) !== []) {
            $this->notify($company, $diff);
        }
        $company->forceFill(['ergani_roster_diff' => $diff, 'ergani_roster_checked_at' => now()])->saveQuietly();

        return $diff;
    }

    public static function count(?array $diff): int
    {
        return $diff === null ? 0 : count($diff['new'] ?? []) + count($diff['inactive'] ?? []) + count($diff['missing'] ?? []) + count($diff['no_afm'] ?? []);
    }

    /** Human lines (Greek) — the bell body and the dashboard card. @return list<string> */
    public static function lines(?array $diff): array
    {
        $names = fn (array $items): string => implode(', ', array_column($items, 'name'));

        return array_values(array_filter([
            ($diff['new'] ?? []) ? count($diff['new']).' νέος/οι στο ΕΡΓΑΝΗ που δεν υπάρχουν εδώ: '.$names($diff['new']).' → «Εισαγωγή από ΕΡΓΑΝΗ»' : null,
            ($diff['inactive'] ?? []) ? 'Ενεργοί στο ΕΡΓΑΝΗ αλλά ανενεργοί/διαγραμμένοι εδώ: '.$names($diff['inactive']) : null,
            ($diff['missing'] ?? []) ? 'Ενεργοί εδώ αλλά όχι στο ΕΡΓΑΝΗ: '.$names($diff['missing']).' — αποχώρησαν; ορίστε «Ενεργός: όχι»' : null,
            ($diff['no_afm'] ?? []) ? 'Με ψηφιακή κάρτα αλλά χωρίς ΑΦΜ (δεν δηλώνονται — συμπληρώστε τον): '.$names($diff['no_afm']) : null,
        ]));
    }

    /** One key per difference, bucket-qualified («new:123456789», «missing:7»). @return list<string> */
    private static function keys(?array $diff): array
    {
        if ($diff === null) {
            return [];
        }
        $keys = [];
        foreach (['new' => 'afm', 'inactive' => 'afm', 'missing' => 'id', 'no_afm' => 'id'] as $bucket => $field) {
            foreach ($diff[$bucket] ?? [] as $item) {
                $keys[] = $bucket.':'.$item[$field];
            }
        }

        return $keys;
    }

    private function notify(Company $company, array $diff): void
    {
        $recipients = LeaveWorkflow::usersWhoCan($company, 'Update:Employee');
        if ($recipients->isEmpty()) {
            return;
        }
        Notification::make()
            ->title('ΕΡΓΑΝΗ ↔ Εργαζόμενοι: '.self::count($diff).' διαφορά/ές')
            // Counts only: personal names don't belong in long-lived notification rows.
            ->body(implode(' · ', array_filter([
                ($n = count($diff['new'])) ? $n.' νέος/οι στο ΕΡΓΑΝΗ' : null,
                ($n = count($diff['inactive'])) ? $n.' ενεργός/οί εκεί αλλά ανενεργός/οί εδώ' : null,
                ($n = count($diff['missing'])) ? $n.' ενεργός/οί εδώ αλλά όχι στο ΕΡΓΑΝΗ' : null,
                ($n = count($diff['no_afm'])) ? $n.' με κάρτα χωρίς ΑΦΜ' : null,
            ])).'. Λεπτομέρειες στον Πίνακα ελέγχου (κάρτα ΕΡΓΑΝΗ).')
            ->icon('heroicon-o-user-group')
            ->warning()
            ->actions([
                Action::make('employees')->label('Εργαζόμενοι')
                    ->url(EmployeeResource::getUrl('index', tenant: $company))
                    ->markAsRead(),
            ])
            ->sendToDatabase($recipients);
    }
}
