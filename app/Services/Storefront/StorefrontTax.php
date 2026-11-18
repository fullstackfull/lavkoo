<?php

namespace App\Services\Storefront;

use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Modules\TaxModule\app\Models\Tax;

/**
 * GCC-05 and D1-07: the tax an order is placed under follows the storefront it is placed from. The tax module keeps one default setup for the whole platform, which
 * is Saudi, so a storefront with its own money (the same switch that gives it its own currency) reads the active default setup whose `country_code` is its own, and
 * reads the platform's setup when it has none. The platform's setup is the row no storefront owns: a row carrying the code of one of the other configured storefronts is
 * never the platform's, so adding a storefront's setup cannot change what the Saudi storefront, a panel or a queue reads. While the switch is off, and for the default
 * storefront, a request and a console command read exactly what they read before.
 *
 * The rates themselves are the owner's to give per country and per selling company; nothing here chooses one.
 */
#[Singleton]
final class StorefrontTax
{
    public const PAYER = 'vendor';

    public function __construct(private readonly StorefrontCurrency $currency)
    {
    }

    /** The country whose own tax setup the request reads, or null while the platform's setup stands. */
    public function countryInForce(): ?string
    {
        return $this->currency->marketInForce();
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

    /** The setup the request is taxed under: its storefront's own, else the platform's, else null (the caller starts the platform's first setup, as it always did). */
    public function setupInForce(string $payer): ?SystemTaxSetup
    {
        $country = $this->countryInForce();
        $own = $country === null ? null : $this->activeDefaultSetups($payer)->where('country_code', $country)->first();

        return $own ?? $this->platformSetup(payer: $payer);
    }

    /** Whether the tax module has its table, which the readiness report needs before it can say anything about a storefront's tax. */
    public function tracksSetups(): bool
    {
        return Schema::hasTable('system_tax_setups');
    }

    /**
     * What the orders of a storefront are taxed with, for the readiness report: whether the setup is its own, its type, whether the prices include the tax, and the
     * rates of an order-wise setup (a product-wise or category-wise setup has its rates on the product or the category). Null when no setup is active anywhere.
     *
     * @return array{own: bool, type: string, included: bool, rates: list<array{name: string, rate: float}>}|null
     */
    public function of(string $country): ?array
    {
        $own = $this->activeDefaultSetups(self::PAYER)->where('country_code', strtoupper($country))->first();
        $setup = $own ?? $this->platformSetup(payer: self::PAYER);

        if ($setup === null) {
            return null;
        }

        return [
            'own' => $own !== null,
            'type' => (string) $setup->tax_type,
            'included' => (bool) $setup->is_included,
            'rates' => $setup->tax_type === 'order_wise' ? $this->ratesOf(setup: $setup) : [],
        ];
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

    /** @return list<array{name: string, rate: float}> */
    private function ratesOf(SystemTaxSetup $setup): array
    {
        return Tax::query()
            ->whereIn('id', (array) $setup->tax_ids)
            ->where('is_active', 1)
            ->where('is_default', 1)
            ->orderBy('id')
            ->get(['name', 'tax_rate'])
            ->map(static fn (Tax $tax): array => ['name' => (string) $tax->name, 'rate' => (float) $tax->tax_rate])
            ->all();
    }
}
