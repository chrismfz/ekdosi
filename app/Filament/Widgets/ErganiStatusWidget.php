<?php

namespace App\Filament\Widgets;

use App\Enums\LeaveStatus;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\OvertimeDeclarations\OvertimeDeclarationResource;
use App\Filament\Resources\WorkCardEvents\WorkCardEventResource;
use App\Models\Company;
use App\Models\ErganiSubmission;
use App\Models\LeaveRequest;
use App\Models\OvertimeDeclaration;
use App\Models\WorkCardEvent;
use App\Services\Ergani\LeaveErganiSubmitter;
use App\Services\Ergani\OvertimeService;
use App\Services\Ergani\WorkCardService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * «ΕΡΓΑΝΗ» on the dashboard (approvers/admins): environment + what is auto-
 * declared, last successful declaration, the card-sector watch, and — the
 * point — what NEEDS ATTENTION (failed/uncertain declarations, an approved
 * leave starting soon without a declaration), plus today's/tomorrow's overtime.
 * Every line links to the list where it is fixed; nothing here writes.
 */
class ErganiStatusWidget extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.ergani-status';

    public static function canView(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->hasErgani()
            && (bool) auth()->user()?->can('Update:LeaveRequest');
    }

    protected function getViewData(): array
    {
        /** @var Company $c */
        $c = Filament::getTenant();
        $id = (int) $c->getKey();
        $today = CarbonImmutable::today();   // immutable: the addDays() below must not move «today»
        $stale = now()->subMinutes(LeaveErganiSubmitter::CLAIM_STALE_MINUTES);
        $uncertain = fn ($q) => $q->whereIn('ergani_status', ['failed', 'unknown'])
            ->orWhere(fn ($q) => $q->where('ergani_status', 'submitting')->where('updated_at', '<', $stale));

        $attention = [];
        if (LeaveRequestResource::canAccess()) {
            $n = LeaveRequest::query()->where('company_id', $id)
                ->where(fn ($q) => $q->whereIn('ergani_status', ['failed', 'unknown', 'cancel_failed'])
                    ->orWhere(fn ($q) => $q->whereIn('ergani_status', ['submitting', 'cancelling'])->where('updated_at', '<', $stale)))
                ->count();
            if ($n > 0) {
                $attention[] = ['text' => $n.' άδει'.($n === 1 ? 'α' : 'ες').' με αποτυχημένη ή αβέβαιη δήλωση/ανάκληση', 'url' => LeaveRequestResource::getUrl('index')];
            }
            if ($c->ergani_submit_leaves) {
                $soon = LeaveRequest::query()->where('company_id', $id)
                    ->where('status', LeaveStatus::Approved->value)
                    ->whereBetween('starts_on', [$today->toDateString(), $today->addDays(2)->toDateString()])
                    ->where(fn ($q) => $q->whereNull('ergani_status')->orWhere('ergani_status', 'failed'))
                    ->count();
                if ($soon > 0) {
                    $attention[] = ['text' => $soon.' εγκεκριμένη/ες άδεια/ες ξεκινά/ούν έως μεθαύριο ΧΩΡΙΣ δήλωση', 'url' => LeaveRequestResource::getUrl('index')];
                }
            }
        }
        if (OvertimeDeclarationResource::canAccess()) {
            $n = OvertimeDeclaration::query()->where('company_id', $id)
                ->where('work_date', '>=', $today->toDateString())->where($uncertain)->count();
            if ($n > 0) {
                $attention[] = ['text' => $n.' επερχόμενη/ες υπερωρία/ες χωρίς επιβεβαιωμένη δήλωση', 'url' => OvertimeDeclarationResource::getUrl('index')];
            }
        }
        if (WorkCardEventResource::canAccess()) {
            $n = WorkCardEvent::query()->where('company_id', $id)
                ->where('occurred_at', '>=', now()->subDays(7))->where($uncertain)->count();
            if ($n > 0) {
                $attention[] = ['text' => $n.' κίνηση/εις κάρτας (7 ημ.) χωρίς επιβεβαιωμένη δήλωση', 'url' => WorkCardEventResource::getUrl('index')];
            }
        }

        $overtime = OvertimeDeclaration::query()->where('company_id', $id)
            ->whereBetween('work_date', [$today->toDateString(), $today->addDay()->toDateString()])
            ->where(fn ($q) => $q->whereNull('ergani_status')->orWhere('ergani_status', '!=', 'superseded'))
            ->with('employee')->orderBy('work_date')->orderBy('from_time')->get()
            ->map(fn (OvertimeDeclaration $o): string => ($o->work_date->isToday() ? 'Σήμερα' : 'Αύριο').' '.$o->from_time.'–'.$o->to_time
                .' · '.$o->employee?->full_name.' — '.OvertimeDeclarationResource::erganiLabel($o))
            ->all();

        $last = ErganiSubmission::query()->where('company_id', $id)->where('ok', true)->latest('id')->first();

        return [
            'production' => $c->ergani_mode === 'production',
            'auto' => array_keys(array_filter([
                'άδειες' => (bool) $c->ergani_submit_leaves,
                'κάρτα' => WorkCardService::enabledFor($c),
                'υπερωρίες' => OvertimeService::enabledFor($c),
            ])),
            'cardSector' => $c->ergani_card_sector,
            'cardCheckedAt' => $c->ergani_card_sector_checked_at,
            'last' => $last ? $last->document.' · '.$last->created_at?->format('d/m H:i').($last->environment === 'production' ? '' : ' (δοκιμαστικό)') : null,
            'attention' => $attention,
            'overtime' => $overtime,
        ];
    }
}
