@php
    $companyName = getWebConfig(name: 'company_name');
    $companyLogo = getWebConfig(name: 'company_web_logo');
    $title = $template['title'] ?? null;
    $body = $template['body'] ?? null;
    $copyrightText = $template['copyright_text'] ?? null;
    $footerText = $template['footer_text'] ?? null;
    $buttonName = $template['button_name'] ?? null;
    foreach ($template?->translationCurrentLanguage ?? [] as $translate) {
       $title = $translate->key == 'title' ? $translate->value : $title;
       $body = $translate->key == 'body' ? $translate->value : $body;
       $copyrightText = $translate->key == 'copyright_text' ? $translate->value : $copyrightText;
       $footerText = $translate->key == 'footer_text' ? $translate->value : $footerText;
       $buttonName = $translate->key == 'button_name' ? $translate->value : $buttonName;
    }
@endphp
@extends('layouts.admin.app')

@section('title', translate('email_templates'))

@push('css_or_js')
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/summernote/summernote-bs5.min.css') }}">
@endpush

@section('content')
    <div class="content container-fluid">
        @include('admin-views.business-settings.email-template.partials.page-title')
        @include('admin-views.business-settings.email-template.partials.'.$template['user_type'].'-mail-inline-menu')
        <div class="">
            @include('admin-views.business-settings.email-template.partials.update-status')
            <div class="card">
                <div class="card-body">
                    <form action="{{ route('admin.system-setup.email-templates.update',[$template['template_name'],$template['user_type']]) }}" method="POST" enctype="multipart/form-data" class="form-advance-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                        @csrf
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="card card-sm shadow-1 h-100">
                                    <div class="card-header">
                                        <h2 class="mb-2">
                                            {{ translate('Editor') }}
                                        </h2>
                                        <p class="fs-12 mb-0"></p>
                                    </div>
                                    <div class="card-body d-flex flex-column gap-3 gap-sm-20">
                                        @include('admin-views.business-settings.email-template.partials.form-field')
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="card card-sm shadow-1 h-100">
                                    <div class="card-header">
                                        <h2 class="mb-2">{{ translate('preview') }}
                                        </h2>
                                        <p class="fs-12 mb-0">{{ translate('this_section_you_can_view_the_mail_preview_after_action_on_editor_section.') }}
                                        </p>
                                    </div>
                                    <div class="card-body">
                                        <div class="bg-section p-12 p-sm-20 rounded">
                                            @include('admin-views.business-settings.email-template.'.$template['user_type'].'-mail-template'.'.'.$template['template_design_name'])
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="d-flex justify-content-end trans3 action-btn-wrapper-container">
                                    <div class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                                        <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('reset') }}</button>
                                        <button type="submit" class="btn btn-primary px-3 px-sm-4">
                                            <i class="fi fi-sr-disk"></i>
                                            {{ translate('save_information') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @include('admin-views.business-settings.email-template.partials.instructions')
    @include("layouts.admin.partials.offcanvas._email-template")
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/business-setting/email-template.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/backend/libs/summernote/summernote-bs5.min.js') }}"></script>
@endpush
