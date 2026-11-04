{{-- Facet counts come from the manifest, so a filter that would return nothing is never offered.
     A dropdown with twenty options and eighteen dead ends is worse than one with two. --}}
<form class="dev-filters" method="GET" action="{{ url()->current() }}">
    <div class="dev-filters__search">
        <i class="tio-search"></i>
        <input type="search" name="search" value="{{ $data['filters']['search'] ?? '' }}"
               placeholder="{{ translate('search_paths_names_and_summaries') }}" autocomplete="off">
    </div>

    @foreach (['method' => 'method', 'audience' => 'audience', 'version' => 'version', 'group' => 'group'] as $field => $label)
        @if (count($data['facets'][$field] ?? []) > 1)
            <select name="{{ $field }}" class="dev-filters__select" onchange="this.form.submit()">
                <option value="">{{ translate('all') }} {{ translate($label) }}</option>
                @foreach ($data['facets'][$field] as $value => $count)
                    <option value="{{ $value }}" @selected(($data['filters'][$field] ?? null) === (string) $value)>
                        {{ translate((string) $value) }} ({{ $count }})
                    </option>
                @endforeach
            </select>
        @endif
    @endforeach

    <select name="auth" class="dev-filters__select" onchange="this.form.submit()">
        <option value="">{{ translate('any_authentication') }}</option>
        <option value="required" @selected(($data['filters']['auth'] ?? null) === 'required')>{{ translate('authenticated') }}</option>
        <option value="public" @selected(($data['filters']['auth'] ?? null) === 'public')>{{ translate('public') }}</option>
    </select>

    <button type="submit" class="k-btn k-btn--sm">{{ translate('filter') }}</button>
    @if (!empty(array_filter($data['filters'] ?? [])))
        <a class="k-btn k-btn--ghost k-btn--sm" href="{{ url()->current() }}">{{ translate('clear') }}</a>
    @endif
</form>
