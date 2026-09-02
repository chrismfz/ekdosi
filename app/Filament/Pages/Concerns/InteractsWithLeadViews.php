<?php

namespace App\Filament\Pages\Concerns;

use App\Models\Company;
use App\Models\Lead;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * What the leads views (kanban board, calendar) share: the operator picker,
 * the «may this operator move leads» gate, the tenant-scoped lookup of a
 * lead a drop may change, and the refusal notice.
 */
trait InteractsWithLeadViews
{
    /** Operator filter: '' = everyone, 'me', or a user id (see Lead::scopeForOperator). */
    public string $operator = '';

    /** @var array<int, string>|null */
    private ?array $operatorOptions = null;

    public function canMove(): bool
    {
        return Gate::allows('Update:Lead');
    }

    /** @return array<int, string> the tenant's users */
    public function getOperatorOptions(): array
    {
        if ($this->operatorOptions !== null) {
            return $this->operatorOptions;
        }

        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $this->operatorOptions = $tenant->users()->orderBy('name')->pluck('users.name', 'users.id')->all();
    }

    /**
     * The lead a drop may change: this tenant's, open, not trashed — and the
     * operator may update leads. Anything else is refused with a notice.
     */
    protected function movableLead(int $leadId): ?Lead
    {
        if (! $this->canMove()) {
            $this->fail('Δεν έχεις δικαίωμα να αλλάζεις leads.');

            return null;
        }

        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        $lead = Lead::query()->where('company_id', $tenant->id)->whereKey($leadId)->first();

        if ($lead === null || ! $lead->isOpen()) {
            $this->fail('Το lead δεν είναι ανοιχτό (ή δεν υπάρχει πια).');

            return null;
        }

        return $lead;
    }

    protected function fail(string $message): void
    {
        Notification::make()->title($message)->danger()->send();
    }
}
