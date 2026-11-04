{{--
    Storefront SEO head block (Phase 1.6) — OPT-IN.

    Include it inside <head> from any theme layout, passing what the page is showing:

        @include('seo.head', [
            'seoableType'  => \App\Models\Product::class,
            'seoableId'    => $product->id,
            'entityType'   => 'product',
            'templateData' => ['product_name' => $product->name, 'store_name' => $webConfig['company_name'] ?? ''],
            'alternates'   => ['en' => $enUrl, 'ar' => $arUrl],
            'schema'       => $productSchema,   // from StructuredDataBuilder
            'currentUrl'   => url()->current(),
            'siteName'     => $webConfig['company_name'] ?? null,
        ])

    Themes that don't include it are entirely unaffected — no existing head markup is changed.
--}}
@if(!empty($seoTags))
    {!! $seoTags !!}
@endif
@if(!empty($seoSchema))
    {!! $seoSchema !!}
@endif
