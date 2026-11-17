<?php

namespace Tests\Support\StaffProbe;

use App\Http\Controllers\Admin\Product\ProductController;
use Illuminate\Http\JsonResponse;

/** A product screen that a later change adds without a rule for it: the staff scope must refuse it to a restricted employee instead of letting it through. */
class UnlistedProductController extends ProductController
{
    public function unlisted(): JsonResponse
    {
        return response()->json(['reached' => 'unlisted']);
    }
}
