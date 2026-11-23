<?php

namespace App\Services;

use App\Enums\SessionKey;
use App\Traits\CalculatorTrait;
use App\Utils\CartManager;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\TaxModule\app\Traits\VatTaxManagement;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class CartService
{
    use CalculatorTrait;
    use VatTaxManagement;

    /**
     * @param object $request
     * @param object $product
     * @param string|null $colorName
     * @return array
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getVariantData(object $request, object $product, ?string $colorName = null): array
    {
        $quantity = 0;
        $price = 0;
        $unitPrice = 0;
        $discount = 0;
        $currentStock = 0;
        $variations = json_decode($product->variation, true) ?? [];
        $hasVariations = !empty($variations);
        $variation = $this->makeVariation(
            request: $request,
            colorName: $colorName,
            choiceOptions: json_decode($product['choice_options'])
        );

        if ($variation != null) {
            $count = count(json_decode($product->variation));
            for ($i = 0; $i < $count; $i++) {
                if (json_decode($product->variation)[$i]->type == $variation) {
                    $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: json_decode($product->variation)[$i]->price, from: 'panel');
                    $price = json_decode($product->variation)[$i]->price - $discount;
                    $unitPrice = json_decode($product->variation)[$i]->price;
                    $quantity = json_decode($product->variation)[$i]->qty;
                }
            }
        } else {
            $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $product['unit_price'], from: 'panel');
            $price = $product['unit_price'] - $discount;
            $unitPrice = $product['unit_price'];
            $quantity = $product['current_stock'];
        }

        $requestQuantity = (int)$request['quantity'];

        $inCartStatus = 0;
        $cartData = session(SessionKey::CURRENT_USER) ? session()->get(session(SessionKey::CURRENT_USER)) : [];
        if (is_null($cartData)) {
            $cartData = [];
        }
        $inCartData = null;

        if ($product['product_type'] == 'digital' && $request->has('variant_key')) {
            $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $product['unit_price'], from: 'panel');
            $quantity = $product['current_stock'];
            foreach ($product['digitalVariation'] as $variant) {
                if ($variant['variant_key'] == $request->variant_key) {
                    $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $variant['price'], from: 'panel');
                    $price = $variant['price'] - $discount;
                    $unitPrice = $variant['price'];
                    $variation = $variant['variant_key'];
                }
            }
        }

        foreach ($cartData as $cart) {
            if (is_array($cart) && $cart['id'] == $product['id'] && $cart['variant'] == $variation) {
                $inCartStatus = 1;
                $cartDiscount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $cart['price'], from: 'panel');
                $price = ($cart['price'] - $cartDiscount);
                $inCartData = [
                    'price' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $price * $requestQuantity)),
                    'discount' => usdToDefaultCurrency($cartDiscount),
                    'quantity' => (int)$cart['quantity'],
                    'variant' => $cart['variant'],
                    'id' => $cart['id'],
                ];
                $requestQuantity = (int)($request['quantity_in_cart'] ?? $cart['quantity']);
            }
        }
        $discountType = getProductPriceByType(product: $product, type: 'discount_type', result: 'string');
        $allVariationsOutOfStock = $hasVariations ? empty(array_filter($variations, fn($v) => ($v['qty'] ?? 0) > 0)) : ($quantity < 1);
        return [
            'price' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $price * $requestQuantity)),
            'discount' => usdToDefaultCurrency($discount),
            'discount_amount' => $discount,
            'discount_type' => $discountType,
            'discount_text' => $discountType == 'flat' ? translate('save') . ' ' . setCurrencySymbol(amount: usdToDefaultCurrency(amount: $discount), currencyCode: getCurrencyCode()) : getProductPriceByType(product: $product, type: 'discount', result: 'value') . '% ' . translate('off'),
            'quantity' => $product['product_type'] == 'physical' ? $quantity : 100,
            'inCartStatus' => $inCartStatus,
            'inCartData' => $inCartData,
            'requestQuantity' => $requestQuantity,
            'total_unit_price' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $unitPrice)),
            'discounted_unit_price' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: $unitPrice - $discount)),
            'allVariationsOutOfStock' => $allVariationsOutOfStock
        ];
    }

    public function makeVariation(object $request, string|null $colorName, array $choiceOptions): string
    {
        $variation = '';
        if ($colorName) {
            $variation = $colorName;
        }
        foreach ($choiceOptions as $choice) {
            if ($variation != null) {
                $variation .= '-' . str_replace(' ', '', $request[$choice->name]);
            } else {
                $variation .= str_replace(' ', '', $request[$choice->name]);
            }
        }
        return $variation;
    }

    public function getUserId(): int
    {
        $userId = 0;
        if (Str::contains(session(SessionKey::CURRENT_USER), 'saved-customer')) {
            $userId = explode('-', session(SessionKey::CURRENT_USER))[2];
        }
        return $userId;
    }

    public function getUserType(): string
    {
        $userType = 'walk-in-customer';
        if (Str::contains(session(SessionKey::CURRENT_USER), 'saved-customer')) {
            $userType = 'saved-customer';
        }
        return $userType;
    }

    public function getNewCartSession(string|int $cartId): void
    {
        if (!session()->has(SessionKey::CURRENT_USER)) {
            session()->put(SessionKey::CURRENT_USER, $cartId);
        }
        if (!session()->has(SessionKey::CART_NAME)) {
            if (!in_array($cartId, session(SessionKey::CART_NAME) ?? [])) {
                session()->push(SessionKey::CART_NAME, $cartId);
            }
        }
    }

    public function getCartKeeper(): void
    {
        $cartId = session(SessionKey::CURRENT_USER);

        // Before the first POS cart exists this key is unset, and `session(null)` returns the
        // SessionManager itself rather than null — so `count($cart)` was a fatal TypeError and the
        // POS could not open a cart at all. Nothing to keep when there is no cart id.
        if (!$cartId) {
            return;
        }

        $cart = session($cartId);
        $cartKeeper = [];
        if (is_array($cart)) {
            foreach ($cart as $cartItem) {
                $cartKeeper[] = $cartItem;
            }
        }
        session()->put($cartId, $cartKeeper);
    }

    public function getVariationPrice(array $variation, string $variant): float
    {
        $count = count($variation);
        $price = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($variation[$i]->type == $variant) {
                $price = $variation[$i]->price;
            }
        }
        return $price;
    }

    public function getVariationQuantity(array $variation, string $variant): int
    {
        $count = count($variation);
        $productQuantity = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($variation[$i]->type == $variant) {
                $productQuantity = $variation[$i]->qty;
            }
        }
        return $productQuantity;
    }

    public function getCurrentQuantity($variation, $variant, $quantity): int
    {
        $productQuantity = $this->getVariationQuantity($variation, $variant);
        return $productQuantity - $quantity;
    }

    public function addCartDataOnSession(object $product, int $quantity, float $price, float $discount, string $variant, array $variations): array
    {
        $cartId = session(SessionKey::CURRENT_USER);
        $sessionData = [
            'id' => $product['id'],
            'customerId' => $this->getUserId(),
            'customerOnHold' => false,
            'quantity' => $quantity,
            'price' => $price,
            'name' => $product['name'],
            'productType' => $product['product_type'],
            'image' => $product->thumbnail_full_url,
            'discount' => $discount,
            'variant' => $variant,
            'variations' => $variations,
        ];
        if (session()->has($cartId)) {
            $keeper = [];
            foreach (session($cartId) as $item) {
                $keeper[] = $item;
            }
            $keeper[] = $sessionData;

            if (!isset(session()->get($cartId)['add_to_cart_time'])) {
                $keeper += ['add_to_cart_time' => Carbon::now()];
            }
            session()->put($cartId, $keeper);
        } else {
            session()->put($cartId, [$sessionData] + ['add_to_cart_time' => Carbon::now()]);
        }
        return $sessionData;
    }

    public function getProductCounts(): array
    {
        $cartId = session(SessionKey::CURRENT_USER);
        $cartData = session($cartId, []);
        $productCounts = [];

        if (!empty($cartData)) {
            foreach ($cartData as $key => $item) {
                if ($key === 'add_to_cart_time' || !is_array($item) || !isset($item['id'])) {
                    continue;
                }
                $productId = $item['id'];
                $quantity = $item['quantity'] ?? 0;
                if (isset($productCounts[$productId])) {
                    $productCounts[$productId] += $quantity;
                } else {
                    $productCounts[$productId] = $quantity;
                }
            }
        }
        return $productCounts;
    }
    public function getQuantityAndUpdateTime(object $request, object $product): int
    {
        $quantity = 0;
        $cartId = session(SessionKey::CURRENT_USER);
        $cart = session($cartId);
        $keeper = [];

        foreach ($cart as $item) {
            if (is_array($item)) {
                $variantCheck = false;
                if (!empty($item['variant']) && ($item['variant'] == $request['variant']) && ($item['id'] == $request['key'])) {
                    $variantCheck = true;
                } elseif (empty($request['variant']) && $item['id'] == $request['key']) {
                    $variantCheck = true;
                }

                if ($variantCheck) {
                    $variant = '';
                    if ($item['variations']) {
                        foreach ($item['variations'] as $value) {
                            if ($variant != null) {
                                $variant .= '-' . str_replace(' ', '', $value);
                            } else {
                                $variant .= str_replace(' ', '', $value);
                            }
                        }
                    }

                    if ($variant != null) {
                        $productQuantity = $this->getVariationQuantity(json_decode($product['variation']), $variant);
                    } else {
                        $productQuantity = $product['current_stock'];
                    }

                    $quantity = $productQuantity - $request['quantity'];
                    if ($product['product_type'] == 'physical' && $quantity < 0) {
                        return $quantity;
                    }
                    $item['quantity'] = $request['quantity'];
                }
                $keeper[] = $item;
            }
        }
        $keeper += ['add_to_cart_time' => Carbon::now()];
        session()->put($cartId, $keeper);
        return $quantity;
    }

    public function getNewCartId(): void
    {
        $cartId = 'walk-in-customer-' . rand(10, 1000);
        session()->put(SessionKey::CURRENT_USER, $cartId);
        if (!in_array($cartId, session(SessionKey::CART_NAME) ?? [])) {
            session()->push(SessionKey::CART_NAME, $cartId);
        }
    }

    /**
     * The current POS cart id, creating one if the session has none yet.
     *
     * Only `POSController@index` established this key, so every other POS endpoint assumed a page
     * load had already happened. Hit directly — which is what the panel's own AJAX does after a
     * session expires — they passed null into `getCartData(string $cartName)` and fataled. A POS
     * with no cart yet is a walk-in customer, which is exactly what getNewCartId() sets up.
     */
    public function ensureCartSession(): string
    {
        if (!session(SessionKey::CURRENT_USER)) {
            $this->getNewCartId();
        }

        return (string) session(SessionKey::CURRENT_USER);
    }

    public function getCartSubtotalCalculation(object|array $product, array $cartItem, int|float $totalDiscountedPrice, string $cartName): array
    {
        $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $cartItem['price'], from: 'panel');
        $productSubtotal = (($cartItem['price'] - $discount) * $cartItem['quantity']);

        $couponDiscount = 0;
        if (isset(session()->get($cartName)['coupon_discount'])) {
            $couponDiscount = session()->get($cartName)['coupon_discount'];
        }
        $productCouponDiscount = ($couponDiscount * $productSubtotal) / $totalDiscountedPrice;

        $extraDiscount = session()->get($cartName)['ext_discount'] ?? 0;
        $extraDiscountType = session()->get($cartName)['ext_discount_type'] ?? 'amount';
        if ($extraDiscountType == 'percent' && $extraDiscount > 0) {
            $extraDiscount = (($totalDiscountedPrice - $couponDiscount) * $extraDiscount) / 100;
        }
        $productExtraDiscount = ($extraDiscount * $productSubtotal) / $totalDiscountedPrice;

        $taxConfig = self::getTaxSystemType();
        $appliedTaxAmount = CartManager::getAppliedTaxAmount(
            product: $product,
            taxConfig: $taxConfig,
            totalDiscountedPrice: $productSubtotal,
            appliedDiscountedAmount: $productCouponDiscount + $productExtraDiscount,
        );

        return [
            'countItem' => 1,
            'totalQuantity' => $cartItem['quantity'],
            'productSubtotal' => $productSubtotal,
            'subtotal' => $productSubtotal,
            'discountOnProduct' => $discount * $cartItem['quantity'],
            'couponDiscount' => $productCouponDiscount,
            'extraDiscount' => $productExtraDiscount,
            'appliedTaxAmount' => $appliedTaxAmount,
        ];
    }

    public function getTotalCalculation(array $subTotalCalculation, string $cartName): array
    {
        $couponDiscount = 0;
        if (isset(session()->get($cartName)['coupon_discount'])) {
            $couponDiscount = session()->get($cartName)['coupon_discount'];
        }

        $total = $subTotalCalculation['subtotal'];
        $extraDiscount = session()->get($cartName)['ext_discount'] ?? 0;
        $extraDiscountType = session()->get($cartName)['ext_discount_type'] ?? 'amount';
        if ($extraDiscountType == 'percent' && $extraDiscount > 0) {
            $extraDiscount = (($subTotalCalculation['subtotal'] - $couponDiscount) * $extraDiscount) / 100;
        }
        if ($extraDiscount) {
            $total -= $extraDiscount;
        }

        return [
            'total' => $total,
            'couponDiscount' => $couponDiscount,
            'extraDiscount' => $extraDiscount
        ];
    }

    public function customerOnHoldStatus($status): void
    {
        $cart = session(session(SessionKey::CURRENT_USER));
        $cartKeeper = [];
        if (session()->has(session(SessionKey::CURRENT_USER)) && count($cart) > 0) {
            foreach ($cart as $cartItem) {
                if (is_array($cartItem)) {
                    $cartItem['customerOnHold'] = $status;
                }
                $cartKeeper[] = $cartItem;
            }
        }
        session()->put(session(SessionKey::CURRENT_USER), $cartKeeper);
    }

    public function getCartTotalDiscountPrice($cartList, $products, $customerID): float|int
    {
        $totalDiscountedPrice = 0;
        if ($cartList) {
            foreach ($cartList as $cartItem) {
                if (is_array($cartItem)) {
                    $product = $products->firstWhere('id', $cartItem['id']);
                    if ($product) {
                        if ($cartItem['customerId'] == $customerID) {
                            $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $cartItem['price'], from: 'panel');
                            $totalDiscountedPrice += (($cartItem['price'] - $discount) * $cartItem['quantity']);
                        }
                    }
                }
            }
        }
        return $totalDiscountedPrice;
    }

    public function checkCurrentStock(string $variant, array $variation, int $productQty, int $quantity): int
    {
        if ($variant != null) {
            $currentQty = $this->getCurrentQuantity(variation: $variation, variant: $variant, quantity: $quantity);
        } else {
            $currentQty = $productQty - $quantity;
        }
        return $currentQty;
    }

    public function checkProductTypeDigital(string|int $cartId): bool
    {
        $cart = session($cartId);
        $isDigitalProduct = false;
        foreach ($cart as $item) {
            if (is_array($item) && $item['productType'] == 'digital') {
                $isDigitalProduct = true;
            }
        }
        return $isDigitalProduct;
    }

    public function getCustomerInfo(object|null $currentCustomerData, int $customerId): array
    {
        if ($currentCustomerData) {
            $customerName = $currentCustomerData['f_name'] . ' ' . $currentCustomerData['l_name'];
            $customerPhone = $currentCustomerData['phone'];
        } else {
            $customerName = "";
            $customerPhone = "";
            session()->forget(session($customerId));
            $this->getNewCartId();
        }
        return [
            'customerName' => $customerName,
            'customerPhone' => $customerPhone
        ];
    }

    public function filterSessionCartList($cart): Collection
    {
        $cartListVariations = [];
        return collect($cart)->filter(function ($cart, $key) {
            return is_array($cart);
        })->filter(function ($cartItem, $key) use (&$cartListVariations) {
            $variations = $cartItem['id'] . '-' . $cartItem['productType'] . '-Q' . $cartItem['quantity'] . '-' . ($cartItem['variant'] ? $cartItem['variant'] : 'single');
            $variationExist = in_array($variations, $cartListVariations);
            $cartListVariations[] = $variations;
            return !$variationExist;
        });
    }
}
