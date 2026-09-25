<?php

namespace App\Support\Hr;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Normalizer;

/**
 * Suggests which panel user IS an unlinked employee — by the same email, else
 * by the same name (either order, accents/case ignored). A suggestion is made
 * ONLY when it is unambiguous both ways: exactly one candidate user for the
 * employee, and no other unlinked employee claims the same user. The operator
 * always confirms — this never links on its own.
 */
class EmployeeAccountMatcher
{
    /** @var array<int, array<int, User>> per-company memo for one request */
    private array $memo = [];

    /** @return array<int, User> employee id → suggested user */
    public function suggestions(Company $company): array
    {
        return $this->memo[$company->getKey()] ??= $this->compute($company);
    }

    /** Drop the memo after a link changed who is free. */
    public function reset(): void
    {
        $this->memo = [];
    }

    public function suggestionFor(Employee $employee): ?User
    {
        if ($employee->user_id !== null || $employee->trashed()) {
            return null;
        }
        // Keyed by id so a table of N rows doesn't lazy-load the company N times.
        $companyId = (int) $employee->company_id;
        if (! isset($this->memo[$companyId])) {
            $company = Company::query()->find($companyId);
            $this->memo[$companyId] = $company ? $this->compute($company) : [];
        }

        return $this->memo[$companyId][$employee->getKey()] ?? null;
    }

    /** @return array<int, User> */
    private function compute(Company $company): array
    {
        $employees = Employee::query()->withTrashed()->where('company_id', $company->getKey())->get();
        // A user linked to a DELETED employee is still taken (unique company_id+user_id).
        $taken = $employees->pluck('user_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $employees = $employees->reject(fn (Employee $e): bool => $e->trashed());
        /** @var Collection<int, User> $users */
        $users = $company->users()->get()->reject(fn (User $u): bool => in_array((int) $u->getKey(), $taken, true))->values();

        $candidates = [];
        foreach ($employees->whereNull('user_id') as $employee) {
            $email = mb_strtolower(trim((string) $employee->email));
            $byEmail = $email !== '' ? $users->filter(fn (User $u): bool => mb_strtolower(trim((string) $u->email)) === $email) : collect();
            $matches = $byEmail->isNotEmpty() ? $byEmail : $users->filter(fn (User $u): bool => self::sameName($employee, (string) $u->name));
            if ($matches->count() === 1) {
                $candidates[$employee->getKey()] = $matches->first();
            }
        }

        // Two employees pointing at the same user → ambiguous, suggest neither.
        $counts = array_count_values(array_map(fn (User $u): int => (int) $u->getKey(), $candidates));

        return array_filter($candidates, fn (User $u): bool => $counts[(int) $u->getKey()] === 1);
    }

    private static function sameName(Employee $employee, string $userName): bool
    {
        $a = self::nameKey($employee->first_name.' '.$employee->last_name);

        return $a !== '' && $a === self::nameKey($userName);
    }

    /** «Δημήτρης Κωστόπουλος» == «ΚΩΣΤΟΠΟΥΛΟΣ δημητρης» (word order, case, accents ignored). */
    public static function nameKey(string $name): string
    {
        $plain = preg_replace('/\p{Mn}/u', '', Normalizer::normalize(mb_strtolower($name), Normalizer::FORM_D)) ?? '';
        $words = preg_split('/[^\p{L}\p{N}]+/u', str_replace('ς', 'σ', $plain), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($words);

        return implode(' ', $words);
    }
}
