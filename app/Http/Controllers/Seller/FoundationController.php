<?php

namespace App\Http\Controllers\Seller;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Wave 1's acceptance screen.
 *
 * The handoff calls the foundation done when "a throwaway screen can be assembled from
 * configuration alone: a table with 10 columns, 4 saved views, 6 filter types, selection, bulk bar,
 * density switch, all seven data states, in both directions, at all five breakpoints, with no
 * screen-specific CSS" (handoff 13, wave 1). This is that screen, and it is reachable only with
 * debug on so it never becomes a production destination.
 */
class FoundationController extends SellerCenterController
{
    public function __invoke(Request $request): View
    {
        abort_unless(config('app.debug'), 404);

        return view('seller-views.foundation', [
            'state' => (string) $request->query('state', 'normal'),
        ]);
    }
}
