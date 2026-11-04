<audio id="myAudio">
    <source src="{{ dynamicAsset(path: 'public/assets/backend/sound/notification.mp3') }}" type="audio/mpeg">
</audio>

<span class="please_fill_out_this_field" data-text="{{ translate('please_fill_out_this_field') }}"></span>
<span id="onerror-chatting"
      data-onerror-chatting="{{dynamicAsset(path: 'public/assets/back-end/img/image-place-holder.png')}}"></span>
<span id="onerror-user"
      data-onerror-user="{{dynamicAsset(path: 'public/assets/back-end/img/160x160/img1.jpg')}}"></span>
<span id="get-root-path-for-toggle-modal-image"
      data-path="{{dynamicAsset(path: 'public/assets/back-end/img/modal')}}"></span>
<span id="get-customer-list-route" data-action="{{route('vendor.customer.list')}}"></span>
<span id="get-search-product-route" data-action="{{route('vendor.products.search-product')}}"></span>
<span id="get-orders-list-route" data-action="{{route('vendor.orders.list', ['status' => 'all'])}}"></span>
<span class="system-default-country-code" data-value="{{ getWebConfig(name: 'country_code') ?? 'us' }}"></span>
<span id="message-select-word" data-text="{{ translate('select') }}"></span>
<span id="message-yes-word" data-text="{{ translate('yes') }}"></span>
<span id="message-no-word" data-text="{{ translate('no') }}"></span>
<span id="message-cancel-word" data-text="{{ translate('cancel') }}"></span>
<span id="message-are-you-sure" data-text="{{ translate('are_you_sure') }} ?"></span>
<span id="message-invalid-date-range" data-text="{{ translate('invalid_date_range') }}"></span>
<span id="message-status-change-successfully" data-text="{{ translate('status_change_successfully') }}"></span>
<span id="message-are-you-sure-delete-this" data-text="{{ translate('are_you_sure_to_delete_this') }} ?"></span>
<span id="message-you-will-not-be-able-to-revert-this"
      data-text="{{ translate('you_will_not_be_able_to_revert_this') }}"></span>
<span id="exceeds10MBSizeLimit" data-text="{{ translate('File_exceeds_10MB_size_limit') }}"></span>
<span id="getChattingNewNotificationCheckRoute" data-route="{{ route('vendor.messages.new-notification') }}"></span>
<span id="get-search-vendor-product-for-clearance-route"
      data-action="{{route('vendor.clearance-sale.search-product-for-clearance')}}"></span>
<span id="get-multiple-clearance-product-details-route"
      data-action="{{route('vendor.clearance-sale.multiple-clearance-product-details')}}"></span>

<span id="get-stock-limit-status" data-action="{{route('vendor.products.stock-limit-status')}}"></span>
<span id="get-product-stock-limit-title" data-title="{{translate('warning')}}"></span>
<span id="get-product-stock-limit-image"
      data-warning-image="{{ dynamicAsset(path: 'public/assets/back-end/img/warning-2.png') }}"></span>
<span id="get-product-stock-limit-message"
      data-message-for-multiple="{{ translate('there_is_not_enough_quantity_on_stock').' . '.translate('please_check_products_in_limited_stock').'.' }}"
      data-message-for-three-plus-product="{{translate('_more_products_have_low_stock') }}"
      data-message-for-one-product="{{translate('this_product_is_low_on_stock')}}">
        </span>
<span id="get-product-stock-view"
      data-stock-limit-page="{{route('vendor.products.stock-limit-list')}}"
>
        </span>
<span id="route-for-real-time-activities" data-route="{{ route('vendor.dashboard.real-time-activities') }}"></span>
<span id="get-confirm-and-cancel-button-text-for-delete-all-products" data-sure="{{translate('are_you_sure').'?'}}"
      data-text="{{translate('want_to_clear_all_stock_clearance_products?').'!'}}"
      data-confirm="{{translate('Yes, Delete it')}}" data-cancel="{{translate('cancel')}}"></span>

<span id="get-currency-symbol"
      data-currency-symbol="{{ getCurrencySymbol(currencyCode: getCurrencyCode(type: 'default')) }}"></span>

<span id="check-offcanvas-setup-guide" data-value="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' }}"></span>
<span id="get-initial-data-for-panel-time" data-value="{!! in_array(request()->ip(), ['127.0.0.1', '::1']) ? '15000' : '15000' !!}"></span>
