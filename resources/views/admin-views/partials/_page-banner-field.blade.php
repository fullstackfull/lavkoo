{{-- Optional landing-page banner, editable right where the category / brand itself is edited.
     Saved as the entity's 'Category Banner' / 'Brand Banner' row in Promotion -> Banners, so both
     doors edit the same record. Pass $currentBanner (nullable App\Models\Banner). --}}
@php($__currentBannerUrl = isset($currentBanner) && $currentBanner
    ? getStorageImages(path: $currentBanner->photo_full_url, type: 'banner')
    : '')

<div class="p-12 p-sm-20 bg-section rounded mt-3">
    <div class="d-flex flex-column gap-20 text-center">
        <div>
            <label class="form-label fw-semibold mb-1">{{ translate('page_banner') }} <span class="text-muted fw-normal">({{ translate('optional') }})</span></label>
            <p class="fs-12 mb-0">{{ translate('shown_at_the_top_of_this_page_on_the_storefront') }} — {{ translate('Ratio') }} 4:1</p>
        </div>

        <div class="upload-file">
            <input type="file" name="page_banner" class="upload-file__input single_file_input"
                   accept="{{ getFileUploadFormats(skip:'.svg,.gif') }}"
                   data-max-size="{{ getFileUploadMaxSize() }}">
            <label class="upload-file__wrapper">
                <div class="upload-file-textbox text-center {{ $__currentBannerUrl ? 'd--none' : '' }}">
                    <img width="34" height="34" class="svg" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}" alt="">
                    <h6 class="mt-1 fw-medium lh-base text-center">
                        <span class="text-info">{{ translate('Click_to_upload') }}</span><br>
                        {{ translate('or drag and drop') }}
                    </h6>
                </div>
                <img class="upload-file-img" loading="lazy"
                     src="{{ $__currentBannerUrl }}" data-default-src="{{ $__currentBannerUrl }}" alt="">
            </label>
        </div>

        @if (!empty($currentBanner))
            <p class="fs-10 mb-0">
                {{ translate('also_editable_in_banner_setup') }} —
                <a href="{{ route('admin.banner.update', ['id' => $currentBanner->id]) }}">#{{ $currentBanner->id }}</a>
                @if (!$currentBanner->published)
                    <span class="badge badge-soft-danger">{{ translate('unpublished_will_not_show') }}</span>
                @endif
            </p>
        @endif
    </div>
</div>
