@extends('layouts.admin.app')

@section('title', translate('Priority_Setup'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-4">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('Priority_Setup') }}
            </h2>
        </div>

        <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-20">
            <i class="fi fi-sr-lightbulb-on text-info"></i>
            <span>
                {{ translate('after_change_any_setup_in_this_page_must_click_the_save_information') }}
                <span class="fw-semibold">{{ translate('Save_Information') }}</span>
               {{ translate('button_otherwise_changes_are_not_work.') }}
            </span>
        </div>

        <form action="{{ route('admin.business-settings.priority-setup.index') }}" method="post">
        @csrf

            @include('admin-views.business-settings.priority-setup.partial.brand')
            @include('admin-views.business-settings.priority-setup.partial.category')
            @include('admin-views.business-settings.priority-setup.partial.vendor-list')
            @include('admin-views.business-settings.priority-setup.partial.featured-product')
            @include('admin-views.business-settings.priority-setup.partial.new-arrival-product')
            @include('admin-views.business-settings.priority-setup.partial.top-vendor')
            @include('admin-views.business-settings.priority-setup.partial.category-wise-product')
            @include('admin-views.business-settings.priority-setup.partial.top-rated-product')
            @include('admin-views.business-settings.priority-setup.partial.best-selling-product')
            @include('admin-views.business-settings.priority-setup.partial.searched-product')
            @include('admin-views.business-settings.priority-setup.partial.vendor-product-list')

            <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                <div class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('reset') }}</button>
                    <button type="submit" class="btn btn-primary px-3 px-sm-4">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('save_information') }}
                    </button>
                </div>
            </div>
        </form>
    </div>

    @include("layouts.admin.partials.offcanvas._priority-setup")
@endsection
