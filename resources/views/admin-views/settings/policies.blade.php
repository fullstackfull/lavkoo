@extends('layouts.admin.app')

@section('title', translate('platform_policies'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-settings-sliders"></i> {{ translate('platform_policies') }}</h2>
            <p class="mb-0 fs-12">{{ translate('the_rules_the_platform_applies_to_itself_every_one_of_them_settable_bounded_and_audited') }}.</p>
        </div>

        <div class="row g-3">
            <div class="col-lg-3">
                <div class="card">
                    <div class="list-group list-group-flush">
                        @foreach ($groups as $key => $definition)
                            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ $key === $group ? 'active' : '' }}"
                               href="{{ route('admin.settings.policies.group', ['group' => $key]) }}">
                                <span>{{ translate($definition['title']) }}</span>
                                <span class="badge bg-light text-dark">{{ count($definition['policies']) }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="col-lg-9">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h5 class="mb-0">{{ translate($meta['title']) }}</h5>
                            <small class="text-muted">{{ translate($meta['help']) }}.</small>
                        </div>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('admin.settings.policies.update', ['group' => $group]) }}" method="post" class="row g-3">
                            @csrf
                            @foreach ($meta['policies'] as $key => $field)
                                <div class="col-md-6">
                                    <label class="form-label fs-12 mb-1" for="{{ $key }}">{{ translate($field['label']) }}</label>
                                    @include('admin-views.settings.partials._policy-field', ['key' => $key, 'field' => $field, 'value' => $values[$key]])
                                    @if (!empty($field['help']))
                                        <small class="text-muted d-block mt-1">{{ translate($field['help']) }}.</small>
                                    @endif
                                    @error($key)<small class="text-danger d-block mt-1">{{ $message }}</small>@enderror
                                    <small class="text-muted d-block mt-1">
                                        {{ translate('default') }}: {{ is_array($field['default']) ? implode(', ', $field['default']) : $field['default'] }}
                                        @if (isset($field['min']))
                                            · {{ translate('allowed') }}: {{ $field['min'] }}–{{ $field['max'] }}
                                        @endif
                                    </small>
                                </div>
                            @endforeach
                            <div class="col-12 d-flex justify-content-end">
                                <button class="btn btn-primary px-4">{{ translate('save') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
