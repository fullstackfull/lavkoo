<?php

namespace App\Casts;

use App\Services\Fulfillment\Support\CurrencyCode;
use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A money column read in the decimals of the currency its own row names, not in a number of decimals fixed in the model (C-15).
 *
 * `decimal:2` is right for every amount this platform has carried so far, because every one of them was a riyal amount and a riyal is 100
 * halalas. It is wrong for three of the six markets: a Kuwaiti or Bahraini dinar is 1,000 fils and an Omani rial 1,000 baisa, so a cast that
 * formats to two decimals hands 12.34 back to the courier payload for a shipment the customer owes 12.345 for — five fils the platform
 * silently keeps. The row knows which currency it is in, so the row decides how many decimals it has; a row that names none is in the
 * currency providers are paid in ({@see CurrencyCode::provider()}), which is what the column's own default says.
 *
 * Only reading is formatted. Writing passes the value through untouched, because the row's currency may not have been assigned yet when its
 * amount is — `create(['cod_amount' => ..., 'currency' => ...])` fills in the order the caller wrote — and the column's own scale is what
 * decides what is stored. The decimals come from the one place that holds them, `gcc.storefronts.currency.decimals`, which answers two for
 * every currency it is not told about.
 */
final class MoneyInItsCurrency implements CastsAttributes
{
    private const CURRENCY_COLUMN = 'currency';

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : number_format((float) $value, $this->decimalsOf($attributes), '.', '');
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }

    /** @param array<string, mixed> $attributes */
    private function decimalsOf(array $attributes): int
    {
        $named = $attributes[self::CURRENCY_COLUMN] ?? null;
        $code = is_string($named) && trim($named) !== '' ? $named : CurrencyCode::provider();

        return app(StorefrontCurrency::class)->decimalsOf($code);
    }
}
