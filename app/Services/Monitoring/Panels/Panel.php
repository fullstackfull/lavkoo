<?php

namespace App\Services\Monitoring\Panels;

use Illuminate\Http\Request;

/**
 * One section of the operations centre.
 *
 * A panel turns stored measurements into the shape a section renders. It never measures anything
 * itself — collectors do that — and it never decides who may see it, which is the controller's job.
 *
 * The return value is deliberately plain arrays rather than view models: the same payload is
 * rendered server-side and served as JSON to the page's refresh, so it has to survive
 * json_encode() unchanged.
 */
interface Panel
{
    /**
     * @param  string  $range  one of the controller's range keys (live, 15m, 1h, 6h, 24h, 7d, 30d, 90d)
     * @return array<string, mixed>
     */
    public function data(string $range, Request $request): array;
}
