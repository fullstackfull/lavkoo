{{--
    The theme each storefront serves (GCC F-01), shown only while themes are kept by storefront. A storefront that has no theme of its own is shown with the default
    storefront's, which is what a visitor of that storefront is served; a storefront with nothing to show is served the built-in pages.
--}}
@use('App\Services\Theme\StorefrontThemeResolver')
@php $served = app(StorefrontThemeResolver::class)->servedByStorefront(); @endphp
@if ($served !== [])
    <div class="card mb-3" id="theme-storefronts">
        <div class="card-header"><h5 class="mb-0">{{ translate('the_theme_each_storefront_serves') }}</h5></div>
        <div class="card-body p-0">
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead>
                        <tr>
                            <th>{{ translate('theme_storefront') }}</th>
                            <th>{{ translate('theme') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($served as $storefront)
                        <tr data-served-storefront="{{ $storefront['country'] }}">
                            <td class="fw-bold"><span dir="ltr">{{ $storefront['label'] }}</span></td>
                            <td>
                                @if ($storefront['theme'])
                                    {{ $storefront['theme']->name }}
                                    @if ($storefront['inherited'])
                                        <span class="badge badge-soft-secondary">{{ translate('the_default_storefronts_theme') }}</span>
                                    @endif
                                @else
                                    <span class="text-muted">{{ translate('no_theme_the_built_in_pages_are_shown') }}</span>
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
