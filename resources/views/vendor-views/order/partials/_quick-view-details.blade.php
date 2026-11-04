<div>
    <form class="order-edit-add-to-cart-form" method="POST"
          action="{{ route('vendor.orders.edit-order-product-add') }}"
          data-check-variant-price="{{ route('vendor.orders.edit-order-product-variant-price') }}">
        @csrf
        <input type="hidden" name="order_id" value="{{ $orderId }}">
        <input type="hidden" name="product_id" value="{{ $product['id'] }}">
        <input type="hidden" name="variant" value="{{ $currentVariation['variant'] }}">

        <div class="overflow-y-auto max-h-300 pb-2">
            <div class="details">
                <div class="d-flex flex-wrap gap-3 align-items-center mb-4">
                    @if($product->product_type === 'physical')
                        <div class="d-flex gap-2 align-items-center">
                            {{ translate('Stock') }} :
                            <span class="text-dark fw-semibold current-stock-qty">
                                {{ $currentVariation['current_stock'] }}
                            </span>
                        </div>
                        <div
                            class="d-flex gap-2 align-items-center rounded-pill px-2 py-1 bg-opacity-10 stock-status-in-quick-view {{ $currentVariation['current_stock'] > 0 ? 'bg-success text-success' : 'bg-danger text-danger' }}">
                            <i class="tio-checkmark-circle-outlined"></i>
                            {{  $currentVariation['current_stock'] > 0 ? translate('In_Stock') : translate('out_of_stock.') }}
                        </div>
                    @endif
                    @if (getProductPriceByType(product: $product, type: 'discount', result: 'value') > 0)
                        <div
                            class="d-flex gap-1 align-items-center rounded-pill px-2 py-1 bg-primary text-primary bg-opacity-10">
                            <span class="fs-12">
                                   -{{ getProductPriceByType(product: $product, type: 'discount', result: 'string') }}
                                </span>
                        </div>
                    @endif
                </div>
                <h2 class="mb-3 product-title fs-20">{{ $product->name }}</h2>

                @if ($product->reviews_count > 0)
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class="fi fi-sr-star text-warning"></i>
                        <span
                            class="text-muted text-capitalize">({{ $product->reviews_count . ' ' . translate('customer_review') }})</span>
                    </div>
                @endif

                <div class="d-flex flex-wrap align-items-center gap-3 mb-2 text-dark">
                    <h2
                        class="text--primary text-accent d-flex gap-2 align-items-center mb-0">
                        @if ($currentVariation['discount'] > 0)
                            <del
                                class="product-total-unit-price align-middle text-ADB0B7 fs-20 fw-semibold">
                                {{ webCurrencyConverter(amount: $currentVariation['current_price']) }}
                            </del>
                        @endif
                        <span class="fs-30 fw-semibold">
                            {{ webCurrencyConverter(amount: $currentVariation['discounted_price']) }}
                        </span>
                    </h2>
                </div>
            </div>

            <input type="hidden" name="id" value="{{ $product->id }}">
            <div class="variant-change">
                <div class="position-relative mb-4">
                    @if (count(json_decode($product->colors)) > 0)
                        <div class="d-flex flex-wrap gap-3 align-items-center">
                            <span class="text-9B9B9B">{{ translate('color') }}</span>
                            <div class="color-select d-flex gap-2 flex-wrap" id="option1">
                                @foreach (json_decode($product->colors) as $key => $color)
                                    <input class="btn-check action-color-change" type="radio"
                                           id="{{ $product->id }}-color-{{ $key }}" name="color"
                                           value="{{ $color }}"
                                           data-color="{{ $color }}"
                                           @if (isset($variantRequest['color']) && $variantRequest['color'] == $color)
                                               checked
                                           @elseif (!isset($variantRequest['color']) && $key == 0)
                                               checked
                                           @endif autocomplete="off">
                                    <label id="label-{{ $product->id }}-color-{{ $key }}"
                                           @if (isset($variantRequest['color']) && $variantRequest['color'] == $color)
                                               class="color-ball mb-0 border-add"
                                           @elseif (!isset($variantRequest['color']) && $key == 0)
                                               class="color-ball mb-0 border-add"
                                           @else
                                               class="color-ball mb-0"
                                           @endif
                                           style="background: {{ $color }};"
                                           for="{{ $product->id }}-color-{{ $key }}">
                                        <i class="tio-done"></i>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
                @foreach (json_decode($product->choice_options) as $key => $choice)
                    <div class="d-flex gap-3 align-items-center mb-3">
                        <div class="my-2 w-43px">
                            <span class="text-9B9B9B">{{ ucfirst($choice->title) }}</span>
                        </div>
                        <div class="d-flex gap-3 flex-wrap px-1">
                            @foreach ($choice->options as $index => $option)
                                <input class="btn-check" type="radio"
                                       id="{{ $choice->name }}-{{ $option }}" name="{{ $choice->name }}"
                                       value="{{ $option }}"
                                       @if (isset($variantRequest[$choice->name]) && $variantRequest[$choice->name] == $option)
                                           checked
                                       @elseif (!isset($variantRequest[$choice->name]) && $index == 0)
                                           checked
                                       @endif
                                       autocomplete="off">
                                <label class="btn fs-12 check-label border bg-transparent mb-0 w-fit-content min-w-max-content text-transform-none h-30 rounded-10 px-2 py-1 pos-check-label"
                                    for="{{ $choice->name }}-{{ $option }}">
                                    <span class="text-nowrap max-w-180 line-1">{{ $option }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @php($extensionIndex = 0)
                @if (
                    $product['product_type'] == 'digital' &&
                        $product['digital_product_file_types'] &&
                        count($product['digital_product_file_types']) > 0 &&
                        $product['digital_product_extensions']
                )
                    @foreach ($product['digital_product_extensions'] as $extensionKey => $extensionGroup)
                        <div class="d-flex gap-3 align-items-center mb-3">
                            <div class="my-2">
                                <span class="text-9B9B9B">{{ translate($extensionKey) }}</span>
                            </div>

                            @if (count($extensionGroup) > 0)
                                <div class="d-flex gap-2 flex-wrap">
                                    @foreach ($extensionGroup as $index => $extension)
                                        <input class="btn-check" type="radio"
                                               id="extension_{{ str_replace(' ', '-', $extension) }}"
                                               name="variant_key"
                                               value="{{ $extensionKey . '-' . preg_replace('/\s+/', '-', $extension) }}"
                                               @if (isset($variantRequest['variant_key']) && $variantRequest['variant_key'] == $extensionKey . '-' . preg_replace('/\s+/', '-', $extension))
                                                   checked
                                               @elseif (!isset($variantRequest['variant_key']) && $extensionIndex == 0)
                                                   checked
                                               @endif
                                               autocomplete="off">
                                        <label
                                            class="btn btn-sm check-label border bg-transparent mb-0 w-fit-content h-30 rounded-10 px-2 py-1 pos-check-label"
                                            for="extension_{{ str_replace(' ', '-', $extension) }}">
                                            {{ $extension }}
                                        </label>
                                        @php($extensionIndex++)
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-3 position-relative price-section mt-1">
            <div class="alert alert--message flex-row alert-dismissible fade show pos-alert-message gap-2 d-none"
                 role="alert">
                <img class="mb-1"
                     src="{{ dynamicAsset(path: 'public/assets/back-end/img/warning-icon.png') }}"
                     alt="{{ translate('warning') }}">
                <div class="w-0">
                    <h6>{{ translate('warning') }}</h6>
                    <div class="product-stock-message"></div>
                </div>
                <a href="javascript:" class="align-items-center close-alert-message">
                    <i class="tio-clear"></i>
                </a>
            </div>
            <div class="default-quantity-system">
                <div class="d-flex gap-3 align-items-center">
                    <span class="text-9B9B9B">{{ translate('qty') }}</span>
                    <div class="product-quantity d-flex align-items-center">
                        <div class="d-flex align-items-center">
                            <?php
                            $minQty = $product['minimum_order_qty'] ?? 1;
                            $maxQty = $currentVariation['current_stock'];
                            $reqQty = $variantRequest['quantity'] ?? $minQty;

                            if ($reqQty < $minQty) {
                                $finalQty = $minQty;
                            } elseif ($reqQty > $maxQty) {
                                $finalQty = $maxQty < $minQty ? $minQty : $maxQty;
                            } else {
                                $finalQty = $reqQty;
                            }
                            ?>
                            <span class="product-quantity-group input group">
                                <button type="button"
                                        class="btn-number bg-transparent border-0 shadow-none"
                                        data-type="minus" data-field="quantity" {{ $finalQty <= $minQty ? 'disabled' : '' }}>
                                    <i class="fi fi-sr-minus fs-10"></i>
                                </button>
                                <input type="text" name="quantity"
                                       class="form-control input-number text-center cart-qty-field border-0 shadow-none"
                                       placeholder="1"
                                       value="{{ $finalQty }}"
                                       min="{{ $minQty }}"
                                       max="{{ $maxQty }}">
                                <button type="button"
                                        class="btn-number bg-transparent cart-qty-field-plus border-0 shadow-none"
                                        data-type="plus" data-field="quantity"
                                       {{ $finalQty >= $maxQty ? 'disabled' : '' }}>
                                    <i class="fi fi-sr-plus fs-10"></i>
                                </button>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-1 title-color">
                <div class="product-description-label text-dark fw-bold fs-12">
                    {{ translate('total_Price') }}:
                </div>
                <div class="product-price text-primary">
                    <strong class="product-details-chosen-price-amount fs-16">
                        {{ webCurrencyConverter(amount: $currentVariation['discounted_price'] * ($variantRequest['quantity'] ?? ($product['minimum_order_qty'] ?? 1))) }}
                    </strong>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-center mt-4">
            @if($product['product_type'] == 'physical')
                <button class="btn btn--primary btn-block" type="submit">
                    <i class="fi fi-sr-shopping-cart"></i>
                    <span class="submit-button-text">
                        @if(($currentVariation['has_variations'] && $currentVariation['stock_out_status']) || ($currentVariation['current_stock'] < 1) || ($currentVariation['current_stock'] < $product['minimum_order_qty']))
                            {{  translate('Out_of_Stock') }}
                        @elseif($currentVariation['already_in_cart'])
                            {{ translate("Update_to_cart") }}
                        @else
                            {{ translate("Add_to_cart") }}
                        @endif
                    </span>
                </button>
            @else
                <div>
                    <div
                        class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                        <i class="fi fi-sr-info text-warning"></i>
                        <span>
                            {{ translate('Digital products cannot be added or edited when modifying an existing order.') }}
                        </span>
                    </div>
                </div>
            @endif
        </div>
    </form>
</div>
