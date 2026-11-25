<?php

namespace App\Services\Fulfillment\Support;

/**
 * What the checkout does with a line whose stored execution source this installation does not know (C-03, finding E11-F05).
 *
 * `products.fulfillment_provider` is a free text column holding a carrier code. Three things put a value in it that the platform
 * cannot resolve: a carrier taken out of `config/fulfillment.php` while products still name it, an import that wrote a supplier's
 * own spelling, and a hand edit. Until this existed every one of them was read as "in-house", so a product somebody had assigned to
 * a warehouse was packed by the shop without a word -- the one outcome the owner never chose.
 *
 * The admin screens stopped calling such a value in-house when {@see \App\Services\Fulfillment\ProductSource::UNKNOWN} was added:
 * the product list shows it as its own danger badge. The checkout did not, and the checkout is where it costs money.
 *
 * Which of the two safe answers is right is the owner's decision, because they fail in opposite directions: REFUSE protects the
 * warehouse and loses the sale, HOLD takes the money and makes a person look at the line before anything ships. Both are reachable
 * here and the default is the platform exactly as it runs today, so a Saudi shop that upgrades changes nothing.
 *
 * This is NOT about a carrier the configuration declares. Such a carrier is known -- {@see \App\Services\Fulfillment\ProviderRegistry}
 * answers for it -- and its lines are snapshotted like any other; reading only the two enum cases was the defect this setting sits
 * beside, not the setting's subject.
 */
final class WhatAnUnknownSourceDoes
{
    /** The default: the line is fulfilled in-house, writes no ownership snapshot, and the order is placed. Today's platform. */
    public const IN_HOUSE_TODAY = 'in_house_today';

    /** Option B: the checkout refuses the line, which rolls the order transaction back. Nothing is sold that nobody can ship. */
    public const REFUSE = 'refuse';

    /**
     * Option C: the order is placed and the line is frozen with the source it actually names, unrouted.
     *
     * Nothing dispatches it: {@see \App\Services\Fulfillment\FulfillmentRouter::route()} resolves each group's code through the
     * registry and skips a group it cannot resolve, so the line waits for a person instead of being packed by the shop. That is
     * what "held" means here -- a snapshot nothing can route -- and it needs no new status, because `unrouted` already is one.
     */
    public const HOLD = 'hold';

    /** Which rule is in force. An unrecognised value is read as the default, so a typo in the environment cannot stop a checkout. */
    public static function rule(): string
    {
        $named = (string) config('fulfillment.unknown_source');

        return in_array($named, [self::REFUSE, self::HOLD], true) ? $named : self::IN_HOUSE_TODAY;
    }

    public static function refuses(): bool
    {
        return self::rule() === self::REFUSE;
    }

    public static function holds(): bool
    {
        return self::rule() === self::HOLD;
    }
}
