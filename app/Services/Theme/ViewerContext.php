<?php

namespace App\Services\Theme;

use Illuminate\Http\Request;

/**
 * Who is being served, in the only terms the theme engine is allowed to ask about.
 *
 * Deliberately small. A visibility rule that can read anything about a shopper becomes a profiling
 * surface by accident, and every field here has to be defensible on its own: the platform and
 * device decide which renderer will draw the section, the auth flag decides whether a
 * customer-only rail makes sense, the locale decides which translation to hand back, and the
 * capabilities decide what the client can draw at all. Nothing identifies a person.
 *
 * Immutable, and constructible from an HTTP request or by hand — the second is what lets the
 * builder preview a legacy phone from an admin browser.
 */
final class ViewerContext
{
    public const PLATFORM_WEB = 'web';
    public const PLATFORM_APP = 'app';

    public const DEVICE_DESKTOP = 'desktop';
    public const DEVICE_TABLET  = 'tablet';
    public const DEVICE_MOBILE  = 'mobile';

    public const AUDIENCE_GUEST    = 'guest';
    public const AUDIENCE_CUSTOMER = 'customer';

    public const PLATFORMS = [self::PLATFORM_WEB, self::PLATFORM_APP];
    public const DEVICES   = [self::DEVICE_DESKTOP, self::DEVICE_TABLET, self::DEVICE_MOBILE];
    public const AUDIENCES = [self::AUDIENCE_GUEST, self::AUDIENCE_CUSTOMER];

    /**
     * @param  array<int, string>  $supportedComponents  section types this client can draw
     */
    public function __construct(
        public readonly string $platform = self::PLATFORM_WEB,
        public readonly string $device = self::DEVICE_DESKTOP,
        public readonly bool $authenticated = false,
        public readonly ?string $locale = null,
        public readonly int $uiEngineVersion = 0,
        public readonly int $uiSchemaVersion = 0,
        public readonly array $supportedComponents = [],
        // Which surface asked. Null means "work it out from the platform", which is what every
        // client that predates channels does — and what keeps this constructor's old call sites
        // correct without touching them.
        public readonly ?string $channel = null,
        // The customer segments this viewer belongs to (Phase 3.4). Empty for guests, for
        // unresolvable customers, and everywhere the commerce engine is off — all three of which
        // mean the same thing downstream: the base, non-personalised experience.
        public readonly array $segments = [],
        // A stable identity for experiment bucketing (Phase 3.5): the customer id when signed
        // in (survives devices), else the analytics visitor id (the SAME identity the telemetry
        // already uses — §47 says use existing identifiers, not a third population). Null means
        // control, deterministically.
        public readonly ?string $experimentSubject = null,
    ) {
    }

    /**
     * The context a customer-app request describes about itself.
     *
     * Every field is optional and every unparseable value falls back rather than failing: this
     * feeds a public endpoint, and a client that spells a header wrong should get the default
     * home page, not a 422.
     */
    public static function fromRequest(Request $request, string $platform = self::PLATFORM_APP): self
    {
        $device = self::normalize($request->query('device'), self::DEVICES)
            ?? ($platform === self::PLATFORM_APP ? self::DEVICE_MOBILE : self::DEVICE_DESKTOP);

        // Capabilities arrive as a comma list, from a header (so a CDN can vary on it) or a query
        // parameter (so a browser can be pointed at one by hand for debugging).
        $componentsRaw = $request->header('X-UI-Components') ?? $request->query('components');
        $components = is_string($componentsRaw)
            ? array_values(array_filter(array_map('trim', explode(',', $componentsRaw))))
            : [];

        $engineRaw = $request->header('X-UI-Engine') ?? $request->query('ui_engine');
        $engine = is_numeric($engineRaw) ? max(0, (int) $engineRaw) : 0;

        // The schema generation this build parses. Read (and folded into the delivery cache key)
        // so a future schema bump can shape payloads per generation; today every build says 1.
        $schemaRaw = $request->header('X-UI-Schema') ?? $request->query('ui_schema');
        $schema = is_numeric($schemaRaw) ? max(0, (int) $schemaRaw) : 0;

        // A client may name its own channel; one that does not is placed by its platform, so every
        // installed app keeps landing on customer_app without sending anything new.
        $channel = Channel::normalize($request->header('X-UI-Channel') ?? $request->query('channel'));

        return new self(
            platform: $platform,
            device: $device,
            authenticated: self::customerIsAuthenticated(),
            locale: app()->getLocale(),
            uiEngineVersion: $engine,
            uiSchemaVersion: $schema,
            supportedComponents: $components,
            channel: $channel,
            segments: self::resolvedSegments(),
            experimentSubject: self::resolvedSubject($request),
        );
    }

    /**
     * @see \App\Services\Commerce\ExperimentResolver — null yields control, never randomness.
     */
    private static function resolvedSubject(Request $request): ?string
    {
        try {
            $customerId = auth('api')->id();
            if ($customerId) {
                return 'c' . $customerId;
            }

            $visitorId = app(\App\Services\Analytics\VisitorContext::class)->visitorId($request);

            return $visitorId !== null ? 'v' . $visitorId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The authenticated customer's segments, or none — never an error. Personalisation failing
     * must cost the personalised sections, not the page (§44).
     *
     * @return array<int, string>
     */
    private static function resolvedSegments(): array
    {
        try {
            $customerId = auth('api')->id();

            return $customerId
                ? app(\App\Services\Commerce\SegmentResolver::class)->segmentsFor((int) $customerId)
                : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Whether the request carries an authenticated customer.
     *
     * Guarded because resolving the api guard boots Passport, and an environment without its keys
     * (tests, a fresh install mid-setup) must degrade to "guest" — the narrower audience — rather
     * than 500 a public endpoint.
     */
    private static function customerIsAuthenticated(): bool
    {
        try {
            return (bool) auth('api')->user();
        } catch (\Throwable) {
            return false;
        }
    }

    public function audience(): string
    {
        return $this->authenticated ? self::AUDIENCE_CUSTOMER : self::AUDIENCE_GUEST;
    }

    /** The surface this viewer belongs to — declared, or derived from the platform. */
    public function channel(): string
    {
        return Channel::forViewer($this);
    }

    /**
     * Whether this client says it can draw a section type.
     *
     * A client that declared nothing is treated as able to draw everything — that is the web, and
     * it is also every app build that predates capability reporting. Silently emptying their home
     * page would be a far worse failure than sending a section an old client ignores.
     */
    public function canRender(string $type): bool
    {
        return $this->supportedComponents === [] || in_array($type, $this->supportedComponents, true);
    }

    public function declaresCapabilities(): bool
    {
        return $this->supportedComponents !== [] || $this->uiEngineVersion > 0;
    }

    /** @param  array<int, string>  $allowed */
    private static function normalize(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
