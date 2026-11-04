@extends('layouts.admin.app')

@section('title', translate('analytics') . ' — ' . translate($meta['label']))

@push('css_or_js')
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/kohl/css/analytics.css') }}">
@endpush

@section('content')
    <div class="content container-fluid k ana" id="analytics-root"
         data-section="{{ $section }}"
         data-live-url="{{ route('admin.analytics.live-feed') }}">

        <x-k.page-header :title="translate('analytics')" :subtitle="translate($meta['hint'])">
            <x-slot:actions>
                {{-- The window applies to every section, so it lives in the header and survives
                     navigation: comparing two sections over different periods is how a merchant
                     reaches a conclusion that is not true of either. --}}
                <div class="ana-range">
                    @foreach ($ranges as $key => $days)
                        <a class="ana-range__option {{ $window->key === $key ? 'is-active' : '' }}"
                           href="{{ route('admin.analytics.section', ['section' => $section, 'range' => $key]) }}">
                            {{ translate(\App\Services\Analytics\Reporting\Window::make($key)->label()) }}
                        </a>
                    @endforeach
                </div>
            </x-slot:actions>
        </x-k.page-header>

        {{-- Collection health, permanently. This is the bar that answers "can I trust the rest of
             this screen", and it is the single thing whose absence let the old Analytics page show
             empty charts for months without anybody learning why. --}}
        @if ($health['state'] !== 'healthy')
            <div class="ana-alert ana-alert--{{ in_array($health['state'], ['rollup_never_ran', 'not_installed', 'disabled', 'no_events'], true) ? 'danger' : 'warn' }}">
                <i class="tio-warning"></i>
                <div>
                    <strong>{{ translate('analytics_is_not_collecting_normally') }}</strong>
                    {{-- The explanation is a key from a fixed set so it translates; what varies —
                         a path, an hour count — is rendered beside it rather than through
                         translate(), which would mint a language key per value. --}}
                    <p>{{ translate($health['message_key'] ?? 'analytics_is_not_collecting_normally') }}</p>
                    @if (!empty($health['detail']))
                        <p><code>{{ $health['detail'] }}</code></p>
                    @endif
                    {{-- Where the cause is visible. A rollup that never ran is almost always the
                         server cron, and Monitoring is the section that can say whether it fired —
                         which is a different screen, and one nobody thinks to open from here. --}}
                    <p class="ana-muted">
                        @if ($health['state'] === 'rollup_never_ran')
                            <a href="{{ route('admin.monitoring.section', ['section' => 'scheduler']) }}">
                                {{ translate('check_whether_the_scheduler_is_running') }}
                            </a>
                        @else
                            <a href="{{ route('admin.monitoring.section', ['section' => 'overview']) }}">
                                {{ translate('check_the_state_of_the_server') }}
                            </a>
                        @endif
                    </p>
                </div>
            </div>
        @endif

        <div class="ana-window">
            <span>{{ translate('showing') }} <strong>{{ $window->fromDate() }}</strong> → <strong>{{ $window->toDate() }}</strong></span>
            <span class="ana-muted">
                {{ translate('compared_with') }} {{ $window->previousFromDate() }} → {{ $window->previousToDate() }}
            </span>
            @if ($window->includesToday())
                <span class="ana-chip" title="{{ translate('todays_rollup_is_still_running_so_today_is_read_live') }}">{{ translate('includes_today_live') }}</span>
            @endif
        </div>

        <div class="ana-layout">
            <nav class="ana-rail" aria-label="{{ translate('analytics_sections') }}">
                @foreach ($navigation as $groupKey => $group)
                    <div class="ana-rail__group">
                        <span class="ana-rail__label">{{ translate($group['label']) }}</span>
                        @foreach ($group['sections'] as $key => $item)
                            <a class="ana-rail__item {{ $key === $section ? 'is-active' : '' }}"
                               href="{{ route('admin.analytics.section', ['section' => $key, 'range' => $window->key]) }}">
                                {{ translate($item['label']) }}
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </nav>

            <main class="ana-body">
                @includeFirst(
                    ['admin-views.analytics.sections.' . $section, 'admin-views.analytics.sections._placeholder'],
                    ['data' => $data, 'window' => $window, 'section' => $section, 'meta' => $meta, 'health' => $health]
                )
            </main>
        </div>
    </div>
@endsection
