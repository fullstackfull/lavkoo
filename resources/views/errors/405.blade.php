@extends('errors::minimal')

@section('title', translate('Method_Not_Allowed'))

@section('message')
    <div class="container">
        <div class="row justify-content-center align-items-center vh-100">
            <div class="col-12">
                <div class="text-primary">
                    @include('errors.405-icon')
                </div>

                <h2 class="text-center pt-3">{{ translate('Method_Not_Allowed') }}</h2>

                <p class="text-center h4 lead py-2">
                    {{ translate('we_are_sorry') }}, {{ translate('the_request_method_is_not_allowed_for_this_url') }}
                    <br>
                    {{ translate('please_go_back_to_the_homepage') }}
                </p>
                <div class="text-center">
                    <a class="btn btn-primary font-weight-bold" href="{{ route('home') }}">
                        <span class="mr-2"><i class="fa fa-home" aria-hidden="true"></i></span>
                        {{ translate('home') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
