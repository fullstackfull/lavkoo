{{--
    The optional phone-shaped image, offered the same way wherever one exists.

    A picture that reads on a wide storefront is often unreadable once the app draws it in a small
    tile, so the merchant may upload a second one for the apps. Leaving it empty is the normal case:
    the apps fall back to the web image, which is why removing one is safe and why the checkbox
    below exists at all — an upload field can only replace, never take away.

    Parameters:
      $storedImage   the stored file name, or null. Decides whether the preview and the remove
                     checkbox are shown at all.
      $previewUrl    a resolved url for the preview, or null.
      $inputId       unique element id on this page.
      $hint          one line under the field saying where this image is used.
      $ratio         optional wrapper class for the preview box (default 1:1).
--}}
@php
    $previewUrl = $previewUrl ?? null;
    $ratio = $ratio ?? '';
    $inputId = $inputId ?? 'mobile-image';
@endphp

<div class="text-center">
    <label for="{{ $inputId }}" class="form-label fw-semibold mb-1">
        {{ translate('mobile_app_image') }}
        <span class="text-muted fs-12">({{ translate('optional') }})</span>
    </label>
</div>

<div class="upload-file">
    <input type="file" name="mobile_image" id="{{ $inputId }}"
           class="upload-file__input single_file_input"
           data-max-size="{{ getFileUploadMaxSize() }}"
           accept="{{ getFileUploadFormats(skip: '.svg') }}">

    <label class="upload-file__wrapper {{ $ratio }}">
        <div class="upload-file-textbox text-center">
            <img width="34" height="34" class="svg"
                 src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"
                 alt="image upload">
            <h6 class="mt-1 fw-medium lh-base text-center">
                <span class="text-info">{{ translate('Click_to_upload') }}</span>
                <br>
                {{ translate('or_drag_and_drop') }}
            </h6>
        </div>
        <img class="upload-file-img" loading="lazy"
             src="{{ $storedImage ? $previewUrl : '' }}"
             data-default-src="{{ $storedImage ? $previewUrl : '' }}"
             alt="">
    </label>

    <div class="overlay">
        <div class="d-flex gap-10 justify-content-center align-items-center h-100">
            <button type="button" class="btn btn-outline-info icon-btn view_btn">
                <i class="fi fi-sr-eye"></i>
            </button>
            <button type="button" class="btn btn-outline-info icon-btn edit_btn">
                <i class="fi fi-rr-camera"></i>
            </button>
        </div>
    </div>
</div>

<p class="fs-12 text-center max-w-360 m-auto">{{ $hint ?? '' }}</p>

@if ($storedImage)
    <div class="d-flex justify-content-center align-items-center gap-2 mt-2">
        <input class="form-check-input m-0" type="checkbox" name="remove_mobile_image" value="1"
               id="remove-{{ $inputId }}">
        <label class="form-check-label fs-12 m-0" for="remove-{{ $inputId }}">
            {{ translate('remove_the_mobile_image_and_use_the_web_image') }}
        </label>
    </div>
@endif
