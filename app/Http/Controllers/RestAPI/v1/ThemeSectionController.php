<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Theme\Channel;
use App\Services\Theme\ExperiencePageService;
use App\Services\Theme\SectionDataResolver;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeDelivery;
use App\Services\Theme\ThemePreviewToken;
use App\Services\Theme\ThemeSourceMap;
use App\Services\Theme\ViewerContext;
use App\Services\VendorSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The published theme, for a client that is not the web storefront.
 *
 * The theme builder, Banner Setup and the storefront are already one system on the web:
 * StorefrontThemeRenderer serves the PUBLISHED version, and blockCards() resolves every
 * banner-backed block against its Promotion → Banners row live — an edit in Banner Setup shows on
 * the storefront without touching the theme, and unpublishing a banner hides its tile. The mobile
 * app had no way into that pipeline, so integrators queried theme_sections by hand: a hardcoded
 * version id that goes stale on the next publish, no publish-state checks, no banner overrides,
 * and a query per block.
 *
 * This endpoint IS that pipeline. Whatever the merchant publishes in the builder is what it
 * answers, section order and visibility included; whatever they edit in Banner Setup is live in
 * it, exactly as on the web. It adds only what a phone needs and a blade does not: image URLs are
 * made absolute, because the app has no origin to resolve `/storage/...` against.
 */
class ThemeSectionController extends Controller
{
    /**
     * The pages the engine guarantees, used when the page table has not been migrated yet.
     *
     * The real list comes from {@see ExperiencePageService}: a merchant who adds a page has to be
     * able to fetch it, and a list written into this file could never know about one.
     */
    private const GUARANTEED_PAGES = ['home', 'header', 'footer'];

    /** Settings keys whose value is an image path a phone must be able to fetch. */
    private const IMAGE_KEYS = ['image', 'image_2', 'image_3', 'image_mobile', 'background_image', 'logo', 'icon_image', 'after'];

    public function __construct(
        private readonly StorefrontThemeRenderer $renderer,
        private readonly SectionDataResolver $resolver,
        private readonly ThemeSourceMap $sources,
        private readonly ThemeDelivery $delivery,
        private readonly ThemePreviewToken $previews,
        private readonly ExperiencePageService $pages,
        private readonly VendorSummaryService $vendorSummary,
    ) {
    }

    /**
     * The page this request may be served, or the home page.
     *
     * An unknown name falls back rather than erroring: this is a public endpoint, and `?page[]=x`
     * or a typo must never be a 500. A page that exists but is turned off is unknown too — turning
     * one off is how a merchant takes it out of the app.
     */
    /**
     * The page this channel may be served, or null when the request names one it may not.
     *
     * A malformed page parameter still falls back to home — `?page[]=x` is noise, not a request
     * for a page. But a WELL-FORMED slug that is unknown, disabled, or another channel's is a
     * page this client must not have, and answering it with the HOME payload put the home page
     * inside the app's "Offers" screen. The honest answer is a 404 the client renders as such.
     */
    private function servablePage(Request $request, ViewerContext $viewer): ?string
    {
        $requested = $request->query('page', 'home');

        if (!is_string($requested) || $requested === '') {
            return 'home';
        }

        $theme = \App\Models\Theme::query()->where('is_active', true)->value('id');

        $allowed = $theme === null
            ? self::GUARANTEED_PAGES
            : $this->pages->servableSlugs((int) $theme, $viewer->channel() ?? Channel::CUSTOMER_APP);

        if ($allowed === []) {
            $allowed = self::GUARANTEED_PAGES;
        }

        return in_array($requested, $allowed, true) ? $requested : null;
    }

