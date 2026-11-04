{{-- Every event by name and volume.

     No revenue column on purpose. The rollup records revenue against both `order_placed` and
     `payment_succeeded`, which are two rows describing the SAME money — a column here would invite
     adding one sale up twice. Revenue lives on Overview and Revenue, where it is counted once. --}}
@include('admin-views.analytics.sections._breakdown', [
    'breakdown' => $data['events'],
    'title' => translate('every_recorded_event'),
    'label' => translate('event'),
    'dimension' => 'event',
    'window' => $window,
    'showEngagement' => false,
    'showRevenue' => false,
])
