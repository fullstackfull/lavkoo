<h3 class="mb-3">
    {{ translate('Products_List') }}
    <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">
        {{ count($orderProductsSession['product_list'] ?? []) }}
    </span>
</h3>

<input type="hidden" name="order_id" value="{{ $order['id'] }}">
<div class="k-table-wrap border rounded-10">
    <table class="k-table">
        <thead>
        <tr>
            <th>{{ translate('SL') }}</th>
            <th>{{ translate('Item_List') }}</th>
            <th class="text-center">{{ translate('Qty') }}</th>
            <th class="text-end">{{ translate('Total') }}</th>
            <th class="text-center">{{ translate('Action') }}</th>
        </tr>
        </thead>
        <tbody>
        @php($orderProductsSessionIndex=1)
        @foreach($orderProductsSession['product_list'] as $key => $detail)
            <tr
                @class([
                    'table--primary' => $detail['existing_product'] ?? false,
                    'opacity-50' => $detail['product_type'] === 'digital',
                ])
                @if($detail['product_type'] === 'digital')
                    data-bs-toggle="tooltip"
                    data-bs-title="{{ translate('Digital_Product_is_not_editable') }}"
                @endif
            >

                <td>{{ $orderProductsSessionIndex++ }}</td>
                <td>
                    <div class="media align-items-center gap-2">
                        <img class="avatar avatar-50 rounded img-fit"
                             src="{{ getStorageImages(path: $detail['thumbnail_full_url'], type: 'backend-product') }}"
                             alt="{{translate('image_Description')}}">
                        <div class="media-body d-flex flex-column gap-1 fs-12">
                            <div class="max-w-200 line-1">
                                {{ Str::limit($detail['name'] ?? "", 30) }}
                            </div>
                            <div class="d-flex gap-2 align-items-center lh-1">
                                <span class="text-body">{{ translate('Unit_Price') }} : </span>
                                <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $detail['price'])) }}</span>
                            </div>
                            @if($detail['discount'] > 0)
                                <div class="d-flex gap-2 align-items-center lh-1">
                                    <span class="text-body">{{ translate('Discount') }} : </span>
                                    <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $detail['discount'])) }}</span>
                                </div>
                            @endif
                            @if($detail['variant'])
                                <div class="d-flex gap-2 align-items-center lh-1">
                                    <span class="text-body">{{ translate('variation') }} : </span>
                                    <span>{{ $detail['variant'] }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </td>
                <td>
                    <?php
                        $minQty = $detail['minimum_order_qty'] ?? 1;
                        $maxQty = $detail['current_stock'] ?? 100;
                        $reqQty = $detail['qty'] ?? $minQty;

                        if ($reqQty < $minQty) {
                            $finalQty = $minQty;
                        } elseif ($reqQty > $maxQty) {
                            $finalQty = $maxQty < $minQty ? $minQty : $maxQty;
                        } else {
                            $finalQty = $reqQty;
                        }
                    ?>

                    <div class="qty-input-group-design qty-input-group-js product-quantity-group form-control w-max-content d-flex gap-2 align-items-center {{ isset($detail['is_quantity_editable']) && $detail['is_quantity_editable'] === false ? 'opacity-50' : '' }}"
                    @if(isset($detail['is_quantity_editable']) && $detail['is_quantity_editable'] === false)
                         data-bs-toggle="tooltip"
                         data-bs-title="{{ translate('The quantity cannot be updated because the product has been modified') }}"
                    @endif
                    >
                        <button type="button" class="qty-count"
                                data-action="minus"
                            {{ $finalQty <= $minQty ? 'disabled' : '' }}
                            {{ $detail['product_type'] == 'digital' ? 'disabled' : '' }}
                            {{ isset($detail['is_quantity_editable']) && $detail['is_quantity_editable'] === false ? 'disabled' : '' }}
                        >-
                        </button>
                        <input class="product-qty text-center" type="number"
                               name="products[{{ $key }}][qty]"
                               value="{{ $finalQty }}"
                               min="{{ $minQty }}"
                               max="{{ $maxQty }}"
                            {{ $detail['product_type'] == 'digital' ? 'readonly' : '' }}
                            {{ isset($detail['is_quantity_editable']) && $detail['is_quantity_editable'] === false ? 'readonly' : '' }}
                        >
                        <button class="qty-count" data-action="plus" type="button"
                                {{ $detail['product_type'] == 'digital' ? 'disabled' : '' }}
                                {{ isset($detail['is_quantity_editable']) && $detail['is_quantity_editable'] === false ? 'disabled' : '' }}
                                {{ $finalQty >= $maxQty ? 'disabled' : '' }}>+
                        </button>
                    </div>
                </td>
                <td class="text-end">
                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($detail['price'] - $detail['discount']) * $detail['qty']), currencyCode: getCurrencyCode()) }}
                </td>
                <td>
                    <div class="d-flex justify-content-center gap-3">
                        @if($detail['product_type'] != 'digital')
                            <button class="btn btn-danger rounded-circle icon-btn edit-order-product-remove-js"
                                    data-route="{{ route('admin.orders.edit-order-product-remove', [
                                    'order_id' => $detail['order_id'],
                                    'product_id' => $detail['product_id'],
                                    'variant' => $detail['variant'] ?? ''
                                ]) }}">
                                <i class="fi fi-rr-trash"></i>
                            </button>
                        @else
                            -
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
