<section class="overflow-hidden">
    <div class="container">
        <div class="flash-deals-wrapper rounded {{ count($bannerTypeMainBanner) > 0 ? '' : 'mt-20' }}">
            <div class="flash-deal-view-all-web row d-flex justify-content-end align-items-center mb-3">
                <div class="flash-deal-text web-text-primary mt-0 flex-grow-1">
                    <h1 class="web-text-primary lh-1 h3 letter-spacing-0 line--limit-3 text-start mb-0">
                        <span>{{$web_config['flash_deals']->title}}</span>
                    </h1>
                </div>
                @if ($web_config['flash_deals']->products_count > 0)
                    <a class="text-capitalize view-all-btn-text"
                       href="{{ route('flash-deals',['id' => $web_config['flash_deals'] ? $web_config['flash_deals']['id'] : 0]) }}">
                        <span class="view-btn-text">{{ translate('View_All') }}</span>
                        <i class="czi-arrow-{{ session('direction') === "rtl" ? 'left' : 'right' }}"></i>
                    </a>
                @endif
            </div>
            <?php
                $startDate = \Carbon\Carbon::parse($web_config['flash_deals']['start_date']);
                $endDate = \Carbon\Carbon::parse($web_config['flash_deals']['end_date']);
                $now = \Carbon\Carbon::now();

                if ($now->lt($startDate)) {
                    $flashDealsPercentage = 0;
                } elseif ($now->gt($endDate)) {
                    $flashDealsPercentage = 100;
                } else {
                    $totalDuration = $startDate->diffInSeconds($endDate);
                    $elapsedDuration = $startDate->diffInSeconds($now);

                    $flashDealsPercentage = ($elapsedDuration / $totalDuration) * 100;
                }
            ?>
            <div class="d-none d-sm-block">
                <div class="row g-3">
                    <div class="col-lg-4 flashdeal-responsive">
                        <h2 class="fs-16 fw-semibold text-primary mb-3">{{translate('hurry_Up')}} ! {{translate('the_offer_is_limited')}}. {{translate('grab_while_it_lasts')}}</h2>
                        <a href="{{ route('flash-deals',['id' => $web_config['flash_deals'] ? $web_config['flash_deals']['id'] : 0]) }}" class="countdown-card bg-transparent d-block">
                            <div class="countdown-background text-white w-100 max-w-100 p-3 mt-0">
                                <div class="text-center">
                                   <span class="cz-countdown d-flex justify-content-center align-items-center flash-deal-countdown"
                                         data-countdown="{{$web_config['flash_deals']?date('m/d/Y',strtotime($web_config['flash_deals']['end_date'])):''}} 23:59:00 ">
                                       <span class="cz-countdown-days">
                                           <span class="cz-countdown-value"></span>
                                           <span class="cz-countdown-text text-nowrap">{{ translate('days')}}</span>
                                       </span>
                                       <span class="cz-countdown-value p-1">:</span>
                                       <span class="cz-countdown-hours">
                                           <span class="cz-countdown-value"></span>
                                           <span class="cz-countdown-text text-nowrap">{{ translate('hours')}}</span>
                                       </span>
                                       <span class="cz-countdown-value p-1">:</span>
                                       <span class="cz-countdown-minutes">
                                           <span class="cz-countdown-value"></span>
                                           <span class="cz-countdown-text text-nowrap">{{ translate('minutes')}}</span>
                                       </span>
                                       <span class="cz-countdown-value p-1">:</span>
                                       <span class="cz-countdown-seconds">
                                           <span class="cz-countdown-value"></span>
                                           <span class="cz-countdown-text text-nowrap">{{ translate('seconds')}}</span>
                                       </span>
                                   </span>
                                    <div class="progress __progress">
                                        <div class="progress-bar flash-deal-progress-bar" role="progressbar" style="width: {{ number_format($flashDealsPercentage, 2) }}%" aria-valuenow="25" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    @php($flashDealCount = count($flashDeal['flashDealProducts']))
                    @if($flashDealCount == 10 || $flashDealCount <= 4)
                        @foreach($flashDeal['flashDealProducts'] as $key=>$flashDealProduct)
                            <div class="col-lg-2 col-md-4 col-sm-6">
                                @include('web-views.partials._feature-product',['product'=> $flashDealProduct,'decimal_point_settings'=>$decimal_point_settings])
                            </div>
                        @endforeach
                    @endif
                    @if($flashDealCount > 4 && $flashDealCount < 10)
                        <div class="col-lg-8 d-none d-md-block">
                            <div class="owl-theme owl-carousel flash-deal-slider">
                                @foreach($flashDeal['flashDealProducts'] as $key=>$flashDealProduct)
                                    @include('web-views.partials._feature-product',['product'=> $flashDealProduct,'decimal_point_settings'=>$decimal_point_settings])
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="d-sm-none">
                <div class="row g-3">
                    <div class="col-sm-12 col-5">
                        <div class="">
                            <h3 class="web-text-primary fw-bold lh-1 fs-16 letter-spacing-0 line--limit-3 text-uppercase mb-2">
                                <span>{{$web_config['flash_deals']->title}}</span>
                            </h3>
                            <h5 class="fs-12 lh-19px font-weight-normal web-text-primary mb-0">{{translate('hurry_Up')}} ! {{translate('the_offer_is_limited')}}. {{translate('grab_while_it_lasts')}}</h5>
                        </div>
                    </div>
                    <div class="col-sm-12 col-7">
                        <div class="flashdeal-responsive">
                            <a href="{{ route('flash-deals',['id' => $web_config['flash_deals']?$web_config['flash_deals']['id']:0]) }}" class="countdown-card bg-transparent d-block">
                                <div class="countdown-background text-white w-100 max-w-100 px-2 pb-2 pt-sm-0 mt-0">
                                    <div class="text-center">
                                       <span class="cz-countdown d-flex justify-content-center align-items-center flash-deal-countdown pb-sm-3"
                                             data-countdown="{{$web_config['flash_deals']?date('m/d/Y',strtotime($web_config['flash_deals']['end_date'])):''}} 23:59:00 ">
                                           <span class="cz-countdown-days">
                                               <span class="cz-countdown-value fs-8 lh-2"></span>
                                               <span class="cz-countdown-text text-nowrap fs-8">{{ translate('days')}}</span>
                                           </span>
                                           <span class="cz-countdown-value p-1 fs-8">:</span>
                                           <span class="cz-countdown-hours">
                                               <span class="cz-countdown-value fs-8 lh-2"></span>
                                               <span class="cz-countdown-text text-nowrap fs-8">{{ translate('hours')}}</span>
                                           </span>
                                           <span class="cz-countdown-value p-1 fs-8">:</span>
                                           <span class="cz-countdown-minutes">
                                               <span class="cz-countdown-value fs-8 lh-2"></span>
                                               <span class="cz-countdown-text text-nowrap fs-8">{{ translate('min')}}</span>
                                           </span>
                                           <span class="cz-countdown-value p-1 fs-8">:</span>
                                           <span class="cz-countdown-seconds">
                                               <span class="cz-countdown-value fs-8 lh-2"></span>
                                               <span class="cz-countdown-text text-nowrap fs-8">{{ translate('sec')}}</span>
                                           </span>
                                       </span>
                                        <div class="progress __progress h-2px mt-0">
                                            <div class="progress-bar flash-deal-progress-bar" role="progressbar" style="width: {{ number_format($flashDealsPercentage, 2) }}%" aria-valuenow="25" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="col-12 pb-0 d-md-none">
                        <div class="owl-theme owl-carousel flash-deal-slider-mobile">
                            @foreach($flashDeal['flashDealProducts'] as $key=>$flashDealProduct)
                                @include('web-views.partials._product-card-1',['product' => $flashDealProduct,'decimal_point_settings'=>$decimal_point_settings])
                            @endforeach
                        </div>
                    </div>
                    @if (count($flashDeal['flashDealProducts']) > 0)
                        <div class="col-12 d-md-none text-center">
                            <a class="text-capitalize view-all-btn-text"
                               href="{{ route('flash-deals',['id' => $web_config['flash_deals']?$web_config['flash_deals']['id'] : 0]) }}">
                                <span class="view-btn-text">{{ translate('View_All') }}</span>
                                <i class="czi-arrow-{{ session('direction') === "rtl" ? 'left' : 'right' }}"></i>
                            </a>
                        </div>
                    @endif
                </div>
            </div>


        </div>
    </div>
</section>
