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
        if ($feed === null || $feed->company === null || $feed->user === null
            || ! $feed->company->users()->whereKey($feed->user_id)->exists()) {
            abort(404);
        }

        $feed->forceFill(['last_accessed_at' => now()])->saveQuietly();

        return response(app(CalendarFeedBuilder::class)->build($feed), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="ekdosi.ics"',
            'Cache-Control' => 'private, max-age=900',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
