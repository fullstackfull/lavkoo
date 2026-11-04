<?php
    $fallbackThumbPath = getStorageImages(path: $auctionProduct->thumbnail_full_url, type: 'product');
?>
<div class="pd-img-wrap position-relative max-w-200px max-w-100-mobile">
    <div class="w-100 d-flex justify-content-center">
        <div class="swiper-container quickviewSlider2 active-border border rounded aspect--1 inline-size-100 border--gray">
            <div class="swiper-wrapper">
                @if(isset($imageSources) && count($imageSources) > 0)
                    @php($isSingleImage = count($imageSources) === 1)
                    @foreach($imageSources as $photo)
                        <?php
                            $imagePath = isset($photo['image_name'])
                                ? getStorageImages(path: $photo['image_name'], type: 'backend-product')
                                : getStorageImages(path: $photo, type: 'backend-product');
                        ?>
                        <div class="swiper-slide position-relative rounded aspect--1">
                            @if($isSingleImage)
                                <img class="dark-support rounded" alt="{{ $auctionProduct->name }}"
                                        src="{{ $imagePath }}">
                            @else
                                <div class="easyzoom easyzoom--overlay">
                                    <a href="{{ $imagePath }}">
                                        <img class="dark-support rounded" alt="{{ $auctionProduct->name }}"
                                                src="{{ $imagePath }}">
                                    </a>
                                </div>
                            @endif
                        </div>
                    @endforeach
                @else
                    <div class="swiper-slide position-relative rounded aspect--1">
                        <img class="dark-support rounded" alt="{{ $auctionProduct->name }}"
                                src="{{ $fallbackThumbPath }}">
                    </div>
                @endif
            </div>
        </div>
    </div>
    @if(isset($imageSources) && count($imageSources) > 1)
    <div class="mt-2 user-select-none position-relative">
        <div class="quickviewSliderThumb2 swiper-container active-border position-relative w-100">
            <div class="swiper-wrapper auto-item-width justify-content-start border--gray width--4rem">
                @foreach($imageSources as $photo)
                    <?php
                        $imagePath = isset($photo['image_name'])
                            ? getStorageImages(path: $photo['image_name'], type: 'backend-product')
                            : getStorageImages(path: $photo, type: 'backend-product');
                    ?>
                    <div class="swiper-slide position-relative rounded border">
                        <img class="dark-support rounded" alt="{{ $auctionProduct->name }}"
                                src="{{ $imagePath }}">
                    </div>
                @endforeach
            </div>
        </div>
        <div class="swiper-button-prev swiper-quickview-button-prev size-1-5rem"></div>
        <div class="swiper-button-next swiper-quickview-button-next size-1-5rem"></div>
    </div>
    @endif
</div>
