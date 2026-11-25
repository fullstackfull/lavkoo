<?php

namespace App\Services\Storefront;

use App\Models\ShippingMethod;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Which shipping methods a storefront lists and charges (H1-14).
 *
 * The platform's decision is that local shipping rules belong to a storefront and its selling company and are NEVER inherited by another: a method made
 * for the Saudi store must not quietly become Kuwait's delivery option at a Saudi price in Saudi riyals. So a method carries the market it belongs to,
 * and this is the only place that knows the rule for reading it.
 *
 * **What a method with no market means.** Every method on this platform today has none, because there has only ever been one storefront, and all of them
 * are that storefront's. So an empty column is read as the DEFAULT storefront's method: the default market lists its own methods AND the ones that name no
 * market, and every other market lists only methods that name it. A market with no method of its own therefore offers none, which is the correct answer
 * and not an empty screen by accident -- offering it the Saudi methods would be inheriting exactly what the decision forbids.
 *
 * **It is dormant.** While `gcc.storefronts.enabled` is off, or before the migration has run, this narrows nothing at all: every listing is the listing it
 * has always been, by the same query, in the same order. That is what the first test of this class asserts.
 */
#[Singleton]
final class StorefrontShippingMethods
{
    public const COLUMN = 'storefront_country';

    private const TABLE = 'shipping_methods';

    private ?bool $columnReady = null;

    /** @var array<string, bool> */
    private array $ownMethods = [];

    /** Whether a shipping method can carry a market at all, and whether anything should read it. */
    public function applies(): bool
    {
        if (!(bool) config('gcc.storefronts.enabled', false)) {
            return false;
        }

        return $this->columnReady ??= Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, self::COLUMN);
    }

    /**
     * Narrows a query of shipping methods to the storefront given, or to the one the request is being served from.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    public function narrow(Builder $query, ?string $country = null): Builder
    {
        if (!$this->applies()) {
            return $query;
        }

        $market = strtoupper(trim((string) ($country ?? StorefrontContext::current()->country)));

        if ($market === '') {
            return $query;
        }

        return $market === $this->defaultCountry()
            ? $query->where(fn (Builder $scoped): Builder => $scoped->where(self::COLUMN, $market)->orWhereNull(self::COLUMN))
            : $query->where(self::COLUMN, $market);
    }

    /**
     * Whether this market has shipping methods of its OWN -- rows that name it, not the unmarked rows of the default storefront.
     *
     * This is what decides whether a market with its own currency may charge a method at all. The unmarked methods are deliberately
     * not counted: they are the default storefront's, typed in the platform's money, and charging them in another currency is the
     * mistake the whole override exists to prevent.
     *
     * Asked once per market per request: a basket of twenty lines reads the shipping type twenty times.
     */
    public function marketHasOwnMethods(?string $country = null): bool
    {
        if (!$this->applies()) {
            return false;
        }

        $market = $this->stampFor($country);

        if ($market === null) {
            return false;
        }

        return $this->ownMethods[$market] ??= ShippingMethod::query()
            ->where('status', 1)
            ->where(self::COLUMN, $market)
            ->exists();
    }

    /** The market a method is given when one is made in a storefront, or null while this does not apply. */
    public function stampFor(?string $country = null): ?string
    {
        if (!$this->applies()) {
            return null;
        }

        $market = strtoupper(trim((string) ($country ?? StorefrontContext::current()->country)));

        return $market === '' ? null : $market;
    }

    public function forget(): void
    {
        $this->columnReady = null;
        $this->ownMethods = [];
    }

    /** The storefront whose methods are the ones that name no market. */
    private function defaultCountry(): string
    {
        $configured = strtoupper(trim((string) config('gcc.storefronts.default_country')));

        return $configured === '' ? 'SA' : $configured;
    }
}
