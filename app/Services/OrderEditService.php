<?php

namespace App\Services;

use App\Traits\ProductTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Modules\TaxModule\app\Traits\VatTaxManagement;

class OrderEditService
{

    use ProductTrait;
    use VatTaxManagement;

    public function getOrderEditSessionKey(string $orderId): string
    {
        $sessionKey = '';
        $sessionSuffix = 'SESSION_FOR_EDIT_ORDER_' . $orderId;
        if (auth()->guard('admin')->check()) {
            $sessionKey = 'ADMIN_' . $sessionSuffix;
        } else if (auth()->guard('seller')->check()) {
            $sessionKey = 'SELLER_' . $sessionSuffix;
        }
        return $sessionKey;
    }

    public function getOrderEditSession(object|array $order): array
    {
        $userType = auth()->guard('admin')->check() ? 'ADMIN' : (auth()->guard('seller')->check() ? 'SELLER' : 'DEFAULT');
        $sessionKey = $userType . '_SESSION_FOR_EDIT_ORDER_' . $order['id'];
        $session = Session::get($sessionKey, []);
        if (!Session::has($sessionKey) || !isset($session['product_list'])) {
            Session::put($sessionKey, ['product_list' => $this->addOrderDetailsInSession(order: $order)]);
        }
        return Session::get($sessionKey, ['product_list' => $this->addOrderDetailsInSession(order: $order)]);
    }


    public function getVariationFromRequest(object $request, string|null $colorName, array $choiceOptions): string
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


