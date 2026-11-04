<?php

namespace App\Services\Theme;

/**
 * What each client can actually draw, and what the server may therefore send it.
 *
 * The registry in SectionRegistry answers "what can a merchant compose" — that is the builder's
 * question and the web can draw all of it. This one answers a different question: "will the phone
 * in a shopper's pocket survive this section". Those are not the same list and never will be, so
 * they are not the same file: the app ships a fixed set of renderers compiled into it, and gains
 * new ones only when a new build reaches the store.
 *
 * Compatibility is negotiated on CAPABILITIES, not on version numbers. An app reports the
 * component types it holds; the server sends the intersection. A version comparison would be a
 * guess about what a build contains — and wrong the first time someone side-loads, staggers a
 * rollout, or ships a hotfix that adds a renderer without bumping the marketing version.
 *
 * Two rules keep this safe in both directions:
 *   - a client that declares nothing gets everything this registry marks app-safe, because every
 *     build older than capability reporting would otherwise get an empty home page;
 *   - a component this registry does not list is never sent to the app at all, whatever a client
 *     claims to support. (`custom_html` IS listed: the app draws it through a sandboxed HTML
 *     widget — scripts never execute — and the web renders the same markup.)
 */
class ComponentCapabilityRegistry
{
    /** The contract shape itself. Bumped only when the payload's meaning changes. */
    public const SCHEMA_VERSION = 1;

    /** The renderer generation shipped in the current app build. */
    public const CURRENT_ENGINE_VERSION = 1;

    /**
     * Section types the customer app can render, with the component version and the minimum
     * engine generation each needs.
     *
     * `version` is the component's own contract: bump it when a section's settings change in a way
     * an older renderer would draw wrongly, and the older client stops receiving it. `engine` is
     * the renderer generation, for a section that needs a primitive earlier builds do not have.
     *
     * @var array<string, array{version: int, engine: int}>
     */
    private const APP_COMPONENTS = [
        // Banner family — one renderer, several arrangements, all fed by `cards`.
        'hero_banner'        => ['version' => 1, 'engine' => 1],
        'promotional_banner' => ['version' => 1, 'engine' => 1],
        'split_banner'       => ['version' => 1, 'engine' => 1],
        'banner_mosaic'      => ['version' => 1, 'engine' => 1],
        'banner_strip'       => ['version' => 1, 'engine' => 1],
        'store_banner'       => ['version' => 1, 'engine' => 1],

        // Product family — one renderer, fed by the section's `source`.
        'product_slider'    => ['version' => 1, 'engine' => 1],
        'featured_deal'     => ['version' => 1, 'engine' => 1],
        'clearance_sale'    => ['version' => 1, 'engine' => 1],
        'deal_of_the_day'   => ['version' => 1, 'engine' => 1],
        'flash_deal'        => ['version' => 1, 'engine' => 1],
        'category_showcase' => ['version' => 1, 'engine' => 1],
        'brand_showcase'    => ['version' => 1, 'engine' => 1],
        'bundle'            => ['version' => 1, 'engine' => 1],
        'product_tabs'      => ['version' => 1, 'engine' => 1],

        // Taxonomy rails.
        'category_grid'  => ['version' => 1, 'engine' => 1],
        'brand_slider'   => ['version' => 1, 'engine' => 1],
        'vendor_slider'  => ['version' => 1, 'engine' => 1],

        // Content and chrome — everything they need is inline in the payload.
        'announcement_bar' => ['version' => 1, 'engine' => 1],
        'usp_strip'        => ['version' => 1, 'engine' => 1],
        'stats_bar'        => ['version' => 1, 'engine' => 1],
        'testimonials'     => ['version' => 1, 'engine' => 1],
        'faq'              => ['version' => 1, 'engine' => 1],
        'interest_tiles'   => ['version' => 1, 'engine' => 1],
        'price_tiles'      => ['version' => 1, 'engine' => 1],
        'app_download'     => ['version' => 1, 'engine' => 1],

        // Storytelling and utility — inline payloads, plus the authed coupon list.
        'stories'         => ['version' => 1, 'engine' => 1],
        'branches'        => ['version' => 1, 'engine' => 1],
        'before_after'    => ['version' => 1, 'engine' => 1],
        'shipping_cutoff' => ['version' => 1, 'engine' => 1],
        'coupon_strip'    => ['version' => 1, 'engine' => 1],
        'custom_html'     => ['version' => 1, 'engine' => 1],

        // Layout primitives.
        'spacer' => ['version' => 1, 'engine' => 1],
    ];

