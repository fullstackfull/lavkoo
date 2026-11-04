<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Support\AttributionEngine;
use App\Services\Analytics\Support\AnalyticsPolicy;
use App\Services\Analytics\Support\BotDetector;
use App\Services\Analytics\Support\PathNormalizer;
use App\Services\Telemetry\ClientIdentity;
use App\Services\Telemetry\TelemetryRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Who is here, on which visit, and may we count them.
 *
 * Resolved once per request and remembered, because every event recorded during that request asks
 * the same questions and none of them should cost a second query. Identity is a first-party random
 * id in a cookie the visitor can clear — never a fingerprint, never anything derived from their
 * device — and the session is a row that a second visit half an hour later will not join.
 *
 * The visitor cookie is deliberately the SAME one the request telemetry already sets. Two cookies
 * for two ids would mean two different populations called "visitors" on two different admin
 * screens, which is how a merchant ends up with two dashboards that disagree.
 *
 * API and app callers hold no cookie at all — the api middleware group has none — so their identity
 * comes from ClientIdentity, which telemetry reads too for the same reason. Every session records
 * WHICH of those identities it got, because one of them (the masked network address) counts networks
 * rather than people, and a figure built on it has to be able to say so.
 */
class VisitorContext
{
    public const CAMPAIGN_COOKIE = 'k_camp';

    private ?string $visitorId = null;
    private ?int $sessionId = null;
    private bool $resolved = false;
    private bool $isBot = false;
    private bool $isInternal = false;
    private ?string $device = null;
    private ?string $country = null;
    private ?string $userType = null;
    private ?int $userId = null;
    private bool $isNewVisitor = false;
    private ?string $identityBasis = null;
    private ?bool $sessionsCarryBasis = null;

    public function __construct(
        private readonly BotDetector $bots,
        private readonly AttributionEngine $attribution,
        private readonly PathNormalizer $paths,
        private readonly ClientIdentity $identity,
    ) {
    }

    /** Resolve everything about this request, once. Never throws. */
    public function resolve(Request $request): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;

