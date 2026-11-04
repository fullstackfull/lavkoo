@extends('layouts.admin.app')

@section('title', translate('announcement'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2 text-capitalize">
                <img width="20" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/announcement.png')}}" alt="">
                {{translate('announcement_setup')}}
            </h2>
        </div>
        <form data-k-save-bar action="{{ route('admin.business-settings.announcement') }}" method="post" enctype="multipart/form-data">
            @csrf
            @if (isset($announcement))
                <div class="card h-100">
                    <div class="card-header">
                        <h4 class="mb-0">{{translate('announcement_Setup')}}</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-flex gap-10 align-items-center mb-2">
                            <input type="radio" name="announcement_status" class="form-check-input radio--input"
                                    value="1" {{$announcement['status']==1?'checked':''}}>
                            <label class="form-check-label mb-0">{{translate('active')}}</label>
                        </div>
                        <div class="d-flex gap-10 align-items-center mb-4">
                            <input type="radio" name="announcement_status" class="form-check-input radio--input"
                                    value="0" {{$announcement['status']==0?'checked':''}}>
                            <label class="form-check-label mb-0">{{translate('inactive')}}</label>
                        </div>
                        <div class="row g-4 mb-4">
                            <div class="col-lg-3">
                                <div class="form-group">
                                    <label class="form-label">{{translate('background_color')}}</label>
                                    <div class="d-flex align-items-center gap-10 bg-white border rounded py-2 px-10">
                                        <input type="color" name="announcement_color" value="{{ $announcement['color'] }}" id="background-color" class="form-control form-control_color color-code-preview">
                                        <span class="fs-14 fw-medium text-dark color-code color-code-selection">{{ $announcement['color'] }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-3">
                                <div class="form-group">
                                    <label class="form-label">{{translate('text_color')}}</label>
                                    <div class="d-flex align-items-center gap-10 bg-white border rounded py-2 px-10">
                                        <input type="color" name="text_color" value="{{ $announcement['text_color'] }}" id="text_color" class="form-control form-control_color color-code-preview">
                                        <span class="fs-14 fw-medium text-dark color-code color-code-selection">{{ $announcement['text_color'] }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-group mb-4">
                            <label class="form-label">{{translate('text')}}</label>
                            <input class="form-control" type="text" name="announcement"
                                    value="{{ $announcement['announcement'] }}">
                        </div>
                        <div class="justify-content-end d-flex">
                            <button type="submit" class="btn btn-primary px-4">{{translate('publish')}}</button>
                        </div>
                    </div>
                </div>
            @endif
            <x-k.save-bar />
        </form>
    </div>
@endsection

@push('script_2')
    <script src="{{dynamicAsset(path: 'public/assets/backend/admin/js/business-settings/business-general-setting.js')}}"></script>
@endpush
