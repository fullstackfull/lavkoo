{{-- The star row of <x-k.product-card>. All four legacy partials carried this
     loop verbatim; only the wrapper class differed, so it arrives as $class. --}}
@if ($overallRating[0] != 0)
    <div class="{{ $class }}">
        <span class="d-inline-block font-size-sm text-body">
            @for ($inc = 1; $inc <= 5; $inc++)
                @if ($inc <= (int) $overallRating[0])
                    <i class="tio-star text-warning"></i>
                @elseif ($overallRating[0] != 0 && $inc <= (int) $overallRating[0] + 1.1 && $overallRating[0] > ((int) $overallRating[0]))
                    <i class="tio-star-half text-warning"></i>
                @else
                    <i class="tio-star-outlined text-warning"></i>
                @endif
            @endfor
            <label class="badge-style">( {{ count($product->reviews) }} )</label>
        </span>
    </div>
@endif
