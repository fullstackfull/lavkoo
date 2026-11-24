<?php

namespace App\Services\Shipping;

/**
 * What was decided about customs duty on one order (H1-09): whether it could be quoted at all, how much, on what, and who pays it.
 *
 * The requirement's hardest sentence is the one about failure: a route whose duty cannot be determined refuses the order, and never
 * charges zero. So "not quoted" is a state of its own and the DEFAULT one, told apart from "no duty is payable". A zero is an answer -- it
 * says the goods cross free -- and nobody may give that answer on the platform's behalf without a rule to give it from.
 *
 * Who pays is a commercial term rather than a calculation: the buyer settling it with the carrier on delivery and the seller paying it up
 * front are different prices and different promises. A rule that names an amount and no payer is therefore not a quote either.
 */
final readonly class CustomsDutyQuote
{
    /** Nobody has written a rule for this route, so no amount may be claimed -- not even zero. */
    public const NOT_QUOTED = 'not_quoted';

    /** The route is quotable and nothing is payable: a domestic order, or a value under the destination's de minimis. */
    public const NONE = 'none';

    /** An amount is payable, by the named payer. */
    public const PAYABLE = 'payable';

    /** The buyer settles it with the carrier when the parcel arrives. */
    public const BUYER_ON_DELIVERY = 'buyer_on_delivery';

    /** The seller pays it and the customer sees one price. */
    public const SELLER = 'seller';

    public function __construct(
        public string $kind,
        public float $amount = 0.0,
        public ?string $payer = null,
        public float $basis = 0.0,
        public ?string $route = null,
    ) {
    }

    /** The default answer, and the honest one until somebody writes the route's rule down. */
    public static function notQuoted(?string $route = null): self
    {
        return new self(kind: self::NOT_QUOTED, route: $route);
    }

    public static function none(?string $route = null): self
    {
        return new self(kind: self::NONE, route: $route);
    }

    public static function payable(float $amount, string $payer, float $basis, ?string $route = null): self
    {
        return new self(kind: self::PAYABLE, amount: $amount, payer: $payer, basis: $basis, route: $route);
    }

    public function isQuoted(): bool
    {
        return $this->kind !== self::NOT_QUOTED;
    }

    public function isPayable(): bool
    {
        return $this->kind === self::PAYABLE;
    }

    /** As it is stored on an order, so that what was quoted stays quoted when the rules change later. */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'amount' => round($this->amount, 3),
            'payer' => $this->payer,
            'basis' => round($this->basis, 3),
            'route' => $this->route,
        ];
    }
}
