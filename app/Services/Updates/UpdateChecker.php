<?php

namespace App\Services\Updates;

use App\Support\BuildInfo;
use App\Support\Settings\SystemSettings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * READ-ONLY «is this box up to date?» check (Phase 1). Compares the deployed
 * build (BuildInfo) against the repo's latest GitHub release/tag and reports
 * whether an update is available + how many commits behind. It NEVER downloads
 * or applies anything — the actual upgrade stays with deploy/update.sh, whose
 * snapshot → migrate → queue:restart → ops:health choreography a naive in-app
 * file-swap can't safely reproduce for a multi-tenant money app.
 *
 * Every result is cached (default 6h) so the panel doesn't hammer the API, and
 * every failure degrades gracefully: on a network/API error we fall back to the
 * last good result (flagged stale) or a plain «could not check» — never an
 * exception into the UI, and the current build is always reported regardless.
 */
class UpdateChecker
{
    private const CACHE_KEY = 'ekdosi.updates.status';

    private const API = 'https://api.github.com';

    public function __construct(private readonly BuildInfo $build) {}

    /** Live-toggle aware: the «Ρυθμίσεις συστήματος» override wins over the env/config default. */
    private function updatesEnabled(): bool
    {
        return app(SystemSettings::class)->bool('system.update_check_enabled', (bool) config('ekdosi.updates.enabled', true));
    }

