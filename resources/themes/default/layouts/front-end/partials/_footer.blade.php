{{-- Themed footer (Theme Builder "footer" page): when the published version composes footer
     sections they replace this built-in footer wholesale — same replace-not-stack contract as the
     themed home. Guarded: a broken section logs and the built-in footer renders as the fallback. --}}
@php
    $__themedFooter = '';
    try {
        $__themedFooter = trim(view('theme-sections.footer')->render());
    } catch (\Throwable $themeFooterError) {
        report($themeFooterError);
        $__themedFooter = '';
    }
    echo $__themedFooter;
@endphp
@if ($__themedFooter === '')
@php
    // Everything below is drawn from what the merchant already fills in: company info and legal
    // identifiers (Business settings), pages (Pages & media), social links, and the payment
    // gateways that are actually switched on. A block with nothing behind it is simply not drawn.
    $__pages = $web_config['business_pages'] ?? collect();
    $__legal = collect([
        ['label' => translate('commercial_registration'), 'value' => getWebConfig(name: 'company_registration_no'), 'icon' => 'fi fi-rr-badge-check'],
        ['label' => translate('vat_number'), 'value' => getWebConfig(name: 'company_vat_no'), 'icon' => 'fi fi-rr-receipt'],
        ['label' => translate('business_platform'), 'value' => getWebConfig(name: 'company_platform_no'), 'icon' => 'fi fi-rr-shield-check'],
    ])->filter(fn ($row) => trim((string) $row['value']) !== '');

    $__gateways = collect();
    try {
        $__gateways = \App\Models\Setting::where(['settings_type' => 'payment_config', 'is_active' => 1])
            ->get()
            ->map(function ($gateway) {
                $extra = json_decode($gateway->additional_data ?? '{}');
                $image = $extra?->gateway_image ?? null;
                $path = $image && file_exists(base_path('storage/app/public/payment_modules/gateway_image/' . $image))
                    ? dynamicStorage(path: 'storage/app/public/payment_modules/gateway_image/' . $image)
                    : null;

                return ['title' => $extra?->gateway_title ?: ucwords(str_replace('_', ' ', $gateway->key_name)), 'image' => $path];
            });
    } catch (\Throwable $gatewayError) {
        report($gatewayError);
    }
@endphp

