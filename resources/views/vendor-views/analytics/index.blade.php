@extends('layouts.vendor.app')

@section('title', translate('analytics'))

@push('css_or_js')
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/kohl/css/analytics.css') }}">
@endpush

@section('content')
    <div class="content container-fluid k ana">
        <x-k.page-header :title="translate('my_analytics')" :subtitle="translate('how_customers_find_and_buy_your_products')">
            <x-slot:actions>
                <div class="ana-range">
                    @foreach ($ranges as $key => $days)
                        <a class="ana-range__option {{ $window->key === $key ? 'is-active' : '' }}"
                           href="{{ route('vendor.analytics.index', ['range' => $key]) }}">
                            {{ translate(\App\Services\Analytics\Reporting\Window::make($key)->label()) }}
                        </a>
                    @endforeach
                </div>
            </x-slot:actions>
        </x-k.page-header>

        <div class="ana-window">
            <span>{{ translate('showing') }} <strong>{{ $window->fromDate() }}</strong> → <strong>{{ $window->toDate() }}</strong></span>
            @if ($window->includesToday())
                <span class="ana-chip">{{ translate('includes_today_live') }}</span>
            @endif
        </div>

        @if ($data['state'] !== 'ok')
            <x-k.card>
                @include('admin-views.analytics.sections._empty', ['state' => $data['state']])
            </x-k.card>
        @else
            <x-k.card :title="translate('your_shop_in_this_period')">
                <div class="ana-metrics">
                    <div class="ana-metric"><small>{{ translate('visitors') }}</small><span class="k-num">{{ number_format($data['summary']['visitors']) }}</span></div>
                    <div class="ana-metric"><small>{{ translate('visits') }}</small><span class="k-num">{{ number_format($data['summary']['sessions']) }}</span></div>
                    <div class="ana-metric"><small>{{ translate('product_views') }}</small><span class="k-num">{{ number_format($data['summary']['product_views']) }}</span></div>
                    <div class="ana-metric"><small>{{ translate('added_to_cart') }}</small><span class="k-num">{{ number_format($data['summary']['cart_adds']) }}</span></div>
                    <div class="ana-metric"><small>{{ translate('orders') }}</small><span class="k-num">{{ number_format($data['summary']['orders']) }}</span></div>
                    <div class="ana-metric"><small>{{ translate('revenue') }}</small><span class="k-num">{{ number_format($data['summary']['revenue'], 2) }}</span></div>
                </div>
                <p class="ana-note">
                    {{ translate('these_are_your_products_only_bot_and_staff_traffic_is_excluded_as_it_is_everywhere_else_in_analytics') }}
                </p>
            </x-k.card>

            <x-k.card :title="translate('your_products')">
                @if ($data['products'] === [])
                    <x-k.empty :title="translate('no_product_activity_yet')"
                               :text="translate('once_customers_start_viewing_your_products_they_appear_here_with_how_many_reached_a_cart')" />
                @else
                    <table class="ana-table">
                        <thead><tr>
                            <th>{{ translate('product') }}</th>
                            <th class="ana-num">{{ translate('views') }}</th>
                            <th class="ana-num">{{ translate('visitors') }}</th>
                            <th class="ana-num">{{ translate('added_to_cart') }}</th>
                            <th class="ana-num">{{ translate('view_to_cart') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($data['products'] as $product)
                            <tr>
                                <td>{{ $names[$product->entity_id] ?? '#' . $product->entity_id }}</td>
                                <td class="ana-num">{{ number_format($product->views) }}</td>
                                <td class="ana-num">{{ number_format($product->visitors) }}</td>
                                <td class="ana-num">{{ number_format($product->cart_adds) }}</td>
                                <td class="ana-num">
                                    {{ $product->views > 0 ? round(100 * $product->cart_adds / $product->views, 1) . '%' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </x-k.card>
        @endif
    </div>
@endsection