    /**
     * @return array<string, mixed> the update status (see fetch()/base())
     */
    public function check(bool $fresh = false): array
    {
        if (! $this->updatesEnabled()) {
            return $this->base(['enabled' => false, 'error' => 'Ο έλεγχος ενημερώσεων είναι απενεργοποιημένος.']);
        }

        if (! $fresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $status = $this->fetch();

        // Only cache a SUCCESSFUL check — an outage must not pin a bad result for 6h.
        if (($status['ok'] ?? false) === true) {
            Cache::put(self::CACHE_KEY, $status, now()->addHours((int) config('ekdosi.updates.cache_hours', 6)));
        }

        return $status;
    }

    /**
     * NON-BLOCKING read for page mounts: return the last cached result WITHOUT ever
     * hitting the network, so opening the System page never hangs on a cold cache
     * (a synchronous GitHub call inside mount() could block render for `timeout`).
     * The «Έλεγχος ενημερώσεων» button calls check(fresh: true) to actually fetch.
     *
     * @return array<string, mixed>
     */
    public function cached(): array
    {
        if (! $this->updatesEnabled()) {
            return $this->base(['enabled' => false, 'error' => 'Ο έλεγχος ενημερώσεων είναι απενεργοποιημένος.']);
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        return $this->base(['error' => 'Δεν έχει γίνει έλεγχος ακόμη — πάτησε «Έλεγχος ενημερώσεων».']);
    }

    /** @return array<string, mixed> */
    private function fetch(): array
    {
        $repo = trim((string) config('ekdosi.updates.repo', ''));
        if ($repo === '') {
            return $this->base(['error' => 'Δεν έχει οριστεί αποθετήριο (EKDOSI_UPDATE_REPO).']);
        }

        try {
            $latest = $this->latestRelease($repo) ?? $this->latestTag($repo);
        } catch (Throwable $e) {
            return $this->degraded('Αποτυχία επικοινωνίας με το GitHub: '.$e->getMessage());
        }

        if ($latest === null) {
            return $this->base(['error' => 'Δεν βρέθηκε δημοσιευμένη έκδοση/tag στο αποθετήριο.']);
        }

        $current = $this->build->version();
        $latestVersion = ltrim($latest['tag'], 'vV');
        $updateAvailable = version_compare($latestVersion, ltrim($current, 'vV'), '>');

        // Opportunistic «N commits behind» — only when we know our commit sha and
        // the compare call succeeds. Never fatal; null just hides the count.
        $behind = null;
        if ($sha = $this->build->sha()) {
            try {
                $behind = $this->commitsBehind($repo, $sha, $latest['tag']);
            } catch (Throwable) {
                $behind = null;
            }
        }

        return $this->base([
            'ok' => true,
            'error' => null,
            'latest_version' => $latest['tag'],
            'update_available' => $updateAvailable,
            'commits_behind' => $behind,
            'published_at' => $latest['published_at'],
            'url' => $latest['url'],
        ]);
    }

    /**
     * Latest published GitHub Release (the primary signal — cut via the git tag
     * that ekdosi:release prints). Returns null on 404 (no releases yet) so the
     * caller falls back to raw tags.
     *
     * @return array{tag: string, url: ?string, published_at: ?string}|null
     */
    private function latestRelease(string $repo): ?array
    {
        $res = $this->request(self::API."/repos/{$repo}/releases/latest");
        if ($res === null || $res->status() === 404) {
            return null;
        }
        $res->throw();
        $body = $res->json();

        return [
            'tag' => (string) ($body['tag_name'] ?? ''),
            'url' => $body['html_url'] ?? null,
            'published_at' => $body['published_at'] ?? null,
        ];
    }

    /**
     * Fallback when the repo tags but doesn't cut Releases: pick the highest SemVer
     * among the tags (the API doesn't sort them semantically, so we sort ourselves).
     *
     * @return array{tag: string, url: ?string, published_at: ?string}|null
     */
    private function latestTag(string $repo): ?array
    {
        $res = $this->request(self::API."/repos/{$repo}/tags?per_page=100");
        if ($res === null) {
            return null;
        }
        $res->throw();
        $tags = collect($res->json())
            ->pluck('name')
            ->filter(fn ($n) => is_string($n) && $n !== '')
            ->values();

        if ($tags->isEmpty()) {
            return null;
        }

        $highest = $tags->sort(fn ($a, $b) => version_compare(ltrim($a, 'vV'), ltrim($b, 'vV')))->last();

        return [
            'tag' => (string) $highest,
            'url' => 'https://github.com/'.$repo.'/releases/tag/'.$highest,
            'published_at' => null,
        ];
    }

    /**
     * How many commits the deployed sha is behind the latest tag, via the compare
     * API. base = our sha, head = latest tag → GitHub's `ahead_by` is the count of
     * commits HEAD has that BASE doesn't = exactly how many we're behind. Returns
     * null when the compare can't be resolved (unknown sha, shallow clone, 404).
     */
    private function commitsBehind(string $repo, string $base, string $head): ?int
    {
        $res = $this->request(self::API."/repos/{$repo}/compare/{$base}...{$head}");
        if ($res === null || ! $res->successful()) {
            return null;
        }
        $aheadBy = $res->json('ahead_by');

        return is_int($aheadBy) ? $aheadBy : null;
    }

    private function request(string $url): ?Response
    {
        $token = config('ekdosi.updates.token');
        $http = Http::withHeaders([
            'User-Agent' => 'ekdosi-update-check',
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->timeout((int) config('ekdosi.updates.timeout', 8));

        if (is_string($token) && $token !== '') {
            $http = $http->withToken($token);
        }

        return $http->get($url);
    }

    /** @return array<string, mixed> */
    private function degraded(string $error): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return array_merge($cached, ['ok' => false, 'stale' => true, 'error' => $error]);
        }

        return $this->base(['error' => $error]);
    }

    /**
     * The status envelope with the current build always populated, plus sensible
     * defaults callers can rely on being present.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function base(array $overrides = []): array
    {
        return array_merge([
            'ok' => false,
            'enabled' => $this->updatesEnabled(),
            'stale' => false,
            'error' => null,
            'current_version' => $this->build->version(),
            'current_build' => $this->build->buildStamp(),
            'current_sha' => $this->build->sha(),
            'latest_version' => null,
            'update_available' => false,
            'commits_behind' => null,
            'published_at' => null,
            'url' => null,
            'checked_at' => now()->toIso8601String(),
        ], $overrides);
    }
}
