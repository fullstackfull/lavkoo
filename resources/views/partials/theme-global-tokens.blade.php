{{-- Storefront design tokens from the ACTIVE, PUBLISHED theme (Phase 1 theme system -> storefront).
     Rendered only when a theme is published (see StorefrontThemeRenderer::publishedGlobalSettings), so
     the storefront is unchanged until a merchant publishes. A later :root declaration wins in CSS, so
     these override the legacy business-settings colours the storefront already consumes site-wide via
     --web-primary / --base / --web-secondary. --}}
@php($__c = $themeSettings['colors'] ?? [])
@php($__t = $themeSettings['typography'] ?? [])
@php($__l = $themeSettings['layout'] ?? [])
<style id="theme-global-tokens">
    :root {
        @if(!empty($__c['primary']))
        --base: {{ $__c['primary'] }};
        --bs-base-rgb: {{ getHexToRGBColorCode($__c['primary']) }};
        --web-primary: {{ $__c['primary'] }};
        --web-primary-rgb: {{ getHexToRGBColorCode($__c['primary']) }};
        --web-primary-10: {{ $__c['primary'] }}10;
        --web-primary-20: {{ $__c['primary'] }}20;
        --web-primary-40: {{ $__c['primary'] }}40;
        @endif
        @if(!empty($__c['secondary']))
        --base-2: {{ $__c['secondary'] }};
        --web-secondary: {{ $__c['secondary'] }};
        @endif
        @if(!empty($__t['base_font_size']))
        --theme-base-font-size: {{ (int) $__t['base_font_size'] }}px;
        @endif
        @if(isset($__l['border_radius']) && $__l['border_radius'] !== '')
        --theme-border-radius: {{ (int) $__l['border_radius'] }}px;
        @endif
    }
</style>
