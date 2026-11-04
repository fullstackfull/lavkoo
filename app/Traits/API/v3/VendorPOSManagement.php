<?php

namespace App\Traits\API\v3;

trait VendorPOSManagement
{
    public function getOrderDetailsAddData($cartItem, $product): array
    {
        $variant = $cartItem['variant'];
        $unitPrice = $product['unit_price'];
        $price = $product['unit_price'];
        $productDiscount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $product['unit_price'], from: 'panel');
        $productSubtotal = ($product['unit_price']) * $cartItem['quantity'];

        if ($cartItem['variant'] != null) {
            foreach (json_decode($product['variation'], true) as $variation) {
                if ($cartItem['variant'] == $variation['type']) {
                    $unitPrice = $variation['price'];
                    $price = $variation['price'];
                    $productDiscount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $variation['price'], from: 'panel');
                    $productSubtotal = $variation['price'] * $cartItem['quantity'];
                }
            }
        }

        if ($product['product_type'] == 'digital' && $product['digital_product_type'] == 'ready_product' && !empty($product['digital_file_ready']) && !isset($cartItem['variant_key'])) {
            $product['storage_path'] = $product['digital_file_ready_storage_type'] ?? 'public';
        }

        if ($product['product_type'] == 'digital' && isset($cartItem['variant_key']) && !empty($cartItem['variant_key'])) {
            foreach ($product['digitalVariation'] as $digitalVariation) {
                if ($digitalVariation['variant_key'] == $cartItem['variant_key']) {
                    $digitalProductVariation = $this->digitalProductVariationRepo->getFirstWhere(
                        params: ['product_id' => $cartItem['id'], 'variant_key' => $cartItem['variant_key']],
                        relations: ['storage']
                    );
                    if ($product['digital_product_type'] == 'ready_product' && $digitalProductVariation) {
                        $getStoragePath = $this->storageRepo->getFirstWhere(params: [
                            'data_id' => $digitalProductVariation['id'],
                            "data_type" => "App\Models\DigitalProductVariation",
                        ]);

                        $product['digital_file_ready'] = $digitalProductVariation['file'];
                        $product['storage_path'] = $getStoragePath ? $getStoragePath['value'] : 'public';
                    }

                    $variant = $digitalVariation['variant_key'];
                    $unitPrice = $digitalVariation['price'];
                    $price = $digitalVariation['price'];
                    $productDiscount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $digitalVariation['price'], from: 'panel');
                    $productSubtotal = $digitalVariation['price'] * $cartItem['quantity'];
                }
            }
        }

        $product['unit_price_amount'] = $unitPrice;
        unset($product['order_details']);
        unset($product['order_delivered']);
        unset($product['seo_info']);
        unset($product['reviews']);
        return [
            'tax' => 0,
            'price' => $price,
            'variant' => $variant,
            'product' => $product,
            'productDiscount' => $productDiscount,
            'productSubtotal' => $productSubtotal,
        ];
    }
}
