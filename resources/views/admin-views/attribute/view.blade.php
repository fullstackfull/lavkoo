@extends('layouts.admin.app')

@section('title', translate('Attribute'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex gap-2 align-items-center">
                <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/attribute.png') }}" alt="">
                {{ translate('Attribute_Setup') }}
            </h2>
        </div>

        <div class="row product-attribute-management">
            <div class="col-md-12 mb-3">
                <div class="card">
                    <div class="card-body">
                        <form id="attribute-form" action="{{ route('admin.attribute.store') }}" method="post" class="text-start form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                            @csrf
                            <input type="hidden" name="attribute_id" id="attribute-id">
                            <span id="default-language" data-lang="{{ $defaultLanguage }}" style="display: none;"></span>
                            <div class="bg-section rounded-10 p-12 p-sm-20">
                                <div class="table-responsive w-auto overflow-y-hidden mb-3">
                                    <div class="position-relative nav--tab-wrapper">
                                        <ul class="nav nav-underline nav--tab text-capitalize p-0" id="pills-tab"
                                            role="tablist">
                                            @foreach($language as $lang)
                                                <li class="nav-item px-0">
                                                    <a data-bs-toggle="pill" data-bs-target="#{{ $lang }}-form" role="tab"
                                                       class="nav-link px-2 {{ $lang == $defaultLanguage ? 'active' : '' }}"
                                                       id="{{ $lang }}-link">
                                                        {{ getLanguageName($lang).'('.strtoupper($lang).')' }}
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                        <div class="nav--tab__prev">
                                            <button class="btn btn-circle border-0 bg-white text-primary">
                                                <i class="fi fi-sr-angle-left"></i>
                                            </button>
                                        </div>
                                        <div class="nav--tab__next">
                                            <button class="btn btn-circle border-0 bg-white text-primary">
                                                <i class="fi fi-sr-angle-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-content" id="pills-tabContent">
                                    @foreach($language as $lang)
                                        <div class="tab-pane fade {{ $lang == $defaultLanguage ? 'show active' : '' }}"
                                             id="{{ $lang }}-form" aria-labelledby="{{ $lang }}-link" role="tabpanel">
                                            <label class="form-label" for="name-{{ $lang }}">
                                                {{ translate('attribute_Name') }}
                                                @if($lang == $defaultLanguage)<span class="text-danger">*</span>@endif
                                                ({{ strtoupper($lang) }})
                                            </label>
                                            <input type="text" name="name[]" class="form-control attribute-name-input"
                                                   id="name-{{ $lang }}" data-lang="{{ $lang }}"
                                                   data-required-msg="{{ translate('Attribute_name_is_required') }}"
                                                   placeholder="{{ translate('enter_Attribute_Name') }}"
                                                {{$lang == $defaultLanguage? 'required':''}}>
                                        </div>
                                        <input type="hidden" name="lang[]" value="{{$lang }}">
                                    @endforeach
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-end mt-4">
                                <button type="button" id="cancel-edit-btn" class="btn btn-secondary min-w-120" style="display: none;">
                                    {{ translate('cancel') }}
                                </button>
                                <button type="reset" id="reset-btn" class="btn btn-secondary min-w-120">{{ translate('reset') }}</button>
                                <button type="submit" id="submit-btn" class="btn btn-primary min-w-120">{{ translate('submit') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-12">
                <x-k.data-view :title="translate('Attribute_List')" :count="$attributes->total()"
                               searchName="searchValue" :searchValue="requestString('searchValue')"
                               :searchPlaceholder="translate('search_by_Attribute_Name')">

                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('attribute_Name') }} </th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($attributes as $key => $attribute)
                            <tr>
                                <td><span class="k-num">{{ $attributes->firstItem() + $key }}</span></td>
                                <td>{{ $attribute['name'] }}</td>
                                <td>
                                    <div class="k-table__actions">
                                        <button type="button"
                                                class="k-btn k-btn--ghost k-btn--sm k-btn--icon attribute-edit-btn"
                                                title="{{ translate('edit') }}"
                                                data-id="{{ $attribute['id'] }}"
                                                data-name="{{ $attribute['name'] }}"
                                                data-action="{{route('admin.attribute.translation-data', ['id' => $attribute['id']] )}}">
                                            <x-k.icon name="edit" :size="15" />
                                        </button>
                                        <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon attribute-delete-button" role="button"
                                              title="{{ translate('delete') }}"
                                              id="{{ $attribute['id'] }}">
                                            <x-k.icon name="trash" :size="15" />
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    @if(count($attributes) == 0)
                        <x-k.empty icon="catalog" :title="translate('no_attribute_found')" />
                    @endif

                    @if ($attributes->total() > 0)
                        <x-slot:pager>
                            <span class="k-pager__info">
                                {{ translate('showing') }}
                                <span class="k-num">{{ $attributes->firstItem() }}–{{ $attributes->lastItem() }}</span>
                                {{ translate('of') }} <span class="k-num">{{ $attributes->total() }}</span>
                            </span>
                            <div>{!! $attributes->appends(request()->except('page'))->links() !!}</div>
                        </x-slot:pager>
                    @endif
                </x-k.data-view>
            </div>
        </div>
    </div>

    <span id="route-admin-attribute-delete" data-url="{{ route('admin.attribute.delete') }}"></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/products/products-management.js') }}"></script>
@endpush
