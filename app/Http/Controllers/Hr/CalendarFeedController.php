<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\CalendarFeed;
use App\Services\Hr\CalendarFeedBuilder;
use Illuminate\Http\Response;

/**
 * GET /calendar/{token}.ics — the read-only subscription a calendar app polls
 * (it can't log in). An unknown/revoked token, or a user no longer in that
 * company, gets a plain 404 (never says which). Rights are re-checked by the
 * builder on every fetch, so a demoted user's feed shrinks immediately.
 */
class CalendarFeedController extends Controller
{
    public function show(string $token): Response
    {
        $feed = CalendarFeed::forToken($token);
        if ($feed === null || $feed->company === null || $feed->user === null) {
            abort(404);
        }
        if (! $feed->company->users()->whereKey($feed->user_id)->exists()) {
            // Removed from the company: the link dies for good — a re-added user gets
            // a NEW link, never the old (possibly leaked) one back.
            $feed->delete();
            abort(404);
        }

        if ($feed->last_accessed_at === null || $feed->last_accessed_at->lt(now()->subHour())) {
            $feed->forceFill(['last_accessed_at' => now()])->saveQuietly();
        }

        return response(app(CalendarFeedBuilder::class)->build($feed), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="ekdosi.ics"',
            'Cache-Control' => 'private, max-age=900',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
