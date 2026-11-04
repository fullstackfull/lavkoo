@extends('layouts.admin.app')

@section('title', translate('review_List'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex gap-2 align-items-center">
                <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/customer_review.png')}}" alt="">
                {{translate('customer_reviews')}}
                <span class="badge badge-info text-bg-info">{{ $reviews->total() }}</span>
            </h2>
        </div>

        @php($reviewFilterActive = request('product_id') || request('customer_id') || request('status') || request('from') || request('to'))
        <x-k.data-view :title="translate('Customer_Reviews_List')" :count="$reviews->total()"
                       searchName="searchValue" :searchValue="requestString('searchValue')"
                       :searchPlaceholder="translate('search_by_Id, Product, Reviewer, Review')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('admin.reviews.export', ['searchValue'=>$searchValue, 'product_id' => $product_id, 'vendor_id' => $vendor_id, 'customer_id' => $customer_id, 'status' => $status, 'from' => $from, 'to' => $to]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
                <button type="button" class="k-btn {{ $reviewFilterActive ? 'k-btn--primary' : 'k-btn--secondary' }}"
                        data-bs-toggle="offcanvas" data-bs-target="#reviewFilterOffcanvas">
                    <x-k.icon name="filter" :size="15" /> {{ translate('Filter') }}
                </button>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('SL') }}</th>
                    <th>{{ translate('Review_ID') }}</th>
                    <th>{{ translate('product') }}</th>
                    <th>{{ translate('customer') }}</th>
                    <th class="k-table__num">{{ translate('rating') }}</th>
                    <th>{{ translate('review') }}</th>
                    <th>{{ translate('Reply') }}</th>
                    <th>{{ translate('date') }}</th>
                    <th>{{ translate('status') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($reviews as $key => $review)
                    <?php
                        $isProductUnavailable = $review?->product === null;
                        $productDetails = $review?->product;
                        if ($isProductUnavailable) {
                            $getOrderProduct = $review?->order?->details?->where('product_id', $review['product_id'])?->first()?->product_details ?? null;
                            $productDetails = $getOrderProduct ? json_decode($getOrderProduct, true) : null;
                        }
                    ?>
                    <tr>
                        <td><span class="k-num">{{ $reviews->firstItem()+$key }}</span></td>
                        <td><span class="k-num">{{ $review->id }}</span></td>
                        <td>
                            @if(isset($review?->product))
                                <a href="{{$review['product_id'] ? route('admin.products.view', ['addedBy'=>($review->product->added_by =='seller'?'vendor' : 'in-house'),'id'=>$review->product->id]) : 'javascript:'}}"
                                   class="k-row">
                                    <img src="{{ getStorageImages(path: $review->product->thumbnail_full_url, type: 'backend-product') }}"
                                         alt="" width="40" height="40"
                                         style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                    <span class="k-truncate text-dark" style="max-inline-size:200px"
                                          data-bs-toggle="tooltip" title="{{ $review->product['name'] }}">
                                        {{ Str::limit($review->product['name'], 25) }}
                                    </span>
                                </a>
                            @else
                                <div class="k-row" style="opacity:.5">
                                    <img src="{{ getStorageImages(path: '', type: 'backend-product') }}"
                                         alt="" width="40" height="40"
                                         style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                    <span class="k-truncate" style="max-inline-size:200px"
                                          data-bs-toggle="tooltip" title="{{ translate('Product_has_been_deleted') }}">
                                        {{ $productDetails ? Str::limit($productDetails['name'], 25) : translate('Product_has_been_deleted') }}
                                    </span>
                                </div>
                            @endif
                        </td>
                        <td>
                            @if ($review->customer)
                                <a href="{{ route('admin.customer.view', [$review->customer_id]) }}" class="k-row">
                                    <img src="{{getStorageImages(path:$review->customer->image_full_url,type:'backend-profile')}}"
                                         alt="" width="32" height="32"
                                         style="border-radius:50%;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                    <span class="text-dark">
                                        {{ $review->customer->f_name . ' ' . $review->customer->l_name }}
                                    </span>
                                </a>
                            @else
                                <x-k.badge tone="danger">{{ translate('customer_removed') }}</x-k.badge>
                            @endif
                        </td>
                        <td class="k-table__num">
                            <x-k.badge tone="info"><span class="k-num">{{ $review->rating }}</span> <i class="fi fi-sr-star"></i></x-k.badge>
                        </td>
                        <td>
                            @php($comment = $review->comment)
                            <div
                                @if($comment && Str::length($comment) > 35)
                                    data-bs-toggle="tooltip" data-bs-placement="top" title="{{ $comment }}"
                                @endif>
                                @if($comment)
                                    {{ Str::limit($comment, 35) }}
                                @else
                                    <span class="k-text-subtle">{{ translate('no_comment_found') }}</span>
                                @endif
                            </div>
                            @if(count($review->attachment_full_url) > 0)
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    @foreach ($review->attachment_full_url as $img)
                                        <a href="{{ $img['path'] }}"
                                           data-lightbox="mygallery-{{ $review->id }}">
                                            <img width="36" height="36" class="rounded object-fit-cover border"
                                                 src="{{ getStorageImages(path: $img, type: 'backend-basic') }}"
                                                 alt="{{translate('image')}}">
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td>
                            @php($reply = $review?->reply?->reply_text)
                            <div class="k-truncate" style="max-inline-size:200px"
                                 @if($reply && Str::length($reply) > 50)
                                     data-bs-toggle="tooltip" data-bs-placement="top" title="{{ $reply }}"
                                 @endif>
                                @if($reply)
                                    {{ Str::limit($reply, 50) }}
                                @else
                                    <span class="k-text-subtle">—</span>
                                @endif
                            </div>
                        </td>
                        <td><span class="k-num">{{ date('d M Y', strtotime($review->updated_at)) }}</span></td>
                        <td>
                            <form action="{{ route('admin.reviews.status') }}"
                                  method="post" id="reviews-status{{$review['id']}}-form"
                                  class="no-reload-form">
                                <input type="hidden" name="id" value="{{$review['id']}}">
                                <label class="switcher mx-auto" for="reviews-status{{$review['id']}}">
                                    <input
                                        class="switcher_input custom-modal-plugin"
                                        type="checkbox" value="1" name="status"
                                        id="reviews-status{{$review['id']}}"
                                        {{ $review->status ? 'checked' : '' }}
                                        data-modal-type="input-change-form"
                                        data-modal-form="#reviews-status{{$review['id']}}-form"
                                        data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/customer-reviews-on.png') }}"
                                        data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/customer-reviews-off.png') }}"
                                        data-on-title = "{{translate('Want_to_Turn_ON_Customer_Reviews').'?'}}"
                                        data-off-title = "{{translate('Want_to_Turn_OFF_Customer_Reviews').'?'}}"
                                        data-on-message = "<p>{{translate('if_enabled_anyone_can_see_this_review_on_the_user_website_and_customer_app')}}</p>"
                                        data-off-message = "<p>{{translate('if_disabled_this_review_will_be_hidden_from_the_user_website_and_customer_app')}}</p>"
                                        data-on-button-text="{{ translate('turn_on') }}"
                                        data-off-button-text="{{ translate('turn_off') }}">
                                    <span class="switcher_control"></span>
                                </label>
                            </form>
                        </td>
                        <td>
                            <div class="k-table__actions">
                                <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                        title="{{ translate('View') }}"
                                        data-bs-toggle="modal" data-bs-target="#review-view-for-{{ $review['id'] }}">
                                    <x-k.icon name="eye" :size="15" />
                                </button>
                                @if(isset($review->product) && $review?->product?->added_by == 'admin')
                                    <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                            title="{{ $review?->reply ? translate('Update_Review') : translate('Review_Reply') }}"
                                            data-bs-toggle="modal" data-bs-target="#review-update-for-{{ $review['id'] }}">
                                        <x-k.icon name="{{ $review?->reply ? 'edit' : 'marketing' }}" :size="15" />
                                    </button>
                                @elseif($review?->product?->added_by == 'seller')
                                    <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon" style="opacity:.4"
                                          data-bs-toggle="tooltip"
                                          title="{{ translate('Admin_can_not_reply_to_vendor_product_review') }}">
                                        <x-k.icon name="{{ $review?->reply ? 'edit' : 'marketing' }}" :size="15" />
                                    </span>
                                @else
                                    <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon" style="opacity:.4"
                                          data-bs-toggle="tooltip"
                                          title="{{ translate('product_not_found') }}">
                                        <x-k.icon name="{{ $review?->reply ? 'edit' : 'marketing' }}" :size="15" />
                                    </span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($reviews)==0)
                <x-k.empty icon="sparkles" :title="translate('no_review_found')"
                           :text="request('searchValue') ? translate('no_review_matches_your_search') : null" />
            @endif

            @if ($reviews->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $reviews->firstItem() }}–{{ $reviews->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $reviews->total() }}</span>
                    </span>
                    <div>{!! $reviews->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>

        @foreach($reviews as $key => $review)
            @if(isset($review->customer))
                <div class="modal fade" id="review-update-for-{{ $review['id'] }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header border-0 d-flex justify-content-end">
                                <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none"
                                        style="--size: 2rem;" data-bs-dismiss="modal" aria-label="Close">
                                    <i class="fi fi-sr-cross"></i>
                                </button>
                            </div>
                            <form method="POST" action="{{ route('admin.reviews.add-review-reply') }}">
                                @csrf
                                <div class="modal-body pt-0">
                                    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                                        @if(isset($review->product))
                                            <img src="{{ getStorageImages(path: $review->product->thumbnail_full_url, type: 'backend-product') }}"
                                                 width="60" class="rounded aspect-1 border" alt="{{ translate('Product') }}">
                                            <div class="flex-grow-1">
                                                <h5 class="mb-1 fs-14 text-dark">{{ $review->product['name'] }}</h5>
                                                @if($review['order_id'])
                                                    <span class="fs-12 text-muted">{{ translate('Order_ID') }} #{{ $review['order_id'] }}</span>
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-dark">{{ translate('product_not_found') }}</span>
                                        @endif
                                    </div>

                                    <div class="bg-section p-3 rounded border mb-3">
                                        <div class="d-flex gap-2 align-items-center mb-2">
                                            <img class="h-30 aspect-1 rounded-circle"
                                                 src="{{ getStorageImages(path: $review->customer?->image_full_url, type: 'backend-profile') }}"
                                                 alt="{{ translate('Customer') }}">
                                            <span class="fs-14 fw-medium text-dark">
                                                {{ $review->customer?->f_name ?? translate('Customer') }}
                                            </span>
                                        </div>
                                        <p class="mb-0 fs-14">{{ $review['comment'] ?? translate('No comment found') }}</p>
                                    </div>

                                    @if(count($review->attachment_full_url) > 0)
                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            @foreach ($review->attachment_full_url as $img)
                                                <a href="{{ getStorageImages(path: $img, type: 'backend-basic') }}"
                                                   data-lightbox="review-gallery-modal{{ $review['id'] }}">
                                                    <img width="45" class="rounded aspect-1 border"
                                                         src="{{ getStorageImages(path: $img, type: 'backend-basic') }}"
                                                         alt="{{ translate('review_image') }}">
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif

                                    <input type="hidden" name="review_id" value="{{ $review['id'] }}">
                                    <div class="form-group">
                                        <label class="form-label fw-bold">
                                            {{ translate('Reply') }}
                                        </label>
                                        <textarea class="form-control text-area-max-min" rows="3" name="reply_text"
                                                  data-maxlength="250"
                                                  placeholder="{{ translate('Write_the_reply_of_the_product_review') }}...">{{ $review?->reply?->reply_text ?? '' }}</textarea>
                                        <div class="d-flex justify-content-end">
                                            <span class="text-body-light">0/160</span>
                                        </div>
                                    </div>

                                    <div class="text-end mt-4">
                                        <button type="submit" class="btn btn-primary">
                                            {{ $review?->reply?->reply_text ? translate('Update') : translate('submit') }}
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif

            @include("admin-views.reviews._review-modal", ['review' => $review])
        @endforeach
    </div>

    @include('admin-views.reviews.partials._filter-offcanvas')
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/new/back-end/js/search-product.js')}}"></script>
    <script>
        $(document).ready(function () {

            function toggleCustomDate(wrapper) {
                let selectValue = wrapper.find('.date-type-select').val();

                if (selectValue === 'custom') {
                    wrapper.find('.custom-date-div').slideDown(200);
                } else {
                    wrapper.find('.custom-date-div').slideUp(200);
                }
            }

            $(document).on('change', '.date-type-select', function () {
                let wrapper = $(this).closest('.date-filter-wrapper');
                toggleCustomDate(wrapper);
            });

            $('.date-filter-wrapper').each(function () {
                toggleCustomDate($(this));
            });

        });
    </script>
@endpush
