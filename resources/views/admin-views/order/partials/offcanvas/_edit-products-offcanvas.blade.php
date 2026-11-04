<form class="update-order-product-form"
      action="{{ route('admin.orders.edit-order-generate') }}"
      data-update="{{ route('admin.orders.edit-order-product-list-update') }}"
      method="POST">
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasEditProducts"
        aria-labelledby="offcanvasEditProductsLabel" style="--bs-offcanvas-width: 750px;">
        <div class="offcanvas-header border d-block">
            <div class="d-flex justify-content-between align-items-center gap-2 w-100 mb-2">
                <h3 class="fw-bold fs-18 mb-0">{{ translate('Edit_Products') }}</h3>
                <button type="button" class="btn btn-circle border-0 fs-12 text-dark bg-section2 shadow-none"
                        data-bs-dismiss="offcanvas" aria-label="Close">
                    <i class="fi fi-rr-cross d-flex"></i>
                </button>
            </div>
            <div class="d-flex align-items-center gap-4 flex-wrap">
                <div class="d-flex gap-1 align-items-center">
                    <h4 class="mb-0">{{ translate('Order') }} #{{ $order['id'] }}</h4>
                    @if($order['order_status'] == 'pending')
                        <span class="badge text-bg-info badge-info">
                            {{ translate(str_replace('_', ' ', $order['order_status'])) }}
                        </span>
                    @elseif($order['order_status'] == 'failed')
                        <span class="badge text-bg-danger badge-danger">
                            {{ translate(str_replace('_', ' ', $order['order_status'] == 'failed' ? 'Failed to Deliver' : '')) }}
                        </span>
                    @elseif($order['order_status'] == 'processing' || $order['order_status'] == 'out_for_delivery')
                        <span class="badge text-bg-warning badge-warning">
                            {{ translate(str_replace('_', ' ', $order['order_status'] == 'processing' ? 'Packaging' : $order['order_status'])) }}
                        </span>
                    @elseif($order['order_status'] == 'delivered' || $order['order_status'] == 'confirmed')
                        <span class="badge text-bg-success badge-success">
                            {{ translate(str_replace('_', ' ', $order['order_status'])) }}
                        </span>
                    @else
                        <span class="badge text-bg-danger badge-danger">
                            {{ translate(str_replace('_', ' ', $order['order_status'])) }}
                        </span>
                    @endif
                </div>
                <div>
                    <h4 class="mb-0">
                        <span class="fw-normal">{{ translate('Order_Placed') }} : </span>
                        <span>{{ date('d M Y , h:i A', strtotime($order['created_at'])) }}</span>
                    </h4>
                </div>
            </div>
        </div>
        <div class="offcanvas-body">
            <div>
                <div class="dropdown select-order-edit-product-search w-100 mb-20">
                    <div class="search-form" id="customSearchToggle">
                        <input type="text" id="searchInput" class="form-control ps-5 search-product-for-order-edit"
                               placeholder="{{ translate('search_by_product_name_or_bar_code_and_click_or_press_enter_to_add') }}">
                        <span
                            class="fi fi-rr-search position-absolute inset-inline-start-0 top-0 h-40 d-flex align-items-center ps-3"></span>
                    </div>
                    <div class="dropdown-menu w-100 px-2">
                        <div class="d-flex flex-column max-h-300 overflow-y-auto overflow-x-hidden child-border-bottom search-result-box">
                            @include('admin-views.order.partials._search-product', ['products' => $allProductsList, 'orderId' => $order['id']])
                        </div>
                    </div>
                </div>

                <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-20">
                    <i class="fi fi-sr-lightbulb-on text-info"></i>
                    <span>
                        {{ translate('after_editing,_the_price_will_be_updated_to_the_latest_product_price_.'). translate('to_keep_the_previous_price,_click_on_cancel._otherwise_billing_will_be_calculated_based_on_latest_price') }}
                    </span>
                </div>
            </div>

            <div id="edit-order-products-list">
                @include("admin-views.order.partials.offcanvas._edit-order-products-list", ['order' => $order])
            </div>
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap bg-white px-4 py-3">
                <div class="text-dark">
                    {{ translate('Total Amount') }} :
                    <span class="fw-bold edit-order-total-amount">
                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $editOrderSummary['order_amount']), currencyCode: getCurrencyCode()) }}
                    </span>
                    ({{ translate('Vat/Tax & Others') }})
                </div>
                <div class="d-flex justify-content-end align-items-center gap-3 bg-white">
                    <button type="reset" class="btn btn-secondary min-w-120" data-bs-dismiss="offcanvas">{{ translate('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary min-w-120 update-cart-btn" data-loading-text="{{ translate('Processing') }}">
                        <span>{{ translate('Update_Cart') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
