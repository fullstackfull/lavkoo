@extends('layouts.admin.app')

@section('title', translate('support_Ticket'))

@push('css_or_js')
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/owl-carousel/owl.carousel.min.css')}}"/>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/support_ticket.png') }}"
                    alt="">
                {{ translate('support_ticket') }}
            </h2>
        </div>

        <div class="card card-body card-chat justify-content-center Chat pt-3 px-0 pb-0">
            <div class="bg-section border-0 d-flex flex-wrap gap-3 justify-content-between align-items-center border px-3 py-2 rounded-8 mb-4 mx-3">
                @foreach ($supportTicket as $ticket)
                    <div class="media d-flex gap-2 align-items-center">
                        <img width="35" class="rounded-circle aspect-1"
                            src="{{ getStorageImages(path: isset($ticket->customer) ? $ticket->customer->image_full_url : '', type: 'backend-basic') }}"
                            alt="" />
                        <div class="media-body">
                            <h5 class="fs-14 mb-0">
                                {{ isset($ticket->customer) ? $ticket->customer['f_name'] . ' ' . $ticket->customer['l_name'] : translate('not_found') }}
                            </h5>
                            <div class="fs-12">{{ isset($ticket->customer) ? $ticket->customer['phone'] : '' }}</div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center flex-wrap gap-3">
                        <div class="type fw-bold badge text-bg-info badge-info">
                            {{ translate(str_replace('_', ' ', $ticket['type'])) }}</div>
                        <div class="priority d-flex flex-wrap align-items-center gap-3">
                            <span class="text-dark fs-12">{{ translate('priority') }}:</span>
                            <span
                                class="badge text-bg-info badge-info">{{ translate(str_replace('_', ' ', $ticket['priority'])) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="card-body p-3 overflow-y-auto h-220 flex-grow-1 msg_history d-flex flex-column-reverse support-ticket-messages"
                id="show_msg">
                @foreach ($ticket->conversations->reverse()->values() as $key => $message)
                    @if ($message['admin_id'])
                        <div class="outgoing_msg">
                            <div class="sent_msg w-100">
                                <div class="received_withdraw_msg">
                                    @if ($message->admin_message)
                                        <div class="d-flex justify-content-end">
                                            <p class="bg-F1F7FF text-dark rounded-10 px-3 py-2 mb-1 overflow-wrap-anywhere">
                                                {{ $message->admin_message }}
                                            </p>
                                        </div>
                                    @endif
                                    @if ($message['attachment'] != null && count($message->attachment_full_url) > 0)
                                        <div class="d-flex justify-content-end">
                                            <div class="d-flex gap-2 flex-wrap pt-1 justify-content-start max-w-150px">
                                                @foreach ($message->attachment_full_url as $index => $photo)
                                                   <div
                                                       class="position-relative img_row{{$index}} {{$index > 3 ? 'd-none' : ''}}" >
                                                       <a data-bs-toggle="modal"
                                                       data-bs-target="#imgViewModal{{ $message->id }}"
                                                       data-type=""
                                                       href="javascript:"
                                                       download
                                                       class="position-relative aspect-1 overflow-hidden d-block border rounded"
                                                       data-index="{{ $index }}">
                                                       <img class="img-fit aspect-1 w-65px" alt="" src="{{ getStorageImages(path: $photo, type: 'backend-basic') }}">
                                                           @if($index == 3 && count($message->attachment_full_url) > 4 )
                                                               <div class="extra-images">
                                                                   <span class="extra-image-count">
                                                                       +{{ count($message->attachment_full_url) - 3 }}
                                                                   </span>
                                                               </div>
                                                           @endif
                                                       </a>
                                                   </div>
                                               @endforeach
                                           </div>
                                        </div>

                                        @endif
                                </div>
                            </div>
                        </div>
                         @if ($message->admin_message || $message['attachment'] != null)
                            <span class="time_date fs-12 pb-3 d-flex justify-content-center">
                                {{ $message->created_at->diffForHumans() }}
                            </span>
                        @endif
                    @else
                        <div class="incoming_msg">
                            <div class="received_msg">
                                <div>
                                    <div class="d-flex justify-content-start">
                                        <div class="media d-flex gap-3 align-items-end">
                                            <img width="30" class="rounded-circle aspect-1 flex-shrink-0 mb-2"
                                            src="{{ getStorageImages(path: isset($ticket->customer) ? $ticket->customer->image_full_url : '', type: 'backend-basic') }}"
                                            alt="" />
                                            <div class="media-body">
                                                @if ($message->customer_message)
                                                <p class="bg-body text-dark rounded-10 px-3 py-2 mb-1 overflow-wrap-anywhere">
                                                    {{ $message->customer_message }}
                                                </p>
                                                @endif
                                                @if ($message['attachment'] != null && count($message->attachment_full_url) > 0)

                                                    <div class="d-flex justify-content-start">
                                                        <div class="d-flex gap-2 flex-wrap pt-1 max-w-150px">
                                                            @foreach ($message->attachment_full_url as $index => $photo)
                                                                <div
                                                                    class="position-relative img_row{{ $index }} {{$index > 3 ? 'd-none' : ''}}" >
                                                                    <a data-bs-toggle="modal"
                                                                    data-bs-target="#imgViewModal{{ $message->id }}"
                                                                    data-type=""
                                                                    href="javascript:"
                                                                    download
                                                                    class="position-relative aspect-1 overflow-hidden d-block border rounded"
                                                                    data-index="{{ $index }}">
                                                                    <img class="img-fit aspect-1 w-65px" alt="" src="{{ getStorageImages(path: $photo, type: 'backend-basic') }}">
                                                                        @if($index == 3 && count($message->attachment_full_url) > 4 )
                                                                            <div class="extra-images">
                                                                                <span class="extra-image-count">
                                                                                    +{{ count($message->attachment_full_url) - 3 }}
                                                                                </span>
                                                                            </div>
                                                                        @endif
                                                                    </a>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @if ($message->customer_message || $message['attachment'] != null)
                            <span class="time_date fs-12 d-flex justify-content-center pb-3">
                                {{ $message->created_at->diffForHumans() }}
                            </span>
                        @endif
                    @endif

                    <div class="modal fade imgViewModal" id="imgViewModal{{ $message->id }}" tabindex="-1" aria-labelledby="imgViewModal{{ $message->id }}Label" role="dialog" aria-modal="true">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content bg-transparent border-0">
                                <div class="modal-body pt-0">
                                    <div class="imgView-slider owl-theme owl-carousel" dir="ltr">
                                        @foreach ($message->attachment_full_url as $index => $photo)
                                            <div class="imgView-item">
                                                <div class="d-flex justify-content-between align-items-end">
                                                    <a href="{{ getStorageImages(path: $photo, type: 'backend-basic') }}"
                                                    class="d-flex align-items-center gap-2 mb-2"
                                                    download>
                                                        <div
                                                            class="btn btn--download icon-btn bg-white d-flex justify-content-center align-items-center"
                                                        >
                                                        <i class="fi fi-rr-download"></i>
                                                        </div>
                                                        <h5 class="text-white text-decoration-underline mb-0">
                                                            {{ translate('Download_Image') }}
                                                        </h5>
                                                    </a>
                                                    <button type="button" class="btn btn-close p-1 border-0 fs-10" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="image-wrapper">
                                                    <div class="position-relative">
                                                        <div class="image-wrapper">
                                                            <img class="image" alt=""
                                                                src="{{ getStorageImages(path: $photo, type: 'backend-basic') }}">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                    @if(count($message->attachment_full_url) > 1)
                                        <div class="imgView-slider_buttons d-flex justify-content-center" dir="ltr">
                                            <button type="button" class="btn owl-btn imgView-owl-prev">
                                                    <i class="fi fi-sr-angle-small-left"></i>
                                            </button>
                                            <button type="button" class="btn owl-btn imgView-owl-next">
                                                <i class="fi fi-sr-angle-small-right"></i>
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endForeach

                <div class="incoming_msg">
                    <div class="received_msg">
                        <div class="received_withdraw_msg">
                            <div class="d-flex justify-content-start">
                                <div class="media d-flex gap-3 align-items-end">
                                    <img width="30" class="rounded-circle aspect-1 flex-shrink-0 mb-2"
                                    src="{{ getStorageImages(path: isset($ticket->customer) ? $ticket->customer->image_full_url : '', type: 'backend-basic') }}"
                                    alt="" />
                                    <div class="media-body">
                                        @if ($ticket->description)
                                        <p class="bg-body text-dark rounded-10 px-3 py-2 mb-1 overflow-wrap-anywhere">
                                            {{ $ticket->description }}
                                        </p>
                                        @endif
                                        @if ($ticket['attachment'] != null && $ticket->attachment_full_url && count($ticket->attachment_full_url) > 0)
                                            <div class="d-flex justify-content-start">
                                                <div class="d-flex gap-2 flex-wrap pt-1 max-w-150px">
                                                    @foreach ($ticket->attachment_full_url as $index => $photo)
                                                        <div
                                                            class="position-relative img_row{{ $index }} {{$index > 3 ? 'd-none' : ''}}" >
                                                            <a data-bs-toggle="modal"
                                                            data-bs-target="#imgViewModal{{ $ticket->id }}"
                                                            data-type=""
                                                            href="javascript:"
                                                            download
                                                            class="position-relative aspect-1 overflow-hidden d-block border rounded"
                                                            data-index="{{ $index }}">
                                                            <img class="img-fit aspect-1 w-65px" alt="" src="{{ getStorageImages(path: $photo, type: 'backend-basic') }}">
                                                                @if($index == 3 && count($ticket->attachment_full_url) > 4 )
                                                                    <div class="extra-images">
                                                                        <span class="extra-image-count">
                                                                            +{{ count($ticket->attachment_full_url) - 3 }}
                                                                        </span>
                                                                    </div>
                                                                @endif
                                                            </a>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>

                                        @endif

                                        <div class="modal fade imgViewModal" id="imgViewModal{{ $ticket->id }}" tabindex="-1" aria-labelledby="imgViewModal{{ $ticket->id }}Label" role="dialog" aria-modal="true">
                                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                                <div class="modal-content bg-transparent border-0">
                                                    <div class="modal-body pt-0">
                                                        <div class="imgView-slider owl-theme owl-carousel" dir="ltr">
                                                            @foreach ($ticket->attachment_full_url as $index => $photo)
                                                                <div class="imgView-item">
                                                                    <div class="d-flex justify-content-between align-items-end">
                                                                        <a href="{{ getStorageImages(path: $photo, type: 'backend-basic') }}"
                                                                        class="d-flex align-items-center gap-2 mb-2"
                                                                        download>
                                                                            <div
                                                                                class="btn btn--download icon-btn bg-white d-flex justify-content-center align-items-center"
                                                                            >
                                                                            <i class="fi fi-rr-download"></i>
                                                                            </div>
                                                                            <h5 class="text-white text-decoration-underline mb-0">
                                                                                {{ translate('Download_Image') }}
                                                                            </h5>
                                                                        </a>
                                                                        <button type="button" class="btn btn-close p-1 border-0 fs-10" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                    </div>
                                                                    <div class="image-wrapper">
                                                                        <div class="position-relative">
                                                                            <div class="image-wrapper">
                                                                                <img class="image" alt=""
                                                                                    src="{{ getStorageImages(path: $photo, type: 'backend-basic') }}">
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                        @if(count($ticket->attachment_full_url) > 1)
                                                            <div class="imgView-slider_buttons d-flex justify-content-center" dir="ltr">
                                                                <button type="button" class="btn owl-btn imgView-owl-prev">
                                                                        <i class="fi fi-sr-angle-small-left"></i>
                                                                </button>
                                                                <button type="button" class="btn owl-btn imgView-owl-next">
                                                                    <i class="fi fi-sr-angle-small-right"></i>
                                                                </button>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <span class="time_date fs-12 pb-3 d-flex justify-content-center">
                    {{ $ticket->created_at->diffForHumans() }} </span>
            </div>
            <div class="type_msg">
                <div class="input_msg_write">
                    @foreach ($supportTicket as $reply)
                        <form class="mt-4 needs-validation form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" href="{{ route('admin.support-ticket.replay', $reply['id']) }}"
                            method="post" enctype="multipart/form-data" novalidate="novalidate">
                            @csrf
                            <input type="hidden" name="id" value="{{ $reply['id'] }}">
                            <input type="hidden" name="adminId" value="1">
                            <div class="position-relative d-flex">
                                <label
                                    class="d-flex align-items-center m-0 position-absolute top-3 px-3 gap-2">
                                    <img width="20" class="cursor-pointer" src="{{dynamicAsset('/public/assets/back-end/img/chatting-image-icon.svg')}}" alt="">
                                    <input type="file" id="select-media" class="h-100 position-absolute w-100 "
                                        hidden multiple accept="{{ getFileUploadFormats(skip: '.svg,.gif') }}">
                                </label>
                                <label class="w-0 flex-grow-1 uploaded-file-container">
                                    <textarea class="form-control resize-none pt-3 radius-left-button border-0 pl-60px" id="msgInputValue" name="replay" data-required-msg="{{ translate('type_something') }}"
                                        type="text" data-maxlength="189" placeholder="{{ translate('Send_a_Message...') }}" required></textarea>
                                    <div class="d-flex justify-content-between items-container">
                                        <div class="overflow-x-auto pt-3 pb-2">
                                            <div>
                                                <div class="d-flex gap-3">
                                                    <div class="d-flex gap-3 flex-wrap image-array"></div>
                                                    <div id="selected-media-container"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </label>
                                <div class="d-flex align-items-center justify-content-center bg-F1F7FF flex-shrink-0 radius-right-button">
                                    <button class="aSend btn bg-primary text-white w-50px h-100 outline-0 border-0 shadow-0 px-0 send-btn" type="submit" id="msgSendBtn" disabled>
                                        <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/send-icon.svg') }}" alt="">
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
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
        function updateCharCount() {
            const $input = $('#msgInputValue');
            const maxLength = $input.data('maxlength');
            let currentLength = $input.val().length;

            if (currentLength > maxLength) {
                $input.val($input.val().substring(0, maxLength));
                currentLength = maxLength;
            }

            $('#charCount').text(currentLength + '/' + maxLength);
        }
        $('#msgInputValue').on('input', updateCharCount);
        updateCharCount();

       $(document).ready(function () {

            function toggleSupportSendBtn() {
                const isEmpty = $('#msgInputValue').val().trim() === '';
                const isImageEmpty = $('.upload_img_box').length === 0;

                $('#msgSendBtn').prop('disabled', isEmpty && isImageEmpty);
            }

            $('#msgInputValue').on('input', toggleSupportSendBtn);

            const target = document.querySelector('.image-array');

            if (target) {
                const observer = new MutationObserver(function () {
                    toggleSupportSendBtn();
                });

                observer.observe(target, {
                    childList: true
                });
            }

            toggleSupportSendBtn();
        });

    </script>
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/owl-carousel/owl.carousel.min.js') }}"></script>
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/admin/chatting.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/select-multiple-image-for-message.js') }}"></script>
@endpush
