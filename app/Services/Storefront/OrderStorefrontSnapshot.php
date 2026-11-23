<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * What an order was sold in (GCC-05): the storefront whose products it holds, the currency its amounts are in, and the rate that turns a stored
 * amount into that currency. It is read from the lines the order was saved with, not from the request, so every path that creates an order (the
 * web and API checkout, both POS screens, the seller app's POS) records the same thing, and an order saved by a queue or a console command
 * would too. An amount of a storefront with its own currency is stored as it is, so the rate is one.
 *
 * Nothing is recorded while storefront currencies are off or before the orders migration has run, an order whose lines belong to more than one
 * country gets no storefront, and a record is written once: a later change of the settings never rewrites what an order was sold in. An order
 * with no record is read as an order of the default storefront in the platform's own currency settings, as every order was before.
 *
 * The order's record is then copied onto the money rows that belong to it (D1-06) by {@see stampMoneyRows()}, so a line, a payment transaction
 * or a refund carries the currency of its own amount and no reader has to ask the settings a second time.
 */
#[Singleton]
final class OrderStorefrontSnapshot
{
    public const COLUMN = 'storefront_country';

    private const CURRENCY = 'currency_code';

    private const RATE = 'currency_rate';

    /** The tables whose rows hold an amount of one order, and so are counted in that order's own currency. */
    public const MONEY_ROW_TABLES = ['order_details', 'order_transactions', 'refund_requests', 'refund_transactions'];

    /** How long a "no" is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array<string, array{value: bool, at: int}> */
    private array $columnAnswers = [];

    public function __construct(
        private readonly StorefrontCurrency $storefrontCurrency,
        private readonly OrderLegalEntitySnapshot $legalEntitySnapshot,
    ) {
    }

    public function record(int|string $orderId): void
    {
        $recordsCurrency = $this->storefrontCurrency->isEnabled() && $this->columnReady();

        if ((!$recordsCurrency && !$this->legalEntitySnapshot->recordsOrders()) || !StorefrontCatalog::tracksProductCountry()) {
            return;
        }

        // countryOfLines, not countryOfMoney: this guard is the integration side's and asks the lines' own country.
        // countryOfMoney is this branch's separate public reader and keeps its own callers.
        $country = $this->countryOfLines(orderId: $orderId);

        if ($country === null) {
            return;
        }

        if ($recordsCurrency) {
            $this->recordCurrency(orderId: $orderId, country: $country);
        }

        $this->legalEntitySnapshot->record(orderId: $orderId, country: $country);
    }

    private function recordCurrency(int|string $orderId, string $country): void
    {
        $requestCountry = StorefrontCatalog::country();
        $currency = $requestCountry === null || $requestCountry === $country ? $this->storefrontCurrency->currencyOfSales($country) : null;

        DB::table('orders')->where('id', $orderId)->whereNull(self::COLUMN)->update([
            self::COLUMN => $country,
            self::CURRENCY => $currency?->code,
            self::RATE => $currency === null ? null : 1,
        ]);
    }

    /**
     * Writes the order's own currency onto the money rows it has in one table, so a line, a payment transaction or a refund says what its amount
     * is counted in without anything having to ask the settings again.
     *
     * The order's SAVED record is the only source. Not the request, which belongs to whoever is looking and is gone by the time a refund is
     * approved; not the settings, which may have been changed since; not the session, which a queue worker and a console command do not have.
     * An order placed before the snapshot columns, an order of the default storefront on a platform that converts from a base currency, and an
     * order written by a path that records no storefront all carry no currency, so nothing is written for them and every such row stays exactly
     * as it was. Nothing is written before the migration has run either.
     *
     * It must be called AFTER {@see record()} has stamped the order: the lines of an order are saved before the order can be read for the one
     * country they belong to, so a line asked at the moment it is inserted would find the order still empty and record nothing. Only rows that
     * carry no currency yet are written, so calling it twice, or again after a retried transaction, records the same thing once.
     */
    public function stampMoneyRows(string $table, int|string $orderId): void
    {
        if (!in_array($table, self::MONEY_ROW_TABLES, true)) {
            throw new InvalidArgumentException($table . ' does not hold the money rows of an order.');
        }

        // Both columns, not just the one that is read back: SQLite appends them in two statements, so a
        // migration that stopped between them would leave a table this could write a code to and not a
        // rate, and a throw here would roll back the checkout that is placing the order.
        if (!$this->columnExists(table: $table, column: self::CURRENCY) || !$this->columnExists(table: $table, column: self::RATE)) {
            return;
        }

        $order = DB::table('orders')->where('id', $orderId)->first([self::CURRENCY, self::RATE]);
        $code = $order === null ? null : $order->{self::CURRENCY};

        if (!is_string($code) || trim($code) === '') {
            return;
        }

        DB::table($table)->where('order_id', $orderId)->whereNull(self::CURRENCY)->update([
            self::CURRENCY => $code,
            self::RATE => $order->{self::RATE},
        ]);
    }

    /**
     * The storefront whose money an order's amounts are counted in, as {@see record()} reads it: the one country the products of its lines
     * belong to, or null when there is none, more than one, or nothing is recorded on this platform at all.
     *
     * A writer that saves an amount of its own alongside the lines — a till saving what was paid and what the coupon took off — asks this so it
     * can store that amount in the same unit as the lines it has just written, which is the unit the order's record then says they are all in.
     */
    public function countryOfMoney(int|string $orderId): ?string
    {
        if (!$this->storefrontCurrency->isEnabled() || !StorefrontCatalog::tracksProductCountry()) {
            return null;
        }

        return $this->countryOfLines(orderId: $orderId);
    }

    /** The one country the products of the order's lines belong to, or null when there is none or more than one. */
    private function countryOfLines(int|string $orderId): ?string
    {
        $countries = DB::table('order_details')
            ->join('products', 'products.id', '=', 'order_details.product_id')
            ->where('order_details.order_id', $orderId)
            ->distinct()
            ->pluck('products.' . StorefrontCatalog::COLUMN);

        return $countries->count() === 1 ? strtoupper((string) $countries->first()) : null;
    }

    /** Whether the orders migration has run, that is whether an order can hold a record at all. The question OrderStorefront asks. */
    public function columnReady(): bool
    {
        return $this->columnExists(table: 'orders', column: self::COLUMN);
    }

    /**
     * Whether one column of one table is there. Named apart from columnReady() because both are needed and PHP has only one
     * name per method: the no-argument one above is the question the rest of the application asks, this is the one this class
     * asks of each column it writes.
     */
    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        $answer = $this->columnAnswers[$key] ?? null;

        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::NEGATIVE_ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $ready = Schema::hasTable($table) && Schema::hasColumn($table, $column);
        $this->columnAnswers[$key] = ['value' => $ready, 'at' => time()];

        return $ready;
    }
}
