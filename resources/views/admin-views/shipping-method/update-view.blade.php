@extends('layouts.admin.app')

@section('title', translate('shipping_method'))

@section('content')
<div class="content container-fluid">
    <div class="mb-3">
        <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
            <img src="{{dynamicAsset(path: 'public/assets/back-end/img/business-setup.png')}}" alt="">
            {{translate('shipping_method_update')}}
        </h2>
    </div>
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form action="{{route('admin.business-settings.shipping-method.update',[$method['id']])}}"
                          class="text-start"
                          method="post">
                        @csrf
                        <div class="form-group">
                            <div class="row ">
                                <div class="col-md-12">
                                    <label class="title-color" for="title">{{translate('title')}} <span class="text-danger">*</span></label>
                                    <input type="text" name="title" value="{{$method['title']}}" class="form-control" placeholder="{{translate('title')}}">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="row ">
                                <div class="col-md-12">
                                    <label class="title-color" for="duration">{{translate('Shipping_duration')}} <span class="text-danger">*</span></label>
                                    <input type="text" name="duration" value="{{$method['duration']}}"
                                           class="form-control"
                                           placeholder="{{translate('ex').' '.':'.' '.translate('4_to_6_days')}}">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="row ">
                                <div class="col-md-12">
                                    <label class="title-color" for="cost">{{translate('Shipping_cost')}} <span class="text-danger">*</span></label>
                                    <input type="number" min="0" max="1000000" name="cost"
                                           value="{{usdToDefaultCurrency(amount: $method['cost'])}}"
                                           class="form-control"
                                           placeholder="{{translate('ex').' '.':'.' '.translate('10')}}$">
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                            <div class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                                <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">
                                    {{ translate('reset') }}
                                </button>
                                <button type="submit" class="btn btn-primary px-3 px-sm-4">
                                    {{ translate('Update') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
