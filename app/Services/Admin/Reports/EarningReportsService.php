<?php

namespace App\Services\Admin\Reports;


use Carbon\Carbon;
use Carbon\CarbonPeriod;

class EarningReportsService
{

    public function getEarnFromOrderForEarningReport(
        object|array|null $query = [],
        string|null $userType = '',
        string|null $dataType = '',
        string|null $startDate = '',
        string|null $endDate = '',
        int|null $incrementNumber = 0,
        int|null $defaultIncrement = 0,
    ): array
    {
        $earnFromOrder = [];

        if ($dataType == 'today') {
            $dayName = [Carbon::today()->format('l')];
            for ($increment = 0; $increment < $incrementNumber; $increment++) {
                $earnFromOrder[$dayName[$increment]] = 0;
                foreach ($query as $order) {
                    if ($order['day'] == $dayName[$increment]) {
                        $earnFromOrder[$dayName[$increment]] += self::getEarnFormOrderAmount(order: $order, type: $userType);
                    }
                }
            }
        }

        if ($dataType == 'this_week') {
            $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
            $dayNames = [];
            foreach ($period as $date) {
                $dayNames[] = $date->format('l');
            }

            for ($inc = 0; $inc <= $incrementNumber; $inc++) {
                $earnFromOrder[$dayNames[$inc]] = 0;
                foreach ($query as $order) {
                    if ($order['day'] == $inc) {
                        $earnFromOrder[$dayNames[$inc]] += self::getEarnFormOrderAmount(order: $order, type: $userType);
                    }
                }
            }
        }

        if ($dataType == 'this_month') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $earnFromOrder[$inc] = 0;
                foreach ($query as $order) {
                    if ($order['day'] == $inc) {
                        $earnFromOrder[$inc] += self::getEarnFormOrderAmount(order: $order, type: $userType);
                    }
                }
            }
        }

        if ($dataType == 'this_year') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $month = substr(date("F", strtotime("2023-$inc-01")), 0, 3);
                $earnFromOrder[$month] = 0;
                foreach ($query as $order) {
                    if ($order['month'] == $inc) {
                        $earnFromOrder[$month] += self::getEarnFormOrderAmount(order: $order, type: $userType);
                    }
                }
            }
        }

        if ($dataType == 'custom_date') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $earnFromOrder[$inc] = 0;
                foreach ($query as $order) {
                    if ($order['year'] == $inc) {
                        $earnFromOrder[$inc] += self::getEarnFormOrderAmount(order: $order, type: $userType);
                    }
                }
            }
        }

        return $earnFromOrder;
    }

    function getEarnFormOrderAmount($order, $type)
    {
        $amount = $order['order_amount'] + $order['refer_and_earn_discount'];
        $amount -= $order['total_tax_amount'];
        $amount -= $order['shipping_cost'];
        // $amount -= $order['admin_commission'];

        if ($type == 'admin' && $order['seller_is'] == 'admin' && $order['coupon_discount_bearer'] == 'inhouse' && $order['discount_type'] == 'coupon_discount') {
            $amount += $order['discount_amount'];
        }

        if ($type == 'seller' && $order['seller_is'] == 'seller' && $order['coupon_discount_bearer'] == 'inhouse' && $order['discount_type'] == 'coupon_discount') {
            $amount += $order['discount_amount'];
        }

        if ($type == 'seller' && $order['seller_is'] == 'seller' && $order['coupon_discount_bearer'] == 'seller' && $order['discount_type'] == 'coupon_discount') {
            $amount += $order['discount_amount'];
        }

        if ($type == 'admin' && $order['seller_is'] == 'admin' && $order['free_delivery_bearer'] == 'admin' && $order['is_shipping_free']) {
            $amount += $order['extra_discount'];
        }

        if ($type == 'seller' && $order['seller_is'] == 'seller' && $order['free_delivery_bearer'] == 'seller' && $order['is_shipping_free']) {
            $amount += $order['extra_discount'];
        }

        if ($type == 'seller' && $order['seller_is'] == 'seller' && $order['free_delivery_bearer'] == 'admin' && $order['is_shipping_free']) {
            $amount += $order['extra_discount'];
        }
        return $amount;
    }

    public function getCommissionFromOrderForEarningReport(
        object|array|null $query = [],
        string|null $userType = '',
        string|null $dataType = '',
        string|null $startDate = '',
        string|null $endDate = '',
        int|null $incrementNumber = 0,
        int|null $defaultIncrement = 0,
    ): array
    {
        $commission = [];

        if ($dataType == 'today') {
            $dayName = [Carbon::today()->format('l')];
            for ($increment = 0; $increment < $incrementNumber; $increment++) {
                $commission[$dayName[$increment]] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $dayName[$increment]) {
                        $commission[$dayName[$increment]] = $match['commission'];
                    }
                }
            }
        }

        if ($dataType == 'this_week') {
            $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
            $dayName = [];
            foreach ($period as $date) {
                $dayName[] = $date->format('l');
            }
            for ($inc = 0; $inc <= $incrementNumber; $inc++) {
                $commission[$dayName[$inc]] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $dayName[$inc]) {
                        $commission[$dayName[$inc]] = $match['commission'];
                    }
                }
            }
        }

        if ($dataType == 'this_month') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $commission[$inc] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $inc) {
                        $commission[$inc] = $match['commission'];
                    }
                }
            }
        }

        if ($dataType == 'this_year') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $month = substr(date("F", strtotime("2023-$inc-01")), 0, 3);
                $commission[$month] = 0;
                foreach ($query as $match) {
                    if ($match['month'] == $inc) {
                        $commission[$month] = $match['commission'];
                    }
                }
            }
        }

        if ($dataType == 'custom_date') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $commission[$inc] = 0;
                foreach ($query as $match) {
                    if ($match['year'] == $inc) {
                        $commission[$inc] = $match['commission'];
                    }
                }
            }
        }

        return $commission;
    }

    public function getShippingEarnFromOrderForEarningReport(
        object|array|null $query = [],
        string|null $userType = '',
        string|null $dataType = '',
        string|null $startDate = '',
        string|null $endDate = '',
        int|null $incrementNumber = 0,
        int|null $defaultIncrement = 0,
    ): array
    {
        $shippingEarn = [];

        if ($dataType == 'today') {
            $dayName = [Carbon::today()->format('l')];
            for ($increment = 0; $increment < $incrementNumber; $increment++) {
                $shippingEarn[$dayName[$increment]] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $dayName[$increment]) {
                        $shippingEarn[$dayName[$increment]] += self::getShippingEarnFormOrderAmount(order: $match, type: $userType);
                    }
                }
            }
        }

        if ($dataType == 'this_week') {
            $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
            $dayName = [];
            foreach ($period as $date) {
                $dayName[] = $date->format('l');
            }
            for ($inc = 0; $inc <= $incrementNumber; $inc++) {
                $shippingEarn[$dayName[$inc]] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $inc) {
                        $shippingEarn[$dayName[$inc]] += self::getShippingEarnFormOrderAmount(order: $match, type: $userType);
                    }
                }
            }
        }

        if ($dataType == 'this_month') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $shippingEarn[$inc] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $inc) {
                        $shippingEarn[$inc] += self::getShippingEarnFormOrderAmount(order: $match, type: $userType);
                    }
                }
            }
        }

        if ($dataType == 'this_year') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $month = substr(date("F", strtotime("2023-$inc-01")), 0, 3);
                $shippingEarn[$month] = 0;
                foreach ($query as $match) {
                    if ((int)$match['month'] == $inc) {
                        $shippingEarn[$month] += self::getShippingEarnFormOrderAmount(order: $match, type: $userType);
                    }
                }
            }
        }

        if ($dataType == 'custom_date') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $shippingEarn[$inc] = 0;
                foreach ($query as $match) {
                    if ((int)$match['year'] == $inc) {
                        $shippingEarn[$inc] += self::getShippingEarnFormOrderAmount(order: $match, type: $userType);
                    }
                }
            }
        }

        return $shippingEarn;
    }

    function getShippingEarnFormOrderAmount($order, $type)
    {
        $amount = 0;
        if ($type == 'admin' && ($order['seller_is'] == 'admin' || ($order['seller_is'] == 'seller' && $order['shipping_responsibility'] == 'inhouse_shipping'))) {
            $amount += $order['shipping_cost'];
        }

        if ($type == 'seller' && $order['seller_is'] == 'seller' && $order['shipping_responsibility'] != 'inhouse_shipping') {
            $amount += $order['shipping_cost'];
        }
        return $amount;
    }

    public function getDeliverymanIncentiveFromOrderForEarningReport(
        object|array|null $query = [],
        string|null $userType = '',
        string|null $dataType = '',
        string|null $startDate = '',
        string|null $endDate = '',
        int|null $incrementNumber = 0,
        int|null $defaultIncrement = 0,
    ): array
    {
        $deliverymanIncentive = [];

        if ($dataType == 'today') {
            $dayName = [Carbon::today()->format('l')];
            for ($increment = 0; $increment < $incrementNumber; $increment++) {
                $deliverymanIncentive[$dayName[$increment]] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $dayName[$increment]) {
                        $deliverymanIncentive[$dayName[$increment]] = $match['deliveryman_incentive'];
                    }
                }
            }
        }

        if ($dataType == 'this_week') {
            $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
            $dayName = [];
            foreach ($period as $date) {
                $dayName[] = $date->format('l');
            }
            for ($inc = 0; $inc <= $incrementNumber; $inc++) {
                $deliverymanIncentive[$dayName[$inc]] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $dayName[$inc]) {
                        $deliverymanIncentive[$dayName[$inc]] = $match['deliveryman_incentive'];
                    }
                }
            }
        }

        if ($dataType == 'this_month') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $deliverymanIncentive[$inc] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $inc) {
                        $deliverymanIncentive[$inc] = $match['deliveryman_incentive'];
                    }
                }
            }
        }

        if ($dataType == 'this_year') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $month = substr(date("F", strtotime("2023-$inc-01")), 0, 3);
                $deliverymanIncentive[$month] = 0;
                foreach ($query as $match) {
                    if ($match['month'] == $inc) {
                        $deliverymanIncentive[$month] = $match['deliveryman_incentive'];
                    }
                }
            }
        }

        if ($dataType == 'custom_date') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $deliverymanIncentive[$inc] = 0;
                foreach ($query as $match) {
                    if ($match['year'] == $inc) {
                        $deliverymanIncentive[$inc] = $match['deliveryman_incentive'];
                    }
                }
            }
        }

        return $deliverymanIncentive;
    }

    public function getDiscountGivenFromOrderForEarningReport(
        object|array|null $query = [],
        string|null $userType = '',
        string|null $dataType = '',
        string|null $startDate = '',
        string|null $endDate = '',
        int|null $incrementNumber = 0,
        int|null $defaultIncrement = 0,
    ): array
    {
        $discountGiven = [];

        if ($dataType == 'today') {
            $dayName = [Carbon::today()->format('l')];
            for ($increment = 0; $increment < $incrementNumber; $increment++) {
                $discountGiven[$dayName[$increment]] = 0;
                foreach ($query as $order) {
                    if ($order['day'] === $dayName[$increment]) {
                        $discountGiven[$dayName[$increment]] += self::getDiscountGivenFormOrderAmount(order: $order, type: $userType);
                    }
                }
            }
        }

        if ($dataType == 'this_week') {
            $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
            $dayName = [];
            foreach ($period as $date) {
                $dayName[] = $date->format('l');
            }
            for ($inc = 0; $inc <= $incrementNumber; $inc++) {
                $discountGiven[$dayName[$inc]] = 0;
                foreach ($query as $order) {
                    if ($order['day'] == $inc) {
                        $discountGiven[$dayName[$inc]] += self::getDiscountGivenFormOrderAmount(order: $order, type: $userType);
                    }
                }
            }
        }

        if ($dataType == 'this_month') {
            for ($increment = $defaultIncrement; $increment <= $incrementNumber; $increment++) {
                $discountGiven[$increment] = 0;
                foreach ($query as $order) {
                    if ($order['day'] == $increment) {
                        $discountGiven[$increment] += self::getDiscountGivenFormOrderAmount(order: $order, type: $userType);
                    }
                }
            }
        }

        if ($dataType == 'this_year') {
            for ($increment = $defaultIncrement; $increment <= $incrementNumber; $increment++) {
                $month = substr(date("F", strtotime("2023-$increment-01")), 0, 3);
                $discountGiven[$month] = 0;
                foreach ($query as $order) {
                    if ($order['month'] == $increment) {
                        $discountGiven[$month] += self::getDiscountGivenFormOrderAmount(order: $order, type: $userType);
                    }
                }
            }
        }

        if ($dataType == 'custom_date') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $discountGiven[$inc] = 0;
                foreach ($query as $order) {
                    if ($order['year'] == $inc) {
                        $discountGiven[$inc] += self::getDiscountGivenFormOrderAmount(order: $order, type: $userType);
                    }
                }
            }
        }

        return $discountGiven;
    }

    function getDiscountGivenFormOrderAmount($order, $type)
    {
        $amount = 0;
        $couponDiscountBearer = $order['coupon_discount_bearer'] == 'inhouse' ? 'admin' : 'seller';
        if ($type == $couponDiscountBearer && $order->discount_type === 'coupon_discount') {
            $amount += $order->discount_amount;
        }
        if ((int)$order->is_shipping_free === 1 && $order->free_delivery_bearer === $type) {
            $amount += $order->extra_discount;
        }
        if ($type == 'admin') {
            $amount += $order->refer_and_earn_discount;
        }
        return $amount;
    }


    public function getVatTAxFromOrderForEarningReport(
        object|array|null $query = [],
        string|null $userType = '',
        string|null $dataType = '',
        string|null $startDate = '',
        string|null $endDate = '',
        int|null $incrementNumber = 0,
        int|null $defaultIncrement = 0,
    ): array
    {
        $totalTax = [];

        if ($dataType == 'today') {
            $dayName = [Carbon::today()->format('l')];
            for ($increment = 0; $increment < $incrementNumber; $increment++) {
                $totalTax[$dayName[$increment]] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $dayName[$increment]) {
                        $totalTax[$dayName[$increment]] = $match['total_tax'];
                    }
                }
            }
        }

        if ($dataType == 'this_week') {
            $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
            $dayName = [];
            foreach ($period as $date) {
                $dayName[] = $date->format('l');
            }
            for ($inc = 0; $inc <= $incrementNumber; $inc++) {
                $totalTax[$dayName[$inc]] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $dayName[$inc]) {
                        $totalTax[$dayName[$inc]] = $match['total_tax'];
                    }
                }
            }
        }

        if ($dataType == 'this_month') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $totalTax[$inc] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $inc) {
                        $totalTax[$inc] = $match['total_tax'];
                    }
                }
            }
        }

        if ($dataType == 'this_year') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $month = substr(date("F", strtotime("2023-$inc-01")), 0, 3);
                $totalTax[$month] = 0;
                foreach ($query as $match) {
                    if ($match['month'] == $inc) {
                        $totalTax[$month] = $match['total_tax'];
                    }
                }
            }
        }

        if ($dataType == 'custom_date') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $totalTax[$inc] = 0;
                foreach ($query as $match) {
                    if ($match['year'] == $inc) {
                        $totalTax[$inc] = $match['total_tax'];
                    }
                }
            }
        }

        return $totalTax;
    }

    public function getRefundGivenFromOrderForEarningReport(
        object|array|null $query = [],
        string|null $userType = '',
        string|null $dataType = '',
        string|null $startDate = '',
        string|null $endDate = '',
        int|null $incrementNumber = 0,
        int|null $defaultIncrement = 0,
    ): array
    {
        $refundGiven = [];

        if ($dataType == 'today') {
            $dayName = [Carbon::today()->format('l')];
            for ($increment = 0; $increment < $incrementNumber; $increment++) {
                $refundGiven[$dayName[$increment]] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $dayName[$increment]) {
                        $refundGiven[$dayName[$increment]] = $match['refund_amount'];
                    }
                }
            }
        }

        if ($dataType == 'this_week') {
            $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
            $dayName = [];
            foreach ($period as $date) {
                $dayName[] = $date->format('l');
            }
            for ($inc = 0; $inc <= $incrementNumber; $inc++) {
                $refundGiven[$dayName[$inc]] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $dayName[$inc]) {
                        $refundGiven[$dayName[$inc]] = $match['refund_amount'];
                    }
                }
            }
        }

        if ($dataType == 'this_month') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $refundGiven[$inc] = 0;
                foreach ($query as $match) {
                    if ($match['day'] == $inc) {
                        $refundGiven[$inc] = $match['refund_amount'];
                    }
                }
            }
        }

        if ($dataType == 'this_year') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $month = substr(date("F", strtotime("2023-$inc-01")), 0, 3);
                $refundGiven[$month] = 0;
                foreach ($query as $match) {
                    if ($match['month'] == $inc) {
                        $refundGiven[$month] = $match['refund_amount'];
                    }
                }
            }
        }

        if ($dataType == 'custom_date') {
            for ($inc = $defaultIncrement; $inc <= $incrementNumber; $inc++) {
                $refundGiven[$inc] = 0;
                foreach ($query as $match) {
                    if ($match['year'] == $inc) {
                        $refundGiven[$inc] = $match['refund_amount'];
                    }
                }
            }
        }

        return $refundGiven;
    }

}
