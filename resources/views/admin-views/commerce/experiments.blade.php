@extends('layouts.admin.app')

@section('title', translate('Experiments'))

@section('content')
    <div class="content container-fluid">
        @include('admin-views.commerce._nav', ['current' => 'experiments'])

        <div class="d-flex align-items-center gap-2 mb-3">
            <h2 class="h1 mb-0">{{ translate('experiments') }}</h2>
            @if (!$enabled)
                <span class="badge badge-soft-danger">{{ translate('commerce_experience_is_switched_off') }}</span>
            @endif
        </div>

        @if (!$ready)
            <div class="alert alert-warning">
                {{ translate('the_experiment_table_has_not_been_migrated_yet') }} —
                <code dir="ltr">php artisan migrate</code>
            </div>
        @else
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">{{ translate('all_experiments') }}
                                <span class="badge badge-soft-primary">{{ $experiments->count() }}</span>
                            </h5>
                            <small class="text-muted">
                                {{ translate('a_shopper_keeps_their_variant_for_the_whole_experiment_nothing_is_stored_to_do_it') }}
                            </small>
                        </div>
                        <div class="card-body p-0">
                            <div class="k-table-wrap">
                                <table class="k-table">
                                    <thead>
                                        <tr>
                                            <th>{{ translate('experiment') }}</th>
                                            <th>{{ translate('variants') }}</th>
                                            <th class="text-center">{{ translate('status') }}</th>
                                            <th class="text-center">{{ translate('action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    @forelse ($experiments as $experiment)
                                        <tr>
                                            <td>
                                                <div class="fw-bold">{{ $experiment->name }}</div>
                                                <small class="text-muted">
                                                    {{ $experiment->page }} · <code dir="ltr">{{ $experiment->section_uuid }}</code>
                                                </small>
                                            </td>
                                            <td>
                                                @php
                                                    $claimed = array_sum(array_map(
                                                        fn ($variant) => (int) ($variant['weight'] ?? 0),
                                                        $experiment->variantRows(),
                                                    ));
                                                @endphp
                                                @php
                                                    // Measured exposures beside the promised split: distinct shoppers
                                                    // who actually had each variant on screen in the last month.
                                                    $exposures = $reach->variantVisitors($experiment->key);
                                                @endphp
                                                <span class="badge badge-soft-secondary">control · {{ 100 - $claimed }}%</span>
                                                @foreach ($experiment->variantRows() as $variant)
                                                    <span class="badge badge-soft-info">
                                                        {{ $variant['key'] ?? '?' }} · {{ $variant['weight'] ?? 0 }}%
                                                        @if (isset($exposures[$variant['key'] ?? '']))
                                                            · {{ $exposures[$variant['key']] }} {{ translate('seen') }}
                                                        @endif
                                                    </span>
                                                @endforeach
                                            </td>
                                            <td class="text-center">
                                                @if ($experiment->status === 'running')
                                                    <span class="badge bg-warning">{{ translate('LIVE') }}</span>
                                                @elseif ($experiment->status === 'draft')
                                                    <span class="badge bg-primary">{{ translate('UPCOMING') }}</span>
                                                @else
                                                    <span class="badge badge-soft-secondary">{{ translate($experiment->status) }}</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                @if ($editable)
                                                    <div class="d-flex flex-wrap gap-1 justify-content-center">
                                                        @if ($experiment->status === 'draft')
                                                            <form action="{{ route('admin.commerce.experiments.update') }}" method="post">
                                                                @csrf
                                                                <input type="hidden" name="id" value="{{ $experiment->id }}">
                                                                <input type="hidden" name="status" value="running">
                                                                <button type="submit" class="btn btn-sm btn-outline-success">{{ translate('start') }}</button>
                                                            </form>
                                                        @endif
                                                        @if ($experiment->status === 'running')
                                                            <form action="{{ route('admin.commerce.experiments.update') }}" method="post">
                                                                @csrf
                                                                <input type="hidden" name="id" value="{{ $experiment->id }}">
                                                                <input type="hidden" name="status" value="stopped">
                                                                <button type="submit" class="btn btn-sm btn-outline-warning">{{ translate('stop') }}</button>
                                                            </form>
                                                        @endif
                                                        <form action="{{ route('admin.commerce.experiments.delete') }}" method="post"
                                                              onsubmit="return confirm('{{ translate('delete_this_experiment') }}?')">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $experiment->id }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                                        </form>
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center py-4 text-muted">
                                            {{ translate('no_experiments_yet') }}
                                        </td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">{{ translate('new_experiment') }}</h5></div>
                        <div class="card-body">
                            @if (!$editable)
                                <p class="text-muted mb-0">{{ translate('you_do_not_have_permission_to_edit_a_theme') }}</p>
                            @elseif ($sections === [])
                                <p class="text-muted mb-0">
                                    {{ translate('publish_a_page_first_an_experiment_varies_a_section_shoppers_actually_see') }}
                                </p>
                            @else
                                <form action="{{ route('admin.commerce.experiments.store') }}" method="post" id="xp-form">
                                    @csrf
                                    <div class="mb-2">
                                        <label class="form-label">{{ translate('name') }}</label>
                                        <input type="text" name="name" class="form-control" required maxlength="120"
                                               placeholder="{{ translate('hero_message_test') }}">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">{{ translate('the_section_under_test') }}</label>
                                        <select name="section_uuid" class="form-control" required>
                                            @foreach ($sections as $section)
                                                <option value="{{ $section['uuid'] }}">{{ $section['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <label class="form-label">{{ translate('variants') }}</label>
                                    <p class="text-muted small mb-2">
                                        {{ translate('each_variant_changes_only_the_fields_you_fill_the_rest_stays_as_published_the_unclaimed_share_is_control') }}
                                    </p>
                                    <div id="xp-variants" class="d-flex flex-column gap-2 mb-2"></div>
                                    <input type="hidden" name="variants" id="xp-variants-json" value="[]">
                                    <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="xp-add-variant">
                                        + {{ translate('add_a_variant') }}
                                    </button>

                                    <button type="submit" class="btn btn-primary w-100">{{ translate('create_experiment') }}</button>
                                </form>
                            @endif
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        {{ translate('stopping_an_experiment_serves_control_to_everybody_a_stopped_one_never_restarts') }}
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

@push('script')
<script>
    (function () {
        'use strict';

        var host = document.getElementById('xp-variants');
        var json = document.getElementById('xp-variants-json');
        var form = document.getElementById('xp-form');
        if (!form) return;

        var PATCH_FIELDS = ['title', 'subtitle', 'button_text', 'image', 'style', 'columns', 'limit'];

        function variantRow() {
            var row = document.createElement('div');
            row.className = 'border rounded p-2 xp-variant';

            var head = document.createElement('div');
            head.className = 'd-flex gap-1 mb-2 align-items-center';

            var weight = document.createElement('input');
            weight.type = 'number';
            weight.min = '1';
            weight.max = '99';
            weight.value = '50';
            weight.className = 'form-control form-control-sm';
            weight.style.maxWidth = '90px';
            weight.addEventListener('input', collect);

            var weightLabel = document.createElement('span');
            weightLabel.className = 'small text-muted';
            weightLabel.textContent = @json(translate('percent_of_traffic'));

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger ms-auto';
            remove.textContent = '×';
            remove.addEventListener('click', function () { row.remove(); collect(); });

            head.appendChild(weight);
            head.appendChild(weightLabel);
            head.appendChild(remove);
            row.appendChild(head);

            PATCH_FIELDS.forEach(function (key) {
                var input = document.createElement('input');
                input.type = (key === 'columns' || key === 'limit') ? 'number' : 'text';
                input.className = 'form-control form-control-sm mb-1';
                input.placeholder = key.replace(/_/g, ' ');
                input.dataset.patchKey = key;
                if (key === 'image') input.dir = 'ltr';
                input.addEventListener('input', collect);
                row.appendChild(input);
            });

            return row;
        }

        function collect() {
            var variants = [];
            host.querySelectorAll('.xp-variant').forEach(function (row, index) {
                var settings = {};
                row.querySelectorAll('[data-patch-key]').forEach(function (input) {
                    if (input.value !== '') settings[input.dataset.patchKey] = input.value;
                });
                variants.push({
                    key: String.fromCharCode(98 + index),
                    weight: parseInt(row.querySelector('input[type="number"]').value || '0', 10),
                    settings: settings
                });
            });
            json.value = JSON.stringify(variants);
        }

        document.getElementById('xp-add-variant').addEventListener('click', function () {
            host.appendChild(variantRow());
            collect();
        });
        form.addEventListener('submit', collect);
    })();
</script>
@endpush
