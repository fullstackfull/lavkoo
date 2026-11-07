<?php

namespace App\Http\Controllers\Admin\Address;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Address\AddressActiveRequest;
use App\Http\Requests\Admin\Address\AddressCityRequest;
use App\Models\AddressCity;
use App\Services\Address\AddressCatalog;
use App\Services\Address\AddressCatalogEditor;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The cities: found in Arabic or English, filtered by region and state, added, edited and switched on or off. Never deleted. */
class AddressCityController extends Controller
{
    private const PER_PAGE = 25;

    private const SEARCH_LIMIT = 100;

    public function __construct(private readonly AddressCatalog $catalog, private readonly AddressCatalogEditor $editor)
    {
    }

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'region_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        return view('admin-views.address.cities', [
            'cities' => filled($filters['q'] ?? null) ? $this->searched($filters) : $this->listed($filters),
            'regions' => $this->catalog->regions(onlyActive: false),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('admin-views.address.city-form', ['city' => new AddressCity(['is_active' => true]), 'regions' => $this->catalog->regions(onlyActive: false)]);
    }

    public function store(AddressCityRequest $request): RedirectResponse
    {
        $city = $this->editor->createCity($request->city());
        ToastMagic::success(translate('address_city_saved'));

        return redirect()->route('admin.address-system.cities.index', ['q' => $city->name_en, 'region_id' => $city->region_id]);
    }

    public function edit(int $city): View
    {
        return view('admin-views.address.city-form', ['city' => AddressCity::query()->findOrFail($city), 'regions' => $this->catalog->regions(onlyActive: false)]);
    }

    public function update(AddressCityRequest $request, int $city): RedirectResponse
    {
        $this->editor->updateCity(AddressCity::query()->findOrFail($city), $request->city());
        ToastMagic::success(translate('address_city_saved'));

        return redirect()->route('admin.address-system.cities.edit', $city);
    }

    public function status(AddressActiveRequest $request, int $city): RedirectResponse
    {
        $this->editor->setCityActive(AddressCity::query()->findOrFail($city), active: $request->active());
        ToastMagic::success(translate('address_status_saved'));

        return back();
    }

    /** @param array<string, mixed> $filters */
    private function listed(array $filters): LengthAwarePaginator
    {
        return AddressCity::query()
            ->with('region')
            ->when(filled($filters['region_id'] ?? null), static fn ($query) => $query->where('region_id', $filters['region_id']))
            ->when(filled($filters['status'] ?? null), static fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('region_id')
            ->orderByDesc('sort_order')
            ->orderBy('name_en')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * A search answers with the best matches (the same search the address forms use), so it is one page of at most SEARCH_LIMIT.
     *
     * @param array<string, mixed> $filters
     */
    private function searched(array $filters): LengthAwarePaginator
    {
        $found = $this->catalog
            ->searchCities(regionId: isset($filters['region_id']) ? (int) $filters['region_id'] : null, query: $filters['q'], limit: self::SEARCH_LIMIT, onlyActive: false)
            ->load('region')
            ->when(filled($filters['status'] ?? null), static fn ($cities) => $cities->filter(static fn (AddressCity $city): bool => $city->is_active === ($filters['status'] === 'active')))
            ->values();

        return new LengthAwarePaginator(items: $found, total: $found->count(), perPage: self::SEARCH_LIMIT, currentPage: 1, options: ['path' => url()->current()]);
    }
}
