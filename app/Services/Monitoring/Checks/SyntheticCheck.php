<?php

namespace App\Services\Monitoring\Checks;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Scripted journeys: fetch a page the way a customer would and assert what came back.
 *
 * Server metrics can all be green while the home page returns a white screen — a template that
 * throws after the response has started, a CDN serving a stale error, a maintenance file nobody
 * removed. Only an actual request finds that.
 *
 * Journeys are defined with `php artisan monitoring:synthetic` rather than hard-coded, and NOTHING is probed
 * until at least one is defined: inventing a target would mean this server quietly making
 * outbound requests the operator never asked for. With none defined the check reports
 * not_configured and says how to add one.
 *
 * Only safe, idempotent GETs are allowed. A synthetic journey that could place an order would put
 * fake orders in a real shop, and a monitoring system must never write to the business it watches.
 */
class SyntheticCheck implements Check
{
    private const DEFAULT_TIMEOUT_SECONDS = 15;

    /** Bound on how many journeys one run will perform, so a long list cannot stall the schedule. */
    private const MAX_TARGETS = 10;

    public function __construct(private readonly MonitoringSettings $settings)
    {
    }

    public function key(): string
    {
        return 'synthetic';
    }

    public function kind(): string
    {
        return 'synthetic';
    }

    /** @return array<int, CheckResult> */
    public function run(): array
    {
        $targets = $this->targets();

        if ($targets === []) {
            return [CheckResult::notConfigured(
                key: $this->key(),
                remedy: 'No synthetic journey is defined, so nothing is fetched and a white screen would go unnoticed. Define one with '
                    . '`php artisan monitoring:synthetic add "Home page" ' . rtrim((string) config('app.url'), '/') . '/ --contains="…"`'
                    . ' — a name, a URL on this site, the status it must return and a phrase the page must contain.',
                kind: $this->kind(),
            )];
        }

        return array_map(fn (array $target) => $this->probe($target), $targets);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function targets(): array
    {
        $configured = $this->settings->get('synthetics', []);

        if (is_string($configured)) {
            $configured = json_decode($configured, true);
        }

        if (!is_array($configured)) {
            return [];
        }

        $targets = [];

        foreach ($configured as $target) {
            if (!is_array($target) || !isset($target['url']) || !self::isProbeable((string) $target['url'])) {
                continue;
            }

            $targets[] = $target;

            if (count($targets) >= self::MAX_TARGETS) {
                break;
            }
        }

        return $targets;
    }

    /**
     * A URL is probeable only if it is an http(s) address. Anything else — file://, a shell
     * fragment, an internal metadata address — is not a customer journey and is not fetched.
     */
    public static function isProbeable(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            return false;
        }

        // Cloud instance metadata endpoints hand out credentials to anything that asks; a
        // monitoring target list is not allowed to become the thing that asks.
        return !in_array($host, ['169.254.169.254', 'metadata.google.internal', '[fd00:ec2::254]'], true);
    }

    private function probe(array $target): CheckResult
    {
        $name = (string) ($target['name'] ?? parse_url((string) $target['url'], PHP_URL_PATH) ?: 'journey');
        $key = 'synthetic:' . Str::slug($name);
        $expectedStatus = (int) ($target['expect_status'] ?? 200);
        $expectedText = isset($target['expect_text']) ? (string) $target['expect_text'] : null;
        $maxMs = isset($target['max_ms']) ? (int) $target['max_ms'] : null;
        $started = hrtime(true);

        try {
            $response = Http::withoutVerifying()
                ->withHeaders(['User-Agent' => 'PharmacyMonitoring/1.0 (synthetic check)'])
                ->timeout((int) ($target['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS))
                ->get((string) $target['url']);
        } catch (\Throwable $exception) {
            return CheckResult::failing(
                $key,
                Metric::describeFailure($exception),
                context: ['name' => $name, 'url' => $target['url']],
                kind: $this->kind(),
            );
        }

        $elapsed = (int) round((hrtime(true) - $started) / 1e6);
        $context = [
            'name' => $name,
            'url' => $target['url'],
            'status' => $response->status(),
            'expected_status' => $expectedStatus,
            'bytes' => strlen($response->body()),
        ];

        if ($response->status() !== $expectedStatus) {
            return CheckResult::failing(
                $key,
                "Returned HTTP {$response->status()}, expected {$expectedStatus}.",
                $elapsed,
                $context,
                $this->kind(),
            );
        }

        if ($expectedText !== null && $expectedText !== '' && !str_contains($response->body(), $expectedText)) {
            // A 200 that is missing the thing that makes the page the page: the classic silent
            // failure, where the shell renders and the products do not.
            return CheckResult::failing(
                $key,
                "The page returned 200 but does not contain \"{$expectedText}\".",
                $elapsed,
                $context + ['expect_text' => $expectedText],
                $this->kind(),
            );
        }

        if ($maxMs !== null && $elapsed > $maxMs) {
            return CheckResult::degraded($key, "Took {$elapsed} ms, over the {$maxMs} ms budget.", $elapsed, $context, $this->kind());
        }

        return CheckResult::ok($key, "HTTP {$expectedStatus} in {$elapsed} ms.", $elapsed, $context, $this->kind());
    }
}
