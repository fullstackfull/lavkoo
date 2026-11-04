<div class="modal fade" id="review-view-for-{{ $review['id'] }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none"
                        style="--size: 2rem;" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fi fi-sr-cross"></i>
                </button>
            </div>

            <?php
                $isProductUnavailable = $review?->product === null;
                $productDetails = $review?->product;
                if ($isProductUnavailable) {
                    $getOrderProduct = $review?->order?->details?->where('product_id', $review['product_id'])?->first()?->product_details ?? null;
                    $productDetails = $getOrderProduct ? json_decode($getOrderProduct, true) : null;
                }
            ?>

            <div class="modal-body px-20 py-0 mb-30">
                <h3 class="mb-2">{{ translate('Review') }} #{{ $review->id }}</h3>

                <div
                    class="d-flex gap-3 flex-wrap flex-sm-nowrap justify-content-between align-items-center border-bottom mb-4 pb-4">
                    <div class="d-flex gap-2 align-items-center">
                        <img class="h-50px aspect-1 rounded border"
                             src="{{ getStorageImages(path: $review?->product?->thumbnail_full_url, type: 'backend-product') }}"
                             alt="{{ translate('Product') }}">
                        @if(isset($review?->product))
                            <span class="fs-14 text-dark">{{ $review->product?->name ?? translate('Product not found') }}</span>
                        @else
                            <span class="fs-14 text-dark" data-bs-toggle="tooltip"
                                  title="{{ translate('Product_has_been_deleted') }}">
                                {{ $productDetails ? Str::limit($productDetails['name'], 25) : ($getOrderProduct ? $getOrderProduct['name'] : translate('Product_has_been_deleted')) }}
                            </span>
                        @endif
                    </div>

                    <div
                        class="bg-section h-50px w-120 rounded-10 text-dark fs-20 fw-medium d-flex gap-2 align-items-center justify-content-center lh-1">
                        <span>{{ $review->rating }}</span>
                        <span><i class="fi fi-sr-star text-warning"></i></span>
                    </div>
                </div>

                <!-- Review Content -->
                <div class="mb-20">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-2">
                        <h4 class="mb-0">{{ translate('Review') }}</h4>
                        <div class="d-flex gap-2 align-items-center">
                            <span>{{ date('d M, Y', strtotime($review->created_at)) }}</span>
                            <span class="d-none d-sm-block">|</span>
                            <span>{{ date('h:i A', strtotime($review->created_at)) }}</span>
                        </div>
                    </div>
                    <div class="bg-section p-3 p-sm-20 rounded-10">
                        <div class="d-flex gap-2 align-items-center mb-2">
                            <img class="h-30 aspect-1 rounded-circle"
                                 src="{{ getStorageImages(path: $review?->customer?->image_full_url, type: 'backend-profile') }}"
                                 alt="{{ translate('Customer') }}">
                            <span class="fs-14 text-dark fw-medium">{{ ($review->customer?->f_name || $review->customer?->l_name) ? ($review->customer->f_name . ' ' . $review->customer->l_name) : translate('Customer') }}</span>
                        </div>
                        <p class="short_text_wrapper mb-0">
                                <span class="short_text" data-maxlength="298"
                                      data-see-more-text="{{ translate('See_More') }}"
                                      data-see-less-text="{{ translate('See_Less') }}">
                                    {{ $review->comment ?? translate('No comment found') }}
                                </span>
                            @if (Str::length($review?->comment ?? '') > 200)
                                <a href="javascript:"
                                   class="see_more_btn text-underline text-nowrap">{{ translate('See_More') }}</a>
                            @endif
                        </p>
                        @if(count($review->attachment_full_url) > 0)
                            <div class="d-flex flex-wrap gap-3 align-items-center mt-2">
                                @foreach ($review->attachment_full_url as $img)
                                    <a href="{{ getStorageImages(path: $img, type: 'backend-basic') }}"
                                       data-lightbox="review_attachment{{ $review['id'] }}">
                                        <img class="w-50px aspect-1 rounded"
                                             src="{{ getStorageImages(path: $img, type: 'backend-basic') }}"
                                             alt="{{translate('image')}}">
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Reply Section -->
                @if($review->reply)
                    <div>
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-2">
                            <h4 class="mb-0">{{ translate('Reply') }}</h4>
                            <div class="d-flex gap-2 align-items-center">
                                <span>{{ date('d M, Y', strtotime($review->reply->created_at)) }}</span>
                                <span class="d-none d-sm-block">|</span>
                                <span>{{ date('h:i A', strtotime($review->reply->created_at)) }}</span>
                            </div>
                        </div>
                        <div class="bg-section p-3 p-sm-20 rounded-10">
                            <div class="d-flex gap-2 align-items-center mb-2">
                                @if($review?->product?->added_by == 'seller')
                                    <img class="h-30 aspect-1 rounded-circle"
                                         src="{{ getStorageImages(path: $review?->product?->seller?->shop?->image_full_url, type: 'shop') }}"
                                         alt="">
                                    <span class="fs-14 text-dark fw-medium">
                                    {{ $review?->product?->seller?->shop?->name ?? translate('Not_found') }}
                                </span>
                                @else
                                    <img class="h-30 aspect-1 rounded-circle"
                                         src="{{ getStorageImages(path: getInHouseShopConfig(key: 'image_full_url'), type: 'logo') }}"
                                         alt="">
                                    <span class="fs-14 text-dark fw-medium">
                                    {{ getInHouseShopConfig(key: 'name') }}
                                </span>
                                @endif
                            </div>
                            <p class="short_text_wrapper mb-0">
                                    <span class="short_text" data-maxlength="298"
                                          data-see-more-text="{{ translate('See_More') }}"
                                          data-see-less-text="{{ translate('See_Less') }}">
                                        {{ $review->reply->reply_text }}
                                    </span>

                                @if (Str::length($review?->reply?->reply_text ?? "") > 200)
                                    <a href="javascript:"
                                       class="see_more_btn text-underline text-nowrap">{{ translate('See_More') }}</a>
                                @endif
                            </p>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
