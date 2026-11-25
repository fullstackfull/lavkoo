<?php

namespace App\Services\PlatformAdmin;

/**
 * The names of the topics a platform admin's device may be subscribed to (L-16). The SERVER names them and a device never asks for one.
 *
 * Two rules, and the second is the one with teeth:
 *
 *  - a topic is derived from what the admin's GRANT allows: one per market they may see, and one for the level they hold. A device of a
 *    restricted viewer is subscribed to their own markets and to nothing else, so a market they may not see cannot reach their phone even by
 *    push -- which is the one channel that arrives without a request to check;
 *  - every name starts with `lavkoo_pa_`, a prefix no other part of this platform uses, and the names of the topics this platform already
 *    publishes to sellers, customers and marketing are listed here so that a test can say the two sets are disjoint. A topic the admin shell
 *    shared with the seller app would mean a seller's phone receiving a platform alert, or an admin receiving a maintenance notice meant for
 *    shops, and neither is a thing to find out in production.
 */
final class PlatformAdminTopics
{
    /** The prefix of every admin topic. Nothing else on this platform publishes to a topic beginning with it. */
    public const PREFIX = 'lavkoo_pa_';

    /**
     * The topics this platform publishes to somebody who is NOT a platform admin. No admin topic may equal one of these, and the test that says
     * so reads this list, so a topic added to the seller or customer side has to be added here before it can collide unnoticed.
     *
     * `lavkoo_seller` and `maintenance_mode_start_vendor` are the seller app's own (SellerPushTopics in lavkooappseller); the market variants are
     * what K-11 added beside them.
     */
    public const NOT_OURS = [
        'lavkoo_seller',
        'maintenance_mode_start_vendor',
        'lavkoo_customer',
        'lavkoo_marketing',
    ];

    public function __construct(private readonly PlatformMarkets $markets)
    {
    }

    /**
     * The topics for this principal: one per market their grant allows, and one for their level.
     *
     * @return list<string>
     */
    public function forPrincipal(PlatformAdminPrincipal $principal): array
    {
        $topics = [self::PREFIX . 'level_' . $principal->level()];

        foreach ($principal->markets as $market) {
            $topics[] = self::PREFIX . strtolower($market);
        }

        return array_values(array_unique($topics));
    }

    /** Every topic this platform could name for an admin, for a test that wants to compare the whole set with somebody else's. */
    public function all(): array
    {
        $topics = [];

        foreach ($this->markets->codes() as $code) {
            $topics[] = self::PREFIX . strtolower($code);
        }

        return array_values(array_unique([...$topics, self::PREFIX . 'level_1']));
    }

    /**
     * Whether a name is one of this API's own topics: one the SERVER would name, and not merely one that carries the prefix. A market nobody
     * configured is not a topic of this platform however the name is spelt, so a figure or an alert of such a market is sent nowhere.
     */
    public function isOurs(string $topic): bool
    {
        return str_starts_with($topic, self::PREFIX) && !in_array($topic, self::NOT_OURS, true) && in_array($topic, $this->all(), true);
    }
}
