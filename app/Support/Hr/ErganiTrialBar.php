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

    public static function html(): string
    {
        $c = Filament::getTenant();
        if (! $c instanceof Company || ! $c->hasErgani() || $c->ergani_mode === 'production') {
            return '';
        }
        if (! $c->ergani_submit_leaves && ! WorkCardService::enabledFor($c) && ! OvertimeService::enabledFor($c)) {
            return '';
        }

        return '<div role="status" style="margin-bottom:1rem;padding:.6rem .9rem;border-radius:.6rem;background:#fef3c7;color:#92400e;'
            .'border:1px solid #fcd34d;font-size:.9rem">'
            .'<strong>ΔΟΚΙΜΑΣΤΙΚΟ ΕΡΓΑΝΗ</strong> — οι δηλώσεις από εδώ πάνε στο δοκιμαστικό περιβάλλον και '
            .'<strong>δεν έχουν νομική ισχύ</strong>. Ο λογιστής συνεχίζει να δηλώνει κανονικά.</div>';
    }
}
