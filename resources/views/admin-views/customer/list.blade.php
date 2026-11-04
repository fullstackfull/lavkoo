@php use Illuminate\Support\Str; @endphp
@extends('layouts.admin.app')

@section('title', translate('Customer_List'))

@section('content')
    @php
        $currentFilters = [
            'sort_by'                => request('sort_by'),
            'choose_first'           => request('choose_first'),
            'is_active'              => request('is_active'),
            'order_date'             => request('order_date'),
            'customer_joining_date'  => request('customer_joining_date'),
            'searchValue'            => request('searchValue'),
        ];
        $hasFilters = collect($currentFilters)
            ->except('searchValue')
            ->filter(fn ($value) => !is_null($value) && $value !== '')
            ->isNotEmpty();
    @endphp

    <div class="content container-fluid">
        <x-k.page-header :title="translate('Customer_List')" :subtitle="$totalCustomers . ' ' . translate('customers')">
            <x-slot:actions>
                <x-k.button variant="secondary" icon="download"
                            :href="route('admin.customer.export', $currentFilters)">
                    {{ translate('export') }}
                </x-k.button>
            </x-slot:actions>
        </x-k.page-header>

        {{-- Filters stay an ordinary GET form so a filtered view remains shareable
             and bookmarkable — the same contract the data-view's search uses. --}}
        <x-k.card style="margin-block-end:var(--k-size-4)">
            <form action="{{ url()->current() }}" method="GET">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:var(--k-size-4)">
                    <x-k.field :label="translate('Order_Date')" name="order_date">
                        <input type="text" id="order_date" name="order_date"
                               class="k-input js-daterangepicker-with-range cursor-pointer"
                               value="{{ requestString('order_date') }}" placeholder="{{ translate('Select_Date') }}"
                               autocomplete="off" readonly>
                    </x-k.field>

                    <x-k.field :label="translate('Customer_Joining_Date')" name="customer_joining_date">
                        <input type="text" id="customer_joining_date" name="customer_joining_date"
                               class="k-input js-daterangepicker-with-range cursor-pointer"
                               value="{{ requestString('customer_joining_date') }}" placeholder="{{ translate('Select_Date') }}"
                               autocomplete="off" readonly>
                    </x-k.field>

                    <x-k.field :label="translate('Customer_Status')" name="is_active">
                        <select id="is_active" class="k-select set-filter" name="is_active">
                            <option value="" {{ request('is_active') === '' ? 'selected' : '' }}>{{ translate('All') }}</option>
                            <option value="1" {{ request('is_active') === '1' ? 'selected' : '' }}>{{ translate('Active') }}</option>
                            <option value="0" {{ request('is_active') === '0' ? 'selected' : '' }}>{{ translate('Inactive') }}</option>
                        </select>
                    </x-k.field>

                    <x-k.field :label="translate('Sort_By')" name="sort_by">
                        <select id="sort_by" class="k-select" name="sort_by">
                            <option value="" {{ is_null(request('sort_by')) ? 'selected' : '' }}>{{ translate('Select_Customer_sorting_order') }}</option>
                            <option value="order_amount" {{ request('sort_by') === 'order_amount' ? 'selected' : '' }}>{{ translate('Sort_By_Order_Amount') }}</option>
                            <option value="asc" {{ request('sort_by') === 'asc' ? 'selected' : '' }}>{{ translate('Sort_By_Oldest') }}</option>
                            <option value="desc" {{ request('sort_by') === 'desc' ? 'selected' : '' }}>{{ translate('Sort_By_Newest') }}</option>
                        </select>
                    </x-k.field>

                    <x-k.field :label="translate('Choose_First')" name="choose_first"
                               :hint="translate('Ex') . ' : 100'">
                        <input type="number" id="choose_first" class="k-input" min="1"
                               value="{{ requestString('choose_first') }}" name="choose_first"
                               placeholder="{{ translate('Ex') }} : 100">
                    </x-k.field>

                    <div class="k-row" style="align-items:flex-end;gap:var(--k-size-2)">
                        <x-k.button variant="primary" type="submit">{{ translate('Filter') }}</x-k.button>
                        <x-k.button variant="ghost" :href="route('admin.customer.list')">{{ translate('reset') }}</x-k.button>
                    </div>
                </div>
            </form>
        </x-k.card>

        <x-k.data-view :title="translate('Customer_List')" :count="$customers->total()" :selectable="true"
                       searchName="searchValue" :searchValue="requestString('searchValue')"
                       :searchPlaceholder="translate('search_by_Name_or_Email_or_Phone')">

            <x-slot:bulk>
                <x-k.button variant="secondary" size="sm" icon="eye-off" data-k-bulk-action="block">
                    {{ translate('block') }}
                </x-k.button>
                <x-k.button variant="secondary" size="sm" icon="check" data-k-bulk-action="unblock">
                    {{ translate('unblock') }}
                </x-k.button>
                <span class="k-field__hint">{{ translate('each_customer_is_emailed_about_the_change') }}</span>
            </x-slot:bulk>

            <table class="k-table">
                <thead>
                <tr>
                    <th style="inline-size:44px">
                        <input type="checkbox" data-k-select-all aria-label="{{ translate('select_all') }}">
                    </th>
                    <th>{{ translate('customer_name') }}</th>
                    <th>{{ translate('contact_info') }}</th>
                    <th class="k-table__num">{{ translate('total_Order') }}</th>
                    <th>{{ translate('block') }} / {{ translate('unblock') }}</th>
                    <th></th>
                </tr>
                </thead>

                <tbody>
                @foreach ($customers as $customer)
                    @php $isWalkIn = $customer['email'] == 'walking@customer.com'; @endphp
                    <tr>
                        <td>
                            {{-- The walk-in placeholder account cannot be blocked, so it is not
                                 selectable either — an action that silently skips is worse than
                                 one that is not offered. --}}
                            @unless ($isWalkIn)
                                <input type="checkbox" data-k-row-select value="{{ $customer['id'] }}"
                                       aria-label="{{ translate('select_customer') }} {{ $customer['id'] }}">
                            @endunless
                        </td>

                        <td>
                            <a href="{{ route('admin.customer.view', [$customer['id']]) }}" class="k-row">
                                <img src="{{ getStorageImages(path: $customer->image_full_url, type: 'backend-profile') }}"
                                     alt="" width="34" height="34"
                                     style="border-radius:50%;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                <span class="k-truncate" style="max-inline-size:200px">
                                    {{ Str::limit($customer['f_name'] . ' ' . $customer['l_name'], 28) }}
                                </span>
                            </a>
                        </td>

                        <td>
                            <a href="mailto:{{ $customer->email }}" class="k-truncate" style="display:block;max-inline-size:220px">
                                {{ $customer->email }}
                            </a>
                            <a href="tel:{{ $customer->phone }}" class="k-text-subtle">{{ $customer->phone }}</a>
                        </td>

                        <td class="k-table__num">
                            <span class="k-num">{{ $customer?->orders?->count() ?? 0 }}</span>
                        </td>

                        <td>
                            @if ($isWalkIn)
                                <x-k.badge :dot="false">{{ translate('default') }}</x-k.badge>
                            @else
                                {{-- Kept as the project's confirm-modal switcher: blocking locks the
                                     customer out and emails them, so it keeps its confirmation step. --}}
                                <form action="{{ route('admin.customer.status-update') }}" method="post"
                                      id="customer-status{{ $customer['id'] }}-form" class="no-reload-form">
                                    @csrf
                                    <input type="hidden" name="id" value="{{ $customer['id'] }}">
                                    <label class="switcher" for="customer-status{{ $customer['id'] }}">
                                        <input class="switcher_input custom-modal-plugin" type="checkbox" value="1" name="is_active"
                                               id="customer-status{{ $customer['id'] }}"
                                               {{ $customer['is_active'] == 1 ? 'checked' : '' }}
                                               data-modal-type="input-change-form"
                                               data-modal-form="#customer-status{{ $customer['id'] }}-form"
                                               data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/customer-block-on.png') }}"
                                               data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/customer-block-off.png') }}"
                                               data-on-title="{{ translate('want_to_unblock') . ' ' . $customer['f_name'] . ' ' . $customer['l_name'] . '?' }}"
                                               data-off-title="{{ translate('want_to_block') . ' ' . $customer['f_name'] . ' ' . $customer['l_name'] . '?' }}"
                                               data-on-message="<p>{{ translate('if_enabled_this_customer_will_be_unblocked_and_can_log_in_to_this_system_again') }}</p>"
                                               data-off-message="<p>{{ translate('if_disabled_this_customer_will_be_blocked_and_cannot_log_in_to_this_system') }}</p>"
                                               data-on-button-text="{{ translate('turn_on') }}"
                                               data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </form>
                            @endif
                        </td>

                        <td>
                            <div class="k-table__actions">
                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('view') }}"
                                   href="{{ route('admin.customer.view', [$customer['id']]) }}">
                                    <x-k.icon name="eye" :size="15" />
                                </a>
                                @if ($customer['id'] != '0')
                                    <a href="javascript:" class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete delete-data"
                                       title="{{ translate('delete') }}" data-id="customer-{{ $customer['id'] }}"
                                       style="color:var(--k-danger)">
                                        <x-k.icon name="trash" :size="15" />
                                    </a>
                                    <form action="{{ route('admin.customer.delete', [$customer['id']]) }}"
                                          method="post" id="customer-{{ $customer['id'] }}">
                                        @csrf @method('delete')
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if (count($customers) == 0)
                <x-k.empty icon="customers" :title="translate('no_customer_found')"
                           :text="$hasFilters ? translate('no_customer_matches_the_filters_you_applied') : null">
                    @if ($hasFilters)
                        <x-slot:action>
                            <x-k.button variant="secondary" :href="route('admin.customer.list')">
                                {{ translate('reset') }}
                            </x-k.button>
                        </x-slot:action>
                    @endif
                </x-k.empty>
            @endif

            @if ($customers->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $customers->firstItem() }}–{{ $customers->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $customers->total() }}</span>
                    </span>
                    <div>{!! $customers->appends($currentFilters)->links() !!}</div>
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

            var endpoint = @json(route('admin.customer.bulk-status'));
            var csrf = document.querySelector('meta[name="csrf-token"]');
            var labels = {
                confirm: @json(translate('apply_this_to')),
                customers: @json(translate('customers')),
                notice: @json(translate('each_customer_is_emailed_about_the_change')),
                working: @json(translate('updating_customers')),
                failed: @json(translate('could_not_update_the_customers')),
            };

            view.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-k-bulk-action]');
                if (!trigger) return;

                var ids = Kohl.selectedIds(view);
                if (!ids.length) return;
                // The email is the part people do not expect, so it is in the confirmation.
                if (!confirm(labels.confirm + ' ' + ids.length + ' ' + labels.customers + '?\n' + labels.notice)) return;

                trigger.disabled = true;
                Kohl.toast({title: labels.working, duration: 2000});

                fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : ''
                    },
                    body: JSON.stringify({ids: ids, action: trigger.getAttribute('data-k-bulk-action')})
                })
                    .then(function (response) { return response.json(); })
                    .then(function (body) {
                        Kohl.toast({title: body.message || labels.failed, tone: body.status === 1 ? 'success' : 'danger'});
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
