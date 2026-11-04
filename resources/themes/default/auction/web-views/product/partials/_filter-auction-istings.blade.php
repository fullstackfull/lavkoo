@php($selectedListingTypes = (array) request('listing_types', []))
@if(empty($selectedListingTypes) && request('listing_type'))
    @php($selectedListingTypes = [request('listing_type')])
@endif
<div class="">
    <h6 class="fs-13 fw-semibold mb-15">{{ translate('Auction Listings') }}</h6>
    <div class="for-auction-listing webkit-scrolling-custom d-flex flex-column gap-20px p-10px">
        <div class="form-check">
            <input class="form-check-input form-check-input_theme auction-listing-filter" type="checkbox"
                   name="listing_types[]"
                   value="ending_soon" id="auction_listing_ending_soon"
                   data-listing-type="ending_soon"
                   {{ in_array('ending_soon', $selectedListingTypes, true) ? 'checked' : '' }}>
            <label class="form-check-label fs-13 title-clr" for="auction_listing_ending_soon">
                {{ translate('Ending_Soon') }}
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input form-check-input_theme auction-listing-filter" type="checkbox"
                   name="listing_types[]"
                   value="trending" id="auction_listing_trending"
                   data-listing-type="trending"
                   {{ in_array('trending', $selectedListingTypes, true) || in_array('trending_products', $selectedListingTypes, true) ? 'checked' : '' }}>
            <label class="form-check-label fs-13 title-clr" for="auction_listing_trending">
                {{ translate('Trending') }}
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input form-check-input_theme auction-listing-filter" type="checkbox"
                   name="listing_types[]"
                   value="live" id="auction_listing_live"
                   data-listing-type="live"
                   {{ in_array('live', $selectedListingTypes, true) ? 'checked' : '' }}>
            <label class="form-check-label fs-13 title-clr" for="auction_listing_live">
                {{ translate('Live') }}
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input form-check-input_theme auction-listing-filter" type="checkbox"
                   name="listing_types[]"
                   value="upcoming" id="auction_listing_upcoming"
                   data-listing-type="upcoming"
                   {{ in_array('upcoming', $selectedListingTypes, true) || in_array('upcoming_products', $selectedListingTypes, true) ? 'checked' : '' }}>
            <label class="form-check-label fs-13 title-clr" for="auction_listing_upcoming">
                {{ translate('Upcoming') }}
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input form-check-input_theme auction-listing-filter" type="checkbox"
                   name="listing_types[]"
                   value="no_bids_yet" id="auction_listing_no_bids_yet"
                   data-listing-type="no_bids_yet"
                   {{ in_array('no_bids_yet', $selectedListingTypes, true) ? 'checked' : '' }}>
            <label class="form-check-label fs-13 title-clr" for="auction_listing_no_bids_yet">
                {{ translate('No_Bids_Yet') }}
            </label>
        </div>
    </div>
</div>
