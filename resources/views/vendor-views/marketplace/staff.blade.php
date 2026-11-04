@extends('layouts.vendor.app')

@section('title', translate('staff_and_roles'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0">{{ translate('staff_and_roles') }}</h2>
            <p class="mb-0 fs-12">{{ translate('define_roles_and_add_your_team') }}.</p>
        </div>

        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h6 class="mb-1">{{ translate('staff_sign_in_link') }}</h6>
                    <p class="mb-0 fs-12 text-muted">{{ translate('share_this_link_with_your_staff_to_sign_in') }}.</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" readonly class="form-control form-control-sm" style="min-width:260px"
                           value="{{ route('vendor.staff-auth.login') }}"
                           onclick="this.select()">
                    <a href="{{ route('vendor.staff-auth.login') }}" target="_blank" class="btn btn-sm btn-outline-primary">
                        {{ translate('open') }}
                    </a>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-6">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('create_role') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('vendor.business-settings.staff.roles.store') }}" method="post">
                            @csrf
                            <div class="mb-2"><label class="form-label fs-12">{{ translate('role_name') }} *</label>
                                <input type="text" name="name" class="form-control form-control-sm" required maxlength="120"></div>
                            <label class="form-label fs-12">{{ translate('permissions') }}</label>
                            @foreach ($catalog as $group => $perms)
                                <div class="mb-2 border rounded p-2">
                                    <div class="fs-11 text-uppercase text-muted mb-1">{{ translate($group) }}</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach ($perms as $perm)
                                            <label class="d-flex align-items-center gap-1 fs-12 mb-0">
                                                <input type="checkbox" name="permissions[]" value="{{ $perm }}"> {{ translate($perm) }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                            <button class="btn btn-primary btn-sm">{{ translate('save') }}</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('roles') }}</h5></div>
                    <div class="card-body p-0">
                        <table class="k-table">
                            <tbody>
                            @forelse ($roles as $r)
                                <tr>
                                    <td>
                                        <strong>{{ $r->name }}</strong>
                                        @if($r->status !== 'active')<span class="k-badge">{{ translate($r->status) }}</span>@endif
                                        <div class="fs-10 text-muted">{{ count($r->permissions ?? []) }} {{ translate('permissions') }}</div>
                                    </td>
                                    <td class="text-end">
                                        <form action="{{ route('vendor.business-settings.staff.roles.destroy', $r->id) }}" method="post" onsubmit="return confirm('{{ translate('are_you_sure') }}')">
                                            @csrf @method('DELETE') <button class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-muted text-center py-3">{{ translate('no_roles_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('add_staff_member') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('vendor.business-settings.staff.store') }}" method="post" class="row g-2">
                            @csrf
                            <div class="col-6"><label class="form-label fs-12">{{ translate('name') }} *</label>
                                <input type="text" name="name" class="form-control form-control-sm" required maxlength="120"></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('email') }} *</label>
                                <input type="email" name="email" class="form-control form-control-sm" required maxlength="191"></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('password') }} *</label>
                                <input type="password" name="password" class="form-control form-control-sm" required minlength="6"></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('role') }}</label>
                                <select name="seller_role_id" class="form-control form-control-sm">
                                    <option value="">{{ translate('no_role') }}</option>
                                    @foreach ($roles as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
                                </select></div>
                            <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary btn-sm">{{ translate('add') }}</button></div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('team') }}</h5></div>
                    <div class="card-body p-0">
                        <table class="k-table">
                            <thead><tr><th>{{ translate('name') }}</th><th>{{ translate('role') }}</th><th>{{ translate('status') }}</th><th></th></tr></thead>
                            <tbody>
                            @forelse ($staff as $s)
                                <tr>
                                    <td>{{ $s->name }}<div class="fs-10 text-muted">{{ $s->email }}</div></td>
                                    <td>{{ $s->role?->name ?: translate('no_role') }}</td>
                                    <td><span class="k-badge k-badge--{{ $s->status === 'active' ? 'success' : 'secondary' }}">{{ translate($s->status) }}</span></td>
                                    <td class="text-end">
                                        <form action="{{ route('vendor.business-settings.staff.destroy', $s->id) }}" method="post" onsubmit="return confirm('{{ translate('are_you_sure') }}')">
                                            @csrf @method('DELETE') <button class="btn btn-sm btn-outline-danger">{{ translate('remove') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted text-center py-3">{{ translate('no_staff_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
