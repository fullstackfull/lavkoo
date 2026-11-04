@extends('layouts.admin.app')

@section('title', 'Delivery Syria Settings')

@section('content')
<div class="content container-fluid">

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col-sm mb-2 mb-sm-0">
                <h1 class="page-header-title">
                    Delivery Syria Settings
                </h1>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <div class="card-body">

            <form
                action="{{ route('admin.delivery-syria.settings.update') }}"
                method="POST"
            >
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label>
                        Delivery Syria Webhook Token
                    </label>

                    <input
                        type="password"
                        name="delivery_syria_webhook_token"
                        class="form-control"
                        value="{{ old(
                            'delivery_syria_webhook_token',
                            $setting->value ?? ''
                        ) }}"
                        required
                    >

                    <small class="text-muted">
                        This token will be used of DELIVERY_SYRIA_WEBHOOK_TOKEN 
                    </small>
                    <hr>
                        <small class="text-muted">
               this is your WEBHOOK link
                      </small>
                     <br>
                      <small class="text-muted">
            https://pharmacysyria.com/api/v2/delivery-syria/orders/update-status
                      </small>
                      <br>
                      
                        <small class="text-muted">
                    X-platform:  Pharmacy
                       </small>
                </div>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Save Token
                </button>

            </form>

        </div>
    </div>

</div>
@endsection