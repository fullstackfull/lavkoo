@extends('layouts.admin.app')
@section('title', translate('inhouse_product_sale Report'))
@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/inhouse_sale.png')}}" alt="">
                {{translate('inhouse_sale')}}
            </h2>
        </div>

        {{-- The category filter submits itself; the table sits on the data-view. --}}
        <x-k.data-view :title="translate('inhouse_sale')" :count="$products->total()">

            <x-slot:actions>
                <form class="k-row" method="GET" action="{{ url()->current() }}">
                    <div class="select-wrapper">
                        <select class="form-control" name="category_id" onchange="this.form.submit()"
                                aria-label="{{ translate('category') }}">
                            <option value="all">{{ translate('all') }}</option>
                            @foreach($categories as $category)
                                <option value="{{$category['id'] }}" {{request('category_id')==$category['id']? 'selected': ''}}>
                                    {{$category['name'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </form>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('product_Name') }}</th>
                    <th class="k-table__num">{{ translate('total_Sale') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($products as $data)
                    <tr>
                        <td>
                            <a href="{{ route('admin.products.view', ['addedBy' => $data['added_by'] == 'seller' ? 'vendor' : 'in-house', 'id' => $data['id']]) }}"
                               class="k-truncate" style="display:block;max-inline-size:340px" title="{{ $data['name'] }}">
                                {{ $data['name'] }}
                            </a>
                        </td>
                        <td class="k-table__num"><span class="k-num">{{ $data->orderDelivered->sum('qty') }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($products)==0)
                <x-k.empty icon="catalog" :title="translate('no_product_found')" />
            @endif

            @if ($products->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $products->firstItem() }}–{{ $products->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $products->total() }}</span>
                    </span>
                    <div>{!! $products->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>
@endsection
