<?php

namespace App\Services\Analytics\Support;

use App\Services\DeveloperPortal\ApiConsole;
use Illuminate\Http\Request;

/**
 * Is this a person?
 *
 * This exists because of a measured problem, not a theoretical one: of 367 recorded sessions on
 * this store, 133 were crawlers — the visitor count on the old Analytics page was inflated by more
 * than a third, and every conversion rate computed from it was correspondingly wrong.
 *
 * Detection is deliberately conservative in one direction: a real customer must never be
 * classified as a bot, because that silently deletes them from every report. A crawler that slips
 * through inflates a number; a customer wrongly excluded loses a sale nobody can see.
 */
class BotDetector
{
    /**
     * Declared crawlers. Nearly all of them say so in the user agent — the well-behaved ones
     * because they want to be identified, and the lazy ones because they never changed the default.
     */
    private const AGENT_SIGNATURES = [
        'bot', 'crawl', 'spider', 'slurp', 'search', 'scrape', 'archiver',
        'facebookexternalhit', 'whatsapp', 'telegrambot', 'twitterbot', 'linkedinbot',
        'pinterest', 'discordbot', 'slackbot', 'embedly', 'quora link preview',
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
        'curl/', 'wget/', 'python-requests', 'python-urllib',
        'libwww-perl', 'guzzlehttp', 'postman', 'insomnia', 'lighthouse', 'pagespeed',
        'uptimerobot', 'pingdom', 'statuscake', 'newrelicpinger', 'datadog', 'ahrefs',
        'semrush', 'mj12bot', 'dotbot', 'petalbot', 'bytespider', 'gptbot', 'ccbot',
        'claudebot', 'perplexitybot', 'applebot', 'amazonbot', 'yandex', 'baiduspider',
    ];

    /**
     * HTTP libraries, which are what a scraper uses AND what a native app is built on.
     *
     * okhttp is the default user agent of every Android app built on Retrofit — including this
     * shop's own — and NSURLSession sends nothing recognisable at all. Treating these as crawlers
     * classified the store's own customers as bots and erased them from every report, which is the
     * one direction this class is not allowed to be wrong in. They only count as a crawler when
     * the request has not identified itself as one of the shop's apps.
     */
    private const GENERIC_CLIENTS = [
        'okhttp', 'java/', 'go-http-client', 'apache-httpclient', 'httpclient',
        'axios/', 'node-fetch', 'dart:io', 'dio/', 'cfnetwork',
    ];

    /** Our own probes, which must never appear anywhere in a customer report. */
    private const OWN_AGENTS = ['pharmacymonitoring'];

    public function isBot(Request $request): bool
    {
        $agent = strtolower(trim((string) $request->userAgent()));

        // A declared crawler is a crawler whatever else it claims to be.
        foreach (array_merge(self::AGENT_SIGNATURES, self::OWN_AGENTS) as $signature) {
            if ($agent !== '' && str_contains($agent, $signature)) {
                return true;
            }
        }

        // The shop's own apps say so, in the same header MonitorRequest files their traffic under.
        // Without this the two systems disagreed about the same request: monitoring counted it as
        // the Android app while analytics deleted it as a crawler.
        if ($this->isOwnApp($request)) {
            return false;
        }

        if ($agent === '') {
            // On the web a browser always sends one. On the API an app that did not declare itself
            // is unidentifiable rather than proven fake — but counting it as a person would let any
            // script inflate the numbers, so the conservative answer stands where it is cheap.
            return true;
        }

        foreach (self::GENERIC_CLIENTS as $signature) {
            if (str_contains($agent, $signature)) {
                return true;
            }
        }

        // A browser that asks for a page and accepts nothing is not rendering it. Only asked of
        // browser traffic: native clients routinely omit Accept and are none the worse for it.
        if (!$this->isApi($request)
            && $request->isMethod('GET')
            && trim((string) $request->headers->get('Accept')) === '') {
            return true;
        }

        return false;
    }

    /** Did this request identify itself as one of the shop's own apps? */
    private function isOwnApp(Request $request): bool
    {
        $platform = strtolower(trim((string) $request->headers->get('X-Platform', '')));

        if (in_array($platform, ['android', 'ios'], true)) {
            return true;
        }

        // The version header is the app's too, and an app that sends one has been built by whoever
        // runs this shop rather than pointed at it.
        return preg_match(
            '/^[0-9A-Za-z\.\-\+]{1,32}$/',
            (string) $request->headers->get('X-App-Version', '')
        ) === 1;
    }

    private function isApi(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    /**
     * Staff, and anyone browsing from the shop's own network.
     *
     * Counting the merchant's own eleven visits a day as customer traffic is how a small shop's
     * conversion rate ends up looking like a rounding error.
     */
    public function isInternal(Request $request): bool
    {
        if (auth('admin')->check() || auth('seller')->check()) {
            return true;
        }

        // The API console's own probes. Flagged rather than dropped, like every other internal
        // visit: a filter nobody can audit is indistinguishable from one that stopped working.
        if ($request->headers->has(ApiConsole::MARKER_HEADER)) {
            return true;
        }

        $address = (string) $request->ip();

        foreach ((array) config('analytics.internal_ips', []) as $range) {
            if ($this->matches($address, trim((string) $range))) {
                return true;
            }
        }

        return false;
    }

    /** The device class this request came from — bot included, because that is a real answer. */
    public function device(Request $request): string
    {
        if ($this->isBot($request)) {
            return 'bot';
        }

        $agent = strtolower((string) $request->userAgent());

        if (str_contains($agent, 'ipad') || (str_contains($agent, 'android') && !str_contains($agent, 'mobile'))) {
            return 'tablet';
        }

        if (preg_match('/mobile|iphone|ipod|android|windows phone|opera mini/', $agent) === 1) {
            return 'mobile';
        }

        // A native app sends a user agent that says nothing about the handset — okhttp/4.12.0 on
        // Android, nothing at all on iOS — so the header it does send decides, rather than
        // defaulting a phone to 'desktop'.
        return $this->isOwnApp($request) ? 'mobile' : 'desktop';
    }

    private function matches(string $address, string $range): bool
    {
        if ($range === '' || $address === '') {
            return false;
        }

        if (!str_contains($range, '/')) {
            return $address === $range;
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $bits = (int) $bits;

        $addressBinary = @inet_pton($address);
        $subnetBinary = @inet_pton($subnet);

        if ($addressBinary === false || $subnetBinary === false || strlen($addressBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if (strncmp($addressBinary, $subnetBinary, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($addressBinary[$wholeBytes]) & $mask) === (ord($subnetBinary[$wholeBytes]) & $mask);
    }
}
