@extends('layouts.front-end.app')

@section('title', translate('payment_failed'))

@section('content')
    <div class="container py-5 my-4">
        <div class="row justify-content-center">
            <div class="col-lg-6 col-md-8 text-center">
                <div class="card border-0 shadow-sm rounded-10 p-4 p-md-5">
                    <div class="mb-3 fs-1 text-danger">
                        <i class="czi-close-circle"></i>
                    </div>
                    <h1 class="h3 mb-2">{{ translate('payment_failed') }}</h1>
                    <p class="text-muted mb-4">
                        {{ translate('the_payment_did_not_complete_and_no_order_was_placed') }}.
                        {{ translate('your_cart_is_untouched_so_you_can_try_again_or_pick_a_different_payment_method') }}.
                    </p>
                    <div class="d-flex flex-column gap-2">
                        <a href="{{ route('checkout-payment') }}" class="btn btn--primary btn-block py-2">
                            {{ translate('try_payment_again') }}
                        </a>
                        <a href="{{ route('shop-cart') }}" class="btn btn-outline-secondary btn-block py-2">
                            {{ translate('review_cart') }}
                        </a>
                        <a href="{{ route('home') }}" class="text-primary mt-2">
                            {{ translate('continue_Shopping') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
