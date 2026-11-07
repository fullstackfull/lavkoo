{{-- The "whose books" field of a filter form. Expects $sellers (list<int>) and $selected (?int, null for every seller). --}}
@use('App\Http\Controllers\Admin\OperationalCosts\Filters\SellerScope')
<label class="form-label fs-12" for="filter-seller">{{ translate('books_of') }}</label>
<select class="form-select form-select-sm" id="filter-seller" name="seller">
    @foreach (array_values(array_unique([...$sellers, ...($selected === null ? [] : [$selected])])) as $sellerOption)
        <option value="{{ $sellerOption }}" @selected($selected === $sellerOption)>{{ SellerScope::label($sellerOption) }}</option>
    @endforeach
    <option value="{{ SellerScope::ALL }}" @selected($selected === null)>{{ translate('all_sellers') }}</option>
</select>
