<div class="text-center p-4">
    <img class="mb-3 w-{{ $width ?? 160 }}" alt="{{ translate('image_description') }}"
         src="{{dynamicAsset(path: 'public/assets/new/back-end/img/empty-state-icon/'.$image.'.png')}}">
    <p class="mb-0">{{ translate($text) }}</p>
    @if($button ?? false)
        <a href="{{ $route }}" class="btn btn-primary mt-3">
            <i class="fi fi-sr-add"></i> {{ translate($buttonText) }}
        </a>
    @endif
</div>
