<?php

namespace App\Services\Monitoring\Checks;

use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Environment;
use App\Services\Monitoring\Support\MonitoringSettings;

/**
 * How many days are left on the certificate the storefront is served with.
 *
 * An expired certificate takes the whole shop offline with a browser warning no customer will
 * click past, and it happens on a date known months in advance — so the only reason it ever
 * happens is that nobody was watching. The certificate is read straight off the TLS handshake,
 * which is what a browser sees, rather than off a file on disk that may not be the one nginx
 * is actually serving.
 */
class SslCheck implements Check
{
    private const HANDSHAKE_TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly Environment $environment,
        private readonly MonitoringSettings $settings,
    ) {
    }

    public function key(): string
    {
        return 'ssl';
    }

    public function kind(): string
    {
        return 'health';
    }

    public function run(): CheckResult
    {
        if (!$this->environment->has('openssl')) {
            return CheckResult::notSupported($this->key(), 'The OpenSSL extension is not loaded, so this server cannot inspect a certificate.');
        }

        $url = (string) $this->settings->get('ssl_url', config('app.url'));
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!is_string($host) || $host === '') {
            return CheckResult::notConfigured($this->key(), 'APP_URL does not contain a host name, so there is no certificate to check.');
        }

        if ($scheme !== 'https') {
            return CheckResult::notConfigured(
                $this->key(),
                "APP_URL is {$scheme}://{$host}, so this site is not served over TLS here and there is no certificate to expire. Set APP_URL to the https address the shop is reached on.",
                ['host' => $host],
            );
        }

        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        $certificate = $this->peerCertificate($host, $port);

        if (is_string($certificate)) {
            return CheckResult::failing($this->key(), $certificate, context: ['host' => $host, 'port' => $port]);
        }

        $expiresAt = Clock::parse((int) $certificate['validTo_time_t']);
        $days = (int) floor(Clock::now()->diffInSeconds($expiresAt, false) / 86400);
        $context = [
            'host' => $host,
            'issuer' => $certificate['issuer']['O'] ?? ($certificate['issuer']['CN'] ?? null),
            'subject' => $certificate['subject']['CN'] ?? null,
            'expires_at' => $expiresAt->toDateTimeString(),
            'days_remaining' => $days,
        ];

        if ($days < 0) {
            return CheckResult::failing($this->key(), 'The certificate expired ' . abs($days) . ' day(s) ago.', context: $context);
        }

        $warning = (int) ($this->settings->threshold('ssl_expiry_warning_days') ?? 21);

        if ($days <= 3) {
            return CheckResult::failing($this->key(), "The certificate expires in {$days} day(s).", context: $context);
        }

        if ($days <= $warning) {
            return CheckResult::degraded($this->key(), "The certificate expires in {$days} day(s).", context: $context);
        }

        return CheckResult::ok($this->key(), "The certificate is valid for another {$days} day(s).", context: $context);
    }

    /**
     * @return array<string, mixed>|string  the parsed certificate, or the reason there is none
     */
    private function peerCertificate(string $host, int $port): array|string
    {
        // capture_peer_cert, and verify_peer off: an expired or mismatched certificate is exactly
        // what this check exists to report, so refusing to look at it would defeat the purpose.
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errorCode,
            $errorMessage,
            self::HANDSHAKE_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return "The TLS handshake with {$host}:{$port} failed: " . ($errorMessage !== '' ? $errorMessage : "error {$errorCode}") . '.';
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $resource = $params['options']['ssl']['peer_certificate'] ?? null;
        $parsed = $resource !== null ? openssl_x509_parse($resource) : false;

        return is_array($parsed) && isset($parsed['validTo_time_t'])
            ? $parsed
            : "The certificate served by {$host}:{$port} could not be parsed.";
    }
}
