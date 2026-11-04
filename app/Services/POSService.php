<?php

namespace App\Services;

use App\Enums\SessionKey;
use App\Traits\CalculatorTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Support\Str;

class POSService
{
    use CalculatorTrait;

    public function getTotalHoldOrders(): int
    {
        $totalHoldOrders = 0;
        if (session()->has(SessionKey::CART_NAME)) {
            foreach (session(SessionKey::CART_NAME) as $item) {
                if (session()->has($item) && count(session($item)) > 1) {
                    if (isset(session($item)[0]) && is_array(session($item)[0]) && isset(session($item)[0]['customerOnHold']) && session($item)[0]['customerOnHold']) {
                        $totalHoldOrders++;
                    }
                }
            }
        }
        return $totalHoldOrders;
    }

    public function getCartNames(): array
    {
        $cartNames = [];
        if (session()->has(SessionKey::CART_NAME)) {
            foreach (session(SessionKey::CART_NAME) as $item) {
                if (session()->has($item) && count(session($item)) > 1) {
                    $cartNames[] = $item;
                }
            }
        }
        return $cartNames;
    }

    public function UpdateSessionWhenCustomerChange(string $cartId): void
    {
        if (!in_array($cartId, session(SessionKey::CART_NAME) ?? [])) {
            session()->push(SessionKey::CART_NAME, $cartId);
        }

        $currentCartName = session(SessionKey::CURRENT_USER);
        $cart = session($currentCartName);

        $cartKeeper = [];
        if (session()->has($currentCartName) && count($cart) > 0) {
            foreach ($cart as $key => $cartItem) {
                if (is_array($cartItem)) {
                    $cartItem['customerId'] = Str::contains($cartId, 'walk-in-customer') ? '0' : explode('-', $cartId)[2];
                    $cartKeeper[$key] = $cartItem;
                } else {
                    $cartKeeper[$key] = $cartItem;
                }
            }
        }

        if ($currentCartName != $cartId) {
            $tempCartName = [];
            foreach (session(SessionKey::CART_NAME) as $cartName) {
                if ($cartName != $currentCartName) {
                    $tempCartName[] = $cartName;
                }
            }
            session()->put(SessionKey::CART_NAME, $tempCartName);
        }
        session()->forget($currentCartName);
        session()->put($cartId, $cartKeeper);
        session()->put(SessionKey::CURRENT_USER, $cartId);
    }

    public function checkConditions(float $amount, ?float $paidAmount = null): bool
    {
        $condition = false;
        $cartId = session(SessionKey::CURRENT_USER);
        if (session()->has($cartId)) {
            if (count(session()->get($cartId)) < 1) {
                ToastMagic::error(translate('cart_empty_warning'));
                $condition = true;
            }
        } else {
            ToastMagic::error(translate('cart_empty_warning'));
            $condition = true;
        }
        if ($amount <= 0) {
            ToastMagic::error(translate('You cannot place an order with an amount of zero. Please enter a valid amount.'));
            $condition = true;
        }
        if (!is_null($paidAmount) && $paidAmount < $amount) {
            ToastMagic::error(translate('paid_amount_is_less_than_total_amount'));
            $condition = true;
        }
        return $condition;
    }

    public function getCouponCalculation(object $coupon, float $totalProductPrice, float $productDiscount): array
    {
        if ($coupon['discount_type'] === 'percentage') {
            $discount = min(((($totalProductPrice - $productDiscount) / 100) * $coupon['discount']), $coupon['max_discount']);
        } else {
            $discount = $coupon['discount'];
        }
        $total = $totalProductPrice - $productDiscount - $discount;
        return [
            'total' => $total,
            'discount' => $discount,
        ];
    }

    public function putCouponDataOnSession($cartId, $discount, $couponTitle, $couponBearer, $couponCode): void
    {
        $cart = session($cartId, collect([]));
        $cart['coupon_code'] = $couponCode;
        $cart['coupon_discount'] = $discount;
        $cart['coupon_title'] = $couponTitle;
        $cart['coupon_bearer'] = $couponBearer;
        session()->put($cartId, $cart);
    }

    public function getVariantData(string $type, array $variation, int $quantity): array
    {
        $variationData = [];
        foreach ($variation as $variant) {
            if ($type == $variant['type']) {
                // Floor at zero: another variant's stock can mask a single-variant shortfall past the
                // product-total check, so this subtraction could otherwise persist a negative variant qty.
                $variant['qty'] = max(0, $variant['qty'] - $quantity);
            }
            $variationData[] = $variant;
        }
        return $variationData;
    }

    public function getSummaryData(): array
    {
        return [
            'cartName' => session(SessionKey::CART_NAME),
            'currentUser' => session(SessionKey::CURRENT_USER),
            'totalHoldOrders' => $this->getTotalHoldOrders(),
            'cartNames' => $this->getCartNames(),
        ];
    }
}
