<div class="position-relative nav--tab-wrapper mb-4">
    <ul class="nav nav-pills nav--tab" id="pills-tab" role="tablist">
        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/pages-and-media/list') ?'active':'' }}"
               href="{{route('admin.pages-and-media.list') }}">{{ translate('Page_Setup') }}</a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/helpTopic/index') ?'active':'' }}"
               href="{{route('admin.helpTopic.list') }}">{{ translate('FAQ') }}</a>
        </li>

        @if (Route::has('admin.pages-and-media.features-section'))
            <li class="nav-item">
                <a class="nav-link {{ Request::is('admin/pages-and-media/features-section') ?'active':'' }}"
                   href="{{ route('admin.pages-and-media.features-section') }}">{{ translate('Features_Section') }}</a>
            </li>
        @endif

        <li class="nav-item">
            <a class="nav-link {{ Request::is('admin/pages-and-media/company-reliability') ? 'active':'' }}"
               href="{{route('admin.pages-and-media.company-reliability') }}">
                {{ translate('Our_Commitments') }}
            </a>
        </li>

        @if(function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction') && getWebConfig(name: 'auction_feature_status'))
            <li class="nav-item">
                <a class="nav-link {{ Request::is('admin/auction/company-reliability') ? 'active':'' }}"
                   href="{{route('admin.auction.company-reliability') }}">
                    {{ translate('Auction_Commitments') }}
                </a>
            </li>
        @endif
    </ul>
    <div class="nav--tab__prev">
        <button type="button" class="btn btn-circle border-0 bg-white text-primary">
            <i class="fi fi-sr-angle-left"></i>
        </button>
    </div>
    <div class="nav--tab__next">
        <button class="btn btn-circle border-0 bg-white text-primary">
            <i class="fi fi-sr-angle-right"></i>
        </button>
    </div>

</div>
