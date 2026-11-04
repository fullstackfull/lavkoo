{{-- Four ways to enter the catalogue, because the right one depends on what the
     merchant sells: rings read as a pharmacy's departments, cards give the name
     room when it is long, tiles turn the category itself into the artwork, and
     chips fit twenty of them above the fold. --}}

@php
    $cats = $__resolver->categories(limit: (int) ($s['limit'] ?? 12), picked: $s['category_ids'] ?? null);
    $catStyle = $s['style'] ?? 'circles';
@endphp
@if ($cats->isNotEmpty())
    <div class="ml-sec-head ml-reveal">
        <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('shop_by_category') }}</span>
        @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
        <div class="ml-rule"></div>
    </div>
    <div class="{{ $catStyle === 'chips' ? 'ml-cat-chips' : 'ml-grid' }} ml-cats--{{ $catStyle }}">
        @foreach ($cats as $cat)
            @php $catIcon = category_icon_url($cat); @endphp
            @if ($catStyle === 'chips')
                <a href="{{ route('products', ['category_id' => $cat->id]) }}" class="ml-chip ml-reveal" data-delay="{{ $loop->index % 6 }}">
                    @if ($catIcon)<img src="{{ $catIcon }}" alt="" loading="lazy">@endif
                    <span>{{ $cat->name }}</span>
                </a>
            @elseif ($catStyle === 'tiles')
                <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                    <a href="{{ route('products', ['category_id' => $cat->id]) }}" class="ml-cat-tile">
                        <img src="{{ $catIcon ?: $__placeholder }}" alt="{{ $cat->name }}" loading="lazy">
                        <span class="ml-cat-tile__veil"></span>
                        <span class="ml-cat-tile__name">{{ $cat->name }}</span>
                    </a>
                </div>
            @elseif ($catStyle === 'cards')
                <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                    <a href="{{ route('products', ['category_id' => $cat->id]) }}" class="ml-cat-card">
                        <span class="ml-cat-card__art {{ $catIcon ? '' : 'is-letter' }}">
                            @if ($catIcon)
                                <img src="{{ $catIcon }}" alt="{{ $cat->name }}" loading="lazy">
                            @else
                                <span aria-hidden="true">{{ mb_substr(trim((string) $cat->name), 0, 1) }}</span>
                            @endif
                        </span>
                        <span class="ml-cat-card__name">{{ $cat->name }}</span>
                        <span class="ml-cat-card__go" aria-hidden="true">&rsaquo;</span>
                    </a>
                </div>
            @else
                <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                    <a href="{{ route('products', ['category_id' => $cat->id]) }}" class="ml-cat">
                        <span class="ml-cat-ring {{ $catIcon ? '' : 'is-letter' }}">
                            @if ($catIcon)
                                <img src="{{ $catIcon }}" alt="{{ $cat->name }}" loading="lazy">
                            @else
                                <span aria-hidden="true">{{ mb_substr(trim((string) $cat->name), 0, 1) }}</span>
                            @endif
                        </span>
                        <span class="ml-name ml-cat-name">{{ $cat->name }}</span>
                    </a>
                </div>
            @endif
        @endforeach
    </div>
@endif
