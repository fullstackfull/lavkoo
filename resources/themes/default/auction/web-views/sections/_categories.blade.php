@if(isset($auctionCategories) && count($auctionCategories) > 0)
    <div class="categories-section overflow-hidden position-relative mb-20">
        <div class="section-card-margin">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <h2 class="m-0 text-capitalize fw-bold fs-5 d-flex align-items-center gap-1">
                    <span class="line--limit-1">{{ translate('Categories') }}</span>
                </h2>
            </div>
            <div class="categories-boxes position-relative owl-nav-center">
                <div class="owl-carousel categories-product-slidewrap px-xl-0 px-4 owl-theme" data-slide-items="4">
                    @foreach($auctionCategories as $category)
                        <div class="item pb-2">
                            <div
                                class="categories-card card-hover-shadow bg-white border-0 p-12px shadow-sm rounded-10 position-relative">
                                <div class="d-flex align-items-center gap-10px">
                                    <div class="rounded overflow-hidden w-60px h-60px min-w-60px">
                                        <a href="{{ route('auction.category-products', ['slug' => $category->slug]) }}" class="m-thumbnail d-block">
                                            <img alt="{{ $category['name'] }}" class="w-100 h-100"
                                                src="{{ getStorageImages(path: $category->icon_full_url, type: 'category') }}">
                                        </a>
                                    </div>
                                    <div class="">
                                        <h6 class="mb-2">
                                            <a href="{{ route('auction.category-products', ['slug' => $category->slug]) }}"
                                               class="text-decoration-none fs-16 text-capitalize fw-semibold title-clr line--limit-1">
                                                {{ $category['name'] }}
                                            </a>
                                        </h6>
                                        <div class="d-flex justify-content-start gap-2 align-items-center">
                                            <span class="title-clr fs-14">
                                                {{ $category?->auction_product_count ?? 0 }}
                                            </span>
                                            <span class="fs-14 title-semidark">
                                                {{ $category?->auction_product_count > 1 ? translate('Auctions') : translate('Auction') }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endif
