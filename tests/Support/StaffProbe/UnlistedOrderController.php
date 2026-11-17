<?php

namespace Tests\Support\StaffProbe;

use App\Http\Controllers\Admin\Order\OrderController;
use Illuminate\Http\JsonResponse;

/** An order screen that a later change adds without a rule for it: the staff scope must refuse it to a restricted employee instead of letting it through. */
class UnlistedOrderController extends OrderController
{
    public function unlisted(): JsonResponse
    {
        return response()->json(['reached' => 'unlisted']);
    }
}
