{{-- Commerce Experience navigation (§69): each entry is a screen that works today. --}}
<div class="ab-nav">
    <div class="ab-nav__title">
        <i class="fi fi-rr-shop"></i>
        <span>{{ translate('commerce_experience') }}</span>
    </div>

    <nav>
        <a href="{{ route('admin.commerce.collections.index') }}"
           class="{{ ($current ?? '') === 'collections' ? 'is-active' : '' }}">
            <i class="fi fi-rr-boxes"></i> {{ translate('collections') }}
        </a>
        <a href="{{ route('admin.commerce.campaigns.index') }}"
           class="{{ ($current ?? '') === 'campaigns' ? 'is-active' : '' }}">
            <i class="fi fi-rr-megaphone"></i> {{ translate('campaigns') }}
        </a>
        <a href="{{ route('admin.commerce.segments.index') }}"
           class="{{ ($current ?? '') === 'segments' ? 'is-active' : '' }}">
            <i class="fi fi-rr-users"></i> {{ translate('segments') }}
        </a>
        <a href="{{ route('admin.commerce.experiments.index') }}"
           class="{{ ($current ?? '') === 'experiments' ? 'is-active' : '' }}">
            <i class="fi fi-rr-test-tube"></i> {{ translate('experiments') }}
        </a>
        <a href="{{ route('admin.app-builder.index') }}">
            <i class="fi fi-rr-layout-fluid"></i> {{ translate('app_builder') }}
        </a>
    </nav>
</div>

<style>
    .ab-nav { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem;
              padding: .75rem 1rem; margin-bottom: 1rem; border-radius: .6rem;
              background: #fff; border: 1px solid #e6e9ef; }
    .ab-nav__title { display: flex; align-items: center; gap: .45rem; font-weight: 700; }
    .ab-nav nav { display: flex; flex-wrap: wrap; gap: .35rem; }
    .ab-nav nav a { display: inline-flex; align-items: center; gap: .35rem; padding: .35rem .7rem;
                    border-radius: .4rem; font-size: .85rem; color: #4b5563; text-decoration: none; }
    .ab-nav nav a:hover { background: #f1f5f9; }
    .ab-nav nav a.is-active { background: #eef2ff; color: #4338ca; font-weight: 600; }
</style>
