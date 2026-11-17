<?php

namespace Tests\Support\StaffProbe;

use App\Http\Controllers\Admin\Customer\CustomerController;
use Illuminate\Http\JsonResponse;

/** A customer screen that a later change adds without a rule for it: the staff scope must refuse it to a restricted employee instead of letting it through. */
class UnlistedPeopleController extends CustomerController
{
    public function unlisted(): JsonResponse
    {
        return response()->json(['reached' => 'unlisted']);
    }
}
