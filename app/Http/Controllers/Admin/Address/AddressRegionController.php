<?php

namespace App\Http\Controllers\Admin\Address;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Address\AddressActiveRequest;
use App\Http\Requests\Admin\Address\AddressRegionRequest;
use App\Models\AddressRegion;
use App\Services\Address\AddressCatalog;
use App\Services\Address\AddressCatalogEditor;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** The regions: renamed in both languages, switched on or off. Never deleted: addresses, mappings and orders point at them by id. */
class AddressRegionController extends Controller
{
    public function __construct(private readonly AddressCatalog $catalog, private readonly AddressCatalogEditor $editor)
    {
    }

    public function index(): View
    {
        return view('admin-views.address.regions', [
            'regions' => $this->catalog->regions(onlyActive: false)->loadCount(['cities', 'cities as active_cities_count' => static fn ($query) => $query->where('is_active', true)]),
        ]);
    }

    public function update(AddressRegionRequest $request, int $region): RedirectResponse
    {
        $this->editor->renameRegion(AddressRegion::query()->findOrFail($region), nameAr: $request->validated('name_ar'), nameEn: $request->validated('name_en'));
        ToastMagic::success(translate('address_region_saved'));

        return back();
    }

    public function status(AddressActiveRequest $request, int $region): RedirectResponse
    {
        $this->editor->setRegionActive(AddressRegion::query()->findOrFail($region), active: $request->active());
        ToastMagic::success(translate('address_status_saved'));

        return back();
    }
}
