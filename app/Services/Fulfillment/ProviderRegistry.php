<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Contracts\ProviderAdapter;
use App\Services\Fulfillment\Contracts\ProviderIdentity;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The set of carriers this installation knows how to talk to. Adding one means adding an entry to `config/fulfillment.php` and an adapter class —
 * routing, dispatch, retries, webhooks and the parent-order summary need no change.
 *
 * That sentence was the intent from the start and was not true until H1-11: `ProviderCode` was a closed enum, so a carrier for the UAE or Kuwait could
 * not exist until a case was added to it, however complete its configuration was. The configuration is the gate now. This class is the only place that
 * resolves a stored code into a carrier, and it answers for the two carriers with an enum case and for any other declared in the configuration alike —
 * `isKnown()` here is the question "does this platform know this carrier", which is NOT the same as `ProviderCode::isKnown()`, the question "is this one
 * of the two we ship".
 *
 * A declared entry needs an `adapter` class to be a carrier at all: an entry without one is configuration somebody started and did not finish, and it is
 * not offered to a router or a dispatcher, because a carrier nothing can talk to would hold every parcel routed to it.
 */
class ProviderRegistry
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Every carrier this installation knows, the two with an enum case first and in their declared order after that.
     *
     * @return list<ProviderIdentity>
     */
    public function identities(): array
    {
        $identities = ProviderCode::cases();

        foreach (array_keys((array) config('fulfillment.providers', [])) as $code) {
            if (!is_string($code) || ProviderCode::tryFrom($code) !== null) {
                continue;
            }

            $declared = ConfiguredProvider::fromConfig($code);

            if ($declared !== null && $this->adapterClassOf($code) !== null) {
                $identities[] = $declared;
            }
        }

        return $identities;
    }

    /** The carrier this code names, or null where this installation knows no such carrier. */
    public function identity(ProviderIdentity|string|null $provider): ?ProviderIdentity
    {
        if ($provider instanceof ProviderIdentity) {
            return $provider;
        }

        if ($provider === null || $provider === '') {
            return null;
        }

        $enum = ProviderCode::tryFrom($provider);

        if ($enum !== null) {
            return $enum;
        }

        return $this->adapterClassOf($provider) === null ? null : ConfiguredProvider::fromConfig($provider);
    }

    /** The carrier whose URL form this is ('torod-point'), or null. */
    public function fromSlug(string $slug): ?ProviderIdentity
    {
        return $this->identity(str_replace('-', '_', $slug));
    }

    /** Whether this installation knows a carrier by this code. The configuration decides, not the enum. */
    public function isKnown(?string $code): bool
    {
        return $this->identity($code) !== null;
    }

    public function adapter(ProviderIdentity|string $provider): ProviderAdapter
    {
        $identity = $this->identity($provider);

        if ($identity === null) {
            $given = $provider instanceof ProviderIdentity ? $provider->code() : $provider;

            throw new InvalidArgumentException("Unknown fulfillment provider '{$given}'.");
        }

        $class = $this->adapterClassOf($identity->code());

        if ($class === null) {
            throw new InvalidArgumentException("No adapter configured for provider '{$identity->code()}'.");
        }

        return $this->container->make($class);
    }

    /** @return array<string, mixed> */
    public function meta(ProviderIdentity|string $provider): array
    {
        $identity = $this->identity($provider);

        return $identity === null ? [] : (array) config('fulfillment.providers.' . $identity->code(), []);
    }

    /**
     * Default non-secret settings, declared statically by the adapter so they can be read without constructing it (the adapter needs the connection
     * manager, which needs these defaults).
     *
     * @return array<string, mixed>
     */
    public function defaultSettings(ProviderIdentity|string $provider): array
    {
        $class = $this->meta($provider)['adapter'] ?? null;

        return is_string($class) && method_exists($class, 'defaultSettings') ? $class::defaultSettings() : [];
    }

    private function adapterClassOf(string $code): ?string
    {
        $class = config('fulfillment.providers.' . $code . '.adapter');

        return is_string($class) && $class !== '' ? $class : null;
    }
}
