@extends('layouts.admin.app')

@section('title', translate('monitoring') . ' — ' . translate($meta['label']))

@push('css_or_js')
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/kohl/css/monitoring.css') }}">
@endpush

@section('content')
    {{-- The whole area is one shell: a permanent status header, a section rail, and whichever
         section is open. The header never changes as you move between sections, so "is the shop
         alright" stays answered while you are three levels deep in a database page. --}}
    <div class="content container-fluid k mon" id="monitoring-root"
         data-pulse-url="{{ route('admin.monitoring.pulse') }}"
         data-section="{{ $section }}"
         data-range="{{ $range }}">

        <x-k.page-header :title="translate('monitoring')" :subtitle="translate($meta['hint'])">
            <x-slot:actions>
                <div class="mon-range">
                    @foreach ($ranges as $key => $option)
                        <a class="mon-range__option {{ $key === $range ? 'is-active' : '' }}"
                           href="{{ route('admin.monitoring.section', ['section' => $section, 'range' => $key]) }}">
                            {{ translate($option['label']) }}
                        </a>
                    @endforeach
                </div>
            </x-slot:actions>
        </x-k.page-header>

        {{-- Status header. `data-mon-*` attributes are what the pulse feed refreshes, so the
             markup is written once and updated in place rather than re-rendered. --}}
        <div class="mon-status" id="mon-status" data-state="{{ $pulse['status'] ?? 'unknown' }}">
            <div class="mon-status__verdict">
                <span class="mon-status__dot" aria-hidden="true"></span>
                <div>
                    <strong data-mon="status">{{ translate($pulse['status'] ?? 'unknown') }}</strong>
                    <small data-mon="status-detail">
                        @if (($pulse['status'] ?? null) === 'unknown')
                            {{ translate('monitoring_is_not_receiving_data_so_the_state_of_the_shop_is_unknown') }}
                        @else
                            {{ translate('across_all_measured_signals') }}
                        @endif
                    </small>
                </div>
            </div>

            <div class="mon-status__score">
                <span class="k-num" data-mon="score">{{ $pulse['score'] ?? '—' }}</span><i>/100</i>
                <small data-mon="coverage">
                    {{ translate('from') }}
                    {{ $pulse['coverage']['measured'] ?? 0 }}/{{ $pulse['coverage']['total'] ?? 0 }}
                    {{ translate('measured_signals') }}
                </small>
            </div>

            {{-- Monitoring watching itself. Without this the most dangerous failure is invisible:
                 a dead collector makes every chart flat and every threshold quiet, which looks
                 exactly like a calm, healthy shop. --}}
            <div class="mon-status__self" id="mon-self">
                @php
                    $monAge = function (?array $reading): string {
                        if (!$reading || $reading['age_seconds'] === null) {
                            return translate('no_data');
                        }
                        $seconds = (int) $reading['age_seconds'];
                        return match (true) {
                            $seconds < 90 => $seconds . 's',
                            $seconds < 5400 => round($seconds / 60) . 'm',
                            default => round($seconds / 3600) . 'h',
                        } . ' ' . translate('ago');
                    };
                @endphp
                <span class="mon-chip" data-mon="self-collection"
                      data-state="{{ ($self['collection_enabled'] ?? false) ? 'ok' : 'off' }}">
                    {{ ($self['collection_enabled'] ?? false) ? translate('collecting') : translate('collection_off') }}
                </span>
                <span class="mon-chip" data-mon="self-gauges" data-state="{{ $self['gauges']['state'] ?? 'no_data' }}">
                    {{ translate('gauges') }} {{ $monAge($self['gauges'] ?? null) }}
                </span>
                <span class="mon-chip" data-mon="self-requests" data-state="{{ $self['requests']['state'] ?? 'no_data' }}">
                    {{ translate('requests') }} {{ $monAge($self['requests'] ?? null) }}
                </span>
                <span class="mon-chip mon-chip--quiet" data-mon="self-buffer">{{ $self['buffer']['description'] ?? '' }}</span>
            </div>
        </div>

        <div class="mon-shell">
            {{-- Section rail. Only the sections this admin may open are here: a section they
                 cannot enter is not advertised, and the controller's own check is the second lock
                 rather than the only one. --}}
            <nav class="mon-rail" aria-label="{{ translate('monitoring') }}">
                <button type="button" class="mon-rail__toggle" data-mon-rail-toggle aria-expanded="false">
                    <x-k.icon name="settings" :size="16" />
                    <span>{{ translate($meta['label']) }}</span>
                </button>
                <div class="mon-rail__body">
                    @foreach ($groups as $groupKey => $items)
                        <div class="mon-rail__group">
                            <span class="mon-rail__group-title">{{ translate($groupLabels[$groupKey] ?? $groupKey) }}</span>
                            @foreach ($items as $item)
                                <a class="mon-rail__link {{ $item['active'] ? 'is-active' : '' }} {{ ($item['built'] ?? true) ? '' : 'is-unbuilt' }}"
                                   href="{{ route('admin.monitoring.section', ['section' => $item['key'], 'range' => $range]) }}"
                                   title="{{ translate($item['hint']) }}@if (!($item['built'] ?? true)) — {{ translate('not_installed_in_this_build') }}@endif">
                                    <x-k.icon :name="$item['icon']" :size="15" />
                                    <span>{{ translate($item['label']) }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </nav>

            <main class="mon-main">
                @if (($panel['state'] ?? null) === 'not_built')
                    <x-k.card>
                        <x-k.empty icon="settings"
                                   :title="translate('this_section_is_not_installed_in_this_build')"
                                   :text="$panel['message'] ?? ''" />
                    </x-k.card>
                @elseif (($panel['state'] ?? null) === 'failed')
                    {{-- A panel that threw says so plainly. Rendering an empty page instead would
                         read as "there is no data", which is a different and much more comforting
                         claim than "this failed". --}}
                    <x-k.card :title="translate('this_section_could_not_be_built')">
                        <p class="mon-note mon-note--critical">{{ $panel['message'] ?? '' }}</p>
                    </x-k.card>
                @else
                    @includeFirst(
                        ['admin-views.monitoring.sections.' . $section, 'admin-views.monitoring.sections._placeholder'],
                        ['panel' => $panel, 'range' => $range, 'section' => $section, 'permissions' => $permissions]
                    )
                @endif
            </main>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/kohl/js/monitoring.js') }}" defer></script>
@endpush
