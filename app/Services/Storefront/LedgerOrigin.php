<?php

namespace App\Services\Storefront;

use App\Enums\LedgerActor;

/**
 * What a ledger writer already knows about the movement it records (E-05): the order, the checkout, the refund or the payment behind it, and the staff member who made it. It
 * only carries what the caller has, so building one reads nothing; LedgerAttribution turns it into the columns of the ledger row, once the migration has run. A movement made by
 * a customer or by the system has no actor, and one whose order does not exist yet (a point-of-sale debit is taken before its order is saved) has no source.
 */
final readonly class LedgerOrigin
{
    public const ORDER = 'order';

    public const ORDER_GROUP = 'order_group';

    public const REFUND = 'refund';

    public const PAYMENT = 'payment';

    private function __construct(
        public ?string $sourceType,
        public int|string|null $subject,
        public ?LedgerActor $actor,
    ) {
    }

    public static function order(int|string $id, ?LedgerActor $by = null): self
    {
        return new self(sourceType: self::ORDER, subject: $id, actor: $by);
    }

    /** One debit of the wallet pays every order a checkout created, so the row names the order group they share, found from the first of them. */
    public static function checkout(array $orderIds): self
    {
        return new self(sourceType: self::ORDER_GROUP, subject: array_values($orderIds)[0] ?? null, actor: null);
    }

    public static function refund(int|string $id, ?LedgerActor $by = null): self
    {
        return new self(sourceType: self::REFUND, subject: $id, actor: $by);
    }

    /** The payment of a gateway top-up: the id of its row in payment_requests. */
    public static function payment(int|string $id): self
    {
        return new self(sourceType: self::PAYMENT, subject: $id, actor: null);
    }

    /** A manual movement with no order, payment or refund behind it, such as a top-up by an administrator. */
    public static function by(LedgerActor $actor): self
    {
        return new self(sourceType: null, subject: null, actor: $actor);
    }
}
