
<div class="text-center py-5 d-flex align-items-center justify-content-center">
    <div>
        <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/empty-state-icon/'.$image.'.png') }}" alt="" class="mb-3 w-{{ $width ?? '' }}">
        <h5 class="text-9EADC1 m-0 fs-16 font-weight-normal">{{ translate($text) }}</h5>
        @if($button ?? false)
            <a href="{{ $route }}" class="btn btn-primary mt-3">
                <i class="fi fi-sr-add"></i> {{ translate($buttonText) }}
            </a>
        @endif
    </div>
</div>
