@extends('layouts.admin.app')

@section('title', translate('Theme_Settings'))

@section('content')
    <div class="content container-fluid">
        <x-ui.page-header title="Theme_Settings" subtitle="branding_colors_typography_and_layout_for_the_storefront">
            <a href="{{ route('admin.theme.index') }}" class="btn btn-outline-secondary">{{ translate('Theme_Management') }}</a>
            @if($version)
                <a href="{{ route('admin.theme.builder.index', ['version' => $version->id]) }}" class="btn btn-primary">
                    {{ translate('Open_Theme_Builder') }}
                </a>
            @endif
        </x-ui.page-header>

        @if(!$version)
            <x-ui.empty-state title="no_theme_version_is_available_create_a_theme_first" icon="fi-rr-palette">
                <a href="{{ route('admin.theme.index') }}" class="btn btn-sm btn-primary">{{ translate('Theme_Management') }}</a>
            </x-ui.empty-state>
        @else
            @unless($editable)
                <div class="alert alert-warning">
                    {{ translate('this_version_is_published_and_read_only_duplicate_it_to_a_draft_to_edit') }}
                </div>
            @endunless

            <form action="{{ route('admin.theme.settings.update') }}" method="post">
                @csrf
                <input type="hidden" name="version_id" value="{{ $version->id }}">

                <div class="row g-3">
                    {{-- Branding --}}
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header"><h5 class="mb-0">{{ translate('branding') }}</h5></div>
                            <div class="card-body">
                                @foreach(['logo', 'mobile_logo', 'favicon'] as $field)
                                    <div class="form-group">
                                        <label class="form-label" for="branding-{{ $field }}">{{ translate($field) }}</label>
                                        <input type="text" dir="ltr" id="branding-{{ $field }}"
                                               name="settings[branding][{{ $field }}]"
                                               class="form-control" @disabled(!$editable)
                                               value="{{ $settings['branding'][$field] ?? '' }}"
                                               placeholder="https://…">

                                        {{-- Click an uploaded image to fill the field above. Without this the
                                             upload feature would only produce a URL to copy by hand. --}}
                                        @if($editable && $assets->isNotEmpty())
                                            <div class="d-flex flex-wrap gap-2 mt-2">
                                                @foreach($assets as $asset)
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-secondary p-1 js-pick-theme-asset"
                                                            data-target="branding-{{ $field }}"
                                                            data-url="{{ $asset->url }}"
                                                            title="{{ $asset->label ?? $asset->mime_type }}">
                                                        <img src="{{ $asset->url }}" alt="{{ $asset->label ?? translate('theme_image') }}"
                                                             style="width:34px;height:34px;object-fit:contain;">
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                                <div class="row">
                                    @foreach(['logo_width', 'logo_height'] as $field)
                                        <div class="col-6 form-group">
                                            <label class="form-label">{{ translate($field) }}</label>
                                            <input type="number" name="settings[branding][{{ $field }}]"
                                                   class="form-control" @disabled(!$editable)
                                                   value="{{ $settings['branding'][$field] ?? '' }}">
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Colors --}}
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header"><h5 class="mb-0">{{ translate('colors') }}</h5></div>
                            <div class="card-body">
                                <div class="row">
                                    @foreach($defaults['colors'] as $key => $default)
                                        <div class="col-6 col-xl-4 form-group">
                                            <label class="form-label">{{ translate($key) }}</label>
                                            <div class="d-flex align-items-center gap-2">
                                                <input type="color" name="settings[colors][{{ $key }}]"
                                                       class="form-control form-control-color p-1" @disabled(!$editable)
                                                       value="{{ $settings['colors'][$key] ?? $default }}"
                                                       aria-label="{{ translate($key) }}">
                                                <code dir="ltr" class="small">{{ $settings['colors'][$key] ?? $default }}</code>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Typography --}}
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="mb-0">{{ translate('typography') }}</h5>
                                <small class="text-muted">{{ translate('arabic_and_english_fonts_can_be_configured_separately') }}</small>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    @foreach(['heading_font' => 'english', 'body_font' => 'english', 'heading_font_ar' => 'arabic', 'body_font_ar' => 'arabic'] as $field => $scriptLabel)
                                        <div class="col-md-6 form-group">
                                            <label class="form-label">
                                                {{ translate($field) }}
                                                <span class="badge badge-soft-secondary">{{ translate($scriptLabel) }}</span>
                                            </label>
                                            <input type="text" dir="ltr" name="settings[typography][{{ $field }}]"
                                                   class="form-control" @disabled(!$editable)
                                                   value="{{ $settings['typography'][$field] ?? '' }}">
                                        </div>
                                    @endforeach
                                    <div class="col-6 form-group">
                                        <label class="form-label">{{ translate('base_font_size') }}</label>
                                        <input type="number" name="settings[typography][base_font_size]"
                                               class="form-control" @disabled(!$editable)
                                               value="{{ $settings['typography']['base_font_size'] ?? 16 }}">
                                    </div>
                                    <div class="col-6 form-group">
                                        <label class="form-label">{{ translate('line_height') }}</label>
                                        <input type="number" step="0.1" name="settings[typography][line_height]"
                                               class="form-control" @disabled(!$editable)
                                               value="{{ $settings['typography']['line_height'] ?? 1.6 }}">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Layout --}}
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header"><h5 class="mb-0">{{ translate('layout') }}</h5></div>
                            <div class="card-body">
                                <div class="row">
                                    @foreach(['container_width', 'page_spacing', 'border_radius'] as $field)
                                        <div class="col-md-4 form-group">
                                            <label class="form-label">{{ translate($field) }}</label>
                                            <input type="number" name="settings[layout][{{ $field }}]"
                                                   class="form-control" @disabled(!$editable)
                                                   value="{{ $settings['layout'][$field] ?? '' }}">
                                        </div>
                                    @endforeach
                                    @foreach(['button_style' => ['rounded','pill','square'], 'input_style' => ['outline','filled','underline'], 'card_style' => ['shadow','border','flat']] as $field => $options)
                                        <div class="col-md-4 form-group">
                                            <label class="form-label">{{ translate($field) }}</label>
                                            <select name="settings[layout][{{ $field }}]" class="form-control" @disabled(!$editable)>
                                                @foreach($options as $option)
                                                    <option value="{{ $option }}" @selected(($settings['layout'][$field] ?? '') === $option)>
                                                        {{ translate($option) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                @if($editable)
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary">{{ translate('save_draft') }}</button>
                        <span class="text-muted small align-self-center">
                            {{ translate('changes_apply_to_the_storefront_only_after_you_publish_the_draft') }}
                        </span>
                    </div>
                @endif
            </form>
        @endif
    </div>
@endsection

@push('script')
    <script>
        // Fill a branding URL field from an uploaded theme image. Delegated so it survives the
        // pjax navigation this admin uses, and written without jQuery/Bootstrap so it does not
        // depend on which of the two Bootstrap majors this page happens to load.
        document.addEventListener('click', function (event) {
            var picker = event.target.closest ? event.target.closest('.js-pick-theme-asset') : null;
            if (!picker) {
                return;
            }
            var field = document.getElementById(picker.getAttribute('data-target'));
            if (!field) {
                return;
            }
            field.value = picker.getAttribute('data-url') || '';
            field.dispatchEvent(new Event('change', {bubbles: true}));
        });
    </script>
@endpush
