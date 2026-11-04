@extends('layouts.admin.app')

@section('title', translate('Dynamic_Collections'))

@section('content')
    <div class="content container-fluid">
        @include('admin-views.commerce._nav', ['current' => 'collections'])

        <div class="d-flex align-items-center gap-2 mb-3">
            <h2 class="h1 mb-0">{{ translate('dynamic_collections') }}</h2>
            @if (!$enabled)
                <span class="badge badge-soft-danger">{{ translate('commerce_experience_is_switched_off') }}</span>
            @endif
        </div>

        @if (!$ready)
            <div class="alert alert-warning">
                {{ translate('the_collection_tables_have_not_been_migrated_yet') }} —
                <code dir="ltr">php artisan migrate</code>
            </div>
        @else
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">{{ translate('collections') }}
                                <span class="badge badge-soft-primary">{{ $collections->count() }}</span>
                            </h5>
                            @if ($metricsAge)
                                <small class="text-muted">{{ translate('metrics_computed') }}: {{ $metricsAge }}</small>
                            @else
                                <small class="text-muted">
                                    {{ translate('metrics_never_computed_run') }} <code dir="ltr">php artisan commerce:metrics-refresh</code>
                                </small>
                            @endif
                        </div>
                        <div class="card-body p-0">
                            <div class="k-table-wrap">
                                <table class="k-table">
                                    <thead>
                                        <tr>
                                            <th>{{ translate('collection') }}</th>
                                            <th>{{ translate('rules') }}</th>
                                            <th class="text-center">{{ translate('ranking') }}</th>
                                            <th class="text-center">{{ translate('status') }}</th>
                                            <th class="text-center">{{ translate('action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    @forelse ($collections as $collection)
                                        <tr>
                                            <td>
                                                <div class="fw-bold">{{ $collection->name }}</div>
                                                <small class="text-muted"><code dir="ltr">{{ $collection->slug }}</code></small>
                                            </td>
                                            <td>
                                                @forelse ($collection->ruleRows() as $rule)
                                                    <span class="badge badge-soft-secondary" dir="ltr">
                                                        {{ $rule['field'] ?? '' }} {{ str_replace('_', ' ', $rule['operator'] ?? '') }}
                                                        {{ is_array($rule['value'] ?? null) ? implode(',', $rule['value']) : ($rule['value'] ?? '') }}
                                                    </span>
                                                @empty
                                                    <small class="text-muted">{{ translate('every_active_product') }}</small>
                                                @endforelse
                                            </td>
                                            <td class="text-center"><span class="badge badge-soft-info">{{ translate($collection->sort_by) }}</span></td>
                                            <td class="text-center">
                                                @if ($collection->status)
                                                    <span class="badge badge-soft-success">{{ translate('live') }}</span>
                                                @else
                                                    <span class="badge badge-soft-warning">{{ translate('off') }}</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex flex-wrap gap-1 justify-content-center">
                                                    <button type="button" class="btn btn-sm btn-outline-primary cx-preview"
                                                            data-id="{{ $collection->id }}"
                                                            data-url="{{ route('admin.commerce.collections.preview') }}">
                                                        {{ translate('preview') }}
                                                    </button>
                                                    @if ($editable)
                                                        <button type="button" class="btn btn-sm btn-outline-secondary cx-edit"
                                                                data-collection="{{ json_encode([
                                                                    'id' => $collection->id,
                                                                    'name' => $collection->name,
                                                                    'sort_by' => $collection->sort_by,
                                                                    'rules' => $collection->ruleRows(),
                                                                    'merchandising' => $collection->merchandising,
                                                                ]) }}">
                                                            {{ translate('edit') }}
                                                        </button>
                                                    @endif
                                                    @if ($editable)
                                                        <form action="{{ route('admin.commerce.collections.update') }}" method="post">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $collection->id }}">
                                                            <input type="hidden" name="status" value="{{ $collection->status ? 0 : 1 }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                                                {{ $collection->status ? translate('turn_off') : translate('turn_on') }}
                                                            </button>
                                                        </form>
                                                        <form action="{{ route('admin.commerce.collections.delete') }}" method="post"
                                                              onsubmit="return confirm('{{ translate('delete_this_collection_sections_using_it_will_render_nothing_until_repointed') }}?')">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $collection->id }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                                        </form>
                                                    @endif
                                                </div>
                                                <div class="cx-preview-result text-start small mt-2" hidden></div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="text-center py-4 text-muted">
                                            {{ translate('no_collections_yet_make_one_and_source_any_product_section_from_it') }}
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
                        <div class="card-header"><h5 class="mb-0">{{ translate('new_collection') }}</h5></div>
                        <div class="card-body">
                            @if ($editable)
                                <form action="{{ route('admin.commerce.collections.store') }}" method="post" id="cx-form">
                                    @csrf
                                    <div class="mb-3">
                                        <label class="form-label">{{ translate('name') }}</label>
                                        <input type="text" name="name" class="form-control" required maxlength="120"
                                               placeholder="{{ translate('best_selling_vitamins') }}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">{{ translate('ranked_by') }}</label>
                                        <select name="sort_by" class="form-control">
                                            @foreach ($sorts as $sort)
                                                <option value="{{ $sort }}">{{ translate($sort) }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <label class="form-label">{{ translate('rules') }}</label>
                                    <p class="text-muted small mb-2">
                                        {{ translate('every_rule_must_hold_no_rules_means_every_active_product') }}
                                    </p>
                                    <div id="cx-rules" class="d-flex flex-column gap-2 mb-2"></div>
                                    <input type="hidden" name="rules" id="cx-rules-json" value="[]">
                                    <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="cx-add-rule">
                                        + {{ translate('add_a_rule') }}
                                    </button>

                                    <hr>
                                    <h6>{{ translate('merchandising') }}</h6>

                                    <div class="mb-2">
                                        <label class="form-label mb-1">{{ translate('pinned_products') }}</label>
                                        <p class="text-muted small mb-1">{{ translate('each_pin_holds_the_position_it_is_listed_at') }}</p>
                                        <div class="cx-pick" data-resource="product" data-role="pins"></div>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label mb-1">{{ translate('excluded_products') }}</label>
                                        <div class="cx-pick" data-resource="product" data-role="excluded"></div>
                                    </div>

                                    <div class="mb-2">
                                        <label class="form-label mb-1">{{ translate('boosts') }}</label>
                                        <p class="text-muted small mb-1">{{ translate('a_boost_moves_matching_products_up_the_ranking_it_never_adds_or_removes') }}</p>
                                        <div id="cx-boosts" class="d-flex flex-column gap-2 mb-1"></div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="cx-add-boost">
                                            + {{ translate('add_a_boost') }}
                                        </button>
                                    </div>

                                    <div class="row g-2 mb-2">
                                        <div class="col-6">
                                            <label class="form-label mb-1">{{ translate('minimum_items') }}</label>
                                            <input type="number" id="cx-min-items" class="form-control form-control-sm"
                                                   min="0" max="24" value="0">
                                            <small class="text-muted">{{ translate('fewer_than_this_and_the_fallback_decides') }}</small>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label mb-1">{{ translate('fallback') }}</label>
                                            <select id="cx-fallback-kind" class="form-control form-control-sm">
                                                <option value="hide">{{ translate('hide_the_section') }}</option>
                                                <option value="source">{{ translate('a_catalogue_source') }}</option>
                                                <option value="collection">{{ translate('another_collection') }}</option>
                                            </select>
                                            <select id="cx-fallback-source" class="form-control form-control-sm mt-1" hidden>
                                                @foreach ($fallbackSources as $fallbackSource)
                                                    <option value="{{ $fallbackSource }}">{{ translate($fallbackSource) }}</option>
                                                @endforeach
                                            </select>
                                            <select id="cx-fallback-collection" class="form-control form-control-sm mt-1" hidden>
                                                @foreach ($collections as $other)
                                                    <option value="{{ $other->id }}">{{ $other->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-check mb-3">
                                        <input type="checkbox" id="cx-replace" class="form-check-input">
                                        <label class="form-check-label" for="cx-replace">
                                            {{ translate('top_a_short_list_up_from_the_fallback') }}
                                        </label>
                                    </div>

                                    <input type="hidden" name="merchandising" id="cx-merch-json" value="null">
                                    <input type="hidden" name="id" id="cx-editing-id" value="" disabled>

                                    <button type="submit" class="btn btn-primary w-100" id="cx-submit"
                                            data-store="{{ route('admin.commerce.collections.store') }}"
                                            data-update="{{ route('admin.commerce.collections.update') }}"
                                            data-create-label="{{ translate('create_collection') }}"
                                            data-update-label="{{ translate('save_changes') }}">{{ translate('create_collection') }}</button>
                                    <button type="button" class="btn btn-link w-100" id="cx-cancel-edit" hidden>
                                        {{ translate('stop_editing_and_make_a_new_one') }}
                                    </button>
                                </form>
                            @else
                                <p class="text-muted mb-0">{{ translate('you_do_not_have_permission_to_edit_a_theme') }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        {{ translate('a_collection_does_nothing_by_itself_source_a_product_section_from_it_in_the_builder_and_publish') }}
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

        var FIELDS = @json($fields);
        var rulesHost = document.getElementById('cx-rules');
        var rulesJson = document.getElementById('cx-rules-json');
        var addButton = document.getElementById('cx-add-rule');

        // ---- rule builder: field -> operators for its type -> one value input -------------
        function ruleRow() {
            var row = document.createElement('div');
            row.className = 'd-flex gap-1 align-items-start';

            var field = document.createElement('select');
            field.className = 'form-control form-control-sm';
            Object.keys(FIELDS).forEach(function (name) {
                var option = document.createElement('option');
                option.value = name;
                option.textContent = name.replace(/_/g, ' ');
                field.appendChild(option);
            });

            var operator = document.createElement('select');
            operator.className = 'form-control form-control-sm';

            var value = document.createElement('input');
            value.type = 'text';
            value.className = 'form-control form-control-sm';
            value.dir = 'ltr';

            function refresh() {
                var definition = FIELDS[field.value];
                operator.innerHTML = '';
                (definition.operators || []).forEach(function (name) {
                    var option = document.createElement('option');
                    option.value = name;
                    option.textContent = name.replace(/_/g, ' ');
                    operator.appendChild(option);
                });
                value.placeholder = definition.type === 'set' ? '3,7,12'
                    : definition.type === 'boolean' ? '1'
                    : definition.type === 'days' ? '30'
                    : operator.value === 'between' ? '10,50' : '10';
            }
            field.addEventListener('change', function () { refresh(); collect(); });
            operator.addEventListener('change', function () { refresh(); collect(); });
            value.addEventListener('input', collect);
            refresh();

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

        function collect() {
            var rules = [];
            rulesHost.querySelectorAll(':scope > div').forEach(function (row) {
                var parts = row.querySelectorAll('select, input');
                var raw = parts[2].value.trim();
                if (raw === '') return;
                var operator = parts[1].value;
                var needsList = operator === 'in' || operator === 'not_in' || operator === 'between';
                rules.push({
                    field: parts[0].value,
                    operator: operator,
                    value: needsList ? raw.split(',').map(function (piece) { return piece.trim(); }) : raw
                });
            });
            rulesJson.value = JSON.stringify(rules);
        }

        if (addButton) {
            addButton.addEventListener('click', function () {
                rulesHost.appendChild(ruleRow());
                collect();
            });
        }

        // ---- search-and-pick widget: choose real records by name, keep ids in order --------
        var RESOURCES_URL = @json(route('admin.theme.builder.resources'));
        var pickers = {};

        function picker(host) {
            var chosen = []; // [{id, label}] in pick order

            var chips = document.createElement('div');
            chips.className = 'd-flex flex-wrap gap-1 mb-1';
            var search = document.createElement('input');
            search.type = 'text';
            search.className = 'form-control form-control-sm';
            search.placeholder = @json(translate('search_by_name'));
            var results = document.createElement('div');
            results.className = 'border rounded p-1 d-none';
            var timer;

            function paint() {
                chips.innerHTML = '';
                chosen.forEach(function (item, index) {
                    var chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'btn btn-sm btn-outline-secondary';
                    chip.textContent = (host.dataset.role === 'pins' ? ('#' + (index + 1) + ' ') : '') + item.label + ' ×';
                    chip.addEventListener('click', function () {
                        chosen.splice(index, 1);
                        paint();
                    });
                    chips.appendChild(chip);
                });
            }

            function runSearch() {
                fetch(RESOURCES_URL + '?resource=' + encodeURIComponent(host.dataset.resource)
                        + '&q=' + encodeURIComponent(search.value || ''),
                      {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                    .then(function (response) { return response.json(); })
                    .then(function (body) {
                        results.innerHTML = '';
                        results.classList.remove('d-none');
                        ((body && body.options) || []).slice(0, 8).forEach(function (option) {
                            var row = document.createElement('button');
                            row.type = 'button';
                            row.className = 'btn btn-sm btn-light w-100 text-start';
                            row.textContent = option.label;
                            row.addEventListener('click', function () {
                                if (!chosen.some(function (item) { return item.id === option.value; })) {
                                    chosen.push({id: option.value, label: option.label});
                                    paint();
                                }
                                results.classList.add('d-none');
                                search.value = '';
                            });
                            results.appendChild(row);
                        });
                    })
                    .catch(function () { results.classList.add('d-none'); });
            }

            search.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(runSearch, 250);
            });
            search.addEventListener('focus', runSearch);

            host.appendChild(chips);
            host.appendChild(search);
            host.appendChild(results);

            return {
                ids: function () { return chosen.map(function (item) { return item.id; }); },
                set: function (ids, labels) {
                    chosen = (ids || []).map(function (id) {
                        return {id: id, label: (labels && labels[id]) || ('#' + id)};
                    });
                    paint();
                },
                clear: function () { chosen = []; paint(); }
            };
        }

        document.querySelectorAll('.cx-pick').forEach(function (host) {
            pickers[host.dataset.role] = picker(host);
        });

        // ---- boosts: kind + subject + weight ----------------------------------------------
        var BOOST_KINDS = @json($boostKinds);
        var boostsHost = document.getElementById('cx-boosts');
        var addBoost = document.getElementById('cx-add-boost');

        function boostRow(preset) {
            var row = document.createElement('div');
            row.className = 'd-flex gap-1 align-items-start cx-boost';

            var kind = document.createElement('select');
            kind.className = 'form-control form-control-sm';
            BOOST_KINDS.forEach(function (name) {
                var option = document.createElement('option');
                option.value = name;
                option.textContent = name;
                kind.appendChild(option);
            });

            var subject = document.createElement('input');
            subject.type = 'number';
            subject.min = '1';
            subject.className = 'form-control form-control-sm';
            subject.placeholder = 'id';

            var weight = document.createElement('input');
            weight.type = 'number';
            weight.min = '1';
            weight.max = '1000';
            weight.className = 'form-control form-control-sm';
            weight.placeholder = '+30';

            function refresh() { subject.disabled = kind.value === 'featured'; }
            kind.addEventListener('change', refresh);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger';
            remove.textContent = '×';
            remove.addEventListener('click', function () { row.remove(); });

            row.appendChild(kind);
            row.appendChild(subject);
            row.appendChild(weight);
            row.appendChild(remove);

            if (preset) {
                kind.value = preset.kind || 'product';
                subject.value = preset.id || '';
                weight.value = preset.weight || '';
            }
            refresh();

            return row;
        }

        if (addBoost) {
            addBoost.addEventListener('click', function () { boostsHost.appendChild(boostRow()); });
        }

        // ---- assemble the merchandising JSON on submit ------------------------------------
        var form = document.getElementById('cx-form');
        var merchJson = document.getElementById('cx-merch-json');
        var fallbackKind = document.getElementById('cx-fallback-kind');
        var fallbackSource = document.getElementById('cx-fallback-source');
        var fallbackCollection = document.getElementById('cx-fallback-collection');

        function refreshFallback() {
            fallbackSource.hidden = fallbackKind.value !== 'source';
            fallbackCollection.hidden = fallbackKind.value !== 'collection';
        }
        if (fallbackKind) fallbackKind.addEventListener('change', refreshFallback);

        function collectMerch() {
            var boosts = [];
            boostsHost.querySelectorAll('.cx-boost').forEach(function (row) {
                var parts = row.querySelectorAll('select, input');
                if (!parts[2].value) return;
                boosts.push({
                    kind: parts[0].value,
                    id: parts[0].value === 'featured' ? null : parseInt(parts[1].value || '0', 10),
                    weight: parseFloat(parts[2].value)
                });
            });

            var merch = {
                pins: pickers.pins ? pickers.pins.ids().map(function (id, index) {
                    return {id: id, position: index + 1};
                }) : [],
                excluded: pickers.excluded ? pickers.excluded.ids() : [],
                boosts: boosts,
                min_items: parseInt(document.getElementById('cx-min-items').value || '0', 10),
                replace: document.getElementById('cx-replace').checked,
                fallback: fallbackKind.value === 'source'
                    ? {kind: 'source', source: fallbackSource.value}
                    : fallbackKind.value === 'collection'
                        ? {kind: 'collection', id: parseInt(fallbackCollection.value || '0', 10)}
                        : {kind: 'hide'}
            };
            merchJson.value = JSON.stringify(merch);
        }

        if (form) {
            form.addEventListener('submit', function () {
                collect();
                collectMerch();
            });
        }

        // ---- edit mode: load a row into the form, submit as update ------------------------
        var editingId = document.getElementById('cx-editing-id');
        var submitButton = document.getElementById('cx-submit');
        var cancelEdit = document.getElementById('cx-cancel-edit');

        function resetForm() {
            form.reset();
            form.action = submitButton.dataset.store;
            editingId.value = '';
            editingId.disabled = true;
            submitButton.textContent = submitButton.dataset.createLabel;
            cancelEdit.hidden = true;
            rulesHost.innerHTML = '';
            boostsHost.innerHTML = '';
            Object.keys(pickers).forEach(function (role) { pickers[role].clear(); });
            collect();
            refreshFallback();
        }

        document.querySelectorAll('.cx-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                var data = JSON.parse(button.dataset.collection);
                resetForm();

                form.action = submitButton.dataset.update;
                editingId.value = data.id;
                editingId.disabled = false;
                submitButton.textContent = submitButton.dataset.updateLabel;
                cancelEdit.hidden = false;

                form.querySelector('[name="name"]').value = data.name || '';
                form.querySelector('[name="sort_by"]').value = data.sort_by || 'sales_30d';

                (data.rules || []).forEach(function (rule) {
                    var row = ruleRow();
                    rulesHost.appendChild(row);
                    var parts = row.querySelectorAll('select, input');
                    parts[0].value = rule.field;
                    parts[0].dispatchEvent(new Event('change'));
                    parts[1].value = rule.operator;
                    parts[2].value = Array.isArray(rule.value) ? rule.value.join(',') : rule.value;
                });
                collect();

                var merch = data.merchandising || {};
                var pinIds = (merch.pins || []).map(function (pin) { return pin.id || pin; });
                var excludedIds = merch.excluded || [];
                if (pickers.pins) pickers.pins.set(pinIds);
                if (pickers.excluded) pickers.excluded.set(excludedIds);
                // Names instead of raw ids on the chips, from the same endpoint the builder uses.
                var allIds = pinIds.concat(excludedIds);
                if (allIds.length) {
                    fetch(@json(route('admin.theme.builder.resource-labels'))
                            + '?resource=product&ids=' + encodeURIComponent(allIds.join(',')),
                          {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                        .then(function (response) { return response.json(); })
                        .then(function (body) {
                            var labels = {};
                            ((body && body.options) || []).forEach(function (option) {
                                labels[option.value] = option.label;
                            });
                            if (pickers.pins) pickers.pins.set(pinIds, labels);
                            if (pickers.excluded) pickers.excluded.set(excludedIds, labels);
                        })
                        .catch(function () {});
                }
                (merch.boosts || []).forEach(function (boost) { boostsHost.appendChild(boostRow(boost)); });
                document.getElementById('cx-min-items').value = merch.min_items || 0;
                document.getElementById('cx-replace').checked = !!merch.replace;
                var fallback = merch.fallback || {kind: 'hide'};
                fallbackKind.value = fallback.kind || 'hide';
                if (fallback.kind === 'source') fallbackSource.value = fallback.source;
                if (fallback.kind === 'collection') fallbackCollection.value = fallback.id;
                refreshFallback();

                form.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
        });

        if (cancelEdit) cancelEdit.addEventListener('click', resetForm);

        // ---- preview: what the collection resolves to right now ---------------------------
        document.querySelectorAll('.cx-preview').forEach(function (button) {
            button.addEventListener('click', function () {
                var host = button.closest('td').querySelector('.cx-preview-result');
                host.hidden = false;
                host.textContent = '…';
                fetch(button.dataset.url + '?id=' + encodeURIComponent(button.dataset.id),
                      {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                    .then(function (response) { return response.json(); })
                    .then(function (body) {
                        var products = (body && body.products) || [];
                        host.textContent = products.length
                            ? products.map(function (product) { return product.name; }).join(' · ')
                            : @json(translate('no_products_match_right_now'));
                    })
                    .catch(function () { host.textContent = @json(translate('preview_failed')); });
            });
        });
    })();
</script>
@endpush
