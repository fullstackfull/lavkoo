{{-- The App Builder's own navigation.

     Every entry goes to a screen that exists and does the job today: the composer, the pages this
     channel has, the catalogue of what can go on them, the colours, and the publishing and version
     history that already live on Theme Management. A nav entry with nothing behind it would be a
     promise the product does not keep. --}}
<div class="ab-nav">
    <div class="ab-nav__title">
        <i class="fi fi-rr-mobile-notch"></i>
        <span>{{ translate('app_builder') }}</span>
        <span class="badge badge-soft-primary">{{ translate($channel) }}</span>
    </div>

    <nav>
        <a href="{{ route('admin.app-builder.index', ['channel' => $channel]) }}"
           class="{{ ($current ?? '') === 'compose' ? 'is-active' : '' }}">
            <i class="fi fi-rr-layout-fluid"></i> {{ translate('compose') }}
        </a>
        <a href="{{ route('admin.app-builder.pages', ['channel' => $channel]) }}"
           class="{{ ($current ?? '') === 'pages' ? 'is-active' : '' }}">
            <i class="fi fi-rr-copy"></i> {{ translate('pages') }}
        </a>
        <a href="{{ route('admin.app-builder.sections', ['channel' => $channel]) }}"
           class="{{ ($current ?? '') === 'sections' ? 'is-active' : '' }}">
            <i class="fi fi-rr-apps"></i> {{ translate('sections') }}
        </a>
        <a href="{{ route('admin.app-builder.media', ['channel' => $channel]) }}"
           class="{{ ($current ?? '') === 'media' ? 'is-active' : '' }}">
            <i class="fi fi-rr-picture"></i> {{ translate('media') }}
        </a>
        <a href="{{ route('admin.app-builder.templates', ['channel' => $channel]) }}"
           class="{{ ($current ?? '') === 'templates' ? 'is-active' : '' }}">
            <i class="fi fi-rr-magic-wand"></i> {{ translate('templates') }}
        </a>
        <a href="{{ route('admin.app-builder.health', ['channel' => $channel]) }}"
           class="{{ ($current ?? '') === 'health' ? 'is-active' : '' }}">
            <i class="fi fi-rr-pulse"></i> {{ translate('experience_health') }}
        </a>
        <a href="{{ route('admin.theme.settings.index') }}">
            <i class="fi fi-rr-palette"></i> {{ translate('global_styles') }}
        </a>
        <a href="{{ route('admin.theme.index') }}">
            <i class="fi fi-rr-rocket-lunch"></i> {{ translate('publishing_and_versions') }}
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
