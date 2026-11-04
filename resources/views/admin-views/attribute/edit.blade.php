@extends('layouts.admin.app')

@section('title', translate('Attribute'))

@section('content')
    <div class="content container-fluid">

        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex gap-2 align-items-center">
                <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/attribute.png') }}" alt="">
                {{ translate('Update_Attribute') }}
            </h2>
        </div>

        <div class="row">
            <div class="col-md-12 mb-10">
                <div class="card">
                    <div class="card-body text-start">
                        <form action="{{route('admin.attribute.update', [$attribute['id']])}}" method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                            @csrf
                            <div class="table-responsive w-auto overflow-y-hidden mb-4">
                                <div class="position-relative nav--tab-wrapper">
                                    <ul class="nav nav-pills nav--tab text-capitalize lang_tab" id="pills-tab"
                                        role="tablist">
                                        @foreach($language as $lang)
                                            <li class="nav-item px-0">
                                                <a data-bs-toggle="pill" data-bs-target="#{{ $lang }}-form" role="tab"
                                                   class="nav-link px-2 {{ $lang == $defaultLanguage ? 'active' : '' }}"
                                                   id="{{ $lang }}-link">
                                                    {{ getLanguageName($lang).' ('.strtoupper($lang).')' }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <div class="nav--tab__prev">
                                        <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                                            <i class="fi fi-sr-angle-left"></i>
                                        </button>
                                    </div>
                                    <div class="nav--tab__next">
                                        <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                                            <i class="fi fi-sr-angle-right"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-content" id="pills-tabContent">
                                @foreach($language as $lang)
                                        <?php
                                        if (count($attribute['translations'])) {
                                            $translate = [];
                                            foreach ($attribute['translations'] as $translations) {
                                                if ($translations->locale == $lang && $translations->key == "name") {
                                                    $translate[$lang]['name'] = $translations->value;
                                                }
                                            }
                                        }
                                        ?>
                                    <div class="tab-pane fade {{ $lang == $defaultLanguage ? 'show active' : '' }}"
                                         id="{{ $lang }}-form" aria-labelledby="{{ $lang }}-link" role="tabpanel">
                                        <input type="hidden" id="id">
                                        <label class="form-label" for="name">
                                            {{ translate('attribute_Name') }}
                                            <span class="text-danger">*</span>
                                            ({{strtoupper($lang)}})
                                        </label>
                                        <input type="text" name="name[]"
                                               value="{{$lang==$defaultLanguage?$attribute['name']:($translate[$lang]['name']??'') }}"
                                               class="form-control" id="name"
                                                  data-required-msg="{{ translate('name_is_required') }}"
                                               placeholder="{{ translate('enter_Attribute_Name') }}" {{$lang == $defaultLanguage ? 'required':''}}>
                                    </div>
                                    <input type="hidden" name="lang[]" value="{{$lang }}" id="lang">
                                @endforeach
                            </div>
                            <div class="d-flex justify-content-end gap-3 mt-4">
                                <button type="reset" class="btn px-4 btn-secondary">{{ translate('reset') }}</button>
                                <button type="submit" class="btn px-4 btn-primary">{{ translate('update') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/products/products-management.js') }}"></script>
@endpush
