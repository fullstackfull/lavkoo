@php use Illuminate\Support\Str; @endphp
@extends('layouts.admin.app')

@section('title', translate('vendor_List'))

@section('content')
    @php
        $currentFilters = ['searchValue' => request('searchValue'), 'sort_by' => request('sort_by')];
        $sortOptions = [
            'latest'         => translate('latest'),
            'oldest'         => translate('oldest'),
            'most-favorite'  => translate('most_favorite'),
            'orders_count'   => translate('orders_count'),
        ];
        $activeSort = request('sort_by');
    @endphp

    <div class="content container-fluid">
        <x-k.page-header :title="translate('vendor_List')" :subtitle="$vendors->total() . ' ' . translate('vendors')">
            <x-slot:actions>
                <x-k.button variant="secondary" icon="download"
                            :href="route('admin.vendors.export', ['searchValue' => request('searchValue')])">
                    {{ translate('export') }}
                </x-k.button>
                <x-k.button variant="primary" icon="plus" :href="route('admin.vendors.add')">
                    {{ translate('add_new_vendor') }}
                </x-k.button>
            </x-slot:actions>
        </x-k.page-header>

        <x-k.data-view :title="translate('vendor_List')" :count="$vendors->total()" :selectable="true"
                       searchName="searchValue" :searchValue="requestString('searchValue')"
                       :searchPlaceholder="translate('search_by_shop_name_or_vendor_name_or_phone_or_email')">

            {{-- Sorting was a dropdown whose current choice was only visible as a red dot.
                 As tabs the active order is the thing you read first. --}}
            <x-slot:tabs>
                <a class="k-tab" href="{{ route('admin.vendors.vendor-list', ['searchValue' => request('searchValue')]) }}"
                   aria-selected="{{ empty($activeSort) ? 'true' : 'false' }}">{{ translate('all') }}</a>
                @foreach ($sortOptions as $value => $label)
                    <a class="k-tab" aria-selected="{{ $activeSort === $value ? 'true' : 'false' }}"
                       href="{{ route('admin.vendors.vendor-list', ['sort_by' => $value, 'searchValue' => request('searchValue')]) }}">
                        {{ $label }}
                    </a>
                @endforeach
            </x-slot:tabs>

            <x-slot:bulk>
                <x-k.button variant="secondary" size="sm" icon="check" data-k-bulk-status="approved">
                    {{ translate('approve') }}
                </x-k.button>
                <x-k.button variant="secondary" size="sm" icon="eye-off" data-k-bulk-status="suspended">
                    {{ translate('suspend') }}
                </x-k.button>
                <x-k.button variant="danger" size="sm" icon="close" data-k-bulk-status="rejected">
                    {{ translate('reject') }}
                </x-k.button>
                <span class="k-field__hint">{{ translate('each_vendor_is_emailed_and_suspending_ends_their_session') }}</span>
            </x-slot:bulk>

            <table class="k-table">
                <thead>
                <tr>
                    <th style="inline-size:44px">
                        <input type="checkbox" data-k-select-all aria-label="{{ translate('select_all') }}">
                    </th>
                    <th>{{ translate('shop_name') }}</th>
                    <th>{{ translate('vendor_name') }}</th>
                    <th>{{ translate('contact_info') }}</th>
                    <th>{{ translate('status') }}</th>
                    <th class="k-table__num">{{ translate('total_products') }}</th>
                    <th class="k-table__num">{{ translate('total_orders') }}</th>
                    <th></th>
                </tr>
                </thead>

                <tbody>
                @foreach ($vendors as $seller)
                    <tr>
                        <td>
                            <input type="checkbox" data-k-row-select value="{{ $seller->id }}"
                                   aria-label="{{ translate('select_vendor') }} {{ $seller->id }}">
                        </td>

                        <td>
                            <a href="{{ route('admin.vendors.view', ['id' => $seller->id]) }}" class="k-row">
                                <img src="{{ getStorageImages(path: $seller?->shop?->image_full_url, type: 'backend-basic') }}"
                                     alt="" width="34" height="34"
                                     style="border-radius:50%;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                <span style="min-inline-size:0">
                                    <span class="k-truncate" style="display:block;max-inline-size:190px">
                                        {{ $seller->shop ? Str::limit($seller->shop->name, 26) : translate('shop_not_found') }}
                                    </span>
                                    {{-- A closed or vacationing shop still shows "active" by status, so the
                                         reason it is not selling has to be visible on the row itself. --}}
                                    @if (checkVendorAbility(type: 'vendor', status: 'temporary_close', vendor: $seller?->shop))
                                        <x-k.badge tone="warning">{{ translate('temporary_closed') }}</x-k.badge>
                                    @elseif (checkVendorAbility(type: 'vendor', status: 'vacation_status', vendor: $seller?->shop))
                                        <x-k.badge tone="warning">{{ translate('On_Vacation') }}</x-k.badge>
                                    @endif
                                </span>
                            </a>
                        </td>

                        <td>
                            <a href="{{ route('admin.vendors.view', $seller->id) }}" class="k-truncate"
                               style="display:block;max-inline-size:170px" title="{{ $seller->f_name }} {{ $seller->l_name }}">
                                {{ $seller->f_name }} {{ $seller->l_name }}
                            </a>
                        </td>

                        <td>
                            <a href="mailto:{{ $seller->email }}" class="k-truncate" style="display:block;max-inline-size:210px">
                                {{ $seller->email }}
                            </a>
                            <a href="tel:{{ $seller->phone }}" class="k-text-subtle">{{ $seller->phone }}</a>
                        </td>

                        <td>
                            {{-- The old list collapsed every non-approved state into "inactive", which
                                 hid the difference between a vendor waiting on you and one you rejected. --}}
                            @switch($seller->status)
                                @case('approved')
                                    <x-k.badge tone="success">{{ translate('active') }}</x-k.badge>
                                    @break
                                @case('pending')
                                    <x-k.badge tone="warning">{{ translate('pending') }}</x-k.badge>
                                    @break
                                @case('suspended')
                                    <x-k.badge tone="danger">{{ translate('suspended') }}</x-k.badge>
                                    @break
                                @case('rejected')
                                    <x-k.badge tone="danger">{{ translate('rejected') }}</x-k.badge>
                                    @break
                                @default
                                    <x-k.badge>{{ translate($seller->status) }}</x-k.badge>
                            @endswitch
                        </td>

                        <td class="k-table__num">
                            <a href="{{ route('admin.vendors.view', ['id' => $seller['id'], 'tab' => 'product']) }}" class="k-num">
                                {{ $seller->product->count() }}
                            </a>
                        </td>

                        <td class="k-table__num">
                            <a href="{{ route('admin.vendors.view', ['id' => $seller['id'], 'tab' => 'order']) }}" class="k-num">
                                {{ $seller->orders->where('seller_is', 'seller')->where('order_type', 'default_type')->count() }}
                            </a>
                        </td>

                        <td>
    <div class="k-table__actions" style="gap: 8px;">
        <!-- زر المعاينة -->
        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('view') }}"
           href="{{ route('admin.vendors.view', $seller->id) }}">
            <x-k.icon name="eye" :size="15" />
        </a>

        <!-- زر الحذف الناعم (Soft Delete) -->
        <form action="{{ route('admin.vendors.destroy', $seller->id) }}" method="POST" onsubmit="return confirm('{{ translate('Are_you_sure_you_want_to_delete_this_vendor') }}');" style="display: inline;">
            @csrf
            @method('DELETE')
            <button type="submit" class="k-btn k-btn--ghost k-btn--sm k-btn--icon" style="color: #dc3545;" title="{{ translate('delete') }}">
                <x-k.icon name="trash" :size="15" /> 
                <!-- ملاحظة: إذا لم تظهر أيقونة سلة المهملات، جرب تغيير name="trash" إلى name="delete" أو name="x" -->
            </button>
        </form>
    </div>