    #[ApiDoc(
        summary: 'The published theme page, with every banner-backed block resolved live',
        description: 'One request per page area (home, header, footer), optionally narrowed to one '
            . 'section type: /api/v1/theme/sections?page=home&type=banner_mosaic. '
            . 'Serves the PUBLISHED theme version — never a draft, never a version id the caller names. '
            . 'Each section carries its normalized `settings`, its visible `blocks`, and — for sections '
            . 'whose blocks are banner-backed (mosaic tiles, slides, banners, splits) — render-ready '
            . '`cards`: the block merged with its linked Promotion → Banners row, so an edit or '
            . 'unpublish in Banner Setup changes this response without any theme change. Manage the '
            . 'images in Admin → Promotion → Banner Setup (builder uploads register themselves there '
            . 'as "Theme Banner" rows). All image URLs are absolute, and responsive settings arrive '
            . 'resolved for the mobile breakpoint. Every banner section offers a swipeable row, '
            . 'read from a different key per section: banner_mosaic uses `settings.display` '
            . '("grid" or "swipe"), promotional_banner uses `settings.style` ("tiles", "rail", '
            . '"overlap" or "swipe"), store_banner uses `settings.layout` ("grid", "split", '
            . '"strip" or "swipe"). In swipe mode render one horizontally swipeable row of cards '
            . 'sized against `settings.height` (px, default 240); in grid mode render a wall. A '
            . "card's `span` names its shape — small/square 1x1, wide 2x1, tall 1x2, large 2x2, "
            . 'strip full-width — which sets its cell in the wall and its width in the row. Any '
            . 'card of any banner section may carry more than one picture: `images` is the ordered '
            . 'frame list (`image` is always frames[0]), and a card with two or more frames '
            . 'crossfades through them every `settings.rotate_ms` (ms, default 4000, floor 1500). Every card carries a structured `target` — what tapping it opens: '
            . '{kind: product|category|brand|shop, id, slug, name} resolved from the linked banner\'s '
            . 'own resource picker or from the link URL; {kind: url} for a link that matches no '
            . 'catalogue shape (feed it to the deep-link resolve API); {kind: none} for a tile that '
            . 'opens nothing. Each section also carries `source` — where its DATA '
            . 'lives: `inline` (everything is in this payload), `api` (fetch the named v1 endpoint with '
            . 'the given params, plus the standard guest_id/token the app already sends), or `none` '
            . '(no public API feeds it yet; the note says why — hide that section). `sections` is empty '
            . 'when the merchant has not published a theme for that page — render the app\'s default '
            . 'layout then.',
        audience: ApiDoc::CUSTOMER_APP,
        visibility: ApiDoc::PARTNER_VISIBLE,
        stability: ApiDoc::STABLE,
        since: 'v1',
    )]
    public function sections(Request $request): JsonResponse
    {
        // The house rule for request input: a value nobody can spell is not a filter. `?page[]=x`
        // must fall back, never 500 a public endpoint — while a real slug this channel cannot be
        // served is a 404, never somebody else's page.
        $page = $this->servablePage($request, ViewerContext::fromRequest($request));

        if ($page === null) {
            return response()->json(['errors' => [['code' => 'page', 'message' => 'page_not_found']]], 404);
        }

        $type = $request->query('type');
        $type = is_string($type) && $type !== '' ? $type : null;

        $sections = $this->renderer->sectionsFor($page) ?? [];

        if ($type !== null) {
            $sections = array_values(array_filter($sections, fn (array $section) => $section['type'] === $type));
        }

        return response()->json([
            'page' => $page,
            'sections' => array_map(fn (array $section) => $this->present($section), $sections),
        ]);
    }

    #[ApiDoc(
        summary: 'The published home page, negotiated against what this client can actually draw',
        description: 'The versioned delivery endpoint: /api/v1/theme/home?page=home&device=mobile. '
            . 'Unlike /theme/sections it carries the contract a client syncs against — `revision` '
            . '(monotonic, one step per publish), `checksum` (the ETag), `schema_version` and '
            . '`engine_version` — plus the theme\'s design `tokens` and a `compatibility` block '
            . 'naming any section type withheld from this build and why. '
            . 'Send `X-UI-Components` (comma-separated section types this build can render) and '
            . '`X-UI-Engine` (renderer generation) to negotiate; a client that sends neither is '
            . 'served every app-safe section, so builds predating capability reporting keep working. '
            . 'Send `If-None-Match` with the last checksum to get 304 and no body. '
            . 'Sections carry `source` (where to fetch their data), `cards` (banner-backed blocks '
            . 'resolved live from Banner Setup) and typed `action` objects beside every link, so a '
            . 'tap opens a native screen instead of a browser. '
            . '`revision: 0` means no theme is published — render the built-in home page. '
            . 'Send `preview` (a signed token minted in the admin builder) to render an unpublished '
            . 'draft instead: the response carries `preview: true`, is never cached and never '
            . 'ETagged, and the token expires on its own. An absent, expired or tampered token is '
            . 'ignored and the published page is served, so a stale preview link degrades into the '
            . 'ordinary home page rather than an error.',
        audience: ApiDoc::CUSTOMER_APP,
        visibility: ApiDoc::PARTNER_VISIBLE,
        stability: ApiDoc::STABLE,
        since: 'v1',
    )]
    public function home(Request $request): JsonResponse
    {
        $viewer = ViewerContext::fromRequest($request);
        $page = $this->servablePage($request, $viewer);

        if ($page === null) {
            return response()->json(['errors' => [['code' => 'page', 'message' => 'page_not_found']]], 404);
        }

        // A merchant checking a draft on a real phone before it is anyone else's home page. The
        // preview path shares no cache and no validator with the published one: a draft changes on
        // every save, and a 304 against a shopper's checksum would hand them the draft.
        $previewing = $this->previews->version($request->query('preview'));

        if ($previewing !== null) {
            return response()
                ->json($this->delivery->previewPayload($previewing, $page, $viewer))
                ->header('Cache-Control', 'no-store, private');
        }

        $payload = $this->delivery->payload($page, $viewer);

        $etag = $payload['checksum'] !== null ? '"' . $payload['checksum'] . '"' : null;

        // The payload varies by everything the fingerprint varies by; any shared HTTP cache in
        // front of this endpoint must key on the same headers or one build's page becomes
        // another's.
        $vary = 'X-UI-Components, X-UI-Engine, X-UI-Schema, X-UI-Channel, X-Platform, lang, Authorization';

        // A client that already holds this exact page is told so and sent nothing. This is the
        // difference between a resume costing a header and costing the whole home page, on every
        // resume of every installed app.
        if ($etag !== null && $this->matchesEtag($request, $etag)) {
            return response()->json(null, 304)->setEtag($payload['checksum'])->header('Vary', $vary);
        }

        $response = response()->json($payload)->header('Vary', $vary);

        return $etag !== null ? $response->setEtag($payload['checksum']) : $response;
    }

    #[ApiDoc(
        summary: 'Whether the published theme has changed, without downloading it',
        description: 'The cheap half of the sync: /api/v1/theme/version returns `revision`, '
            . '`checksum`, `schema_version`, `engine_version` and `published_at` and nothing else. '
            . 'A client holding revision N calls this on cold start and resume; only a higher '
            . 'revision (or a different checksum) is worth a call to /theme/home. '
            . '`revision: 0` means nothing has ever been published.',
        audience: ApiDoc::CUSTOMER_APP,
        visibility: ApiDoc::PARTNER_VISIBLE,
        stability: ApiDoc::STABLE,
        since: 'v1',
    )]
    public function version(): JsonResponse
    {
        return response()->json($this->delivery->revision());
    }

    /**
     * Whether the client already holds this exact page.
     *
     * `If-None-Match` may carry several validators and a weak prefix; a substring test over the
     * raw header is what keeps a proxy-rewritten `W/"abc"` from being read as a miss and costing
     * the download the header exists to avoid.
     */
    private function matchesEtag(Request $request, string $etag): bool
    {
        $header = $request->header('If-None-Match');
        if (!is_string($header) || $header === '') {
            return false;
        }

        return $header === '*' || str_contains($header, trim($etag, '"'));
    }

    /**
     * One section as the app renders it.
     *
     * `cards` exists only where the web renders cards — sections whose blocks are banner-backed —
     * and comes from the SAME resolver the storefront uses, so the two can never disagree about
     * which tile shows or which image it shows.
     *
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function present(array $section): array
    {
        $blocks = $section['blocks'] ?? [];
        $bannerBacked = array_intersect(
            array_column($blocks, 'type'),
            SectionRegistry::BANNER_BACKED_BLOCK_TYPES,
        ) !== [];

        return [
            'id' => $section['id'],
            'type' => $section['type'],
            'settings' => $this->absolutize(
                $this->withResolved($section['type'], $this->forPhone($section['settings'] ?? []))),
            'blocks' => array_map(fn (array $block) => [
                'id' => $block['id'],
                'type' => $block['type'],
                'settings' => $this->absolutize($this->forPhone($block['settings'] ?? [])),
            ], $blocks),
            'cards' => $this->cardsFor($section, $blocks, $bannerBacked),
            'source' => $this->sources->for($section['type'], $section['settings'] ?? [], $blocks),
        ];
    }

    /**
     * Settings a phone cannot work out for itself.
     *
     * A showcase stores the shop as an id, and every fact a card needs about that shop — its name,
     * its mark, its cover, what its buyers rate it — lives behind a lookup the app has no endpoint
     * for. Rather than invent one, the shop travels with the section, as the customer-safe summary
     * every other vendor payload already uses; nothing private crosses over.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */












    private function withResolved(string $type, array $settings): array
    {
        if ($type !== 'vendor_showcase') {
            return $settings;
        }

        $showcase = $this->resolver->vendorShowcase($settings);

        return $showcase === null
            ? $settings
            : $settings + ['shop' => $this->vendorSummary->summarizeShop($showcase['shop'])];
    }

    /**
     * The same cards /theme/home serves: block-backed sections resolve their blocks; store_banner
     * resolves its Banner Setup rows LIVE (its promise in the API doc, previously kept only by
     * the home endpoint); banner_strip's single banner is its own settings.
     *
     * @param  array<string, mixed>  $section
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>|null
     */
    private function cardsFor(array $section, array $blocks, bool $bannerBacked): ?array
    {
        $settings = $section['settings'] ?? [];

        if (($section['type'] ?? null) === 'store_banner') {
            return array_map(fn (array $card) => $this->absolutize($card), $this->resolver->dashboardBanners(
                (string) ($settings['banner_type'] ?? 'Main Banner'),
                max(1, (int) ($settings['limit'] ?? 6)),
            ));
        }

        if (($section['type'] ?? null) === 'banner_strip' && trim((string) ($settings['image'] ?? '')) !== '') {
            return [$this->absolutize(array_filter([
                'type' => 'banner', 'image' => $settings['image'],
                'eyebrow' => $settings['eyebrow'] ?? null, 'title' => $settings['title'] ?? null,
                'subtitle' => $settings['subtitle'] ?? null, 'link' => $settings['link'] ?? null,
                'button_text' => $settings['button_text'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''))];
        }

        return $bannerBacked
            ? array_map(fn (array $card) => $this->absolutize($card), $this->resolver->blockCards($blocks, withTargets: true))
            : null;
    }

    /**
     * Responsive settings, resolved for the client this endpoint serves.
     *
     * The builder stores per-breakpoint values as `<key>_mobile` / `<key>_tablet` siblings and the
     * web picks at render time. This endpoint has exactly one breakpoint, so the mobile value wins
     * in place; the siblings stay in the payload for a client that wants the full picture.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function forPhone(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (str_ends_with($key, '_mobile') && $value !== null && $value !== '') {
                $settings[substr($key, 0, -7)] = $value;
            }
        }

        return $settings;
    }

    /**
     * Image paths a browser resolves against the page origin, made whole for a client that has none.
     *
     * Only the known image keys are touched, and only when they hold a root-relative path — a full
     * URL, a data URI or ordinary text passes through untouched.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function absolutize(array $settings): array
    {
        foreach (self::IMAGE_KEYS as $key) {
            $value = $settings[$key] ?? null;

            if (is_string($value) && str_starts_with($value, '/')) {
                $settings[$key] = url($value);
            }
        }

        // A multi-frame tile's whole frame list, same rule as its lead image.
        if (isset($settings['images']) && is_array($settings['images'])) {
            $settings['images'] = array_map(
                fn ($frame) => is_string($frame) && str_starts_with($frame, '/') ? url($frame) : $frame,
                $settings['images'],
            );
        }

        return $settings;
    }
}