<?php

namespace App\Services\Admin\Reports;

use Carbon\Carbon;
use Carbon\CarbonPeriod;

class TransactionReportService
{
    public function getOrderTransactionListFormatedData($transactions): array
    {
        $transactionsTableData = [];
        foreach ($transactions as $key => $transaction) {
            if ($transaction?->order) {
                $shopName = $this->getShopName($transaction);
                $customer = $this->getCustomer($transaction);
                $customerId = $this->getCustomerId($transaction, $customer);
                $orderDetailsSumPrice = $transaction?->orderDetails[0]?->order_details_sum_price ?? 0;
                $orderDetailsSumDiscount = $transaction?->orderDetails[0]?->order_details_sum_discount ?? 0;
                $referAndEarnDiscount = $transaction?->order?->refer_and_earn_discount ?? 0;
                $couponDiscount = $this->getCouponDiscount($transaction);
                $adminCouponDiscount = $this->getAdminCouponDiscount($transaction);
                $adminShippingDiscount = $this->getAdminShippingDiscount($transaction);
                $sellerCouponDiscount = $this->getSellerCouponDiscount($transaction);
                $sellerShippingDiscount = $this->getSellerShippingDiscount($transaction);

                $firstItem = $this->getFirstItem($transactions);

                $transactionsTableData[$firstItem + $key] = [
                    'order_id' => $transaction['order_id'],
                    'shop_name' => $shopName,
                    'is_guest' => $transaction?->order?->is_guest ?? 0,
                    'customer_id' => $customerId,
                    'customer_name' => $customer ? $customer?->f_name . ' ' . $customer?->l_name : ($transaction?->order?->is_guest ? translate('guest_customer') : translate('not_found')),
                    'total_product_amount' => $orderDetailsSumPrice,
                    'product_discount' => $orderDetailsSumDiscount,
                    'coupon_discount' => $transaction?->order?->discount_amount ?? 0,
                    'referral_discount' => $referAndEarnDiscount,
                    'discounted_amount' => $this->getDiscountAmount($orderDetailsSumPrice, $orderDetailsSumDiscount, $couponDiscount, $referAndEarnDiscount),
                    'tax' => $transaction?->tax ?? 0,
                    'shipping_charge' => $transaction?->order?->shipping_cost ?? 0,
                    'order_amount' => $transaction?->order?->order_amount ?? 0,
                    'edit_due_amount' => $transaction?->order?->edit_due_amount ?? 0,
                    'edit_return_amount' => $transaction?->order?->edit_return_amount ?? 0,
                    'delivered_by' => $transaction?->delivered_by ?? '',
                    'deliveryman_incentive' =>  $this->getDeliveryManIncentive($transaction),
                    'admin_discount' => $this->getAdminDiscount($adminCouponDiscount, $adminShippingDiscount, $referAndEarnDiscount),
                    'vendor_discount' => $this->getVendorDiscount($sellerCouponDiscount, $sellerShippingDiscount),
                    'admin_commission' => $transaction?->admin_commission ?? 0,
                    'admin_net_income' => $this->getAdminNetIncome($transaction, $adminCouponDiscount, $adminShippingDiscount, $sellerCouponDiscount),
                    'vendor_net_income' => $this->getVendorNetIncome($transaction, $adminCouponDiscount),
                    'payment_method' => $transaction?->payment_method ?? '',
                    'payment_status' => $transaction?->status ?? '',
                    'seller_is' => $transaction->seller_is ?? '',
                    'shipping_responsibility' => $transaction?->order?->shipping_responsibility ?? '',
                    'admin_coupon_discount' => $adminCouponDiscount,
                    'seller_coupon_discount' => $sellerCouponDiscount,
                ];
            }
        }
        return $transactionsTableData;
    }

    private function getShopName($transaction): string
    {
        return $transaction['seller_is'] == 'admin' ? getInHouseShopConfig(key: 'name') : ($transaction?->seller?->shop?->name ?? translate('not_found'));
    }

    private function getCustomer($transaction)
    {
        return !$transaction?->order?->is_guest && $transaction?->customer ? $transaction->customer : null;
    }

    private function getCustomerId($transaction, $customer): ?int
    {
        return !$transaction?->order?->is_guest && $customer ? $customer->id : null;
    }

    private function getCouponDiscount($transaction): float
    {
        return isset($transaction->order->coupon) && $transaction->order->coupon->coupon_type != 'free_delivery' ? $transaction->order->discount_amount : 0;
    }

    private function getDiscountAmount($orderDetailsSumPrice, $orderDetailsSumDiscount, $couponDiscount, $referAndEarnDiscount): float
    {
        return $orderDetailsSumPrice - $orderDetailsSumDiscount - $couponDiscount - $referAndEarnDiscount;
    }

    private function getDeliveryManIncentive($transaction): float
    {
        return $transaction->order->delivery_type == 'self_delivery' && $transaction->order->delivery_man_id ? $transaction->order->deliveryman_charge : 0;
    }

    private function getAdminCouponDiscount($transaction): float
    {
        return (($transaction['seller_is'] == 'admin' || $transaction->order->coupon_discount_bearer == 'inhouse') && $transaction->order->discount_type == 'coupon_discount') ? $transaction->order->discount_amount : 0;
    }

    private function getAdminShippingDiscount($transaction): float
    {
        return ($transaction->order->free_delivery_bearer == 'admin' && $transaction->order->is_shipping_free) ? $transaction->order->extra_discount : 0;
    }

    private function getAdminDiscount($adminCouponDiscount, $adminShippingDiscount, $referAndEarnDiscount): float
    {
        return $adminCouponDiscount + $adminShippingDiscount + $referAndEarnDiscount;
    }

