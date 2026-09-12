<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * «Οι συνεδρίες μου» — the operator's own active browser sessions (device / IP /
 * last activity) with a per-session «Τερματισμός» and a password-confirmed
 * «Αποσύνδεση όλων των άλλων συσκευών». Self-service, so it lives in the user
 * menu (not the nav) and every authenticated operator sees ONLY their own.
 *
 * Guard scoping matters: the `sessions` table is shared by the web (/admin) and
 * portal (/user) guards and stores only a numeric user_id, so a CustomerUser
 * with the same id could otherwise surface here. Every read/delete is therefore
 * double-gated: user_id === the current WEB user AND the row's decoded payload
 * carries the web-guard login key. A portal session (or another user's) is never
 * shown, and never deletable from here.
 */
class MySessions extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected string $view = 'filament.pages.my-sessions';

    public static function shouldRegisterNavigation(): bool
    {
        return false; // reached from the user menu, not the sidebar
    }

    public static function canAccess(): bool
    {
        return Auth::guard('web')->check();
    }

    public function getTitle(): string
    {
        return 'Οι συνεδρίες μου';
    }

    /**
     * This operator's active WEB-guard sessions, newest activity first, the
     * current one flagged. Only the DB session driver populates the table; on
     * any other driver this is simply empty.
     *
     * @return list<array<string, mixed>>
     */
    public function sessions(): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        $userId = Auth::guard('web')->id();
        $currentId = session()->getId();

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->get()
            ->filter(fn ($row): bool => $this->isWebGuardSession($row->payload ?? null))
            ->map(fn ($row): array => [
                'id' => $row->id,
                'ip' => $row->ip_address ?: '—',
                'device' => $this->deviceLabel((string) ($row->user_agent ?? '')),
                'user_agent' => (string) ($row->user_agent ?? ''),
                'last_active' => Carbon::createFromTimestamp((int) $row->last_activity)->diffForHumans(),
                'is_current' => $row->id === $currentId,
            ])
            ->values()
            ->all();
    }

    /** Revoke ONE other session of this operator (never the current one). */
    public function revoke(string $id): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $userId = Auth::guard('web')->id();

        if ($id === session()->getId()) {
            return; // can't kill the session you're using here
        }

        $table = config('session.table', 'sessions');
        $row = DB::table($table)->where('id', $id)->where('user_id', $userId)->first();

        // Refuse anything that isn't this operator's own web-guard session.
        if ($row === null || ! $this->isWebGuardSession($row->payload ?? null)) {
            return;
        }

        DB::table($table)->where('id', $id)->where('user_id', $userId)->delete();

        Notification::make()->title('Η συνεδρία τερματίστηκε.')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('logout_others')
                ->label('Αποσύνδεση όλων των άλλων συσκευών')
                ->icon('heroicon-o-shield-exclamation')
                ->color('danger')
                ->visible(fn (): bool => config('session.driver') === 'database')
                ->requiresConfirmation()
                ->modalDescription('Θα αποσυνδεθούν όλες οι άλλες συσκευές/φυλλομετρητές εκτός από αυτόν. Επιβεβαίωσε με τον κωδικό σου.')
                ->schema([
                    TextInput::make('password')
                        ->label('Ο κωδικός σου')
                        ->password()
                        ->required()
                        ->rule('current_password:web'),
                ])
                ->action(function (array $data): void {
                    $userId = Auth::guard('web')->id();
                    $currentId = session()->getId();
                    $table = config('session.table', 'sessions');

                    // Authoritative: collect this operator's OTHER web-guard session
                    // ids (the guard check needs per-row payload decoding) and drop
                    // them in a single delete.
                    $ids = DB::table($table)
                        ->where('user_id', $userId)
                        ->where('id', '!=', $currentId)
                        ->get()
                        ->filter(fn ($row): bool => $this->isWebGuardSession($row->payload ?? null))
                        ->pluck('id')
                        ->all();

                    if ($ids !== []) {
                        DB::table($table)->whereIn('id', $ids)->where('user_id', $userId)->delete();
                    }

                    // Belt-and-suspenders: re-secure via the framework path so any
                    // row that survives a race is rejected by AuthenticateSession.
                    try {
                        Auth::guard('web')->logoutOtherDevices($data['password']);
                    } catch (\Throwable) {
                        // best-effort — the row deletion above already logged them out
                    }

                    Notification::make()->title('Αποσυνδέθηκαν οι άλλες συσκευές.')->success()->send();
                }),
        ];
    }

    /** Is this a session row belonging to the WEB guard (not the portal guard)? */
    private function isWebGuardSession(?string $payload): bool
    {
        $data = $this->decodeSessionPayload($payload);

        return $data !== null && array_key_exists($this->webLoginKey(), $data);
    }

    /**
     * Decode a session row's payload to its attribute array, honouring the
     * CONFIGURED session serialization. Laravel defaults this app to 'json'
     * (config/session.php), so decoding with unserialize() would fail on every
     * real row — the payload is base64(json_encode(...)) there, base64(serialize
     * (...)) only under the 'php' setting. Returns null when it can't decode.
     * `allowed_classes => false` neutralises object-injection on the php path.
     *
     * @return array<string, mixed>|null
     */
    private function decodeSessionPayload(?string $payload): ?array
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $raw = base64_decode($payload, true);
        if ($raw === false) {
            return null;
        }

        // With SESSION_ENCRYPT on, the stored payload is ciphertext — decrypt it
        // back to the serialized attribute string before decoding, or the guard
        // check would silently fail for every row.
        if (config('session.encrypt')) {
            try {
                $raw = Crypt::decrypt($raw, false);
            } catch (\Throwable) {
                return null;
            }
        }

        $data = config('session.serialization', 'php') === 'json'
            ? json_decode($raw, true)
            : @unserialize($raw, ['allowed_classes' => false]);

        return is_array($data) ? $data : null;
    }

    /** Laravel stores the logged-in user under this session key for the web guard. */
    private function webLoginKey(): string
    {
        return 'login_web_'.sha1(SessionGuard::class);
    }

    /** A short, friendly device label from a raw User-Agent (best-effort). */
    private function deviceLabel(string $ua): string
    {
        if ($ua === '') {
            return 'Άγνωστη συσκευή';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'OPR') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Φυλλομετρητής',
        };

        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return $os !== '' ? "{$browser} · {$os}" : $browser;
    }
}
