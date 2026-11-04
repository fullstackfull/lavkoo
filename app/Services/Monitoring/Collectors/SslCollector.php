<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Environment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The certificate a customer's browser is actually shown, and the three ways a days-until-expiry
 * check lies about it.
 *
 * 1. A certificate that is perfectly valid but issued for the wrong name is a total outage that
 *    every naive expiry check calls healthy: 60 days left, padlock green in the monitoring panel,
 *    full-page interstitial for every visitor. So the names in the certificate — common name and
 *    every subjectAltName, wildcards resolved one label deep — are matched against the host in
 *    APP_URL and reported as their own metric.
 *
 * 2. The certificate says nothing about the DOMAIN. A 90-day certificate renews itself happily on
 *    a name whose registration lapses next Tuesday, and everything looks fine until the registrar
 *    parks it. Registration expiry is only knowable from the registry, which nothing here talks
 *    to, so it is reported as not configured rather than inferred from validTo.
 *
 * 3. HTTPS being available is not the same as HTTP being closed. A store that still answers on
 *    port 80 without redirecting hands sessions out in the clear no matter how good its
 *    certificate is, so the redirect is probed rather than assumed from the certificate's
 *    existence.
 *
 * The handshake deliberately does not verify the peer. Verification aborts on exactly the
 * certificates worth reporting — expired, self-signed, issued for another name — which would leave
 * this page silent at the moment it has something to say. The certificate is read first and judged
 * afterwards, here.
 */
class SslCollector implements Collector
{
    private const CONFIG_SOURCE = 'Laravel config app.url';
    private const DOMAIN_SOURCE = 'Domain registry (RDAP/WHOIS)';

    /** Short enough that an unresponsive host costs the dashboard a blink, not a page load. */
    private const TIMEOUT_SECONDS = 3;

    private const HANDSHAKE_KEY = 'monitoring:ssl:handshake';
    private const REDIRECT_KEY = 'monitoring:ssl:redirect';

    /** A certificate changes a few times a year; a dashboard refreshes every few seconds. */
    private const READING_SECONDS = 300;

    /** A host that would not talk is retried sooner, so a fixed listener shows up quickly. */
    private const UNREACHABLE_SECONDS = 60;

    private const APP_URL_REMEDY = 'Set APP_URL in .env to the address customers reach the store on — APP_URL=https://shop.example.com — then run php artisan optimize:clear.';

    /** Everything that only exists once there is a TLS endpoint worth connecting to. */
    private const ENDPOINT_METRICS = [
        'port',
        'subject',
        'issuer',
        'valid_from',
        'valid_to',
        'days_until_expiry',
        'expired',
        'covers_host',
        'names',
        'tls_version',
        'tls_cipher',
        'redirects_to_https',
    ];

    /** @var array<string, Metric>|null */
    private ?array $readings = null;

    /** @var array<string, mixed>|null */
    private ?array $peer = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function key(): string
    {
        return 'ssl';
    }

    public function collect(): array
    {
        // Memoised per instance: gauges() asks for the expiry again, and a second handshake per
        // render buys nothing the first one did not already say.
        return $this->readings ??= $this->read();
    }

    public function gauges(): array
    {
        $collected = $this->collect();

        return array_filter([
            'ssl.days_until_expiry' => $collected['days_until_expiry'],
        ], fn (Metric $metric) => $metric->isOk());
    }

    // -------------------------------------------------------------------------------------------

    /** @return array<string, Metric> */
    private function read(): array
    {
        $url = trim((string) config('app.url'));
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $target = $this->target($url, $host);

        $readings = [
            'url' => $url === ''
                ? Metric::notConfigured(self::CONFIG_SOURCE, self::APP_URL_REMEDY, 'APP_URL is empty.')
                : Metric::of($url, self::CONFIG_SOURCE),
            'https' => $this->https($url, $host),
            'host' => $host === ''
                ? Metric::notConfigured(self::CONFIG_SOURCE, self::APP_URL_REMEDY, 'APP_URL holds no hostname.')
                : Metric::of($host, self::CONFIG_SOURCE),
        ];

        // One unreachable endpoint explains every certificate metric on the page at once, with the
        // same remedy attached to each, rather than a column of dashes that never says why.
        $endpoint = $target instanceof Metric
            ? array_fill_keys(self::ENDPOINT_METRICS, $target)
            : array_merge(
                ['port' => $this->port($url, $target)],
                $this->certificate($target),
                ['redirects_to_https' => $this->redirect($target)],
            );

        return array_merge($readings, $endpoint, ['domain_expiry_days' => $this->domainExpiry($host)]);
    }

