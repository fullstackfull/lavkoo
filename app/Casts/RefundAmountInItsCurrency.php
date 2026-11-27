<?php

namespace App\Casts;

use App\Services\MyFatoorah\RefundMoney;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A refund's amount read in the decimals of the currency the refund itself names (D2-10).
 *
 * `decimal:2` is right for a riyal refund and wrong for a Kuwaiti, Bahraini or Omani one: a row holding 10.234 handed 10.23 back to the
 * admin screen, so the fix to how a refund is computed and stored would have stopped at the model and the operator would still read the
 * truncated figure. The row knows its own currency, so the row decides how many decimals it has.
 *
 * **It is not {@see MoneyInItsCurrency}, which is the same shape, for one reason**: that cast answers the currency's decimals
 * unconditionally, and this one is behind `gcc.refunds.gateway_refunds_in_the_currencys_minor_unit` like the rest of this change. While the
 * switch is off a refund row reads back at two decimals whatever currency it is in, exactly as it does today, even on a column with room
 * for a third. The places come from {@see RefundMoney::places()}, so the switch is read in one place and the model cannot disagree with the
 * service that wrote the row.
 *
 * Only reading is formatted. Writing passes the value through untouched: {@see \App\Services\MyFatoorah\RefundService} already hands over
 * the exact string for the column, and the row's currency may not have been assigned yet when its amount is.
 */
final class RefundAmountInItsCurrency implements CastsAttributes
{
    private const CURRENCY_COLUMN = 'currency';

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $named = $attributes[self::CURRENCY_COLUMN] ?? null;
        $places = RefundMoney::places(is_string($named) ? $named : null);

        return number_format((float) $value, $places, '.', '');
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}
