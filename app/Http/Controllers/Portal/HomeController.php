<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Portal landing (Slice 0): an authenticated placeholder — «Καλωσήρθες» + logout,
 * and nothing else. No customer data, no grants, no invoices yet: this slice only
 * proves the auth shell. Later slices add the documents/services behind grants.
 */
class HomeController extends Controller
{
    public function index(Request $request): View
    {
        return view('portal.home', [
            'user' => Auth::guard('portal')->user(),
        ]);
    }
}
