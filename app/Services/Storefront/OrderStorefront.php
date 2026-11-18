<?php

namespace App\Services\Storefront;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The storefront an order was sold in, as the seller app reads it and narrows by it (K-04): the reading side of OrderStorefrontSnapshot, which writes it.
 *
 * Nothing here acts while storefronts are switched off, so an answer is what it was before, whatever the app sends. While they are on, an order the
 * seller API answers carries the two things its record holds, `storefront_country` and `currency_code`, and the list can be narrowed to one market.
 * A record that was never written (an order from before the snapshot, from a time the switches were off, or one whose lines span two countries) is
 * null: it is not made up. Such an order belongs to the default storefront, as the platform reads every order without a record, so the default
 * market also holds them and the other markets never do.
 *
 * A market is narrowed by what the orders recorded, not by what is configured now: the orders a storefront sold stay in its market after it is
 * switched off or taken out of the configuration. The seller's own scope is never widened: callers narrow a query that is already the seller's.
 */
final class OrderStorefront
{
    /** What an order says of its market. They are part of the seller API's answer while storefronts are on, whatever the model hides from every other. */
    private const LABELS = [OrderStorefrontSnapshot::COLUMN, 'currency_code'];

    private const COUNTRY_CODE = '/^[A-Z]{2}$/';

    /** What is asked for when what the app sent is not even a text: a market that nothing is sold in. */
    private const NO_MARKET = '';

    public function __construct(private readonly OrderStorefrontSnapshot $snapshot)
    {
    }

    /** The market the request asks to see, or null while storefronts are off and when it asks for none. A value that is no code is a market nothing matches. */
    public function requestedBy(Request $request): ?string
    {
        if (!$this->isOn()) {
            return null;
        }

        $requested = $request->input('storefront');

        if ($requested === null || $requested === '') {
            return null;
        }

        return is_string($requested) ? $requested : self::NO_MARKET;
    }

    /** Keeps the orders of one market: the default market also keeps the orders with no record, and before the orders migration has run it keeps all of them. */
    public function narrow(Builder $orders, string $country): Builder
    {
        $country = strtoupper(trim($country));

        if (preg_match(self::COUNTRY_CODE, $country) !== 1) {
            return $orders->whereRaw('1 = 0');
        }

        $isDefault = $country === StorefrontResolver::fromConfig()->defaultCountry();

        if (!$this->snapshot->columnReady()) {
            return $isDefault ? $orders : $orders->whereRaw('1 = 0');
        }

        $column = $orders->qualifyColumn(OrderStorefrontSnapshot::COLUMN);

        return $isDefault
            ? $orders->where(fn (Builder $inner): Builder => $inner->whereNull($column)->orWhere($column, $country))
            : $orders->where($column, $country);
    }

    /**
     * Makes each order carry its storefront and its currency, as null where it has none, and as the seller sees them even where the model hides its record.
     *
     * @param iterable<Order|null> $orders
     */
    public function label(iterable $orders): void
    {
        if (!$this->isOn()) {
            return;
        }

        foreach ($orders as $order) {
            if (!$order instanceof Order) {
                continue;
            }

            $order->makeVisible(self::LABELS);

            foreach (self::LABELS as $label) {
                $order->setAttribute($label, $order->getAttributes()[$label] ?? null);
            }
        }
    }

    private function isOn(): bool
    {
        return StorefrontResolver::fromConfig()->isEnabled();
    }
}
