<?php

namespace App\Services\Admin\Reports;

class TransectionReportPdfService
{
    private object|array $transactions;
    private array $totals;

    public function __construct($transactions)
    {
        $this->transactions = $transactions;
        $this->initializeTotals();
    }

    private function initializeTotals(): void
    {
        $this->totals = [
            'total_ordered_product_price' => 0,
            'total_product_discount' => 0,
            'total_coupon_discount' => 0,
            'total_referral_discount' => 0,
            'total_discounted_amount' => 0,
            'total_tax' => 0,
            'total_delivery_charge' => 0,
            'total_order_amount' => 0,
            'total_admin_discount' => 0,
            'total_seller_discount' => 0,
            'total_admin_commission' => 0,
            'total_admin_net_income' => 0,
            'total_seller_net_income' => 0,
            'total_admin_shipping_discount' => 0,
            'total_seller_shipping_discount' => 0,
            'total_deliveryman_incentive' => 0,
        ];
    }

    public function calculateTotals(): array
    {
        foreach ($this->transactions as $transaction) {
            if (!$transaction->order) {
                continue;
            }
            $this->calculateOrderedProductPrice($transaction);
            $this->calculateProductDiscount($transaction);
            $this->calculateCouponDiscount($transaction);
            $this->calculateDiscountedAmount($transaction);
            $this->calculateTax($transaction);
            $this->calculateDeliveryCharge($transaction);
            $this->calculateOrderAmount($transaction);
            $this->calculateAdminDiscount($transaction);
            $this->calculateSellerDiscount($transaction);
            $this->calculateAdminCommission($transaction);
            $this->calculateDeliverymanIncentive($transaction);
            $this->calculateAdminNetIncome($transaction);
            $this->calculateSellerNetIncome($transaction);
        }
        return $this->totals;
    }

    private function calculateOrderedProductPrice($transaction): void
    {
        $this->totals['total_ordered_product_price'] += $transaction->orderDetails[0]?->order_details_sum_price ?? 0;
    }

    private function calculateProductDiscount($transaction): void
    {
        $this->totals['total_product_discount'] += $transaction->orderDetails[0]?->order_details_sum_discount ?? 0;
    }

    private function calculateCouponDiscount($transaction): void
    {
        $this->totals['total_coupon_discount'] += $transaction->order->discount_amount;
    }

    private function calculateDiscountedAmount($transaction): void
    {
        $orderDetailsPrice = $transaction->orderDetails[0]?->order_details_sum_price ?? 0;
        $orderDetailsDiscount = $transaction->orderDetails[0]?->order_details_sum_discount ?? 0;
        $couponDiscount = isset($transaction->order->coupon) && $transaction->order->coupon->coupon_type != 'free_delivery'
            ? $transaction->order->discount_amount
            : 0;

        $this->totals['total_discounted_amount'] += $orderDetailsPrice - $orderDetailsDiscount - $couponDiscount;
    }

    private function calculateTax($transaction): void
    {
        $this->totals['total_tax'] += $transaction->tax;
    }

    private function calculateDeliveryCharge($transaction): void
    {
        $this->totals['total_delivery_charge'] += $transaction->order->shipping_cost;
    }

    private function calculateOrderAmount($transaction): void
    {
        $orderAmount = ($transaction?->order?->order_amount ?? 0)
            + ($transaction?->order?->edit_due_amount ?? 0)
            - ($transaction?->order?->edit_return_amount ?? 0);

        $this->totals['total_order_amount'] += $orderAmount;
    }

    private function calculateAdminDiscount($transaction): void
    {
        $adminCouponDiscount = $this->getAdminCouponDiscount($transaction);
        $adminShippingDiscount = $this->getAdminShippingDiscount($transaction);
        $referAndEarnDiscount = $transaction?->order?->refer_and_earn_discount ?? 0;

        $this->totals['total_admin_discount'] += $adminCouponDiscount + $adminShippingDiscount + $referAndEarnDiscount;
        $this->totals['total_admin_shipping_discount'] += $adminShippingDiscount;
    }

    private function calculateSellerDiscount($transaction): void
    {
        $sellerCouponDiscount = $this->getSellerCouponDiscount($transaction);
        $sellerShippingDiscount = $this->getSellerShippingDiscount($transaction);

        $this->totals['total_seller_discount'] += $sellerCouponDiscount + $sellerShippingDiscount;
        $this->totals['total_seller_shipping_discount'] += $sellerShippingDiscount;
    }

    private function calculateAdminCommission($transaction): void
    {
        $this->totals['total_admin_commission'] += $transaction->admin_commission;
    }

    private function calculateDeliverymanIncentive($transaction): void
    {
        $this->totals['total_deliveryman_incentive'] += $transaction->order->deliveryman_charge;
    }

    private function calculateAdminNetIncome($transaction): void
    {
        $adminNetIncome = $this->getAdminNetIncome($transaction);
        $this->totals['total_admin_net_income'] += $adminNetIncome;
    }

