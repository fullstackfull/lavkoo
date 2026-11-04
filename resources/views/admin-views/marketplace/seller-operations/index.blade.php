@extends('layouts.admin.app')

@section('title', translate('seller_operations'))

@php
    $shopName = fn ($id) => isset($sellers[$id])
        ? (trim(($sellers[$id]->f_name ?? '') . ' ' . ($sellers[$id]->l_name ?? '')) ?: ('#' . $id))
        : ('#' . $id);

    /** Every number links to the page behind it: the instinct on seeing one is to open it. */
    $cards = [
        'automation' => ['icon' => 'settings', 'route' => 'automation'],
        'issues' => ['icon' => 'alert', 'route' => 'issues'],
        'keys' => ['icon' => 'store', 'route' => 'integrations'],
        'webhooks' => ['icon' => 'refresh', 'route' => 'integrations'],
        'staff' => ['icon' => 'customers', 'route' => 'team'],
        'bulk_jobs' => ['icon' => 'orders', 'route' => 'bulk-jobs'],
    ];
@endphp

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('seller_operations')"
                         :subtitle="translate('what_the_sellers_are_doing_with_the_platform')" />

        @include('admin-views.marketplace.seller-operations._nav')

        {{-- What needs somebody, named rather than coloured.
             A red card saying "3" says something is wrong and not what. Shown only
             when there is something; a row of reassuring zeroes is not a status. --}}
        @if (! empty($attention))
            <x-k.card class="mb-3" :title="translate('needs_attention')">
                <div class="k-stack" style="gap:var(--k-size-2)">
                    @foreach ($attention as $item)
                        <div class="k-row k-row--between">
                            <div class="k-row">
                                <x-k.badge :tone="$item['tone']">{{ $item['count'] }}</x-k.badge>
                                <span>{{ translate($item['label']) }}</span>
                            </div>
                            @if ($item['href'])
                                <x-k.button variant="ghost" size="sm" :href="$item['href']">
                                    {{ translate('open') }}
                                </x-k.button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-k.card>
        @endif

        {{-- Narrower tracks than the component default so all six sit on one row at desk
             widths: five across with a single orphan below reads as a layout fault. --}}
        <div class="k-stats mb-4" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
            @foreach ($cards as $key => $card)
                @php($state = $summary[$key] ?? ['installed' => false, 'label' => $key])
                <x-k.stat
                    :label="translate($state['label'])"
                    {{-- A dash, never a zero: an operator reading "0 stopped rules" on a
                         platform with no rules table would conclude automation is healthy. --}}
                    :value="$state['installed'] ? $state['total'] : '—'"
                    :icon="$card['icon']"
                    :href="$state['installed'] && $card['route']
                        ? route('admin.marketplace.seller-operations.' . $card['route'])
                        : null"
                    :caption="$state['installed'] ? null : translate('not_installed')" />
            @endforeach
        </div>

        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <x-k.card :title="translate('shops_with_open_issues')" :padded="false">
                    <x-slot:actions>
                        <span class="k-text-subtle">{{ translate('ranked_by_what_is_worst_not_by_how_many') }}</span>
                    </x-slot:actions>

                    @if (empty($issuesBySeller))
                        <x-k.empty icon="check" :title="translate('no_open_issues_on_any_shop')"
                                   :text="translate('detection_runs_hourly_and_writes_only_what_it_finds')" />
                    @else
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead>
                                    <tr>
                                        <th>{{ translate('seller') }}</th>
                                        <th class="k-table__num">{{ translate('critical') }}</th>
                                        <th class="k-table__num">{{ translate('open') }}</th>
                                        <th class="k-table__num">{{ translate('worst_score') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach ($issuesBySeller as $row)
                                    <tr>
                                        <td>
                                            {{-- The count is a dead end unless it opens what it
                                                 counts, filtered to the shop it belongs to. --}}
                                            <a href="{{ route('admin.marketplace.seller-operations.issues', ['seller_id' => $row->seller_id]) }}">
                                                {{ $shopName($row->seller_id) }}
                                            </a>
                                        </td>
                                        <td class="k-table__num">
                                            @if ((int) $row->critical > 0)
                                                <x-k.badge tone="danger">{{ $row->critical }}</x-k.badge>
                                            @else
                                                <span class="k-num k-text-subtle">0</span>
                                            @endif
                                        </td>
                                        <td class="k-table__num k-num">{{ $row->total }}</td>
                                        {{-- Out of a hundred, said out loud: a bare "87" cannot be
                                             read without knowing the scale. --}}
                                        <td class="k-table__num k-num">{{ $row->worst_score }} / 100</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-k.card>
            </div>

            <div class="col-12 col-xl-5">
                <x-k.card :title="translate('webhook_deliveries_last_24h')" :padded="false">
                    @if (! $deliveryHealth['installed'])
                        <x-k.empty icon="info" :title="translate('not_installed')" />
                    @elseif (($deliveryHealth['delivered'] + $deliveryHealth['failed'] + $deliveryHealth['pending']) === 0)
                        <x-k.empty icon="clock" :title="translate('nothing_was_sent_in_the_last_day')"
                                   :text="translate('a_delivery_is_only_made_when_a_seller_asks_for_one')" />
                    @else
                        <div class="k-card__body">
                            <div class="k-stats">
                                <x-k.stat :label="translate('delivered')" :value="$deliveryHealth['delivered']" />
                                <x-k.stat :label="translate('failed')" :value="$deliveryHealth['failed']" />
                                <x-k.stat :label="translate('still_trying')" :value="$deliveryHealth['pending']" />
                            </div>
                        </div>
                    @endif
                </x-k.card>
            </div>
        </div>
    </div>
@endsection
