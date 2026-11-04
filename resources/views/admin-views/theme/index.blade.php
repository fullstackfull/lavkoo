@extends('layouts.admin.app')

@section('title', translate('Theme_Management'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="h1 mb-0 text-capitalize">{{ translate('Theme_Management') }}</h2>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('admin.theme.settings.index') }}" class="btn btn-outline-primary">
                    {{ translate('Theme_Settings') }}
                </a>
                <a href="{{ route('admin.theme.builder.index') }}" class="btn btn-primary">
                    {{ translate('Open_Theme_Builder') }}
                </a>
            </div>
        </div>

        <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span>
                <strong>{{ translate('this_is_the_only_theme_surface') }}.</strong>
                {{ translate('everything_about_the_storefront_look_lives_here_the_active_theme_its_versions_the_section_builder_and_the_colour_and_typography_settings') }}
            </span>
        </div>

        <div class="row g-3">
            {{-- Create theme --}}
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('Create_Theme') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.theme.store') }}" method="post">
                            @csrf
                            <div class="form-group">
                                <label class="form-label">{{ translate('theme_name') }} <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">{{ translate('description') }}</label>
                                <input type="text" name="description" class="form-control" value="{{ old('description') }}">
                            </div>
                            <button type="submit" class="btn btn-primary">{{ translate('create') }}</button>
                        </form>
                        <hr>
                        <h6>{{ translate('start_from_a_preset') }}</h6>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            @foreach($presets as $key => $preset)
                                <form action="{{ route('admin.theme.import-preset') }}" method="post">
                                    @csrf
                                    <input type="hidden" name="preset" value="{{ $key }}">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                        {{ translate($preset['label']) }}
                                    </button>
                                </form>
                            @endforeach
                        </div>

                        <h6>{{ translate('import_a_theme_file') }}</h6>
                        <form action="{{ route('admin.theme.import') }}" method="post" enctype="multipart/form-data" class="mb-2">
                            @csrf
                            <div class="form-group">
                                <input type="file" name="theme_file" class="form-control" accept="application/json,.json" required>
                                <small class="text-muted">{{ translate('imported_themes_are_created_inactive_as_a_draft_and_never_overwrite_an_existing_theme') }}</small>
                            </div>
                            <button type="submit" class="btn btn-sm btn-secondary">{{ translate('import') }}</button>
                            <a href="{{ route('admin.theme.example') }}" class="btn btn-sm btn-outline-secondary">
                                <i class="fi fi-rr-download"></i> {{ translate('download_example_theme') }}
                            </a>
                        </form>

                        {{-- Format help: this system uses its own JSON theme file (not a WordPress-style
                             zip). Source it three ways: a preset above, the Export button on any version,
                             or hand-authored JSON to the shape below. --}}
                        <details class="mb-3">
                            <summary class="small text-primary" style="cursor:pointer">{{ translate('what_file_format_is_expected') }}?</summary>
                            <div class="mt-2 small text-muted">
                                <p class="mb-1">{{ translate('a_json_file_in_this_shape_(not_a_zip)') }}:</p>