    private function getSellerCouponDiscount($transaction): float
    {
        return ($transaction['seller_is'] == 'seller' && $transaction->order->coupon_discount_bearer == 'seller' && $transaction->order->discount_type == 'coupon_discount') ? $transaction->order->discount_amount : 0;
    }

    private function getSellerShippingDiscount($transaction): float
    {
        return ($transaction->order->free_delivery_bearer == 'seller' && $transaction->order->is_shipping_free) ? $transaction->order->extra_discount : 0;
    }

    private function getVendorDiscount($sellerCouponDiscount, $sellerShippingDiscount): float
    {
        return $sellerCouponDiscount + $sellerShippingDiscount;
    }

    private function getAdminNetIncome($transaction, $adminCouponDiscount, $adminShippingDiscount, $sellerCouponDiscount): float
    {
        $adminNetIncome = 0;

        if ($transaction['seller_is'] == 'admin') {
            $adminNetIncome += $transaction['order_amount'];
        }

        if (isset($transaction->order->deliveryMan) && $transaction->order->deliveryMan->seller_id == 0) {
            $adminNetIncome += $transaction['delivery_charge'];
        } elseif (!isset($transaction->order->deliveryMan) && ($transaction['seller_is'] == 'seller') && ($transaction->order->shipping_responsibility == 'inhouse_shipping' || $transaction['seller_is'] == 'admin')) {
            $adminNetIncome += $transaction->order->is_shipping_free && $transaction->order->free_delivery_bearer == 'admin' ? 0 : $transaction->order->shipping_cost;
        }

        $adminNetIncome += $transaction['admin_commission'];
        $adminNetIncome -= $transaction?->order?->refer_and_earn_discount ?? 0;

        if ((empty($transaction?->order?->delivery_type) || $transaction->order->delivery_type == 'self_delivery') && ($transaction->order->shipping_responsibility == 'inhouse_shipping' || $transaction->order->seller_is == 'admin')) {
            $adminNetIncome -= $transaction->order->deliveryman_charge;
        }

        if ($transaction['seller_is'] == 'seller') {
            $adminNetIncome -= $transaction->order->coupon_discount_bearer == 'inhouse' ? $adminCouponDiscount : 0;
        }

        if ($transaction->order->shipping_responsibility == 'inhouse_shipping') {
            if (!$transaction->order->is_shipping_free) {
                $adminNetIncome += $transaction['seller_is'] == 'seller' ? $transaction->order->shipping_cost : 0;
            }
            if ($transaction->order->is_shipping_free) {
                $adminNetIncome += 0;
            } else if (($transaction->order->coupon_discount_bearer == 'seller' && isset($transaction->order->coupon) && $transaction->order->coupon->coupon_type == 'free_delivery')) {
                $adminNetIncome += $sellerCouponDiscount;
            }
        } elseif ($transaction->order->shipping_responsibility == 'sellerwise_shipping' && $transaction['seller_is'] == 'seller') {
            $adminNetIncome -= $transaction->order->free_delivery_bearer == 'admin' ? $adminShippingDiscount : 0;
        }

        return $adminNetIncome;
    }

    private function getVendorNetIncome($transaction, $adminCouponDiscount): float
    {
        $vendorNetIncome = 0;

        if ($transaction['seller_is'] == 'seller') {
            $vendorNetIncome += $transaction['order_amount'] - $transaction['admin_commission'];
            if (isset($transaction->order->deliveryMan) && $transaction->order->deliveryMan->seller_id != 0) {
                $vendorNetIncome += $transaction['delivery_charge'];
            }
        }

        if ((empty($transaction?->order?->delivery_type) || $transaction->order->delivery_type == 'self_delivery') && $transaction->order->shipping_responsibility == 'sellerwise_shipping' && $transaction->order->seller_is == 'seller') {
            $vendorNetIncome -= $transaction->order->deliveryman_charge;
        }

        if ($transaction['seller_is'] == 'seller') {
            // $vendorNetIncome += $transaction->order->coupon_discount_bearer == 'inhouse' ? $adminCouponDiscount : 0;
        }

        if ($transaction['seller_is'] == 'seller') {
            if ($transaction->order->shipping_responsibility == 'inhouse_shipping') {
                $vendorNetIncome -= $transaction->order->shipping_cost;
                $vendorNetIncome += $transaction->order->free_delivery_bearer == 'admin' && $transaction->order->is_shipping_free ? $transaction->order->shipping_cost : 0;
            } elseif ($transaction->order->shipping_responsibility == 'sellerwise_shipping') {
                $vendorNetIncome += $transaction->order->free_delivery_bearer == 'admin' ? $transaction->order->shipping_cost : 0;
                //$vendorNetIncome -= ($transaction->order->free_delivery_bearer == 'seller') ? $transaction->order->shipping_cost : 0;
            }
        }
        return $vendorNetIncome;
    }

    private function getFirstItem($transactions): int
    {
        if ($transactions instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            return $transactions->firstItem();
        }
        return 1;
    }
    public function getThisWeekOrderAmount($orders): array
    {
        $number = 6;
        $order_amount = [];
        $period = CarbonPeriod::create(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek());
        $day_name = array();
        foreach ($period as $date) {
            $day_name[] = $date->format('l');
        }
        for ($inc = 0; $inc <= $number; $inc++) {
            $order_amount[$day_name[$inc]] = 0;
            foreach ($orders as $match) {
                if ($match['day'] == $day_name[$inc]) {
                    $order_amount[$day_name[$inc]] = $match['order_amount'];
                }
            }
        }
        return $order_amount;
    }
}
