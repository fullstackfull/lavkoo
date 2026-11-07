@extends('layouts.admin.app')

@use('App\Services\Address\LegacyBackfill')

@section('title', translate('address_legacy_title'))

@section('content')
    @php
        $matchTone = ['matched' => 'success', 'ambiguous' => 'warning', 'conflict' => 'danger', 'none' => 'neutral'];
        $regionLabels = $regions->mapWithKeys(static fn ($region): array => [$region->id => $region->name_ar . ' / ' . $region->name_en])->all();
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('address_system')" :subtitle="translate('address_legacy_subtitle')" />
        @include('admin-views.address.partials._tabs', ['active' => 'legacy'])

        <x-k.card :title="translate('address_legacy_summary')" class="mb-3">
            @if ($plan === null)
                <p class="fs-12 text-muted mb-0">{{ translate('address_legacy_not_counted') }}</p>
            @else
                <div class="k-stats mb-3">
                    @foreach (LegacyBackfill::STATUSES as $status)
                        <x-k.stat :label="translate('address_match_' . $status)" :value="number_format($plan['rows'][$status])" />
                    @endforeach
                </div>
                <form method="POST" action="{{ route('admin.address-system.legacy.apply') }}" class="d-flex flex-wrap align-items-center gap-3"
                      onsubmit="return confirm(@js(translate('address_apply_matches_confirm')))">@csrf
                    <button class="btn btn-primary btn-sm" type="submit" @disabled($plan['rows']['matched'] === 0)>{{ translate('address_apply_matches') }}</button>
                    <span class="fs-12 text-muted">{{ translate('address_apply_matches_help') }}</span>
                </form>
            @endif
            @if ($blankRows > 0)
                <p class="fs-12 text-muted mt-3 mb-0">{{ translate('address_legacy_blank_rows') }}: {{ number_format($blankRows) }}</p>
            @endif
        </x-k.card>

        <form method="GET" action="{{ route('admin.address-system.legacy.index') }}" class="d-flex gap-2 mb-3">
            <input class="form-control form-control-sm" type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="{{ translate('address_legacy_search') }}" aria-label="{{ translate('address_legacy_search') }}">
            <button class="btn btn-primary btn-sm" type="submit">{{ translate('search') }}</button>
        </form>

        <x-k.card :padded="false">
            @if ($groups->isEmpty())
                <x-k.empty icon="search" :title="translate('address_legacy_nothing')" :text="translate('address_legacy_nothing_help')" />
            @else
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{ translate('address_legacy_typed_city') }}</th>
                            <th>{{ translate('address_legacy_addresses') }}</th>
                            <th>{{ translate('address_legacy_states') }}</th>
                            <th>{{ translate('address_legacy_result') }}</th>
                            <th>{{ translate('address_legacy_decide') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($groups as $group)
                            @php
                                $match = $group->match;
                                $status = $match->status->value;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $group->sample() }}</strong>
                                    @foreach (array_slice($group->spellings(), 1, 3, true) as $spelling => $count)
                                        <div class="fs-12">{{ $spelling }} <span class="text-muted">({{ $count }})</span></div>
                                    @endforeach
                                    <div class="fs-10 text-muted" dir="ltr">{{ $group->key }}</div>
                                </td>
                                <td>{{ number_format($group->rows) }}</td>
                                <td class="fs-12">
                                    @forelse ($group->states() as $state => $count)
                                        <div>{{ $state }} <span class="text-muted">({{ $count }})</span></div>
                                    @empty
                                        —
                                    @endforelse
                                </td>
                                <td><x-k.badge :tone="$matchTone[$status]">{{ translate('address_match_' . $status) }}</x-k.badge></td>
                                <td style="min-width: 22rem">
                                    @if ($status === 'matched' && $match->city === null)
                                        <p class="fs-12 text-muted mb-2">{{ translate('address_legacy_several_cities_note') }}</p>
                                    @else
                                        @foreach ($match->candidates as $candidate)
                                            @php($usable = $candidate->is_active && $candidate->region?->is_active)
                                            <form method="POST" action="{{ route('admin.address-system.legacy.confirm') }}" class="d-flex flex-wrap align-items-center gap-2 mb-1"
                                                  onsubmit="return confirm(@js(translate('address_legacy_confirm_prompt')))">@csrf
                                                <input type="hidden" name="text" value="{{ $group->sample() }}">
                                                <input type="hidden" name="city_id" value="{{ $candidate->id }}">
                                                <span class="fs-12">
                                                    {{ $candidate->name_ar }} / <span dir="ltr">{{ $candidate->name_en }}</span> — {{ $candidate->region?->name_ar }} <span class="text-muted">#{{ $candidate->id }}</span>
                                                    @unless ($usable)<x-k.badge tone="warning">{{ translate('address_candidate_switched_off') }}</x-k.badge>@endunless
                                                </span>
                                                <button class="btn btn-primary btn-sm" type="submit" @disabled(! $usable)>{{ translate('address_legacy_use_city') }}</button>
                                            </form>
                                        @endforeach
                                    @endif

                                    <form method="POST" action="{{ route('admin.address-system.legacy.confirm') }}" class="mt-2" data-city-picker onsubmit="return confirm(@js(translate('address_legacy_confirm_prompt')))"
                                          data-url="{{ route('address.cities') }}" data-empty="{{ translate('address_no_cities_found') }}" data-error="{{ translate('address_city_load_failed') }}">@csrf
                                        <input type="hidden" name="text" value="{{ $group->sample() }}">
                                        <input type="hidden" name="city_id" value="" data-picker-city>
                                        <input class="form-control form-control-sm" type="search" autocomplete="off" maxlength="100" data-picker-input
                                               placeholder="{{ translate('address_choose_city') }}" aria-label="{{ translate('address_legacy_choose_city') }}">
                                        <div class="list-group fs-12 mt-1" data-picker-results></div>
                                        <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                                            <span class="fs-12 fw-semibold" data-picker-chosen></span>
                                            <button class="btn btn-outline-primary btn-sm" type="submit" data-picker-submit disabled>{{ translate('address_legacy_use_city') }}</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($groups->hasPages())
                    <x-slot:footer>{{ $groups->links() }}</x-slot:footer>
                @endif
            @endif
        </x-k.card>
        <p class="fs-12 text-muted mt-2">{{ translate('address_legacy_confirm_help') }}</p>
    </div>
