@extends('layouts.admin.app')

@section('title', translate('Campaigns'))

@section('content')
    <div class="content container-fluid">
        @include('admin-views.commerce._nav', ['current' => 'campaigns'])

        <div class="d-flex align-items-center gap-2 mb-3">
            <h2 class="h1 mb-0">{{ translate('campaigns') }}</h2>
            @if (!$enabled)
                <span class="badge badge-soft-danger">{{ translate('commerce_experience_is_switched_off') }}</span>
            @endif
        </div>

        @if (!$ready)
            <div class="alert alert-warning">
                {{ translate('the_campaign_table_has_not_been_migrated_yet') }} —
                <code dir="ltr">php artisan migrate</code>
            </div>
        @else
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">{{ translate('all_campaigns') }}
                                <span class="badge badge-soft-primary">{{ $campaigns->count() }}</span>
                            </h5>
                            <small class="text-muted">
                                {{ translate('a_campaign_dresses_the_page_for_its_window_and_hands_it_back_untouched') }}
                            </small>
                        </div>
                        <div class="card-body p-0">
                            <div class="k-table-wrap">
                                <table class="k-table">
                                    <thead>
                                        <tr>
                                            <th>{{ translate('campaign') }}</th>
                                            <th class="text-center">{{ translate('window') }}</th>
                                            <th class="text-center">{{ translate('priority') }}</th>
                                            <th class="text-center">{{ translate('status') }}</th>
                                            <th class="text-center">{{ translate('action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    @forelse ($campaigns as $campaign)
                                        <tr>
                                            <td>
                                                <div class="fw-bold">{{ $campaign->name }}</div>
                                                <small class="text-muted">
                                                    {{ translate('page') }}: <code dir="ltr">{{ $campaign->page }}</code>
                                                    · {{ count($campaign->overrideRows()) }} {{ translate('overrides') }}
                                                    @if (($campaignReach = $reach->campaignVisitors($campaign->id)) !== null)
                                                        {{-- Measured, not promised: shoppers whose screens actually
                                                             showed this campaign's sections in the last month. --}}
                                                        · <span class="badge badge-soft-info">{{ $campaignReach }} {{ translate('shoppers_reached_30d') }}</span>
                                                    @endif
                                                </small>
                                            </td>
                                            <td class="text-center small" dir="ltr">
                                                {{ $campaign->starts_at?->format('Y-m-d H:i') ?? '—' }}<br>
                                                {{ $campaign->ends_at?->format('Y-m-d H:i') ?? '—' }}
                                            </td>
                                            <td class="text-center">{{ $campaign->priority }}</td>
                                            <td class="text-center">
                                                @if ($campaign->isLive())
                                                    <span class="badge bg-warning">{{ translate('LIVE') }}</span>
                                                @elseif ($campaign->status === 'scheduled')
                                                    <span class="badge bg-primary">{{ translate('UPCOMING') }}</span>
                                                @else
                                                    <span class="badge badge-soft-secondary">{{ translate($campaign->status) }}</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                @if ($editable)
                                                    <div class="d-flex flex-wrap gap-1 justify-content-center">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary cp-edit"
                                                                data-campaign="{{ json_encode([
                                                                    'id' => $campaign->id,
                                                                    'name' => $campaign->name,
                                                                    'page' => $campaign->page,
                                                                    'priority' => $campaign->priority,
                                                                    'starts_at' => $campaign->starts_at?->format('Y-m-d\TH:i'),
                                                                    'ends_at' => $campaign->ends_at?->format('Y-m-d\TH:i'),
                                                                    'overrides' => $campaign->overrideRows(),
                                                                ]) }}">
                                                            {{ translate('edit') }}
                                                        </button>

                                                        @foreach ([
                                                            'active' => translate('go_live'),
                                                            'scheduled' => translate('schedule'),
                                                            'paused' => translate('pause'),
                                                            'ended' => translate('end'),
                                                        ] as $status => $label)
                                                            @if ($campaign->status !== $status)
                                                                <form action="{{ route('admin.commerce.campaigns.update') }}" method="post">
                                                                    @csrf
                                                                    <input type="hidden" name="id" value="{{ $campaign->id }}">
                                                                    <input type="hidden" name="status" value="{{ $status }}">
                                                                    <button type="submit" class="btn btn-sm btn-outline-{{ $status === 'active' ? 'success' : ($status === 'ended' ? 'danger' : 'warning') }}">
                                                                        {{ $label }}
                                                                    </button>
                                                                </form>
                                                            @endif
                                                        @endforeach

                                                        <form action="{{ route('admin.commerce.campaigns.delete') }}" method="post"
                                                              onsubmit="return confirm('{{ translate('delete_this_campaign') }}?')">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $campaign->id }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                                        </form>
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="text-center py-4 text-muted">
                                            {{ translate('no_campaigns_yet') }}
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
                        <div class="card-header"><h5 class="mb-0" id="cp-form-title">{{ translate('new_campaign') }}</h5></div>
                        <div class="card-body">
                            @if ($editable)
                                <form action="{{ route('admin.commerce.campaigns.store') }}" method="post" id="cp-form">
                                    @csrf
                                    <div class="mb-2">
                                        <label class="form-label">{{ translate('name') }}</label>
                                        <input type="text" name="name" class="form-control" required maxlength="120"
                                               placeholder="{{ translate('ramadan_sale') }}">
                                    </div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-6">
                                            <label class="form-label">{{ translate('page') }}</label>
                                            <select name="page" class="form-control">
                                                @foreach ($pages as $pageSlug)
                                                    <option value="{{ $pageSlug }}">{{ $pageSlug }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">{{ translate('priority') }} (1–100)</label>
                                            <input type="number" name="priority" class="form-control" min="1" max="100" value="30">
                                        </div>
                                    </div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-6">
                                            <label class="form-label">{{ translate('starts') }}</label>
                                            <input type="datetime-local" name="starts_at" class="form-control">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">{{ translate('ends') }}</label>
                                            <input type="datetime-local" name="ends_at" class="form-control">
                                        </div>
                                    </div>

                                    <label class="form-label">{{ translate('overrides') }}</label>
                                    <p class="text-muted small mb-2">
                                        {{ translate('each_override_puts_one_section_into_one_slot_for_the_campaigns_window') }}
                                    </p>
                                    <div id="cp-overrides" class="d-flex flex-column gap-2 mb-2"></div>
                                    <input type="hidden" name="overrides" id="cp-overrides-json" value="[]">
                                    <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="cp-add-override">
                                        + {{ translate('add_an_override') }}
                                    </button>

                                    <input type="hidden" name="id" id="cp-editing-id" value="" disabled>
                                    <button type="submit" class="btn btn-primary w-100" id="cp-submit"
                                            data-store="{{ route('admin.commerce.campaigns.store') }}"
                                            data-update="{{ route('admin.commerce.campaigns.update') }}"
                                            data-create-label="{{ translate('create_campaign') }}"
                                            data-update-label="{{ translate('save_changes') }}">{{ translate('create_campaign') }}</button>
                                    <button type="button" class="btn btn-link w-100" id="cp-cancel-edit" hidden>
                                        {{ translate('stop_editing_and_make_a_new_one') }}
                                    </button>
                                </form>
                            @else
                                <p class="text-muted mb-0">{{ translate('you_do_not_have_permission_to_edit_a_theme') }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        {{ translate('when_the_window_closes_the_base_page_returns_by_itself_nothing_to_restore') }}
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

        var SLOTS = @json($slots);
        var TYPES = @json($types);
        var host = document.getElementById('cp-overrides');
        var json = document.getElementById('cp-overrides-json');
        var addButton = document.getElementById('cp-add-override');
        var form = document.getElementById('cp-form');
        if (!form) return;

        // Which fields make sense for which override type; everything else stays hidden.
        var FIELD_MAP = {
            hero_banner:        ['title', 'subtitle', 'image', 'link', 'button_text'],
            promotional_banner: ['title', 'subtitle', 'image', 'link', 'button_text'],
            banner_strip:       ['title', 'image', 'link'],
            product_slider:     ['title', 'source', 'collection_id', 'limit'],
            flash_deal:         ['title'],
            spacer:             ['height']
        };

        function overrideRow(preset) {
            preset = preset || {};
            var settings = (preset.section && preset.section.settings) || {};
            var row = document.createElement('div');
            row.className = 'border rounded p-2 cp-override';

            var head = document.createElement('div');
            head.className = 'd-flex gap-1 mb-2';

            var slot = select(SLOTS, preset.slot);
            var type = select(TYPES, preset.section && preset.section.type);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger';
            remove.textContent = '×';
            remove.addEventListener('click', function () { row.remove(); collect(); });

            head.appendChild(slot);
            head.appendChild(type);
            head.appendChild(remove);
            row.appendChild(head);

            var fields = document.createElement('div');
            fields.className = 'd-flex flex-column gap-1';
            row.appendChild(fields);

            function refresh() {
                fields.innerHTML = '';
                (FIELD_MAP[type.value] || []).forEach(function (key) {
                    var input;
                    if (key === 'source') {
                        input = select(['featured', 'best_selling', 'new_arrival', 'top_rated', 'collection'], settings[key]);
                    } else {
                        input = document.createElement('input');
                        input.type = (key === 'limit' || key === 'height' || key === 'collection_id') ? 'number' : 'text';
                        input.placeholder = key.replace(/_/g, ' ');
                        input.className = 'form-control form-control-sm';
                        if (settings[key] !== undefined && settings[key] !== null) input.value = settings[key];
                        if (key === 'image' || key === 'link') input.dir = 'ltr';
                    }
                    input.dataset.settingKey = key;
                    input.addEventListener('input', collect);
                    input.addEventListener('change', collect);
                    fields.appendChild(input);
                });
            }

            function select(options, value) {
                var node = document.createElement('select');
                node.className = 'form-control form-control-sm';
                options.forEach(function (option) {
                    var choice = document.createElement('option');
                    choice.value = option;
                    choice.textContent = option.replace(/_/g, ' ');
                    if (option === value) choice.selected = true;
                    node.appendChild(choice);
                });
                node.addEventListener('change', function () { refresh(); collect(); });
                return node;
            }

            row.dataset.role = 'override';
            refresh();
            return row;
        }

        function collect() {
            var overrides = [];
            host.querySelectorAll('.cp-override').forEach(function (row) {
                var selects = row.querySelectorAll(':scope > div:first-child > select');
                var settings = {};
                row.querySelectorAll('[data-setting-key]').forEach(function (input) {
                    if (input.value !== '') settings[input.dataset.settingKey] = input.value;
                });
                overrides.push({
                    slot: selects[0].value,
                    section: {type: selects[1].value, settings: settings}
                });
            });
            json.value = JSON.stringify(overrides);
        }

        addButton.addEventListener('click', function () {
            host.appendChild(overrideRow());
            collect();
        });
        form.addEventListener('submit', collect);

        // ---- edit mode --------------------------------------------------------------------
        var editingId = document.getElementById('cp-editing-id');
        var submitButton = document.getElementById('cp-submit');
        var cancelEdit = document.getElementById('cp-cancel-edit');

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

        document.querySelectorAll('.cp-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                var data = JSON.parse(button.dataset.campaign);
                resetForm();

                form.action = submitButton.dataset.update;
                editingId.value = data.id;
                editingId.disabled = false;
                submitButton.textContent = submitButton.dataset.updateLabel;
                cancelEdit.hidden = false;

                form.querySelector('[name="name"]').value = data.name || '';
                form.querySelector('[name="page"]').value = data.page || 'home';
                form.querySelector('[name="priority"]').value = data.priority || 30;
                form.querySelector('[name="starts_at"]').value = data.starts_at || '';
                form.querySelector('[name="ends_at"]').value = data.ends_at || '';
                (data.overrides || []).forEach(function (override) {
                    host.appendChild(overrideRow(override));
                });
                collect();
                form.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
        });

        cancelEdit.addEventListener('click', resetForm);
    })();
</script>
@endpush
