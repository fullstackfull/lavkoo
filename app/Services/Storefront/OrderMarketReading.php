<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a customer is told one of their own orders was sold in: the market, the currency and the company that sold it (A2-10).
 *
 * The three facts are already on the order row -- `storefront_country` and `currency_code` from the storefront snapshot, and the frozen
 * company in `entity_snapshot` -- and because a customer's order answers are the Eloquent model as it stands, the columns reach the app
 * by themselves. What they cannot do by themselves is answer for an order that carries NO stamp, which is every order placed before
 * markets existed and every order of a platform that has not switched them on: those columns are null, and a null does not name a market.
 *
 * So this is the READING of the record, beside it and never over it: the raw columns stay exactly what the row holds, and three further
 * keys say what the row MEANS by the written rule --
 *
 *  - the market of an unstamped order is the DEFAULT storefront's, which is {@see StorefrontMarketRows}'s one rule for every table that
 *    has markets, read here and not restated;
 *  - the company of an unstamped order is the platform's own, as `OrderLegalEntitySnapshot` already says in words: an order with no
 *    record is an order of the Saudi company in the platform's settings;
 *  - the currency, alone of the three, can be UNKNOWABLE, and then it says nothing. On a platform that converts from a base currency the
 *    currency an order is shown in is the visitor's own choice, so an unstamped order has no one currency it was sold in, and naming one
 *    would be an invention. {@see StorefrontCurrency::currencyOfSales()} is the judge of that, not this class.
 *
 * The market narrowing is here too, because the question "which of my orders belong to this market" is the same rule read from the other
 * end. It narrows only to a market the CUSTOMER picked: with nothing picked the history is every market, which is what it has always been
 * and what the owner has still to decide, and with storefronts switched off `StorefrontMarketRows` narrows nothing at all.
 */
#[Singleton]
final class OrderMarketReading
{
    public const MARKET = 'sold_in_market';

    public const CURRENCY = 'sold_in_currency';

    public const COMPANY = 'selling_company';

    /** The frozen copy of the selling company an order carries, written by {@see OrderLegalEntitySnapshot}. */
    private const SNAPSHOT_COLUMN = 'entity_snapshot';

    /** What a filter means by "every market", so the history is not narrowed to a market called `all`. */
    private const EVERY_MARKET = 'ALL';

    /** @var array<string, string|null> the currency of sales of a market, which is a query per market and the same for every order of it */
    private array $currencyOfMarket = [];

    public function __construct(
        private readonly StorefrontMarketRows $marketRows,
        private readonly StorefrontCurrency $currency,
    ) {
    }

    /**
     * The reading of one order.
     *
     * @return array{sold_in_market: string, sold_in_currency: string|null, selling_company: string|null}
     */
    public function of(object|array $order): array
    {
        $market = $this->marketOf($order);

        return [
            self::MARKET => $market,
            self::CURRENCY => $this->currencyOf($order, $market),
            self::COMPANY => $this->companyOf($order),
        ];
    }

    /**
     * Writes the reading onto each order of a list, next to the row's own columns.
     *
     * @param iterable<object> $orders
     */
    public function nameOn(iterable $orders): void
    {
        foreach ($orders as $order) {
            foreach ($this->of($order) as $key => $value) {
                $order->{$key} = $value;
            }
        }
    }

    /**
     * Narrows a customer's own order history to the market they picked, and to every market when they picked none.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public function narrowToPickedMarket(Builder $query, ?string $picked): Builder
    {
        $market = $this->canonical($picked);

        if ($market === '' || $market === self::EVERY_MARKET) {
            return $query;
        }

        return $this->marketRows->narrow($query, 'orders', $market);
    }

    /**
     * The markets this customer actually has orders in, for a screen that offers the filter: a market with none of their orders is not
     * offered, so the filter never answers "no orders" for a market the customer has never bought in.
     *
     * Empty while storefronts are off or the column is absent, which is what keeps the screen exactly as it is today.
     *
     * @return list<string>
     */
    public function marketsOfCustomer(int|string|null $customerId): array
    {
        if (!$customerId || !$this->marketRows->applies('orders')) {
            return [];
        }

        $markets = DB::table('orders')
            ->where('customer_id', $customerId)
            ->where('is_guest', 0)
            ->distinct()
            ->pluck(StorefrontMarketRows::COLUMN)
            ->map(fn ($market) => $this->canonical($market) === '' ? $this->marketRows->defaultCountry() : $this->canonical($market))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return count($markets) > 1 ? $markets : [];
    }

    private function marketOf(object|array $order): string
    {
        $stamped = $this->canonical($this->attribute($order, StorefrontMarketRows::COLUMN));

        return $stamped === '' ? $this->marketRows->defaultCountry() : $stamped;
    }

    private function currencyOf(object|array $order, string $market): ?string
    {
        $stamped = $this->canonical($this->attribute($order, 'currency_code'));

        if ($stamped !== '') {
            return $stamped;
        }

        return $this->currencyOfMarket[$market] ??= $this->currency->currencyOfSales($market)?->code;
    }

    /**
     * The company named on the order's own frozen copy; the platform's own company while no order carries one, which is the written rule
     * for every order placed before legal entities existed.
     */
    private function companyOf(object|array $order): ?string
    {
        $snapshot = $this->attribute($order, self::SNAPSHOT_COLUMN);
        $frozen = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;

        if (is_array($frozen)) {
            $named = trim((string) ($frozen['trade_name'] ?? '')) ?: trim((string) ($frozen['legal_name'] ?? ''));

            if ($named !== '') {
                return $named;
            }
        }

        $platform = trim((string) getWebConfig(name: 'company_name'));

        return $platform === '' ? null : $platform;
    }

    /** An order whose table has not got the column answers null for it, which is what an Eloquent model does for an attribute it has not got. */
    private function attribute(object|array $order, string $key): mixed
    {
        return is_array($order) ? ($order[$key] ?? null) : ($order->{$key} ?? null);
    }

    private function canonical(mixed $value): string
    {
        return strtoupper(trim((string) $value));
    }
}
