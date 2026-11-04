<div class="pd-img-wrap position-relative h-100">
    <div class="w-100 d-flex justify-content-center">
        <div class="swiper-container quickviewSlider2 active-border border rounded aspect--1 inline-size-100 border--gray">
            <div class="m-lg-3 m-2 d-flex social-badge gap-10px z-2 position-absolute top-0 end-0">
                <div class="share_dropdown_wrapper">
                    <button type="button"
                            class="btn d-center btn-outline-primary rounded-circle w-35px h-35px min-w-35px p-0 share_btn"
                            style="--size: 35px" tabindex="0">
                        <i class="fi fi-sr-share fs-14"></i>
                    </button>
                    <div class="share_dropdown bg-white d-flex gap-2 align-items-center flex-column">
                        <a href="#" class="bg-facebook share-on-social-media facebook d-center"
                            data-action="{{ $productDetailUrl }}"
                            data-social-media-name="facebook.com/sharer/sharer.php?u=">
                            <i class="fi fi-brands-facebook"></i>
                        </a>
                        <a href="#" class="bg-twitter share-on-social-media twitter d-center"
                            data-action="{{ $productDetailUrl }}"
                            data-social-media-name="twitter.com/intent/tweet?text=">
                            <i class="fi fi-brands-twitter-alt"></i>
                        </a>
                        <a href="#" class="bg-linkedin share-on-social-media linkedin d-center"
                            data-action="{{ $productDetailUrl }}"
                            data-social-media-name="linkedin.com/shareArticle?mini=true&url=">
                            <i class="fi fi-brands-linkedin"></i>
                        </a>
                        <a href="#" class="bg-whatsapp share-on-social-media whatsapp d-center"
                            data-action="{{ $productDetailUrl }}"
                            data-social-media-name="api.whatsapp.com/send?text=">
                            <i class="fi fi-brands-whatsapp"></i>
                        </a>
                    </div>
                </div>
            </div>

            @if(isset($imageSources) && count($imageSources)>0)
                <div class="swiper-wrapper">
                    @foreach($imageSources as $photo)
                        @php
                            $imagePath = isset($photo['image_name'])
                                ? getStorageImages(path: $photo['image_name'], type: 'backend-product')
                                : getStorageImages(path: $photo, type: 'backend-product');
                        @endphp
                        <div class="swiper-slide position-relative rounded aspect--1">
                            <div class="easyzoom easyzoom--overlay">
                                <a href="{{ $imagePath }}">
                                    <img class="dark-support rounded" alt="{{ $auctionProduct->name }}"
                                            src="{{ $imagePath }}">
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    <div class="mt-2 user-select-none position-relative">
        <div class="quickviewSliderThumb2 swiper-container active-border position-relative w-100">
            @if(isset($imageSources) && count($imageSources)>0)
                <div class="swiper-wrapper auto-item-width justify-content-start border--gray width--4rem">
                    @foreach($imageSources as $photo)
                        @php
                            $imagePath = isset($photo['image_name'])
                                ? getStorageImages(path: $photo['image_name'], type: 'backend-product')
                                : getStorageImages(path: $photo, type: 'backend-product');
                        @endphp
                        <div class="swiper-slide position-relative rounded border">
                            <img class="dark-support rounded" alt="{{ $auctionProduct->name }}"
                                    src="{{ $imagePath }}">
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="swiper-button-prev swiper-quickview-button-prev size-1-5rem"></div>
        <div class="swiper-button-next swiper-quickview-button-next size-1-5rem"></div>
    </div>
</div>