<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\DeliveryCountryCodeRepositoryInterface;
use App\Contracts\Repositories\DeliveryZipCodeRepositoryInterface;
use App\Enums\ViewPaths\Admin\DeliveryRestriction;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\DeliveryCountryCodeAddRequest;
use App\Http\Requests\Admin\DeliveryZipCodeAddRequest;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class DeliveryRestrictionController extends BaseController
{

    public function __construct(
        private readonly BusinessSettingRepositoryInterface     $businessSettingRepo,
        private readonly DeliveryCountryCodeRepositoryInterface $deliveryCountryCodeRepo,
        private readonly DeliveryZipCodeRepositoryInterface     $deliveryZipCodeRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $countries = COUNTRIES;
        $storedCountries = $this->deliveryCountryCodeRepo->getListWhere(orderBy: ['id' => 'desc'], dataLimit: 'all');

        if (request('search_country')) {
            $searchCountry = request('search_country');
            $storedCountries = $storedCountries->filter(function ($country) use ($searchCountry, $countries) {
                foreach ($countries as $countryItem) {
                    if (stripos($countryItem['name'], $searchCountry) !== false && $country['country_code'] == $countryItem['code']) {
                        return true;
                    }
                }
                return false;
            })->sortByDesc('id')->values();
        }

        $offset = $request['offset'];
        $currentPage = $offset ?? Paginator::resolveCurrentPage('page');
        $totalSize = $storedCountries->count();
        $paginationLimit = (int)(getWebConfig(name: 'pagination_limit') ? getWebConfig(name: 'pagination_limit') : 20);
        $results = $storedCountries->forPage($currentPage, $paginationLimit);
        $storedCountries = new LengthAwarePaginator(items: $results, total: $totalSize, perPage: $paginationLimit, currentPage: $currentPage, options: [
            'path' => Paginator::resolveCurrentPath(),
            'appends' => $request->all(),
        ]);

        $countryRestrictionStatus = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'delivery_country_restriction']);
        $zipCodeAreaRestrictionStatus = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'delivery_zip_code_area_restriction']);
        $zipCodeFieldStatus = getWebConfig(name: 'zip_code_field_status');
        $storedCountryCode = $storedCountries->pluck('country_code')->toArray();
        $storedZip = $this->deliveryZipCodeRepo->getListWhere(orderBy: ['id' => 'desc'], searchValue: requestString('search_zip_code') ?: null, dataLimit: getWebConfig(name: 'pagination_limit'));
        return view('admin-views.business-settings.delivery-restriction', compact('countries', 'storedCountries', 'storedCountryCode', 'storedZip', 'countryRestrictionStatus', 'zipCodeAreaRestrictionStatus', 'zipCodeFieldStatus'));
    }

    public function add(DeliveryCountryCodeAddRequest $request): RedirectResponse
    {
        foreach ($request->input('country_code') as $code) {
            $this->deliveryCountryCodeRepo->add(data: ['country_code' => $code, 'created_at' => now()]);
        }
        ToastMagic::success(translate('delivery_country_added_successfully'));
        return back();
    }

    public function delete(Request $request): RedirectResponse
    {
        $this->deliveryCountryCodeRepo->delete(params: ['id' => $request['id']]);
        ToastMagic::success(translate('delivery_country_deleted_successfully'));
        return back();
    }

    public function addZipCode(DeliveryZipCodeAddRequest $request): RedirectResponse
    {
        $zipCodes = explode(',', $request['zipcode']);
        $existingZipCodes = $this->deliveryZipCodeRepo->getListWhere(dataLimit: 'all')->pluck('zipcode')->toArray();
        $zipCodes = array_diff($zipCodes, $existingZipCodes);
        if (!$zipCodes) {
            ToastMagic::warning(translate('delivery_zip_code_already_exists'));
            return back();
        }
        foreach ($zipCodes as $code) {
            $this->deliveryZipCodeRepo->add(data: ['zipcode' => $code]);
        }
        ToastMagic::success(translate('delivery_zip_code_added_successfully'));
        return back();
    }

    public function deleteZipCode(Request $request): RedirectResponse
    {
        $this->deliveryZipCodeRepo->delete(params: ['id' => $request['id']]);
        ToastMagic::success(translate('delivery_zip_code_deleted_successfully'));
        return back();
    }

    public function countryRestrictionStatusChange(Request $request): JsonResponse|RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(type: 'delivery_country_restriction', value: $request->get('status', 0));
        if ($request->ajax()) {
            return response()->json([
                'message' => translate('delivery_country_restriction_status_changed_successfully'),
                'status' => true
            ]);
        }
        return back();
    }

    /**
     * Show or hide the ZIP field on customer address forms.
     *
     * Separate from the delivery restriction above: that one limits WHERE you deliver, this one
     * decides whether customers are asked for a postcode at all. Off by default — many markets
     * (Syria among them) do not use postcodes, and a mandatory empty field only blocked checkout.
     */
    public function zipcodeFieldStatusChange(Request $request): JsonResponse|RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(type: 'zip_code_field_status', value: $request->get('status', 0));

        if ($request->ajax()) {
            return response()->json([
                'message' => translate('zip_code_field_status_changed_successfully'),
                'status' => true,
            ]);
        }

        ToastMagic::success(translate('zip_code_field_status_changed_successfully'));
        return back();
    }

    public function zipcodeRestrictionStatusChange(Request $request): JsonResponse|RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(type: 'delivery_zip_code_area_restriction', value: $request->get('status', 0));
        if ($request->ajax()) {
            return response()->json([
                'message' => translate('delivery_zip_code_restriction_status_changed_successfully'),
                'status' => true,
            ]);
        }
        clearWebConfigCacheKeys();
        return back();
    }

}
