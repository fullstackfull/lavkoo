<?php

namespace App\Services\Telemetry;

use App\Services\DeveloperPortal\ApiConsole;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes one telemetry row per request, after the response has gone out.
 *
 * Everything here is deliberately defensive: telemetry runs on every web and
 * API request of a live store, so it must cost one or two inserts at most and
 * must never surface an error to the shopper — record() swallows everything.
 *
 * Web visitors are identified by a first-party cookie (k_vid) so visits,
 * sources and sessions can be counted; API traffic (the customer and vendor
 * apps) is identified by ClientIdentity, which holds the one rule both this
 * and the analytics pipeline follow for callers that cannot hold a cookie.
 *
 * Product and shop resolution is NOT done here — the raw path/route is stored
 * and the nightly rollup does the joining, keeping the request path free of
 * extra queries.
 */
class TelemetryRecorder
{
    public const VISITOR_COOKIE = 'k_vid';

    public function __construct(private readonly ClientIdentity $identity)
    {
    }

    public function record(Request $request, Response $response, float $startedAt): void
    {
        try {
            if (!config('telemetry.enabled')) {
                return;
            }

            // A request the API console sent from the admin panel is the panel testing an endpoint,
            // not a customer using it. Counting it would put deliberately malformed probes into the
            // route timings and the error rate that an operator reads to decide whether the shop is
            // healthy.
            if ($request->headers->has(ApiConsole::MARKER_HEADER)) {
                return;
            }

            $path = trim($request->path(), '/');
            foreach (config('telemetry.ignore_prefixes', []) as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    return;
                }
            }

            $channel = $this->channelOf($path);
            [$userType, $userId] = $this->identify($request, $channel);
            $now = Carbon::now();

            $sessionId = null;
            $visitorId = null;
            if ($channel === 'web') {
                $visitorId = $request->cookie(self::VISITOR_COOKIE) ?: ($request->attributes->get('telemetry_new_visitor_id') ?: null);
                if ($visitorId && $this->isHtmlNavigation($request, $response)) {
                    $sessionId = $this->touchSession($request, $visitorId, $userType, $userId, $now);
                }
            } else {
                // API callers hold no cookie, so identity is the account, then an id the app sends
                // for its own installation, then the masked network address — which counts networks
                // rather than people and expires daily. Nothing at all when none of the three is
                // there, because one shared placeholder would merge every such request into a
                // single visitor that does not exist.
                [$visitorId] = $this->identity->forApi($request, $userType, $userId);
            }

            DB::table('telemetry_requests')->insert([
                'session_id' => $sessionId,
                'visitor_id' => $visitorId ? Str::limit($visitorId, 60, '') : null,
                'channel' => $channel,
                'method' => Str::limit($request->method(), 8, ''),
                'route_name' => Str::limit(optional($request->route())->getName() ?? '', 120, '') ?: null,
                'path' => Str::limit($path === '' ? '/' : $path, 191, ''),
                'status' => $response->getStatusCode(),
                'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                'user_type' => $userType,
                'user_id' => $userId,
                'referrer_domain' => $this->referrerDomain($request),
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // Telemetry never breaks a request.
        }
    }

    /** New web visitors get their cookie queued here, during the request. */
    public function ensureVisitorCookie(Request $request): void
    {
        try {
            if (!config('telemetry.enabled') || $this->channelOf(trim($request->path(), '/')) !== 'web') {
                return;
            }
            if (!$request->cookie(self::VISITOR_COOKIE)) {
                $visitorId = Str::random(32);
                $request->attributes->set('telemetry_new_visitor_id', $visitorId);
                cookie()->queue(cookie(self::VISITOR_COOKIE, $visitorId, 60 * 24 * 365, null, null, null, false));
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function channelOf(string $path): string
    {
        return str_starts_with($path, 'api/') ? 'api' : 'web';
    }

    /** @return array{0: ?string, 1: ?int} */
    private function identify(Request $request, string $channel): array
    {
        if (auth('admin')->check()) {
            return ['admin', auth('admin')->id()];
        }
        if (auth('seller')->check()) {
            return ['vendor', auth('seller')->id()];
        }
        if (auth('customer')->check()) {
            return ['customer', auth('customer')->id()];
        }
        if ($channel === 'api' && ($user = $request->user('api'))) {
            return ['customer', $user->id];
        }
        return ['guest', null];
    }

    /**
     * Sessions only advance on real page navigations — ajax polling and asset
     * fetches must not inflate pageview counts.
     */
    private function isHtmlNavigation(Request $request, Response $response): bool
    {
        return $request->isMethod('GET')
            && !$request->ajax()
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }

    private function touchSession(Request $request, string $visitorId, ?string $userType, ?int $userId, Carbon $now): ?int
    {
        $gap = (int) config('telemetry.session_gap_minutes', 30);
        $current = DB::table('visit_sessions')
            ->where('visitor_id', $visitorId)
            ->where('last_activity_at', '>=', $now->copy()->subMinutes($gap))
            ->orderByDesc('id')
            ->first(['id']);

        if ($current) {
            DB::table('visit_sessions')->where('id', $current->id)->update([
                'last_activity_at' => $now,
                'pageviews' => DB::raw('pageviews + 1'),
                'user_type' => $userType,
                'user_id' => $userId,
            ]);
            return $current->id;
        }

        return (int) DB::table('visit_sessions')->insertGetId([
            'visitor_id' => $visitorId,
            'channel' => 'web',
            'user_type' => $userType,
            'user_id' => $userId,
            'entry_path' => Str::limit(trim($request->path(), '/') ?: '/', 191, ''),
            'referrer_domain' => $this->referrerDomain($request),
            // Guarded, though nothing here 500s: the whole recorder sits inside a catch, so
            // `?utm_source[]=x` on any storefront URL threw and was swallowed — and the visit
            // SESSION was silently never written. A campaign parameter nobody can spell should cost
            // the attribution, not the visit.
            'utm_source' => $this->campaignTag($request, 'utm_source'),
            'utm_medium' => $this->campaignTag($request, 'utm_medium'),
            'utm_campaign' => $this->campaignTag($request, 'utm_campaign'),
            'device' => $this->device($request),
            'ip_hash' => $this->identity->networkHash($request),
            'pageviews' => 1,
            'started_at' => $now,
            'last_activity_at' => $now,
        ]);
    }

    private function referrerDomain(Request $request): ?string
    {
        $referrer = (string) $request->headers->get('referer');
        if ($referrer === '') {
            return null;
        }
        $host = parse_url($referrer, PHP_URL_HOST);
        if (!$host) {
            return null;
        }
        // Internal navigation is not a traffic source.
        if (strcasecmp($host, (string) $request->getHost()) === 0) {
            return null;
        }
        return Str::limit(strtolower($host), 191, '');
    }

    private function device(Request $request): string
    {
        $agent = strtolower((string) $request->userAgent());
        if ($agent === '') {
            return 'unknown';
        }
        if (preg_match('/bot|crawl|spider|slurp|curl|wget|httpclient|python-requests/', $agent)) {
            return 'bot';
        }
        if (str_contains($agent, 'ipad') || (str_contains($agent, 'android') && !str_contains($agent, 'mobile'))) {
            return 'tablet';
        }
        if (preg_match('/mobile|iphone|android/', $agent)) {
            return 'mobile';
        }
        return 'desktop';
    }
    /** One UTM parameter, or null when the visitor's URL did not carry a readable one. */
    private function campaignTag(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? (Str::limit($value, 96, '') ?: null) : null;
    }
}
