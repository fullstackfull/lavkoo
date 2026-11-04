<?php

namespace App\Services;

class ShippingTypeService
{
    /**
     * @param object $request
     * @param string|int $id
     * @return array
     */
    public function getShippingTypeDataForAdd(object $request, string|int $id ) : array
    {
        return [
            'seller_id' => $id,
            'shipping_type' => $request['shippingType'],
        ];
    }

    /**
     * @param object $request
     * @return array
     */
    public function getShippingTypeDataForUpdate(object $request) : array
    {
        return [
            'shipping_type' => $request['shippingType'],
        ];
    }
}
