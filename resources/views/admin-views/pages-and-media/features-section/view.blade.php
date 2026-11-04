@extends('layouts.admin.app')

@section('title', translate('features_Section'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/Pages.png')}}" alt="">
                {{ translate('pages') }}
            </h2>
        </div>
        @include('admin-views.pages-and-media._pages-and-media-inline-menu')
        <form action="{{ route('admin.pages-and-media.features-section.submit') }}" method="POST"
              enctype="multipart/form-data">
            @csrf
            <div class="row">

                <div class="col-md-12 mb-3">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between">
                            <h5 class="mb-0 mt-3">{{ translate('features_Section').' '.'-'.' '.translate('bottom') }}</h5>
                            <span id="add-this-features-card-bottom" class="btn btn-primary text-capitalize">
                                <i class="tio-add pe-2"></i>{{ translate('add_new') }}
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row" id="features-Section-bottom-row">
                                @if (!empty($featuresSectionBottom) )
                                    @forelse (json_decode($featuresSectionBottom->value) as $key => $item)
                                        @php($card_index = rand(1111, 9999))
                                        <div class="col-sm-12 col-md-3 mb-4">
                                            <div class="card">
                                                <div class="d-flex card-header align-items-center justify-content-between">
                                                    <h4 class="m-0 text-muted">{{ translate('icon_box') }}</h4>
                                                    <a class="btn btn-outline-danger icon-btn remove_icon_box_with_titles"
                                                        data-title="{{ $item->title }}" data-subtitle="{{ $item->subtitle }}">
                                                        <i class="fi fi-rr-trash"></i>
                                                    </a>
                                                </div>
                                                <div class="card-body">
                                                    <div class="mb-3">
                                                        <label for="title" class="form-label text-capitalize">{{ translate('Title') }}</label>
                                                        <input type="text" class="form-control" disabled
                                                               value="{{ $item->title }}"
                                                               name="icontitle"
                                                               placeholder="{{ translate('type_your_title_text') }}">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="title" class="form-label text-capitalize">{{ translate('Sub_Title') }}</label>
                                                        <textarea class="form-control" disabled
                                                                  placeholder="{{ translate('type_your_subtitle_text') }}">{{ $item->subtitle }}</textarea>
                                                    </div>

                                                    <div class="mb-2 d-flex">
                                                        <div class="m-auto upload-file__wrapper">
                                                                <?php
                                                                $imageArray = [
                                                                    'image_name' => $item?->icon->image_name ?? $item?->icon,
                                                                    'storage' => $item?->icon?->storage ?? 'public',
                                                                ];
                                                                $imagePath = storageLink('banner', $imageArray['image_name'], $imageArray['storage']);
                                                                ?>
                                                            <img id="pre_img_header_logo{{ $card_index }}"
                                                                 src="{{ getStorageImages(path: $imagePath, type: 'backend-basic') }}"
                                                                 class="img-fit" alt="">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="col-sm-12 col-md-3 mb-4 remove-this-features-card-div">
                                            <div class="card">
                                                <div class="card-header align-items-center justify-content-between">
                                                    <h4 class="m-0 text-muted">{{ translate('icon_box') }}</h4>
                                                    <div class="cursor-pointer remove-this-features-card">
                                                <span class="btn btn-outline-danger icon-btn">
                                                    <i class="fi fi-rr-trash"></i>
                                                </span>
                                                    </div>
                                                </div>
                                                <div class="card-body">
                                                    <div class="mb-3">
                                                        <label for="title" class="form-label text-capitalize">{{ translate('Title') }}</label>
                                                        <input type="text" class="form-control"
                                                               name="features_section_bottom[title][]"
                                                               value="" required
                                                               placeholder="{{ translate('type_your_title_text') }}">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label for="title" class="form-label text-capitalize">{{ translate('Sub_Title') }}</label>
                                                        <textarea class="form-control"
                                                                  name="features_section_bottom[subtitle][]" required
                                                                  placeholder="{{ translate('type_your_subtitle_text') }}"></textarea>
                                                    </div>

                                                    <div class="custom_upload_input">
                                                        <input type="file" name="features_section_bottom_icon[]"
                                                               class="custom-upload-input-file aspect-ratio-3-15 upload-color-image"
                                                               data-imgpreview="pre_img_header_logo"
                                                               accept=".jpg, .png, .jpeg, .gif">
                                                        <span class="delete-file-input btn btn-outline-danger icon-btn d-none">
                                                    <i class="fi fi-rr-trash"></i>
                                                </span>

                                                        <div class="img_area_with_preview position-absolute z-index-2">
                                                            <img id="pre_img_header_logo"
                                                                 class="h-auto aspect-ratio-3-15 bg-white"
                                                                 onerror="this.classList.add('d-none')" src="" alt="">
                                                        </div>
                                                        <div class="position-absolute h-100 top-0 w-100 d-flex align-content-center justify-content-center">
                                                            <div class="d-flex flex-column justify-content-center align-items-center">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}"
                                                                     class="w-50" alt="">
                                                                <h3 class="text-muted text-capitalize">{{ translate('upload_icon') }}</h3>
                                                            </div>
                                                        </div>
                                                    </div>

                                                </div>
                                            </div>
                                        </div>
                                    @endforelse
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        {{ translate('submit') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
    <span id="get-feature-section-append-translate-text"
          data-title="{{translate('title')}}"
          data-title-placeholder="{{translate('type_your_title_text')}}"
          data-sub-title="{{translate('sub-title')}}"
          data-sub-title-placeholder="{{translate('type_your_title_text')}}"
          data-icon-box="{{translate('icon_box')}}"
          data-upload-icon="{{translate('upload_icon')}}">
    </span>
    <span id="get-feature-section-icon-remove-route"
          data-action="{{ route('admin.pages-and-media.features-section.icon-remove') }}"></span>

      <span class="get-upload-icon"
          data-upload-icon="{{ dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg') }}"></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/admin/features-and-company-reliability-section.js') }}"></script>
@endpush
