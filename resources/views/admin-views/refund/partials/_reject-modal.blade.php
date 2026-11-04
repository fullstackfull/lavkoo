<div class="modal fade" id="rejectModal-{{ $refund['id'] }}">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="{{ route('admin.refund-section.refund.refund-status-update') }}" method="post"
                  id="submit-rejected-form-{{$refund['id']}}">
                @csrf
                <div class="modal-body">
                    <input type="hidden" name="id" value="{{ $refund->id}}">
                    <input type="hidden" name="refund_status" value="rejected">
                    <div class="text-center">
                        <img class="mb-3"
                             src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/refund-reject.png') }}"
                             alt="{{ translate('refund_reject') }}">
                        <h4 class="mb-4 mx-auto max-w-283 text-capitalize">
                            {{ translate('rejection_note') }}
                        </h4>
                    </div>
                    <textarea class="form-control text-area-max-min"
                              placeholder="{{ translate('please_write_the_reject_reason').'...'}}"
                              name="rejected_note" rows="3"></textarea>
                    <div class="d-flex flex-wrap justify-content-end gap-3 mt-3">
                        <button type="button" class="btn btn-secondary px-3"
                                data-bs-dismiss="modal">{{ translate('close') }}</button>
                        <button type="button" class="btn btn-primary form-submit"
                                data-form-id="submit-rejected-form-{{$refund['id']}}"
                                data-message="{{ translate('want_to_reject_this_refund_request').'?'}}"
                                data-redirect-route="{{ route('admin.refund-section.refund.list',['status'=>$refund['status']]) }}">{{ translate('submit') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
