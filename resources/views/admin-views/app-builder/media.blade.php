@extends('layouts.admin.app')

@section('title', translate('App_Builder_Media'))

@section('content')
    <div class="content container-fluid">
        @include('admin-views.app-builder._nav', ['current' => 'media'])

        @if (!$assetsReady)
            <div class="alert alert-warning">
                {{ translate('the_image_table_has_not_been_migrated_yet') }} —
                <code dir="ltr">php artisan migrate</code>
            </div>
        @elseif (!$theme)
            <div class="alert alert-info">
                {{ translate('create_a_theme_before_uploading_images') }}
                <a href="{{ route('admin.theme.index') }}">{{ translate('Theme_Management') }}</a>
            </div>
        @else
            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">{{ translate('upload_an_image') }}</h5></div>
                        <div class="card-body">
                            @if ($editable)
                                {{-- The theme's own upload action: the App Builder adds a door, never a
                                     second copy of the room behind it. --}}
                                <form action="{{ route('admin.theme.asset.upload') }}" method="post"
                                      enctype="multipart/form-data">
                                    @csrf
                                    <input type="hidden" name="theme_id" value="{{ $theme->id }}">
                                    <div class="mb-3">
                                        <label class="form-label" for="ab-asset-file">{{ translate('image') }}</label>
                                        <input type="file" id="ab-asset-file" name="asset" class="form-control" required
                                               accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml,image/x-icon">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="ab-asset-label">{{ translate('label') }}</label>
                                        <input type="text" id="ab-asset-label" name="label" class="form-control" maxlength="120"
                                               placeholder="{{ translate('for_example_ramadan_banner') }}">
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">{{ translate('upload') }}</button>
                                    <small class="text-muted d-block mt-2">
                                        {{ translate('images_only_up_to') }} {{ round($maxAssetSize / 1024 / 1024) }}MB.
                                        {{ translate('the_file_type_is_verified_from_the_file_contents_not_its_name') }}
                                    </small>
                                </form>
                            @else
                                <p class="text-muted mb-0">{{ translate('you_do_not_have_permission_to_edit_a_theme') }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        {{ translate('copy_an_image_url_here_and_paste_it_into_any_image_field_in_the_composer') }}
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                {{ translate('images') }}
                                <span class="badge badge-soft-primary">{{ $theme->assets->count() }}</span>
                            </h5>
                            <small class="text-muted">{{ $theme->name }}</small>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                @forelse ($theme->assets->sortByDesc('id') as $asset)
                                    <div class="col-xl-6 mb-2">
                                        <div class="d-flex align-items-center gap-2 border rounded p-2 h-100">
                                            <img src="{{ $asset->url }}" alt="{{ $asset->label ?? translate('theme_image') }}"
                                                 style="width:48px;height:48px;object-fit:contain;flex:0 0 auto;">
                                            <div class="flex-grow-1 min-w-0">
                                                <div class="fw-bold text-truncate">{{ $asset->label ?? translate('untitled') }}</div>
                                                <input type="text" dir="ltr" readonly class="form-control form-control-sm"
                                                       onfocus="this.select();" value="{{ $asset->url }}"
                                                       title="{{ translate('copy_this_url_into_an_image_field') }}">
                                                <small class="text-muted">
                                                    {{ $asset->mime_type }} · {{ $asset->size_for_humans }}
                                                </small>
                                            </div>
                                            @if ($editable)
                                                <form action="{{ route('admin.theme.asset.delete') }}" method="post"
                                                      onsubmit="return confirm('{{ translate('delete_this_image') }}?')">
                                                    @csrf
                                                    <input type="hidden" name="id" value="{{ $asset->id }}">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="col-12 text-center py-4 text-muted">
                                        {{ translate('no_images_uploaded_yet_upload_one_and_its_url_works_in_every_image_field') }}
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
