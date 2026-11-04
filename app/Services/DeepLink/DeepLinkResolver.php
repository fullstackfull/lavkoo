<?php

namespace App\Services\DeepLink;

use App\Models\Brand;
use App\Services\Analytics\CampaignService;
use Illuminate\Support\Str;

/**
 * Turns a shop URL into the screen the app should open.
 *
 * The app receives a URL — from a universal link, a push notification, a pasted message — and has
 * to decide what to show. Without this it has to parse the shop's URL structure itself, which means
 * the routing rules exist in three codebases and drift in two of them. Here the shop answers, from
 * the same config/deeplinks.php list that decides which paths the association files publish, so a
 * path that opens the app and a path the app knows how to route are the same list by construction.
 *
 * A campaign short link is resolved the whole way: /go/{code} comes back as the destination it
 * points at, carrying the campaign's attribution, so the app opens the right screen AND the visit
 * is still attributed to the campaign that caused it. That is the part that was missing — the short
 * link and the deep link knew nothing about each other, so a customer who tapped a campaign on a
 * phone with the app installed either got the browser or got the app with no attribution at all.
 */
class DeepLinkResolver
{
    public const CAMPAIGN_TARGET = 'campaign';

    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly AppLinkService $links,
    ) {
    }

    /**
     * @return array{
     *     resolved: bool,
     *     target: string,
     *     parameter: ?string,
     *     subject: ?array{id: int, name: string},
     *     path: string,
     *     web_url: string,
     *     attribution: array<string, string>,
     *     campaign: ?array{code: string, name: string},
     *     reason: ?string
     * }
     */
    public function resolve(string $url): array
    {
        $path = $this->pathOf($url);

        if ($path === null) {
            return $this->unresolved('/', 'that_link_does_not_belong_to_this_shop');
        }

        $match = $this->match($path);

        if ($match !== null && $match['target'] === self::CAMPAIGN_TARGET) {
            return $this->resolveCampaign($path, $url);
        }

        $attribution = $this->utmOf($url);

        if ($match === null) {
            // Not an app path: the app should open it in a web view rather than guess a screen.
            return [
                'resolved' => false,
                'target' => 'web',
                'parameter' => null,
                'subject' => null,
                'path' => $path,
                'web_url' => $this->absolute($path, $url),
                'attribution' => $attribution,
                'campaign' => null,
                'reason' => 'this_path_is_not_published_as_an_app_link',
            ];
        }

        return [
            'resolved' => true,
            'target' => $match['target'],
            'parameter' => $match['parameter'],
            'subject' => $this->subjectFor(target: $match['target'], parameter: $match['parameter']),
            'path' => $path,
            'web_url' => $this->absolute($path, $url),
            'attribution' => $attribution,
            'campaign' => null,
            'reason' => null,
        ];
    }

    /**
     * A short link, followed all the way to the screen it points at.
     *
     * @return array<string, mixed>
     */
    private function resolveCampaign(string $path, string $url): array
    {
        $code = trim(Str::after($path, '/go/'), '/');
        $resolved = $this->campaigns->resolve($code);

        if (!($resolved['ok'] ?? false)) {
            // The web redirect sends an unknown code quietly to the home page rather than to an
            // error, and the app has to behave the same way or a mistyped poster becomes a crash.
            return [
                'resolved' => true,
                'target' => 'home',
                'parameter' => null,
                'subject' => null,
                'path' => '/',
                'web_url' => rtrim((string) config('app.url'), '/') . '/',
                'attribution' => [],
                'campaign' => null,
                'reason' => $resolved['reason'] ?? 'unknown_code',
            ];
        }

        $destination = (string) $resolved['url'];
        $destinationPath = $this->pathOf($destination) ?? '/';
        $match = $this->match($destinationPath);
        $campaign = $resolved['campaign'];

        $attribution = $this->utmOf($destination);
        // The campaign's own code, not the one that was typed: a code read off a poster in capitals
        // resolves, and the attribution stored for it has to match the one on the campaign row.
        $attribution['campaign_code'] = (string) $campaign->code;

        return [
            'resolved' => true,
            'target' => $match['target'] ?? 'web',
            'parameter' => $match['parameter'] ?? null,
            'subject' => $this->subjectFor(
                target: (string) ($match['target'] ?? 'web'),
                parameter: $match['parameter'] ?? null,
            ),
            'path' => $destinationPath,
            'web_url' => $destination,
            'attribution' => $attribution,
            'campaign' => ['code' => (string) $campaign->code, 'name' => (string) $campaign->name],
            'reason' => null,
        ];
    }

    /**
     * The campaign a code belongs to, for the click record. Null when it does not resolve.
     */
    public function campaignForCode(string $code): ?object
    {
        $resolved = $this->campaigns->resolve($code);

        return ($resolved['ok'] ?? false) ? $resolved['campaign'] : null;
    }

    /**
     * Match a path against the published patterns, and pull out the parameter the app needs.
     *
     * @return array{target: string, parameter: ?string}|null
     */
    private function match(string $path): ?array
    {
        foreach ((array) config('deeplinks.enabled_routes', []) as $route) {
            $pattern = (string) ($route['path'] ?? '');

            if ($pattern === '') {
                continue;
            }

            if ($pattern === $path) {
                return ['target' => (string) $route['target'], 'parameter' => null];
            }

            if (str_ends_with($pattern, '*')) {
                $prefix = rtrim($pattern, '*');

                if ($prefix !== '/' && str_starts_with($path, $prefix)) {
                    $parameter = trim(substr($path, strlen($prefix)), '/');

                    return [
                        'target' => (string) $route['target'],
                        'parameter' => $parameter === '' ? null : $parameter,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * The path of a URL that belongs to this shop, or null.
     *
     * A URL on another host is refused rather than followed. This method's answer decides what the
     * app opens, so accepting a foreign host here would let a crafted link put an attacker's page
     * inside the shop's own app.
     */
    private function pathOf(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // A bare path is this shop's by definition; a full URL has to prove it.
        if (str_starts_with($url, '/')) {
            return '/' . ltrim((string) (parse_url($url, PHP_URL_PATH) ?? '/'), '/');
        }

        $host = parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!is_string($host) || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if ($this->normaliseHost($host) !== $this->normaliseHost((string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
            return null;
        }

        return '/' . ltrim((string) (parse_url($url, PHP_URL_PATH) ?? '/'), '/');
    }

    /**
     * @return array<string, string>
     */
    private function utmOf(string $url): array
    {
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);

        $attribution = [];

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $key) {
            if (isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '') {
                $attribution[$key] = $query[$key];
            }
        }

        return $attribution;
    }

    private function absolute(string $path, string $original): string
    {
        if (str_starts_with($original, 'http')) {
            return $original;
        }

        return rtrim((string) config('app.url'), '/') . $path;
    }

    private function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host, '. '));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @return array<string, mixed>
     */
    private function unresolved(string $path, string $reason): array
    {
        return [
            'resolved' => false,
            'target' => 'web',
            'parameter' => null,
            'subject' => null,
            'path' => $path,
            'web_url' => rtrim((string) config('app.url'), '/') . $path,
            'attribution' => [],
            'campaign' => null,
            'reason' => $reason,
        ];
    }

    /**
     * The parameter, resolved to what the app's screen actually opens with.
     *
     * A brand link carries a slug because that is what the web URL holds, but the app's brand
     * screen opens by id and the app has no slug index to look one up in. Answering with the id
     * here keeps this shop the only codebase that knows its own URL structure. Null when there is
     * nothing to hydrate — the app then falls back to its brand list or the web URL.
     *
     * @return array{id: int, name: string}|null
     */
    private function subjectFor(string $target, ?string $parameter): ?array
    {
        if ($target !== 'brand' || $parameter === null) {
            return null;
        }

        try {
            $brand = Brand::active()->where('slug', $parameter)->first();
        } catch (\Throwable) {
            // A link has to open even when the lookup cannot run.
            return null;
        }

        return $brand === null
            ? null
            : ['id' => (int) $brand->id, 'name' => (string) $brand->name];
    }

    public function appLinks(): AppLinkService
    {
        return $this->links;
    }
}
