@include('admin-views.analytics.sections._funnel', ['funnel' => $data['funnel']])
@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['gateways'], 'title' => translate('payment_methods'), 'label' => translate('gateway'), 'dimension' => 'gateway', 'window' => $window, 'showEngagement' => false])
