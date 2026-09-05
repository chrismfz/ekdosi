<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\CustomerLedgerFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * «Η καρτέλα μου» — the customer's read-only financial statement (balance +
 * chronological ledger), one per granted (company, customer). All figures come
 * from the shared CustomerLedgerBuilder via CustomerLedgerFeed; this controller
 * only renders. No «pay» action yet (attaches with the payment-gateway pillar).
 */
class StatementController extends Controller
{
    public function index(Request $request, CustomerLedgerFeed $feed): View
    {
        $user = Auth::guard('portal')->user();

        return view('portal.statement', [
            'user' => $user,
            'statements' => $feed->forLogin($user),
        ]);
    }
}
