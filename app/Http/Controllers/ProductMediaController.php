<?php

namespace App\Http\Controllers;

use App\Models\ProductMedia;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a product photo/video file. SIGNED, deliberately WITHOUT a login:
 * product photos are public by nature (they go on the e-shop) and WooCommerce
 * must fetch them. The signature (URL::signedRoute, no expiry) makes the URL
 * stable — so browsers cache it — while ids can't be enumerated or forged.
 * Deleting the media deletes the file, so the URL then 404s.
 */
class ProductMediaController extends Controller
{
    public function __invoke(int $media, string $variant): Response
    {
        $row = ProductMedia::query()->withoutGlobalScopes()->find($media);
        abort_if($row === null || $row->disk === null, Response::HTTP_NOT_FOUND);

        $path = $variant === 'thumb' ? ($row->thumb_path ?: $row->path) : $row->path;
        abort_if(! $path || ! Storage::disk($row->disk)->exists($path), Response::HTTP_NOT_FOUND);

        // The file behind a given URL never changes (new uploads get new names).
        $headers = [
            'Content-Type' => $row->mime_type ?? 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ];

        // Local disk → BinaryFileResponse, which answers HTTP Range (206): Safari/iOS
        // won't play an MP4 without it, and seeking needs it everywhere.
        if (config("filesystems.disks.{$row->disk}.driver") === 'local') {
            return response()->file(Storage::disk($row->disk)->path($path), $headers);
        }

        return Storage::disk($row->disk)->response($path, $row->original_name, $headers, 'inline');
    }
}
