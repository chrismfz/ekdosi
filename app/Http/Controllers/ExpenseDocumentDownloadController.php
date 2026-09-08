<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams an expense's attached document. AUTH + SIGNED + tenant-checked: the
 * link is generated server-side (short-lived `temporarySignedRoute`), the caller
 * must be able to view expenses, AND must belong to the expense's company — so a
 * private supplier scan can't be forged, enumerated, or read cross-tenant.
 *
 * A real HTTP GET (not a Livewire-returned download) streams the file from the
 * private `local` disk in chunks instead of buffering it in the worker.
 */
class ExpenseDocumentDownloadController extends Controller
{
    public function __invoke(Request $request, Expense $expense): BinaryFileResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->can('View:Expense'), Response::HTTP_FORBIDDEN);
        // Cross-tenant guard: the signed URL belongs to one tenant's expense.
        abort_unless($user->companies()->whereKey($expense->company_id)->exists(), Response::HTTP_FORBIDDEN);
        abort_if(blank($expense->document_path), Response::HTTP_NOT_FOUND);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($expense->document_path), Response::HTTP_NOT_FOUND);

        return response()->download($disk->path($expense->document_path));
    }
}
