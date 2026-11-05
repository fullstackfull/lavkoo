<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| The proxies whose X-Forwarded-* headers are believed: a comma-separated list of addresses or CIDR ranges, or '*' to trust
| the caller (only when the application is reachable through the proxy and nowhere else). Empty, the default, trusts none:
| the application then sees the proxy's address as the client's, and the proxy's scheme as the request's.
|
| bootstrap/app.php wires the framework's Illuminate\Http\Middleware\TrustProxies, which reads this key. The class in
| app/Http/Middleware/TrustProxies.php is not used, so editing it has no effect.
|
*/

return [
    'proxies' => env('TRUSTED_PROXIES'),
];
