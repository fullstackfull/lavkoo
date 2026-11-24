<?php

namespace App\Services\Shipping;

use App\Services\Fulfillment\Support\DestinationCountry;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps on the order what was quoted for customs duty (H1-09), beside what was decided about shipping it (H1-06).
 *
 * Written once and never rewritten, for the reason the shipping decision is: a tariff changes, a rule is written or withdrawn, and an
 * order recomputed a year later would show a figure nobody ever charged. What the customer was told at checkout is what the order must
 * carry, so this records the quote as it was given and leaves it alone afterwards.
 *
 * Silent while duty is off, and silent before its column exists, so the platform as it runs today writes nothing at all.
 */
final class OrderCustomsQuote
{
    private const COLUMN = 'customs_quote';

    /** @var bool|null whether the column exists; not re-read for every order of a batch */
    private static ?bool $columnReady = null;

    public static function record(int|string|null $orderId): ?CustomsDutyQuote
    {
        if ($orderId === null || !CustomsDuty::isOn() || !self::columnReady()) {
            return null;
        }

        $order = DB::table('orders')->where('id', $orderId)->first(['id', self::COLUMN, 'order_amount']);

        // Written once. An order that already carries a quote keeps the one it was taken under.
        if ($order === null || $order->{self::COLUMN} !== null) {
            return null;
        }

        $quote = CustomsDuty::quoteFor(
            source: self::sellingMarketOf($orderId),
            destination: self::deliveredToOf($orderId),
            value: (float) ($order->order_amount ?? 0),
        );

        DB::table('orders')->where('id', $orderId)->update([
            self::COLUMN => json_encode($quote->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $quote;
    }

    /** What was quoted for an order, or null when it carries no quote -- which is every order taken before this existed. */
    public static function of(int|string|null $orderId): ?CustomsDutyQuote
    {
        if ($orderId === null || !self::columnReady()) {
            return null;
        }

        $stored = DB::table('orders')->where('id', $orderId)->value(self::COLUMN);
        $quote = is_string($stored) ? json_decode($stored, associative: true) : null;

        if (!is_array($quote) || !isset($quote['kind'])) {
            return null;
        }

        return new CustomsDutyQuote(
            kind: (string) $quote['kind'],
            amount: (float) ($quote['amount'] ?? 0),
            payer: isset($quote['payer']) ? (string) $quote['payer'] ?: null : null,
            basis: (float) ($quote['basis'] ?? 0),
            route: isset($quote['route']) ? (string) $quote['route'] ?: null : null,
        );
    }

    /** Tests build and drop the column inside one process, so what was read once is not remembered. */
    public static function forget(): void
    {
        self::$columnReady = null;
    }

    /**
     * The market the order was SOLD in, which is the country the goods leave.
     *
     * {@see DestinationCountry::ofOrder} is that record. Its own name reads the other way round, and for every order this platform has
     * taken the two were the same thing: a customer bought from the storefront of the country they were standing in, so the market was
     * both where the goods were and where they were going. A cross-border order is the first case where they differ, which is why duty
     * asks for them separately -- the market here, the delivery address below -- rather than taking one for the other.
     */
    private static function sellingMarketOf(int|string $orderId): ?string
    {
        return DestinationCountry::ofOrder($orderId) ?? StorefrontResolver::fromConfig()->defaultCountry();
    }

    /** The country the parcel is going to, out of the address copy the order keeps, which is the address as it was that day. */
    private static function deliveredToOf(int|string $orderId): ?string
    {
        $stored = DB::table('orders')->where('id', $orderId)->value('shipping_address_data');
        $address = is_string($stored) ? json_decode($stored, associative: true) : null;

        return is_array($address) && is_string($address['country'] ?? null) ? $address['country'] : null;
    }

    private static function columnReady(): bool
    {
        return self::$columnReady ??= Schema::hasColumn('orders', self::COLUMN);
    }
}
