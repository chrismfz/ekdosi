<?php

namespace App\Http\Controllers;

use App\Models\ProductMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a product photo/video to a SIGNED-IN operator of the media's company
 * (same posture as ExpenseDocumentDownloadController). Deliberately NOT public:
 * every guest/bot/marketplace fetch would land on ekdosi — the e-shop gets the
 * files through the WooCommerce bridge and serves them from its own CDN/cache
 * (docs/woocommerce-bridge-plan.md §6 Φάση 4).
 */
class ProductMediaController extends Controller
{
    public function __invoke(Request $request, int $media, string $variant): Response
    {
        $user = $request->user();
        abort_unless($user !== null, Response::HTTP_FORBIDDEN);

        $row = ProductMedia::query()->withoutGlobalScopes()->find($media);
        abort_if($row === null || $row->disk === null, Response::HTTP_NOT_FOUND);
        // Cross-tenant guard: only members of the media's company.
        abort_unless($user->companies()->whereKey($row->company_id)->exists(), Response::HTTP_FORBIDDEN);

        // OUTSIDE the panel: set Spatie's team id to the media's (membership-checked)
        // company for the check, else a non-super-admin's team-scoped role never
        // matches (see PosReceiptController). The till's tiles show these photos
        // too — a «Ταμίας» (View:PointOfSale) has no View:Product.
        $registrar = app(PermissionRegistrar::class);
        $priorTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($row->company_id);
        try {
            abort_unless($user->can('View:Product') || $user->can('View:PointOfSale'), Response::HTTP_FORBIDDEN);
        } finally {
            $registrar->setPermissionsTeamId($priorTeamId);
        }

        $path = $variant === 'thumb' ? ($row->thumb_path ?: $row->path) : $row->path;
        abort_if(! $path || ! Storage::disk($row->disk)->exists($path), Response::HTTP_NOT_FOUND);

        $headers = [
            'Content-Type' => $row->mime_type ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
            // Images are GD re-encoded; videos keep a video/* type — nosniff stops any
            // reinterpretation. (No CSP here: «default-src 'none'» would stop the
            // browser playing the very video/image opened in a new tab.)
            'X-Content-Type-Options' => 'nosniff',
        ];

        // Local disk → BinaryFileResponse (answers HTTP Range — needed to play/seek MP4).
        if (config("filesystems.disks.{$row->disk}.driver") === 'local') {
            $response = response()->file(Storage::disk($row->disk)->path($path), $headers);
            // BinaryFileResponse defaults to «public» — operator-only bytes must
            // never be stored by a shared proxy.
            $response->setPrivate();
            $response->setMaxAge(3600);

            return $response;
        }

        return Storage::disk($row->disk)->response($path, basename($path), $headers, 'inline');
    }
}
