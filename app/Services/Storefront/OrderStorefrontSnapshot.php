<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an order was sold in (GCC-05): the storefront whose products it holds, the currency its amounts are in, and the rate that turns a stored
 * amount into that currency. It is read from the lines the order was saved with, not from the request, so every path that creates an order (the
 * web and API checkout, both POS screens, the seller app's POS) records the same thing, and an order saved by a queue or a console command
 * would too. An amount of a storefront with its own currency is stored as it is, so the rate is one.
 *
 * Nothing is recorded while storefront currencies are off or before the orders migration has run, an order whose lines belong to more than one
 * country gets no storefront, and a record is written once: a later change of the settings never rewrites what an order was sold in. An order
 * with no record is read as an order of the default storefront in the platform's own currency settings, as every order was before.
 */
#[Singleton]
final class OrderStorefrontSnapshot
{
    private const COLUMN = 'storefront_country';

    /** How long a "no" is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private ?array $columnAnswer = null;

    public function __construct(private readonly StorefrontCurrency $storefrontCurrency)
    {
    }

    public function record(int|string $orderId): void
    {
        if (!$this->storefrontCurrency->isEnabled() || !$this->columnReady() || !StorefrontCatalog::tracksProductCountry()) {
            return;
        }

        $country = $this->countryOfLines(orderId: $orderId);

        if ($country === null) {
            return;
        }

        $requestCountry = StorefrontCatalog::country();
        $currency = $requestCountry === null || $requestCountry === $country ? $this->storefrontCurrency->currencyOfSales($country) : null;

        DB::table('orders')->where('id', $orderId)->whereNull(self::COLUMN)->update([
            self::COLUMN => $country,
            'currency_code' => $currency?->code,
            'currency_rate' => $currency === null ? null : 1,
        ]);
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

    private function columnReady(): bool
    {
        $answer = $this->columnAnswer;

        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::NEGATIVE_ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $ready = Schema::hasColumn('orders', self::COLUMN);
        $this->columnAnswer = ['value' => $ready, 'at' => time()];

        return $ready;
    }
}