    private function calculateSellerNetIncome($transaction): void
    {
        $sellerNetIncome = $this->getSellerNetIncome($transaction);
        $this->totals['total_seller_net_income'] += $sellerNetIncome;
    }

    private function getAdminCouponDiscount($transaction)
    {
        return ($transaction->order->coupon_discount_bearer == 'inhouse' && $transaction->order->discount_type == 'coupon_discount')
            ? $transaction->order->discount_amount
            : 0;
    }

    private function getAdminShippingDiscount($transaction)
    {
        return ($transaction->order->is_shipping_free && $transaction->order->free_delivery_bearer == 'admin')
            ? $transaction->order->extra_discount
            : 0;
    }

    private function getSellerCouponDiscount($transaction)
    {
        return ($transaction->order->coupon_discount_bearer == 'seller' && $transaction->order->discount_type == 'coupon_discount')
            ? $transaction->order->discount_amount
            : 0;
    }

    private function getSellerShippingDiscount($transaction)
    {
        return ($transaction->order->is_shipping_free && $transaction->order->free_delivery_bearer == 'seller')
            ? $transaction->order->extra_discount
            : 0;
    }

    private function getAdminNetIncome($transaction)
    {
        $adminNetIncome = 0;
        $order = $transaction->order;

        if ($transaction['seller_is'] == 'admin') {
            $adminNetIncome += $transaction['order_amount'];
        }

        if (isset($order->deliveryMan) && $order->deliveryMan->seller_id == 0) {
            $adminNetIncome += $transaction['delivery_charge'];
        } elseif (!isset($order->deliveryMan) && ($transaction['seller_is'] == 'seller') && ($order->shipping_responsibility == 'inhouse_shipping' || $transaction['seller_is'] == 'admin')) {
            $shippingCost = $order->is_shipping_free && $order->free_delivery_bearer == 'admin' ? 0 : $order->shipping_cost;
            $adminNetIncome += $shippingCost;
        }

        $adminNetIncome += $transaction['admin_commission'];
        $adminNetIncome -= $transaction?->order?->refer_and_earn_discount ?? 0;

        if ((empty($order->delivery_type) || $order->delivery_type == 'self_delivery')
            && ($order->shipping_responsibility == 'inhouse_shipping' || $order->seller_is == 'admin')) {
            $adminNetIncome -= $order->deliveryman_charge;
        }

        if ($transaction['seller_is'] == 'seller') {
            $adminCouponDiscount = $this->getAdminCouponDiscount($transaction);
            $adminNetIncome -= $order->coupon_discount_bearer == 'inhouse' ? $adminCouponDiscount : 0;
        }

        if ($order->shipping_responsibility == 'inhouse_shipping') {
            if ($order->is_shipping_free) {
                $adminNetIncome += 0;
            } elseif (($order->coupon_discount_bearer == 'seller' && isset($order->coupon) && $order->coupon->coupon_type == 'free_delivery')) {
                $sellerCouponDiscount = $this->getSellerCouponDiscount($transaction);
                $adminNetIncome += $sellerCouponDiscount;
            }
        } elseif ($order->shipping_responsibility == 'sellerwise_shipping' && $transaction['seller_is'] == 'seller') {
            $adminShippingDiscount = $this->getAdminShippingDiscount($transaction);
            $adminNetIncome -= $order->free_delivery_bearer == 'admin' ? $adminShippingDiscount : 0;
        }

        return $adminNetIncome;
    }

    private function getSellerNetIncome($transaction)
    {
        $sellerNetIncome = 0;
        $order = $transaction->order;

        if ($transaction['seller_is'] == 'seller') {
            $sellerNetIncome += $transaction['order_amount'] - $transaction['admin_commission'];

            if (isset($order->deliveryMan) && $order->deliveryMan->seller_id != 0) {
                $sellerNetIncome += $transaction['delivery_charge'];
            }
        }

        if ((empty($order->delivery_type) || $order->delivery_type == 'self_delivery')
            && $order->shipping_responsibility == 'sellerwise_shipping'
            && $order->seller_is == 'seller') {
            $sellerNetIncome -= $order->deliveryman_charge;
        }

        if ($transaction['seller_is'] == 'seller') {
            $adminCouponDiscount = $this->getAdminCouponDiscount($transaction);
            $sellerNetIncome += $order->coupon_discount_bearer == 'inhouse' ? $adminCouponDiscount : 0;
        }

        if ($transaction['seller_is'] == 'seller') {
            if ($order->shipping_responsibility == 'inhouse_shipping') {
                $sellerNetIncome -= $order->shipping_cost;
                $sellerNetIncome += $order->free_delivery_bearer == 'admin' && $order->is_shipping_free ? $order->shipping_cost : 0;
            } elseif ($order->shipping_responsibility == 'sellerwise_shipping') {
                $sellerNetIncome += $order->free_delivery_bearer == 'admin' ? $order->shipping_cost : 0;
                $sellerNetIncome -= ($order->free_delivery_bearer == 'seller') ? $order->shipping_cost : 0;
            }
        }

        return $sellerNetIncome;
    }
}
