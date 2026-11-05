<?php

namespace App\Services\Fulfillment\Support;

use Illuminate\Support\Arr;

/**
 * What came back from one provider HTTP call, classified by what it tells us about whether the
 * provider acted on the request.
 *
 *  success       2xx
 *  auth_error    401/403 — refused before any processing
 *  rejected      other 4xx — a definitive "no"
 *  rate_limited  429 — refused before processing; safe to retry later
 *  server_error  5xx — for a write, the provider may have acted: treat as ambiguous
 *  not_sent      the request never left (DNS/connection refused/TLS) — safe to retry
 *  ambiguous     408/409, or a timeout/reset after the request was sent — the provider may have acted
 */
final readonly class HttpResult
{
    public const SUCCESS = 'success';
    public const AUTH_ERROR = 'auth_error';
    public const REJECTED = 'rejected';
    public const RATE_LIMITED = 'rate_limited';
    public const SERVER_ERROR = 'server_error';
    public const NOT_SENT = 'not_sent';
    public const AMBIGUOUS = 'ambiguous';

    /** @param array<array-key, mixed>|null $json */
    public function __construct(
        public string $kind,
        public ?int $status,
        public ?array $json,
        public string $body,
        public ?string $error,
        public int $durationMs,
        public string $correlationId,
    ) {
    }

    public function ok(): bool
    {
        return $this->kind === self::SUCCESS;
    }

    /** True when the provider certainly did NOT act on a write. */
    public function certainlyNotProcessed(): bool
    {
        return in_array($this->kind, [self::NOT_SENT, self::RATE_LIMITED, self::AUTH_ERROR], true);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return $this->json === null ? $default : Arr::get($this->json, $path, $default);
    }

    /** A short, redacted human message for logs and the admin UI. */
    public function message(): string
    {
        foreach (['message', 'error', 'error_description', 'msg'] as $key) {
            $value = $this->get($key);
            if (is_string($value) && $value !== '') {
                return SecretRedactor::redactString($value);
            }
        }

        if ($this->error !== null) {
            return SecretRedactor::redactString($this->error);
        }

        return SecretRedactor::redactString((string) SecretRedactor::truncate($this->body, 300));
    }
}
