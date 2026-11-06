<?php

namespace App\Services\SalesChannels\Data;

use App\Services\Fulfillment\Support\SecretRedactor;
use Illuminate\Support\Arr;

/**
 * What came back from one channel call, classified by what it proves about whether the channel acted.
 *
 *  success       2xx
 *  auth_error    401 / 403 - refused before any processing (credentials, token or permission)
 *  rejected      other 4xx - a definitive "no"
 *  rate_limited  429 - refused before processing; safe to retry later
 *  server_error  5xx - for a write, the channel may have acted
 *  not_sent      the request never left (DNS, refused connection, TLS)
 *  ambiguous     a timeout or reset after the request was sent: the channel may have acted
 *  dry_run       nothing was sent: the account is in dry-run and this is the call it would have made
 */
final readonly class ChannelResponse
{
    public const SUCCESS = 'success';
    public const AUTH_ERROR = 'auth_error';
    public const REJECTED = 'rejected';
    public const RATE_LIMITED = 'rate_limited';
    public const SERVER_ERROR = 'server_error';
    public const NOT_SENT = 'not_sent';
    public const AMBIGUOUS = 'ambiguous';
    public const DRY_RUN = 'dry_run';

    /**
     * @param array<array-key, mixed>|null $json
     * @param array<string, string> $headers lower-cased names
     */
    public function __construct(
        public string $kind,
        public ?int $status,
        public ?array $json,
        public string $body,
        public ?string $error,
        public int $durationMs,
        public string $correlationId,
        public array $headers = [],
        public int $attempts = 1,
    ) {
    }

    public function ok(): bool
    {
        return $this->kind === self::SUCCESS;
    }

    public function isDryRun(): bool
    {
        return $this->kind === self::DRY_RUN;
    }

    /** True when the channel certainly did NOT act on a write. */
    public function certainlyNotProcessed(): bool
    {
        return in_array($this->kind, [self::NOT_SENT, self::RATE_LIMITED, self::AUTH_ERROR, self::DRY_RUN], true);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return $this->json === null ? $default : Arr::get($this->json, $path, $default);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Seconds the channel asked us to wait (Retry-After), if it said. */
    public function retryAfterSeconds(): ?int
    {
        $value = $this->header('retry-after');

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    /** A short, redacted human message for logs and the admin panel. */
    public function message(): string
    {
        foreach (['message', 'error_description', 'error', 'detail', 'errors.0.message', 'errors.0.key', 'errors.0.code'] as $key) {
            $value = $this->get($key);
            if (is_string($value) && $value !== '') {
                return SecretRedactor::redactString($value);
            }
        }

        return SecretRedactor::redactString((string) SecretRedactor::truncate($this->error ?? $this->body, 300));
    }

    public static function dryRun(string $correlationId): self
    {
        return new self(self::DRY_RUN, null, null, '', null, 0, $correlationId);
    }
}
