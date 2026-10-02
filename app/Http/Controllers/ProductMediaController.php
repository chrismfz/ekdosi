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
        return Storage::disk($row->disk)->response($path, $row->original_name, [
            'Content-Type' => $row->mime_type ?? 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
