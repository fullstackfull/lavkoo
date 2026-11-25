<?php

namespace App\Services\Checkout;

use App\Services\Storefront\OrderStorefrontSnapshot;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders placed before an order recorded what it was sold in, given that record (D1-06).
 *
 * Every order on this platform carries no currency: the snapshot columns arrived after them, and a reader of an
 * order with no record falls back to today's settings and today's rate. That fallback is right for a single-market
 * platform and wrong the moment a second currency exists, because an invoice reprinted next year would be read
 * against next year's settings. The record has to be written down once, from what can actually be known.
 *
 * **WHAT CAN BE KNOWN, AND WHAT CANNOT.** Under `single_currency` the platform converts nothing: an order's stored
 * amounts ARE in the one default currency, and every surface showed that currency to everybody. So the record is
 * not a guess -- the code is the default currency's and the rate is one, meaning "the stored amount is already in
 * it". Under `multi_currency` the stored amounts are in the platform's base unit and the currency each customer
 * was SHOWN came from their session, which no row remembers. There is no way back to what a given order was sold
 * in, so this REFUSES rather than stamping a currency somebody could later reprint an invoice from. A refusal that
 * names its reason is the only honest answer there, and it is not a limitation to be worked around later: the
 * information is gone.
 *
 * **IT NEVER TOUCHES AN AMOUNT.** Not one column of money is in any statement it runs -- it writes
 * `storefront_country`, `currency_code` and `currency_rate` and nothing else, and it proves that rather than
 * claiming it: the sum of `orders.order_amount` is read before and after and the two must be identical, or the run
 * reports that it does not balance. The same for each money-row table it stamps.
 *
 * **IT IS A READ UNTIL SOMEBODY SAYS OTHERWISE.** {@see plan()} writes nothing and is what the command runs by
 * default. Only {@see apply()} writes, and it writes only where the three columns are still empty, so running it
 * twice stamps the same orders once.
 */
#[Singleton]
final class OrderCurrencyBackfill
{
    public const REFUSED_MULTI_CURRENCY = 'the_platform_converts_from_a_base_currency_so_an_old_orders_currency_is_not_recorded_anywhere';

    public const REFUSED_NO_COLUMNS = 'the_order_snapshot_migration_has_not_run';

    public const REFUSED_NO_DEFAULT_CURRENCY = 'the_platform_has_no_default_currency_row';

    private const COUNTRY = 'storefront_country';

    private const CURRENCY = 'currency_code';

    private const RATE = 'currency_rate';

    public function __construct(private readonly OrderStorefrontSnapshot $snapshot)
    {
    }

    /**
     * What a run would do, having written nothing.
     *
     * @return array{refusal: ?string, currency: ?string, country: ?string, orders: int, money_rows: array<string, int>, amount: ?string}
     */
    public function plan(?int $limit = null): array
    {
        $refusal = $this->refusal();

        if ($refusal !== null) {
            return ['refusal' => $refusal, 'currency' => null, 'country' => null, 'orders' => 0, 'money_rows' => [], 'amount' => null];
        }

        $moneyRows = [];
        foreach (OrderStorefrontSnapshot::MONEY_ROW_TABLES as $table) {
            $moneyRows[$table] = $this->moneyRowsWaiting(table: $table);
        }

        return [
            'refusal' => null,
            'currency' => $this->defaultCurrencyCode(),
            'country' => StorefrontResolver::fromConfig()->defaultCountry(),
            'orders' => $this->ordersWaiting()->count(),
            'money_rows' => $moneyRows,
            'amount' => $this->totalOfOrdersWaiting(),
        ];
    }