<pre class="p-2 rounded" style="background:#0f172a;color:#e2e8f0;font-size:.72rem;overflow:auto;direction:ltr;text-align:left">{
  "format_version": 1,
  "theme": { "name": "My theme", "description": "..." },
  "settings": {
    "colors":     { "primary": "#1c1917", "accent": "#b08d57" },
    "typography": { "base_font_size": 16, "line_height": 1.7 },
    "layout":     { "container_width": 1240, "border_radius": 2 }
  },
  "sections": [
    { "page": "home", "type": "hero_banner",   "sort_order": 1, "is_visible": true,
      "settings": { "height": 560 }, "blocks": [] },
    { "page": "home", "type": "product_slider", "sort_order": 2, "is_visible": true,
      "settings": { "title": "New Arrivals", "source": "new_arrival", "limit": 8 } }
  ]
}</pre>
                                <ul class="mb-0 ps-3">
                                    <li>{{ translate('start_from_a_preset_above_-_the_fastest_way') }}.</li>
                                    <li>{{ translate('or_click_export_on_any_theme_version_to_get_a_real_file_you_can_re-import') }}.</li>
                                    <li>{{ translate('or_download_the_example_theme_above_edit_it_and_import_it_back') }}.</li>
                                    <li>{{ translate('section_types') }}: hero_banner, category_grid, product_slider, promotional_banner, split_banner, banner_mosaic, banner_strip, store_banner, usp_strip, brand_slider, newsletter, custom_html, spacer.</li>
                                    <li>{{ translate('store_banner_renders_whatever_you_publish_in_promotion_banners_so_a_banner_added_there_appears_in_the_theme') }}.</li>
                                </ul>
                            </div>
                        </details>

                        <p class="text-muted mb-0 small">
                            {{ translate('a_new_theme_starts_with_an_empty_draft_version_publishing_a_draft_archives_the_previous_published_version_so_you_can_always_roll_back') }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- Themes list --}}
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h5 class="mb-0">{{ translate('Themes') }} <span class="badge badge-soft-primary">{{ $themes->total() }}</span></h5>
                        <form action="{{ route('admin.theme.index') }}" method="get" class="d-flex gap-2">
                            <input type="text" name="searchValue" class="form-control form-control-sm" value="{{ $search }}" placeholder="{{ translate('search') }}">
                            <button type="submit" class="btn btn-sm btn-primary">{{ translate('search') }}</button>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead>
                                    <tr>
                                        <th>{{ translate('theme') }}</th>
                                        <th class="text-center">{{ translate('versions') }}</th>
                                        <th class="text-center">{{ translate('status') }}</th>
                                        <th class="text-center">{{ translate('action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @forelse($themes as $theme)
                                    @php
                                        $published = $theme->versions->firstWhere('status', 'published');
                                        $latestDraft = $theme->versions->where('status', 'draft')->sortByDesc('id')->first();
                                    @endphp
                                    @php $findings = $readiness[$theme->id] ?? ['blocking' => [], 'warnings' => []]; @endphp
                                    <tr>
                                        <td>
                                            <div class="fw-bold">{{ $theme->name }}</div>
                                            <small class="text-muted"><code dir="ltr">{{ $theme->slug }}</code></small>
                                            @if($theme->is_system)
                                                <span class="badge badge-soft-secondary">{{ translate('system') }}</span>
                                            @endif

                                            {{-- Said here, where there is width to say it, and before the
                                                 publish button rather than after the live site shows it. --}}
                                            @if($findings['blocking'])
                                                <div class="alert alert-danger py-2 px-3 mt-2 mb-0 small">
                                                    <strong>{{ translate('this_draft_cannot_be_published_yet') }}</strong>
                                                    <ul class="mb-0 ps-3">
                                                        @foreach($findings['blocking'] as $finding)
                                                            <li>
                                                                <strong>{{ translate($finding['label']) }}</strong>
                                                                @if($finding['page'] !== 'home')<span class="text-muted">({{ translate($finding['page']) }})</span>@endif
                                                                — {{ translate($finding['reason_key']) }}. {{ translate($finding['fix_key']) }}.
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif
                                            @if($findings['warnings'])
                                                <div class="mt-2 small text-muted">
                                                    <strong>{{ translate('publishing_this_draft_as_it_is') }}:</strong>
                                                    <ul class="mb-0 ps-3">
                                                        @foreach($findings['warnings'] as $finding)
                                                            <li>@if($finding['label'] !== 'home'){{ translate($finding['label']) }} — @endif{{ translate($finding['reason_key']) }}.</li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-soft-info">{{ $theme->versions->count() }}</span>
                                            @if($published)
                                                <span class="badge badge-soft-success">{{ translate('published') }} #{{ $published->id }}</span>
                                                @if($published->change_note)
                                                    <div class="small text-muted mt-1">{{ $published->change_note }}</div>
                                                @endif
                                            @endif
                                            @if($latestDraft)
                                                <span class="badge badge-soft-warning">{{ translate('draft') }} #{{ $latestDraft->id }}</span>
                                                @if($latestDraft->isScheduled())
                                                    <span class="badge badge-soft-info" dir="auto">
                                                        {{ translate('publishes') }} {{ $latestDraft->publish_at->toDayDateTimeString() }}
                                                    </span>
                                                @endif
                                                @php $report = $compatibility[$theme->id] ?? null; @endphp
                                                @if($report && $report['sections'] > 0)
                                                    <span class="badge {{ empty($report['withheld']) ? 'badge-soft-success' : 'badge-soft-danger' }}"
                                                          title="{{ empty($report['withheld'])
                                                              ? translate('every_section_of_this_draft_renders_in_the_mobile_app')
                                                              : translate('sections_the_app_cannot_show') . ': ' . collect($report['withheld'])->map(fn ($gap) => translate($gap['label']) . ($gap['count'] > 1 ? ' ×' . $gap['count'] : ''))->implode(', ') }}">
                                                        {{ translate('app') }} {{ $report['app_supported'] }}/{{ $report['sections'] }}
                                                    </span>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($theme->is_active)
                                                <span class="badge badge-soft-success">{{ translate('active') }}</span>
                                            @else
                                                <span class="badge badge-soft-secondary">{{ translate('inactive') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex flex-wrap gap-1 justify-content-center">
                                                @if(!$theme->is_active)
                                                    <form action="{{ route('admin.theme.activate') }}" method="post">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $theme->id }}">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">{{ translate('activate') }}</button>
                                                    </form>
                                                @endif
                                                @if($latestDraft)
                                                    @php $report = $compatibility[$theme->id] ?? null; @endphp
                                                    @if($findings['blocking'])
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                                                title="{{ translate('fix_the_sections_listed_beside_this_theme_first') }}">
                                                            {{ translate('publish_draft') }}
                                                        </button>
                                                    @else
                                                        <form action="{{ route('admin.theme.version.publish') }}" method="post"
                                                              class="d-flex flex-column gap-1"
                                                              onsubmit="return confirm('{{ !empty($report['withheld'])
                                                                  ? translate('publish_this_draft') . '? ' . count($report['withheld']) . ' ' . translate('section_types_will_not_appear_in_the_mobile_app')
                                                                  : translate('publish_this_draft') . '?' }}')">
                                                            @csrf
                                                            <input type="hidden" name="version_id" value="{{ $latestDraft->id }}">
                                                            {{-- The sentence that makes a rollback three months from now a choice
                                                                 rather than a guess between two version numbers. --}}
                                                            <input type="text" name="change_note" maxlength="300"
                                                                   class="form-control form-control-sm"
                                                                   placeholder="{{ translate('what_changed_optional') }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-success">{{ translate('publish_draft') }}</button>
                                                        </form>

                                                        {{-- Or at a chosen hour, with nobody at a keyboard for it. The same
                                                             check runs again when it fires: hours pass, and a section that
                                                             was fine when the time was set can be broken by the time it
                                                             arrives. --}}
                                                        @if($latestDraft->isScheduled())
                                                            <form action="{{ route('admin.theme.version.schedule') }}" method="post">
                                                                @csrf
                                                                <input type="hidden" name="version_id" value="{{ $latestDraft->id }}">
                                                                <input type="hidden" name="cancel" value="1">
                                                                <button type="submit" class="btn btn-sm btn-outline-secondary">{{ translate('cancel_scheduled_publish') }}</button>
                                                            </form>
                                                        @elseif($schedulerOk)
                                                            <form action="{{ route('admin.theme.version.schedule') }}" method="post"
                                                                  class="d-flex flex-column gap-1">
                                                                @csrf
                                                                <input type="hidden" name="version_id" value="{{ $latestDraft->id }}">
                                                                <input type="datetime-local" name="publish_at" class="form-control form-control-sm">
                                                                <button type="submit" class="btn btn-sm btn-outline-info">{{ translate('publish_later') }}</button>
                                                            </form>
                                                        @else
                                                            <small class="text-muted d-block" style="max-width:12rem">
                                                                {{ translate('scheduled_publishing_needs_the_server_cron_to_be_running') }}
                                                            </small>
                                                        @endif
                                                    @endif
                                                @endif
                                                @php $exportable = $published ?? $latestDraft; @endphp
                                                @if($exportable)
                                                    <a href="{{ route('admin.theme.version.export', ['version_id' => $exportable->id]) }}"
                                                       class="btn btn-sm btn-outline-secondary">{{ translate('export') }}</a>
                                                @endif
                                                @foreach($theme->versions->where('status', 'archived')->sortByDesc('id')->take(3) as $archived)
                                                    <form action="{{ route('admin.theme.version.restore') }}" method="post"
                                                          onsubmit="return confirm('{{ translate('restore_this_version_into_a_new_draft') }}?')">
                                                        @csrf
                                                        <input type="hidden" name="version_id" value="{{ $archived->id }}">
                                                        <button type="submit" class="btn btn-sm btn-outline-warning"
                                                                title="{{ $archived->change_note ?: ($archived->published_at?->toDayDateTimeString() ?? translate('never_published')) }}">
                                                            {{ translate('restore') }} #{{ $archived->id }}
                                                        </button>
                                                    </form>
                                                @endforeach
                                                @if($published)
                                                    <form action="{{ route('admin.theme.version.duplicate') }}" method="post">
                                                        @csrf
                                                        <input type="hidden" name="version_id" value="{{ $published->id }}">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ translate('duplicate_to_draft') }}</button>
                                                    </form>
                                                @endif
                                                @if(!$theme->is_active && !$theme->is_system)
                                                    <form action="{{ route('admin.theme.delete') }}" method="post"
                                                          onsubmit="return confirm('{{ translate('delete_this_theme_and_all_its_versions') }}?')">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $theme->id }}">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>

                                @empty
                                    <tr><td colspan="4" class="text-center py-4 text-muted">{{ translate('no_themes_yet') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @if($themes->hasPages())
                        <div class="card-footer">{{ $themes->links() }}</div>
                    @endif
                </div>
            </div>

            {{-- Theme images.
                 Deliberately OUTSIDE the themes table: .table-responsive is a scroll container with a
                 constrained height, so an expanding panel inside a cell spilled past the card edge
                 (measured at 704px of table inside a 640px box). Its own card has no such constraint.

                 <details> rather than a Bootstrap collapse, because this admin serves Bootstrap 4.5
                 while other areas serve 5.x and the data-toggle/data-bs-toggle attribute differs
                 between them. <details> needs no JS at all. --}}
            @if($assetsReady)
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">{{ translate('Theme_Images') }}</h5>
                            <small class="text-muted">
                                {{ translate('upload_logos_favicons_and_backgrounds_then_paste_the_url_into_a_theme_setting') }}
                            </small>
                        </div>
                        <div class="card-body">
                            @forelse($themes as $theme)
                                <details class="v2-theme-assets mb-2 border rounded p-2" @if($loop->first) open @endif>
                                    <summary class="text-primary" style="cursor:pointer;">
                                        {{ $theme->name }}
                                        <span class="badge badge-soft-info">{{ $theme->assets->count() }}</span>
                                        @if($theme->is_active)
                                            <span class="badge badge-soft-success">{{ translate('active') }}</span>
                                        @endif
                                    </summary>

                                    <div class="pt-3">
                                        {{-- `row`, not BS4's `form-row`: this layout loads Bootstrap 5.3, where
                                             form-row and btn-block were removed and silently do nothing. --}}
                                        <form action="{{ route('admin.theme.asset.upload') }}" method="post"
                                              enctype="multipart/form-data" class="row align-items-end mb-3">
                                            @csrf
                                            <input type="hidden" name="theme_id" value="{{ $theme->id }}">
                                            <div class="col-md-5 mb-2">
                                                <label class="form-label mb-1" for="asset-file-{{ $theme->id }}">{{ translate('image') }}</label>
                                                <input type="file" id="asset-file-{{ $theme->id }}" name="asset" class="form-control" required
                                                       accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml,image/x-icon">
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <label class="form-label mb-1" for="asset-label-{{ $theme->id }}">{{ translate('label') }}</label>
                                                <input type="text" id="asset-label-{{ $theme->id }}" name="label" class="form-control" maxlength="120"
                                                       placeholder="{{ translate('for_example_header_logo') }}">
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <button type="submit" class="btn btn-primary w-100">{{ translate('upload') }}</button>
                                            </div>
                                            <div class="col-12">
                                                <small class="text-muted">
                                                    {{ translate('images_only_up_to') }}
                                                    {{ round($maxAssetSize / 1024 / 1024) }}MB.
                                                    {{ translate('the_file_type_is_verified_from_the_file_contents_not_its_name') }}
                                                </small>
                                            </div>
                                        </form>

                                        <div class="row">
                                            @forelse($theme->assets->sortByDesc('id') as $asset)
                                                <div class="col-xl-6 mb-2">
                                                    <div class="d-flex align-items-center gap-2 border rounded p-2 h-100">
                                                        <img src="{{ $asset->url }}" alt="{{ $asset->label ?? translate('theme_image') }}"
                                                             style="width:48px;height:48px;object-fit:contain;flex:0 0 auto;">
                                                        <div class="flex-grow-1 min-w-0">
                                                            <div class="fw-bold text-truncate">{{ $asset->label ?? translate('untitled') }}</div>
                                                            <input type="text" dir="ltr" readonly class="form-control form-control-sm"
                                                                   onfocus="this.select();" value="{{ $asset->url }}"
                                                                   title="{{ translate('copy_this_url_into_a_theme_setting') }}">
                                                            <small class="text-muted">
                                                                {{ $asset->mime_type }} · {{ $asset->size_for_humans }}
                                                            </small>
                                                        </div>
                                                        <form action="{{ route('admin.theme.asset.delete') }}" method="post"
                                                              onsubmit="return confirm('{{ translate('delete_this_image') }}?')">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $asset->id }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            @empty
                                                <div class="col-12">
                                                    <p class="text-muted mb-0 small">{{ translate('no_images_uploaded_for_this_theme_yet') }}</p>
                                                </div>
                                            @endforelse
                                        </div>
                                    </div>
                                </details>
                            @empty
                                <p class="text-muted mb-0">{{ translate('create_a_theme_first_then_upload_its_images_here') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
