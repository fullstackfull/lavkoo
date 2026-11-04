<div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-lg">
        <div class="modal-header border-0 pb-0 d-flex justify-content-between">
            <h3 class="modal-title product-title">{{translate('update_emergency_contact')}} </h3>
            <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                data-bs-dismiss="modal" aria-label="Close">
            </button>
        </div>
        <div class="modal-body px-4 px-sm-5 pt-0 mt-3">
            <form action="{{route('admin.delivery-man.emergency-contact.update',['id'=>$emergencyContact['id']])}}" method="post">
                @csrf
                <div class="row gy-4 mb-4">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label class="form-label d-flex" for="f_name">{{translate('contact_name')}} <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="{{translate('contact_name')}}" value="{{$emergencyContact['name']}}" required>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="form-group">
                            <label class="form-label d-flex" for="exampleFormControlInput1">{{translate('phone')}} <span class="text-danger">*</span></label>
                            <div class="input-group mb-3">
                                <input value="{{$emergencyContact['phone']}}" type="tel" name="phone" class="form-control" placeholder="{{translate('ex').':'.'017********'}}" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-3 justify-content-end">
                    <button type="submit" class="btn btn-primary px-4">{{translate('update')}}</button>
                </div>
            </form>

        </div>
    </div>
</div>
