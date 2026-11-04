@extends('layouts.admin.app')

@section('title', translate('SEO_Templates'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/seo-settings.svg') }}" alt="">
                {{ translate('SEO_Settings') }}
            </h2>
        </div>

        @include('admin-views.seo-settings._inline-menu')

        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('Add_or_Update_Template') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.seo-settings.templates.save') }}" method="post">
                            @csrf
                            <div class="form-group">
                                <label class="form-label">{{ translate('entity_type') }} <span class="text-danger">*</span></label>
                                <select name="entity_type" id="entity_type" class="form-control" required>
                                    @foreach($variables as $entity => $vars)
                                        <option value="{{ $entity }}">{{ translate($entity) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">{{ translate('language') }} <span class="text-danger">*</span></label>
                                <select name="language_code" class="form-control" required>
                                    @foreach($languages as $lang)
                                        <option value="{{ is_array($lang) ? ($lang['code'] ?? '') : $lang }}">
                                            {{ is_array($lang) ? ($lang['code'] ?? '') : $lang }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">{{ translate('title_template') }}</label>
                                <input type="text" name="title_template" id="title_template" class="form-control" dir="ltr"
                                       placeholder="{product_name} | {store_name}">
                            </div>
                            <div class="form-group">
                                <label class="form-label">{{ translate('description_template') }}</label>
                                <textarea name="description_template" id="description_template" class="form-control" rows="3" dir="ltr"></textarea>
                            </div>
                            <div class="form-group form-check">
                                <input type="checkbox" name="is_active" id="tpl_active" class="form-check-input" value="1" checked>
                                <label class="form-check-label" for="tpl_active">{{ translate('active') }}</label>
                            </div>
                            <button type="submit" class="btn btn-primary">{{ translate('save') }}</button>
                        </form>
                    </div>
                </div>

                {{-- Available variables --}}
                <div class="card mt-3">
                    <div class="card-header"><h6 class="mb-0">{{ translate('available_variables') }}</h6></div>
                    <div class="card-body">
                        @foreach($variables as $entity => $vars)
                            <div class="mb-2 entity-vars" data-entity="{{ $entity }}">
                                <strong class="d-block mb-1">{{ translate($entity) }}</strong>
                                @foreach($vars as $v)
                                    <button type="button" class="btn btn-sm btn-outline-secondary mb-1 insert-var" data-var="{{ '{'.$v.'}' }}">
                                        <code dir="ltr">{{ '{'.$v.'}' }}</code>
                                    </button>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                {{-- Google-style preview --}}
                <div class="card mb-3">
                    <div class="card-header"><h6 class="mb-0">{{ translate('search_result_preview') }}</h6></div>
                    <div class="card-body">
                        <div class="border rounded p-3">
                            <div id="preview-title" style="color:#1a0dab;font-size:18px;line-height:1.3">{{ translate('your_seo_title_will_appear_here') }}</div>
                            <div style="color:#006621;font-size:13px">{{ url('/') }}</div>
                            <div id="preview-desc" style="color:#545454;font-size:13px">{{ translate('your_meta_description_will_appear_here') }}</div>
                        </div>
                        <div class="mt-2 small">
                            <span id="title-advice" class="text-muted"></span>
                            <span id="desc-advice" class="text-muted ms-3"></span>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('Existing_Templates') }}</h5></div>
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead>
                                    <tr>
                                        <th>{{ translate('entity') }}</th>
                                        <th>{{ translate('language') }}</th>
                                        <th>{{ translate('title_template') }}</th>
                                        <th class="text-center">{{ translate('status') }}</th>
                                        <th class="text-center">{{ translate('action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @forelse($templates as $tpl)
                                    <tr>
                                        <td>{{ translate($tpl->entity_type) }}</td>
                                        <td><span class="badge badge-soft-info">{{ $tpl->language_code }}</span></td>
                                        <td><code dir="ltr">{{ \Illuminate\Support\Str::limit($tpl->title_template, 40) }}</code></td>
                                        <td class="text-center">
                                            <span class="badge {{ $tpl->is_active ? 'badge-soft-success' : 'badge-soft-secondary' }}">
                                                {{ $tpl->is_active ? translate('active') : translate('inactive') }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="{{ route('admin.seo-settings.templates.delete', ['id' => $tpl->id]) }}"
                                               class="btn btn-sm btn-outline-danger square-btn"
                                               onclick="return confirm('{{ translate('are_you_sure') }}?')">
                                                <i class="fi fi-rr-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center py-4 text-muted">{{ translate('no_templates_yet') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
        "use strict";
        (function () {
            var titleInput = document.getElementById('title_template');
            var descInput  = document.getElementById('description_template');
            var previewUrl = "{{ route('admin.seo-settings.templates.preview') }}";
            var lastFocused = titleInput;

            function advise(el, adviceEl, type) {
                if (!el) return;
                fetch(previewUrl + '?template=' + encodeURIComponent(el.value || '') + '&type=' + type, {
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                }).then(function (r) { return r.json(); }).then(function (data) {
                    var target = document.getElementById(type === 'title' ? 'preview-title' : 'preview-desc');
                    if (target && data.rendered) target.textContent = data.rendered;
                    if (adviceEl && data.advice) {
                        adviceEl.textContent = type + ': ' + data.advice.length + ' (' + data.advice.status + ')';
                        adviceEl.className = 'small ' + (data.advice.status === 'ok' ? 'text-success' : 'text-warning');
                    }
                }).catch(function () { /* preview is advisory only */ });
            }

            if (titleInput) {
                titleInput.addEventListener('focus', function () { lastFocused = titleInput; });
                titleInput.addEventListener('input', function () {
                    advise(titleInput, document.getElementById('title-advice'), 'title');
                });
            }
            if (descInput) {
                descInput.addEventListener('focus', function () { lastFocused = descInput; });
                descInput.addEventListener('input', function () {
                    advise(descInput, document.getElementById('desc-advice'), 'description');
                });
            }

            document.querySelectorAll('.insert-var').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var target = lastFocused || titleInput;
                    if (!target) return;
                    target.value += btn.dataset.var;
                    target.dispatchEvent(new Event('input'));
                    target.focus();
                });
            });
        })();
    </script>
@endpush
