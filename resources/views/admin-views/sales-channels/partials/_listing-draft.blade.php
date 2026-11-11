{{-- One channel account's row: where this product stands there, the channel's own fields, and the four things a person can do. Expects $row, $productId, $variantKey, $labels. --}}
@php
    $account = $row['account'];
    $draft = $row['draft'];
    $listing = $row['listing'];
    $readyToSend = $row['readyToSend'];
    $waiting = $row['waiting'];
    $draftBag = $errors->getBag('draft_' . $account->id);
    $clearanceBag = $errors->getBag('clearance_' . ($draft->id ?? 0));
    $publishBag = $errors->getBag('publish_' . ($draft->id ?? 0));
@endphp
<x-k.card class="mb-3">
    <x-slot:title>{{ $account->name }} <span class="fs-12 text-muted">{{ translate('sales_channel_' . $account->channel) }}</span></x-slot:title>
    <x-slot:actions>
        <x-k.badge :tone="$listing ? 'success' : ($readyToSend ? 'info' : 'neutral')">{{ $labels->state($row['state']) }}</x-k.badge>
        <x-k.badge :tone="$row['writeState']->tone()" :dot="false">{{ translate($row['writeState']->labelKey()) }}</x-k.badge>
    </x-slot:actions>

    @unless ($row['canCreate'])
        <div class="alert alert-secondary fs-12 py-2" role="note">{{ translate('channel_cannot_create_listings') }}</div>
    @endunless

    <dl class="row mb-3 fs-12">
        <dt class="col-sm-3">{{ translate('listing_key') }}</dt>
        <dd class="col-sm-9">{{ $listing->listing_key ?? ($row['sku'] ?: '—') }}</dd>

        <dt class="col-sm-3">{{ translate('what_the_channel_said') }}</dt>
        <dd class="col-sm-9">{{ $draft?->channel_status ?: '—' }}
            @if ($draft?->submission_id)<span class="text-muted">· {{ translate('submission') }} {{ $draft->submission_id }}</span>@endif
            @if ($draft?->checked_at)<span class="text-muted">· {{ translate('last_checked') }} {{ $draft->checked_at->format('Y-m-d H:i') }}</span>@endif
        </dd>

        @if (($draft?->channel_listing_statuses ?? []) !== [])
            <dt class="col-sm-3">{{ translate('channel_listing_statuses') }}</dt>
            <dd class="col-sm-9">{{ implode(', ', array_map('strval', $draft->channel_listing_statuses)) }}</dd>
        @endif

        @if (($draft?->withheld_attributes ?? []) !== [])
            <dt class="col-sm-3">{{ translate('withheld_from_the_channel') }}</dt>
            <dd class="col-sm-9">{{ implode(', ', array_map('strval', $draft->withheld_attributes)) }}
                <div class="text-muted">{{ translate('no_offer_is_ever_sent_from_here') }}</div>
            </dd>
        @endif

        @if (($draft?->submitted_payload ?? []) !== [])
            <dt class="col-sm-3">{{ translate('sent_and_not_yet_confirmed') }}</dt>
            <dd class="col-sm-9">{{ implode(', ', array_keys($draft->submitted_payload)) }}</dd>
        @endif

        @if (($draft?->published_payload ?? []) !== [])
            <dt class="col-sm-3">{{ translate('the_channel_confirms_it_holds') }}</dt>
            <dd class="col-sm-9">{{ implode(', ', array_keys($draft->published_payload)) }}</dd>
        @endif
    </dl>

    @if ($draft?->refusal_code)
        <div class="alert alert-warning fs-12 py-2" role="alert">{{ translate('nothing_was_sent_and_here_is_why') }}: {{ $labels->refusal($draft->refusal_code) }}</div>
    @endif

    @if ($row['problems'] !== [])
        <div class="alert alert-warning fs-12 py-2" role="alert">
            <span class="d-block fw-bold">{{ translate('what_is_still_missing_or_wrong') }}</span>
            <ul class="mb-0 ps-3">
                @foreach (array_slice($row['problems'], 0, 12) as $problem)
                    <li>{{ $labels->problem($problem) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.sales-channels.listings.draft', ['product' => $productId, 'account' => $account->id]) }}" class="d-flex flex-column gap-2">@csrf
        <input type="hidden" name="variant_key" value="{{ $variantKey }}">
        <p class="fs-12 text-muted mb-0">{{ translate('saving_the_draft_sends_nothing') }}</p>
        <div class="row g-2">
            <div class="col-md-6">
                <label class="form-label fs-12 mb-0" for="product-type-{{ $account->id }}">{{ translate('the_channels_product_type') }}</label>
                <input class="form-control form-control-sm" id="product-type-{{ $account->id }}" name="product_type" maxlength="191" value="{{ old('product_type', $draft->product_type ?? '') }}">
                <div class="fs-12 text-muted">{{ translate('the_channels_product_type_help') }}</div>
                @if ($draftBag->has('product_type'))<div class="text-danger fs-12" role="alert">{{ $draftBag->first('product_type') }}</div>@endif
            </div>
            <div class="col-md-6">
                @if ($row['requirements'] !== [])
                    <span class="form-label fs-12 mb-0 d-block">{{ translate('what_the_channel_says_it_requires') }}</span>
                    <div class="fs-12">{{ implode(', ', $row['requirements']) }}</div>
                    <div class="fs-12 text-muted">{{ $row['requirementsFromTheOwner'] ? translate('requirements_declared_by_the_owner') : translate('requirements_as_the_channel_gave_them') }}</div>
                @else
                    <div class="fs-12 text-muted">{{ translate('the_channel_has_not_been_asked_what_it_requires_yet') }}</div>
                @endif
            </div>
        </div>

        <div class="k-table-wrap table-responsive">
            <table class="k-table">
                <thead><tr><th>{{ translate('the_channels_field') }}</th><th>{{ translate('value') }}</th></tr></thead>
                <tbody>
                @foreach ($row['fields'] as $index => $field)
                    <tr>
                        <td style="max-width: 18rem">
                            <input class="form-control form-control-sm" name="fields[{{ $index }}][name]" maxlength="191" value="{{ $field['name'] }}"
                                   aria-label="{{ translate('the_channels_field') }}" @readonly($field['fixed'] && $field['name'] !== '')>
                            @if ($field['required'])<span class="fs-12 text-danger">{{ translate('required_by_the_channel') }}</span>@endif
                        </td>
                        <td>
                            <input class="form-control form-control-sm" name="fields[{{ $index }}][value]" maxlength="2000" value="{{ $field['value'] }}" aria-label="{{ translate('value') }}">
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($draftBag->isNotEmpty())<div class="text-danger fs-12" role="alert">{{ $draftBag->first() }}</div>@endif
        <div><button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('save_the_draft') }}</button></div>
    </form>

    @if ($draft !== null)
        <hr class="my-3">
        <div class="d-flex flex-wrap gap-2 align-items-start">
            <form method="POST" action="{{ route('admin.sales-channels.listings.check', $draft) }}">@csrf
                <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('check_it') }}</button>
                <div class="fs-12 text-muted">{{ translate('check_it_help') }}</div>
            </form>

            <form method="POST" action="{{ route('admin.sales-channels.listings.identify', $draft) }}">@csrf
                <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('ask_the_catalogue') }}</button>
                <div class="fs-12 text-muted">{{ translate('ask_the_catalogue_help') }}</div>
            </form>

            <form method="POST" action="{{ route('admin.sales-channels.listings.follow', $draft) }}">@csrf
                <button class="btn btn-outline-secondary btn-sm" type="submit" @disabled(!$waiting)>{{ translate('ask_the_channel_what_it_holds') }}</button>
                <div class="fs-12 text-muted">{{ $waiting ? translate('ask_the_channel_what_it_holds_help') : translate('nothing_is_waiting_for_the_channel') }}</div>
            </form>

            @unless ($row['withdrawn'])
                <form method="POST" action="{{ route('admin.sales-channels.listings.withdraw', $draft) }}" onsubmit="return confirm(@js(translate('withdraw_this_draft_confirm')))">@csrf
                    <button class="btn btn-outline-secondary btn-sm" type="submit">{{ translate('withdraw_the_draft') }}</button>
                </form>
            @endunless
        </div>

        @php($catalog = (array) ($draft->catalog_match ?? []))
        @if (($catalog['state'] ?? null) !== null)
            <hr class="my-3">
            <div class="fs-12 fw-bold mb-1">{{ translate('what_the_channels_catalogue_says') }}</div>

            @if ($catalog['state'] === 'unknown')
                {{-- The answer that must never be shown as an empty catalogue: it is why nobody may create a listing yet. --}}
                <div class="alert alert-warning py-2 px-3 fs-12 mb-2" role="alert">
                    <div class="fw-bold">{{ translate('the_channels_catalogue_could_not_be_asked') }}</div>
                    <div>{{ $labels->refusal('catalog_' . ($catalog['reason'] ?? '')) }}</div>
                </div>
            @elseif ($catalog['state'] === 'none')
                <div class="fs-12 text-muted mb-2">{{ translate('the_channels_catalogue_holds_nothing_for_this_barcode') }}</div>
            @else
                @if ($catalog['state'] === 'several')
                    <div class="alert alert-info py-2 px-3 fs-12 mb-2" role="alert">
                        <div class="fw-bold">{{ translate('the_channel_holds_more_than_one_product_with_this_barcode') }}</div>
                        <div>{{ translate('choose_the_right_one_before_sending_anything') }}</div>
                    </div>
                @endif
                <div class="d-flex flex-column gap-2">
                    @foreach ((array) ($catalog['candidates'] ?? []) as $candidate)
                        @php($chosen = (string) ($draft->catalog_external_id ?? '') === (string) ($candidate['external_id'] ?? ''))
                        <div class="d-flex flex-wrap align-items-center gap-2 border rounded p-2 {{ $chosen ? 'border-primary' : '' }}">
                            @if (!empty($candidate['image_url']))
                                <img src="{{ $candidate['image_url'] }}" alt="" width="40" height="40" class="rounded" loading="lazy">
                            @endif
                            <div class="flex-grow-1 fs-12">
                                <div class="fw-bold">{{ $candidate['title'] ?? $candidate['external_id'] }}</div>
                                <div class="text-muted">
                                    <span>{{ $candidate['external_id'] }}</span>
                                    @if (!empty($candidate['brand'])) · <span>{{ $candidate['brand'] }}</span> @endif
                                    {{-- The channel's own word for the kind of thing it is: shown as it came, never translated. --}}
                                    @if (!empty($candidate['product_type'])) · <code>{{ $candidate['product_type'] }}</code> @endif
                                </div>
                            </div>
                            @if ($chosen)
                                <span class="badge badge-soft-primary">{{ translate('this_one') }}</span>
                            @else
                                <form method="POST" action="{{ route('admin.sales-channels.listings.catalog-item', $draft) }}">@csrf
                                    <input type="hidden" name="external_id" value="{{ $candidate['external_id'] }}">
                                    <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('it_is_this_one') }}</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @endif

        @if ($row['needsClearance'])
            <hr class="my-3">
            <form method="POST" action="{{ route('admin.sales-channels.listings.clearance', $draft) }}" class="d-flex flex-column gap-2">@csrf
                <span class="fw-bold fs-12">{{ translate('this_is_a_regulated_product') }}</span>
                <p class="fs-12 text-muted mb-0">{{ translate('regulated_product_clearance_help') }}</p>
                @if ($draft->hasRecordedClearance())
                    <div class="fs-12">{{ translate('clearance_on_record') }}: <code>{{ $draft->regulatory_clearance['reference'] ?? '' }}</code>
                        <span class="text-muted">· {{ translate('recorded_by') }} #{{ $draft->regulatory_clearance['by'] ?? '' }} · {{ $draft->regulatory_clearance['at'] ?? '' }}</span></div>
                @endif
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label fs-12 mb-0" for="clearance-{{ $draft->id }}">{{ translate('licence_or_decision_reference') }}</label>
                        <input class="form-control form-control-sm" id="clearance-{{ $draft->id }}" name="reference" maxlength="190" value="">
                        @if ($clearanceBag->has('reference'))<div class="text-danger fs-12" role="alert">{{ $clearanceBag->first('reference') }}</div>@endif
                    </div>
                    <div class="col-md-6"><button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('record_the_clearance') }}</button></div>
                </div>
            </form>
        @endif

        <hr class="my-3">
        <form method="POST" action="{{ route('admin.sales-channels.listings.publish', $draft) }}" class="d-flex flex-column gap-2">@csrf
            <span class="fw-bold fs-12">{{ $listing ? translate('send_the_change_to_the_channel') : translate('send_it_to_the_channel') }}</span>
            <p class="fs-12 text-muted mb-0">{{ $listing ? translate('send_the_change_help') : translate('send_it_help') }}</p>
            @if ($row['writeState']->sendsForReal() && $publishingEnabled)
                @include('admin-views.sales-channels.partials._switch', [
                    'name' => 'confirm_live', 'id' => 'confirm-live-' . $draft->id,
                    'label' => translate('yes_send_this_to_the_channel_for_real'), 'checked' => false, 'help' => translate($row['writeState']->helpKey()),
                ])
                @if ($publishBag->has('confirm_live'))<div class="text-danger fs-12" role="alert">{{ $publishBag->first('confirm_live') }}</div>@endif
            @else
                <div class="fs-12 text-muted">{{ translate($row['writeState']->helpKey()) }}</div>
            @endif
            <div><button class="btn btn-primary btn-sm" type="submit" @disabled(!$readyToSend && !$waiting)>{{ translate('send_it') }}</button>
                @unless ($readyToSend)<span class="fs-12 text-muted ms-2">{{ translate('check_it_first') }}</span>@endunless
            </div>
        </form>
    @endif
</x-k.card>