    public function getProductCurrentStockInfo(object|array $request, object|array $order, object|array $product, string|null $colorName = ''): array
    {
        $variations = json_decode($product->variation, true) ?? [];
        $hasVariations = is_array($variations) && count($variations) > 0;
        $currentQuantity = $product['minimum_order_qty'] ?? 1;
        $allVariationsOutOfStock = true;
        $firstVariantType = '';

        if ($hasVariations) {
            $generateVariation = $this->getVariationFromRequest(
                request: $request,
                colorName: $colorName,
                choiceOptions: json_decode($product['choice_options'])
            );

            $firstVariation = collect($variations)->first(function ($variation) use ($generateVariation) {
                return $variation['type'] == $generateVariation;
            }) ?? $variations[0];

            $firstVariantType = $firstVariation['type'] ?? "";
            $currentStock = $firstVariation['qty'] ?? 0;
            $currentPrice = $firstVariation['price'] ?? 0;
            foreach ($variations as $v) {
                if (!empty($v['qty']) && $v['qty'] > 0) {
                    $allVariationsOutOfStock = false;
                    break;
                }
            }
        } else {
            $currentStock = max(0, $product->current_stock);
            $currentPrice = $product->unit_price;
        }

        $checkDetails = collect($order?->details)?->where('product_id', $product['id'])->firstWhere('variant', $firstVariantType);
        if ($checkDetails) {
            $currentStock += $checkDetails['qty'] ?? 1;
        }

        $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $currentPrice);
        $discountedPrice = getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'value', price: $currentPrice);

        $alreadyInCart = false;
        $orderSession = $this->getOrderEditSession(order: $order);
        $productList = $orderSession['product_list'] ?? [];
        foreach ($productList as $key => $item) {
            if ($item['product_id'] == $product['id'] && ($item['variant'] ?? '') === $firstVariantType) {
                $alreadyInCart = true;
                $currentQuantity = $item['qty'] ?? 1;
                break;
            }
        }

        return [
            'variant' => $firstVariantType,
            'has_variations' => $hasVariations,
            'current_stock' => $currentStock,
            'current_price' => $currentPrice,
            'current_quantity' => $currentQuantity,
            'stock_out_status' => $allVariationsOutOfStock,
            'discount' => $discount,
            'discounted_price' => $discountedPrice,
            'already_in_cart' => $alreadyInCart
        ];
    }

    public function addOrderDetailsInSession(object|array $order): array
    {
        // $productList = $this->getProductListWithAllDetails(ids: $order?->details?->pluck('product_id')->toArray());

        $productList = collect($order?->details?->pluck('productAllStatus'));

        $products = [];
        foreach ($order?->details as $details) {
            $product = json_decode($details->product_details, true);
            $activeProduct = $productList?->firstWhere('id', $details['product_id']) ?? $product;

            $currentStock = $activeProduct ? max(0, $activeProduct['current_stock']) : $product['current_stock'];
            $variations = is_array($activeProduct['variation']) ? $activeProduct['variation'] : json_decode($activeProduct['variation'], true);
            $firstVariation = collect($variations)->first(function ($variation) use ($details) {
                return $variation['type'] == $details['variant'];
            });

            $unitPrice = $activeProduct ? $activeProduct['unit_price'] : $details['price'];

            if ($details['variant'] && $firstVariation) {
                $currentStock = $firstVariation['qty'] ?? 0;
                $unitPrice = $firstVariation['price'] ?? 0;
            }

            if ($details) {
                $currentStock += $details['qty'] ?? 1;
            }

            $discount = $activeProduct ? getProductPriceByType(product: $activeProduct, type: 'discounted_amount', result: 'value', price: $details['price']) : (getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $details['price']) ?? 0);
            $discountedPrice = $activeProduct ? getProductPriceByType(product: $activeProduct, type: 'discounted_unit_price', result: 'value', price: $details['price']) : (getProductPriceByType(product: $product, type: 'discount', result: 'value', price: $details['price']) ?? 0);


            $checkActiveProduct = $productList?->firstWhere('id', $details['product_id']);
            $isQuantityEditable = (bool)($checkActiveProduct);

            if ($checkActiveProduct) {
                $detailsProductVariation = json_decode($product['variation'], true);
                $activeProductVariation = json_decode($checkActiveProduct['variation'] ?? '', true);
                if (count($activeProductVariation) > 0) {
                    $isQuantityEditable = collect($activeProductVariation)->filter(function ($item) use ($details) {
                        return $item['type'] == $details['variant'];
                    })->isNotEmpty();
                }

                if ((count($activeProductVariation) == 0 && count($detailsProductVariation) != 0) || (count($activeProductVariation) != 0 && count($detailsProductVariation) == 0)) {
                    $isQuantityEditable = false;
                }

                if ($details['variant'] && count($activeProductVariation) == 0) {
                    $isQuantityEditable = false;
                }
            }
            if ($product) {
                $products[$this->generateProductIndex(id: $details['product_id'], variant: $details['variant'] ?? '')] = [
                    'existing_product' => true,
                    'existing_product_modified' => true,
                    'product_type' => $product['product_type'] ?? 'physical',
                    'name' => $product['name'],
                    'discounted_price' => $discountedPrice,
                    'thumbnail_full_url' => $details?->product?->thumbnail_full_url ?? [],
                    'is_quantity_editable' => $isQuantityEditable,

                    'order_id' => $details['order_id'],
                    'product_id' => $details['product_id'],
                    'seller_id' => $details['seller_id'],
                    'seller_is' => $order['seller_is'],
                    'minimum_order_qty' => $product['minimum_order_qty'] ?? 1,
                    'digital_file_after_sell' => $details['digital_file_after_sell'],
                    'product_details' => $details['product_details'],
                    'active_product' => $activeProduct ?? $product,
                    'qty' => $details['qty'],
                    'price' => $unitPrice,
                    'tax' => $details['tax'],
                    'discount' => $discount,
                    'current_stock' => $currentStock,
                    'tax_model' => $details['tax_model'],
                    'delivery_status' => $details['delivery_status'],
                    'payment_status' => $details['payment_status'],
                    'shipping_method_id' => $details['shipping_method_id'],
                    'variant' => $details['variant'],
                    'variation' => $details['variation'],
                    'discount_type' => $activeProduct ? $activeProduct['discount_type'] : ($product['discount_type'] ?? ''),
                    'is_stock_decreased' => $details['is_stock_decreased'],
                    'refund_request' => $details['refund_request'],
                    'refund_started_at' => now(),
                    'created_at' => $details['created_at'],
                ];
            }
        }
        return $products;
    }

    public function addOrUpdateProductInOrderSession(object|array $request, object|array $order = [], object|array|null $color = []): array
    {
        $product = $this->getProductWithAllDetails(id: $request['product_id']);
        $orderSession = $this->getOrderEditSession(order: $order);
        $productList = $orderSession['product_list'] ?? [];
        $currentStock = $product['current_stock'];

        $status = 'success';
        $unitPrice = $product['unit_price'] ?? 0;
        $variation = $request['variant'];
        $variations = [];

        if ($color) {
            $variations['color'] = $color?->name;
        }

        foreach (json_decode($product->choice_options) as $choice) {
            $variations[$choice->title] = $request[$choice->name];
        }

        if ($variation != null) {
            $count = count(json_decode($product->variation));
            for ($i = 0; $i < $count; $i++) {
                if (json_decode($product->variation)[$i]->type == $variation) {
                    $unitPrice = json_decode($product->variation)[$i]->price;
                    $currentStock = json_decode($product->variation)[$i]->qty;
                }
            }
        } else {
            $unitPrice = $product['unit_price'];
        }

        if ($currentStock <= 0 || $currentStock < $product['minimum_order_qty']) {
            return [
                'status' => 'error',
                'message' => translate('Out of stock'),
            ];
        }

        $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $unitPrice);
        $discountedPrice = getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'value', price: $unitPrice);

        $existingItem = null;
        $existingIndex = null;
        foreach ($productList as $key => $item) {
            if ($item['product_id'] == $product['id'] && ($item['variant'] ?? '') === ($request['variant'] ?? '')) {
                $existingItem = $item;
                $existingIndex = $key;
                break;
            }
        }

        $searchProductInDetails = collect($order?->details)
            ->first(function ($item) use ($product, $request) {
                return $item['product_id'] == $product['id']
                    && (empty($request['variant']) || ($item['variant'] ?? '') == $request['variant']);
            });

        $taxConfig = self::getTaxSystemType();

        $productData = [
            'current_stock' => $currentStock,
            'existing_product' => (bool)$searchProductInDetails,
            'existing_product_modified' => (bool)$searchProductInDetails,
            'product_type' => $product['product_type'],
            'name' => $product['name'],
            'discounted_price' => $discountedPrice,
            'thumbnail_full_url' => $product['thumbnail_full_url'] ?? [],

            'order_id' => $request['order_id'],
            'product_id' => $product['id'],
            'seller_id' => $order['seller_id'],
            'seller_is' => $order['seller_is'],
            'minimum_order_qty' => $product['minimum_order_qty'] ?? 1,
            'digital_file_after_sell' => $searchProductInDetails['digital_file_after_sell'] ?? null,
            'product_details' => $product->makeHidden(['order_details', 'order_delivered', 'seo_info', 'reviews']),
            'active_product' => $product,
            'qty' => (int)($request['quantity'] ?? 1),
            'price' => $unitPrice,
            'tax' => 0,
            'discount' => $discount,
            'tax_model' => $taxConfig['is_included'] ? 'include' : 'exclude',
            'delivery_status' => 'pending',
            'payment_status' => 'unpaid',
            'shipping_method_id' => null,
            'variant' => $request['variant'] ?? '',
            'variation' => $searchProductInDetails ? $searchProductInDetails['variation'] : json_encode($variations),
            'discount_type' => $product['discount_type'],
            'refund_request' => 0,
            'refund_started_at' => now(),
            'created_at' => $searchProductInDetails ? $searchProductInDetails['created_at'] : Carbon::now(),
        ];

        if ($existingItem !== null) {
            $productData['existing_product'] = (bool)$searchProductInDetails;
            $productData['existing_product_modified'] = isset($existingItem['existing_product_modified']) ? $existingItem['existing_product_modified'] : false;
            $productList[$existingIndex] = $productData;
            $message = translate('product_update_successfully');
        } else {
            $productList[$this->generateProductIndex(id: $product['id'], variant: $request['variant'])] = $productData;
            $message = translate('product_added_successfully');
        }

        $orderSession['product_list'] = $productList;
        Session::put($this->getOrderEditSessionKey(orderId: $request['order_id']), $orderSession);

        return [
            'status' => $status,
            'message' => $message,
        ];
    }

    public function removeProductInOrderSession(object|array $request, object|array $order = []): array
    {
        $orderSession = $this->getOrderEditSession(order: $order);
        $productList = $orderSession['product_list'] ?? [];
        $filteredProducts = [];

        if (count($productList) <= 1) {
            return [
                'status' => 'error',
                'message' => translate('Cannot remove all product from list'),
            ];
        }

        foreach ($productList as $key => $productListItem) {
            if (!($productListItem['product_id'] == $request['product_id'] && $productListItem['variant'] == ($request['variant'] ?? ''))) {
                $filteredProducts[$key] = $productListItem;
            }
        }

        $orderSession['product_list'] = $filteredProducts;
        Session::put($this->getOrderEditSessionKey(orderId: $order['id']), $orderSession);

        return [
            'status' => 'success',
            'message' => translate('product_remove_successfully'),
        ];
    }


    public function updateProductListInOrderSession(object|array $request, object|array $order = []): array
    {
        $orderSession = $this->getOrderEditSession(order: $order);
        $productList = $orderSession['product_list'] ?? [];
        $requestProducts = $request['products'] ?? [];
        $filteredProducts = [];

        foreach ($productList as $key => $productListItem) {
            foreach ($requestProducts as $requestKey => $requestItem) {
                if ($requestKey == $key) {
                    $productListItem['qty'] = $requestItem['qty'];
                    $filteredProducts[$key] = $productListItem;
                }
            }
        }

        $orderSession['product_list'] = $filteredProducts;
        Session::put($this->getOrderEditSessionKey(orderId: $order['id']), $orderSession);

        return [
            'status' => 'success',
            'message' => translate('product_update_successfully'),
        ];
    }

    private function generateProductIndex(mixed $id, ?string $variant = null): string
    {
        return $id . '-' . (!empty($variant) ? $variant : 'single-variant');
    }

    public function checkIsOrderEditable(object|array $order, string|null $type = 'admin'): array
    {
        if (($order->order_type != 'default_type') || (!in_array($order->order_status, ['pending', 'confirmed']) && in_array($order->order_status, ['processing', 'out_for_delivery', 'delivered', 'returned', 'failed', 'canceled']))) {
            return [
                'status' => false,
                'message' => translate('Order can only be edited when the order status is Pending or Confirmed.'),
            ];
        }

        $physicalProductExist = false;
        foreach ($order?->details?->pluck('product_details') as $productDetail) {
            $product = json_decode($productDetail ?? '', true);
            if ($product && (!isset($product['product_type']) || $product['product_type'] == 'physical')) {
                $physicalProductExist = true;
            }
        }

        if (!$physicalProductExist) {
            return [
                'status' => false,
                'message' => translate('Orders containing only digital products cannot be edited.'),
            ];
        }

        if ($type == 'vendor' && getWebConfig('vendor_can_edit_order') != 1) {
            return [
                'status' => false,
                'message' => translate('Vendors are not allowed to edit orders now.'),
            ];
        }

        if ($order['payment_method'] == 'offline_payment' && $order['payment_status'] == 'unpaid') {
            return [
                'status' => false,
                'message' => translate('Please confirm the offline payment information before editing this order.'),
            ];
        }
        if($order->edited_status == 1 && $order?->latestEditHistory?->order_due_payment_method == 'offline_payment' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid'){
            return [
                'status' => false,
                'message' => translate('Please confirm the offline payment information of the due amount before editing this order.'),
            ];
        }

        return [
            'status' => true,
            'message' => translate('Order is editable'),
        ];
    }


    public function getFormatAPIProductsForEditOrder(object|array $request, object|array $order = [], object|array|null $colors = []): array
    {
        $requestProducts = isset($request['products']) && is_array($request['products']) ? $request['products'] : json_decode($request['products'] ?? '', true);
        $products = $this->getProductListWithAllDetails(ids: collect($requestProducts)->pluck('id')->toArray());

        $productList = [];
        $status = 'success';
        $errors = [];

        foreach ($requestProducts as $productItem) {
            $product = $products->firstWhere('id', $productItem['id']);
            $unitPrice = $product['unit_price'] ?? 0;
            $currentStock = $product['current_stock'] ?? 0;

            $variation = $productItem['variant'];
            $variations = [];

            if ($productItem['color']) {
                $variations['color'] = $colors->firstWhere('code', $productItem['color'])?->name ?? null;
            }

            foreach (json_decode($product->choice_options) as $choice) {
                $requestChoice = $productItem['choice'] ?? [];
                foreach ($requestChoice as $choiceItem) {
                    if ($choice?->name == $choiceItem['key']) {
                        $variations[$choice->title] = $choiceItem['value'];
                    }
                }
            }

            if ($variation != null) {
                $count = count(json_decode($product->variation));
                for ($i = 0; $i < $count; $i++) {
                    if (json_decode($product->variation)[$i]->type == $variation) {
                        $unitPrice = json_decode($product->variation)[$i]->price;
                        $currentStock = json_decode($product->variation)[$i]->qty;
                    }
                }
            } else {
                $unitPrice = $product['unit_price'];
            }

            $checkDetails = collect($order?->details)?->where('product_id', $product['id'])->firstWhere('variant', $productItem['variant']);
            if ($checkDetails) {
                $currentStock += $checkDetails['qty'] ?? 1;
            }

            if ($currentStock <= 0 || $currentStock < $product['minimum_order_qty']) {
                $errors[] = [translate('Out of stock')];
                return [
                    'status' => 'error',
                    'message' => translate('Order_Edit_Failed'),
                    'errors' => $errors,
                    'product_list' => $productList,
                ];
            }

            $discount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $unitPrice);
            $discountedPrice = getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'value', price: $unitPrice);

            $searchProductInDetails = collect($order?->details)
                ->first(function ($item) use ($product, $productItem) {
                    return $item['product_id'] == $product['id']
                        && (empty($productItem['variant']) || ($item['variant'] ?? '') == $productItem['variant']);
                });

            $taxConfig = self::getTaxSystemType();

            $productList[$this->generateProductIndex(id: $product['id'], variant: $productItem['variant'])] = [
                'current_stock' => $currentStock,
                'existing_product' => (bool)$searchProductInDetails,
                'existing_product_modified' => (bool)$searchProductInDetails,
                'product_type' => $product['product_type'],
                'name' => $product['name'],
                'discounted_price' => $discountedPrice,
                'thumbnail_full_url' => $product['thumbnail_full_url'] ?? [],

                'order_id' => $order['order_id'],
                'product_id' => $product['id'],
                'seller_id' => $order['seller_id'],
                'seller_is' => $order['seller_is'],
                'digital_file_after_sell' => $searchProductInDetails['digital_file_after_sell'] ?? null,
                'product_details' => $product->makeHidden(['order_details', 'order_delivered', 'seo_info', 'reviews']),
                'active_product' => $product,
                'qty' => $productItem['quantity'] ?? 1,
                'price' => $unitPrice,
                'tax' => 0,
                'discount' => $discount,
                'tax_model' => $taxConfig['is_included'] ? 'include' : 'exclude',
                'delivery_status' => 'pending',
                'payment_status' => 'unpaid',
                'shipping_method_id' => null,
                'variant' => $productItem['variant'] ?? '',
                'variation' => $searchProductInDetails ? $searchProductInDetails['variation'] : $variations,
                'discount_type' => $product['discount_type'],
                'refund_request' => 0,
                'refund_started_at' => now(),
                'created_at' => $searchProductInDetails ? $searchProductInDetails['created_at'] : Carbon::now(),
            ];
        }

        return [
            'status' => $status,
            'message' => translate('Order_Edit_successfully'),
            'errors' => $errors,
            'product_list' => $productList,
        ];
    }
}