<footer class="k-foot rtl" role="contentinfo">
    <div class="container">
        <div class="k-foot__grid">
            {{-- Who we are --}}
            <div class="k-foot__brand">
                <a class="k-foot__logo" href="{{ route('home') }}">
                    <img src="{{ getStorageImages(path: $web_config['footer_logo'], type: 'logo') }}"
                         alt="{{ $web_config['company_name'] }}">
                </a>
                {{-- The shop's own words: the summary of the About us page the merchant wrote. --}}
                @if (!empty($web_config['meta_description']))
                    <p class="k-foot__about">{{ $web_config['meta_description'] }}</p>
                @endif

                @if ($__legal->isNotEmpty())
                    <ul class="k-foot__legal">
                        @foreach ($__legal as $item)
                            <li>
                                <i class="{{ $item['icon'] }}"></i>
                                <span>
                                    <small>{{ $item['label'] }}</small>
                                    <b class="direction-ltr">{{ $item['value'] }}</b>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Important links: the merchant's own policy pages --}}
            <nav class="k-foot__col" aria-label="{{ translate('important_links') }}">
                <h6>{{ translate('important_links') }}</h6>
                <ul>
                    @foreach ($__pages->where('default_status', 1)->take(6) as $businessPage)
                        <li>
                            <a href="{{ route('business-page.view', ['slug' => $businessPage['slug']]) }}">
                                {{ Str::limit($businessPage['title'], 32, '...') }}
                            </a>
                        </li>
                    @endforeach
                    <li><a href="{{ route('track-order.index') }}">{{ translate('track_order') }}</a></li>
                </ul>
            </nav>

            {{-- Shop --}}
            <nav class="k-foot__col" aria-label="{{ translate('sections') }}">
                <h6>{{ translate('sections') }}</h6>
                <ul>
                    <li><a href="{{ route('products') }}">{{ translate('all_products') }}</a></li>
                    @if (getWebConfig(name: 'product_brand') && \Illuminate\Support\Facades\Route::has('brands'))
                        <li><a href="{{ route('brands') }}">{{ translate('brands') }}</a></li>
                    @endif
                    <li><a href="{{ route('featured-products') }}">{{ translate('featured_products') }}</a></li>
                    <li><a href="{{ route('best-selling-products') }}">{{ translate('best_selling_product') }}</a></li>
                    <li><a href="{{ route('latest-products') }}">{{ translate('latest_products') }}</a></li>
                    @if ($web_config['flash_deals'] && count($web_config['flash_deals_products']) > 0)
                        <li><a href="{{ route('flash-deals', ['id' => $web_config['flash_deals']['id']]) }}">{{ translate('flash_deal') }}</a></li>
                    @endif
                </ul>
            </nav>

            {{-- Talk to us --}}
            <div class="k-foot__col k-foot__contact">
                <h6>{{ translate('contact_Us') }}</h6>
                @if (getWebConfig(name: 'company_phone'))
                    <a class="k-foot__contact-line" href="{{ 'tel:' . getWebConfig(name: 'company_phone') }}">
                        <i class="fi fi-rr-phone-call"></i>
                        <span class="direction-ltr">{{ getWebConfig(name: 'company_phone') }}</span>
                    </a>
                @endif
                @if (getWebConfig(name: 'company_email'))
                    <a class="k-foot__contact-line" href="{{ 'mailto:' . getWebConfig(name: 'company_email') }}">
                        <i class="fi fi-rr-envelope"></i>
                        <span class="direction-ltr">{{ getWebConfig(name: 'company_email') }}</span>
                    </a>
                @endif
                @if (getWebConfig(name: 'shop_address'))
                    <span class="k-foot__contact-line">
                        <i class="fi fi-rr-marker"></i>
                        <span>{{ getWebConfig(name: 'shop_address') }}</span>
                    </span>
                @endif
                <a class="k-foot__contact-line" href="{{ auth('customer')->check() ? route('account-tickets') : route('customer.auth.login') }}">
                    <i class="fi fi-rr-headset"></i>
                    <span>{{ translate('support_ticket') }}</span>
                </a>

                @if (!empty($web_config['social_media']) && count($web_config['social_media']))
                    <div class="k-foot__social">
                        @foreach ($web_config['social_media'] as $item)
                            <a href="{{ $item->link }}" target="_blank" rel="noopener"
                               aria-label="{{ $item->name }}" title="{{ ucfirst($item->name) }}">
                                <i class="{{ $item->icon }}" aria-hidden="true"></i>
                            </a>
                        @endforeach
                    </div>
                @endif

                <form class="k-foot__news" action="{{ route('subscription') }}" method="post">
                    @csrf
                    <input type="email" name="subscription_email" required
                           placeholder="{{ translate('your_Email_Address') }}"
                           aria-label="{{ translate('newsletter') }}">
                    <button type="submit">{{ translate('subscribe') }}</button>
                </form>

                @if ((isset($web_config['ios']['status']) && $web_config['ios']['status']) || (isset($web_config['android']['status']) && $web_config['android']['status']))
                    <div class="k-foot__apps">
                        @if (isset($web_config['ios']['status']) && $web_config['ios']['status'])
                            <a href="{{ $web_config['ios']['link'] }}" target="_blank" rel="noopener">
                                <img src="{{ theme_asset(path: 'public/assets/front-end/png/apple_app.png') }}" alt="App Store">
                            </a>
                        @endif
                        @if (isset($web_config['android']['status']) && $web_config['android']['status'])
                            <a href="{{ $web_config['android']['link'] }}" target="_blank" rel="noopener">
                                <img src="{{ theme_asset(path: 'public/assets/front-end/png/google_app.png') }}" alt="Google Play">
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="k-foot__bar">
        <div class="container k-foot__bar-inner">
            <p class="k-foot__copy">{{ $web_config['copyright_text'] }}</p>

            @if ($__gateways->isNotEmpty() || getWebConfig(name: 'cash_on_delivery'))
                <ul class="k-foot__pay" aria-label="{{ translate('payment_methods') }}">
                    @foreach ($__gateways as $gateway)
                        <li title="{{ $gateway['title'] }}">
                            @if ($gateway['image'])
                                <img src="{{ $gateway['image'] }}" alt="{{ $gateway['title'] }}" loading="lazy">
                            @else
                                <span>{{ $gateway['title'] }}</span>
                            @endif
                        </li>
                    @endforeach
                    @if (getWebConfig(name: 'cash_on_delivery'))
                        <li><span>{{ translate('cash_on_delivery') }}</span></li>
                    @endif
                </ul>
            @endif

            @if (count($__pages->where('default_status', 0)))
                <ul class="k-foot__mini">
                    @foreach ($__pages->where('default_status', 0)->take(4) as $businessPage)
                        <li>
                            <a href="{{ route('business-page.view', ['slug' => $businessPage['slug']]) }}">
                                {{ Str::limit($businessPage['title'], 24, '...') }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @php($cookie = $web_config['cookie_setting'] ? json_decode($web_config['cookie_setting']['value'], true) : null)
    @if ($cookie && $cookie['status'] == 1)
        <section id="cookie-section"></section>
    @endif
</footer>
@endif
