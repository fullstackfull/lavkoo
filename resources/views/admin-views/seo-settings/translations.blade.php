@extends('layouts.admin.app')

@section('title', translate('SEO_Content'))

@section('content')
    <div class="content container-fluid">
        <x-ui.page-header title="SEO_Settings" />
        @include('admin-views.seo-settings._inline-menu')

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('Per_Language_SEO') }}</h5></div>
                    <div class="card-body">
                        {{-- entity picker --}}
                        <form action="{{ route('admin.seo-settings.translations.index') }}" method="get" class="row g-2 mb-4">
                            <div class="col-md-4">
                                <label class="form-label">{{ translate('entity_type') }}</label>
                                <select name="entity_type" class="form-control">
                                    @foreach($entityTypes as $t)
                                        <option value="{{ $t }}" @selected($entityType === $t)>{{ translate($t) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ translate('entity_id') }}</label>
                                <input type="number" name="entity_id" class="form-control" min="1" value="{{ $entityId }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ translate('language') }}</label>
                                <select name="language" class="form-control">
                                    @foreach($languages as $lang)
                                        <option value="{{ $lang }}" @selected($language === $lang)>{{ $lang }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">{{ translate('load') }}</button>
                            </div>
                        </form>

                        @if($entityType && $entityId)
                            <form action="{{ route('admin.seo-settings.translations.save') }}" method="post">
                                @csrf
                                <input type="hidden" name="entity_type" value="{{ $entityType }}">
                                <input type="hidden" name="entity_id" value="{{ $entityId }}">
                                <input type="hidden" name="language_code" value="{{ $language }}">

                                @php $isRtl = in_array($language, ['ar','sa','sy']); @endphp

                                <div class="form-group">
                                    <label class="form-label">{{ translate('SEO_Title') }}</label>
                                    <input type="text" name="title" class="form-control" @if($isRtl) dir="rtl" @endif
                                           value="{{ old('title', $existing->title ?? '') }}">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">{{ translate('meta_description') }}</label>
                                    <textarea name="description" rows="3" class="form-control" @if($isRtl) dir="rtl" @endif>{{ old('description', $existing->description ?? '') }}</textarea>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label class="form-label">{{ translate('meta_keywords') }}</label>
                                        <input type="text" name="keywords" class="form-control" value="{{ old('keywords', $existing->keywords ?? '') }}">
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label class="form-label">{{ translate('canonical_url') }}</label>
                                        <input type="text" dir="ltr" name="canonical_url" class="form-control" value="{{ old('canonical_url', $existing->canonical_url ?? '') }}">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label class="form-label">{{ translate('og_title') }}</label>
                                        <input type="text" name="og_title" class="form-control" @if($isRtl) dir="rtl" @endif value="{{ old('og_title', $existing->og_title ?? '') }}">
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label class="form-label">{{ translate('og_image') }}</label>
                                        <input type="text" dir="ltr" name="og_image" class="form-control" value="{{ old('og_image', $existing->og_image ?? '') }}">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">{{ translate('og_description') }}</label>
                                    <textarea name="og_description" rows="2" class="form-control" @if($isRtl) dir="rtl" @endif>{{ old('og_description', $existing->og_description ?? '') }}</textarea>
                                </div>
                                <div class="d-flex flex-wrap gap-3 mb-3">
                                    {{-- Hidden 0 paired with each checkbox: an unchecked box is simply
                                         absent from the POST, which is indistinguishable from "not
                                         supplied". Without this, un-ticking would silently do nothing. --}}
                                    <div class="form-check">
                                        <input type="hidden" name="is_indexable" value="0">
                                        <input type="checkbox" name="is_indexable" value="1" class="form-check-input" id="idx"
                                               @checked(old('is_indexable', $existing->is_indexable ?? true))>
                                        <label class="form-check-label" for="idx">{{ translate('indexable') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="hidden" name="is_followable" value="0">
                                        <input type="checkbox" name="is_followable" value="1" class="form-check-input" id="fol"
                                               @checked(old('is_followable', $existing->is_followable ?? true))>
                                        <label class="form-check-label" for="fol">{{ translate('followable') }}</label>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">{{ translate('save') }}</button>
                            </form>
                        @else
                            <x-ui.empty-state title="choose_an_entity_and_language_to_edit_its_seo" icon="fi-rr-search" />
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                {{-- resolved preview: shows which layer supplied each value --}}
                @if($resolved)
                    <div class="card mb-3">
                        <div class="card-header"><h6 class="mb-0">{{ translate('resolved_result') }} ({{ $language }})</h6></div>
                        <div class="card-body">
                            <div class="border rounded p-3">
                                <div style="color:#1a0dab;font-size:18px">{{ $resolved['title'] ?: translate('no_title_resolved') }}</div>
                                <div style="color:#545454;font-size:13px">{{ $resolved['description'] ?: translate('no_description_resolved') }}</div>
                            </div>
                            <div class="mt-2 small">
                                <div>{{ translate('title_source') }}: <span class="badge badge-soft-info">{{ $resolved['source']['title'] ?? 'none' }}</span>
                                    @if($titleAdvice)
                                        <span class="badge {{ $titleAdvice['status'] === 'ok' ? 'badge-soft-success' : 'badge-soft-warning' }}">
                                            {{ $titleAdvice['length'] }} · {{ translate($titleAdvice['status']) }}
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-1">{{ translate('description_source') }}: <span class="badge badge-soft-info">{{ $resolved['source']['description'] ?? 'none' }}</span>
                                    @if($descAdvice)
                                        <span class="badge {{ $descAdvice['status'] === 'ok' ? 'badge-soft-success' : 'badge-soft-warning' }}">
                                            {{ $descAdvice['length'] }} · {{ translate($descAdvice['status']) }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <p class="text-muted small mt-2 mb-0">
                                {{ translate('resolution_order_translation_then_existing_record_then_template') }}
                            </p>
                        </div>
                    </div>
                @endif

                <div class="card">
                    <div class="card-header"><h6 class="mb-0">{{ translate('recent_entries') }}</h6></div>
                    <div class="card-body p-0">
                        <x-ui.data-table>
                            <thead class="thead-light">
                                <tr>
                                    <th>{{ translate('entity') }}</th>
                                    <th>{{ translate('language') }}</th>
                                    <th>{{ translate('title') }}</th>
                                    <th class="text-center">{{ translate('action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($recent as $row)
                                <tr>
                                    <td><small>{{ class_basename($row->seoable_type) }} #{{ $row->seoable_id }}</small></td>
                                    <td><span class="badge badge-soft-info">{{ $row->language_code }}</span></td>
                                    <td><small>{{ \Illuminate\Support\Str::limit($row->title, 30) }}</small></td>
                                    <td class="text-center">
                                        <a href="{{ route('admin.seo-settings.translations.delete', ['id' => $row->id]) }}"
                                           class="btn btn-sm btn-outline-danger square-btn"
                                           aria-label="{{ translate('delete') }}"
                                           onclick="return confirm('{{ translate('are_you_sure') }}?')">
                                            <i class="fi fi-rr-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4"><x-ui.empty-state title="no_seo_entries_yet" /></td></tr>
                            @endforelse
                            </tbody>
                        </x-ui.data-table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
