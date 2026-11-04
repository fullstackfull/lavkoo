<div class="modal fade" id="approveModal-{{ $refund['id'] }}">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="{{route('vendor.refund.update-status')}}" method="post"
                  id="submit-approve-form-{{$refund['id']}}">
                @csrf
                <div class="modal-body">
                    <input type="hidden" name="id" value="{{ $refund->id}}">
                    <input type="hidden" name="refund_status" value="approved">
                    <div class="text-center ">
                        <img class="mb-3"
                             src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/refund-approve.png') }}"
                             alt="{{ translate('refund_approve') }}">
                        <h4 class="mb-4 mx-auto max-w-283 text-capitalize">
                            {{ translate('Approval_Note') }}
                        </h4>
                    </div>
                    <textarea class="form-control text-area-max-min"
                              placeholder="{{ translate('please_write_the_approve_reason').'...'}}"
                              name="approved_note" rows="3"></textarea>
                    <div class="d-flex flex-wrap justify-content-end gap-3 mt-3">
                        <button type="button" class="btn btn-secondary px-3"
                                data-dismiss="modal">{{ translate('close') }}</button>
                        <button type="button" class="btn btn--primary form-submit" data-form-id="submit-approve-form-{{$refund['id']}}"
                                data-message="{{ translate('want_to_approve_this_refund_request').'?'}}"
                                data-redirect-route="">{{ translate('submit') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
