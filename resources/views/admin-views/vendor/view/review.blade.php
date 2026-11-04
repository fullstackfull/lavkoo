@extends('layouts.admin.app')

@section('title', $seller?->shop->name ?? translate("shop_name_not_found"))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex gap-2 align-items-center">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/add-new-seller.png')}}" alt="">
                {{translate('vendor_details')}}
            </h2>
        </div>

        <div class="flex-between d-sm-flex row align-items-center justify-content-between mb-2 mx-1">
            <div>
                @if ($seller->status=="pending")
                    <div class="mt-4 pe-2">
                        <div class="flex-between">
                            <div class="mx-1"><h4><i class="fi fi-rr-shop"></i></h4></div>
                            <div><h4>{{translate('vendor_request_for_open_a_shop.')}}</h4></div>
                        </div>
                        <div class="text-center">
                            <form class="d-inline-block" action="{{route('admin.vendors.updateStatus')}}" method="POST">
                                @csrf
                                <input type="hidden" name="id" value="{{$seller->id}}">
                                <input type="hidden" name="status" value="approved">
                                <button type="submit" class="btn btn-primary btn-sm">{{translate('approve')}}</button>
                            </form>
                            <form class="d-inline-block" action="{{route('admin.vendors.updateStatus')}}" method="POST">
                                @csrf
                                <input type="hidden" name="id" value="{{$seller->id}}">
                                <input type="hidden" name="status" value="rejected">
                                <button type="submit" class="btn btn-danger btn-sm">{{translate('reject')}}</button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="page-header mb-4">
            <h2 class="page-header-title mb-3">{{ $seller?->shop->name ?? translate("shop_Name")." : ".translate("update_Please") }}</h2>

            <div class="position-relative nav--tab-wrapper">
                <ul class="nav nav-pills nav--tab">
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',$seller->id) }}">{{translate('shop')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'order']) }}">{{translate('order')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'product']) }}">{{translate('product')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller['id'], 'tab'=>'clearance_sale']) }}">{{translate('clearance_sale_products')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'setting']) }}">{{translate('setting')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'transaction']) }}">{{translate('transaction')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'review']) }}">{{translate('review')}}</a>
                    </li>
                </ul>
                <div class="nav--tab__prev">
                    <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                        <i class="fi fi-sr-angle-left"></i>
                    </button>
                </div>
                <div class="nav--tab__next">
                    <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                        <i class="fi fi-sr-angle-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between flex-wrap gap-3 align-items-center mb-4">
                    <h3 class="mb-0">
                        {{translate('Customer_Reviews_List')}}
                    </h3>

                    <div class="flex-grow-1 max-w-360">
                        <form action="{{ url()->current() }}" method="GET">
                            <div class="form-group">
                                <div class="input-group">
                                    <input id="datatableSearch_" type="search" name="searchValue" class="form-control"
                                           placeholder="{{ translate('search_by_Id, Product, Reviewer, Review') }}"
                                           aria-label="Search orders" value="{{ requestString('searchValue') }}" >
                                    <div class="input-group-append search-submit">
                                        <button type="submit">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('SL') }}</th>
                                <th>{{ translate('Review_ID') }}</th>
                                <th>{{ translate('product') }}</th>
                                <th>{{ translate('customer') }}</th>
                                <th>{{ translate('rating') }}</th>
                                <th>{{ translate('review') }}</th>
                                <th>{{ translate('Reply') }}</th>
                                <th>{{ translate('date') }}</th>
                                <th class="text-center">{{ translate('status') }}</th>
                                <th class="text-center">{{ translate('action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($reviews as $key => $review)

                            <?php
                                $isProductUnavailable = $review?->product === null;
                                $productDetails = $review?->product;
                                if ($isProductUnavailable) {
                                    $getOrderProduct = $review?->order?->details?->where('product_id', $review['product_id'])?->first()?->product_details ?? null;
                                    $productDetails = $getOrderProduct ? json_decode($getOrderProduct, true) : null;
                                }
                            ?>

                            <tr>
                                <td>
                                    {{ $reviews->firstItem()+$key }}
                                </td>
                                <td class="text-center">
                                    {{ $review->id }}
                                </td>
                                <td>
                                    @if(isset($review?->product))
                                        <a
                                            href="{{$review['product_id'] ? route('admin.products.view', ['addedBy'=>($review->product->added_by =='seller'?'vendor' : 'in-house'),'id'=>$review->product->id]) : 'javascript:'}}"
                                            class="media align-items-center gap-2">
                                            <img
                                                src="{{ getStorageImages(path: $review->product->thumbnail_full_url, type: 'backend-product') }}"
                                                class="avatar border object-fit-cover" alt="">
                                            <div>
                                                <div class="d-flex gap-2 align-items-center lh-1 w-max-content text-wrap line-1 max-w-300 min-w-130 text-dark text-hover-primary">
                                                    <div class="media-body text-dark line-1 text-hover-primary" data-bs-toggle="tooltip" title="{{ $review->product['name'] }}">
                                                       {{ Str::limit($review->product['name'], 25) }}
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    @else
                                        <a href="javascript:" class="media align-items-center gap-2">
                                            <img src="{{ getStorageImages(path: '', type: 'backend-product') }}"
                                                class="avatar border object-fit-cover" alt="">
                                            <div>
                                                <div class="d-flex gap-2 align-items-center lh-1 w-max-content text-wrap line-1 max-w-300 min-w-130 text-dark text-hover-primary">
                                                    <div class="media-body text-dark line-1 text-hover-primary"
                                                         data-bs-toggle="tooltip"
                                                        title="{{ translate('Product_has_been_deleted') }}">
                                                        {{ $productDetails ? Str::limit($productDetails['name'], 25) : translate('Product_has_been_deleted') }}
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    @endif
                                </td>
                                <td>
                                    @if ($review->customer)
                                        <a
                                            href="{{ route('admin.customer.view', [$review->customer_id]) }}"
                                            class="media align-items-center gap-2">
                                            <img
                                                src="{{getStorageImages(path:$review->customer->image_full_url,type:'backend-profile')}}"
                                                class="avatar border object-fit-cover rounded-circle" alt="">
                                            <div>
                                                <div class="d-flex gap-2 align-items-center lh-1 w-max-content text-wrap line-1 max-w-300 min-w-130 text-dark text-hover-primary">
                                                    <div class="media-body text-dark line-1 text-hover-primary">
                                                       {{ $review->customer->f_name . ' ' . $review->customer->l_name }}
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    @else
                                        <label class="badge badge-soft-danger">{{ translate('customer_removed') }}</label>
                                    @endif
                                </td>
                                <td>
                                    <label class="badge badge-info text-bg-info">
                                        <span class="fs-12 d-flex align-items-center gap-1">{{ $review->rating }}
                                            <i class="fi fi-sr-star"></i>
                                        </span>
                                    </label>
                                </td>
                                <td>
                                    <div class="gap-1">
                                        @php
                                            $comment = $review->comment;
                                        @endphp

                                        <div class="fs-12 mb-1"
                                             @if($comment && Str::length($comment) > 35)
                                                 data-bs-toggle="tooltip"
                                                 data-bs-placement="top"
                                                 title="{{ $comment }}"
                                            @endif
                                        >
                                            {{ $comment ? Str::limit($comment, 35) : translate('no_comment_found') }}
                                        </div>
                                        @if(count($review->attachment_full_url) > 0)
                                            <div class="d-flex flex-wrap gap-1 min-w-200">
                                                @foreach ($review->attachment_full_url as $img)
                                                    <a href="{{ $img['path'] }}"
                                                       data-lightbox="mygallery-{{ $review->id }}">
                                                        <img width="60" height="60"
                                                             class="aspect-1 rounded object-fit-cover"
                                                             src="{{ getStorageImages(path: $img, type: 'backend-basic') }}"
                                                             alt="{{translate('image')}}">
                                                    </a>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    @php
                                        $reply = $review?->reply?->reply_text;
                                    @endphp

                                    <div class="line-2 word-break"
                                         @if($reply && Str::length($reply) > 50)
                                            data-bs-toggle="tooltip"
                                            data-bs-placement="top"
                                            title="{{ $reply }}"
                                        @endif
                                    >
                                        {{ $reply ? Str::limit($reply, 50) : '-' }}
                                    </div>
                                </td>
                                <td>{{ date('d M Y', strtotime($review->updated_at)) }}</td>
                                <td>
                                    <form action="{{ route('admin.reviews.status') }}"
                                          method="post" id="reviews-status{{$review['id']}}-form"
                                          class="no-reload-form">
                                        <input type="hidden" name="id" value="{{$review['id']}}">
                                        <label class="switcher mx-auto" for="reviews-status{{$review['id']}}">
                                            <input
                                                class="switcher_input custom-modal-plugin"
                                                type="checkbox" value="1" name="status"
                                                id="reviews-status{{$review['id']}}"
                                                {{ $review->status ? 'checked' : '' }}
                                                data-modal-type="input-change-form"
                                                data-modal-form="#reviews-status{{$review['id']}}-form"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/customer-reviews-on.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/customer-reviews-off.png') }}"
                                                data-on-title = "{{translate('Want_to_Turn_ON_Customer_Reviews').'?'}}"
                                                data-off-title = "{{translate('Want_to_Turn_OFF_Customer_Reviews').'?'}}"
                                                data-on-message = "<p>{{translate('if_enabled_anyone_can_see_this_review_on_the_user_website_and_customer_app')}}</p>"
                                                data-off-message = "<p>{{translate('if_disabled_this_review_will_be_hidden_from_the_user_website_and_customer_app')}}</p>"
                                                data-on-button-text="{{ translate('turn_on') }}"
                                                data-off-button-text="{{ translate('turn_off') }}">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </form>
                                </td>
                                <td>
                                    <div class="d-flex gap-2 justify-content-center">
                                        <div data-bs-toggle="modal" data-bs-target="#review-view-for-{{ $review['id'] }}">
                                            <a class="btn btn-outline-info icon-btn" title="{{ translate('View') }}" data-bs-toggle="tooltip">
                                                <i class="fi fi-rr-eye"></i>
                                            </a>
                                        </div>

                                        @if(isset($review->product) && $review?->product?->added_by == 'admin')
                                            <div data-bs-toggle="modal" data-bs-target="#review-update-for-{{ $review['id'] }}">
                                                @if($review?->reply)
                                                    <a class="btn btn-outline-primary icon-btn" title="{{ translate('Update_Review') }}" data-bs-toggle="tooltip">
                                                        <i class="fi fi-rr-pencil"></i>
                                                    </a>
                                                @else
                                                    <div class="btn btn-outline-primary icon-btn" title="{{ translate('Review_Reply') }}" data-bs-toggle="tooltip">
                                                        <i class="fi fi-rr-reply-all"></i>
                                                    </div>
                                                @endif
                                            </div>
                                        @elseif($review?->product?->added_by == 'seller')
                                            <div>
                                                <a class="btn btn-outline-primary icon-btn" title="{{ translate('Admin_can_not_reply_to_vendor_product_review') }}" data-bs-toggle="tooltip">
                                                    @if($review?->reply)
                                                        <i class="fi fi-rr-pencil"></i>
                                                    @else
                                                    <i class="fi fi-rr-reply-all"></i>
                                                    @endif
                                                </a>
                                            </div>
                                        @else
                                            <div>
                                                <a class="btn btn-outline-primary icon-btn" title="{{ translate('product_not_found') }}" data-bs-toggle="tooltip">
                                                    @if($review?->reply)
                                                    <i class="fi fi-rr-pencil"></i>
                                                    @else
                                                    <i class="fi fi-rr-reply-all"></i>
                                                    @endif
                                                </a>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                @foreach($reviews as $key => $review)
                    @if(isset($review->customer))
                        <div class="modal fade" id="review-update-for-{{ $review['id'] }}" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header border-0 d-flex justify-content-end">
                                        <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none"
                                                style="--size: 2rem;" data-bs-dismiss="modal" aria-label="Close">
                                            <i class="fi fi-sr-cross"></i>
                                        </button>
                                    </div>
                                    <form method="POST" action="{{ route('admin.reviews.add-review-reply') }}">
                                        @csrf
                                        <div class="modal-body pt-0">
                                            <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                                                @if(isset($review->product))
                                                    <img src="{{ getStorageImages(path: $review->product->thumbnail_full_url, type: 'backend-product') }}"
                                                         width="60" class="rounded aspect-1 border" alt="{{ translate('Product') }}">
                                                    <div class="flex-grow-1">
                                                        <h5 class="mb-1 fs-14 text-dark">{{ $review->product['name'] }}</h5>
                                                        @if($review['order_id'])
                                                            <span class="fs-12 text-muted">{{ translate('Order_ID') }} #{{ $review['order_id'] }}</span>
                                                        @endif
                                                    </div>
                                                @else
                                                    <span class="text-dark">{{ translate('product_not_found') }}</span>
                                                @endif
                                            </div>

                                            <div class="bg-section p-3 rounded border mb-3">
                                                <div class="d-flex gap-2 align-items-center mb-2">
                                                    <img class="h-30 aspect-1 rounded-circle"
                                                         src="{{ getStorageImages(path: $review->customer?->image_full_url, type: 'backend-profile') }}"
                                                         alt="{{ translate('Customer') }}">
                                                    <span class="fs-14 fw-medium text-dark">
                                    {{ $review->customer?->f_name ?? translate('Customer') }}
                                </span>
                                                </div>
                                                <p class="mb-0 fs-14">{{ $review['comment'] ?? translate('No comment found') }}</p>
                                            </div>

                                            @if(count($review->attachment_full_url) > 0)
                                                <div class="d-flex flex-wrap gap-2 mb-3">
                                                    @foreach ($review->attachment_full_url as $img)
                                                        <a href="{{ getStorageImages(path: $img, type: 'backend-basic') }}"
                                                           data-lightbox="review-gallery-modal{{ $review['id'] }}">
                                                            <img width="45" class="rounded aspect-1 border"
                                                                 src="{{ getStorageImages(path: $img, type: 'backend-basic') }}"
                                                                 alt="{{ translate('review_image') }}">
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @endif

                                            <input type="hidden" name="review_id" value="{{ $review['id'] }}">
                                            <div class="form-group">
                                                <label class="form-label fw-bold">
                                                    {{ translate('Reply') }}
                                                </label>
                                                <textarea class="form-control text-area-max-min" rows="3" name="reply_text"
                                                          data-maxlength="250"
                                                          placeholder="{{ translate('Write_the_reply_of_the_product_review') }}...">{{ $review?->reply?->reply_text ?? '' }}</textarea>
                                                <div class="d-flex justify-content-end">
                                                    <span class="text-body-light">0/160</span>
                                                </div>
                                            </div>

                                            <div class="text-end mt-4">
                                                <button type="submit" class="btn btn-primary">
                                                    {{ $review?->reply?->reply_text ? translate('Update') : translate('submit') }}
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endif



                    @include("admin-views.reviews._review-modal", ['review' => $review])
                @endforeach

                <div class="table-responsive mt-4">
                    <div class="px-4 d-flex justify-content-lg-end">
                        {!! $reviews->links() !!}
                    </div>
                </div>
                @if(count($reviews)==0)
                    @include('layouts.admin.partials._empty-state',['text'=>'no_review_found'],['image'=>'default'])
                @endif
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/new/back-end/js/search-product.js')}}"></script>
@endpush
