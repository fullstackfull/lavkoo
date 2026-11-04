<div class="card-header">
    <h4 class="d-flex align-items-center text-capitalize gap-10 mb-0">
        <i class="fi fi-sr-star text-warning"></i>
        {{translate('most_Popular_Products')}}
    </h4>
</div>

<div class="card-body">
    @if($mostRatedProducts)
        <div class="row">
            <div class="col-12">
                <div class="grid-card-wrap">
                    @foreach($mostRatedProducts as $key => $product)
                        @if(isset($product['id']))
                            <div class="border p-20 rounded get-view-by-onclick" data-link="{{ route('admin.products.view',['addedBy'=>($product['added_by']=='seller'?'vendor' : 'in-house'),'id'=>$product['id']]) }}">
                                <div class="d-flex align-items-center justify-content-center mb-3">
                                    <img width="70" class="border rounded aspect-1" src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'backend-product') }}" alt="{{$product->name}}{{translate('image')}}">
                                </div>
                                <div class="fs-12 text-center line-1">
                                    {{ isset($product['name']) ? $product->name : 'not exists'}}
                                </div>

                                <div class="d-flex justify-content-center align-items-center gap-1 flex-wrap fs-10">
                                    <span class="text-warning d-flex align-items-center fw-bold gap-1">
                                        <i class="fi fi-sr-star text-warning"></i>
                                        {{ round($product['ratings_average'],2) }}
                                    </span>
                                    <span class="d-flex align-items-center gap-10">
                                        ({{ $product['reviews_count']}} {{ translate('reviews') }})
                                    </span>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    @else
        <div class="text-center">
            <p class="text-muted">{{translate('no_Top_Selling_Products')}}</p>
            <img class="w-75" src="{{dynamicAsset(path: 'public/assets/back-end/img/no-data.png')}}" alt="">
        </div>
    @endif
</div>
