@extends('layouts.admin.app')

@section('title', translate('App_Builder_Pages'))

@section('content')
    <div class="content container-fluid">
        @include('admin-views.app-builder._nav', ['current' => 'pages'])

        @if (!$ready)
            <div class="alert alert-warning">
                {{ translate('the_pages_table_has_not_been_migrated_yet_run_php_artisan_migrate_to_manage_pages') }}
            </div>
        @elseif (!$theme)
            <div class="alert alert-info">
                {{ translate('create_a_theme_before_adding_pages') }}
                <a href="{{ route('admin.theme.index') }}">{{ translate('Theme_Management') }}</a>
            </div>
        @else
            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">{{ translate('pages') }} <span class="badge badge-soft-primary">{{ count($pages) }}</span></h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="k-table-wrap">
                                <table class="k-table">
                                    <thead>
                                        <tr>
                                            <th>{{ translate('page') }}</th>
                                            <th class="text-center">{{ translate('channel') }}</th>
                                            <th class="text-center">{{ translate('status') }}</th>
                                            <th class="text-center">{{ translate('action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    @forelse ($pages as $page)
                                        <tr>
                                            <td>
                                                <div class="fw-bold">{{ translate($page['title']) }}</div>
                                                <small class="text-muted"><code dir="ltr">{{ $page['slug'] }}</code></small>
                                                @if ($page['kind'] === 'system')
                                                    {{-- A built-in page is one the engine guarantees: the storefront and
                                                         the app both look for it by name, so it can be renamed and never
                                                         removed. --}}
                                                    <span class="badge badge-soft-secondary">{{ translate('built_in') }}</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-soft-info">{{ translate($page['channel']) }}</span>
                                            </td>
                                            <td class="text-center">
                                                @if ($page['enabled'])
                                                    <span class="badge badge-soft-success">{{ translate('on') }}</span>
                                                @else
                                                    <span class="badge badge-soft-warning">{{ translate('off') }}</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex flex-wrap gap-1 justify-content-center">
                                                    @if ($draft)
                                                        <a class="btn btn-sm btn-outline-primary"
                                                           href="{{ route('admin.theme.builder.index', ['page' => $page['slug'], 'version' => $draft->id, 'channel' => $channel]) }}">
                                                            {{ translate('compose') }}
                                                        </a>
                                                    @endif

                                                    @if ($editable && $page['kind'] !== 'system')
                                                        <form action="{{ route('admin.app-builder.pages.update') }}" method="post">
                                                            @csrf
                                                            <input type="hidden" name="page_id" value="{{ $page['id'] ?? '' }}">
                                                            <input type="hidden" name="enabled" value="{{ $page['enabled'] ? 0 : 1 }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                                                {{ $page['enabled'] ? translate('turn_off') : translate('turn_on') }}
                                                            </button>
                                                        </form>
                                                        <form action="{{ route('admin.app-builder.pages.delete') }}" method="post"
                                                              onsubmit="return confirm('{{ translate('delete_this_page_and_its_sections') }}?')">
                                                            @csrf
                                                            <input type="hidden" name="page_id" value="{{ $page['id'] ?? '' }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center py-4 text-muted">{{ translate('no_pages_yet') }}</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">{{ translate('add_a_page') }}</h5></div>
                        <div class="card-body">
                            @if ($editable)
                                <form action="{{ route('admin.app-builder.pages.store') }}" method="post">
                                    @csrf
                                    <div class="mb-3">
                                        <label class="form-label">{{ translate('name') }}</label>
                                        <input type="text" name="title" class="form-control" required maxlength="120"
                                               placeholder="{{ translate('offers') }}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">{{ translate('address_optional') }}</label>
                                        <input type="text" name="slug" class="form-control" dir="ltr" maxlength="60"
                                               placeholder="offers">
                                        <small class="text-muted">{{ translate('left_empty_it_is_made_from_the_name') }}</small>
                                    </div>
                                    <div class="form-check mb-3">
                                        <input type="checkbox" name="shared" value="1" class="form-check-input" id="ab-shared">
                                        <label class="form-check-label" for="ab-shared">
                                            {{ translate('the_website_and_the_app_both_show_this_page') }}
                                        </label>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">{{ translate('add_page') }}</button>
                                </form>
                            @else
                                <p class="text-muted mb-0">{{ translate('you_do_not_have_permission_to_edit_a_theme') }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        {{-- Said here because it is the question a merchant asks the moment they add
                             a second page: the app has to be able to reach it. --}}
                        {{ translate('a_new_page_is_composed_here_and_served_by_the_api_the_app_reaches_it_once_a_release_links_to_it') }}
                    </div>

                    <div class="card mt-3">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <h5 class="mb-0">{{ translate('server_readiness') }}</h5>
                            @if ($allGood)
                                <span class="badge badge-soft-success">{{ translate('all_good') }}</span>
                            @else
                                <span class="badge badge-soft-danger">{{ translate('needs_attention') }}</span>
                            @endif
                        </div>
                        <div class="card-body">
                            {{-- The builder's promises that depend on the server: a scheduled publish
                                 needs the cron, the reach numbers need the rollup. Each failing row
                                 carries its own fix, so "verify it on the server" is a checklist the
                                 merchant can read rather than a log only a developer can. --}}
                            <ul class="list-unstyled mb-0 d-flex flex-column gap-3">
                                @foreach ($health as $check)
                                    <li class="d-flex gap-2">
                                        @if ($check['ok'])
                                            <i class="fi fi-sr-check-circle text-success mt-1"></i>
                                        @else
                                            <i class="fi fi-sr-cross-circle text-danger mt-1"></i>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="{{ $check['ok'] ? '' : 'fw-bold' }}">{{ translate($check['label']) }}</div>
                                            @if (!$check['ok'] && $check['why'])
                                                <small class="text-muted d-block">{{ translate($check['why']) }}</small>
                                            @endif
                                            @if (!$check['ok'] && $check['fix'])
                                                <code dir="ltr" class="d-block text-break small mt-1">{{ $check['fix'] }}</code>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
