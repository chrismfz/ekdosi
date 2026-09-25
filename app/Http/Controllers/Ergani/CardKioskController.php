<?php

namespace App\Http\Controllers\Ergani;

use App\Filament\Pages\WorkCard;
use App\Http\Controllers\Controller;
use App\Models\WorkCardKioskDevice;
use App\Services\Ergani\WorkCardRefused;
use App\Services\Ergani\WorkCardService;
use App\Support\MyData\QrImage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The office tablet — no login by design (a wall tablet must never hold a user
 * session). The DEVICE is bound instead: an admin activates it once from
 * «Σημείο κάρτας» (WorkCardKioskDevice: named, listable, revocable per device),
 * which sets an httpOnly cookie with the device's random token; the URL carries
 * nothing.
 *
 * It shows the presence board (who is in, since when) + a PIN pad «ρολόι»
 * (name → PIN → punch, source «kiosk») and the rotating QR for phone users.
 */
class CardKioskController extends Controller
{
    public const COOKIE = 'ergani_kiosk';

    public function show(Request $request, WorkCardService $cards): View
    {
        $device = $this->device($request);
        if ($device === null) {
            return view('ergani.card-kiosk', ['company' => null]);
        }
        $company = $device->company;
        $this->touch($device, $request);

        // ROLLING lifetime: browsers cap cookies at ~400 days (Chrome), so re-issue
        // on every view — a tablet in use never expires; one unused for a year
        // needs re-activation.
        Cookie::queue(self::deviceCookie((string) $request->cookie(self::COOKIE), $request->isSecure()));

        $url = WorkCard::getUrl(['k' => $cards->kioskToken($company)], panel: 'admin', tenant: $company);

        return view('ergani.card-kiosk', [
            'company' => $company->name,
            'qr' => QrImage::dataUri($url, 360),
            'board' => $cards->presence($company),
            'refresh' => max(5, intdiv(WorkCardService::KIOSK_WINDOW, 2)),
        ]);
    }

    public function punch(Request $request, WorkCardService $cards): JsonResponse
    {
        $device = $this->device($request);
        if ($device === null) {
            return response()->json(['ok' => false, 'message' => 'Η συσκευή δεν είναι ενεργοποιημένη.'], 403);
        }
        $company = $device->company;
        $this->touch($device, $request);

        $data = $request->validate([
            'employee' => ['required', 'integer'],
            'pin' => ['required', 'string', 'max:6'],
            'seen' => ['nullable', 'in:in,out'],
        ]);

        try {
            $event = $cards->punchWithPin($device, (int) $data['employee'], (string) $data['pin'], $data['seen'] ?? null);
        } catch (WorkCardRefused $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'Αβέβαιο αποτέλεσμα — η κίνηση ίσως καταγράφηκε. Ενημερώστε τον διαχειριστή πριν ξαναχτυπήσετε.'], 500);
        }

        $ergani = match ($event->ergani_status) {
            'submitted' => ' · ΕΡΓΑΝΗ ✓',
            'failed', 'unknown' => ' · ΕΡΓΑΝΗ: πρόβλημα — ενημερώθηκε ο διαχειριστής',
            default => '',
        };

        return response()->json([
            'ok' => true,
            'message' => $event->employee?->full_name.': '.$event->typeLabel().' '.$event->occurred_at->format('H:i').' ✓'.$ergani,
        ]);
    }

    /** The device-binding cookie (httpOnly, 400 days — the browser maximum). */
    public static function deviceCookie(string $secret, bool $secure): \Symfony\Component\HttpFoundation\Cookie
    {
        return Cookie::make(self::COOKIE, $secret, 60 * 24 * 400, '/', null, $secure, true, false, 'lax');
    }

    private function device(Request $request): ?WorkCardKioskDevice
    {
        return WorkCardKioskDevice::forToken((string) $request->cookie(self::COOKIE));
    }

    /** «Last seen» for the admin's device list (at most once a minute). */
    private function touch(WorkCardKioskDevice $device, Request $request): void
    {
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill([
                'last_seen_at' => now(),
                'last_ip' => $request->ip(),
                'last_user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ])->saveQuietly();
        }
    }
}
