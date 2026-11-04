@extends("auction.layouts.auction-app")

@section('title', 'auction checkout')

@section('content')
<div class="container">

<div>
    <h3 class="fs-18 mb-3 fw-semibold title-clr">Shopping cart</h3>
    <div class="">
        <div class="row g-3">
            <div class="col-lg-8">
                <div class="w-100 mb-20 justify-content-between flex-wrap d-flex gap-2 align-items-end">
                    <div>
                        <h3 class="fs-18 fw-semibold d-flex align-items-center gap-10px title-clr text-capitalize mb-1">
                            Auction ID#564564
                            <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill">
                                Pending
                            </span>
                        </h3>
                        <span class="title-semidark fs-14">Claimed date : 08 jan 2025, 10:20 PM</span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary gap-5px d-center">
                            <i class="fi fi-rr-file-download fs-13"></i> Invoice
                        </button>
                    </div>
                </div>
                <div class="">
                    <div class="">
                        <div class="card bs-border rounded overflow-hidden shadow-sm mb-20">
                            <div class="cards-title fs-18 border-bottom d-flex align-items-center gap-5px fw-semibold light-box py-12px px-xxl-20 px-3">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/vallery-shop.png') }}" alt="" class="">
                                6valley
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table align-middle items-table table-borderless">
                                        <thead class="table-header-bg">
                                            <tr>
                                                <th><div class="fs-14 fw-semibold">Item info</div></th>
                                                <th><div class="fs-14 fw-semibold">Starting Price</div></th>
                                                <th><div class="fs-14 fw-semibold text-end">Final Bid Price</div></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-sm-center gap-2">
                                                        <a href="#0" class="bids-details-thumbs w-60px h-60px min-w-60px d-center position-relative z-1 rounded overflow-hidden">
                                                            <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/thumb.png') }}" alt="" class="w-100 h-100">
                                                        </a>
                                                        <div class="">
                                                            <h2 class="mb-2 fs-13 line--limit-2 title-clr fw-semibold">
                                                                {{ translate('3USB Head Phone') }}
                                                            </h2>
                                                            <div class="d-flex flex-column gap-2 auction-details-right-content">
                                                                <div class="d-flex align-items-center gap-5px">
                                                                    <div class="minmax-xs-100px fs-14 title-semidark lh-sm">
                                                                        {{ translate('Category') }}
                                                                    </div>
                                                                    <span class="title-semidark lh-1">:</span>
                                                                    <div class="info-option2">
                                                                        <h4 class="fs-12 m-0 title-clr fw-normal">Gadget</h4>
                                                                    </div>
                                                                </div>
                                                                <div class="d-flex align-items-center gap-5px">
                                                                    <div class="minmax-xs-100px fs-14 title-semidark lh-sm">
                                                                        {{ translate('Item Condition') }}
                                                                    </div>
                                                                    <span class="title-semidark lh-1">:</span>
                                                                    <div class="info-option2">
                                                                        <h4 class="fs-12 m-0 title-clr fw-normal">New Condition</h4>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fs-14 min-w-100px title-clr">
                                                        140.00$
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fs-14 min-w-100px fw-bold title-clr text-end">
                                                        1125.00$
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="mb-20">
                            <h3 class="fs-16 mb-10px fw-semibold title-clr">Shopping cart</h3>
                            <div class="form-group m-0">
                                <textarea class="form-control" name="claim" id="" placeholder="Type your note here" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="card bs-border mb-20">
                            <div class="card-header light-box border-0 p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0 fs-18 fw-bold">Shipping address</h5>
                                    <select class="form-select outline-0 shadow-none w-auto py-2 bg-white bs-border" style="font-size: 12px;">
                                        <option value="1">Saved Address</option>
                                        <option value="1">Check 1</option>
                                        <option value="1">Check 2</option>
                                    </select>
                                </div>
                            </div>
                            <div class="card-body">
                                <form>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label mb-2">Contact Person Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control min-h-40" placeholder="Type your user name">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label mb-2 d-block">Phone <span class="text-danger">*</span></label>
                                            <input id="phone" type="tel" class="form-control w-100 min-h-40" placeholder="Enter your number">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label mb-2">Address Type</label>
                                            <select class="form-select text-light-gray min-h-40 fs-13">
                                                <option>Select from dropdown</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label mb-2">Country <span class="text-danger">*</span></label>
                                            <select class="form-select text-light-gray min-h-40 fs-13">
                                                <option>Select from dropdown</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label mb-2">City <span class="text-danger">*</span></label>
                                            <select class="form-select text-light-gray min-h-40 fs-13">
                                                <option>Select from dropdown</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label mb-2">Zip Code <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" placeholder="Type your user name">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label mb-2">Address <span class="text-danger">*</span></label>
                                            <textarea class="form-control" rows="2" placeholder="Ex : House#38, Road#04, Demo City"></textarea>
                                        </div>
                                        <div class="col-12">
                                            <div class="map-container mt-2 overflow-hidden rounded">
                                                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3022.617543503295!2d-73.854291!3d40.725832!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zNDDCsDQzJzMzLjAiTiA3M8KwNTEnMTUuNCJX!5e0!3m2!1sen!2sus!4v1650000000000!5m2!1sen!2sus"
                                                    width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy"></iframe>
                                            </div>
                                        </div>
                                    </div>

                                </form>
                            </div>
                        </div>
                        <div class="">
                            <div class="bs-border shadow-sm box-light p-xxl-20px p-3 billing-address-section">
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <h5 class="mb-0 fs-18 fw-bold">Billing address</h5>
                                    <div class="form-check d-flex align-items-center bg-white py-2 px-10 rounded border">
                                        <label class="form-check-label fs-14 mb-0 pe-33px" for="sameAddress">Same As Shipping Adress</label>
                                        <input class="form-check-input form-check-input_theme" type="checkbox" id="sameAddress" checked>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="checkout-wrap-right">
                    <div class="d-flex flex-column gap-4">
                        <div class="p-xxl-20px p-3 card border-0 shadow-sm rounded">
                            <div class="mb-3 d-flex align-items-center justify-content-between gap-1">
                                <h4 class="fs-18 fw-semibold title-clr mb-0 line--limit-1">
                                    Payment Method
                                </h4>
                                <button type="button" class="btn p-0 text-primary fs-16 outline-0">
                                    Change
                                </button>
                            </div>
                            <div class="d-flex flex-column gap-15px">
                                <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="fs-14 title-semidark">
                                            {{ translate('Paid by Wallet ') }}
                                        </div>
                                        <span class="title-semidark">:</span>
                                    </div>
                                    <div class="info-option2">
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">$ 250.00</h4>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="fs-14 title-semidark">
                                            {{ translate('Cash on Delivery ( Due) ') }}
                                        </div>
                                        <span class="title-semidark">:</span>
                                    </div>
                                    <div class="info-option2">
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">$5.00</h4>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn outline-0 bg-light rounded cursor-pointer text-center fs-14 title-semidark p-3">
                                <i class="fi fi-sr-add text-primary mb-10px fs-20"></i>
                                Add Payment Method
                            </button>
                        </div>
                        <div class="p-xxl-20px p-3 card border-0 shadow-sm rounded">
                            <div class="d-flex flex-column gap-15px">
                                <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="fs-14 title-semidark">
                                            {{ translate('Sub total ') }}
                                        </div>
                                        <span class="title-semidark">:</span>
                                    </div>
                                    <div class="info-option2">
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">$100.00</h4>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="fs-14 title-semidark">
                                            {{ translate('Shipping Fee') }}
                                        </div>
                                        <span class="title-semidark">:</span>
                                    </div>
                                    <div class="info-option2">
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">$ 10.00</h4>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="fs-14 title-semidark">
                                            {{ translate('Tax') }}
                                        </div>
                                        <span class="title-semidark">:</span>
                                    </div>
                                    <div class="info-option2">
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">$ 50.00</h4>
                                    </div>
                                </div>
                                <div class="border-bottom"></div>
                                <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="fs-16 fw-semibold title-clr">
                                            {{ translate('Total') }}
                                        </div>
                                        <span class="title-semidark">:</span>
                                    </div>
                                    <div class="info-option2">
                                        <h4 class="fs-16 m-0 title-clr fw-semibold">$ 510.00</h4>
                                    </div>
                                </div>
                                <div class="fs-13 py-2 px-2 badge text-wrap text-start bg-warning title-semidark fw-normal bg-opacity-10 d-flex align-items-center gap-1">
                                    <i class="fi fi-sr-info text-warning"></i>
                                    Return Policy : Return accepted for 7 days
                                </div>
                                <button type="button" class="btn btn-primary w-100 mt-1">
                                    Proceed to Checkout
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<div class="offcanvas offcanvas-start" tabindex="-1" id="profile_aside_btn" aria-labelledby="profile_aside_btn">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title">{{ translate('Profile Information') }}</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <h5 class="fs-16 fw-semibold title-clr py-12px px-10px border-bottom mb-20">{{ translate('Filter_By') }}</h5>
    @include("auction.web-views.user-profile._profile-sidebar")
  </div>
</div>
@endsection


