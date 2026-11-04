@php
    use Carbon\Carbon;
    use Illuminate\Support\Facades\Session;
@endphp
@extends('layouts.admin.app')

@section('title', translate('flash_Deal'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/flash_deal.png') }}" alt="">
                {{ translate('flash_deals') }}
            </h2>
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <button class="btn btn-secondary min-w-200" data-bs-toggle="modal" data-bs-target="#prioritySetModal">
                <span data-bs-toggle="tooltip"
                      data-bs-title="Now you can set priority of products.">{{ translate('Product_Priority_Setup') }}</span>
                </button>
                <a class="btn btn-primary min-w-200" href="{{ route('admin.deal.flash-add') }}">
                    + {{ translate('Create_Flash_Deals') }}
                </a>
            </div>
        </div>

        <div class="row mt-20">
            <div class="col-md-12">
                <x-k.data-view :title="translate('flash_deal_table')" :count="$flashDeals->total()"
                               searchName="searchValue" :searchValue="requestString('searchValue')"
                               :searchPlaceholder="translate('search_by_Title')">

                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('title') }}</th>
                            <th>{{ translate('duration') }}</th>
                            <th class="k-table__num">{{ translate('active_products') }}</th>
                            <th>{{ translate('publish') }}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($flashDeals as $deal)
                            @php
                                $dealStartsAt = strtotime($deal['start_date']);
                                $isExpired = Carbon::parse($deal['end_date'])->endOfDay()->isPast();
                            @endphp
                            <tr>
                                <td>
                                    <span class="k-truncate" style="display:block;max-inline-size:250px" title="{{ $deal['title'] }}">
                                        {{ $deal['title'] }}
                                    </span>
                                </td>
                                <td>
                                    <span class="k-num">{{ date('d M, y', $dealStartsAt) }} – {{ date('d M, y', strtotime($deal['end_date'])) }}</span>
                                    @if ($isExpired)
                                        <x-k.badge tone="danger">{{ translate('expired') }}</x-k.badge>
                                    @elseif ($dealStartsAt > strtotime('today'))
                                        <x-k.badge tone="info">{{ translate('upcoming') }}</x-k.badge>
                                    @else
                                        <x-k.badge tone="warning">{{ translate('live') }}</x-k.badge>
                                    @endif
                                </td>
                                <td class="k-table__num">
                                    <a href="{{ route('admin.deal.add-product', [$deal['id']]) }}" class="k-num">
                                        {{ $deal->products_count }}
                                    </a>
                                </td>
                                <td>
                                    <form action="{{ route('admin.deal.status-update') }}" method="post"
                                        id="flash-deal-status{{ $deal['id'] }}-form" >
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $deal['id'] }}">
                                        <label class="switcher mx-auto"
                                            for="flash-deal-status{{ $deal['id'] }}"  @if($isExpired) data-bs-toggle="tooltip"
                                               data-bs-placement="top"
                                               title="{{ translate('This_deal_has_expired_and_cannot_be_updated.') }}"
                                            @endif>
                                            <input class="switcher_input custom-modal-plugin" type="checkbox"
                                                value="1" name="status"
                                                id="flash-deal-status{{ $deal['id'] }}"
                                                {{ $deal['status'] == 1 ? 'checked' : '' }}
                                                   {{ $isExpired ? 'disabled' : '' }}
                                                data-modal-type="input-change-form"
                                                data-modal-form="#flash-deal-status{{ $deal['id'] }}-form"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/flash-deal-status-on.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/flash-deal-status-off.png') }}"
                                                data-on-title = "{{ translate('Want_to_Turn_ON_Flash_Deal_Status') . '?' }}"
                                                data-off-title = "{{ translate('Want_to_Turn_OFF_Flash_Deal_Status') . '?' }}"
                                                data-on-message = "<p>{{ translate('if_enabled_this_flash_sale_will_be_available_on_the_website_and_customer_app') }}</p>"
                                                data-off-message = "<p>{{ translate('if_disabled_this_flash_sale_will_be_hidden_from_the_user_website_and_customer_app') }}</p>"
                                                data-on-button-text="{{ translate('turn_on') }}"
                                                data-off-button-text="{{ translate('turn_off') }}">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </form>
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        <a class="k-btn k-btn--secondary k-btn--sm"
                                            href="{{ route('admin.deal.add-product', [$deal['id']]) }}">
                                            <x-k.icon name="plus" :size="14" /> {{ translate('add_product') }}
                                        </a>
                                        <a title="{{ translate('edit') }}"
                                            href="{{ route('admin.deal.update', [$deal['id']]) }}"
                                            class="k-btn k-btn--ghost k-btn--sm k-btn--icon">
                                            <x-k.icon name="edit" :size="15" />
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    @if (count($flashDeals) == 0)
                        <x-k.empty icon="marketing" :title="translate('no_data_found')"
                                   :text="request('searchValue') ? translate('no_deal_matches_your_search') : null">
                            <x-slot:action>
                                <x-k.button variant="primary" icon="plus" :href="route('admin.deal.flash-add')">
                                    {{ translate('Create_Flash_Deals') }}
                                </x-k.button>
                            </x-slot:action>
                        </x-k.empty>
                    @endif

                    @if ($flashDeals->total() > 0)
                        <x-slot:pager>
                            <span class="k-pager__info">
                                {{ translate('showing') }}
                                <span class="k-num">{{ $flashDeals->firstItem() }}–{{ $flashDeals->lastItem() }}</span>
                                {{ translate('of') }} <span class="k-num">{{ $flashDeals->total() }}</span>
                            </span>
                            <div>{!! $flashDeals->appends(request()->except('page'))->links() !!}</div>
                        </x-slot:pager>
                    @endif
                </x-k.data-view>
            </div>
        </div>
    </div>

    <div class="modal fade" id="prioritySetModal" tabindex="-1" aria-labelledby="prioritySetModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="{{ route('admin.business-settings.priority-setup.update-by-type') }}" method="post">
                    @csrf
                    <input type="hidden" name="type" value="flash_deal_priority">
                    <div class="modal-body px-sm-4 mb-sm-3">
                        <div class="d-flex align-items-center justify-content-between mb-4">
                            <h4 class="modal-title flex-grow-1 text-center text-capitalize" id="prioritySetModalLabel">
                                {{ translate('priority_settings') }}</h4>
                            <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                data-bs-dismiss="modal" aria-label="Close">
                            </button>
                        </div>
                        <div class="d-flex gap-4 flex-column flash-deal">
                            <div class="d-flex gap-2 justify-content-between pb-3 border-bottom">
                                <div class="d-flex flex-column">
                                    <h3 class="text-capitalize">{{ translate('use_default_sorting_list') }}</h3>
                                    <div class="d-flex gap-2 align-items-center">
                                        <img width="14"
                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/info.svg') }}"
                                            alt="">
                                        <span
                                            class="text-dark fs-12">{{ translate('currently_sorting_this_section_based_on_first_created_products') }}</span>
                                    </div>
                                </div>
                                <label class="switcher">
                                    <input type="checkbox" class="switcher_input switcher-input-js"
                                        data-parent-class="flash-deal" data-from="default-sorting"
                                        {{ $flashDealPriority?->custom_sorting_status == 1 ? '' : 'checked' }}>
                                    <span class="switcher_control"></span>
                                </label>
                            </div>
                            <div class="">
                                <div class="d-flex gap-2 justify-content-between">
                                    <div class="d-flex flex-column">
                                        <h4 class="text-capitalize">{{ translate('use_custom_sorting_list') }}</h4>
                                        <div class="d-flex gap-2 align-items-center">
                                            <img width="14"
                                                src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/info.svg') }}"
                                                alt="">
                                            <span
                                                class="text-dark fs-12">{{ translate('you_can_sorting_this_section_by_others_way') }}</span>
                                        </div>
                                    </div>
                                    <label class="switcher">
                                        <input type="checkbox" class="switcher_input switcher-input-js"
                                            name="custom_sorting_status" value="1" data-parent-class="flash-deal"
                                            data-from="custom-sorting"
                                            {{ isset($flashDealPriority?->custom_sorting_status) && $flashDealPriority?->custom_sorting_status == 1 ? 'checked' : '' }}>
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>

                                <div
                                    class="custom-sorting-radio-list {{ isset($flashDealPriority?->custom_sorting_status) && $flashDealPriority?->custom_sorting_status == 1 ? '' : 'd--none' }}">
                                    <div class="border rounded p-3 d-flex flex-column gap-2 mt-4">

                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" class="show form-check-input radio--input"
                                                name="sort_by" value="latest_created"
                                                id="flash-deal-sort-by-latest-created"
                                                {{ isset($flashDealPriority?->sort_by) && $flashDealPriority?->sort_by == 'latest_created' ? 'checked' : '' }}>
                                            <label class="mb-0 cursor-pointer" for="flash-deal-sort-by-latest-created">
                                                {{ translate('sort_by_latest_created') }}
                                            </label>
                                        </div>

                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" class="show form-check-input radio--input"
                                                name="sort_by" value="first_created"
                                                id="flash-deal-sort-by-first-created"
                                                {{ isset($flashDealPriority?->sort_by) && $flashDealPriority?->sort_by == 'first_created' ? 'checked' : '' }}>
                                            <label class="mb-0 cursor-pointer" for="flash-deal-sort-by-first-created">
                                                {{ translate('sort_by_first_created') }}
                                            </label>
                                        </div>

                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" class="show form-check-input radio--input"
                                                name="sort_by" value="most_order" id="flash-deal-sort-by-most-order"
                                                {{ isset($flashDealPriority?->sort_by) ? ($flashDealPriority?->sort_by == 'most_order' ? 'checked' : '') : 'checked' }}>
                                            <label class="mb-0 cursor-pointer" for="flash-deal-sort-by-most-order">
                                                {{ translate('sort_by_most_order') }}
                                            </label>
                                        </div>

                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" class="show form-check-input radio--input"
                                                name="sort_by" id="flash-deal-sort-by-reviews-count"
                                                value="reviews_count"
                                                {{ isset($flashDealPriority?->sort_by) && $flashDealPriority?->sort_by == 'reviews_count' ? 'checked' : '' }}>
                                            <label class="mb-0 cursor-pointer" for="flash-deal-sort-by-reviews-count">
                                                {{ translate('sort_by_reviews_count') }}
                                            </label>
                                        </div>

                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" class="show form-check-input radio--input"
                                                name="sort_by" id="flash-deal-sort-by-ratings" value="rating"
                                                {{ isset($flashDealPriority?->sort_by) && $flashDealPriority?->sort_by == 'rating' ? 'checked' : '' }}>
                                            <label class="mb-0 cursor-pointer" for="flash-deal-sort-by-ratings">
                                                {{ translate('sort_by_average_ratings') }}
                                            </label>
                                        </div>

                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" class="show form-check-input radio--input"
                                                name="sort_by" value="a_to_z" id="flash-deal-alphabetic-order"
                                                {{ isset($flashDealPriority?->sort_by) && $flashDealPriority?->sort_by == 'a_to_z' ? 'checked' : '' }}>
                                            <label class="mb-0 cursor-pointer text-capitalize"
                                                for="flash-deal-alphabetic-order">
                                                {{ translate('sort_by_Alphabetical') }}
                                                ({{ 'A ' . translate('to') . ' Z' }})
                                            </label>
                                        </div>

                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" class="show form-check-input radio--input"
                                                name="sort_by" value="z_to_a" id="flash-deal-alphabetic-order-reverse"
                                                {{ isset($flashDealPriority?->sort_by) && $flashDealPriority?->sort_by == 'z_to_a' ? 'checked' : '' }}>
                                            <label class="mb-0 cursor-pointer text-capitalize"
                                                for="flash-deal-alphabetic-order-reverse">
                                                {{ translate('sort_by_Alphabetical') }}
                                                ({{ 'Z ' . translate('to') . ' A' }})
                                            </label>
                                        </div>

                                    </div>

                                    <div class="border rounded p-3 d-flex flex-column gap-2 mt-3">
                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" name="out_of_stock_product" value="desc"
                                                class="check-box form-check-input radio--input"
                                                data-parent-class="flash-deal" id="show-in-last"
                                                {{ isset($flashDealPriority?->out_of_stock_product) && $flashDealPriority?->out_of_stock_product == 'desc' ? 'checked' : '' }}>
                                            <label class="mb-0 cursor-pointer" for="show-in-last">
                                                {{ translate('show_stock_out_products_in_the_last') }}
                                            </label>
                                        </div>
                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" name="out_of_stock_product" value="hide"
                                                class="check-box form-check-input radio--input"
                                                data-parent-class="flash-deal" id="remove-product"
                                                {{ isset($flashDealPriority?->out_of_stock_product) && $flashDealPriority?->out_of_stock_product == 'hide' ? 'checked' : '' }}>
                                            <label class="mb-0 cursor-pointer" for="remove-product">
                                                {{ translate('remove_stock_out_products_from_the_list') }}
                                            </label>
                                        </div>
                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" name="out_of_stock_product" value="default"
                                                data-parent-class="flash-deal" id="default"
                                                class="form-check-input radio--input"
                                                {{ isset($flashDealPriority?->out_of_stock_product) ? ($flashDealPriority?->out_of_stock_product == 'default' ? 'checked' : '') : 'checked' }}>
                                            <label class="mb-0 cursor-pointer" for="default">
                                                {{ translate('none') }}
                                            </label>
                                        </div>
                                    </div>

                                    <div class="border rounded p-3 d-flex flex-column gap-2 mt-3">
                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" name="temporary_close_sorting" value="desc"
                                                data-parent-class="flash-deal" id="flash-deal-temporary-close-last"
                                                class="form-check-input radio--input"
                                                {{ isset($flashDealPriority?->temporary_close_sorting) && $flashDealPriority?->temporary_close_sorting == 'desc' ? 'checked' : '' }}>
                                            <label class="mb-0 cursor-pointer" for="flash-deal-temporary-close-last">
                                                {{ translate('show_product_in_the_last_is_store_is_temporarily_off') }}
                                            </label>
                                        </div>
                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" name="temporary_close_sorting" value="hide"
                                                data-parent-class="flash-deal" id="flash-deal-temporary-close-remove"
                                                class="form-check-input radio--input"
                                                {{ isset($flashDealPriority?->temporary_close_sorting) ? ($flashDealPriority?->temporary_close_sorting == 'hide' ? 'checked' : '') : 'checked' }}>
                                            <label class="mb-0 cursor-pointer" for="flash-deal-temporary-close-remove">
                                                {{ translate('remove_product_from_the_list_if_store_is_temporarily_off') }}
                                            </label>
                                        </div>
                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="radio" name="temporary_close_sorting" value="default"
                                                data-parent-class="flash-deal" id="flash-deal-temporary-close-default"
                                                class="form-check-input radio--input"
                                                {{ isset($flashDealPriority?->temporary_close_sorting) ? ($flashDealPriority?->temporary_close_sorting == 'default' ? 'checked' : '') : 'checked' }}>
                                            <label class="mb-0 cursor-pointer" for="flash-deal-temporary-close-default">
                                                {{ translate('none') }}
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-primary px-5">{{ translate('save') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection
@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/deal.js') }}"></script>
@endpush
