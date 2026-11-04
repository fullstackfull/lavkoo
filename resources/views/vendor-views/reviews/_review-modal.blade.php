<div class="modal fade" id="review-view-for-{{ $review['id'] }}" tabindex="-1" aria-labelledby="review-modal" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="--bs-modal-width: 700px;">
        <div class="modal-content">
            <div class="modal-header border-0 pt-3 px-3 ms-4 pb-0 d-flex justify-content-end position-absolute end-0 top-0 z-index-1">
                <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none close" style="--size: 2rem;"  data-dismiss="modal" aria-label="Close">
                    <i class="fi fi-sr-cross"></i>
                </button>
            </div>
            <div class="modal-body px-20 pb-0 pt-4 mb-30">
                <h3 class="mb-2 pe-4">
                    {{ translate('Review') }} #{{ $review['id'] }}
                </h3>
                @if($review['order_id'])
                    <div class="">
                        {{ translate('Order_ID') }} #{{ $review['order_id'] }}
                    </div>
                @endif

                <div
                    class="d-flex gap-3 mt-3 flex-wrap flex-sm-nowrap justify-content-between align-items-center border-bottom mb-4 pb-4">
                    <div class="d-flex gap-2 align-items-center">
                        <img class="h-50px aspect-1 rounded border"
                             src="{{ getStorageImages(path: $review?->product?->thumbnail_full_url, type: 'backend-product') }}" alt="">
                        <span class="fs-14 text-dark line--limit-2">
                            {{ $review->product?->name ?? translate('product_not_found') }}
                        </span>
                    </div>
                    <div
                        class="bg-section min-w-60px h-50px w-120 rounded-10 text-dark fs-20 fw-medium d-flex gap-2 align-items-center justify-content-center lh-1">
                        <span>{{ $review['rating'] }}</span>
                        <span class="lh-1"><i class="fi fi-sr-star text-warning"></i></span>
                    </div>
                </div>
                <div class="mb-20">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-2">
                        <h4 class="mb-0">{{ translate('Review') }}</h4>
                        <div class="d-flex flex-sm-no-wrap flex-wrap gap-2 align-items-center">
                            <span>{{ $review->created_at->format('d M, Y') }}</span>
                            <span class="d-none d-sm-block">|</span>
                            <span>{{ $review->created_at->format('h:i A') }}</span>
                        </div>
                    </div>

                    <div class="bg-section p-3 p-sm-20 rounded-10">
                        <div class="d-flex gap-2 align-items-center mb-3">
                            <img class="h-30 aspect-1 rounded-circle"
                                 src="{{ getStorageImages(path: $review?->customer?->image_full_url, type: 'backend-profile') }}" alt="">
                            <span class="fs-14 text-dark fw-medium">
                                 @if ($review->customer)
                                    {{ $review->customer->f_name . ' ' . $review->customer->l_name }}
                                @else
                                    {{ translate('Not_found') }}
                                  @endif
                            </span>
                        </div>
                        <p class="short_text_wrapper mb-10px">
                                <span class="short_text" data-maxlength="298"
                                      data-see-more-text="{{ translate('See_More') }}"
                                      data-see-less-text="{{ translate('See_Less') }}">
                                    {!! $review['comment'] !!}
                                </span>
                            @if(Str::length($review['comment'] ?? '') > 200)
                                <a href="javascript:" class="see_more_btn text-underline text-nowrap">
                                    {{ translate('See_More') }}
                                </a>
                            @endif
                        </p>

                        @if(count($review->attachment_full_url) > 0)
                            <div class="d-flex flex-wrap gap-3 align-items-center mt-2">
                                @foreach ($review->attachment_full_url as $img)
                                    <a class="aspect-1 overflow-hidden"
                                       href="{{ getStorageImages(path: $img,type: 'backend-basic') }}"
                                       data-lightbox="review-gallery-modal{{ $review['id'] }}" >
                                        <img class="w-50px aspect-1 rounded"
                                             src="{{ getStorageImages(path: $img,type: 'backend-basic') }}"
                                             alt="{{ translate('review_image') }}">
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>


                @if($review?->reply?->reply_text)
                    <div>
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-2">
                            <h4 class="mb-0">{{ translate('Reply') }}</h4>
                            <div class="d-flex flex-sm-no-wrap flex-wrap gap-2 align-items-center">
                                <span>{{ $review?->reply?->created_at->format('d M, Y') }}</span>
                                <span class="d-none d-sm-block">|</span>
                                <span>{{ $review?->reply?->created_at->format('h:i A') }}</span>
                            </div>
                        </div>
                        <div class="bg-section p-3 p-sm-20 rounded-10">
                            <div class="d-flex gap-2 align-items-center mb-3">
                                @if($review?->product?->added_by == 'seller')
                                    <img class="h-30 aspect-1 rounded-circle"
                                         src="{{ getStorageImages(path: $review?->product?->seller?->shop?->image_full_url, type: 'shop') }}" alt="">
                                    <span class="fs-14 text-dark fw-medium">
                                        {{ $review?->product?->seller?->shop?->name ?? translate('Not_found') }}
                                    </span>
                                @else
                                    <img class="h-30 aspect-1 rounded-circle"
                                         src="{{ getStorageImages(path: getInHouseShopConfig(key: 'image_full_url'), type: 'logo') }}" alt="">
                                    <span class="fs-14 text-dark fw-medium">
                                        {{ getInHouseShopConfig(key: 'name') }}
                                    </span>
                                @endif
                            </div>
                            <p class="short_text_wrapper mb-10px">
                                <span class="short_text" data-maxlength="298"
                                      data-see-more-text="{{ translate('See_More') }}"
                                      data-see-less-text="{{ translate('See_Less') }}">
                                    {{ $review?->reply?->reply_text ?? '' }}
                                </span>
                                @if(Str::length($review?->reply?->reply_text ?? '') > 200)
                                    <a href="javascript:" class="see_more_btn text-underline text-nowrap">
                                        {{ translate('See_More') }}
                                    </a>
                                @endif
                            </p>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
