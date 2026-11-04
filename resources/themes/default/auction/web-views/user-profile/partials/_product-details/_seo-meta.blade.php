@if($isOwner && $auctionProduct->seo)
    <div class="border-top pt-30 mt-30">
        <h4 class="fs-16 mb-15 title-clr fw-bold">{{ translate('Product SEO & Meta data') }}</h4>
        <div class="mb-15">
            <h5 class="fs-14 mb-1 title-clr fw-semibold">
                {{ $auctionProduct->seo->title ?? translate('No_SEO_Title_Available') }}
            </h5>
            <p class="fs-14 mb-0 pragraph-clr2 fw-normal">
                {{ $auctionProduct->seo->description ?? translate('No_SEO_Description_Available') }}
            </p>
        </div>
        @if($metaImageUrl)
            <div class="row g-3">
                <div class="col-lg-4 col-md-4 col-6">
                    <div class="position-relative h-100px w-100 bs-border rounded overflow-hidden">
                        <img src="{{ $metaImageUrl }}" alt="{{ $auctionProduct->seo->title }}"
                             class="object-cover-center">
                    </div>
                </div>
            </div>
        @endif
    </div>
@endif
