{{-- Storefront renderer for the Theme Builder's FOOTER sections.
     Replace-not-stack, exactly like the themed home: when the published (or previewed) version has
     visible footer sections they REPLACE the built-in footer wholesale; when this outputs nothing
     the built-in footer renders unchanged. The copyright bar is always appended so replacing the
     footer can never drop the legal line. --}}
@php
    $__footerSections = app(\App\Services\Theme\StorefrontThemeRenderer::class)->sectionsFor('footer');

    // Only sections that actually SHOW something count. A footer_columns section without a single
    // visible block (a merchant added the section but no columns yet) must not replace the built-in
    // footer with an empty shell — that reads as "the footer broke".
    $__footerSections = collect($__footerSections ?? [])
        ->filter(function ($section) {
            $settings = $section['settings'] ?? [];
            if (($settings['visible'] ?? true) === false) {
                return false;
            }
            return match ($section['type'] ?? null) {
                'footer_columns' => count($section['blocks'] ?? []) > 0,
                'custom_html'    => trim((string) ($settings['content'] ?? '')) !== '',
                'newsletter'     => true,
                default          => false,
            };
        })->values()->all();
@endphp

@if (!empty($__footerSections))
<style>
    .tb-footer{ background:#182b47; color:#cfd8e6; }
    .tb-footer a{ color:#cfd8e6; }
    .tb-footer a:hover{ color:#fff; text-decoration:none; }
    .tb-footer h5{ color:#fff; font-size:.95rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; margin-bottom:1rem; }
    .tb-footer .tb-footer__section{ padding-block:2.25rem; }
    .tb-footer .tb-footer__grid{ display:grid; gap:1.5rem; grid-template-columns:repeat(var(--tb-cols,4),minmax(0,1fr)); }
    @media (max-width:767.98px){ .tb-footer .tb-footer__grid{ grid-template-columns:repeat(var(--tb-cols-sm,2),minmax(0,1fr)); } }
    .tb-footer .tbf-align-center{ text-align:center; }
    .tb-footer .tbf-align-center .tb-footer__social,.tb-footer .tbf-align-center .tb-news-form{ justify-content:center; margin-inline:auto; }
    .tb-footer .tbf-align-end{ text-align:end; }
    .tb-footer .tbf-align-end .tb-footer__social{ justify-content:flex-end; }
    .tb-footer .tb-footer__section + .tb-footer__section{ border-top:1px solid rgba(255,255,255,.08); }
    .tb-footer ul{ list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:.55rem; }
    .tb-footer .tb-footer__contact li{ display:flex; gap:.5rem; align-items:baseline; }
    .tb-footer .tb-footer__social{ flex-direction:row; gap:.75rem; }
    .tb-footer .tb-footer__social a{ display:inline-flex; align-items:center; justify-content:center;
        inline-size:38px; block-size:38px; border-radius:50%; border:1px solid rgba(255,255,255,.25); }
    .tb-footer .tb-footer__social a:hover{ background:#fff; color:#182b47; }
    .tb-footer .tb-news-form{ display:flex; gap:.5rem; max-width:420px; }
    .tb-footer .tb-news-form input{ flex:1; border:1px solid rgba(255,255,255,.25); background:rgba(255,255,255,.08);
        color:#fff; padding:.7rem 1.1rem; border-radius:100px; }
    .tb-footer .tb-news-form input::placeholder{ color:rgba(255,255,255,.55); }
    .tb-footer .tb-news-form button{ border:0; border-radius:100px; padding:.7rem 1.5rem; font-weight:700;
        background:var(--web-primary, #673ab7); color:#fff; }
    .tb-footer .tb-footer__bottom{ border-top:1px solid rgba(255,255,255,.08); padding-block:1rem; font-size:.85rem; }
    .tb-footer .tb-apps img{ max-height:44px; }
</style>

<footer class="tb-footer rtl">
    @foreach ($__footerSections as $__section)
        @php
            $type = $__section['type'] ?? null;
            $s = $__section['settings'] ?? [];
            $blocks = collect($__section['blocks'] ?? [])->map(fn ($b) => ['type' => $b['type'] ?? '', 's' => $b['settings'] ?? []]);
            $sectionKey = 'tbf-' . ($__section['id'] ?? $loop->index);
            $align = in_array($s['alignment'] ?? 'start', ['center', 'end'], true) ? $s['alignment'] : 'start';
            $cols = max(1, min(6, (int) ($s['columns'] ?? 4)));
            $sectionStyle = '--tb-cols:' . $cols . ';'
                . (isset($s['padding_top']) ? 'padding-top:' . max(0, (int) $s['padding_top']) . 'px;' : '')
                . (isset($s['padding_bottom']) ? 'padding-bottom:' . max(0, (int) $s['padding_bottom']) . 'px;' : '')
                . (!empty($s['background']) ? 'background:' . $s['background'] . ';' : '');
            $breakpointCss = theme_section_breakpoint_css(settings: $s, selector: '#' . $sectionKey);
        @endphp
        @continue(($s['visible'] ?? true) === false)

        @if ($breakpointCss)<style>{!! $breakpointCss !!}</style>@endif
        <div id="{{ $sectionKey }}" class="tb-footer__section tbf-align-{{ $align }}"
             data-tb-section="{{ $__section['id'] ?? '' }}" style="{{ $sectionStyle }}">
            <div class="{{ ($s['width'] ?? 'container') === 'full' ? 'container-fluid' : 'container' }}">
                @switch($type)

                    @case('footer_columns')
                        <div class="tb-footer__grid">
                            @foreach ($blocks as $block)
                                @php $b = $block['s']; @endphp
                                <div>
                                    @if (!empty($b['title']))<h5>{{ $b['title'] }}</h5>@endif

                                    @switch($block['type'])
                                        @case('menu')
                                            <ul>
                                                @foreach (preg_split('/\r\n|\r|\n/', (string) ($b['links'] ?? '')) as $line)
                                                    @php
                                                        [$label, $url] = array_pad(explode('|', $line, 2), 2, null);
                                                        $label = trim((string) $label);
                                                        $url = trim((string) $url);
                                                    @endphp
                                                    @if ($label !== '')
                                                        <li><a href="{{ $url !== '' ? $url : 'javascript:void(0)' }}">{{ $label }}</a></li>
                                                    @endif
                                                @endforeach
                                            </ul>
                                            @break

                                        @case('text')
                                            <div class="mobile-fs-12">{{ $b['content'] ?? '' }}</div>
                                            @break

                                        @case('contact')
                                            <ul class="tb-footer__contact">
                                                @if (!empty($b['address']))<li><i class="fi fi-rr-marker"></i><span>{{ $b['address'] }}</span></li>@endif
                                                @if (!empty($b['phone']))<li><i class="fi fi-rr-phone-call"></i><a class="direction-ltr" href="tel:{{ $b['phone'] }}">{{ $b['phone'] }}</a></li>@endif
                                                @if (!empty($b['email']))<li><i class="fi fi-rr-envelope"></i><a href="mailto:{{ $b['email'] }}">{{ $b['email'] }}</a></li>@endif
                                            </ul>
                                            @break

                                        @case('social')
                                            <ul class="tb-footer__social">
                                                @foreach (['facebook' => 'fi fi-brands-facebook', 'instagram' => 'fi fi-brands-instagram', 'tiktok' => 'fi fi-brands-tik-tok', 'youtube' => 'fi fi-brands-youtube', 'x' => 'fi fi-brands-twitter-alt'] as $network => $icon)
                                                    @if (!empty($b[$network]))
                                                        <li><a href="{{ $b[$network] }}" target="_blank" rel="noopener" aria-label="{{ $network }}"><i class="{{ $icon }}"></i></a></li>
                                                    @endif
                                                @endforeach
                                            </ul>
                                            @break

                                        @case('apps')
                                            <div class="d-flex flex-wrap gap-2 tb-apps">
                                                @if (!empty($b['google_play']))
                                                    <a href="{{ $b['google_play'] }}" target="_blank" rel="noopener">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/img/media/google-app.png') }}" alt="Google Play">
                                                    </a>
                                                @endif
                                                @if (!empty($b['app_store']))
                                                    <a href="{{ $b['app_store'] }}" target="_blank" rel="noopener">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/img/media/app-app.png') }}" alt="App Store">
                                                    </a>
                                                @endif
                                            </div>
                                            @break
                                    @endswitch
                                </div>
                            @endforeach
                        </div>
                        @break

                    @case('newsletter')
                        <div class="row align-items-center g-3">
                            <div class="col-md-6">
                                <h5 class="mb-1">{{ $s['title'] ?: translate('newsletter') }}</h5>
                                <div class="mobile-fs-12">{{ $s['subtitle'] ?: translate('subscribe_to_our_new_channel_to_get_latest_updates') }}</div>
                            </div>
                            <div class="col-md-6">
                                <form class="tb-news-form ms-md-auto" action="{{ route('subscription') }}" method="post">
                                    @csrf
                                    <input type="email" name="subscription_email" placeholder="{{ translate('your_Email_Address') }}" required>
                                    <button type="submit">{{ translate('subscribe') }}</button>
                                </form>
                            </div>
                        </div>
                        @break

                    @case('custom_html')
                        <div class="mobile-fs-12">{{ $s['content'] ?? '' }}</div>
                        @break

                @endswitch
            </div>
        </div>
    @endforeach

    <div class="tb-footer__bottom">
        <div class="container text-center">{{ $web_config['copyright_text'] ?? '' }}</div>
    </div>
</footer>
@endif
