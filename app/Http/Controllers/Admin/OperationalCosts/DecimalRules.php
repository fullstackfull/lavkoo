<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

/**
 * The validation of a number typed into a form. A figure is a plain decimal string - digits, an optional dot and decimals - and it stays a string until the
 * service turns it into a Money: it never passes through a float, so 0.1 + 0.2 is 0.3 here.
 */
final class DecimalRules
{
    /** An amount of money: up to six decimals, the precision the ledger stores. */
    public const AMOUNT = '/^\d{1,14}(\.\d{1,6})?\z/';

    /** An amount that is more than zero: at least one digit of it is not a zero. */
    public const POSITIVE_AMOUNT = '/^(?=[0.]*[1-9])\d{1,14}(\.\d{1,6})?\z/';

    /** An amount that may be a credit. */
    public const SIGNED_AMOUNT = '/^-?\d{1,14}(\.\d{1,6})?\z/';

    /** A percentage as people write it: 15 means 15 %. */
    public const PERCENT = '/^\d{1,3}(\.\d{1,4})?\z/';

    /** @return list<string> */
    public static function amount(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'regex:' . self::AMOUNT];
    }

    /** @return list<string> */
    public static function positiveAmount(): array
    {
        return ['required', 'regex:' . self::POSITIVE_AMOUNT];
    }

    /** @return list<string> */
    public static function signedAmount(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'regex:' . self::SIGNED_AMOUNT];
    }

    /** @return list<string> */
    public static function percent(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'regex:' . self::PERCENT];
    }
}
