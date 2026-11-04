@extends("auction.layouts.auction-app")

@section('content')
    <div class="container">
        <div class="d-lg-none d-block">
            <div class="w-100 bg-white shadow-sm rounded p-3 mb-3 justify-content-between d-flex gap-2 align-items-center">
                <h5 class="fs-18 fw-semibold title-clr text-capitalize m-0">{{ translate('Profile Info') }}</h5>
                <div>
                    <button type="button" class="btn btn-primary border-0 px-12px" data-bs-toggle="offcanvas"
                            data-bs-target="#profile_aside_btn">
                        <i class="fi fi-sr-apps"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="">
            <div class="row g-3">
                <div class="col-lg-3 d-lg-block d-none">
                    <div class="">
                        @include("auction.web-views.user-profile._profile-sidebar")
                    </div>
                </div>
                <div class="col-lg-9">
                    @yield('profile_content')
                </div>
            </div>
        </div>
    </div>

    <div class="offcanvas offcanvas-start" tabindex="-1" id="profile_aside_btn" aria-labelledby="profile_aside_btn">
        <div class="offcanvas-header pb-0">
            <h5 class="offcanvas-title">{{ translate('Profile Information') }}</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            @include("auction.web-views.user-profile._profile-sidebar")
        </div>
    </div>
@endsection

@push('script')

@endpush
