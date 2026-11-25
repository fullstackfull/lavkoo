<?php

namespace App\Services\Theme;

use App\Models\Admin;
use App\Models\Theme;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontVisibility;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The storefront the theme screens are working on (F-15).
 *
 * The admin panel is bound to no storefront, on purpose: that is what lets a central row be reached there at all. So the theme list, the
 * builder and the app builder had no country to be about, and every one of them opened on THE active theme -- which, once themes belong to
 * storefronts (F-01), is whichever row of six the database answered first. The requirement is not a new editor: it is a selector on the
 * existing ones, and this is the one place that answers what it is set to.
 *
 * Three inputs, in order, so that a deliberate choice always wins and a reload never loses it:
 *
 *  1. the `storefront` parameter of the request, when it names a storefront this employee may reach;
 *  2. the one remembered in the session from the last such parameter;
 *  3. the default storefront.
 *
 * A restricted employee (GCC-14c) is narrowed by their own countries at every step, including the fallback: an employee scoped to AE whose
 * session holds nothing is working on AE, not on Saudi Arabia, and `mayReach()` answers no for every market they do not hold. That is the
 * data half `ThemePermissionService` had none of -- the capabilities said WHAT an employee may do and nothing said WHERE.
 *
 * Nothing is offered or narrowed while themes do not belong to storefronts: `current()` is the default storefront, `options()` is empty, the
 * views show no selector and every query is handed back as it came in, so a single-market installation behaves exactly as it did.
 */
#[Singleton]
final class ThemeStorefrontSelection
{
    /** Where a chosen storefront is remembered between requests. */
    public const SESSION_KEY = 'gcc_theme_storefront';

    /** The request parameter the views and the builder's own calls carry. */
    public const REQUEST_KEY = 'storefront';

    public function __construct(
        private readonly StorefrontThemeResolver $themes,
        private readonly StaffCountryScope $staff,
    ) {
    }

    /** Whether there is a choice to make: themes belong to storefronts and more than one is configured. */
    public function isOffered(): bool
    {
        return $this->themes->keepsThemesByStorefront() && count($this->options()) > 1;
    }

    /**
     * The storefront the theme screens are working on. Reading it REMEMBERS an explicit choice, so the selector does not have to be
     * repeated on the builder's own asynchronous calls.
     */
    public function current(?Request $request = null): string
    {
        if (!$this->themes->keepsThemesByStorefront()) {
            return $this->fallback();
        }

        $asked = $request?->get(self::REQUEST_KEY);
        if (is_string($asked) && $this->mayReach($asked)) {
            $chosen = strtoupper(trim($asked));
            session([self::SESSION_KEY => $chosen]);

            return $chosen;
        }

        $remembered = session(self::SESSION_KEY);

        return is_string($remembered) && $this->mayReach($remembered) ? strtoupper(trim($remembered)) : $this->fallback();
    }

    /**
     * Whether this employee may work on a storefront at all: it is configured, and it is one of their countries when they are restricted.
     * A country that is not configured is never reachable, whatever a request asks for.
     */
    public function mayReach(string $country): bool
    {
        $country = strtoupper(trim($country));

        if (!in_array($country, StorefrontResolver::fromConfig()->configuredCountries(), true)) {
            return false;
        }

        return $this->countriesOfTheEmployee()?->contains($country) ?? true;
    }

    /**
     * The storefronts the selector offers, in configuration order, each with the label the rest of the panel shows for that market.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        if (!$this->themes->keepsThemesByStorefront()) {
            return [];
        }

        $options = [];
        foreach (StorefrontResolver::fromConfig()->configuredCountries() as $country) {
            if ($this->mayReach($country)) {
                $options[$country] = ProductCountry::label(country: $country);
            }
        }

        return $options;
    }

    /**
     * Whether the staff country scopes restrict the signed-in employee at all.
     *
     * It is the difference between "this employee holds AE" and "this employee is not being judged by market", which decides which way a
     * market question that cannot be answered must fail.
     */
    public function restrictsTheSignedInEmployee(): bool
    {
        return $this->countriesOfTheEmployee() !== null;
    }

    /** Whether a theme is one the screens are currently working on. True of every theme while themes do not belong to storefronts. */
    public function covers(?Theme $theme): bool
    {
        if (!$this->themes->keepsThemesByStorefront()) {
            return true;
        }

        return $theme !== null && $this->themes->storefrontOf(theme: $theme) === $this->current(request: request());
    }

    /**
     * Narrows a theme list to the selected storefront, which is what the theme index shows.
     *
     * The rule itself -- that the default storefront also owns every theme naming no country -- stays in `StorefrontThemeResolver`, where
     * the renderer, the delivery and the activation already read it. One definition, four readers.
     */
    public function narrowThemes(Builder $themes): Builder
    {
        if (!$this->themes->keepsThemesByStorefront()) {
            return $themes;
        }

        return $themes->whereIn(
            $themes->qualifyColumn($themes->getModel()->getKeyName()),
            $this->themes->themesOf(storefront: $this->current(request: request()))->select('id'),
        );
    }

    /**
     * Narrows a product query to the catalogue of the selected storefront (F-15, criterion 2).
     *
     * `StorefrontCatalog::restrict()` narrows to the storefront the REQUEST is served from, which in a panel is none -- so the builder's
     * pickers offered every country's products and named them with no country. Here the country is the selected one, which is the whole
     * point of the selector.
     */
    public function narrowProducts(Builder $products): Builder
    {
        if (!$this->themes->keepsThemesByStorefront() || !StorefrontCatalog::columnReady()) {
            return $products;
        }

        return $products->where($products->qualifyColumn(StorefrontCatalog::COLUMN), $this->current(request: request()));
    }

    /**
     * Narrows a list of central rows -- categories, brands, flash deals -- to what the selected storefront offers.
     *
     * Those are not country-owned: GCC-08 keeps ONE category tree and GCC-18 keeps brands central, and what differs per market is whether
     * the market SHOWS a row. That is the mapping `StorefrontVisibility` already holds, and it already answers for a named country, so this
     * is a call and not a second mechanism.
     */
    public function narrowOffered(Builder $rows, string $entityType): Builder
    {
        if (!$this->themes->keepsThemesByStorefront()) {
            return $rows;
        }

        return StorefrontVisibility::offeredIn(query: $rows, entityType: $entityType, country: $this->current(request: request()));
    }

    /** The default storefront, narrowed to a restricted employee's own first country so that they never start on somebody else's market. */
    private function fallback(): string
    {
        $default = StorefrontResolver::fromConfig()->defaultCountry();
        $countries = $this->countriesOfTheEmployee();

        if ($countries === null || $countries->contains($default)) {
            return $default;
        }

        return (string) ($countries->first() ?? $default);
    }

    /**
     * The countries of the signed-in employee, or null for one this mechanism does not restrict -- which is the master admin, an employee
     * with no scope row, and everybody while the staff scopes are switched off.
     *
     * @return \Illuminate\Support\Collection<int, string>|null
     */
    private function countriesOfTheEmployee(): ?\Illuminate\Support\Collection
    {
        $admin = auth('admin')->user();
        $countries = $admin instanceof Admin ? $this->staff->countriesOfAdmin(admin: $admin) : null;

        return $countries === null ? null : collect($countries)->map(fn (string $country) => strtoupper($country));
    }
}