@endsection

@push('script')
    <script>
        (function () {
            'use strict';

            var regionLabels = @json($regionLabels);

            function label(city) {
                return city.name_ar + ' / ' + city.name_en + ' — ' + (regionLabels[city.region_id] || '') + ' #' + city.id;
            }

            document.querySelectorAll('[data-city-picker]').forEach(function (form) {
                var input = form.querySelector('[data-picker-input]');
                var results = form.querySelector('[data-picker-results]');
                var chosen = form.querySelector('[data-picker-chosen]');
                var cityField = form.querySelector('[data-picker-city]');
                var submit = form.querySelector('[data-picker-submit]');
                var timer = null;

                function note(text) {
                    var line = document.createElement('div');
                    line.className = 'list-group-item text-muted py-1';
                    line.textContent = text;
                    results.appendChild(line);
                }

                input.addEventListener('input', function () {
                    cityField.value = '';
                    chosen.textContent = '';
                    submit.disabled = true;
                    results.textContent = '';
                    window.clearTimeout(timer);
                    var query = input.value.trim();
                    if (query === '') { return; }

                    timer = window.setTimeout(function () {
                        fetch(form.dataset.url + '?limit=8&q=' + encodeURIComponent(query), {headers: {'Accept': 'application/json'}})
                            .then(function (response) {
                                if (!response.ok) { throw new Error('status ' + response.status); }
                                return response.json();
                            })
                            .then(function (body) {
                                results.textContent = '';
                                var cities = body.cities || [];
                                if (cities.length === 0) { note(form.dataset.empty); }
                                cities.forEach(function (city) {
                                    var option = document.createElement('button');
                                    option.type = 'button';
                                    option.className = 'list-group-item list-group-item-action py-1';
                                    option.textContent = label(city);
                                    option.addEventListener('click', function () {
                                        cityField.value = city.id;
                                        chosen.textContent = label(city);
                                        results.textContent = '';
                                        submit.disabled = false;
                                    });
                                    results.appendChild(option);
                                });
                            })
                            .catch(function () {
                                results.textContent = '';
                                note(form.dataset.error);
                            });
                    }, 250);
                });
            });
        })();
    </script>
@endpush
