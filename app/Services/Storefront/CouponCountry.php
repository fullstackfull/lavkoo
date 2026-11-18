<?php

namespace App\Services\Storefront;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * The storefront a coupon belongs to (GCC-05 slice 5). A coupon is three numbers with no unit (the discount, the minimum purchase, the cap), typed in one
 * currency, so it may be offered only by the storefront whose currency it was typed in. NULL in `coupons.storefront_country` is the default storefront's
 * coupon, in the platform's money, which is every coupon that exists today.
 *
 * While the storefront currency is switched off, or before the coupons migration has run on the default storefront, nothing here narrows anything, and a
 * query is left as it was (no predicate and no schema probe while off). A storefront other than the default one never meets a coupon before the column
 * exists: it meets none.
 */
final class CouponCountry
{
    private const TABLE = 'coupons';

    private const COLUMN = 'storefront_country';

    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private static ?array $columnAnswer = null;

    /** Whether coupons are stamped with, and read by, the storefront they belong to. */
    public static function isTracked(): bool
    {
        return app(StorefrontCurrency::class)->isEnabled() && self::columnReady();
    }

    /**
     * Keeps the coupons the storefront may offer: its own, and for the default storefront also those with no country. The storefront is the one given, else
     * the one the request is served from, else the default one (a panel and a queue see no storefront, and work in the platform's money).
     */
    public static function narrow(Builder $query, ?string $country = null): Builder
    {
        if (!app(StorefrontCurrency::class)->isEnabled()) {
            return $query;
        }

        $default = StorefrontResolver::fromConfig()->defaultCountry();
        $country = strtoupper($country ?? StorefrontCatalog::country() ?? $default);

        if (!self::columnReady()) {
            return $country === $default ? $query : $query->whereRaw('1 = 0');
        }

        $column = $query->getModel()->getTable() . '.' . self::COLUMN;

        return $country === $default
            ? $query->where(fn (Builder $inner): Builder => $inner->whereNull($column)->orWhere($column, $default))
            : $query->where($column, $country);
    }

    /** The country a coupon belongs to, or null when it has none (or none is tracked). */
    public static function countryOf(int|string $couponId): ?string
    {
        if (!self::isTracked()) {
            return null;
        }

        $country = DB::table(self::TABLE)->where('id', $couponId)->value(self::COLUMN);

        return is_string($country) && $country !== '' ? $country : null;
    }

    /**
     * What a coupon that is created now records of its storefront: nothing while untracked, NULL for the default storefront and for a country that is not a
     * configured one (the coupon is then the default storefront's), the country otherwise.
     *
     * @return array<string, string|null>
     */
    public static function attributesOfNewCoupon(?string $requestedCountry): array
    {
        if (!self::isTracked()) {
            return [];
        }

        $country = strtoupper(trim((string) $requestedCountry));
        $configured = StorefrontResolver::fromConfig()->configuredCountries();

        return [self::COLUMN => $country !== '' && $country !== StorefrontResolver::fromConfig()->defaultCountry() && in_array($country, $configured, true) ? $country : null];
    }

    /**
     * The validation of the storefront field of a new coupon: a configured country, and for a storefront with its own currency, one whose currency is
     * active, because the amounts are typed in that currency and stored as typed. Nothing is validated while coupons are not tracked.
     *
     * @return array<string, list<mixed>>
     */
    public static function newCouponRules(): array
    {
        if (!self::isTracked()) {
            return [];
        }

        return [self::COLUMN => ['bail', 'nullable', Rule::in(ProductCountry::options()), self::mustHaveAnActiveCurrency(...)]];
    }

    /**
     * The storefronts the admin may type a coupon for: the default one, and every other that trades in its own currency and has it active.
     *
     * @return list<string>
     */
    public static function options(): array
    {
        $currency = app(StorefrontCurrency::class);
        $resolver = StorefrontResolver::fromConfig();

        return array_values(array_filter(
            ProductCountry::options(),
            static fn (string $country): bool => $currency->isReady(country: $country, currencyCode: $resolver->currencyOf($country)),
        ));
    }

    /** The mark the amounts of a coupon of this storefront are typed with: its currency's, else the platform's, which is what the form prints without a market. */
    public static function currencySymbolOf(string $country): string
    {
        $currency = app(StorefrontCurrency::class)->currencyOfSales($country);

        return (string) ($currency?->symbol ?? getCurrencySymbol(currencyCode: getCurrencyCode()));
    }

    /** Runs the callback in the money of the storefront the coupon belongs to, so an amount typed in dinars is shown as dinars. */
    public static function inMoneyOf(mixed $coupon, Closure $callback): mixed
    {
        return app(StorefrontCurrency::class)->within(country: data_get($coupon, self::COLUMN), callback: $callback);
    }

    /** Forgets what was learnt about the schema, for a test that builds its own tables. */
    public static function flush(): void
    {
        self::$columnAnswer = null;
    }

    private static function mustHaveAnActiveCurrency(string $attribute, mixed $country, Closure $fail): void
    {
        $country = is_string($country) ? strtoupper($country) : '';

        if (!app(StorefrontCurrency::class)->isReady(country: $country, currencyCode: StorefrontResolver::fromConfig()->currencyOf($country))) {
            $fail(translate('the_currency_of_this_storefront_is_not_active_so_a_coupon_cannot_be_typed_for_it'));
        }
    }

    private static function columnReady(): bool
    {
        $answer = self::$columnAnswer;
        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::NEGATIVE_ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $ready = Schema::hasColumn(self::TABLE, self::COLUMN);
        self::$columnAnswer = ['value' => $ready, 'at' => time()];

        return $ready;
    }
}
