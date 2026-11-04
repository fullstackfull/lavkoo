<div class="ana-grid ana-grid--2">
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['sources'], 'title' => translate('sources'), 'label' => translate('source'), 'dimension' => 'source', 'window' => $window])
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['mediums'], 'title' => translate('mediums'), 'label' => translate('medium'), 'dimension' => 'medium', 'window' => $window])
</div>
<div class="ana-grid ana-grid--2">
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['campaigns'], 'title' => translate('campaigns'), 'label' => translate('campaign'), 'dimension' => 'campaign', 'window' => $window])
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['landing'], 'title' => translate('landing_pages'), 'label' => translate('page'), 'dimension' => 'landing_path', 'window' => $window])
</div>
{{-- How each visit's source was decided. Attribution is the number most often quietly wrong, so
     the basis is reported rather than assumed: a shop whose traffic is overwhelmingly "direct" is
     usually a shop whose referrers are being stripped, not one with that much brand recall. --}}
@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['basis'], 'title' => translate('how_each_visit_was_attributed'), 'label' => translate('basis'), 'dimension' => 'attribution_basis', 'window' => $window, 'showEngagement' => false])