    /**
     * Whether the store advertises itself over TLS at all.
     *
     * An http:// APP_URL is a finding in its own right, not a missing measurement: every absolute
     * URL the application generates comes out of this one setting.
     */
    private function https(string $url, string $host): Metric
    {
        if ($url === '') {
            return Metric::notConfigured(self::CONFIG_SOURCE, self::APP_URL_REMEDY, 'APP_URL is empty, so the scheme the store is served under is unknown.');
        }

        if ($this->scheme($url) === 'https') {
            return Metric::of(true, self::CONFIG_SOURCE, null, "APP_URL is {$url}.");
        }

        $suggested = $host !== '' && !$this->isLoopback($host) ? "https://{$host}" : 'https://shop.example.com';

        return Metric::notConfigured(
            self::CONFIG_SOURCE,
            "Terminate TLS in front of the application and set APP_URL={$suggested} in .env, then run php artisan optimize:clear.",
            $this->isLoopback($host)
                ? "APP_URL is {$url} — plain HTTP on a loopback address, which is normal for a development machine and must not be what production is deployed with."
                : "APP_URL is {$url}, so every absolute address the application generates — links, redirects, password-reset mails and payment callbacks — is plain HTTP.",
        );
    }

    /**
     * The endpoint a certificate could actually be read from, or the reason there is none.
     *
     * @return array{host: string, port: int}|Metric
     */
    private function target(string $url, string $host): array|Metric
    {
        if ($host === '') {
            return Metric::notConfigured(self::CONFIG_SOURCE, self::APP_URL_REMEDY, 'APP_URL holds no hostname, so there is no certificate to inspect.');
        }

        if (!$this->environment->has('openssl')) {
            return Metric::notSupported(
                'PHP OpenSSL extension',
                'PHP here was built without OpenSSL, so this server can neither complete a TLS handshake nor parse a certificate.',
                'Install a PHP build that includes the OpenSSL extension (configure --with-openssl) and enable it in php.ini: extension=openssl.',
            );
        }

        if ($this->isLoopback($host)) {
            return Metric::notConfigured(
                self::CONFIG_SOURCE,
                'Set APP_URL to the public address customers use — APP_URL=https://shop.example.com — and read this page from the server that terminates TLS for it.',
                "APP_URL is {$url}: {$host} never leaves this machine and no certificate authority issues for it, so there is no certificate here to read.",
            );
        }

        if ($this->scheme($url) !== 'https') {
            return Metric::notConfigured(
                self::CONFIG_SOURCE,
                "Serve the store over TLS and set APP_URL=https://{$host} in .env, then run php artisan optimize:clear.",
                "APP_URL is {$url}, so the store advertises itself over plain HTTP and presents no certificate to inspect.",
            );
        }

        return ['host' => $host, 'port' => (int) (parse_url($url, PHP_URL_PORT) ?: 443)];
    }

    /** @param array{host: string, port: int} $target */
    private function port(string $url, array $target): Metric
    {
        return Metric::of(
            $target['port'],
            self::CONFIG_SOURCE,
            null,
            parse_url($url, PHP_URL_PORT) === null ? 'APP_URL names no port, so the standard TLS port is used.' : null,
        );
    }

