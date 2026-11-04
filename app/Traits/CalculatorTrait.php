<?php

namespace App\Traits;

trait CalculatorTrait
{
    protected function getDiscountAmount(float $price, float $discount, string $discountType): float
    {
        if ($discountType == 'percent') {
            $value = ($price / 100) * $discount;
        } else {
            $value = $discount;
        }
        return round($value, 4);
    }

    protected function getTaxAmount(float $price, float $tax): float
    {
        return ($price / 100) * $tax;
    }

    function getDivideWithDynamicPrecision($numerator, $denominator, $maxPrecision = 100): string
    {
        if ($denominator == 0) {
            return 0;
        }
        $result = $numerator / $denominator;
        $resultStr = rtrim(number_format($result, $maxPrecision, '.', ''), '0');
        return (substr($resultStr, -1) === '.') ? substr($resultStr, 0, -1) : $resultStr;
    }
}
