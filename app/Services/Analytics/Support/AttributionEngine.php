<?php

namespace App\Services\Analytics\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where did this visit come from?
 *
 * Attribution is the part of analytics most often quietly wrong, because there is no single right
 * answer and every tool picks one silently. This one answers three separate questions and keeps
 * them separate:
 *
 *   FIRST TOUCH   — how this person ever found the shop. Stored on the visitor, written once.
 *   SESSION TOUCH — what brought them back this time. Stored on the session.
 *   LAST TOUCH    — whatever the converting session says, which is the session touch of the visit
 *                   that ordered. No extra storage; it falls out of the model.
 *
 * Collapsing those into "the source" is how an email campaign gets credited with a sale that an
 * advertisement started and a search brought back — and the reason a merchant's own numbers never
 * match the ad platform's.
 *
 * Every decision records WHY, in attribution_basis, so a figure on a screen can always be
 * explained rather than trusted.
 */
class AttributionEngine
{
    /** Search engines, so organic search is not filed as an ordinary referral. */
    private const SEARCH_DOMAINS = [
        'google' => 'google', 'bing' => 'bing', 'yahoo' => 'yahoo', 'duckduckgo' => 'duckduckgo',
        'yandex' => 'yandex', 'baidu' => 'baidu', 'ecosia' => 'ecosia', 'brave' => 'brave',
        'startpage' => 'startpage', 'qwant' => 'qwant',
    ];

    /** Social networks, so a share is not filed as a website linking to us. */
    private const SOCIAL_DOMAINS = [
        'facebook' => 'facebook', 'fb.com' => 'facebook', 'instagram' => 'instagram',
        'twitter' => 'twitter', 't.co' => 'twitter', 'x.com' => 'twitter',
        'linkedin' => 'linkedin', 'lnkd.in' => 'linkedin', 'pinterest' => 'pinterest',
        'tiktok' => 'tiktok', 'youtube' => 'youtube', 'youtu.be' => 'youtube',
        'reddit' => 'reddit', 'snapchat' => 'snapchat', 'telegram' => 'telegram',
        't.me' => 'telegram', 'whatsapp' => 'whatsapp', 'wa.me' => 'whatsapp',
    ];

    /**
     * Mail clients and webmail, which arrive as referrals but are email traffic.
     *
     * Checked BEFORE the search table, because mail.google.com contains "google" and was therefore
     * reported as an organic search — which made the two entries below that say otherwise
     * unreachable, and filed every Gmail click as SEO.
     */
    private const EMAIL_DOMAINS = [
        'mail.google.com', 'mail.yahoo.com', 'outlook.com', 'outlook.live.com', 'outlook.office.com',
        'outlook.office365.com', 'mail.proton.me', 'protonmail.com', 'mail.zoho.com', 'mail.aol.com',
        'mail.yandex.ru', 'roundcube.net',
    ];

    /** Any host whose first label is one of these is somebody's webmail, whatever the domain is. */
    private const EMAIL_PREFIXES = ['mail', 'webmail', 'mail2', 'email'];

