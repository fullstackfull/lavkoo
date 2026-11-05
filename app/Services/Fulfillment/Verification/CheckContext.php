<?php

namespace App\Services\Fulfillment\Verification;

/** What the checks of one run share: the options the person gave, and what an earlier check found that a later one needs. */
final class CheckContext
{
    private const MAX_SKUS = 20;

    /** @var array<string, mixed> */
    private array $remembered = [];

    private ?string $skipReason = null;

    /** @param array<string, mixed> $options */
    public function __construct(public readonly array $options = [])
    {
    }

    public function remember(string $key, mixed $value): void
    {
        $this->remembered[$key] = $value;
    }

    public function recall(string $key, mixed $default = null): mixed
    {
        return $this->remembered[$key] ?? $default;
    }

    /** Called by a check's closure that cannot run yet; it then returns null. */
    public function cannotRunBecause(string $reason): void
    {
        $this->skipReason = $reason;
    }

    public function takeSkipReason(): string
    {
        $reason = $this->skipReason ?? 'a prerequisite is missing';
        $this->skipReason = null;

        return $reason;
    }

    /** The SKUs a person typed, comma separated: trimmed, without repeats, and no more than a check will compare. @return list<string> */
    public static function parseSkus(string $input): array
    {
        return array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', $input))))), 0, self::MAX_SKUS);
    }

    /** @return list<string> */
    public function skus(): array
    {
        return array_values(array_filter(array_map('trim', (array) ($this->options['skus'] ?? []))));
    }
}
