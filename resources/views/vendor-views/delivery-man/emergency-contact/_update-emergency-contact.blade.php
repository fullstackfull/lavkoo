<div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-lg">
        <div class="modal-header border-0 pb-0 d-flex justify-content-between">
            <h4 class="modal-title product-title">{{translate('update_emergency_contact')}} </h4>
            <button type="button" class="btn-close border-0" data-dismiss="modal" aria-label="Close"><i class="tio-clear"></i></button>
        </div>
        <div class="modal-body px-4 px-sm-5 pt-0 mt-3">
            <form action="{{route('vendor.delivery-man.emergency-contact.update',['id'=>$emergencyContact['id']])}}" method="post">
                @csrf
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label class="title-color d-flex" for="f_name">{{translate('contact_name')}}</label>
                            <input type="text" name="name" class="form-control" placeholder="{{translate('contact_name')}}" value="{{$emergencyContact['name']}}" required>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="form-group">
                            <label class="title-color d-flex" for="exampleFormControlInput1">{{translate('phone')}}</label>
                            <div class="input-group mb-3">
                                <input class="form-control" type="tel" name="phone"
                                       value="{{ $emergencyContact['phone'] }}"
                                       placeholder="{{ translate('enter_phone_number') }}" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-3 justify-content-end">
                    <button type="submit" class="btn btn--primary px-4">{{translate('update')}}</button>
                </div>
            </form>
        </div>
    </div>
</div>
