@php
    use Illuminate\Support\Facades\Session;
@endphp
@extends('layouts.admin.app')
@section('title', translate('social_media'))
@section('content')
    @php($direction = Session::get('direction'))
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
           <h1 class="mb-0"> {{ translate('social_media') }}</h1>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <form action="{{ route('admin.pages-and-media.social-media-store') }}" method="post" style="text-align: {{$direction === "rtl" ? 'right' : 'left'}};" id="social-media-links" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation" novalidate="novalidate">
                    @csrf
                    <input type="hidden" name="id" id="id">
                    <div class="mb-3 mb-sm-20">
                        <h2 class="mb-0 text-capitalize">{{ translate('setup_social_media_link') }}</h2>
                        <p class="fs-12 mb-0">{{ translate('here_you_can_add_your_social_media_links_this_will_help_you_to_show_your_social_activity_to_the_customers') }}.</p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label for="name" class="form-label text-capitalize">
                                        {{ translate('select_social_media') }}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Select Social Media" data-bs-title="{{ translate('select_Social_Media') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <select class="custom-select" data-placeholder="Select social media" name="name" data-required-msg="{{ translate('social_media_field_is_required') }}" required>
                                        <option></option>
                                        <option value="instagram">{{ translate('instagram') }}</option>
                                        <option value="facebook">{{ translate('facebook') }}</option>
                                        <option value="twitter">{{ translate('twitter') }}</option>
                                        <option value="linkedin">{{ translate('linkedIn') }}</option>
                                        <option value="pinterest">{{ translate('pinterest') }}</option>
                                        <option value="google-plus">{{ translate('google_plus') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label for="name" class="form-label text-capitalize">
                                       {{ translate('social_media_link') }}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Enter Social Media Link" data-bs-title="{{ translate('enter_Social_Media_Link') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="url" name="link" class="form-control" id="link"
                                           data-required-msg="{{ translate('social_media_link_is_required') }}"
                                       placeholder="{{ translate('enter_Social_Media_Link') }}" required>
                                </div>
                            </div>
                            <div class="col-12">

                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-sm-20 gap-3 justify-content-end flex-wrap">
                        <button type="reset"  class="btn btn-secondary px-4 w-120 reset-form">{{ translate('reset') }}</button>
                        <button type="submit" id="actionBtn" class="btn btn-primary px-4 w-120">{{ translate('save') }}</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-body d-flex flex-column gap-20">
                <h3 class="mb-0 text-capitalize">{{ translate('social_media_link_list') }}</h3>
                <div class="k-table-wrap">
                    <table class="k-table" id="dataTable">
                        <thead>
                            <tr>
                                <th>{{ translate('sl') }}</th>
                                <th>{{ translate('name') }}</th>
                                <th>{{ translate('social_media_link') }}</th>
                                <th class="text-center">{{ translate('status') }}</th>
                                <th class="text-center">{{ translate('action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($socialMediaLinks as $key => $socialMediaLink)
                                <tr>
                                    <td class="column_name" data-column_name="sl" data-id="1">{{ $socialMediaLinks->firstItem()+$key }}</td>
                                    <td class="column_name" data-column_name="name" data-id="1">{{ $socialMediaLink->name }}</td>
                                    <td class="column_name" data-column_name="slug" data-id="1">{{ $socialMediaLink->link }}</td>
                                    <td class="column_name" data-column_name="status" data-id="1">
                                        <form action="{{route('admin.pages-and-media.social-media-status-update', ['id'=>$socialMediaLink['id']]) }}" method="post" id="social-media-status{{ $socialMediaLink->id }}-form" class="">
                                            @csrf
                                            <label class="switcher mx-auto" for="social-media-status{{ $socialMediaLink->id }}">
                                                <input
                                                    class="switcher_input custom-modal-plugin"
                                                    type="checkbox" value="1" name="status"
                                                    id="social-media-status{{ $socialMediaLink->id }}"
                                                    {{ $socialMediaLink->active_status == 1 ? 'checked' : ''}}
                                                    data-modal-type="input-change-form"
                                                    data-modal-form="#social-media-status{{ $socialMediaLink->id }}-form"
                                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-status-on.png') }}"
                                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-status-off.png') }}"
                                                    data-on-title="{{ translate('Turn_On_') }} {{ $socialMediaLink->name }}"
                                                    data-off-title="{{ translate('Turn_Off_') }}{{ $socialMediaLink->name }}"
                                                    data-on-message = "<p>{{translate('if_you_enable_this_') }} {{ $socialMediaLink->name }} {{ translate('_will_be_shown_in_the_user_app_and_website') }}</p>"
                                                    data-off-message = "<p>{{translate('if_you_enable_this_') }} {{ $socialMediaLink->name }} {{ translate('_will_not_be_shown_in_the_user_app_and_website') }}</p>"
                                                    data-on-button-text = "{{translate('yes_turn_on')}}"
                                                    data-off-button-text = "{{translate('yes_turn_off')}}"
                                                    data-no-button-text = "{{translate('no')}}">
                                                <span class="switcher_control"></span>
                                            </label>
                                        </form>
                                    </td>
                                    <td>
                                        <div class="d-flex justify-content-center gap-3">
                                            <a class="btn btn-outline-info icon-btn edit"
                                                    data-bs-toggle="offcanvas" data-bs-target="#offcanvasSocialMediaEdit-{{ $socialMediaLink['id'] }}"
                                                    title="{{ translate('edit')}}"
                                                    data-id=""
                                            >
                                                <i class="fi fi-sr-pencil"></i>
                                            </a>
                                            <a class="btn btn-outline-danger icon-btn"
                                               title="{{ translate('delete')}}"
                                               data-id="{{$socialMediaLink['id']}}"
                                               data-bs-toggle="modal"
                                               data-bs-target="#deleteModal{{$socialMediaLink['id']}}"
                                            >
                                                <i class="fi fi-rr-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="table-responsive mt-4">
                    <div class="px-4 d-flex justify-content-lg-end">
                        {{ $socialMediaLinks->links() }}
                    </div>
                </div>
                @if(count($socialMediaLinks)==0)
                    @include('layouts.admin.partials._empty-state-svg',['text'=>'no_social_media_link_list'],['image'=>'social-media-links'])
                @endif
            </div>
        </div>
    </div>

    @foreach($socialMediaLinks as $key=> $socialMediaLink)
        @include("admin-views.pages-and-media.social-media.partials._edit-offcanvas", ['socialMediaLink' => $socialMediaLink])
    @endforeach

    @include("layouts.admin.partials.offcanvas._social-media-links-setup")
@endsection
@push('script')
     <script src="{{dynamicAsset(path: 'public/assets/new/back-end/js/social-media.js') }}"></script>
@endpush
