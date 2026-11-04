<!-- Header -->
<div class="card-header">
    <h4 class="d-flex align-items-center text-capitalize gap-10 mb-0">
        <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/top-customers.png') }}" alt="">
        {{ translate('top_customer') }}
    </h4>
</div>
<div class="card-body">
    @if($top_customer)
        <div class="grid-card-wrap">
            @foreach($top_customer as $key => $item)
                @if(isset($item->customer))
                    <a href="{{ route('admin.customer.view', [$item['customer_id']]) }}">
                        <div class="border p-20 rounded text-center">
                            <div class="text-center mb-3">
                                <img width="50" class="aspect-1 rounded-circle" src="{{ getStorageImages(path: $item->customer->image_full_url, type:'backend-profile')}}" alt="">
                            </div>

                            <h4 class="mb-0">{{ $item->customer['f_name'] ?? translate('not_exist') }}</h4>

                            <div class="border orders-count d-inline-flex justify-content-center fs-12 gap-1 mt-2 px-2 py-1 rounded">
                                <div>{{ translate('orders') }} : </div>
                                <div class="fw-semibold">{{$item['count']}}</div>
                            </div>
                        </div>
                    </a>
                @endif
            @endforeach
        </div>
    @else
        <div class="text-center">
            <p class="text-muted">{{ translate('no_Top_Selling_Products') }}</p>
            <img class="w-75" src="{{ dynamicAsset(path: 'public/assets/back-end/img/no-data.png') }}" alt="">
        </div>
    @endif
</div>
