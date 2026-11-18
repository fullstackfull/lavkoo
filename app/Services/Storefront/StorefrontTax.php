<?php

namespace App\Services\Storefront;

use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Modules\TaxModule\app\Models\Tax;

/**
 * GCC-05 and D1-07: the tax an order is placed under follows the storefront it is placed from, and is never the Saudi platform's. The tax module keeps one default
 * setup for the whole platform, which is Saudi. A storefront other than the default one is taxed by the active default setup whose `country_code` is its own and, while
 * it has none, by nothing: its orders carry no tax and no row is written for it. That holds whether or not the currency switch is on, because the Saudi rates are not a
 * legal substitute for another country's. The platform's setup is the row no storefront owns: a row carrying the code of one of the other configured storefronts is
 * never the platform's, so adding a storefront's setup cannot change what the Saudi storefront, a panel or a queue reads. While storefronts are off, and for the default
 * storefront, a request and a console command read exactly what they read before.
 *
 * The rates themselves are the owner's to give per country and per selling company; nothing here chooses one. Whether a storefront may sell on the tax it has is
 * StorefrontTaxApproval's to say.
 */
#[Singleton]
final class StorefrontTax
{
    public const PAYER = 'vendor';

    /** The type of the setup a storefront without one of its own is taxed with: order-wise with no rate. */
    public const NO_TAX_TYPE = 'order_wise';

    public function __construct(private readonly StorefrontCurrency $currency)
    {
    }

    /** The storefront whose own tax setup the request reads, or null while the platform's setup stands: the default storefront, a panel, a queue and storefronts that are off. */
    public function countryInForce(): ?string
    {
        return $this->currency->storefrontInForce();
    }

    /**
     * Runs the callback in the market of an order: the storefront its record names, or the default storefront for an order that has none. A panel sees every country, so
     * the edit of a Kuwaiti order has to be taxed under the setup the order was placed under, not under the platform's.
     */
    public function withinOrder(object|array $order, Closure $callback): mixed
    {
        return $this->currency->withinOrder(order: $order, callback: $callback);
    }

    /** The key the tax configuration of one payer is cached under: the key the platform has always used, with the country of a storefront in force added. */
    public function cacheKey(string $payer, bool $withVat): string
    {
        $country = $this->countryInForce();

        return "tax_system_type_{$payer}_" . ($withVat ? 'with_vat' : 'no_vat') . ($country === null ? '' : "_{$country}");
    }

    /**
     * The setup the request is taxed under: its storefront's own, else (for a storefront other than the default one) a setup that is never saved and charges nothing, else
     * the platform's, else null (the caller starts the platform's first setup, as it always did).
     */
    public function setupInForce(string $payer): ?SystemTaxSetup
    {
        $country = $this->countryInForce();

        if ($country === null) {
            return $this->platformSetup(payer: $payer);
        }

        return $this->ownSetup(payer: $payer, country: $country) ?? $this->noTaxSetup(payer: $payer, country: $country);
    }

    /** Whether the tax module has its table, which the readiness report needs before it can say anything about a storefront's tax. */
    public function tracksSetups(): bool
    {
        return Schema::hasTable('system_tax_setups');
    }

    /**
     * What the orders of a storefront are taxed with, for the readiness report and for its approval: whether the setup is its own, its type, whether the prices include
     * the tax, the rates of an order-wise setup (a product-wise or category-wise setup has its rates on the product or the category) and the rates charged on shipping. A
     * storefront with no setup of its own, or a database with no tax module, is taxed with nothing.
     *
     * @return array{own: bool, type: string, included: bool, rates: list<array{name: string, rate: float}>, shipping: list<array{name: string, rate: float}>}
     */
    public function of(string $country): array
    {
        $setup = $this->tracksSetups() ? $this->ownSetup(payer: self::PAYER, country: strtoupper($country)) : null;

        if ($setup === null) {
            return ['own' => false, 'type' => self::NO_TAX_TYPE, 'included' => false, 'rates' => [], 'shipping' => []];
        }

        return [
            'own' => true,
            'type' => (string) $setup->tax_type,
            'included' => (bool) $setup->is_included,
            'rates' => $setup->tax_type === 'order_wise' ? $this->ratesOf(taxIds: (array) $setup->tax_ids) : [],
            'shipping' => $this->ratesOf(taxIds: $setup->additionalData->pluck('tax_ids')->flatten()->all()),
        ];
    }

    private function ownSetup(string $payer, string $country): ?SystemTaxSetup
    {
        return $this->activeDefaultSetups($payer)->where('country_code', $country)->first();
    }

    /** What a storefront with no setup of its own is taxed with. It is built and never saved, so the checkout of a storefront writes no row for it. */
    private function noTaxSetup(string $payer, string $country): SystemTaxSetup
    {
        return new SystemTaxSetup([
            'tax_type' => self::NO_TAX_TYPE,
            'country_code' => $country,
            'tax_payer' => $payer,
            'tax_ids' => [],
            'is_included' => 0,
            'is_default' => 1,
            'is_active' => 0,
        ]);
    }

    private function platformSetup(string $payer): ?SystemTaxSetup
    {
        return $this->activeDefaultSetups($payer)->ofThePlatform()->first();
    }

    /** @return Builder<SystemTaxSetup> */
    private function activeDefaultSetups(string $payer): Builder
    {
        return SystemTaxSetup::where('is_active', 1)
            ->with(['additionalData' => static fn ($query) => $query->where('is_active', 1)])
            ->where('tax_payer', $payer)
            ->where('is_default', 1);
    }

    /** @return list<string> the configured storefronts other than the default one: the countries whose rows are never the platform's */
    public function storefrontCountries(): array
    {
        $resolver = StorefrontResolver::fromConfig();

        return array_values(array_diff($resolver->configuredCountries(), [$resolver->defaultCountry()]));
    }

    /**
     * @param list<mixed> $taxIds
     * @return list<array{name: string, rate: float}>
     */
    private function ratesOf(array $taxIds): array
    {
        if ($taxIds === []) {
            return [];
        }

        return Tax::query()
            ->whereIn('id', $taxIds)
            ->where('is_active', 1)
            ->where('is_default', 1)
            ->orderBy('id')
            ->get(['name', 'tax_rate'])
            ->map(static fn (Tax $tax): array => ['name' => (string) $tax->name, 'rate' => (float) $tax->tax_rate])
            ->all();
    }
}
