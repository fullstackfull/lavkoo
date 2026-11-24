<?php

namespace App\Services\Storefront;

/**
 * The queue each market's work waits on, so that a market can be worked, paced or paused on its own (A1-14).
 *
 * One queue for every country means one worker pool for every country: a flood of Kuwaiti mail delays Saudi mail, and there is no way to
 * stop one market's work without stopping all of it. Giving each market its own queue name hands both back to the framework rather than
 * building anything: `queue:work --queue=storefront-kw` works one market, `queue:pause storefront-kw` stops reserving from it (Laravel keeps
 * that as a cache flag the worker reads, so nothing is lost, nothing is retried and no attempt is spent), and `queue:resume` starts it again.
 * `gcc:storefront-queues` prints each market's queue, whether it is paused and how much is waiting on it.
 *
 * The default storefront keeps the queue every job uses today. That is the same rule the cache keys follow ({@see StorefrontContext::scopedCacheKey}),
 * and here it is also what makes switching this on safe: a running worker keeps working Saudi jobs whatever its `--queue` list says, so the
 * worst a half-finished rollout can do is leave another market's jobs waiting for the worker that market needs — never strand the market
 * that is live.
 *
 * Off by default. While it is off every job goes where it goes today, and a job that carries no market never moves either.
 */
final class StorefrontQueues
{
    public static function isEnabled(): bool
    {
        return (bool) config('gcc.storefronts.queues.per_market') && StorefrontResolver::fromConfig()->isEnabled();
    }

    /**
     * The queue a job of this market waits on, or null when it waits where every job waits today: while the switch is off, for work that
     * carries no market, and for the default storefront.
     */
    public static function forMarket(?string $country): ?string
    {
        $code = strtoupper(trim((string) $country));

        if ($code === '' || !self::isEnabled() || $code === StorefrontResolver::fromConfig()->defaultCountry()) {
            return null;
        }

        return self::nameOf($code);
    }

    /**
     * Every market that has a queue of its own, as country code => queue name, in the order the configuration lists them. Empty while the
     * switch is off. The default storefront is not in it: it has no queue of its own.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        $resolver = StorefrontResolver::fromConfig();
        $queues = [];

        foreach ($resolver->configuredCountries() as $country) {
            if ($country !== $resolver->defaultCountry()) {
                $queues[$country] = self::nameOf($country);
            }
        }

        return $queues;
    }

    private static function nameOf(string $country): string
    {
        return ((string) config('gcc.storefronts.queues.prefix', 'storefront-')) . strtolower($country);
    }
}
