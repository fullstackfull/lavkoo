<?php

namespace App\Services\DeepLink;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * One place that knows how a web URL becomes an app link, and what a campaign has to carry to
 * survive the trip.
 *
 * The project already had two halves of this and no join between them. One half is the deep-link
 * setup screen: an administrator enters the Android package and the iOS bundle, and the app
 * association files get written so a tap on a shop URL opens the app instead of the browser. The
 * other half is campaigns: a short link that tags a visit with its source. A customer who tapped an
 * Instagram campaign on a phone got the browser, and if they then installed the app from the
 * download banner the install was attributed to nobody, because the store link was a bare URL.
 *
 * This service is the join. It answers three questions:
 *
 *   - which paths are published as app links, so /go/{code} can be one of them;
 *   - what the store URL should be for a visitor who arrived on a campaign, so the attribution
 *     survives the install (Play Install Referrer on Android, the campaign token on iOS);
 *   - whether any of this is configured at all, so a screen can say "not configured" instead of
 *     showing a link that silently does nothing.
 *
 * Everything here degrades to the plain behaviour when the deep-link setup is empty: the store URL
 * comes back exactly as the administrator entered it.
 */
class AppLinkService
{
    public const PLATFORM_ANDROID = 'android';
    public const PLATFORM_IOS = 'ios';

    /**
     * Hosts whose URLs carry install attribution, and which parameter carries it.
     *
     * Attribution is only ever appended to a store URL we recognise. An administrator can put any
     * URL in that field — a landing page, a shortener, a partner link — and quietly rewriting the
     * query of a URL we do not understand would at best do nothing and at worst break it.
     */
    private const ANDROID_STORE_HOSTS = ['play.google.com'];
    private const IOS_STORE_HOSTS = ['apps.apple.com', 'itunes.apple.com'];

    /** @var array<string, mixed>|null */
    private ?array $settings = null;

    /**
     * The stored deep-link setup, read once per request.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $stored = [];

        try {
            if (Schema::hasTable('business_settings')) {
                $stored = (array) (getWebConfig(name: 'app_deep_link') ?? []);
            }
        } catch (\Throwable) {
            // A deployment mid-migration must not take the storefront down over a download banner.
        }

        return $this->settings = $stored;
    }

    /** The pattern campaign short links are served at, whatever prefix this deployment configured. */
    public function campaignPathPattern(): string
    {
        return '/' . trim((string) config('analytics.campaigns.path', 'go'), '/') . '/*';
    }

    public function isConfigured(string $platform): bool
    {
        $settings = $this->settings();

        return $platform === self::PLATFORM_ANDROID
            ? !empty($settings['android_package_name']) && !empty($settings['playstore_redirect_url'])
            : !empty($settings['ios_bundle_id']) && !empty($settings['app_store_redirect_url']);
    }

    public function isConfiguredForAnyPlatform(): bool
    {
        return $this->isConfigured(self::PLATFORM_ANDROID) || $this->isConfigured(self::PLATFORM_IOS);
    }

    public function packageName(): ?string
    {
        return $this->nonEmpty($this->settings()['android_package_name'] ?? null);
    }

    public function bundleId(): ?string
    {
        return $this->nonEmpty($this->settings()['ios_bundle_id'] ?? null);
    }

    /**
     * The paths published as app links for a platform.
     *
     * iOS reads these out of the association file, so the list is authoritative there: a path that
     * is not in it opens the browser. Android verifies the whole host and filters paths in the
     * app's own intent filters, so the Android list is what the app team has to mirror — which is
     * exactly why it is worth publishing rather than leaving in somebody's notes.
     *
     * @return array<int, string>
     */
    public function paths(string $platform): array
    {
        $key = $platform === self::PLATFORM_ANDROID ? 'deeplinks.android_paths' : 'deeplinks.ios_paths';
        $paths = array_values(array_filter(array_map(
            static fn ($path) => trim((string) $path),
            (array) config($key, [])
        )));

        // The campaign prefix is configurable, and the shipped list names the default. A deployment
        // that changed ANALYTICS_CAMPAIGN_PATH would otherwise publish /go/* — a path it does not
        // serve — while its real short links opened the browser.
        $campaign = $this->campaignPathPattern();
        if (!in_array($campaign, $paths, true)) {
            $paths[] = $campaign;
        }

        return array_values(array_unique($paths));
    }

    /**
     * Does this path open the app?
     *
     * The patterns are the association file's own syntax — a trailing * matches any suffix — so
     * this answers with the same rule the phone will apply rather than an approximation of it.
     */
    public function opensTheApp(string $path, string $platform = self::PLATFORM_IOS): bool
    {
        $path = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?? $path, '/');

