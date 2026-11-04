<?php

namespace App\Services;

class RestockProductService
{

    public function getProductRestockRequestAddData(object|array $request, object|array $restockRequest): array
    {
        return [
            'restock_product_id' => $restockRequest ? $restockRequest['id'] : 0,
            'customer_id' => auth('customer')->id(),
            'variant' => $request['product_variation_code'],
        ];
    }

}
