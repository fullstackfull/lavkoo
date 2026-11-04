@if(count($companyReliability) > 0)
<div class="container rtl pb-4 px-0 px-md-3">
    <div class="shipping-policy-web">
        <div class="footer-top-slider owl-theme owl-carousel" data-slide-items="4">
            @foreach ($companyReliability as $key=>$value)
                @if ($value['status'] == 1 && !empty($value['title']))
                    <div class="footer-top-slide-item">
                        <div class="d-flex justify-content-center">
                            <div class="shipping-method-system">
                                <div class="shopping-method-icon d-flex mx-auto justify-content-center bg-white rounded-circle mb-20 d-center">
                                    <img loading="lazy" alt="{{ $value['title'] }}" class="object-contain" width="88" height="88" src="{{ getStorageImages(path: imagePathProcessing(imageData: $value['image'],path: 'company-reliability'), type: 'source', source: 'public/assets/front-end/img'.'/'.$value['item'].'.png') }}">
                                </div>
                                <div class="w-100 text-center">
                                    <p class="m-0">{{ $value['title'] }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</div>
@endif