        foreach ($this->paths($platform) as $pattern) {
            if ($pattern === '*' || $pattern === $path) {
                return true;
            }

            if (str_ends_with($pattern, '*') && str_starts_with($path, rtrim($pattern, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The store link for a visitor, carrying whatever attribution this visit already has.
     *
     * Android: the Play Install Referrer. Play hands the referrer string to the app on its first
     * launch, which is the only way an install can be attributed to the campaign that caused it —
     * the app is a fresh install with no cookie and no session to inherit.
     *
     * iOS: the App Store campaign token, which arrives in App Analytics rather than in the app, so
     * it attributes the install for the merchant without the app having to do anything.
     *
     * @param  array<string, string|null>  $attribution
     */
    public function storeUrl(string $platform, array $attribution = []): ?string
    {
        $settings = $this->settings();

        $url = $platform === self::PLATFORM_ANDROID
            ? $this->nonEmpty($settings['playstore_redirect_url'] ?? null)
            : $this->nonEmpty($settings['app_store_redirect_url'] ?? null);

        if ($url === null) {
            return null;
        }

        $attribution = $this->cleanAttribution($attribution);

        if ($attribution === []) {
            return $url;
        }

        return $platform === self::PLATFORM_ANDROID
            ? $this->withPlayReferrer($url, $attribution)
            : $this->withAppStoreCampaign($url, $attribution);
    }

    /**
     * What this visit should tell the store it came from.
     *
     * Read from the request rather than passed in, because the caller is usually a layout that has
     * no idea a campaign exists. The campaign cookie is preferred over the query string: it was set
     * by the redirect endpoint from a row an administrator created, while the query string is
     * whatever the URL happens to say.
     *
     * @return array<string, string>
     */
    public function attributionFromRequest(Request $request): array
    {
        $attribution = [];

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                $attribution[$key] = $value;
            }
        }

        $code = $request->cookie(\App\Services\Analytics\VisitorContext::CAMPAIGN_COOKIE)
            ?: $request->attributes->get('analytics_campaign_code');

        if (is_string($code) && $code !== '') {
            $attribution['campaign_code'] = $code;
        }

        return $this->cleanAttribution($attribution);
    }

    /**
     * @param  array<string, string|null>  $attribution
     * @return array<string, string>
     */
    private function cleanAttribution(array $attribution): array
    {
        $clean = [];

        foreach ($attribution as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            // Control characters and separators would break out of the referrer string the store
            // hands to the app, and length is capped because Play truncates a long referrer and
            // Apple rejects a campaign token over 40 characters.
            $value = trim(preg_replace('/[^\p{L}\p{N}\-_.\s]/u', '', $value) ?? '');

            if ($value !== '') {
                $clean[$key] = mb_substr($value, 0, 64);
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, string>  $attribution
     */
    private function withPlayReferrer(string $url, array $attribution): string
    {
        if (!$this->hostIsOneOf($url, self::ANDROID_STORE_HOSTS)) {
            return $url;
        }

        // The referrer is itself a query string, url-encoded whole into one parameter. Play caps it
        // at a few hundred characters, so an over-long one is dropped rather than truncated.
        $referrer = http_build_query($attribution, '', '&', PHP_QUERY_RFC3986);

        if ($referrer === '' || strlen($referrer) > 500) {
            return $url;
        }

        return $this->withQuery($url, ['referrer' => $referrer]);
    }

    /**
     * @param  array<string, string>  $attribution
     */
    private function withAppStoreCampaign(string $url, array $attribution): string
    {
        if (!$this->hostIsOneOf($url, self::IOS_STORE_HOSTS)) {
            return $url;
        }

        // Apple takes one campaign token, not a set of parameters. The campaign's own code is the
        // most specific thing we have; its name is the fallback.
        $token = $attribution['campaign_code'] ?? $attribution['utm_campaign'] ?? null;

        if ($token === null) {
            return $url;
        }

        return $this->withQuery($url, ['ct' => mb_substr($token, 0, 40)]);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function withQuery(string $url, array $extra): string
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);

        // The campaign's parameters win: a store URL pasted with a stale referrer would otherwise
        // attribute every install to whatever campaign was running when it was pasted.
        $query = array_merge($query, $extra);

        $rebuilt = ($parts['scheme'] ?? 'https') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '');

        if ($query !== []) {
            $rebuilt .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * @param  array<int, string>  $hosts
     */
    private function hostIsOneOf(string $url, array $hosts): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host)) {
            return false;
        }

        $host = strtolower(ltrim($host, '.'));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return in_array($host, $hosts, true);
    }

    private function nonEmpty(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