</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if (count($vendors) == 0)
                <x-k.empty icon="customers" :title="translate('no_vendor_found')"
                           :text="request('searchValue') ? translate('no_vendor_matches_your_search') : null">
                    <x-slot:action>
                        <x-k.button variant="primary" icon="plus" :href="route('admin.vendors.add')">
                            {{ translate('add_new_vendor') }}
                        </x-k.button>
                    </x-slot:action>
                </x-k.empty>
            @endif

            @if ($vendors->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $vendors->firstItem() }}–{{ $vendors->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $vendors->total() }}</span>
                    </span>
                    <div>{!! $vendors->appends($currentFilters)->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>
@endsection

@push('script')
    <script>
        "use strict";
        (function () {
            var view = document.querySelector('[data-k-selectable]');
            if (!view) return;

            var endpoint = @json(route('admin.vendors.bulk-status'));
            var csrf = document.querySelector('meta[name="csrf-token"]');
            var labels = {
                confirm: @json(translate('apply_this_to')),
                vendors: @json(translate('vendors')),
                notice: @json(translate('each_vendor_is_emailed_and_suspending_ends_their_session')),
                working: @json(translate('updating_vendors')),
                failed: @json(translate('could_not_update_the_vendors')),
                skipped: @json(translate('skipped')),
            };

            view.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-k-bulk-status]');
                if (!trigger) return;

                var ids = Kohl.selectedIds(view);
                if (!ids.length) return;
                // Suspending logs the vendor out and emails them, so both consequences are
                // named before it happens.
                if (!confirm(labels.confirm + ' ' + ids.length + ' ' + labels.vendors + '?\n' + labels.notice)) return;

                trigger.disabled = true;
                Kohl.toast({title: labels.working, duration: 2000});

                fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : ''
                    },
                    body: JSON.stringify({ids: ids, status: trigger.getAttribute('data-k-bulk-status')})
                })
                    .then(function (response) { return response.json(); })
                    .then(function (body) {
                        var skipped = body.skipped || [];
                        Kohl.toast({
                            title: body.message || labels.failed,
                            text: skipped.length
                                ? labels.skipped + ': #' + skipped.map(function (s) { return s.id; }).join(', #')
                                : null,
                            tone: body.status === 1 ? (skipped.length ? 'warning' : 'success') : 'danger',
                        });
                        if (body.updated) setTimeout(function () { location.reload(); }, 1200);
                        else trigger.disabled = false;
                    })
                    .catch(function () {
                        Kohl.toast({title: labels.failed, tone: 'danger'});
                        trigger.disabled = false;
                    });
            });
        })();
    </script>
@endpush