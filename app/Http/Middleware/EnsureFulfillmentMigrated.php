<?php

namespace App\Http\Middleware;

use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Fulfillment\Readiness\FulfillmentPreflight;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Integration Center can be reached as soon as the code is deployed, which may be before its two migrations have run. Until
 * they have, every page of it would fail on a missing table: it explains that instead, and changes nothing.
 */
class EnsureFulfillmentMigrated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (FulfillmentSchema::ready()) {
            return $next($request);
        }

        return response()->view('admin-views.fulfillment.not-migrated', ['migrations' => FulfillmentPreflight::MIGRATIONS], 503);
    }
}
