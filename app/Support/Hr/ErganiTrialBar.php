<?php

namespace App\Support\Hr;

use App\Filament\Pages\WorkCard;
use App\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\LeaveRequests\Pages\ViewLeaveRequest;
use App\Filament\Resources\OvertimeDeclarations\Pages\ListOvertimeDeclarations;
use App\Filament\Resources\WorkCardEvents\Pages\ListWorkCardEvents;
use App\Models\Company;
use App\Services\Ergani\OvertimeService;
use App\Services\Ergani\WorkCardService;
use Filament\Facades\Filament;

/**
 * The «ΔΟΚΙΜΑΣΤΙΚΟ ΕΡΓΑΝΗ» bar (a panel render hook on the declaring screens):
 * shown only while the tenant is in the TRIAL environment AND something is
 * auto-declared — so a trial protocol is never mistaken for a real declaration.
 * Inline styles: the panel has no Tailwind utility layer.
 */
final class ErganiTrialBar
{
    /** @var list<class-string> */
    public const PAGES = [
        ListLeaveRequests::class, CreateLeaveRequest::class, ViewLeaveRequest::class,
        ListOvertimeDeclarations::class, ListWorkCardEvents::class, WorkCard::class,
    ];

    /** @param  list<string>  $scopes  the rendering page's classes (Filament passes them to the hook) */
    public static function html(array $scopes = []): string
    {
        $c = Filament::getTenant();
        if (! $c instanceof Company || ! $c->hasErgani() || $c->ergani_mode === 'production') {
            return '';
        }
        // Only where THIS screen actually declares something in the trial.
        $declares = match (true) {
            in_array(ListOvertimeDeclarations::class, $scopes, true) => OvertimeService::enabledFor($c),
            in_array(ListWorkCardEvents::class, $scopes, true), in_array(WorkCard::class, $scopes, true) => WorkCardService::enabledFor($c),
            default => (bool) $c->ergani_submit_leaves,   // the leave screens
        };
        if (! $declares) {
            return '';
        }

        return '<div role="status" style="margin-bottom:1rem;padding:.6rem .9rem;border-radius:.6rem;background:#fef3c7;color:#92400e;'
            .'border:1px solid #fcd34d;font-size:.9rem">'
            .'<strong>ΔΟΚΙΜΑΣΤΙΚΟ ΕΡΓΑΝΗ</strong> — οι δηλώσεις από εδώ πάνε στο δοκιμαστικό περιβάλλον και '
            .'<strong>δεν έχουν νομική ισχύ</strong>. Ο λογιστής συνεχίζει να δηλώνει κανονικά.</div>';
    }
}