    /**
     * The certificate itself, or one shared explanation of why the handshake produced none.
     *
     * @param  array{host: string, port: int}  $target
     * @return array<string, Metric>
     */
    private function certificate(array $target): array
    {
        $source = 'TLS handshake with ' . $this->endpoint($target);
        $peer = $this->handshake($target);

        if (isset($peer['error'])) {
            return array_fill_keys(
                array_values(array_diff(self::ENDPOINT_METRICS, ['port', 'redirects_to_https'])),
                $this->unreachable($target, $peer, $source),
            );
        }

        $now = Clock::now()->getTimestamp();
        $expiresAt = (int) $peer['valid_to'];
        $startsAt = (int) $peer['valid_from'];
        $daysLeft = (int) floor(($expiresAt - $now) / 86400);
        $names = $peer['names'];
        $covered = $this->covers($target['host'], $names);

        return [
            'subject' => Metric::of($peer['subject'], $source, null, $peer['subject'] === null
                ? 'The certificate carries no subject common name; only its subjectAltName entries identify it.'
                : null),
            'issuer' => Metric::of($peer['issuer'], $source, null, $peer['issuer'] === null
                ? 'The certificate names no issuer, which is what a self-signed certificate looks like.'
                : null),
            // Judged here rather than trusted from the probe, because an unreadable start date
            // must never reach the page as a timestamp of zero: "1970-01-01" reads as a date
            // somebody typed, not as a field that could not be read.
            'valid_from' => $startsAt <= 0
                ? Metric::noData($source, 'The certificate carries no readable notBefore date, so when it started being valid cannot be shown.')
                : Metric::of(Clock::parse($startsAt)->toDateTimeString(), $source, null, $startsAt > $now
                    ? 'The certificate is not valid yet; browsers reject it until this moment.'
                    : null),
            'valid_to' => Metric::of(Clock::parse($expiresAt)->toDateTimeString(), $source),
            'days_until_expiry' => Metric::of($daysLeft, $source, 'days', $daysLeft < 0
                ? 'The certificate expired ' . abs($daysLeft) . ' day(s) ago; every visitor is being shown a browser warning.'
                : null),
            'expired' => Metric::of($expiresAt <= $now, $source),
            'covers_host' => Metric::of($covered, $source, null, $covered
                ? "The certificate covers {$target['host']} (" . $this->nameList($names) . ').'
                : "The certificate served on {$this->endpoint($target)} is issued for " . $this->nameList($names) . " — none of which cover {$target['host']}, so browsers reject it with a name mismatch however long it has left to run.",
            ),
            'names' => Metric::of($names, $source),
            'tls_version' => Metric::of($peer['protocol'], $source, null, 'The protocol this server negotiated; a browser may negotiate a different one.'),
            'tls_cipher' => Metric::of($peer['cipher'], $source),
        ];
    }

    /**
     * @param  array{host: string, port: int}  $target
     * @param  array<string, mixed>  $peer
     */
    private function unreachable(array $target, array $peer, string $source): Metric
    {
        $endpoint = $this->endpoint($target);
        $reason = (string) $peer['error'];
        $stage = (string) ($peer['stage'] ?? 'probe');

        $note = match ($stage) {
            'connect' => "Nothing accepted a connection on {$endpoint} within " . self::TIMEOUT_SECONDS . "s ({$reason}), so the certificate customers are shown cannot be read from this server.",
            'crypto' => "{$endpoint} accepted the connection but the TLS handshake failed ({$reason}).",
            'certificate' => "{$endpoint} completed a TLS handshake without a usable certificate ({$reason}).",
            default => "The certificate probe against {$endpoint} could not be completed ({$reason}).",
        };

        $remedy = $stage === 'connect'
            ? "Confirm a TLS listener is bound to {$endpoint} and that this server may reach it — check the web server's listen directive and any firewall or security group between the two."
            : "Check the TLS listener on {$endpoint}: the certificate and private key it is configured with, and that it speaks TLS rather than plain HTTP on that port.";

        return Metric::notConfigured($source, $remedy, $note);
    }

    /**
     * One handshake per instance, cached across renders.
     *
     * @param  array{host: string, port: int}  $target
     * @return array<string, mixed>
     */
    private function handshake(array $target): array
    {
        if ($this->peer !== null) {
            return $this->peer;
        }

        try {
            $key = self::HANDSHAKE_KEY . ':' . $this->endpoint($target);
            $cached = $this->cached($key);
            if (is_array($cached) && $this->isReadable($cached)) {
                return $this->peer = $cached;
            }

            $reading = $this->readPeerCertificate($target);
            $this->remember($key, $reading, isset($reading['error']) ? self::UNREACHABLE_SECONDS : self::READING_SECONDS);

            return $this->peer = $reading;
        } catch (\Throwable $exception) {
            return $this->peer = ['stage' => 'probe', 'error' => Metric::describeFailure($exception)];
        }
    }

    /**
     * Whether a cached reading still has every field this build goes on to read.
     *
     * A deploy that changes the shape of the array above leaves up to five minutes of readings in
     * the cache that the new code will index into. Re-handshaking costs one connection; trusting
     * the old shape costs an undefined-key exception thrown out of a dashboard render.
     *
     * @param  array<string, mixed>  $reading
     */
    private function isReadable(array $reading): bool
    {
        if (isset($reading['error'])) {
            return true;
        }

        return array_diff(['valid_from', 'valid_to', 'names'], array_keys($reading)) === [];
    }

