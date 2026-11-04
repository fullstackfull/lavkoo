@extends('layouts.admin.app')

@section('title', translate('update_Notification'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/push_notification.png')}}" alt="">
                {{translate('push_notification_update')}}
            </h2>
        </div>
        <div class="card">
            <div class="card-body">
                <form action="{{route('admin.notification.update',[$notification['id']])}}" method="post" class="text-start form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate"
                        enctype="multipart/form-data" novalidate="novalidate">
                    @csrf
                    <div class="row gy-4">
                        <div class="col-md-6">
                            <div class="h-100">
                                <div class="form-group">
                                    <label class="form-label" for="exampleFormControlInput1">{{translate('title')}}</label>
                                    <input type="text" value="{{$notification['title']}}" name="title" class="form-control"
                                            placeholder="{{translate('new_notification')}}" required>
                                </div>
                                <div class="form-group mb-0">
                                    <label class="form-label" for="exampleFormControlInput1">{{translate('description')}}</label>
                                    <textarea name="description" class="form-control text-area-max-min" rows="4"
                                                required>{{$notification['description']}}</textarea>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex justify-content-center align-items-center bg-section rounded-8 p-20 w-100 h-100">
                                <div class="d-flex flex-column gap-30 w-100">
                                    <div class="text-center">
                                        <label for="" class="form-label fw-semibold mb-0">
                                            {{translate('image')}} <span class="text-info-dark">({{ translate('Ratio') . " " . '1:1' }})</span>
                                        </label>
                                    </div>
                                    <div class="upload-file">
                                        <input type="file" name="image" class="upload-file__input single_file_input"
                                               data-max-size="{{ getFileUploadMaxSize() }}"
                                               accept="{{getFileUploadFormats(skip: '.svg,.gif')}}"   value="">
                                        <label
                                            class="upload-file__wrapper">
                                            <div class="upload-file-textbox text-center">
                                                <img width="34" height="34" class="svg" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg')}}" alt="image upload">
                                                <h6 class="mt-1 fw-medium lh-base text-center">
                                                    <span class="text-info">{{ translate('Click to upload') }}</span>
                                                    <br>
                                                    {{ translate('or drag and drop') }}
                                                </h6>
                                            </div>
                                            <img class="upload-file-img" loading="lazy"
                                                src="{{ getStorageImages(path: $notification->image_full_url, type: 'backend-basic') }}"
                                                data-default-src="{{ getStorageImages(path: $notification->image_full_url, type: 'backend-basic') }}"
                                                alt=""
                                                >
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
                                    <p class="fs-12 mb-0 text-center max-w-360 m-auto">
                                        {{ getFileUploadFormats(skip: '.svg, .gif', asBladeMessage: true).' '. translate('Image_size'). ' : '. translate('Max').' '. getFileUploadMaxSize() . 'MB' }}
                                        <span class="fw-medium">(1:1)</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-end flex-wrap gap-3">
                                <button type="reset" class="btn btn-secondary">{{translate('reset')}}</button>
                                <button type="submit" class="btn btn-primary">{{translate('update')}}</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
