@extends('layouts.vendor.app')

@section('title', request()->route('type') == 'delivery-man' ? translate('Deliveryman_Chatting_List') : translate('Customer_Chatting_List'))

@push('css_or_js')
    <link rel="stylesheet" href="{{ dynamicAsset('public/assets/back-end/css/owl.min.css') }}"/>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset('public/assets/back-end/img/support-ticket.png')}}" alt="">
                {{translate('chatting_list')}}
            </h2>
        </div>

        <div class="row">
            <div class="col-xl-3 col-lg-4 chatSel">
                <div class="card card-body px-0 h-100 position-relative max-h-100vh-150px">
                    <div class="inbox_people">
                        <form class="search-form mb-4 px-3 px-md-20 w-100" id="chat-search-form">
                            <div class="search-input-group w-100">
                                <i class="tio-search search-icon" aria-hidden="true"></i>
                                <input id="myInput" type="text" class="w-100" aria-label="Search customers..."
                                       placeholder="{{ request('type') == 'customer' ? translate('search_customers') : translate('search_delivery_men')}}...">
                            </div>
                        </form>
                        <ul class="nav nav-tabs gap-1 border-0 mb-3 mx-4" id="pills-tab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <a class="nav-link bg-transparent p-2 {{ request('type') == 'customer' ? 'active' : '' }}"
                                   href="{{ route('vendor.messages.index', ['type' => 'customer']) }}">
                                    {{translate("customer")}}
                                </a>
                            </li>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link bg-transparent p-2 {{ request('type') == 'delivery-man' ? 'active' : '' }}"
                                   href="{{ route('vendor.messages.index', ['type' => 'delivery-man']) }}">
                                    {{translate('delivery_Man')}}
                                </a>
                            </li>
                        </ul>

                        <div class="tab-content max-h-100vh-300px overflow-y-auto">
                            <div class="tab-pane fade show active" id="customers" role="tabpanel"
                                 aria-labelledby="pills-home-tab">
                                <div class="inbox_chat d-flex flex-column">
                                    @if(isset($allChattingUsers) && count($allChattingUsers) > 0)
                                        @foreach($allChattingUsers as $key => $chatting)
                                            @if($chatting->user_id && $chatting->customer)
                                                <div class="list_filter">
                                                    <div
                                                        class="chat_list p-3 d-flex gap-2 {{ $key == 0 ? 'bg-soft-secondary' : '' }} get-ajax-message-view {{ $chatting->user_id == $lastChatUser->id ? 'active' : '' }}"
                                                        data-user-id="{{ $chatting->user_id }}">
                                                        <div class="chat_people media gap-10 w-100" id="chat_people">
                                                            <div class="chat_img avatar avatar-sm avatar-circle">
                                                                <img
                                                                    src="{{ getStorageImages(path:$chatting->customer->image_full_url,type: 'backend-profile') }}"
                                                                    id="{{$chatting->user_id}}"
                                                                    class="avatar-img avatar-circle" alt="">
                                                                <span
                                                                    class="avatar-status avatar-sm-status avatar-status-success"></span>
                                                            </div>
                                                            <div class="chat_ib media-body">
                                                                <h5 class="mb-1 seller d-flex justify-content-between gap-2 align-items-baseline {{ $key != 0 ?'font-weight-normal' :'' }}"
                                                                    id="{{ $chatting->user_id }}"
                                                                    data-name="{{ $chatting->customer->f_name.' '.$chatting->customer->l_name }}"
                                                                    data-phone="{{ $chatting->customer->phone }}">
                                                                    <span class="text-truncate" data-toggle="tooltip" data-placement="top" title="{{ $chatting->customer->f_name .' '. $chatting->customer->l_name }}">{{ $chatting->customer->f_name .' '. $chatting->customer->l_name }}</span>
                                                                    <span class="lead small flex-shrink-0 text-nowrap">{{ $chatting->created_at->diffForHumans() }}</span>
                                                                </h5>
                                                                <span
                                                                    class="mt-2 font-weight-normal text-muted d-block text-truncate"
                                                                    id="{{ $chatting->user_id }}"
                                                                    data-name="{{ $chatting->customer->f_name .' '. $chatting->customer->l_name}}"
                                                                    data-phone="{{ $chatting->customer->phone }}"
                                                                    data-toggle="tooltip" data-placement="top" title="{{ $chatting->customer->phone }}">
                                                                    {{ $chatting->customer->phone }}
                                                                </span>
                                                                <div
                                                                    class="d-flex gap-2 justify-content-between align-items-center">
                                                                    <p class="fs-12 line-1 mb-0">{{ $chatting?->message ?? 'Shared files' }}</p>
                                                                    @if(array_key_exists($chatting->user_id, $countUnreadMessages))
                                                                        <span
                                                                            id="count-unread-messages-{{ $chatting->user_id }}"
                                                                            class="bg-c1 text-white fs-12 lh-1 rounded-circle aspect-1 min-w-20px p-1 d-flex justify-content-center align-items-center flex-shrink-0">
                                                                            {{ $countUnreadMessages[$chatting->user_id] }}
                                                                        </span>
                                                                    @endif

                                                                </div>
                                                            </div>
                                                        </div>
                                                        @if(!$chatting->seen_by_seller && !($key == 0))
                                                            <div
                                                                class="message-status bg-danger notify-alert-{{ $chatting->user_id }}"></div>
                                                        @endif
                                                    </div>
                                                </div>
                                            @elseif($chatting->delivery_man_id && $chatting->deliveryMan)
                                                <div class="list_filter">
                                                    <div
                                                        class="chat_list p-3 d-flex gap-2 {{ $key == 0 ? 'bg-soft-secondary' : ''}} get-ajax-message-view {{ $chatting->delivery_man_id == $lastChatUser->id ? 'active' : '' }}"
                                                        data-user-id="{{ $chatting->delivery_man_id }}">
                                                        <div class="chat_people media gap-10 w-100" id="chat_people">
                                                            <div class="chat_img avatar avatar-sm avatar-circle">
                                                                <img
                                                                    src="{{ getStorageImages(path:$chatting->deliveryMan->image_full_url,type: 'backend-profile') }}"
                                                                    id="{{$chatting->user_id}}"
                                                                    class="avatar-img avatar-circle" alt="">
                                                                <span
                                                                    class="avatar-status avatar-sm-status avatar-status-success"></span>
                                                            </div>
                                                            <div class="chat_ib media-body">
                                                                <h5 class="mb-1 seller d-flex justify-content-between gap-2 align-items-baseline {{ $key != 0 ?'font-weight-normal' :'' }}"
                                                                    id="{{ $chatting->delivery_man_id }}"
                                                                    data-name="{{ $chatting->deliveryMan->f_name.' '.$chatting->deliveryMan->l_name }}"
                                                                    data-phone="{{ $chatting->deliveryMan->country_code.$chatting->deliveryMan->phone }}">
                                                                    <span class="text-truncate" data-toggle="tooltip" data-placement="top" title="{{ $chatting->deliveryMan->f_name.' '.$chatting->deliveryMan->l_name }}">{{ $chatting->deliveryMan->f_name.' '.$chatting->deliveryMan->l_name }}</span>
                                                                    <span class="lead small flex-shrink-0 text-nowrap">{{ $chatting->created_at->diffForHumans() }}</span>
                                                                </h5>
                                                                <span
                                                                    class="mt-2 font-weight-normal text-muted d-block text-truncate"
                                                                    id="{{ $chatting->delivery_man_id }}"
                                                                    data-name="{{ $chatting->deliveryMan->f_name .' '. $chatting->deliveryMan->l_name}}"
                                                                    data-phone="{{ $chatting->deliveryMan->country_code.$chatting->deliveryMan->phone }}"
                                                                    data-toggle="tooltip" data-placement="top" title="{{ $chatting->deliveryMan->country_code.$chatting->deliveryMan->phone }}">{{ $chatting->deliveryMan->country_code.$chatting->deliveryMan->phone }}
                                                                </span>
                                                                <div
                                                                    class="d-flex gap-2 justify-content-between align-items-center">
                                                                    <p class="fs-12 line-1 mb-0">{{ $chatting?->message ?? 'Shared files' }}</p>
                                                                    @if(array_key_exists($chatting->delivery_man_id, $countUnreadMessages))
                                                                        <span id="count-unread-messages-{{ $chatting->delivery_man_id }}"
                                                                            class="bg-c1 text-white fs-12 lh-1 rounded-circle aspect-1 min-w-20px p-1 d-flex justify-content-center align-items-center flex-shrink-0">
                                                                            {{ $countUnreadMessages[$chatting->delivery_man_id] }}
                                                                        </span>
                                                                    @endif

                                                                </div>
                                                            </div>
                                                        </div>
                                                        @if(!$chatting->seen_by_admin && !($key == 0))
                                                            <div
                                                                class="message-status bg-danger notify-alert-{{ $chatting->delivery_man_id }}"></div>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endif
                                        @endforeach
                                        <div class="justify-content-center align-items-center h-100 min-h-300 d-none empty-state-for-chatting-msg">
                                            <div class="d-flex flex-column align-items-center gap-3">
                                                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/empty-state-icon/no-customer-found.svg') }}"
                                                     alt="">
                                                <p>{{ request('type') == 'customer' ? translate('No_Customer_Found') : translate('No_Deliveryman_Found') }}</p>
                                            </div>
                                        </div>
                                    @else
                                        <div class="d-flex justify-content-center align-items-center h-100 min-h-300">
                                            <div class="d-flex flex-column align-items-center gap-3">
                                                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/empty-state-icon/no-customer-found.svg') }}"
                                                     alt="">
                                                <p>{{ request('type') == 'customer' ? translate('No_Customer_Found') : translate('No_Deliveryman_Found') }}</p>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <section class="col-xl-9 col-lg-8 mt-4 mt-lg-0">
                <div class="card card-body card-chat justify-content-center Chat pt-3 px-0 pb-0" id="">
                    @if(isset($lastChatUser))
                        <div
                            class="inbox_msg_header d-flex flex-wrap gap-3 justify-content-between align-items-center border px-3 py-2 rounded mb-4 mx-3">
                            <div class="media align-items-center gap-3">
                                <div class="avatar avatar-sm avatar-circle border">
                                    <img class="avatar-img user-avatar-image" id="profile_image"
                                         src="{{  getStorageImages(path: $lastChatUser->image_full_url,type: 'backend-profile')}}"
                                         alt="Image Description">
                                    <span class="avatar-status avatar-sm-status avatar-status-success"></span>
                                </div>
                                <div class="media-body">
                                    <h5 class="profile-name mb-1"
                                        id="profile_name">{{ $lastChatUser['f_name'].' '.$lastChatUser['l_name'] }}</h5>
                                    <span class="fs-12" id="profile_phone">
                                        <a href="tel:{{$lastChatUser['phone']}}" class="text-decoration-none text-muted">
                                            {{ $lastChatUser['country_code'] }} {{ $lastChatUser['phone'] }}
                                        </a>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div
                            class="card-body p-3 overflow-y-auto height-220 flex-grow-1 msg_history d-flex flex-column-reverse"
                            id="chatting-messages-section">
                            @include('vendor-views.chatting.messages', ['lastChatUser'=> $lastChatUser, 'chattingMessages'=>$chattingMessages])
                        </div>

                        <div class="type_msg">
                            <div class="input_msg_write">
                                <form class="mt-4 chatting-messages-ajax-form form-advance-validation form-advance-file-validation" enctype="multipart/form-data" novalidate="novalidate">
                                    @csrf
                                    <input type="hidden" id="current-user-hidden-id" value="{{ $lastChatUser->id }}"
                                           name="{{ $userType == 'customer' ? 'user_id' : 'delivery_man_id' }}">
                                    <div class="position-relative d-flex">
                                        <div class="d-flex align-items-center m-0 position-absolute top-3 px-3 gap-2">
                                            <label class="py-0 cursor-pointer">
                                                <img width="20" src="{{dynamicAsset('public/assets/back-end/img/chatting-image-icon.svg')}}" alt="">
                                                <input type="file" id="select-media"
                                                       class="h-100 position-absolute w-100 " hidden multiple
                                                       data-max-size="{{ getFileUploadMaxSize() }}"
                                                       accept=".jpg, .jpeg, .jpe, .jif, .jfif, .jfi, .png, .gif, .webp, .tiff, .tif, .bmp, .svg, .mp4, .mkv, .avi, .mov, .wmv, .flv, .webm, .mpeg, .mpg, .m4v, .3gp, .ogv">
                                            </label>
                                            <label class="py-0 cursor-pointer">
                                                <img width="20" src="{{dynamicAsset('public/assets/back-end/img/chatting-file-icon.svg')}}" alt="">
                                                <input type="file" id="select-file"
                                                       class="h-100 position-absolute w-100 " hidden multiple
                                                       data-max-size="{{ getFileUploadMaxSize(type: 'file') }}"
                                                       accept="{{ getFileUploadFormats(type: 'file', skip: '.txt') }}">
                                            </label>
                                            <label class="py-0 cursor-pointer" id="trigger">
                                                <img width="20" src="{{dynamicAsset('public/assets/back-end/img/chatting-emoji-icon.svg')}}" alt="">
                                            </label>
                                        </div>
                                        <label class="w-0 flex-grow-1 uploaded-file-container">
                                            <textarea class="form-control pt-3 radius-left-button pl-105px"
                                                      id="msgInputValue" name="message" type="text"
                                                      placeholder="{{translate('Write_Something...')}}"
                                                      aria-label="Search"></textarea>
                                            <div class="d-flex justify-content-between items-container">
                                                <div class="overflow-x-auto pt-3 pb-2">
                                                    <div>
                                                        <div class="d-flex gap-3">
                                                            <div class="d-flex gap-3 image-array"></div>
                                                            <div class="d-flex gap-3 file-array"></div>
                                                            <div class="d-flex gap-3 input-uploaded-file">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div id="selected-files-container"></div>
                                                    <div id="selected-media-container"></div>
                                                </div>
                                            </div>
                                        </label>
                                        <div
                                            class="d-flex align-items-center justify-content-center bg-F1F7FF radius-right-button">
                                            <button
                                                class="aSend bg-transparent outline-0 border-0 shadow-0 px-0 h-100 send-btn"
                                                type="submit" id="msgSendBtn">
                                                <img
                                                    src="{{dynamicAsset(path: 'public/assets/back-end/img/send-icon.png')}}"
                                                    alt="">
                                            </button>
                                        </div>
                                        <div class="circle-progress ms-auto collapse">
                                            <div class="inner">
                                                <div class="text"></div>
                                                <svg id="svg" width="24" height="24" viewPort="0 0 12 12" version="1.1"
                                                     xmlns="http://www.w3.org/2000/svg">
                                                    <circle id="bar" r="10" cx="12" cy="12" fill="transparent"
                                                            stroke-dasharray="100" stroke-dashoffset="100"></circle>
                                                </svg>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @else
                        <div class="d-flex justify-content-center align-items-center h-100">
                            <div class="d-flex flex-column align-items-center gap-3">
                                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/empty-message.png') }}"
                                     alt="">
                                <p>{{ translate('you_have_not_any_conversation_yet') }}</p>
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        </div>
        <span id="chatting-post-url"
              data-url="{{ Request::is('vendor/messages/index/customer') ? route('vendor.messages.message').'?user_id=' : route('vendor.messages.message').'?delivery_man_id=' }}"></span>
        <span id="image-url" data-url="{{ dynamicAsset('storage/app/public/chatting') }}"></span>
    </div>

    @include('vendor-views.chatting.partials._share-product-offcanvas')

    <span id="get-file-icon" data-default-icon="{{dynamicAsset("public/assets/back-end/img/default-icon.png")}}"
          data-word-icon="{{dynamicAsset("public/assets/back-end/img/default-icon.png")}}"></span>
    <span id="message-media-error" data-text="{{ translate('File_size_is_too_large') }} {{ translate('Please_upload_a_smaller_file') }}"></span>
    <span id="get-video-preview-icon"
          data-icon="{{ dynamicAsset('public/assets/back-end/img/icons/carbon_play-filled.svg') }}"></span>
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/vendor/chatting.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/picmo-emoji.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/emoji.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/select-multiple-file.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/select-multiple-image-for-message.js')}}"></script>
    <script src="{{ dynamicAsset('public/assets/back-end/js/owl.min.js') }}"></script>
@endpush
