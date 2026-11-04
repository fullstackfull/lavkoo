@if(!empty($authorContext))
<div class="card border-0 shadow-sm mb-20">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div class="d-flex gap-10px align-items-center">
                @if(!empty($authorContext['url']))
                    <div class="rounded w-60px h-60px min-w-60px overflow-hidden text-center rounded-circle d-block flex-shrink-0">
                        <img src="{{ $authorContext['image'] }}" alt="{{ $authorContext['name'] }}" class="w-100 h-100 object-cover">
                    </div>
                @else
                    <div class="rounded w-60px h-60px min-w-60px overflow-hidden text-center rounded-circle flex-shrink-0">
                        <img src="{{ $authorContext['image'] }}" alt="{{ $authorContext['name'] }}" class="w-100 h-100 object-cover">
                    </div>
                @endif
                <div>
                    @if(!empty($authorContext['url']))
                        <div class="text-decoration-none">
                            <h6 class="mb-1 fs-16 text-capitalize fw-semibold title-clr line--limit-1">{{ $authorContext['name'] }}</h6>
                        </div>
                    @else
                        <h6 class="mb-1 fs-16 text-capitalize fw-semibold title-clr line--limit-1">{{ $authorContext['name'] }}</h6>
                    @endif
                    <p class="m-0 fs-14 title-clr">{{ translate('Auction Author') }}</p>
                </div>
            </div>
            @if($authorContext['owner_type'] !== 'customer')
                @if(auth('customer')->check())
                    <button type="button"
                            class="btn btn-primary fs-12 d-flex align-items-center gap-2 px-3"
                            data-bs-toggle="modal" data-bs-target="#chatting_modal">
                        <i class="fi fi-rr-comment fs-14"></i>
                    </button>
                @else
                    <a href="{{ route('customer.auth.login') }}"
                       class="btn btn-primary fs-12 d-flex align-items-center gap-2 px-3">
                        <i class="fi fi-rr-comment fs-14"></i>
                    </a>
                @endif
            @endif
        </div>
    </div>
</div>
@endif

<div class="card border-0 shadow-sm mb-20">
    <div class="card-body">
        <div class="row g-md-3 g-2 align-items-center">
            <div class="col-md-3">
                <div>
                    <h2 class="fw-bold title-clr mb-2 fs-5">
                        @if(isset($pageTitleContent) && $pageTitleContent)
                            {{ $pageTitleContent }}
                        @else
                            {{ translate('Products') }}
                        @endif
                    </h2>
                    <p class="m-0 fs-14">
                        <span id="auction-products-count-number">{{ $products->total() }}</span>
                        <span id="auction-products-count-label"
                              data-singular="{{ translate('item found') }}"
                              data-plural="{{ translate('items found') }}">{{ $products->total() > 1 ? translate('items found') : translate('item found') }}</span>
                    </p>
                </div>
            </div>
            <div class="col-md-9">
                <div class="d-flex flex-lg-nowrap justify-content-md-end flex-wrap gap-xxl-20 gap-2">
                    <div class="mobile-full">
                        <div class="d-flex gap-2 align-items-center">
                            <div class="form-auction overflow-hidden w-xxl-400 rounded border d-flex align-items-center position-relative bg-white flex-grow-1 flex-grow-lg-0 search-header-product">
                                <input class="form-control bg-white rounded-0 border-0 product-list-filter-input auction-live-search-input" type="search" placeholder="{{ translate('Search_by_auction_name') }}" name="search" value="{{ request('search') }}" autocomplete="off">
                                <button class="btn btn-primary border-0 min-h-45 px-3 rounded-0 d-center" type="submit">
                                    <i class="fi fi-rr-search"></i>
                                </button>
                            </div>
                            <div class="d-lg-none d-block">
                                <button type="button" class="btn btn-primary min-h-45 border-0 px-12px" data-bs-toggle="offcanvas" data-bs-target="#searchProduct-filer">
                                    <i class="fi fi-rr-bars-filter"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="min-h-45 w-md-100 d-flex align-items-center gap-10px border rounded ps-3">
                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sorting.svg') }}" alt="sorting-img">
                        <div class="sorting-item w-md-100 d-flex align-items-center">
                            <label class="for-sorting fs-13 text-nowrap">{{ translate('Sort By') }}:</label>
                            <select class="product-list-filter-input text-wrap border-0 shadow-none outline-0 rounded-0 fs-13 form-select" name="sort_by">
                                <option value="recent_created" {{ in_array(request('sort_by'), ['', 'recent_created'], true) ? 'selected' : '' }}>{{ translate('Recent_Created') }}</option>
                                <option value="low_to_high" {{ request('sort_by') === 'low_to_high' ? 'selected' : '' }}>{{ translate('Auction Price (Low to High)') }}</option>
                                <option value="high_to_low" {{ request('sort_by') === 'high_to_low' ? 'selected' : '' }}>{{ translate('Auction Price (High to Low)') }}</option>
                                @if(!request()->routeIs('auction.upcoming-products'))
                                <option value="lowest_to_highest" {{ request('sort_by') === 'lowest_to_highest' ? 'selected' : '' }}>{{ translate('Bid (Low to High)') }}</option>
                                <option value="highest_to_lowest" {{ request('sort_by') === 'highest_to_lowest' ? 'selected' : '' }}>{{ translate('Bid (High to Low)') }}</option>
                                @endif
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

 <div class="offcanvas offcanvas-end" tabindex="-1" id="searchProduct-filer" aria-labelledby="searchProduct-filer">
  <div class="offcanvas-header pb-0">
    <h4 class="offcanvas-title fs-17">{{ translate('Products Filter') }}</h4>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="{{ translate('Close') }}"></button>
  </div>
  <div class="offcanvas-body">
    <h5 class="fs-16 fw-semibold title-clr py-12px px-10px border-bottom mb-20">{{ translate('Filter_By') }}</h5>
      <div class="p-10px pt-0">
          <div class="d-flex flex-column gap-20px">
              @include('auction.web-views.product.partials._filter-ending-time')
              @include('auction.web-views.product.partials._filter-entry-fee')
              @include('auction.web-views.product.partials._filter-product-price')
              @if(!request()->routeIs('auction.ending-soon-products', 'auction.trending-products', 'auction.upcoming-products'))
                  @include('auction.web-views.product.partials._filter-auction-istings')
              @endif
              @include('auction.web-views.product.partials._filter-product-categories', [
                  'productCategories' => $categories,
                  'dataFrom' => request('data_from'),
              ])
              @include('auction.web-views.product.partials._filter-product-brands', [
                  'productBrands' => $activeBrands,
                  'dataFrom' => request('data_from'),
              ])
          </div>
      </div>
  </div>
</div>