    /**
     * Section types deliberately withheld from the app, and why — read by the builder's
     * diagnostics so an absent rail is a stated decision rather than a mystery.
     *
     * @var array<string, string>
     */
    private const APP_EXCLUSIONS = [
        'recently_viewed'   => 'backed_by_the_web_visitor_cookie_no_app_equivalent',
        'blog_posts'        => 'the_blog_module_exposes_no_public_api',
        'newsletter'        => 'the_app_has_no_newsletter_signup_surface',
        'footer_columns'    => 'the_app_draws_its_own_navigation_chrome',
        'trending_searches' => 'no_public_endpoint_for_search_terms',
        'vendor_showcase'   => 'needs_a_by_id_shop_products_endpoint_the_api_only_serves_by_slug',
    ];

    /** Whether the app has any renderer for this type at all. */
    public function isAppSafe(string $type): bool
    {
        return array_key_exists($type, self::APP_COMPONENTS);
    }

    /** @return array{version: int, engine: int}|null */
    public function requirementFor(string $type): ?array
    {
        return self::APP_COMPONENTS[$type] ?? null;
    }

    /** Why a type is withheld from the app, when it is; null for a type the app can draw. */
    public function exclusionReason(string $type): ?string
    {
        if ($this->isAppSafe($type)) {
            return null;
        }

        return self::APP_EXCLUSIONS[$type] ?? 'no_app_renderer_exists_for_this_section';
    }

    /**
     * The types this viewer may be sent — the intersection of what the app ships and what this
     * particular client says it holds.
     *
     * @return array<int, string>
     */
    public function servableTo(ViewerContext $viewer): array
    {
        return $this->servableToChannel($viewer->channel(), $viewer);
    }

    /**
     * What one channel can draw, for this viewer.
     *
     * Keyed by channel rather than by platform so a third surface is an entry here instead of a
     * branch: the web ships with the server and can draw whatever the registry composes; the
     * customer app draws what its build declares; the vendor app declares nothing yet, and an
     * empty answer is the honest one — it renders no sections rather than being sent sections it
     * cannot draw.
     *
     * @return array<int, string>
     */
    public function servableToChannel(string $channel, ?ViewerContext $viewer = null): array
    {
        if ($channel === Channel::WEB) {
            return array_keys(app(SectionRegistry::class)->types());
        }

        if ($channel === Channel::VENDOR_APP) {
            return [];
        }

        $viewer ??= new ViewerContext(platform: ViewerContext::PLATFORM_APP);

        return array_values(array_filter(
            array_keys(self::APP_COMPONENTS),
            fn (string $type) => $this->clientHolds($type, $viewer),
        ));
    }

    /**
     * Which channels can draw a type at all, ignoring any one build's declaration.
     *
     * This is what the builder needs to say "the app will not show this" before anything is
     * published, and what a section's channel picker offers.
     *
     * @return array<int, string>
     */
    public function channelsFor(string $type): array
    {
        $channels = [Channel::WEB];

        if (isset(self::APP_COMPONENTS[$type])) {
            $channels[] = Channel::CUSTOMER_APP;
        }

        return $channels;
    }

    /**
     * Whether one client can be sent one type.
     *
     * Three independent gates: the app must have a renderer at all, this build's engine must be
     * new enough for it, and — when the client bothered to say — it must list the type.
     */
    public function clientHolds(string $type, ViewerContext $viewer): bool
    {
        $requirement = $this->requirementFor($type);
        if ($requirement === null) {
            return false;
        }

        // A build that reports an engine older than the component needs cannot draw it, even if
        // it lists the type. A build that declares COMPONENTS but no engine is a capability-era
        // build with engine 1 — not one exempt from the gate; only the truly pre-capability build
        // (no components either) keeps the everything-app-safe legacy path above.
        $engine = $viewer->uiEngineVersion > 0
            ? $viewer->uiEngineVersion
            : ($viewer->supportedComponents !== [] ? 1 : 0);

        if ($engine > 0 && $engine < $requirement['engine']) {
            return false;
        }

        return $viewer->canRender($type);
    }

    /**
     * The full component manifest, for the Developer Portal and the builder's diagnostics panel.
     *
     * @return array<int, array{type: string, app: bool, version: ?int, engine: ?int, reason: ?string}>
     */
    public function manifest(): array
    {
        $rows = [];

        foreach (array_keys(app(SectionRegistry::class)->types()) as $type) {
            $requirement = $this->requirementFor($type);

            $rows[] = [
                'type'    => $type,
                'app'     => $requirement !== null,
                'version' => $requirement['version'] ?? null,
                'engine'  => $requirement['engine'] ?? null,
                'reason'  => $requirement === null
                    ? (self::APP_EXCLUSIONS[$type] ?? 'no_app_renderer_exists_for_this_section')
                    : null,
            ];
        }

        return $rows;
    }
}
