<div>
    <h6 class="font-semibold fs-13 mb-2">{{ translate('filter') }}</h6>
    <label class="w-100 opacity-75 text-nowrap for-sorting d-block mb-0 ps-0" for="sorting">
        <select class="form-control product-list-filter-input" name="filter">
            <option value="">
                {{ translate('Default') }}
            </option>
            <option {{request('filter') ? "selected" : ""}} value="top-vendors">
                {{ translate('Best_Sellers') }}
            </option>
        </select>
    </label>
</div>
