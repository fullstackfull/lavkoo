{{-- The pages of the address system. Expects $active: overview | regions | cities | legacy | import. --}}
<x-k.tabs class="mb-3" :items="[
    ['label' => translate('overview'), 'href' => route('admin.address-system.index'), 'active' => $active === 'overview'],
    ['label' => translate('address_regions'), 'href' => route('admin.address-system.regions.index'), 'active' => $active === 'regions'],
    ['label' => translate('address_cities'), 'href' => route('admin.address-system.cities.index'), 'active' => $active === 'cities'],
    ['label' => translate('address_legacy_title'), 'href' => route('admin.address-system.legacy.index'), 'active' => $active === 'legacy'],
    ['label' => translate('import'), 'href' => route('admin.address-system.import.index'), 'active' => $active === 'import'],
]" />
