<?php

namespace App\Services\Returns;

use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\OrderPaymentAccount;
use App\Services\Shipping\DestinationCountryCode;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H1-13, criterion 4: which market's shelf a returned unit goes back to, and the money a cross-border return owes back.
 *
 * A return of a cross-border order has two halves that the returns flow could not previously see, because `ReturnLogisticsService` knew neither a country
 * nor a currency. The physical half: the units came off ONE market's shelf and must go back to that same one. The product's own row is that shelf
 * (`products.storefront_country` is the market a product belongs to), so a return whose product belongs to a market OTHER than the one the order was sold
 * in would put units back on a shelf that never held them -- the stock of a market that made no sale would grow, and the market that did sell would stay
 * short. The financial half: what the customer gets back is owed in the currency their payment was taken in, through the merchant account that took it,
 * not in whatever the platform's default currency happens to be on the day the goods arrive.
 *
 * This is the only place that knows either answer, and it answers NOTHING while `gcc.returns.cross_border.enabled` is off, which is the default: no
 * column is read, no shelf is refused, and a Saudi return restocks exactly the units it restocked before, by the same path, with the same movement row.
 * That is what the first test of this class asserts.
 *
 * While it is on, a disagreement is the ONLY thing that refuses a restock. An order with no market recorded, a product with no market recorded, a return
 * with no order behind it -- every shop that has not switched storefronts on, in other words -- restocks as it always did: a missing record is not a
 * disagreement, and refusing those would stop returns working on the one shop that exists today. Only two markets that are both named and different
 * refuse, and the refusal is reported to the caller and written to the audit trail rather than being silent, because goods are then sitting on a
 * receiving desk that no shelf will take and a person has to decide where they belong.
 *
 * What a refund of such a return owes back is NOT paid out here. This records the currency and the account the money is owed in, from the payment the
 * customer actually made (`App\Services\Storefront\RefundPayout` works out the amount, and whether a refund should give back the original payment or the
 * converted figure is the owner's decision, recorded in D1-10 and not taken here). A return that records the currency it owes is a return whose money
 * cannot quietly become the platform's default currency later.
 */
#[Singleton]
final class CrossBorderReturn
{
    public const SETTING = 'gcc.returns.cross_border.enabled';

    /** The shelf of a market that did not sell the goods: the product belongs to one market, the order was sold in another. */
    public const REFUSAL_SHELF_BELONGS_TO_ANOTHER_MARKET = 'shelf_belongs_to_another_market';

    public const SOURCE_COLUMN = 'source_country';

    public const CURRENCY_COLUMN = 'refund_currency';

    public const ACCOUNT_COLUMN = 'refund_account_id';

    /** @var array<string, bool> */
    private array $tableHasColumn = [];

    /** @var array<string, ?string> */
    private array $marketOfOrder = [];

    /** @var array<string, ?string> */
    private array $marketOfProduct = [];

    public function __construct(
        private readonly OrderPaymentAccount $paymentAccount,
        private readonly GatewayAccountRegistry $accounts,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config(self::SETTING, false);
    }

    /**
     * Why this return may not be restocked, or null when there is no reason it may not be.
     *
     * Null while the switch is off, and null wherever either market is unrecorded: see the class note on why a missing record is not a disagreement.
     */
    public function restockRefusal(int|string|null $orderId, int|string|null $productId): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $sold = $this->marketOfOrder(orderId: $orderId);
        $shelf = $this->marketOfProduct(productId: $productId);

        if ($sold === null || $shelf === null || $sold === $shelf) {
            return null;
        }

        return self::REFUSAL_SHELF_BELONGS_TO_ANOTHER_MARKET;
    }

    /** The market the order was sold in: the shelf a returned unit of it belongs on. */
    public function marketOfOrder(int|string|null $orderId): ?string
    {
        if ($orderId === null || !$this->isEnabled()) {
            return null;
        }

        return $this->marketOfOrder[(string) $orderId] ??= $this->countryColumnOf(
            table: 'orders',
            column: 'storefront_country',
            id: $orderId,
        );
    }

    /** The market the product belongs to, which is the shelf its stock sits on. */
    public function marketOfProduct(int|string|null $productId): ?string
    {
        if ($productId === null || !$this->isEnabled()) {
            return null;
        }

        return $this->marketOfProduct[(string) $productId] ??= $this->countryColumnOf(
            table: 'products',
            column: 'storefront_country',
            id: $productId,
        );
    }

    /**
     * What a return records of the money it owes back: the market its goods came from, the currency the customer's payment was taken in, and the merchant
     * account that took it.
     *
     * Each column is written only when the table has it, so a database that has not run the migration writes the row exactly as it wrote it before. The
     * amount is not recorded here: the refund flow owns that, and which of two amounts a refund gives back is an open decision (D1-10).
     *
     * @return array<string, int|string>
     */
    public function recordOn(string $table, int|string|null $orderId, int|string|null $productId = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $order = $this->orderBehind(orderId: $orderId);

        $record = [
            self::SOURCE_COLUMN => $this->marketOfOrder(orderId: $orderId) ?? $this->marketOfProduct(productId: $productId),
            self::CURRENCY_COLUMN => $this->refundCurrencyOf(order: $order),
            self::ACCOUNT_COLUMN => $this->refundAccountOf(order: $order),
        ];

        return array_filter(
            $record,
            fn (int|string|null $value, string $column): bool => $value !== null && $this->hasColumn(table: $table, column: $column),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** The currency the customer's payment was taken in, which is the currency a refund of it is owed in. */
    public function refundCurrencyOf(object|null $order): ?string
    {
        $recorded = $order === null ? null : trim((string) ($order->currency_code ?? ''));

        return $recorded === '' ? null : $recorded;
    }

    /** The merchant account that took the payment, so that what is given back leaves the account that received it. */
    public function refundAccountOf(object|null $order): ?int
    {
        if ($order === null || !$this->accounts->isEnabled()) {
            return null;
        }

        return $this->paymentAccount->of(order: $order)?->accountId;
    }

    public function forget(): void
    {
        $this->tableHasColumn = [];
        $this->marketOfOrder = [];
        $this->marketOfProduct = [];
    }

    private function orderBehind(int|string|null $orderId): ?object
    {
        if ($orderId === null || !Schema::hasTable('orders')) {
            return null;
        }

        return DB::table('orders')->where('id', $orderId)->first();
    }

    private function countryColumnOf(string $table, string $column, int|string $id): ?string
    {
        if (!$this->hasColumn(table: $table, column: $column)) {
            return null;
        }

        return DestinationCountryCode::of(DB::table($table)->where('id', $id)->value($column));
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->tableHasColumn["{$table}.{$column}"] ??= Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
}
