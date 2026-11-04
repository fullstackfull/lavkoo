@if(count($products) > 0)
    @php($decimal_point_settings = getWebConfig(name: 'decimal_point_settings'))
    @foreach($products as $auctionProduct)
        <div class="col-6 col-md-4 col-lg-4 col-xl-3">
            @include('auction.web-views.partials._auction-product-card-5', ['auctionProduct' => $auctionProduct])
        </div>
    @endforeach

    <div class="col-12">
        <nav class="d-flex justify-content-between pt-2" aria-label="Page navigation"
             id="paginator-ajax">
            {!! $products->links() !!}
        </nav>
    </div>
@else
    <div class="d-flex justify-content-center align-items-center w-100 py-5">
        <div>
            <img src="{{ theme_asset(path: 'public/assets/front-end/img/media/product.svg') }}" class="img-fluid" alt="">
            <h6 class="text-muted">{{ translate('no_product_found') }}</h6>
        </div>
    </div>
@endif