    /**
     * Work out the session touch for a request.
     *
     * @param  array<string, mixed>|null  $campaign  the short link this visit arrived through
     * @return array<string, string|null>
     */
    public function resolve(Request $request, ?array $campaign = null): array
    {
        // 1. A short link we issued. The most trustworthy signal there is, because we created it.
        if ($campaign !== null) {
            return [
                'source' => $this->clean($campaign['utm_source'] ?? null, 96),
                'medium' => $this->clean($campaign['utm_medium'] ?? null, 64),
                'campaign' => $this->clean($campaign['utm_campaign'] ?? null, 96),
                'content' => $this->clean($campaign['utm_content'] ?? null, 96),
                'term' => $this->clean($campaign['utm_term'] ?? null, 96),
                'referrer_domain' => $this->referrerDomain($request),
                'campaign_id' => $campaign['id'] ?? null,
                'attribution_basis' => 'campaign_link',
            ];
        }

        // 2. UTM parameters on the URL. Explicit, and set by whoever built the link.
        $utmSource = $this->clean($request->query('utm_source'), 96);
        $utmMedium = $this->clean($request->query('utm_medium'), 64);
        $utmCampaign = $this->clean($request->query('utm_campaign'), 96);

        // Any of the three is enough. Requiring utm_source meant a link carrying only a medium and
        // a campaign — which is what most mail tools and half the ad platforms produce — was filed
        // as direct with the campaign discarded, so the campaign report was missing the traffic it
        // was built to measure.
        if ($utmSource !== null || $utmMedium !== null || $utmCampaign !== null) {
            return [
                // Where the source was not stated, the referrer that carried the link is the
                // honest answer; a link opened from a mail client with no referrer is unknown, and
                // says so rather than claiming to be direct.
                'source' => $utmSource ?? $this->sourceOfReferrer($request) ?? 'unknown',
                'medium' => $utmMedium ?? 'unknown',
                'campaign' => $utmCampaign,
                'content' => $this->clean($request->query('utm_content'), 96),
                'term' => $this->clean($request->query('utm_term'), 96),
                'referrer_domain' => $this->referrerDomain($request),
                'campaign_id' => null,
                'attribution_basis' => 'utm',
            ];
        }

        /*
         * 3. Advertising click identifiers, which arrive without UTMs more often than not.
         *
         * The medium is per-parameter, not a blanket 'cpc'. gclid, gbraid, wbraid and msclkid are
         * only ever minted by an ad platform, so a click carrying one was paid for. fbclid is not:
         * Facebook appends it to EVERY outbound link, including a shop's own organic post, so
         * calling it cpc reported unpaid social as paid clicks and made paid traffic look larger
         * than the ad account it was supposedly bought from. A genuinely paid Facebook click is
         * tagged by the advertiser and has already been caught by the UTM branch above.
         */
        foreach ([
            'gclid' => ['google', 'cpc'],
            'gbraid' => ['google', 'cpc'],
            'wbraid' => ['google', 'cpc'],
            'msclkid' => ['bing', 'cpc'],
            'ttclid' => ['tiktok', 'cpc'],
            'fbclid' => ['facebook', 'social'],
        ] as $parameter => [$source, $medium]) {
            if ($request->query($parameter)) {
                return [
                    'source' => $source,
                    'medium' => $medium,
                    'campaign' => null,
                    'content' => null,
                    'term' => null,
                    'referrer_domain' => $this->referrerDomain($request),
                    'campaign_id' => null,
                    'attribution_basis' => 'click_id',
                ];
            }
        }

        // 4. The referrer, classified.
        $referrer = $this->referrerDomain($request);
        if ($referrer !== null) {
            [$source, $medium] = $this->classify($referrer);

            return [
                'source' => $source,
                'medium' => $medium,
                'campaign' => null,
                'content' => null,
                'term' => null,
                'referrer_domain' => $referrer,
                'campaign_id' => null,
                'attribution_basis' => 'referrer',
            ];
        }

        // 5. Nothing to go on. Called (direct) rather than "unknown" on purpose: it is a real
        //    category — typed, bookmarked, or a referrer the browser withheld — and pretending
        //    otherwise is how "direct" quietly becomes the biggest channel in every report.
        return [
            'source' => '(direct)',
            'medium' => '(none)',
            'campaign' => null,
            'content' => null,
            'term' => null,
            'referrer_domain' => null,
            'campaign_id' => null,
            'attribution_basis' => 'direct',
        ];
    }

    /**
     * The referring host, or null when there is none worth recording.
     *
     * A referrer pointing at this same shop is internal navigation, not a traffic source — the
     * single most common way a self-hosted analytics table ends up saying its own domain is its
     * biggest referrer.
     */
    public function referrerDomain(Request $request): ?string
    {
        $referrer = (string) $request->headers->get('referer');

        if ($referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./', '', $host));
        $ownHost = strtolower(preg_replace('/^www\./', '', (string) $request->getHost()));

        return $host === $ownHost ? null : Str::limit($host, 191, '');
    }

    /**
     * A referring domain, turned into a source and a medium.
     *
     * @return array{0: string, 1: string}
     */
    public function classify(string $domain): array
    {
        $domain = strtolower(trim($domain, '. '));

        // Webmail first: mail.google.com is Gmail, not a Google search.
        foreach (self::EMAIL_DOMAINS as $needle) {
            if ($this->hostMatches($domain, $needle)) {
                return [$domain, 'email'];
            }
        }

        $firstLabel = explode('.', $domain)[0] ?? '';
        if (in_array($firstLabel, self::EMAIL_PREFIXES, true)) {
            return [$domain, 'email'];
        }

        foreach (self::SEARCH_DOMAINS as $needle => $source) {
            if ($this->hostMatches($domain, $needle)) {
                return [$source, 'organic'];
            }
        }

        foreach (self::SOCIAL_DOMAINS as $needle => $source) {
            if ($this->hostMatches($domain, $needle)) {
                return [$source, 'social'];
            }
        }

        return [$domain, 'referral'];
    }

    /**
     * Does this host belong to that domain?
     *
     * By label, never by substring. str_contains reported notgoogle.example.com as Google and
     * mybrave.shop as the Brave search engine — a referring shop's traffic credited to a search
     * engine it never touched.
     */
    private function hostMatches(string $host, string $needle): bool
    {
        if ($host === $needle || str_ends_with($host, '.' . $needle)) {
            return true;
        }

        // Entries like 'google' or 'facebook' name a brand rather than a host, so they match any
        // label of it — google.co.uk and google.com.eg are both Google.
        if (!str_contains($needle, '.')) {
            foreach (explode('.', $host) as $label) {
                if ($label === $needle) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The source a referrer implies, for a link that carried a campaign but not a source. */
    private function sourceOfReferrer(Request $request): ?string
    {
        $referrer = $this->referrerDomain($request);

        return $referrer === null ? null : $this->classify($referrer)[0];
    }

    private function clean(mixed $value, int $limit): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        // UTM values arrive from wherever somebody pasted a link, so they are treated as untrusted
        // text: stripped to a safe character set before they can become a row in a report that an
        // administrator reads.
        $value = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s._\-\+\|]/u', '', $value) ?? ''));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
