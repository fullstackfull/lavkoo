@foreach($filterParentCategories as $parentCategory)
    <div class="col-sm-6">
        <div class="d-flex gap-2">
            <input class="form-check-input checkbox--input cursor-pointer" type="checkbox"
                   name="categories[]"
                   {{ in_array($parentCategory['id'], (request('categories') ?? [])) ? "checked" : '' }}
                   @if(isset($oldCategories) && is_array($oldCategories))
                       {{ in_array($parentCategory['id'], $oldCategories) ? "checked" : '' }}
                   @endif
                   id="parent-category-{{ $parentCategory['id'] }}"
                   value="{{ $parentCategory['id'] }}">
            <label class="form-check-label fs-12 cursor-pointer"
                   for="parent-category-{{ $parentCategory['id'] }}">
                {{ $parentCategory['defaultname'] }}
            </label>
        </div>
    </div>
@endforeach

<input hidden name="old_categories" value="{{ json_encode(request('categories') ?? []) }}">
