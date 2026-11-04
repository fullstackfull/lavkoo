@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['products'], 'title' => translate('products'), 'label' => translate('product'), 'dimension' => 'product', 'window' => $window])
<div class="ana-grid ana-grid--2">
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['categories'], 'title' => translate('categories'), 'label' => translate('category'), 'dimension' => 'category', 'window' => $window])
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['brands'], 'title' => translate('brands'), 'label' => translate('brand'), 'dimension' => 'brand', 'window' => $window])
</div>

{{-- Which banners were actually taken. The merchant chooses the picture, the slot and the link;
     this is the only screen that says whether anyone acted on the choice. --}}
@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['banners'], 'title' => translate('banner_clicks'), 'label' => translate('banner'), 'dimension' => 'banner', 'window' => $window])

{{-- How far down the composed page shoppers actually got. A section nobody reaches looks exactly
     like a section nobody wants, and only one of those is fixed by moving it up. --}}
@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['sections'], 'title' => translate('section_views'), 'label' => translate('section'), 'dimension' => 'theme_section', 'window' => $window])
