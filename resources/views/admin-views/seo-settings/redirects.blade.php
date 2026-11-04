@extends('layouts.admin.app')

@section('title', translate('SEO_Redirects'))

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
            {{-- Add / edit form --}}
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0" id="redirect-form-title">{{ translate('Add_Redirect') }}</h5>
                    </div>
                    <div class="card-body">
                        <form id="redirect-form" action="{{ route('admin.seo-settings.redirects.store') }}" method="post">
                            @csrf
                            <div class="form-group">
                                <label class="form-label">{{ translate('source_path') }} <span class="text-danger">*</span></label>
                                <input type="text" name="from_path" id="from_path" class="form-control" placeholder="/old-url" value="{{ old('from_path') }}" required>
                                <small class="text-muted">{{ translate('a_storefront_path_only_e_g') }} <code>/old-product</code></small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">{{ translate('target') }} <span class="text-danger">*</span></label>
                                <input type="text" name="to_path" id="to_path" class="form-control" placeholder="/new-url" value="{{ old('to_path') }}" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">{{ translate('redirect_type') }}</label>
                                <select name="status_code" id="status_code" class="form-control">
                                    <option value="301">301 — {{ translate('permanent') }}</option>
                                    <option value="302">302 — {{ translate('temporary') }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">{{ translate('match_type') }}</label>
                                <select name="match_type" id="match_type" class="form-control">
                                    <option value="exact">{{ translate('exact') }}</option>
                                    <option value="prefix">{{ translate('prefix') }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">{{ translate('note') }}</label>
                                <input type="text" name="note" id="note" class="form-control" value="{{ old('note') }}">
                            </div>
                            <div class="form-group form-check">
                                <input type="checkbox" name="is_active" id="is_active" class="form-check-input" value="1" checked>
                                <label class="form-check-label" for="is_active">{{ translate('active') }}</label>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">{{ translate('save') }}</button>
                                <button type="button" id="redirect-cancel-edit" class="btn btn-secondary d-none">{{ translate('cancel') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- List --}}
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h5 class="mb-0">{{ translate('Redirects') }} <span class="badge badge-soft-primary">{{ $redirects->total() }}</span></h5>
                        <form action="{{ route('admin.seo-settings.redirects.index') }}" method="get" class="d-flex gap-2">
                            <input type="text" name="searchValue" class="form-control form-control-sm" value="{{ $search }}" placeholder="{{ translate('search') }}">
                            <button type="submit" class="btn btn-sm btn-primary">{{ translate('search') }}</button>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead>
                                    <tr>
                                        <th>{{ translate('source') }}</th>
                                        <th>{{ translate('target') }}</th>
                                        <th class="text-center">{{ translate('type') }}</th>
                                        <th class="text-center">{{ translate('hits') }}</th>
                                        <th class="text-center">{{ translate('active') }}</th>
                                        <th class="text-center">{{ translate('action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($redirects as $redirect)
                                        <tr>
                                            <td><code dir="ltr">{{ $redirect->from_path }}</code></td>
                                            <td><code dir="ltr">{{ \Illuminate\Support\Str::limit($redirect->to_path, 40) }}</code></td>
                                            <td class="text-center">
                                                <span class="badge badge-soft-info">{{ $redirect->status_code }}</span>
                                                <span class="badge badge-soft-secondary">{{ translate($redirect->match_type) }}</span>
                                            </td>
                                            <td class="text-center">{{ $redirect->hits }}</td>
                                            <td class="text-center">
                                                <label class="switcher">
                                                    <a href="{{ route('admin.seo-settings.redirects.status', ['id' => $redirect->id, 'status' => $redirect->is_active ? 0 : 1]) }}"
                                                       class="badge {{ $redirect->is_active ? 'badge-soft-success' : 'badge-soft-danger' }}">
                                                        {{ $redirect->is_active ? translate('on') : translate('off') }}
                                                    </a>
                                                </label>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-outline-primary square-btn redirect-edit"
                                                        data-id="{{ $redirect->id }}"
                                                        data-from="{{ $redirect->from_path }}"
                                                        data-to="{{ $redirect->to_path }}"
                                                        data-status="{{ $redirect->status_code }}"
                                                        data-match="{{ $redirect->match_type }}"
                                                        data-note="{{ $redirect->note }}"
                                                        data-active="{{ $redirect->is_active ? 1 : 0 }}"
                                                        data-action="{{ route('admin.seo-settings.redirects.update', ['id' => $redirect->id]) }}">
                                                    <i class="fi fi-rr-edit"></i>
                                                </button>
                                                <a href="{{ route('admin.seo-settings.redirects.delete', ['id' => $redirect->id]) }}"
                                                   class="btn btn-sm btn-outline-danger square-btn"
                                                   onclick="return confirm('{{ translate('are_you_sure') }}?')">
                                                    <i class="fi fi-rr-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">{{ translate('no_redirects_yet') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @if($redirects->hasPages())
                        <div class="card-footer">{{ $redirects->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
        "use strict";
        (function () {
            var form = document.getElementById('redirect-form');
            if (!form) return;
            var defaultAction = form.getAttribute('action');
            var title = document.getElementById('redirect-form-title');
            var cancelBtn = document.getElementById('redirect-cancel-edit');

            function resetForm() {
                form.reset();
                form.setAttribute('action', defaultAction);
                if (title) title.textContent = '{{ translate('Add_Redirect') }}';
                if (cancelBtn) cancelBtn.classList.add('d-none');
            }

            document.querySelectorAll('.redirect-edit').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    form.setAttribute('action', btn.dataset.action);
                    form.querySelector('#from_path').value = btn.dataset.from || '';
                    form.querySelector('#to_path').value = btn.dataset.to || '';
                    form.querySelector('#status_code').value = btn.dataset.status || '301';
                    form.querySelector('#match_type').value = btn.dataset.match || 'exact';
                    form.querySelector('#note').value = btn.dataset.note || '';
                    form.querySelector('#is_active').checked = btn.dataset.active === '1';
                    if (title) title.textContent = '{{ translate('Edit_Redirect') }}';
                    if (cancelBtn) cancelBtn.classList.remove('d-none');
                    form.scrollIntoView({behavior: 'smooth'});
                });
            });

            if (cancelBtn) cancelBtn.addEventListener('click', resetForm);
        })();
    </script>
@endpush
