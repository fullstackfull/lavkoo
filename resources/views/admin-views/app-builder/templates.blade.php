@extends('layouts.admin.app')

@section('title', translate('App_Builder_Templates'))

@section('content')
    <div class="content container-fluid">
        @include('admin-views.app-builder._nav', ['current' => 'templates'])

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">{{ translate('start_from_a_template') }}</h5>
                        <small class="text-muted">
                            {{ translate('a_template_becomes_a_new_draft_theme_it_never_touches_what_is_live') }}
                        </small>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            @foreach ($presets as $key => $preset)
                                @php
                                    $sections = $preset['payload']['sections'] ?? [];
                                    $colors = $preset['payload']['settings']['colors'] ?? [];
                                @endphp
                                <div class="col-md-6 col-xl-4 mb-3">
                                    <div class="border rounded p-3 h-100 d-flex flex-column">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            @foreach (array_slice(array_values($colors), 0, 3) as $color)
                                                <span style="width:18px;height:18px;border-radius:50%;flex:0 0 auto;
                                                             border:1px solid rgba(0,0,0,.1);background:{{ $color }};"></span>
                                            @endforeach
                                            <span class="fw-bold">{{ translate($preset['label']) }}</span>
                                        </div>
                                        <small class="text-muted mb-3">
                                            {{ count($sections) }} {{ translate('sections_across') }}
                                            {{ count(array_unique(array_column($sections, 'page'))) }} {{ translate('pages') }}
                                        </small>
                                        @if ($editable)
                                            <form action="{{ route('admin.theme.import-preset') }}" method="post" class="mt-auto">
                                                @csrf
                                                <input type="hidden" name="preset" value="{{ $key }}">
                                                <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                                                    {{ translate('create_a_draft_from_this') }}
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('theme_files') }}</h5></div>
                    <div class="card-body">
                        {{-- Export what is composed, carry it to another installation, import it there.
                             The same three actions Theme Management offers, reachable from where the
                             composing happens. --}}
                        @if ($exportable)
                            <a href="{{ route('admin.theme.version.export', ['version_id' => $exportable->id]) }}"
                               class="btn btn-outline-secondary w-100 mb-2">
                                {{ translate('export_the_current_experience') }}
                            </a>
                        @endif

                        @if ($editable)
                            <form action="{{ route('admin.theme.import') }}" method="post"
                                  enctype="multipart/form-data" class="mb-2">
                                @csrf
                                <label class="form-label" for="ab-theme-file">{{ translate('import_a_theme_file') }}</label>
                                <input type="file" id="ab-theme-file" name="theme_file" class="form-control mb-2"
                                       accept=".json,application/json" required>
                                <button type="submit" class="btn btn-outline-secondary w-100">{{ translate('import') }}</button>
                                <small class="text-muted d-block mt-1">
                                    {{ translate('imported_themes_are_created_inactive_as_a_draft_and_never_overwrite_an_existing_theme') }}
                                </small>
                            </form>
                        @endif

                        <a href="{{ route('admin.theme.example') }}" class="btn btn-link w-100">
                            {{ translate('download_the_annotated_example_file') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
