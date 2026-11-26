@php
    use App\Services\Catalog\BulkImportCountry;
    use App\Services\Storefront\ProductCountry;
@endphp
{{-- Drawn only while a product's country is tracked, so the import screen of today is byte-identical (B-08). The list is already narrowed
     to the countries the signed-in employee works with, which is what lets an employee of several countries import by naming one. --}}
@if (ProductCountry::isTracked() && ProductCountry::options() !== [])
    <div class="form-group mb-20">
        <div class="row justify-content-center">
            <div class="max-w-500">
                <label class="form-label" for="bulk-import-country">{{ translate('the_country_these_products_are_imported_into') }}</label>
                <div class="select-wrapper">
                    <select class="form-select" name="{{ BulkImportCountry::FIELD }}" id="bulk-import-country">
                        @foreach (ProductCountry::options() as $country)
                            <option value="{{ $country }}" {{ old(BulkImportCountry::FIELD, ProductCountry::defaultCountry()) === $country ? 'selected' : '' }}>
                                {{ ProductCountry::label(country: $country) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>
@endif
