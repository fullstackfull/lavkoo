<?php

namespace App\Http\Middleware;

use App\Services\Storefront\StorefrontPermissionService;
use Closure;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\Request;

/**
 * Lets an admin through when their role holds any one of the storefront permissions the route names, and sends everyone else back the way the module middleware does. It is its own
 * middleware, and not `module:a,b`, because the module middleware of a tree that does not carry the security set reads its first permission only, and a route that is open to a
 * writer or an approver must not depend on which of the two trees it runs in.
 */
final class EnsureStorefrontPermission
{
    /** The route middleware string for a route open to an admin who holds any one of the permissions. */
    public static function for(string ...$permissions): string
    {
        return self::class . ':' . implode(',', $permissions);
    }

    public function handle(Request $request, Closure $next, string ...$permissions): mixed
    {
        if (collect($permissions)->contains(static fn (string $permission): bool => StorefrontPermissionService::can(permission: $permission))) {
            return $next($request);
        }

        ToastMagic::error(translate('access_Denied') . '!');

        return auth('admin')->check() ? redirect()->route('admin.dashboard.index') : back();
    }
}
