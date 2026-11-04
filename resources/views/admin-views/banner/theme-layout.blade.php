@extends('layouts.admin.app')

@section('title', translate('theme_banners_as_displayed'))

@push('css_or_js')
    <style>
        /* Each section is drawn with the SAME arrangement the storefront uses — a mosaic's large
           tile is large here too — so the merchant recognises every picture at a glance. */
        .tl-section { border: 1px solid var(--bs-border-color, #eff1f4); border-radius: 12px; background: #fff; }
        .tl-section + .tl-section { margin-top: 1.25rem; }
        .tl-head { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; padding: .9rem 1.1rem; border-bottom: 1px solid var(--bs-border-color, #eff1f4); }
        .tl-head h5 { margin: 0; font-size: 1rem; }
        .tl-body { padding: 1.1rem; }

        .tl-card { position: relative; display: block; border-radius: 8px; overflow: hidden; background: #f6f7f9; border: 1px solid var(--bs-border-color, #eff1f4); min-height: 90px; }
        .tl-card img { width: 100%; height: 100%; object-fit: cover; display: block; position: absolute; inset: 0; }
        .tl-card__meta { position: absolute; inset-inline: 0; bottom: 0; z-index: 2; display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; padding: .45rem .6rem; background: linear-gradient(transparent, rgba(10, 14, 20, .78)); color: #fff; font-size: .72rem; }
        .tl-card__meta a { color: #fff; text-decoration: underline; }
        .tl-card__size { position: absolute; top: .45rem; inset-inline-start: .45rem; z-index: 2; padding: .15rem .55rem; border-radius: 999px; background: rgba(255, 255, 255, .92); font-size: .68rem; font-weight: 700; }
        .tl-card--empty { display: grid; place-items: center; color: #97a1af; font-size: .78rem; }

        /* hero: one wide frame per slide, in order */
        .tl-hero { display: flex; flex-direction: column; gap: .6rem; }
        .tl-hero .tl-card { aspect-ratio: 3.2 / 1; min-height: 110px; }

        /* grid: honours the section's column count */
        .tl-grid { display: grid; gap: .6rem; }
        .tl-grid .tl-card { aspect-ratio: 2 / 1; }

        /* mosaic: the storefront's exact spans — small 1x1, wide 2 cols, tall 2 rows, large 2x2 */
        .tl-mosaic { display: grid; grid-template-columns: repeat(4, 1fr); grid-auto-rows: 96px; gap: .6rem; }
        .tl-mosaic .tl-card { min-height: 0; }
        .tl-span--wide { grid-column: span 2; }
        .tl-span--tall { grid-row: span 2; }
        .tl-span--large { grid-column: span 2; grid-row: span 2; }

        /* split: two halves side by side */
        .tl-split { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
        .tl-split .tl-card { aspect-ratio: 1.6 / 1; }

        .tl-strip .tl-card { aspect-ratio: 4.5 / 1; min-height: 80px; }

        .tl-card--off { opacity: .55; }
        .tl-card--off img { filter: grayscale(.6); }
        .tl-off-flag { position: absolute; top: .45rem; inset-inline-end: .45rem; z-index: 3; padding: .15rem .55rem; border-radius: 999px; background: #dc3545; color: #fff; font-size: .68rem; font-weight: 700; }
        .tl-part-title { margin: 1.75rem 0 .75rem; font-size: 1.05rem; font-weight: 700; }
        .tl-strip-stack { display: flex; flex-direction: column; gap: .6rem; }
        .tl-strip-stack .tl-card { aspect-ratio: 4.5 / 1; min-height: 80px; }

        @media (max-width: 767px) {
            .tl-mosaic { grid-template-columns: repeat(2, 1fr); }
            .tl-span--large, .tl-span--wide { grid-column: span 2; }
        }
    </style>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <h2 class="h1 mb-1 text-capitalize">{{ translate('theme_banners_as_displayed') }}</h2>
                <p class="mb-0 fs-12 text-muted">
                    {{ translate('every_banner_the_theme_shows_arranged_exactly_as_the_storefront_lays_it_out_click_a_picture_to_edit_its_banner_or_open_its_section_in_the_builder') }}
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('admin.banner.list') }}" class="btn btn-outline-primary">{{ translate('banner_Setup') }}</a>
                @if (Route::has('admin.theme.builder.index'))
                    <a href="{{ route('admin.theme.builder.index') }}" class="btn btn-primary">{{ translate('Open_Theme_Builder') }}</a>
                @endif
            </div>
        </div>

        @forelse ($groups as $group)
            <div class="tl-section">
                <div class="tl-head">
                    <h5>{{ $group['label'] }}</h5>
                    <span class="badge badge-soft-info">{{ translate($group['page']) }}</span>
                    <span class="badge {{ $group['status'] === 'published' ? 'badge-soft-success' : 'badge-soft-warning' }}">
                        {{ $group['status'] === 'published' ? translate('published') : translate('draft') }}
                    </span>
                    @if (Route::has('admin.theme.builder.index'))
                        <a class="ms-auto fs-12"
                           href="{{ route('admin.theme.builder.index', ['page' => $group['page']]) }}">
                            {{ translate('open_this_section_in_the_builder') }}
                        </a>
                    @endif
                </div>
                <div class="tl-body">
                    @php
                        $wrapClass = match ($group['type']) {
                            'hero_banner'   => 'tl-hero',
                            'banner_mosaic' => 'tl-mosaic',
                            'split_banner'  => 'tl-split',
                            'banner_strip'  => 'tl-strip',
                            'store_banner'  => ($group['layout'] ?? '') === 'mosaic' ? 'tl-mosaic' : 'tl-grid',
                            default         => 'tl-grid',
                        };
                        $sizeNames = [
                            'small' => translate('small_tile'), 'wide' => translate('wide_tile'),
                            'tall'  => translate('tall_tile'),  'large' => translate('large_tile'),
                        ];
                    @endphp
                    <div class="{{ $wrapClass }}"
                         @if ($wrapClass === 'tl-grid') style="grid-template-columns: repeat({{ min(4, max(1, $group['columns'])) }}, 1fr)" @endif>
                        @foreach ($group['cards'] as $index => $card)
                            @php $span = $card['span'] ?? null; @endphp
                            <div class="tl-card {{ $span && $wrapClass === 'tl-mosaic' ? 'tl-span--' . $span : '' }} {{ empty($card['image']) ? 'tl-card--empty' : '' }}">
                                @if (!empty($card['image']))
                                    <img src="{{ $card['image'] }}" alt="{{ $card['title'] ?? '' }}" loading="lazy">
                                @else
                                    <span>{{ translate('no_image_yet') }}</span>
                                @endif

                                @if ($span && $wrapClass === 'tl-mosaic')
                                    <span class="tl-card__size">{{ $sizeNames[$span] ?? $span }}</span>
                                @elseif ($group['type'] === 'hero_banner')
                                    <span class="tl-card__size">{{ translate('slide') }} {{ $index + 1 }}</span>
                                @endif

                                <span class="tl-card__meta">
                                    @if (!empty($card['title']))<span>{{ Str::limit($card['title'], 30) }}</span>@endif
                                    @if (!empty($card['banner_id']))
                                        <span>#{{ $card['banner_id'] }}</span>
                                        <a href="{{ route('admin.banner.update', ['id' => $card['banner_id']]) }}">{{ translate('edit_banner') }}</a>
                                        @if (($card['published'] ?? true) === false)
                                            <span class="badge badge-soft-danger">{{ translate('unpublished') }}</span>
                                        @endif
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @empty
            <div class="card">
                <div class="card-body text-center py-4">
                    <p class="text-muted mb-2">{{ translate('the_theme_builder_shows_no_banner_sections_yet') }}</p>
                    @if (Route::has('admin.theme.builder.index'))
                        <a href="{{ route('admin.theme.builder.index') }}" class="btn btn-sm btn-outline-primary">{{ translate('Open_Theme_Builder') }}</a>
                    @endif
                </div>
            </div>
        @endforelse

        {{-- The BUILT-IN placements: category/brand page headers, the home promo grid, the main
             slider… — every banner listed, unpublished ones dimmed with the reason, so "I added it
             but it does not show" is answered right here. --}}
        <h4 class="tl-part-title">{{ translate('built_in_placements_outside_the_builder') }}</h4>

        @forelse ($placements as $group)
            <div class="tl-section">
                <div class="tl-head">
                    <h5>{{ $group['label'] }}</h5>
                    <span class="badge badge-soft-info">{{ $group['where'] }}</span>
                    @if (!empty($group['dropped_by_theme']))
                        <span class="badge badge-soft-danger">
                            {{ translate('hidden_by_the_composed_home_page') }}
                        </span>
                        @if (Route::has('admin.theme.builder.index'))
                            <a class="fs-12" href="{{ route('admin.theme.builder.index', ['page' => 'home']) }}">
                                {{ translate('add_its_section_in_the_builder') }}
                            </a>
                        @endif
                    @endif
                </div>
                <div class="tl-body">
                    <div class="{{ $group['shape'] === 'hero' ? 'tl-hero' : ($group['shape'] === 'grid' ? 'tl-grid' : 'tl-strip-stack') }}"
                         @if ($group['shape'] === 'grid') style="grid-template-columns: repeat(3, 1fr)" @endif>
                        @foreach ($group['cards'] as $index => $card)
                            <div class="tl-card {{ empty($card['image']) ? 'tl-card--empty' : '' }} {{ $card['published'] ? '' : 'tl-card--off' }}">
                                @if (!empty($card['image']))
                                    <img src="{{ $card['image'] }}" alt="{{ $card['title'] ?? '' }}" loading="lazy">
                                @else
                                    <span>{{ translate('no_image_yet') }}</span>
                                @endif

                                @if ($group['shape'] === 'hero')
                                    <span class="tl-card__size">{{ translate('slide') }} {{ $index + 1 }}</span>
                                @elseif (!empty($card['place']))
                                    <span class="tl-card__size">{{ $card['place'] }}</span>
                                @endif

                                @unless ($card['published'])
                                    <span class="tl-off-flag">{{ translate('unpublished_will_not_show') }}</span>
                                @endunless

                                <span class="tl-card__meta">
                                    @if (!empty($card['title']))<span>{{ Str::limit($card['title'], 30) }}</span>@endif
                                    <span>#{{ $card['banner_id'] }}</span>
                                    <a href="{{ route('admin.banner.update', ['id' => $card['banner_id']]) }}">{{ translate('edit_banner') }}</a>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @empty
            <div class="card"><div class="card-body text-center text-muted py-4">{{ translate('no_banners_created_yet') }}</div></div>
        @endforelse
    </div>
@endsection
