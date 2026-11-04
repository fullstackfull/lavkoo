<?php

namespace App\Services\Monitoring\Checks;

use JsonSerializable;

/**
 * The outcome of one probe.
 *
 * A check is not a metric: a metric is a number, a check is a verdict about whether a component
 * is doing its job right now. The states mirror Metric's on purpose — a probe that cannot run
 * here says so instead of reporting a failure, because "there is no Redis configured" and "Redis
 * is down" are opposite operational facts and must never render the same.
 */
final class CheckResult implements JsonSerializable
{
    public const OK = 'ok';
    public const DEGRADED = 'degraded';
    public const FAILING = 'failing';
    public const UNKNOWN = 'unknown';
    public const NOT_CONFIGURED = 'not_configured';
    public const NOT_SUPPORTED = 'not_supported';

    /** States that mean the component is actually broken, as opposed to absent or unmeasurable. */
    public const BREAKING = [self::DEGRADED, self::FAILING];

    private function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $status,
        public readonly ?int $durationMs,
        public readonly ?string $detail,
        public readonly array $context,
    ) {
    }

    public static function ok(string $key, ?string $detail = null, ?int $durationMs = null, array $context = [], string $kind = 'health'): self
    {
        return new self($key, $kind, self::OK, $durationMs, $detail, $context);
    }

    public static function degraded(string $key, string $detail, ?int $durationMs = null, array $context = [], string $kind = 'health'): self
    {
        return new self($key, $kind, self::DEGRADED, $durationMs, $detail, $context);
    }

    public static function failing(string $key, string $detail, ?int $durationMs = null, array $context = [], string $kind = 'health'): self
    {
        return new self($key, $kind, self::FAILING, $durationMs, $detail, $context);
    }

    public static function unknown(string $key, string $detail, array $context = [], string $kind = 'health'): self
    {
        return new self($key, $kind, self::UNKNOWN, null, $detail, $context);
    }

    public static function notConfigured(string $key, string $remedy, array $context = [], string $kind = 'health'): self
    {
        return new self($key, $kind, self::NOT_CONFIGURED, null, $remedy, $context);
    }

    public static function notSupported(string $key, string $detail, array $context = [], string $kind = 'health'): self
    {
        return new self($key, $kind, self::NOT_SUPPORTED, null, $detail, $context);
    }

    public function isBreaking(): bool
    {
        return in_array($this->status, self::BREAKING, true);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'detail' => $this->detail,
            'context' => $this->context,
        ];
    }
}
