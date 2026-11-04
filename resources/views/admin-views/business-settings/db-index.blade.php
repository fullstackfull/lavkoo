@extends('layouts.admin.app')

@section('title', translate('clean_database'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h1 class="mb-0">
                {{ translate('system_setup') }}
            </h1>
        </div>
        @include('admin-views.system-setup.system-settings-inline-menu')

        <div class="row">
            <div class="col-12 mb-3 mb-sm-20">
                <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded">
                    <div class="d-flex gap-2 align-items-center mb-2">
                        <i class="fi fi-sr-lightbulb-on text-info"></i>
                        <span>
                            {{ translate('this_page_contains_sensitive_information.') }}
                            {{ translate('Make_sure_before_changing.') }}
                        </span>
                    </div>
                    <ul class="m-0 ps-20 d-flex flex-column gap-1 text-body">
                        <li>
                            {{ translate('identify_and_understand_how_the_data_to_be_deleted_interacts_with_other_sections_or_modules_within_the_system.') }}
                        </li>
                        <li>
                            {{ translate('implement_backup_to_reduce_risks_and_ensure_data_recovery_if_necessary.') }}
                        </li>
                        <li>
                            {{ translate('only_proceed_with_deletion_after_confirming_that_all_dependencies_and_impacts_have_been_thoroughly_reviewed.') }}
                        </li>
                    </ul>
                </div>
            </div>
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="mb-3 mb-sm-20">
                            <h2>{{ translate('Clean_Database') }}</h2>
                            <p class="fs-12 mb-0">
                                {{ translate('maintaining_a_clean_database') . ': ' . translate('best_practices_for_data_purging_and_optimization.') }}
                            </p>
                        </div>
                        <form action="{{ route('admin.system-setup.clean-db') }}" method="post" class="clean-database-form"
                              enctype="multipart/form-data">
                            @csrf
                            <div class="bg-section rounded-8 p-12 p-sm-20">
                                <div class="row gy-3">
                                    @foreach($tables as $key => $table)
                                        <div class="col-sm-6 col-xl-3">
                                            <div class="form-group  user-select-none">
                                                <label class="w-100 pointer">
                                                    <span class="d-flex gap-3 justify-content-between align-items-center">
                                                        <span class="form-check-label">
                                                            <input type="checkbox" name="tables[]" value="{{ $table }}"
                                                                   class="form-check-input checkbox--input"
                                                                   id="business_section_{{$key}}">
                                                            {{ translate(str_replace('_',' ', $table)) }}
                                                        </span>
                                                        <span
                                                            class="bg-info bg-opacity-10 fs-12 text-info px-2 py-1 rounded mx-2">
                                                        {{ $rows[$key] }}
                                                    </span>
                                                    </span>
                                                </label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="d-flex justify-content-end gap-3 flex-wrap mt-4">
                                <button type="reset" class="btn btn-secondary px-4">{{ translate('reset') }}</button>

                                @if(config('app.mode') != 'demo')
                                    <button type="button" class="btn btn-primary px-4" data-bs-toggle="modal"
                                            data-bs-target="#cleanDatabaseModal">
                                        {{ translate('clear') }}
                                    </button>
                                @else
                                    <button type="button" class="btn btn-primary px-4 {{ getDemoModeFormButton(type: 'class') }}">
                                        {{ translate('clear') }}
                                    </button>
                                @endif
                            </div>

                            <div class="modal fade" id="cleanDatabaseModal" tabindex="-1"
                                 aria-labelledby="cleanDatabaseModal" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                                            <button type="button"
                                                    class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                                    data-bs-dismiss="modal" aria-label="Close">
                                            </button>
                                        </div>
                                        <div class="modal-body px-20 py-0 mb-30">
                                            <div class="d-flex flex-column align-items-center text-center mb-30">
                                                <img
                                                    src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/delete.png')}}"
                                                    width="80" class="mb-20" id="" alt="">
                                                <h2 class="modal-title mb-3">
                                                    {{ translate('want_to_clean_database').' ?' }}
                                                </h2>
                                                <div class="text-center">
                                                    {{ translate('are_you_sure_want_to_cleaned_the_selected_database').' ?' }}
                                                    {{ translate('it_will_be_permanently_deleted_from_your_database') }}
                                                </div>
                                            </div>
                                            <div class="d-flex justify-content-center gap-3">
                                                <button type="{{ getDemoModeFormButton(type: 'button') }}"
                                                        class="btn btn-danger max-w-120 flex-grow-1 {{ getDemoModeFormButton(type: 'class') }}"
                                                        data-bs-dismiss="modal">
                                                    {{ translate('Yes_Delete') }}
                                                </button>
                                                <button type="button" class="btn btn-secondary max-w-120 flex-grow-1"
                                                        data-bs-dismiss="modal">
                                                    {{ translate('No') }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <span class="clean-database-form-msg"
    data-warning="{{ translate('Warning') }}!"
    data-warning-msg="{{ translate('Please_select_any_checkbox_first') }}!"
    ></span>

    @include("layouts.admin.partials.offcanvas._clean-database")
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/system-setup/system-setup.js') }}"></script>
@endpush
