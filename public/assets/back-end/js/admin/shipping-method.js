'use strict';

function shippingType(shippingTypeValue){
    if (shippingTypeValue === 'category_wise') {
        $('#product_wise_note').hide();
        $('#order_wise_shipping').hide();
        $('#update_category_shipping_cost').show();

    } else if (shippingTypeValue === 'order_wise') {
        $('#product_wise_note').hide();
        $('#update_category_shipping_cost').hide();
        $('#order_wise_shipping').show();
    } else {
        $('#update_category_shipping_cost').hide();
        $('#order_wise_shipping').hide();
        $('#product_wise_note').show();
    }
}