<?php

namespace App\Http\Controllers;

use App\Models\CompanyBackupRun;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a finished backup bundle to the operator. AUTH + SIGNED: the link is
 * generated server-side only for users who may view companies and expires
 * quickly (`URL::temporarySignedRoute`), so it can't be forged or enumerated.
 *
 * Why a route instead of returning the file from the Filament action: a download
 * returned from a Livewire/Filament action is buffered fully in memory and
 * base64-encoded by Livewire — a large `full` bundle could exhaust the worker.
 * A real HTTP GET here lets Symfony's BinaryFileResponse stream the file from
 * disk in chunks. Both «Λήψη αντιγράφου τώρα» and the runs-history «Λήψη» link
 * here.
 */
class CompanyBackupDownloadController extends Controller
{
    public function __invoke(Request $request, CompanyBackupRun $run): BinaryFileResponse
    {
        // Defence in depth: the signed middleware already guards the URL, but a
        // valid link must still belong to someone allowed to see companies (these
        // backups are panel-global / super-admin territory).
        $user = $request->user();
        abort_unless($user !== null && $user->can('View:Company'), Response::HTTP_FORBIDDEN);

        // Only a local bundle that still exists on disk is downloadable; a
        // remote-only / pruned / failed run has no local file.
        abort_unless($run->isDownloadable(), Response::HTTP_NOT_FOUND);

        return response()->download($run->bundle_path, basename((string) $run->bundle_path));
    }
}
