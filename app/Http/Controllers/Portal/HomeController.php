<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\CustomerDocumentFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Portal landing: the customer's own documents, grouped per company/customer
 * they have an ACTIVE grant for. Read-only; scoped entirely by the grants
 * (CustomerDocumentFeed) — a login sees a customer's documents ONLY through a
 * grant, never by ΑΦΜ or company alone.
 */
class HomeController extends Controller
{
    public function index(Request $request, CustomerDocumentFeed $feed): View
    {
        $user = Auth::guard('portal')->user();

        return view('portal.home', [
            'user' => $user,
            'groups' => $feed->forLogin($user),
        ]);
    }
}