    /**
     * Writes the record, in batches, and reconciles.
     *
     * @return array{refusal: ?string, currency: ?string, orders: int, money_rows: array<string, int>, amount_before: ?string, amount_after: ?string, balances: bool}
     */
    public function apply(?int $limit = null): array
    {
        $refusal = $this->refusal();

        if ($refusal !== null) {
            return ['refusal' => $refusal, 'currency' => null, 'orders' => 0, 'money_rows' => [], 'amount_before' => null, 'amount_after' => null, 'balances' => false];
        }

        $country = StorefrontResolver::fromConfig()->defaultCountry();
        $currency = $this->defaultCurrencyCode();
        // Read over EVERY order and not only the ones being stamped: an amount that moved on an order this run did
        // not touch would be invisible to a sum taken over the ones it did.
        $before = $this->totalOfEveryOrder();

        $ids = $this->ordersWaiting()->orderBy('id')->limit($limit ?? PHP_INT_MAX)->pluck('id')->all();
        $stamped = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $stamped += DB::table('orders')
                ->whereIn('id', $chunk)
                ->whereNull(self::COUNTRY)
                ->whereNull(self::CURRENCY)
                ->update([self::COUNTRY => $country, self::CURRENCY => $currency, self::RATE => 1]);
        }

        $moneyRows = array_fill_keys(OrderStorefrontSnapshot::MONEY_ROW_TABLES, 0);

        foreach ($ids as $orderId) {
            foreach (OrderStorefrontSnapshot::MONEY_ROW_TABLES as $table) {
                $moneyRows[$table] += $this->stampTheMoneyRowsOf(table: $table, orderId: $orderId);
            }
        }

        $after = $this->totalOfEveryOrder();

        return [
            'refusal' => null,
            'currency' => $currency,
            'orders' => $stamped,
            'money_rows' => $moneyRows,
            'amount_before' => $before,
            'amount_after' => $after,
            'balances' => $before === $after && $stamped === count($ids),
        ];
    }

    /** Why a run would do nothing, or null when it can go ahead. */
    public function refusal(): ?string
    {
        if (!$this->columnsExist()) {
            return self::REFUSED_NO_COLUMNS;
        }

        if (getWebConfig(name: 'currency_model') === 'multi_currency') {
            return self::REFUSED_MULTI_CURRENCY;
        }

        return $this->defaultCurrencyCode() === null ? self::REFUSED_NO_DEFAULT_CURRENCY : null;
    }

    /** The orders that carry no record at all. An order that carries one is never rewritten: it knows better than this does. */
    private function ordersWaiting()
    {
        return DB::table('orders')->whereNull(self::COUNTRY)->whereNull(self::CURRENCY);
    }

    private function moneyRowsWaiting(string $table): int
    {
        if (!Schema::hasColumn($table, self::CURRENCY)) {
            return 0;
        }

        return DB::table($table)
            ->whereNull(self::CURRENCY)
            ->whereIn('order_id', $this->ordersWaiting()->select('id'))
            ->count();
    }

    /** The snapshot writer's own stamping, so a backfilled row is written by the same code a new order's row is. */
    private function stampTheMoneyRowsOf(string $table, int|string $orderId): int
    {
        if (!Schema::hasColumn($table, self::CURRENCY)) {
            return 0;
        }

        $before = DB::table($table)->where('order_id', $orderId)->whereNull(self::CURRENCY)->count();
        $this->snapshot->stampMoneyRows(table: $table, orderId: $orderId);

        return $before - DB::table($table)->where('order_id', $orderId)->whereNull(self::CURRENCY)->count();
    }

    /** As text, and summed in the database: a wide decimal read into a float and added is how a total stops matching its rows. */
    private function totalOfEveryOrder(): ?string
    {
        $total = DB::table('orders')->selectRaw('SUM(order_amount) AS total')->value('total');

        return $total === null ? null : (string) $total;
    }

    private function totalOfOrdersWaiting(): ?string
    {
        $total = $this->ordersWaiting()->selectRaw('SUM(order_amount) AS total')->value('total');

        return $total === null ? null : (string) $total;
    }

    private function columnsExist(): bool
    {
        foreach ([self::COUNTRY, self::CURRENCY, self::RATE] as $column) {
            if (!Schema::hasColumn('orders', $column)) {
                return false;
            }
        }

        return true;
    }

    /** The code of the currency the platform keeps its figures in, or null when the row it names is gone. */
    private function defaultCurrencyCode(): ?string
    {
        $code = DB::table('currencies')->where('id', getWebConfig(name: 'system_default_currency'))->value('code');

        return is_string($code) && trim($code) !== '' ? strtoupper(trim($code)) : null;
    }
}
