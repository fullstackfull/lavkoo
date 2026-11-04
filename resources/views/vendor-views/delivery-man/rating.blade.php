@extends('layouts.vendor.app')

@section('title', translate('delivery_man_Review'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-10 mb-3">
            <div class="">
                <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/deliveryman.png')}}" width="20" alt="">
                    {{$deliveryMan['f_name']. ' '. $deliveryMan['l_name']}}
                </h2>
            </div>

            <div class="d-flex justify-content-end flex-wrap gap-10">
                <a href="{{route('vendor.delivery-man.list')}}" class="btn btn--primary">
                    <i class="tio-back-ui"></i> {{translate('back')}}
                </a>
            </div>
        </div>
        <div class="card">
            <div class="card-body my-3">
                <div class="row align-items-md-center gx-md-5">
                    <div class="col-md-auto mb-3 mb-md-0">
                        <div class="d-flex align-items-center">
                            <img class="avatar avatar-xxl avatar-4by3 {{Session::get('direction') === "rtl" ? 'ml-4' : 'mr-4'}}"
                                src="{{getStorageImages(path:$deliveryMan->image_full_url,type: 'backend-profile')}}"
                                alt="Image Description">
                            <div class="d-block">
                                <h4 class="display-2 text-dark mb-0">
                                    {{number_format($averageRatting, 2, '.', ' ')}}
                                </h4>
                                <p> {{translate('of')}} {{$reviews->count()?? 0}} {{translate('reviews')}}
                                    <span
                                        class="badge badge-soft-dark badge-pill {{Session::get('direction') === "rtl" ? 'mr-1' : 'ml-1'}}"></span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md">
                        <ul class="list-unstyled list-unstyled-py-2 mb-0">
                            <li class="d-flex align-items-center font-size-sm">
                                <span class="{{Session::get('direction') === "rtl" ? 'ml-3' : 'me-3'}}">{{'5'.' '.translate('star')}}</span>
                                <div class="progress flex-grow-1">
                                    <div class="progress-bar" role="progressbar"
                                         style="width: {{$total==0?0:($five/$total)*100}}%;"
                                         aria-valuenow="{{$total==0?0:($five/$total)*100}}"
                                         aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <span class="{{Session::get('direction') === "rtl" ? 'me-3' : 'ml-3'}}">{{$five}}</span>
                            </li>
                            <li class="d-flex align-items-center font-size-sm">
                                <span
                                    class="{{Session::get('direction') === "rtl" ? 'ml-3' : 'me-3'}}">{{translate('4')}} {{translate('star')}}</span>
                                <div class="progress flex-grow-1">
                                    <div class="progress-bar" role="progressbar"
                                         style="width: {{$total==0?0:($four/$total)*100}}%;"
                                         aria-valuenow="{{$total==0?0:($four/$total)*100}}"
                                         aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <span class="{{Session::get('direction') === "rtl" ? 'me-3' : 'ml-3'}}">{{$four}}</span>
                            </li>
                            <li class="d-flex align-items-center font-size-sm">
                                <span
                                    class="{{Session::get('direction') === "rtl" ? 'ml-3' : 'me-3'}}">{{translate('3')}} {{translate('star')}}</span>
                                <div class="progress flex-grow-1">
                                    <div class="progress-bar" role="progressbar"
                                         style="width: {{$total==0?0:($three/$total)*100}}%;"
                                         aria-valuenow="{{$total==0?0:($three/$total)*100}}"
                                         aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <span class="{{Session::get('direction') === "rtl" ? 'me-3' : 'ml-3'}}">{{$three}}</span>
                            </li>
                            <li class="d-flex align-items-center font-size-sm">
                                <span
                                    class="{{Session::get('direction') === "rtl" ? 'ml-3' : 'me-3'}}">{{translate('2')}} {{translate('star')}}</span>
                                <div class="progress flex-grow-1">
                                    <div class="progress-bar" role="progressbar"
                                         style="width: {{$total==0?0:($two/$total)*100}}%;"
                                         aria-valuenow="{{$total==0?0:($two/$total)*100}}"
                                         aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <span class="{{Session::get('direction') === "rtl" ? 'me-3' : 'ml-3'}}">{{$two}}</span>
                            </li>
                            <li class="d-flex align-items-center font-size-sm">
                                <span class="{{Session::get('direction') === "rtl" ? 'ml-3' : 'me-3'}}">{{translate('1')}} {{translate('star')}}</span>
                                <div class="progress flex-grow-1">
                                    <div class="progress-bar" role="progressbar"
                                         style="width: {{$total==0?0:($one/$total)*100}}%;"
                                         aria-valuenow="{{$total==0?0:($one/$total)*100}}"
                                         aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <span class="{{Session::get('direction') === "rtl" ? 'me-3' : 'ml-3'}}">{{$one}}</span>
                            </li>
                        </ul>
                    </div>

                </div>
            </div>
        </div>
        <div class="card card-body mt-3">
            <form action="{{ url()->current() }}" method="GET">
                <div class="row gy-3 align-items-end">
                    <div class="col-md-3">
                        <label for="from" class="title-color d-flex">{{ translate('from') }}</label>
                        <input type="date" name="from_date" id="from_date" value="{{ $filters['from'] }}"
                               class="form-control" title="{{ translate('from_date') }}">
                    </div>

                    <div class="col-md-3">
                        <label for="to_date" class="title-color d-flex">{{ translate('to') }}</label>
                        <input type="date" name="to_date" id="to_date" value="{{ $filters['to'] }}"
                               class="form-control" title="{{ ucfirst(translate('to_date')) }}">
                    </div>

                    <div class="col-md-3">
                        <select class="form-control" name="rating">
                            <option value="" selected>{{'--'.translate('select_Rating').'--'}}</option>
                            @for ($i = 1; $i <= 5; $i++)
                                <option value="{{ $i }}" {{ $filters['rating']==$i ? 'selected': '' }}>{{ $i }}</option>
                            @endfor
                        </select>
                    </div>

                    <div class="col-md-3">
                        <div class="d-flex gap-2">
                            <a href="{{ url()->current() }}"  class="btn btn--bordered flex-fill">
                                {{ translate('Reset') }}
                            </a>
                            <button type="submit" class="btn btn--primary flex-fill">
                                <i class="tio-filter-list"></i> {{ translate('Filter') }}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <x-k.data-view class="mt-3" :title="translate('reviews')" :count="$reviews->total()"
                       searchName="search" :searchValue="$searchValue"
                       :searchPlaceholder="translate('search_by_Order_ID')">
            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th>{{translate('order_ID')}}</th>
                    <th>{{translate('reviewer')}}</th>
                    <th>{{translate('review')}}</th>
                    <th>{{translate('date')}}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($reviews as $key=>$review)
                    <tr>
                        <td><span class="k-num">{{$reviews->firstItem()+$key}}</span></td>
                        <td>
                            <a class="title-color hover-c1" href="{{$review->order_id ? route('vendor.orders.details',$review['order_id']) : ''}}">{{ $review['order_id'] }}</a>
                        </td>
                        <td>
                            <div class="k-row">
                                <img src="{{getStorageImages(path: $review->customer->image_full_url,type: 'backend-profile')}}"
                                     alt="" width="36" height="36"
                                     style="border-radius:50%;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                <span style="min-inline-size:0">
                                    <span class="d-block title-color">{{$review->customer['f_name']." ".$review->customer['l_name']}}</span>
                                    <span class="k-text-subtle">{{$review->customer->email??""}}</span>
                                </span>
                            </div>
                        </td>
                        <td>
                            <div class="text-wrap">
                                <x-k.badge tone="info"><span class="k-num">{{$review->rating}}</span> <i class="tio-star"></i></x-k.badge>
                                <p class="mb-0 mt-1">{{$review['comment']}}</p>
                            </div>
                        </td>
                        <td>
                            <span class="k-num">{{date('d M Y H:i:s',strtotime($review['updated_at']))}}</span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($reviews)==0)
                <x-k.empty icon="sparkles" :title="translate('no_review_found')" />
            @endif

            @if ($reviews->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $reviews->firstItem() }}–{{ $reviews->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $reviews->total() }}</span>
                    </span>
                    <div>{!! $reviews->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>
@endsection
