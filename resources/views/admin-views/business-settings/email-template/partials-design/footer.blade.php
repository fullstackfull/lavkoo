<p class="view-footer-text">
    {{$footerText}}
</p>
<p>{{translate('Thanks_&_Regards')}}, <br> {{ getWebConfig(name: 'company_name') }}</p>
<div class="bg-white rounded-10 px-2 py-4">
    <div class="d-flex justify-content-center mb-4">
        <img width="76" class="mx-auto" id="view-mail-logo" src="{{$template->logo_full_url['path'] ?? getStorageImages(path: $companyLogo, type:'backend-logo')}}" alt="">
    </div>
    <div class="d-flex justify-content-center gap-2">
        <ul class="list-unstyled d-flex flex-column flex-xl-row align-items-center p-0 gap-3 mx-auto" id="selected-pages">
            @if(!empty($template['pages']) && in_array('privacy_policy',$template['pages']))
                <li class="privacy-policy"><a href="{{ route('business-page.view', ['slug' => 'privacy-policy']) }}" class="text-dark fs-12">{{translate('privacy_Policy')}}</a></li>
            @endif
            @if(!empty($template['pages']) && in_array('refund_policy',$template['pages']))
                <li class="refund-policy"><a href="{{ route('business-page.view', ['slug' => 'refund-policy'])}}" class="text-dark fs-12 border_middle">{{translate('refund_Policy')}}</a></li>
            @endif
            @if(!empty($template['pages']) && in_array('cancellation_policy',$template['pages']))
                <li class="cancellation-policy"><a href="{{ route('business-page.view', ['slug' => 'cancellation-policy'])}}" class="text-dark fs-12 border_middle">{{translate('cancellation_Policy')}}</a></li>
            @endif
            @if(!empty($template['pages']) && in_array('contact_us',$template['pages']))
                <li class="contact-us"><a href="{{route('contacts') }}" class="text-dark fs-12 border_middle">{{translate('contact_Us')}}</a></li>
            @endif
            @if(empty($template['pages']))
                <li class="privacy-policy"><a href="{{ route('business-page.view', ['slug' => 'privacy-policy']) }}" class="text-dark fs-12">{{translate('privacy_Policy')}}</a></li>
                <li class="refund-policy"><a href="{{ route('business-page.view', ['slug' => 'refund-policy']) }}" class="text-dark fs-12 border_middle">{{translate('refund_Policy')}}</a></li>
                <li class="cancellation-policy"><a href="{{ route('business-page.view', ['slug' => 'cancellation-policy'])}}" class="text-dark fs-12 border_middle">{{translate('cancellation_Policy')}}</a></li>
                <li class="contact-us"><a href="{{route('contacts') }}" class="text-dark fs-12 border_middle">{{translate('contact_Us')}}</a></li>
            @endif

        </ul>
    </div>
    <div class="d-flex gap-4 justify-content-center align-items-center mb-3 fs-16 social-media-icon" id="selected-social-media">
        @foreach($socialMedia as $key=>$media)
            @if(!empty($template['social_media']))
                <a class="{{$media['name']}} {{in_array($media['name'],$template['social_media']) ? '' : 'd-none'}}" href="{{$media['link']}}" target="_blank">
                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/'.$media['name'].'.png') }}"
                        width="16" alt="">
                </a>
            @else
                <a class="{{$media['name']}}" href="{{$media['link']}}" target="_blank">
                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/'.$media['name'].'.png') }}"
                        width="16" alt="">
                </a>
            @endif
        @endforeach
    </div>
    <ul class="d-flex justify-content-center ps-3">
        <li class="view-copyright-text">
            {{$copyrightText}}
        </li>
    </ul>
</div>
