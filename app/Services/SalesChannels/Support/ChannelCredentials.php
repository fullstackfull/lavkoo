<?php

namespace App\Services\SalesChannels\Support;

/**
 * An account's decrypted secrets, reachable only by name. They never appear in var_dump, print_r, logs or JSON: `__debugInfo` hides them and the class
 * is not Stringable or JsonSerializable.
 */
final class ChannelCredentials
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function secret(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function has(string $key): bool
    {
        return $this->secret($key) !== null;
    }

    /** @return array<string, bool> which secrets are present, never their values */
    public function presence(): array
    {
        return array_map(static fn ($value): bool => is_string($value) && $value !== '', $this->values);
    }

    public function __debugInfo(): array
    {
        return ['credentials' => '[hidden]', 'keys' => array_keys($this->values)];
    }
}
