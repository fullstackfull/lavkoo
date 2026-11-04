@extends('layouts.admin.app')

@section('title', translate('seller_staff'))

@php
    $shopName = fn ($id) => isset($sellers[$id])
        ? (trim(($sellers[$id]->f_name ?? '') . ' ' . ($sellers[$id]->l_name ?? '')) ?: ('#' . $id))
        : ('#' . $id);
@endphp

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('seller_staff')"
                         :subtitle="translate('who_can_act_as_a_shop_besides_its_owner')" />

        @include('admin-views.marketplace.seller-operations._nav')
        @include('admin-views.marketplace.seller-operations._seller-filter')

        <x-k.card :padded="false" :title="translate('seller_staff')">
            @if ($staff === null)
                <x-k.empty icon="info" :title="translate('not_installed')" />
            @elseif ($staff->isEmpty())
                <x-k.empty icon="customers" :title="translate('no_seller_has_added_staff_yet')"
                           :text="translate('every_shop_is_run_by_its_owner_alone')" />
            @else
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('seller') }}</th>
                                <th>{{ translate('name') }}</th>
                                <th>{{ translate('email') }}</th>
                                <th>{{ translate('role') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th>{{ translate('session') }}</th>
                                <th>{{ translate('last_login') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($staff as $member)
                            <tr>
                                <td>{{ $shopName($member->seller_id) }}</td>
                                <td>{{ $member->name }}</td>
                                <td class="k-text-muted">{{ $member->email }}</td>
                                <td>
                                    @if ($member->role)
                                        {{ $member->role->name }}
                                    @else
                                        {{-- Not a blank: an active account with no role can sign in
                                             and do nothing, which is worth seeing. --}}
                                        <x-k.badge tone="warning">{{ translate('no_role') }}</x-k.badge>
                                    @endif
                                </td>
                                <td>
                                    <x-k.badge :tone="$member->status === 'active' ? 'success' : 'neutral'">
                                        {{ translate($member->status) }}
                                    </x-k.badge>
                                </td>
                                <td class="k-text-muted">
                                    {{-- Whether a token exists, never its value. --}}
                                    {{ translate(!empty($member->auth_token) ? 'signed_in' : 'not_signed_in') }}
                                </td>
                                <td class="k-text-muted">{{ $member->last_login_at ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="k-pager">{{ $staff->links() }}</div>
            @endif
        </x-k.card>
    </div>
@endsection
