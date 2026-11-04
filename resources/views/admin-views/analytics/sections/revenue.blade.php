<x-k.card :title="translate('revenue')">
    <div class="ana-metrics">
        @include('admin-views.analytics.sections._metric', ['label' => 'revenue', 'metric' => $data['totals']['revenue'], 'format' => 'money'])
        @include('admin-views.analytics.sections._metric', ['label' => 'orders', 'metric' => $data['totals']['orders']])
        @include('admin-views.analytics.sections._metric', ['label' => 'average_order_value', 'metric' => $data['totals']['average_order_value'], 'format' => 'money'])
        @include('admin-views.analytics.sections._metric', ['label' => 'conversion_rate', 'metric' => $data['totals']['conversion_rate'], 'suffix' => '%'])
        @include('admin-views.analytics.sections._metric', ['label' => 'carts_started', 'metric' => $data['totals']['cart_adds']])
        @include('admin-views.analytics.sections._metric', ['label' => 'checkouts_started', 'metric' => $data['totals']['checkouts']])
    </div>
</x-k.card>

@include('admin-views.analytics.sections._trend', ['trend' => $data['trend'], 'trendState' => $data['totals']['state'] ?? null])

<div class="ana-grid ana-grid--2">
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['sources'], 'title' => translate('revenue_by_source'), 'label' => translate('source'), 'dimension' => 'source', 'window' => $window])
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['campaigns'], 'title' => translate('revenue_by_campaign'), 'label' => translate('campaign'), 'dimension' => 'campaign', 'window' => $window])
</div>
