<?php

namespace App\Services\Tax;

use Illuminate\Container\Attributes\Singleton;

/**
 * D1-07: the tax that is already inside a tax-included price, and the one place that arithmetic is written.
 *
 * A store running the tax module in `is_included` mode charges the customer a gross price and keeps NO record of the tax inside it: the four
 * tax functions of `CartManager` return zero and no rate at all while `is_included` is set, so the order is written with
 * `total_tax_amount` 0, every line's `tax` 0 and not one row in `order_taxes`, while its `tax_model` says `include`. The money the customer
 * paid is right; what the order says about the tax in it is "none", which is the one thing that cannot be true of a taxed sale. A tax
 * authority asking what VAT was collected on an order of this store is answered from nowhere.
 *
 * Recording it is additive and NEVER changes a figure the customer was charged: the tax is already in the price, so the included amount is
 * written in columns of its own (`orders.included_tax_amount`, `order_details.included_tax`) and `order_amount`, `total_tax_amount` and
 * every line's `tax` stay exactly what they were. `total_tax_amount` in particular is ADDED to the order total by the readers that
 * recompute it (`OrderManager::getOrderEditData`), so writing the included tax there would inflate the order -- which is why it is not
 * written there.
 *
 * It ships OFF. Whether a tax-included order MUST store its tax, and under which country's rules, is the owner's and the finance review's
 * (docs/gcc/tracker/axis-d-orders-checkout-and-payments.md, D1-07). While it is off, a tax-included order records exactly what it records
 * today, down to the absent column.
 */
#[Singleton]
final class IncludedTax
{
    /** Whether the tax inside a tax-included price is recorded on the order. Off is the platform of today. */
    public function isRecorded(): bool
    {
        return filter_var(config('gcc.tax.record_included_amount', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The tax inside a gross amount charged at one combined rate: 115 at 15 per cent holds 15, and 100 at 15 per cent holds 13.0434...
     *
     * Not `gross * percent / 100`, which is the tax a NET amount attracts. The whole point of an included price is that the rate was
     * already applied to a smaller number, so the tax inside it is `gross * p / (100 + p)`. The two differ by 13% at a Saudi rate, which
     * is why this is one function and not a line repeated at each caller.
     *
     * Nothing is rounded here. Rounding belongs to the currency of the order, which this does not know, and the column holds twelve
     * decimals so that the third decimal of a dinar survives.
     */
    public function withinGross(float $gross, float $percent): float
    {
        if ($gross <= 0 || $percent <= 0) {
            return 0;
        }

        return ($gross * $percent) / (100 + $percent);
    }
}
