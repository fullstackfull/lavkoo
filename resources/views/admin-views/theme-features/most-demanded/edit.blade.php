@extends('layouts.admin.app')

@section('title', translate('edit_most_demanded'))

@section('content')
    <div class="content container-fluid">

        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex gap-2 text-capitalize">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/most-demanded.png') }}" alt="">
                {{ translate('edit_most_demanded') }}
            </h2>
        </div>

        <div class="row mb-3">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <form action="{{ route('admin.most-demanded.update', ['id'=>$mostDemandedProduct->id]) }}"
                            method="post" class="text-start"
                            enctype="multipart/form-data">
                            @csrf
                            <div class="row g-4">
                                <div class="col-lg-6">
                                    <div class="h-100">
                                        <label for="name" class="form-label">{{ translate('products') }}</label>
                                        <select
                                            class="custom-select"
                                            name="product_id">
                                            <option value="" disabled selected>
                                                {{ translate('select_Product') }}
                                            </option>
                                            @foreach ($products as $key => $product)
                                                <option
                                                    value="{{ $product->id }}"{{ $mostDemandedProduct->product_id == $product->id ?'selected':''}}>
                                                    {{ $product['name'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="d-flex flex-column gap-30 bg-section rounded-8 p-20 w-100 h-100">
                                        <div>
                                            <label for="banner-image" class="form-label fw-semibold mb-1 d-flex align-items-center justify-content-center gap-1">
                                                {{ translate('banner') }}
                                                <span class="text-info-dark">
                                                    ( {{ translate('ratio') }} {{ translate('4') }}:{{ translate('1') }} )
                                                </span>
                                            </label>
                                        </div>
                                        <div class="upload-file">
                                            <input type="file" name="image" id="banner-image" class="upload-file__input single_file_input"
                                                accept=".jpg, .png, .jpeg, .gif">
                                            <label
                                                class="upload-file__wrapper ratio-4-1">
                                                <div class="upload-file-textbox text-center">
                                                    <img width="34" height="34" class="svg" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg')}}" alt="image upload">
                                                    <h6 class="mt-1 fw-medium lh-base text-center">
                                                        <span class="text-info">{{ translate('Click to upload') }}</span>
                                                        <br>
                                                        {{ translate('or drag and drop') }}
                                                    </h6>
                                                </div>
                                                <img class="upload-file-img" loading="lazy"
                                                src="{{ getStorageImages(path:$mostDemandedProduct->banner_full_url,type: 'backend-basic')}}"
                                                data-default-src="{{ getStorageImages(path:$mostDemandedProduct->banner_full_url,type: 'backend-basic')}}" alt="">
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
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end flex-wrap gap-3 mt-4">
                                <button type="reset" id="reset" class="btn btn-secondary px-4">{{ translate('reset') }}</button>
                                <button type="submit" class="btn btn-primary px-4">{{ translate('submit') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
