{{--
    Canonical, hreflang and Product structured data for the product page (Phase 2.2).

    Complements the theme's own _productSEOMetaContentData partial rather than replacing it: that
    one already writes title, description, og:*, twitter:* and robots, and this adds only the three
    things it does not. Duplicating a canonical or an og tag is worse than omitting it — search
    engines treat conflicting signals as untrustworthy.

    Composed by ProductSeoSupplementComposer, which reads $productDetails from the view and degrades
    to empty strings on any failure, so including it can never break the page.
--}}
@if(!empty($seoSupplementTags))
    {!! $seoSupplementTags !!}
@endif
@if(!empty($seoSupplementSchema))
    {!! $seoSupplementSchema !!}
@endif
