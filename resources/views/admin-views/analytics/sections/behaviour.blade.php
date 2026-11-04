{{-- Paths are normalised to their route pattern before they are stored, so /product/{slug} is one
     row rather than ten thousand. The particular product is answered by product analytics. --}}
@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['pages'], 'title' => translate('pages'), 'label' => translate('page'), 'dimension' => 'path', 'window' => $window])
@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['landing'], 'title' => translate('landing_pages'), 'label' => translate('page'), 'dimension' => 'landing_path', 'window' => $window])
