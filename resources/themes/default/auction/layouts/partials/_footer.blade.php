<div class="__inline-9 footer-wrapper pt-md-4 pt-3 rtl">
    <div class="text-center pb-4">
        <div class="max-w-1160px mx-auto footer-slider-container">
            <div class="container">
                <div class="row g-2" data-item="{{ Route::has('frontend.blog.index') && getWebConfig(name: 'blog_feature_active_status') ? 4 : 3 }}">
                    @if($web_config['business_pages']?->firstWhere('slug', 'about-us'))
                   <div class="{{ Route::has('frontend.blog.index') && getWebConfig(name: 'blog_feature_active_status') ? 'col-6 col-md-3' : 'col-6 col-md-4' }}">
                       <div class="footer-slide-item bg-white">
                           <div>
                               <a href="{{ route('business-page.view', ['slug' => 'about-us']) }}">
                                   <div class="text-center text-primary mb-lg-3 mb-2">
                                       <img class="object-contain svg" width="36" height="36"
                                            src="{{ theme_asset(path: "public/assets/front-end/img/icons/about-us.svg")}}"
                                            alt="{{ translate('about_us') }}">
                                   </div>
                                   <div class="text-center">
                                       <h2 class="m-0 mt-2 heading">
                                           {{ translate('about_us') }}
                                       </h2>
                                       <p class="d-none d-sm-block des mb-0">{{ translate('Know_about_our_company_more.') }}</p>
                                   </div>
                               </a>
                           </div>
                       </div>
                   </div>
                   @endif

                   <div class="{{ Route::has('frontend.blog.index') && getWebConfig(name: 'blog_feature_active_status') ? 'col-6 col-md-3' : 'col-6 col-md-4' }}">
                       <div class="footer-slide-item bg-white">
                           <div>
                               <a href="{{ route('contacts') }}">
                                   <div class="text-center text-primary mb-lg-3 mb-2">
                                       <img class="object-contain svg" width="36" height="36"
                                           src="{{ theme_asset(path: "public/assets/front-end/img/icons/contact-us.svg") }}"
                                           alt="{{ translate('contact_Us') }}">
                                   </div>
                                   <div class="text-center">
                                       <p class="m-0 mt-2">
                                           {{ translate('contact_Us') }}
                                       </p>
                                       <small class="d-none d-sm-block">{{ translate('We_are_Here_to_Help') }}</small>
                                   </div>
                               </a>
                           </div>
                       </div>
                   </div>
                   <div class="{{ Route::has('frontend.blog.index') && getWebConfig(name: 'blog_feature_active_status') ? 'col-6 col-md-3' : 'col-6 col-md-4' }}">
                       <div class="footer-slide-item bg-white">
                           <div>
                               <a href="{{ route('helpTopic') }}">
                                   <div class="text-center text-primary mb-lg-3 mb-2">
                                       <img class="object-contain svg" width="36" height="36"
                                           src="{{ theme_asset(path: "public/assets/front-end/img/icons/faq-icon.svg") }}"
                                           alt="{{ translate('FAQ') }}">
                                   </div>
                                   <div class="text-center">
                                       <p class="m-0 mt-2">
                                           {{ translate('FAQ') }}
                                       </p>
                                       <small class="d-none d-sm-block">{{ translate('Get_all_Answers') }}</small>
                                   </div>
                               </a>
                           </div>
                       </div>
                   </div>

                   @if(Route::has('frontend.blog.index') && getWebConfig(name: 'blog_feature_active_status'))
                   <div class="{{ Route::has('frontend.blog.index') && getWebConfig(name: 'blog_feature_active_status') ? 'col-6 col-md-3' : 'col-6 col-md-4' }}">
                       <div class="footer-slide-item bg-white">
                           <div>
                               <a href="{{ route('frontend.blog.index') }}">
                                   <div class="text-center text-primary mb-lg-3 mb-2">
                                       <img class="object-contain svg" width="36" height="36"
                                           src="{{ theme_asset(path: "public/assets/front-end/img/icons/blog-icon.svg")}}"
                                           alt="{{ translate('Blog') }}">
                                   </div>
                                   <div class="text-center">
                                       <p class="m-0 mt-2">
                                           {{ translate('Blog') }}
                                       </p>
                                       <small class="d-none d-sm-block">{{ translate('Check_Latest_Blogs') }}</small>
                                   </div>
                               </a>
                           </div>
                       </div>
                   </div>
                   @endif
                </div>
            </div>
        </div>
    </div>

    <footer class="page-footer font-small mdb-color rtl">
        <div class="pt-4 custom-light-primary-color-20">
            <div class="container text-center pb-3">

                <div
                    class="row mt-3 pb-3 ">
                    <div class="col-md-3 footer-web-logo text-center text-md-start d-flex flex-column pb-3 justify-content-center justify-content-md-start">
                        <a class="d-block" href="{{ route('home') }}">
                            <img class="{{Session::get('direction') === "rtl" ? 'right-align' : ''}}"
                                 src="{{ getStorageImages(path: $web_config['footer_logo'], type: 'logo') }}"
                                 alt="{{ $web_config['company_name'] }}"/>
                        </a>
                        @if((isset($web_config['ios']['status']) && $web_config['ios']['status']) || (isset($web_config['android']['status']) &&  $web_config['android']['status']))
                            <div class="mt-4 pt-lg-4">
                                <h6 class="text-uppercase footer-header text-white align-items-center text-center text-md-start">
                                    {{ translate('download_our_app') }}
                                </h6>
                            </div>
                        @endif

                        <div class="store-contents d-flex justify-content-center pt-1">
                            @if(isset($web_config['ios']['status']) && $web_config['ios']['status'])
                                <div class="me-2 mb-2">
                                    <a class="" href="{{ $web_config['ios']['link'] }}" role="button">
                                        <img width="100"
                                             src="{{ theme_asset(path: "public/assets/front-end/png/apple_app.png")}}"
                                             alt="{{ translate('App') }}">
                                    </a>
                                </div>
                            @endif

                            @if(isset($web_config['android']['status']) && $web_config['android']['status'])
                                <div class="me-2 mb-2">
                                    <a href="{{ $web_config['android']['link'] }}" role="button">
                                        <img width="100"
                                             src="{{ theme_asset(path: "public/assets/front-end/png/google_app.png")}}"
                                             alt="{{ translate('App') }}">
                                    </a>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="row">
                            <div class="col-sm-3 col-6 footer-padding-bottom text-start">
                                <h6 class="pb-1 text-uppercase fs-12-mobile footer-header text-white">{{ translate('quick_links') }}</h6>
                                <ul class="widget-list pb-2">
                                    @if(auth('customer')->check())
                                        <li class="widget-list-item text-capitalize mb-1">
                                            <a class="widget-list-link" href="{{ route('user-account') }}">
                                                {{ translate('profile_info') }}
                                            </a>
                                        </li>
                                        <li class="widget-list-item text-capitalize mb-1">
                                            <a class="widget-list-link" href="{{ route('wishlists') }}">
                                                {{ translate('wish_list') }}
                                            </a>
                                        </li>
                                    @else
                                        <li class="widget-list-item text-capitalize mb-1">
                                            <a class="widget-list-link" href="{{ route('customer.auth.login') }}">
                                                {{ translate('profile_info') }}
                                            </a>
                                        </li>
                                    @endif
                                    @if($web_config['flash_deals'] && count($web_config['flash_deals_products']) > 0)
                                        <li class="widget-list-item text-capitalize mb-1">
                                            <a class="widget-list-link"
                                               href="{{ route('flash-deals',['id' => $web_config['flash_deals']['id']]) }}">
                                                {{ translate('flash_deal') }}
                                            </a>
                                        </li>
                                    @endif
                                    <li class="widget-list-item text-capitalize mb-1">
                                        <a class="widget-list-link"
                                           href="{{ route('featured-products') }}">
                                            {{ translate('featured_products') }}
                                        </a>
                                    </li>
                                    <li class="widget-list-item text-capitalize mb-1">
                                        <a class="widget-list-link"
                                           href="{{ route('best-selling-products') }}">
                                            {{ translate('best_selling_product') }}
                                        </a>
                                    </li>
                                    <li class="widget-list-item text-capitalize mb-1">
                                        <a class="widget-list-link"
                                           href="{{ route('latest-products') }}">
                                            {{ translate('latest_products') }}
                                        </a>
                                    </li>
                                    <li class="widget-list-item text-capitalize mb-1">
                                        <a class="widget-list-link"
                                           href="{{ route('top-rated-products') }}">
                                            {{ translate('top_rated_product') }}
                                        </a>
                                    </li>


                                    <li class="widget-list-item text-capitalize mb-1">
                                        <a class="widget-list-link"
                                           href="{{ route('track-order.index') }}">{{ translate('track_order') }}</a>
                                    </li>
                                </ul>
                            </div>
                            <div class="col-sm-4 col-6 footer-padding-bottom text-start">
                                <h6 class="pb-1 text-uppercase fs-12-mobile footer-header text-white">
                                    {{ translate('other') }}
                                </h6>

                                <ul class="widget-list pb-2">
                                    @foreach($web_config['business_pages']->where('default_status', 1) as $businessPage)
                                            <li class="widget-list-item text-capitalize mb-1">
                                                <a class="widget-list-link"
                                                   href="{{ route('business-page.view', ['slug' => $businessPage['slug']]) }}">
                                                    {{ Str::limit($businessPage['title'], 25, '...') }}
                                                </a>
                                            </li>
                                    @endforeach
                                </ul>
                            </div>
                            <div class="col-sm-5 footer-padding-bottom offset-max-sm--1 pb-3 pb-sm-0">
                                <div class="mb-2">
                                    <h6 class="pb-1 text-uppercase fs-12-mobile footer-header text-white text-center text-sm-start">{{ translate('newsletter') }}</h6>
                                    <div
                                        class="text-center text-sm-start fs-12-mobile">{{ translate('subscribe_to_our_new_channel_to_get_latest_updates') }}</div>
                                </div>
                                <div class="text-nowrap mb-4 position-relative">
                                    <form action="{{ route('subscription') }}" method="post">
                                        @csrf
                                        <input type="email" name="subscription_email"
                                               class="form-control subscribe-border text-align-direction p-12px"
                                               placeholder="{{ translate('your_Email_Address') }}" required>
                                        <button class="subscribe-button" type="submit">
                                            {{ translate('subscribe') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row g-4 {{Session::get('direction') === "rtl" ? ' flex-row-reverse' : ''}}">
                    <div class="col-xl-5 col-md-6">
                        <div
                            class="d-flex align-items-center mobile-view-center-align text-start justify-content-between">
                            <div class="">
                                <span
                                    class="mb-4 footer-header text-white text-capitalize">{{ translate('start_a_conversation') }}</span>
                            </div>
                            <div
                                class="flex-grow-1 d-none d-md-block {{Session::get('direction') === "rtl" ? 'me-2 ' : 'ml-2'}}">
                                <hr>
                            </div>
                        </div>
                        <div class="row text-start">
                            <div class="col-12 start_address d-flex justify-content-between flex-wrap">
                                <div class="">
                                    @if(!empty($web_config['phone']))
                                    <a class="widget-list-link" href="{{ 'tel:'.$web_config['phone'] }}">
                                        <span class="d-flex gap-2 align-items-center">
                                            <i class="fa fa-phone  me-2 mt-2 mb-2"></i>
                                            <span class="ltr">
                                                {{ getWebConfig(name: 'company_phone') }}
                                            </span>
                                        </span>
                                    </a>
                                    @endif
                                </div>
                                <div>
                                    <a class="widget-list-link"
                                       href="{{ 'mailto:'.getWebConfig(name: 'company_email') }}">
                                        <span class="d-flex gap-2 align-items-center">
                                            <i class="fa fa-envelope  me-2 mt-2 mb-2"></i>
                                            {{ getWebConfig(name: 'company_email') }}
                                        </span>
                                    </a>
                                </div>
                                <div class="pe-3">
                                    @if(auth('customer')->check())
                                        <a class="widget-list-link" href="{{ route('account-tickets') }}">
                                            <span class="d-flex gap-2 align-items-center"><i class="fa fa-user-o me-2 mt-2 mb-2"></i> {{ translate('support_ticket') }} </span>
                                        </a>
                                        <br class="d-none d-md-block"/>
                                    @else
                                        <a class="widget-list-link" href="{{ route('customer.auth.login') }}">
                                            <span class="d-flex gap-2 align-items-center"><i class="fa fa-user-o  me-2 mt-2 mb-2"></i> {{ translate('support_ticket') }} </span>
                                        </a>
                                        <br class="d-none d-md-block"/>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-6 text-start">
                        <div
                            class="d-flex align-items-center mobile-view-center-align justify-content-center justify-content-md-start pb-0">
                            <div class="d-none d-md-block">
                                <span class="mb-4 footer-header text-white">{{ translate('address') }}</span>
                            </div>
                            <div
                                class="flex-grow-1 d-none d-md-block {{ Session::get('direction') === "rtl" ? 'me-2 ' : 'ml-2' }}">
                                <hr class="address_under_line d-block"/>
                            </div>
                        </div>
                        <div>
                            <span
                                class="fs-14 d-flex align-items-center">
                                <i class="fa fa-map-marker me-2 mt-2 mb-2"></i>
                                <span>{{ getWebConfig(name: 'shop_address') }}</span>
                            </span>
                        </div>
                    </div>
                    <div class="col-xl-3">
                        <div class="max-w-100-mobile justify-content-center d-flex flex-wrap mt-md-3 mt-0 mb-md-3">
                            @if($web_config['social_media'])
                                @foreach ($web_config['social_media'] as $item)
                                    <span class="social-media ">
                                        @if ($item->name == "twitter")
                                            <a class="social-btn text-white sb-light sb-{{ $item->name}} me-2 mb-2 d-flex justify-content-center align-items-center"
                                               target="_blank" href="{{ $item->link}}">
                                                <svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="16"
                                                     height="16" viewBox="0 0 24 24">
                                                    <g opacity=".3">
                                                        <polygon fill="#fff" fill-rule="evenodd"
                                                                 points="16.002,19 6.208,5 8.255,5 18.035,19"
                                                                 clip-rule="evenodd">
                                                        </polygon>
                                                        <polygon points="8.776,4 4.288,4 15.481,20 19.953,20 8.776,4">
                                                        </polygon>
                                                    </g>
                                                    <polygon fill-rule="evenodd"
                                                             points="10.13,12.36 11.32,14.04 5.38,21 2.74,21"
                                                             clip-rule="evenodd">
                                                    </polygon>
                                                    <polygon fill-rule="evenodd"
                                                             points="20.74,3 13.78,11.16 12.6,9.47 18.14,3"
                                                             clip-rule="evenodd">
                                                    </polygon>
                                                    <path
                                                        d="M8.255,5l9.779,14h-2.032L6.208,5H8.255 M9.298,3h-6.93l12.593,18h6.91L9.298,3L9.298,3z"
                                                        fill="currentColor">
                                                    </path>
                                                </svg>
                                            </a>
                                        @else
                                            <a class="social-btn text-white sb-light sb-{{ $item->name }} me-2 mb-2"
                                               target="_blank" href="{{ $item->link }}">
                                                <i class="{{ $item->icon }}" aria-hidden="true"></i>
                                            </a>
                                        @endif
                                    </span>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white bg-opacity-05 py-4">
            <div class="container">
                <div class="row">
                    <div class="col"></div>
                    <div class="col-md-10">
                        <div class="d-flex flex-column gap-3">
                            <div>
                                <p class="fs-14 text-center m-0">{{ $web_config['copyright_text'] }}</p>
                            </div>
                            @if(count($web_config['business_pages']->where('default_status', 0)) > 0)
                                <ul class="d-flex fs-12 flex-wrap flex-column flex-sm-row justify-content-center align-content-center gap-2 column-gap-4 mb-0 p-0">
                                    @foreach($web_config['business_pages']->where('default_status', 0) as $businessPage)
                                        <li class="opacity-70">
                                            <a class="widget-list-link"
                                               href="{{ route('business-page.view', ['slug' => $businessPage['slug']]) }}">
                                                <span class="d-flex gap-2 align-items-center">{{ Str::limit($businessPage['title'], 25, '...') }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                    <div class="col"></div>
                </div>
            </div>
        </div>

        @php($cookie = $web_config['cookie_setting'] ? json_decode($web_config['cookie_setting']['value'], true) : null)
        @if($cookie && $cookie['status']==1)
            <section id="cookie-section"></section>
        @endif
    </footer>
</div>
