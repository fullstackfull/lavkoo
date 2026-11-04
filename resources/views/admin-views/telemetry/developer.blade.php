@extends('layouts.admin.app')

@section('title', translate('developer_portal') . ' — ' . translate($meta['label']))

@push('css_or_js')
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/kohl/css/developer.css') }}">
@endpush

@section('content')
    {{-- One shell for every section: a header that states the size and freshness of the API
         surface, a section rail, and whichever section is open. The header does not change as you
         move around, so "how big is this API and when was it read" stays answered. --}}
    <div class="content container-fluid k dev" id="developer-root"
         data-section="{{ $section }}"
         data-endpoint-url="{{ route('admin.developer.endpoint', ['id' => '__ID__']) }}">

        <x-k.page-header :title="translate('developer_portal')" :subtitle="translate($meta['hint'])">
            <x-slot:actions>
                <form action="{{ route('admin.developer.refresh') }}" method="POST" class="d-inline">
                    @csrf
                    <button type="submit" class="k-btn k-btn--ghost k-btn--sm" title="{{ translate('rebuild_the_manifest_from_the_live_route_table') }}">
                        <i class="tio-refresh"></i> {{ translate('rebuild') }}
                    </button>
                </form>
                <a class="k-btn k-btn--ghost k-btn--sm" href="{{ route('admin.developer.openapi') }}">
                    <i class="tio-download"></i> OpenAPI
                </a>
                <a class="k-btn k-btn--ghost k-btn--sm" href="{{ route('admin.developer.postman') }}">
                    <i class="tio-download"></i> Postman
                </a>
            </x-slot:actions>
        </x-k.page-header>

        {{-- The surface bar. Every number here is counted from the manifest that was just built,
             which is why the "read from the route table" line carries a timestamp: a developer
             needs to know whether they are looking at the API as it is now. --}}
        <div class="dev-surface">
            <div class="dev-surface__figure">
                <span class="k-num">{{ number_format($manifest['summary']['api']) }}</span>
                <small>{{ translate('api_endpoints') }}</small>
            </div>
            <div class="dev-surface__figure">
                <span class="k-num">{{ number_format($manifest['summary']['authenticated']) }}</span>
                <small>{{ translate('authenticated') }}</small>
            </div>
            <div class="dev-surface__figure">
                <span class="k-num">{{ number_format($manifest['summary']['public']) }}</span>
                <small>{{ translate('public') }}</small>
            </div>
            <div class="dev-surface__figure">
                <span class="k-num">{{ count($manifest['summary']['by_version']) }}</span>
                <small>{{ translate('versions') }}</small>
            </div>
            @if ($manifest['summary']['deprecated'] > 0)
                <div class="dev-surface__figure dev-surface__figure--warn">
                    <span class="k-num">{{ $manifest['summary']['deprecated'] }}</span>
                    <small>{{ translate('deprecated') }}</small>
                </div>
            @endif
            <div class="dev-surface__meta">
                <strong>{{ $manifest['base_url'] }}</strong>
                <small>
                    {{ translate('read_from_the_live_route_table') }} ·
                    <time datetime="{{ $manifest['generated_at'] }}">{{ \Carbon\Carbon::parse($manifest['generated_at'])->diffForHumans() }}</time>
                    @if ($manifest['app_version']) · v{{ $manifest['app_version'] }} @endif
                </small>
            </div>
        </div>

        <div class="dev-layout">
            {{-- Section rail. A section this installation cannot serve is shown disabled with the
                 reason, not hidden: a developer looking for webhooks should learn there are none
                 rather than wonder where the menu item went. --}}
            <nav class="dev-rail" aria-label="{{ translate('developer_portal_sections') }}">
                @foreach ($navigation as $groupKey => $group)
                    <div class="dev-rail__group">
                        <span class="dev-rail__label">{{ translate($group['label']) }}</span>
                        @foreach ($group['sections'] as $key => $item)
                            @if ($item['available'])
                                <a class="dev-rail__item {{ $key === $section ? 'is-active' : '' }}"
                                   href="{{ route('admin.developer.section', ['section' => $key]) }}">
                                    {{ translate($item['label']) }}
                                </a>
                            @else
                                <span class="dev-rail__item is-unavailable" title="{{ translate('not_available_on_this_installation') }}">
                                    {{ translate($item['label']) }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </nav>

            <main class="dev-body">
                @includeFirst(
                    ['admin-views.telemetry.developer.' . $section, 'admin-views.telemetry.developer._placeholder'],
                    ['data' => $data, 'section' => $section, 'meta' => $meta, 'manifest' => $manifest]
                )
            </main>
        </div>
    </div>
@endsection
