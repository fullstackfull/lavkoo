@extends('layouts.admin.app')

@section('title', translate('Customer_Segments'))

@section('content')
    <div class="content container-fluid">
        @include('admin-views.commerce._nav', ['current' => 'segments'])

        <div class="d-flex align-items-center gap-2 mb-3">
            <h2 class="h1 mb-0">{{ translate('customer_segments') }}</h2>
            @if (!$enabled)
                <span class="badge badge-soft-danger">{{ translate('commerce_experience_is_switched_off') }}</span>
            @endif
        </div>

        @if (!$ready)
            <div class="alert alert-warning">
                {{ translate('the_segment_table_has_not_been_migrated_yet') }} —
                <code dir="ltr">php artisan migrate</code>
            </div>
        @else
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">{{ translate('segments') }}
                                <span class="badge badge-soft-primary">{{ $segments->count() }}</span>
                            </h5>
                            <small class="text-muted">
                                {{ translate('membership_is_computed_from_real_orders_at_request_time_never_stored') }}
                            </small>
                        </div>
                        <div class="card-body p-0">
                            <div class="k-table-wrap">
                                <table class="k-table">
                                    <thead>
                                        <tr>
                                            <th>{{ translate('segment') }}</th>
                                            <th>{{ translate('rules') }}</th>
                                            <th class="text-center">{{ translate('status') }}</th>
                                            <th class="text-center">{{ translate('action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    @forelse ($segments as $segment)
                                        <tr>
                                            <td>
                                                <div class="fw-bold">{{ $segment->name }}</div>
                                                <small class="text-muted"><code dir="ltr">{{ $segment->key }}</code></small>
                                            </td>
                                            <td>
                                                @foreach ($segment->ruleRows() as $rule)
                                                    <span class="badge badge-soft-secondary" dir="ltr">
                                                        {{ $rule['field'] ?? '' }} {{ str_replace('_', ' ', $rule['operator'] ?? '') }}
                                                        {{ is_array($rule['value'] ?? null) ? implode('–', $rule['value']) : ($rule['value'] ?? '') }}
                                                    </span>
                                                @endforeach
                                            </td>
                                            <td class="text-center">
                                                @if ($segment->status)
                                                    <span class="badge badge-soft-success">{{ translate('live') }}</span>
                                                @else
                                                    <span class="badge badge-soft-warning">{{ translate('off') }}</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                @if ($editable)
                                                    <div class="d-flex flex-wrap gap-1 justify-content-center">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary sg-edit"
                                                                data-segment="{{ json_encode([
                                                                    'id' => $segment->id,
                                                                    'name' => $segment->name,
                                                                    'rules' => $segment->ruleRows(),
                                                                ]) }}">
                                                            {{ translate('edit') }}
                                                        </button>
                                                        <form action="{{ route('admin.commerce.segments.update') }}" method="post">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $segment->id }}">
                                                            <input type="hidden" name="status" value="{{ $segment->status ? 0 : 1 }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                                                {{ $segment->status ? translate('turn_off') : translate('turn_on') }}
                                                            </button>
                                                        </form>
                                                        <form action="{{ route('admin.commerce.segments.delete') }}" method="post"
                                                              onsubmit="return confirm('{{ translate('delete_this_segment') }}?')">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $segment->id }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                                        </form>
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center py-4 text-muted">
                                            {{ translate('no_segments_yet_make_one_and_target_any_section_at_it_from_the_builders_visibility_tab') }}
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
                        <div class="card-header"><h5 class="mb-0">{{ translate('new_segment') }}</h5></div>
                        <div class="card-body">
                            @if ($editable)
                                <form action="{{ route('admin.commerce.segments.store') }}" method="post" id="sg-form">
                                    @csrf
                                    <div class="mb-3">
                                        <label class="form-label">{{ translate('name') }}</label>
                                        <input type="text" name="name" class="form-control" required maxlength="120"
                                               placeholder="{{ translate('repeat_buyer') }}">
                                    </div>

                                    <label class="form-label">{{ translate('rules') }}</label>
                                    <p class="text-muted small mb-2">
                                        {{ translate('a_customer_belongs_when_every_rule_holds_guests_never_belong_to_a_segment') }}
                                    </p>
                                    <div id="sg-rules" class="d-flex flex-column gap-2 mb-2"></div>
                                    <input type="hidden" name="rules" id="sg-rules-json" value="[]">
                                    <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="sg-add-rule">
                                        + {{ translate('add_a_rule') }}
                                    </button>

                                    <input type="hidden" name="id" id="sg-editing-id" value="" disabled>
                                    <button type="submit" class="btn btn-primary w-100" id="sg-submit"
                                            data-store="{{ route('admin.commerce.segments.store') }}"
                                            data-update="{{ route('admin.commerce.segments.update') }}"
                                            data-create-label="{{ translate('create_segment') }}"
                                            data-update-label="{{ translate('save_changes') }}">{{ translate('create_segment') }}</button>
                                    <button type="button" class="btn btn-link w-100" id="sg-cancel-edit" hidden>
                                        {{ translate('stop_editing_and_make_a_new_one') }}
                                    </button>
                                </form>
                            @else
                                <p class="text-muted mb-0">{{ translate('you_do_not_have_permission_to_edit_a_theme') }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        {{ translate('example_repeat_buyer_is_orders_count_greater_than_or_equal_2') }}
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

        var FIELDS = @json(array_values($fields));
        var OPERATORS = @json($operators);
        var host = document.getElementById('sg-rules');
        var json = document.getElementById('sg-rules-json');
        var form = document.getElementById('sg-form');
        if (!form) return;

        function ruleRow(preset) {
            preset = preset || {};
            var row = document.createElement('div');
            row.className = 'd-flex gap-1 align-items-start';

            var field = select(FIELDS, preset.field);
            var operator = select(OPERATORS, preset.operator);

            var value = document.createElement('input');
            value.type = 'text';
            value.dir = 'ltr';
            value.className = 'form-control form-control-sm';
            value.placeholder = '2';
            if (preset.value !== undefined) {
                value.value = Array.isArray(preset.value) ? preset.value.join(',') : preset.value;
            }
            value.addEventListener('input', collect);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger';
            remove.textContent = '×';
            remove.addEventListener('click', function () { row.remove(); collect(); });

            row.appendChild(field);
            row.appendChild(operator);
            row.appendChild(value);
            row.appendChild(remove);
            return row;
        }

        function select(options, chosen) {
            var node = document.createElement('select');
            node.className = 'form-control form-control-sm';
            options.forEach(function (option) {
                var choice = document.createElement('option');
                choice.value = option;
                choice.textContent = option.replace(/_/g, ' ');
                if (option === chosen) choice.selected = true;
                node.appendChild(choice);
            });
            node.addEventListener('change', collect);
            return node;
        }

        function collect() {
            var rules = [];
            host.querySelectorAll(':scope > div').forEach(function (row) {
                var parts = row.querySelectorAll('select, input');
                if (parts[2].value === '') return;
                var operator = parts[1].value;
                rules.push({
                    field: parts[0].value,
                    operator: operator,
                    value: operator === 'between'
                        ? parts[2].value.split(',').map(function (piece) { return piece.trim(); })
                        : parts[2].value
                });
            });
            json.value = JSON.stringify(rules);
        }

        document.getElementById('sg-add-rule').addEventListener('click', function () {
            host.appendChild(ruleRow());
            collect();
        });
        form.addEventListener('submit', collect);

        // ---- edit mode --------------------------------------------------------------------
        var editingId = document.getElementById('sg-editing-id');
        var submitButton = document.getElementById('sg-submit');
        var cancelEdit = document.getElementById('sg-cancel-edit');

        function resetForm() {
            form.reset();
            form.action = submitButton.dataset.store;
            editingId.value = '';
            editingId.disabled = true;
            submitButton.textContent = submitButton.dataset.createLabel;
            cancelEdit.hidden = true;
            host.innerHTML = '';
            collect();
        }

        document.querySelectorAll('.sg-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                var data = JSON.parse(button.dataset.segment);
                resetForm();

                form.action = submitButton.dataset.update;
                editingId.value = data.id;
                editingId.disabled = false;
                submitButton.textContent = submitButton.dataset.updateLabel;
                cancelEdit.hidden = false;

                form.querySelector('[name="name"]').value = data.name || '';
                (data.rules || []).forEach(function (rule) { host.appendChild(ruleRow(rule)); });
                collect();
                form.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
        });

        cancelEdit.addEventListener('click', resetForm);
    })();
</script>
@endpush
