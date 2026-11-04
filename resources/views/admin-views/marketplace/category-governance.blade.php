@extends('layouts.admin.app')

@section('title', translate('category_governance'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-apps"></i>
                {{ translate('category_governance') }}
            </h2>
            <p class="mb-0 fs-12">
                {{ translate('return_window_tax_class_and_required_attributes_per_category') }}.
                {{ translate('a_blank_field_inherits_the_store_default') }} ({{ translate('return_window') }}: {{ $globalReturnDays }} {{ translate('days') }}).
            </p>
        </div>

        <div class="accordion" id="governanceAccordion">
            @forelse ($categories as $category)
                @php($gov = $governance[$category->id] ?? null)
                <div class="card mb-2">
                    <div class="card-header d-flex align-items-center justify-content-between" role="button"
                         data-toggle="collapse" data-target="#cat-{{ $category->id }}">
                        <span class="fw-medium">{{ $category->name }}</span>
                        <span class="d-flex gap-1 flex-wrap">
                            @if ($gov?->return_window_days !== null && $gov?->return_window_days !== null)
                                <span class="k-badge k-badge--info">{{ translate('return') }}: {{ $gov->return_window_days }}{{ translate('d') }}</span>
                            @endif
                            @if ($gov?->requires_moderation)
                                <span class="k-badge k-badge--warning">{{ translate('moderated') }}</span>
                            @endif
                            @if ($gov?->tax_class)
                                <span class="k-badge">{{ $gov->tax_class }}</span>
                            @endif
                            @if ($gov?->shipping_restricted)
                                <span class="k-badge k-badge--danger">{{ translate('shipping_restricted') }}</span>
                            @endif
                        </span>
                    </div>
                    <div id="cat-{{ $category->id }}" class="collapse" data-parent="#governanceAccordion">
                        <div class="card-body">
                            <form action="{{ route('admin.marketplace.category-governance.update') }}" method="post" class="row g-3">
                                @csrf
                                <input type="hidden" name="category_id" value="{{ $category->id }}">

                                <div class="col-sm-3">
                                    <label class="form-label fs-12" for="rw-{{ $category->id }}">{{ translate('return_window_days') }}</label>
                                    <input type="number" min="0" max="3650" class="form-control" id="rw-{{ $category->id }}"
                                           name="return_window_days" value="{{ $gov?->return_window_days }}"
                                           placeholder="{{ translate('inherit') }} ({{ $globalReturnDays }})">
                                </div>
                                <div class="col-sm-3">
                                    <label class="form-label fs-12" for="tc-{{ $category->id }}">{{ translate('tax_class') }}</label>
                                    <input type="text" class="form-control" id="tc-{{ $category->id }}"
                                           name="tax_class" value="{{ $gov?->tax_class }}"
                                           placeholder="{{ translate('standard') }}">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label fs-12" for="ra-{{ $category->id }}">{{ translate('required_attributes_comma_separated') }}</label>
                                    <input type="text" class="form-control" id="ra-{{ $category->id }}"
                                           name="required_attributes"
                                           value="{{ collect($gov?->required_attributes ?? [])->implode(', ') }}"
                                           placeholder="strength, volume, brand">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fs-12" for="rn-{{ $category->id }}">{{ translate('shipping_restriction_note') }}</label>
                                    <input type="text" class="form-control" id="rn-{{ $category->id }}"
                                           name="restriction_note" value="{{ $gov?->restriction_note }}">
                                </div>
                                <div class="col-sm-4">
                                    <label class="d-flex align-items-center gap-2">
                                        <input type="checkbox" name="requires_moderation" value="1" {{ $gov?->requires_moderation ? 'checked' : '' }}>
                                        {{ translate('always_moderate_products_here') }}
                                    </label>
                                </div>
                                <div class="col-sm-4">
                                    <label class="d-flex align-items-center gap-2">
                                        <input type="checkbox" name="shipping_restricted" value="1" {{ $gov?->shipping_restricted ? 'checked' : '' }}>
                                        {{ translate('shipping_restricted') }}
                                    </label>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-primary">{{ translate('save') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="card"><div class="card-body text-center text-muted py-4">{{ translate('no_categories_found') }}</div></div>
            @endforelse
        </div>
    </div>
@endsection
