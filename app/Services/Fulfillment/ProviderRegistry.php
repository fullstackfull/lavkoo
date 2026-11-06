<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Contracts\ProviderAdapter;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The set of providers this installation knows how to talk to. Adding a provider means adding an
 * entry to `config/fulfillment.php` and an adapter class — routing, dispatch, retries, webhooks and
 * the parent-order summary need no change.
 */
class ProviderRegistry
{
    public function __construct(private readonly Container $container)
    {
    }

    /** @return list<ProviderCode> */
    public function codes(): array
    {
        return ProviderCode::cases();
    }

    public function adapter(ProviderCode|string $provider): ProviderAdapter
    {
        $code = $provider instanceof ProviderCode ? $provider : ProviderCode::tryFrom($provider);
        if ($code === null) {
            throw new InvalidArgumentException("Unknown fulfillment provider '{$provider}'.");
        }

        $class = config('fulfillment.providers.' . $code->value . '.adapter');
        if (!is_string($class)) {
            throw new InvalidArgumentException("No adapter configured for provider '{$code->value}'.");
        }

        return $this->container->make($class);
    }

    /** @return array<string, mixed> */
    public function meta(ProviderCode $provider): array
    {
        return (array) config('fulfillment.providers.' . $provider->value, []);
    }

    /**
     * Default non-secret settings, declared statically by the adapter so they can be read without
     * constructing it (the adapter needs the connection manager, which needs these defaults).
     *
     * @return array<string, mixed>
     */
    public function defaultSettings(ProviderCode $provider): array
    {
        $class = $this->meta($provider)['adapter'] ?? null;

        return is_string($class) && method_exists($class, 'defaultSettings') ? $class::defaultSettings() : [];
    }
}
