<div class="modal fade" id="chatting_modal" tabindex="-1" aria-labelledby="chattingModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-faded-info">
                <h6 class="modal-title text-capitalize" id="chattingModalLabel">
                    @if(isset($user_type) && $user_type == 'admin')
                        {{ translate('send_message_to') }} {{ getInHouseShopConfig(key:'name') }}
                    @elseif(isset($seller) && isset($user_type) && $user_type == 'seller')
                        {{ translate('send_message_to') }} {{ $seller->shop['name'] }}
                    @endif
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="{{route('messages')}}" method="post" id="seller-chat-form">
                    @csrf

                    <input value="{{ ($user_type ?? '') === 'admin' ? 0 : ($seller?->id ?? '') }}" name="vendor_id" hidden>
                    <textarea name="message" class="form-control min-height-100px max-height-200px" required placeholder="{{ translate('Write_here') }}..."></textarea>
                    <br>
                    <div class="justify-content-end gap-2 d-flex flex-wrap">
                        <a href="{{route('chat', ['type' => 'vendor'])}}" class="btn btn-sm btn-soft-primary bg-secondary border">
                            {{translate('Go_To_Chatbox')}}
                        </a>
                        <button class="btn btn-sm btn-primary text-white">{{translate('send')}}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('script')
<script>
    $("#seller-chat-form").on("submit", function (e) {
        e.preventDefault();
        $.ajaxSetup({ headers: { "X-CSRF-TOKEN": $('meta[name="_token"]').attr("content") } });
        $.ajax({
            type: "post",
            url: $("#route-messages-store").data("url"),
            data: $(this).serialize(),
            success: function () {
                toastr.success($("#message-send-successfully").data("text"), { CloseButton: true, ProgressBar: true });
                $("#seller-chat-form").trigger("reset");
                $("#chatting_modal").modal("hide");
            },
        });
    });
</script>
@endpush
