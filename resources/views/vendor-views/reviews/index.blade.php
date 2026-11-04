@extends('layouts.vendor.app')

@section('title', translate('review_List'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize">
                <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/product-review.png')}}" class="mb-1 me-1"
                     alt="">
                {{translate('product_reviews')}}
                <span class="badge badge-soft-dark radius-50 fs-12">{{ $reviews->total() }}</span>
            </h2>
        </div>

        @php($vendorReviewReplyStatus = getWebConfig('vendor_review_reply_status') ?? 0)
        @php($reviewFilterActive = request('product_id') || request('customer_id') || request('status') || request('from') || request('to'))
        <x-k.data-view :title="translate('product_reviews')" :count="$reviews->total()"
                       searchName="searchValue" :searchValue="$searchValue"
                       :searchPlaceholder="translate('search_by_Id, Product, Reviewer, Review')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('vendor.reviews.export', ['product_id' => $product_id, 'customer_id' => $customer_id, 'status' => $status, 'from' => $from, 'to' => $to, 'searchValue'=>$searchValue]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
                <button type="button" class="k-btn {{ $reviewFilterActive ? 'k-btn--primary' : 'k-btn--secondary' }}"
                        data-toggle="offcanvas" data-target="#reviewFilterOffcanvas">
                    <x-k.icon name="filter" :size="15" /> {{ translate('Filter') }}
                </button>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('SL') }}</th>
                    <th>{{ translate('Review_ID') }}</th>
                    <th>{{ translate('product') }}</th>
                    <th>{{ translate('Reviewer') }}</th>
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
                    @if ($review->product)
                        <tr>
                            <td><span class="k-num">{{ $reviews->firstItem()+$key }}</span></td>
                            <td><span class="k-num">{{ $review->id }}</span></td>
                            <td>
                                <a class="k-row" href="{{ route('vendor.products.view', [$review['product_id']]) }}">
                                    <img src="{{ getStorageImages(path: $review->product['thumbnail_full_url'], type: 'backend-product') }}"
                                         alt="" width="40" height="40"
                                         style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                    <span class="k-truncate title-color" style="max-inline-size:200px" title="{{ $review->product['name'] }}">
                                        {{ Str::limit($review->product['name'], 25) }}
                                    </span>
                                </a>
                            </td>
                            <td>
                                @if ($review->customer)
                                    <div class="k-row">
                                        <img src="{{ getStorageImages(path:$review->customer->image_full_url,type: 'backend-profile') }}"
                                             alt="" width="32" height="32"
                                             style="border-radius:50%;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                        <span class="title-color">
                                            {{ $review->customer->f_name . ' ' . $review->customer->l_name }}
                                        </span>
                                    </div>
                                @else
                                    <x-k.badge tone="danger">{{ translate('customer_removed') }}</x-k.badge>
                                @endif
                            </td>
                            <td class="k-table__num">
                                <span class="k-num d-inline-flex align-items-center gap-1">
                                    <i class="tio-star text-warning"></i>{{ $review->rating }}
                                </span>
                            </td>
                            <td>
                                <div
                                    @if($review->comment && strlen($review->comment) > 35)
                                        data-toggle="tooltip" data-placement="top" title="{{ $review->comment }}"
                                    @endif>
                                    @if($review->comment)
                                        {{ Str::limit($review->comment, 35) }}
                                    @else
                                        <span class="k-text-subtle">{{ translate('no_comment_found') }}</span>
                                    @endif
                                </div>
                                @if($review->attachment_full_url)
                                    <div class="d-flex flex-wrap gap-1 mt-1">
                                        @foreach ($review->attachment_full_url as $img)
                                            <a href="{{getStorageImages(path:$img,type: 'backend-basic')}}"
                                               data-lightbox="mygallery{{ $review['id'] }}">
                                                <img width="36" height="36" class="rounded object-fit-cover border"
                                                     src="{{ getStorageImages(path:$img,type: 'backend-basic')}}"
                                                     alt="{{translate('image')}}">
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td>
                                @php($replyText = $review?->reply?->reply_text)
                                <div class="k-truncate" style="max-inline-size:200px"
                                     @if($replyText && strlen($replyText) > 35)
                                         data-toggle="tooltip" data-placement="top" title="{{ $replyText }}"
                                     @endif>
                                    @if($replyText)
                                        {{ Str::limit($replyText, 35) }}
                                    @else
                                        <span class="k-text-subtle">—</span>
                                    @endif
                                </div>
                            </td>
                            <td><span class="k-num">{{ date('d M Y', strtotime($review->created_at)) }}</span></td>
                            <td>
                                <form action="{{ route('vendor.reviews.update-status', [$review['id'], $review->status ? 0 : 1]) }}"
                                      method="get" id="reviews-status{{$review['id']}}-form"
                                      class="reviews_status_form">
                                    <label class="switcher mx-auto">
                                        <input type="checkbox" class="switcher_input toggle-switch-message"
                                               id="reviews-status{{$review['id']}}"
                                               {{ $review->status ? 'checked' : '' }}
                                               data-modal-id="toggle-status-modal"
                                               data-toggle-id="reviews-status{{$review['id']}}"
                                               data-on-image="customer-reviews-on.png"
                                               data-off-image="customer-reviews-off.png"
                                               data-on-title="{{translate('Want_to_Turn_ON_Customer_Reviews').'?'}}"
                                               data-off-title="{{translate('Want_to_Turn_OFF_Customer_Reviews').'?'}}"
                                               data-on-message="<p>{{translate('if_enabled_anyone_can_see_this_review_on_the_user_website_and_customer_app')}}</p>"
                                               data-off-message="<p>{{translate('if_disabled_this_review_will_be_hidden_from_the_user_website_and_customer_app')}}</p>">
                                        <span class="switcher_control"></span>
                                    </label>
                                </form>
                            </td>
                            <td>
                                <div class="k-table__actions">
                                    <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                            title="{{ translate('View') }}"
                                            data-toggle="modal" data-target="#review-view-for-{{ $review['id'] }}">
                                        <x-k.icon name="eye" :size="15" />
                                    </button>
                                    @if($vendorReviewReplyStatus)
                                        <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                                title="{{ $review?->reply ? translate('Edit') : translate('Review_Reply') }}"
                                                data-toggle="modal" data-target="#review-update-for-{{ $review['id'] }}">
                                            <x-k.icon name="{{ $review?->reply ? 'edit' : 'marketing' }}" :size="15" />
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
                </tbody>
            </table>

            @if(count($reviews)==0)
                <x-k.empty icon="sparkles" :title="translate('no_review_found')"
                           :text="$searchValue ? translate('no_review_matches_your_search') : null" />
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
                <div class="modal fade" id="review-update-for-{{ $review['id'] }}" tabindex="-1" aria-labelledby="exampleModalLabel"
                     aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <button type="button" class="close text-BFBFBF" data-dismiss="modal" aria-label="Close">
                                    <i class="tio-clear-circle"></i>
                                </button>
                            </div>
                            <form method="POST" action="{{ route('vendor.reviews.add-review-reply') }}">
                                @csrf
                                <div class="modal-body pt-0">
                                    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                                        @if(isset($review->product))
                                            <img src="{{ getStorageImages(path:$review?->product?->thumbnail_full_url, type: 'backend-product') }}" width="100" class="rounded aspect-1 border" alt="">
                                            <div class="w-0 flex-grow-1 font-weight-semibold">
                                                @if($review['order_id'])
                                                    <div class="mb-2">
                                                        {{ translate('Order_ID') }} # {{ $review['order_id'] }}
                                                    </div>
                                                @endif
                                                <h4 class="line--limit-2">{{ $review->product['name'] }}</h4>
                                            </div>
                                        @else
                                            <span class="title-color">
                                                {{ translate('product_not_found') }}
                                            </span>
                                        @endif

                                    </div>
                                    <label class="input-label text--title font-weight-bold">
                                        {{ translate('Review') }}
                                    </label>
                                    <div class="__bg-F3F5F9 p-3 rounded border mb-2">
                                        {{ $review['comment'] }}
                                    </div>
                                    <div class="d-flex flex-wrap gap-2">
                                        @if(count($review->attachment_full_url)>0)
                                            @foreach ($review->attachment_full_url as $img)
                                                <a class="aspect-1 float-start overflow-hidden"
                                                   href="{{ getStorageImages(path:$img,type: 'backend-basic') }}"
                                                   data-lightbox="review-gallery-modal{{ $review['id'] }}" >
                                                    <img width="45" class="rounded aspect-1 border"
                                                         src="{{ getStorageImages(path:$img,type: 'backend-basic') }}"
                                                         alt="{{translate('review_image')}}">
                                                </a>
                                            @endforeach
                                        @endif
                                    </div>
                                    <label class="input-label text--title font-weight-bold pt-4">
                                        {{ translate('Reply') }}
                                    </label>
                                    <input type="hidden" name="review_id" value="{{ $review['id'] }}">
                                    <textarea class="form-control text-area-max-min" rows="3" name="reply_text"
                                              placeholder="{{ translate('Write_the_reply_of_the_product_review') }}...">{{ $review?->reply?->reply_text ?? '' }}</textarea>
                                    <div class="text-end mt-4">
                                        <button type="submit" class="btn btn--primary">
                                            @if($review?->reply?->reply_text)
                                                {{ translate('Update') }}
                                            @else
                                                {{ translate('submit') }}
                                            @endif
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
            @include("vendor-views.reviews._review-modal", ['review' => $review])
        @endforeach
        @include("vendor-views.reviews.partials._filter-offcanvas")
    </div>
@endsection
@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/search-product.js')}}"></script>
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