        try {
            $this->isBot = $this->bots->isBot($request);
            $this->isInternal = $this->bots->isInternal($request);
            $this->device = $this->bots->device($request);
            $this->country = $this->country($request);
            [$this->userType, $this->userId] = $this->identify($request);
            $this->visitorId = $this->visitorId($request);
        } catch (\Throwable) {
            // A context that cannot be resolved records nothing; it never breaks the page.
            $this->visitorId = null;
        }
    }

    public function visitorId(Request $request): ?string
    {
        if ($this->visitorId !== null) {
            return $this->visitorId;
        }

        if ($this->isApiClient($request)) {
            [$identity, $basis] = $this->identity->forApi(
                request: $request,
                userType: $this->userType,
                userId: $this->userId,
                maskAddress: app(AnalyticsPolicy::class)->maskIp(),
            );

            if ($identity === null) {
                return null;
            }

            $this->identityBasis = $basis;

            return $this->visitorId = Str::limit($identity, 60, '');
        }

        $existing = $request->cookie(TelemetryRecorder::VISITOR_COOKIE)
            ?: $request->attributes->get('telemetry_new_visitor_id');

        if (is_string($existing) && $existing !== '') {
            $this->identityBasis = ClientIdentity::BASIS_COOKIE;

            return $this->visitorId = Str::limit($existing, 60, '');
        }

        // No cookie yet: mint one and queue it. The same value is put on the request so events
        // recorded during THIS request are attributed to the visitor the browser is about to
        // become, rather than being dropped as anonymous.
        $minted = Str::random(32);
        $request->attributes->set('telemetry_new_visitor_id', $minted);
        $this->isNewVisitor = true;
        $this->identityBasis = ClientIdentity::BASIS_COOKIE;

        try {
            cookie()->queue(cookie(TelemetryRecorder::VISITOR_COOKIE, $minted, 60 * 24 * 365, null, null, null, false));
        } catch (\Throwable) {
            // Without a cookie this visit is simply counted on its own; nothing else breaks.
        }

        return $this->visitorId = $minted;
    }

    /**
     * The current session, opening a new one when the last was more than the gap ago.
     *
     * @return int|null  null when there is nothing to attach events to
     */
    public function sessionId(Request $request): ?int
    {
        if ($this->sessionId !== null) {
            return $this->sessionId;
        }

        $visitorId = $this->visitorId($request);

        if ($visitorId === null) {
            return null;
        }

        try {
            $now = Carbon::now();
            $gap = app(AnalyticsPolicy::class)->sessionGapMinutes();

            $current = $this->connection()->table('analytics_sessions')
                ->where('visitor_id', $visitorId)
                ->where('last_activity_at', '>=', $now->copy()->subMinutes($gap))
                ->orderByDesc('id')
                ->first(['id', 'started_at']);

            return $this->sessionId = $current !== null
                ? $this->touch($current, $request, $now)
                : $this->open($visitorId, $request, $now);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isBot(): bool
    {
        return $this->isBot;
    }

    public function isInternal(): bool
    {
        return $this->isInternal;
    }

    /** True when this visit belongs in the reports a merchant reads. */
    public function isCountable(): bool
    {
        return !($this->isBot && app(AnalyticsPolicy::class)->excludeBots())
            && !($this->isInternal && app(AnalyticsPolicy::class)->excludeInternal());
    }

    public function device(): ?string
    {
        return $this->device;
    }

    public function country(Request $request): ?string
    {
        if ($this->country !== null) {
            return $this->country;
        }

        if (!app(AnalyticsPolicy::class)->storeCountry()) {
            return null;
        }

        // Country comes from a header a CDN resolved, never from a GPS reading and never from a
        // geo-IP lookup this application performs on the request path. With no such header the
        // answer is simply "not available", which is what the reports will say.
        $header = (string) config('analytics.privacy.country_header', '');
        $value = $header !== '' ? (string) $request->headers->get($header) : '';

        return $this->country = preg_match('/^[A-Za-z]{2}$/', $value) === 1 ? strtoupper($value) : null;
    }

    public function userType(): ?string
    {
        return $this->userType;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    /**
     * What this visit's identity is actually worth: user, cookie, client, or network.
     *
     * Reports read it to say so. A count of network-identified sessions is a count of networks, and
     * the screen that shows it has to be able to admit that rather than print it as people.
     */
    public function identityBasis(): ?string
    {
        return $this->identityBasis;
    }

    // -------------------------------------------------------------------------------------------

    /**
     * A caller that cannot hold a cookie.
     *
     * Both mobile apps and every server-to-server integration reach this application through the
     * api group, which has no cookie middleware.
     */
    private function isApiClient(Request $request): bool
    {
        return $request->is('api/*') || $request->headers->has('X-App-Version');
    }

    /** @return array{0: ?string, 1: ?int} */
    private function identify(Request $request): array
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
        // Passport erases the Authorization header when the bearer it is handed
        // is not one of its own (TokenGuard::getPsrRequestViaBearerToken). The
        // seller API carries its own bearer and authenticates it in a later
        // middleware, so asking the api guard here left that middleware with an
        // empty header — a 401 on every authenticated seller request, from a
        // healthy server holding a valid token. Instrumentation must never be
        // able to change what the request means.
        $authorization = $request->headers->get('Authorization');

        try {
            if ($user = $request->user('api')) {
                return ['customer', $user->id];
            }
        } finally {
            if ($authorization !== null) {
                $request->headers->set('Authorization', $authorization, true);
            }
        }

        return ['guest', null];
    }

    private function touch(object $session, Request $request, Carbon $now): int
    {
        $startedAt = Carbon::parse($session->started_at);
        $duration = max(0, (int) $startedAt->diffInSeconds($now, false));
        $engagedAfter = app(AnalyticsPolicy::class)->engagedAfterSeconds();

        $this->connection()->table('analytics_sessions')->where('id', $session->id)->update([
            'last_activity_at' => $now,
            'exit_path' => $this->paths->fromRequest($request),
            'duration_seconds' => $duration,
            // Engagement is measured, not assumed: a second page or ten seconds on the first one.
            'is_engaged' => DB::raw('CASE WHEN pageviews >= 2 OR ' . $duration . ' >= ' . $engagedAfter . ' THEN 1 ELSE is_engaged END'),
            'is_bounce' => DB::raw('CASE WHEN pageviews >= 2 THEN 0 ELSE is_bounce END'),
            'user_type' => $this->userType,
            'user_id' => $this->userId,
        ]);

        return (int) $session->id;
    }

    private function open(string $visitorId, Request $request, Carbon $now): int
    {
        $attribution = $this->attribution->resolve($request, $this->campaign($request));
        $known = $this->connection()->table('analytics_visitors')->where('visitor_id', $visitorId)->first();
        $isNew = $known === null;

        $row = [
            'visitor_id' => $visitorId,
            'channel' => $request->is('api/*') ? 'api' : 'web',
            'user_type' => $this->userType,
            'user_id' => $this->userId,
            'source' => $attribution['source'],
            'medium' => $attribution['medium'],
            'campaign' => $attribution['campaign'],
            'content' => $attribution['content'],
            'term' => $attribution['term'],
            'referrer_domain' => $attribution['referrer_domain'],
            'campaign_id' => $attribution['campaign_id'],
            'attribution_basis' => $attribution['attribution_basis'],
            'landing_path' => $this->paths->fromRequest($request),
            'exit_path' => $this->paths->fromRequest($request),
            'device' => $this->device,
            'os' => $this->platform($request),
            'browser' => $this->browser($request),
            'language' => Str::limit((string) $request->getPreferredLanguage(), 12, '') ?: null,
            'country' => $this->country($request),
            'app_version' => $this->appVersion($request),
            'ip_hash' => $this->identity->networkHash($request, app(AnalyticsPolicy::class)->maskIp()),
            'pageviews' => 0,
            'events' => 0,
            'duration_seconds' => 0,
            'is_engaged' => false,
            'is_bounce' => true,
            'is_new_visitor' => $isNew,
            'is_bot' => $this->isBot,
            'is_internal' => $this->isInternal,
            'started_at' => $now,
            'last_activity_at' => $now,
        ];

        if ($this->sessionsCarryBasis()) {
            $row['identity_basis'] = $this->identityBasis;
        }

        $sessionId = (int) $this->connection()->table('analytics_sessions')->insertGetId($row);

        $this->rememberVisitor($visitorId, $attribution, $request, $now, $isNew);

        return $sessionId;
    }

    /** Installations that have not run the migration yet keep working, without the disclosure. */
    private function sessionsCarryBasis(): bool
    {
        if ($this->sessionsCarryBasis !== null) {
            return $this->sessionsCarryBasis;
        }

        try {
            return $this->sessionsCarryBasis = Schema::connection(config('analytics.connection'))
                ->hasColumn('analytics_sessions', 'identity_basis');
        } catch (\Throwable) {
            return $this->sessionsCarryBasis = false;
        }
    }

    /**
     * First touch is written once and never updated — that is what makes it first touch.
     *
     * @param  array<string, string|null>  $attribution
     */
    private function rememberVisitor(string $visitorId, array $attribution, Request $request, Carbon $now, bool $isNew): void
    {
        if ($isNew) {
            $this->connection()->table('analytics_visitors')->insert([
                'visitor_id' => $visitorId,
                'user_type' => $this->userType,
                'user_id' => $this->userId,
                'identified_at' => $this->userId !== null ? $now : null,
                'first_source' => $attribution['source'],
                'first_medium' => $attribution['medium'],
                'first_campaign' => $attribution['campaign'],
                'first_referrer' => $attribution['referrer_domain'],
                'first_landing_path' => $this->paths->fromRequest($request),
                'sessions' => 1,
                'is_bot' => $this->isBot,
                'is_internal' => $this->isInternal,
                'country' => $this->country($request),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);

            return;
        }

        $this->connection()->table('analytics_visitors')->where('visitor_id', $visitorId)->update([
            'sessions' => DB::raw('sessions + 1'),
            'last_seen_at' => $now,
            'user_type' => $this->userType,
            'user_id' => $this->userId,
            // Set the moment they first identify themselves and left alone after: the gap between
            // first_seen_at and identified_at is how long a customer browsed before signing up.
            'identified_at' => DB::raw($this->userId !== null ? "COALESCE(identified_at, '{$now->toDateTimeString()}')" : 'identified_at'),
        ]);
    }

    /**
     * The short link this visit arrived through, if any.
     *
     * @return array<string, mixed>|null
     */
    private function campaign(Request $request): ?array
    {
        $code = $request->cookie(self::CAMPAIGN_COOKIE) ?: $request->attributes->get('analytics_campaign_code');

        if (!is_string($code) || $code === '') {
            return null;
        }

        try {
            $campaign = $this->connection()->table('analytics_campaigns')
                ->where('code', $code)
                ->first(['id', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']);

            return $campaign === null ? null : (array) $campaign;
        } catch (\Throwable) {
            return null;
        }
    }

    private function platform(Request $request): ?string
    {
        $agent = strtolower((string) $request->userAgent());

        return match (true) {
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'iphone') || str_contains($agent, 'ipad') || str_contains($agent, 'ios') => 'iOS',
            str_contains($agent, 'mac os') => 'macOS',
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'linux') => 'Linux',
            $agent === '' => null,
            default => 'Other',
        };
    }

    private function browser(Request $request): ?string
    {
        $agent = strtolower((string) $request->userAgent());

        return match (true) {
            str_contains($agent, 'edg/') => 'Edge',
            str_contains($agent, 'opr/') || str_contains($agent, 'opera') => 'Opera',
            str_contains($agent, 'samsungbrowser') => 'Samsung Internet',
            str_contains($agent, 'firefox') => 'Firefox',
            // Chrome must be tested after the browsers that also claim to be Chrome.
            str_contains($agent, 'chrome') || str_contains($agent, 'crios') => 'Chrome',
            str_contains($agent, 'safari') => 'Safari',
            $agent === '' => null,
            default => 'Other',
        };
    }

    /** The mobile app build, which the apps send as a header. */
    private function appVersion(Request $request): ?string
    {
        $version = (string) ($request->headers->get('X-App-Version') ?: $request->header('App-Version', ''));

        return preg_match('/^[0-9a-zA-Z.\-+]{1,24}$/', $version) === 1 ? $version : null;
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('analytics.connection'));
    }
}
