<?php

namespace App\Services\Analytics\Support;

/**
 * Where a campaign short link is allowed to send somebody.
 *
 * This is an ALLOW-LIST, and that polarity is the whole point. The project already has
 * OutboundUrlGuard, which looks like the obvious thing to reuse and is exactly the wrong tool: it
 * is an SSRF guard for outbound fetches, so it permits every publicly-resolving host. Routing a
 * redirect through it would ship an open redirect — /go/abc sending a customer to a phishing page
 * that arrives carrying this shop's name in the referrer.
 *
 * So the destination is checked against a short list of hosts an administrator has approved, at
 * CREATION time — the redirect endpoint then only ever sends people to a URL that was already
 * validated, never to one supplied in the request. It is re-checked at click time as well, because
 * a row can be edited directly in the database and an allow-list can be narrowed after the fact.
 */
class CampaignDestination
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * @return array{allowed: bool, reason: ?string, url: ?string, host: ?string}
     */
    public function check(?string $url): array
    {
        $url = trim((string) $url);

        if ($url === '') {
            return $this->deny('a_destination_url_is_required');
        }

        if (mb_strlen($url) > 2000) {
            return $this->deny('that_url_is_too_long');
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $this->deny('that_is_not_a_complete_url_include_https_and_the_domain');
        }

        if (!in_array(strtolower((string) $parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            // javascript:, data: and file: are the classic redirect escalations.
            return $this->deny('only_http_and_https_links_are_allowed');
        }

        // Credentials in a URL are a parser-confusion trick: https://shop.com@evil.net reads as
        // shop.com to a human and resolves to evil.net in a browser.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $this->deny('a_destination_url_may_not_contain_a_username_or_password');
        }

        $host = $this->normaliseHost((string) $parts['host']);

        // Exact equality, never str_contains or str_starts_with: shop-com.evil.net and
        // shop.com.evil.net both pass a naive substring test against "shop.com".
        if (!in_array($host, $this->allowedHosts(), true)) {
            return $this->deny('that_domain_is_not_on_the_allowed_list', $host);
        }

        return ['allowed' => true, 'reason' => null, 'url' => $url, 'host' => $host];
    }

    /**
     * The hosts a campaign may point at: this shop, plus anything an administrator added.
     *
     * @return array<int, string>
     */
    public function allowedHosts(): array
    {
        $hosts = [];

        $own = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($own) && $own !== '') {
            $hosts[] = $this->normaliseHost($own);
        }

        foreach ((array) config('analytics.campaigns.extra_allowed_hosts', []) as $host) {
            $host = $this->normaliseHost(trim((string) $host));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * Add the campaign's UTM parameters to its destination.
     *
     * Existing query parameters are preserved and the UTMs win on a clash, because the campaign's
     * own tagging is the thing being measured — a stale utm_source left on a pasted URL would
     * silently attribute the campaign to whatever it said.
     *
     * @param  array<string, string|null>  $utm
     */
    public function withUtm(string $url, array $utm): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);

        foreach ($utm as $key => $value) {
            if (is_string($value) && $value !== '') {
                $query[$key] = $value;
            }
        }

        $rebuilt = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '');

        if ($query !== []) {
            $rebuilt .= '?' . http_build_query($query);
        }

        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    private function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B."));

        // A leading www. is the same site to a person and a different string to in_array, which is
        // how an allow-list ends up rejecting the shop's own address.
        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * @return array{allowed: bool, reason: string, url: null, host: ?string}
     */
    private function deny(string $reason, ?string $host = null): array
    {
        return ['allowed' => false, 'reason' => $reason, 'url' => null, 'host' => $host];
    }
}
