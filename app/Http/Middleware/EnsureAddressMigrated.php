<?php

namespace App\Http\Middleware;

use App\Services\Address\AddressMigrations;
use App\Services\Address\AddressSchema;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The address pages can be reached as soon as the code is deployed, which may be before the four address migrations have run. Until they
 * have, every page would fail on a missing table: it explains what is still to run instead, and changes nothing.
 */
class EnsureAddressMigrated
{
    public function handle(Request $request, Closure $next): Response
    {
        AddressSchema::flush();
        if (AddressSchema::ready()) {
            return $next($request);
        }

        return response()->view('admin-views.address.not-migrated', ['migrations' => AddressMigrations::status()], 503);
    }
}
