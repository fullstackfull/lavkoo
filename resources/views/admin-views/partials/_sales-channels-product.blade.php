{{-- "Sales channels" card of the in-house product forms: where this product stands on each channel. Read only; it calls no channel. Expects $product on edit; omit it on create. --}}
@php
    $channelRows = app(\App\Services\SalesChannels\Listings\ProductChannelPanel::class)->for(isset($product) ? (int) $product->id : null);
@endphp
@if ($channelRows !== [])
    <div class="card card-body mt-3 rest-part">
        <div>
            <h2 class="mb-1">{{ translate('sales_channels') }}</h2>
            <p class="fs-12 mb-3">{{ translate('product_channels_card_help') }}</p>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th>{{ translate('account') }}</th>
                    <th>{{ translate('status') }}</th>
                    <th>{{ translate('listing_key') }}</th>
                    <th>{{ translate('publishing') }}</th>
                    <th>{{ translate('last_published') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($channelRows as $row)
                    <tr>
                        <td>{{ $row['account']->name }}
                            <span class="d-block fs-12 text-muted">{{ translate('sales_channel_' . $row['account']->channel) }}</span>
                        </td>
                        <td>
                            {{ translate('product_channel_state_' . $row['state']) }}
                            @if ($row['problems'] !== [])
                                <span class="d-block fs-12 text-danger">{{ implode('; ', array_slice($row['problems'], 0, 3)) }}</span>
                            @elseif ($row['state'] === 'not_offered' && !$row['canCreate'])
                                <span class="d-block fs-12 text-muted">{{ translate('channel_cannot_create_listings') }}</span>
                            @endif
                        </td>
                        <td>{{ $row['listing']->listing_key ?? '—' }}</td>
                        <td>
                            @if ($row['listing'])
                                {{ $row['listing']->inventory_publish ? translate('stock') : '' }}
                                {{ $row['listing']->price_publish ? translate('price') : '' }}
                                @unless ($row['listing']->inventory_publish || $row['listing']->price_publish)
                                    <span class="fs-12 text-muted">{{ translate('nothing_is_published_yet') }}</span>
                                @endunless
                            @else
                                —
                            @endif
                        </td>
                        <td class="fs-12">{{ $row['listing']?->last_published_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="fs-12 text-muted mt-3 mb-0">{{ translate('product_channels_card_footnote') }}</p>
    </div>
@endif
