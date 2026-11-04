@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['shops'], 'title' => translate('vendors'), 'label' => translate('vendor'), 'dimension' => 'vendor', 'window' => $window, 'showEngagement' => false])

@php($attention = $data['attention'] ?? [])

{{-- Only shops with something wrong. A shop with nothing wrong is not a row of zeroes, and a
     table of them would bury the two that need somebody. --}}
@if (!empty($attention))
    <div class="card mt-3">
        <div class="card-header">
            <h5 class="mb-0">{{ translate('shops_needing_attention') }}</h5>
            <p class="fs-12 text-muted mb-0">{{ translate('operational_state_beside_the_traffic') }}.</p>
        </div>
        <div class="card-body p-0">
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead>
                        <tr>
                            <th>{{ translate('seller') }}</th>
                            <th class="text-end">{{ translate('critical') }}</th>
                            <th class="text-end">{{ translate('open_issues') }}</th>
                            <th class="text-end">{{ translate('stopped_rules') }}</th>
                            <th class="text-end">{{ translate('failing_endpoints') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($attention as $sellerId => $state)
                        <tr>
                            <td>#{{ $sellerId }}</td>
                            <td class="text-end">
                                @if ($state['critical'] > 0)
                                    <span class="k-badge k-badge--danger">{{ $state['critical'] }}</span>
                                @else
                                    0
                                @endif
                            </td>
                            <td class="text-end">{{ $state['issues'] }}</td>
                            <td class="text-end {{ $state['suspended_rules'] > 0 ? 'text-danger' : '' }}">{{ $state['suspended_rules'] }}</td>
                            <td class="text-end {{ $state['failing_webhooks'] > 0 ? 'text-danger' : '' }}">{{ $state['failing_webhooks'] }}</td>
                            <td class="text-end">
                                @if (Route::has('admin.marketplace.seller-operations.index'))
                                    <a class="btn btn--sm btn-outline-primary"
                                       href="{{ route('admin.marketplace.seller-operations.automation', ['seller_id' => $sellerId]) }}">
                                        {{ translate('open') }}
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
