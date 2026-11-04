<?php

namespace App\Http\Controllers\Seller;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Contextual help for the current screen (handoff 02 §1). */
class HelpController extends SellerCenterController
{
    public function index(Request $request): View
    {
        return view('seller-views.help', [
            'topic' => (string) $request->query('topic', ''),
        ]);
    }
}