    /**
     * The cache is a convenience here, never a dependency.
     *
     * A cache store that is down is a different machine with a different remedy than a TLS endpoint
     * that is down, and reporting the first as the second sends whoever is on call to the wrong
     * server. A failed read or write only means this render pays for its own handshake.
     */
    private function cached(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    private function remember(string $key, mixed $value, int $seconds): void
    {
        try {
            Cache::put($key, $value, $seconds);
        } catch (\Throwable) {
            // The reading is already in hand; it just will not outlive this request.
        }
    }

    /**
     * @param  array{host: string, port: int}  $target
     * @return array<string, mixed>
     */
    private function readPeerCertificate(array $target): array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                // Without SNI a host on shared infrastructure answers with the default vhost's
                // certificate, and this page would report another site's certificate as this
                // store's — including its expiry date.
                'SNI_enabled' => true,
                'peer_name' => $target['host'],
            ],
        ]);

        $errorNumber = 0;
        $errorMessage = '';

        // TCP first and crypto second, rather than one ssl:// connect: the connect timeout does not
        // cover the handshake, so a host that accepts the socket and then stops talking would hold
        // the dashboard open until default_socket_timeout gave up sixty seconds later.
        $stream = @stream_socket_client(
            'tcp://' . $target['host'] . ':' . $target['port'],
            $errorNumber,
            $errorMessage,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            return ['stage' => 'connect', 'error' => $this->reason($errorMessage, $errorNumber)];
        }

        try {
            stream_set_timeout($stream, self::TIMEOUT_SECONDS);

            if (@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_ANY_CLIENT) !== true) {
                return ['stage' => 'crypto', 'error' => $this->reason((string) openssl_error_string())];
            }

            $certificate = stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null;
            if ($certificate === null) {
                return ['stage' => 'certificate', 'error' => 'the server presented no certificate'];
            }

            $parsed = openssl_x509_parse($certificate);
            if (!is_array($parsed)) {
                return ['stage' => 'certificate', 'error' => 'the certificate could not be parsed'];
            }
            if ((int) ($parsed['validTo_time_t'] ?? 0) <= 0) {
                return ['stage' => 'certificate', 'error' => 'the certificate carries no readable validity dates'];
            }

            $crypto = stream_get_meta_data($stream)['crypto'] ?? [];
            $startsAt = (int) ($parsed['validFrom_time_t'] ?? 0);

            return [
                'subject' => $this->distinguishedName($parsed['subject'] ?? []),
                'issuer' => $this->distinguishedName($parsed['issuer'] ?? []),
                // notBefore is not fatal the way notAfter is — an expiry is still worth reporting
                // without it — so it travels as null and is rendered as a missing reading.
                'valid_from' => $startsAt > 0 ? $startsAt : null,
                'valid_to' => (int) $parsed['validTo_time_t'],
                'names' => $this->certificateNames($parsed),
                'protocol' => $crypto['protocol'] ?? null,
                'cipher' => $crypto['cipher_name'] ?? null,
            ];
        } finally {
            fclose($stream);
        }
    }

    /**
     * Whether plain HTTP is closed, which is a separate question from whether HTTPS works.
     *
     * @param  array{host: string, port: int}  $target
     */
    private function redirect(array $target): Metric
    {
        $source = 'HEAD http://' . $target['host'] . '/';

        return Metric::probe($source, function () use ($target, $source) {
            $key = self::REDIRECT_KEY . ':' . $target['host'];
            $cached = $this->cached($key);
            if ($cached instanceof Metric) {
                return $cached;
            }

            $metric = $this->probeRedirect($target, $source);
            $this->remember($key, $metric, $metric->isOk() ? self::READING_SECONDS : self::UNREACHABLE_SECONDS);

            return $metric;
        });
    }

    /** @param array{host: string, port: int} $target */
    private function probeRedirect(array $target, string $source): Metric
    {
        try {
            $response = Http::connectTimeout(self::TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->head('http://' . $target['host'] . '/');
        } catch (ConnectionException $exception) {
            return Metric::noData($source, "Nothing answered http://{$target['host']}/ within " . self::TIMEOUT_SECONDS . 's, so whether plain HTTP redirects cannot be told from here (' . class_basename($exception) . ').');
        }

        $status = $response->status();
        $location = trim((string) $response->header('Location'));

        if ($location === '') {
            return Metric::of(false, $source, null, "http://{$target['host']}/ answered {$status} with no Location header, so a customer who omits the scheme is served over an unencrypted connection.");
        }

        // The one redirect is followed by reading where it points, not by fetching it: the answer
        // is already in the header, and a second request would add a second timeout to the render.
        if (strtolower((string) (parse_url($location, PHP_URL_SCHEME) ?: '')) === 'https') {
            return Metric::of(true, $source, null, "http://{$target['host']}/ answers {$status} to {$location}.");
        }

        return Metric::of(false, $source, null, "http://{$target['host']}/ answers {$status} to {$location}, which is not an https address.");
    }

    /**
     * Registration expiry, which the certificate cannot answer for.
     */
    private function domainExpiry(string $host): Metric
    {
        if ($host === '') {
            return Metric::notConfigured(self::DOMAIN_SOURCE, self::APP_URL_REMEDY, 'APP_URL holds no hostname, so there is no registration to look up.');
        }

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return Metric::notConfigured(
                self::DOMAIN_SOURCE,
                "Set APP_URL to the domain customers type rather than the address {$host}; only a registered name has an expiry date.",
                "APP_URL points straight at an IP address, so there is no domain registration behind it.",
            );
        }

        return Metric::notConfigured(
            self::DOMAIN_SOURCE,
            // -L is not optional: rdap.org is a bootstrap redirector, so without it the command
            // returns a 302 and zero bytes, and the remedy reads as though the domain had no record.
            "No registry source is configured. Read it from the registry itself — curl -sL https://rdap.org/domain/{$host} and take the event with eventAction \"expiration\" — and schedule that if you want it as a live metric.",
            'A certificate says nothing about the domain behind it: a 90-day certificate keeps renewing itself right up to the day the registration lapses, so deriving this from validTo would report a healthy number for a name that is about to stop resolving.',
        );
    }

    /**
     * Every name the certificate covers: the subject common name plus each SAN entry.
     *
     * Browsers have ignored the common name for years and read subjectAltName only, but plenty of
     * internal certificates still carry nothing else, so both are collected.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<int, string>
     */
    private function certificateNames(array $parsed): array
    {
        $names = [];

        $common = $parsed['subject']['CN'] ?? null;
        if (is_array($common)) {
            $common = reset($common);
        }
        if (is_string($common) && trim($common) !== '') {
            $names[] = strtolower(trim($common));
        }

        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);
            if (stripos($entry, 'DNS:') === 0) {
                $names[] = strtolower(trim(substr($entry, 4)));
            } elseif (stripos($entry, 'IP Address:') === 0) {
                $names[] = strtolower(trim(substr($entry, 11)));
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    /** @param array<int, string> $names */
    private function covers(string $host, array $names): bool
    {
        foreach ($names as $name) {
            if ($name === $host) {
                return true;
            }

            if (!str_starts_with($name, '*.')) {
                continue;
            }

            // A wildcard covers exactly one label. *.example.com is shop.example.com, and is
            // neither example.com itself nor a.b.example.com — the gap behind most "but we bought
            // a wildcard" outages.
            $suffix = substr($name, 1);
            if (!str_ends_with($host, $suffix) || strlen($host) <= strlen($suffix)) {
                continue;
            }

            if (!str_contains(substr($host, 0, -strlen($suffix)), '.')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $parts */
    private function distinguishedName(array $parts): ?string
    {
        foreach (['CN', 'O', 'OU'] as $field) {
            $value = $parts[$field] ?? null;
            if (is_array($value)) {
                $value = reset($value);
            }
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * A SAN list can hold hundreds of names; a note on a dashboard cannot.
     *
     * @param  array<int, string>  $names
     */
    private function nameList(array $names): string
    {
        if ($names === []) {
            return 'no names';
        }

        $shown = array_slice($names, 0, 6);
        $remaining = count($names) - count($shown);

        return implode(', ', $shown) . ($remaining > 0 ? " and {$remaining} more" : '');
    }

    /** @param array{host: string, port: int} $target */
    private function endpoint(array $target): string
    {
        return $target['host'] . ':' . $target['port'];
    }

    private function scheme(string $url): string
    {
        return strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
    }

    private function isLoopback(string $host): bool
    {
        if ($host === 'localhost' || $host === 'localhost.localdomain' || str_ends_with($host, '.localhost')) {
            return true;
        }

        return str_starts_with($host, '127.') || $host === '::1' || $host === '[::1]' || $host === '0.0.0.0';
    }

    private function reason(string $message, int $number = 0): string
    {
        $message = trim($message);
        if ($message !== '') {
            return $message;
        }

        return $number !== 0 ? 'socket error ' . $number : 'no reason was reported';
    }
}
