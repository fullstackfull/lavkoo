<ul class="list-group list-group-flush">
    @forelse($products as $product)
        <li class="list-group-item px-0 overflow-hidden">
            <button type="button" class="auction-search-result-item btn p-0 m-0 align-items-center text-start w-100 d-flex gap-2"
                    data-product-name="{{ $product->name }}">
                <span><i class="fi fi-rr-search"></i></span>
                <div class="text-truncate">{{ $product->name }}</div>
                <span class="px-2 ms-auto">
                    <i class="fi fi-rr-arrow-up-left"></i>
                </span>
            </button>
        </li>
    @empty
        <li class="list-group-item px-0 text-center text-muted">{{ translate('No_results_found') }}</li>
    @endforelse
</ul>
