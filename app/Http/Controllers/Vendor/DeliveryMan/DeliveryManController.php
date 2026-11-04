<?php

namespace App\Http\Controllers\Vendor\DeliveryMan;

use App\Contracts\Repositories\DeliveryManRepositoryInterface;
use App\Contracts\Repositories\ReviewRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Enums\ExportFileNames\Admin\DeliveryMan as DeliveryManExport;
use App\Enums\ViewPaths\Vendor\Dashboard;
use App\Exports\DeliveryManListExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Vendor\DeliveryManRequest;
use App\Http\Requests\Vendor\DeliveryManUpdateRequest;
use App\Services\DeliveryManService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DeliveryManController extends BaseController
{
    /**
     * @param DeliveryManRepositoryInterface $deliveryManRepo
     * @param ReviewRepositoryInterface $reviewRepo
     * @param DeliveryManService $deliveryManService
     * @param VendorRepositoryInterface $vendorRepo
     */
    public function __construct(
        private readonly DeliveryManRepositoryInterface $deliveryManRepo,
        private readonly ReviewRepositoryInterface      $reviewRepo,
        private readonly DeliveryManService             $deliveryManService,
        private readonly VendorRepositoryInterface      $vendorRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View|Collection|LengthAwarePaginator|callable|RedirectResponse|null
     */
    public function index(?Request $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        return $this->getAddView();
    }

    /**
     * @return View
     * @function index is the starting point of a controller
     */
    public function getAddView(): View
    {
        $telephoneCodes = TELEPHONE_CODES;
        return view('vendor-views.delivery-man.index', compact('telephoneCodes'));
    }

    /**
     * @param DeliveryManRequest $request
     * @return JsonResponse
     * @function add  is the adding request data to delivery_men table
     */
    public function add(DeliveryManRequest $request): JsonResponse
    {
        $phone = str_replace(('+' . $request['country_code']), '', $request['phone']);
        $deliveryMan = $this->deliveryManRepo->getFirstWhere(params: ['phone' => $phone]);
        if ($deliveryMan) {
            return response()->json(['errors' => translate('this_phone_number_is_already_taken')]);
        }
        $this->deliveryManRepo->add($this->deliveryManService->getDeliveryManAddData(
            request: $request,
            addedBy: 'seller')
        );
        return response()->json(['message' => translate('delivery_man_added_successfully')]);
    }

    /**
     * @param Request $request
     * @return RedirectResponse|View
     * @function getList ,showing the deliveryMan list
     */
    public function getListView(Request $request): RedirectResponse|View
    {
        $searchValue = $request['search'];
        $relations = ['rating'];
        if ($request['sort_by'] == 'rating') {
            $relations = ['deliveredOrders', 'rating', 'review'];
        }
        $deliveryMen = $this->deliveryManRepo->getListWhere(
            searchValue: $searchValue,
            filters: ['seller_id' => auth('seller')->id(), 'sort_by' => $request['sort_by'] ?? 'latest'],
            relations: $relations,
            dataLimit: getWebConfig(name: 'pagination_limit'),
        );

        return view('vendor-views.delivery-man.list', compact('deliveryMen', 'searchValue'));
    }

    /**
     * @param string|int $id
     * @return RedirectResponse|View
     * @function updateView ,showing the deliveryMan update view
     */
    public function getUpdateView(string|int $id): RedirectResponse|View
    {
        $telephoneCodes = TELEPHONE_CODES;
        $deliveryMan = $this->deliveryManRepo->getFirstWhere(params: ['seller_id' => auth('seller')->id(), 'id' => $id]);
        return view('vendor-views.delivery-man.update-view', compact('deliveryMan', 'telephoneCodes'));
    }

    /**
     * @param DeliveryManUpdateRequest $request
     * @param string|int $id
     * @return JsonResponse
     * @function update ,update the deliveryMan data
     */
    public function update(DeliveryManUpdateRequest $request, string|int $id): JsonResponse
    {
        $deliveryMan = $this->deliveryManRepo->getFirstWhere(params:['seller_id' => auth('seller')->id(), 'id' => $id]);
        // Ownership guard: the write below keys off the raw $id, so a seller could otherwise edit
        // another seller's delivery man. Fail closed explicitly rather than relying on a null-deref.
        if (!$deliveryMan) {
            return response()->json(['errors' => translate('delivery_man_not_found')], 404);
        }
        $deliveryManExists = $this->deliveryManRepo->getFirstWhere(params:['phone' => $request['phone'], 'country_code' => $request['country_code']]);
        if (isset($deliveryManExists) && $deliveryManExists['id'] != $deliveryMan['id']) {
            return response()->json(['errors' => translate('this_phone_number_is_already_taken')]);
        }
        $this->deliveryManRepo->update($deliveryMan['id'], $this->deliveryManService->getDeliveryManUpdateData(
            request: $request,
            addedBy: 'seller',
            identityImages: $deliveryMan['identity_image'] ?? [],
            deliveryManImage: $deliveryMan['image'])
        );
        return response()->json(['message' => translate('delivery_man_updated_successfully')]);
    }

    /**
     * @param Request $request
     * @param string|int $id
     * @return JsonResponse
     * @function updateStatus ,update the deliveryMan status
     */
    public function updateStatus(Request $request, string|int $id): JsonResponse
    {
        $deliveryMan = $this->deliveryManRepo->getFirstWhere(params: ['seller_id' => auth('seller')->id(), 'id' => $id]);
        if (!$deliveryMan) {
            return response()->json([], 404);
        }
        $this->deliveryManRepo->update($deliveryMan['id'], data: ['is_active' => $request['status']]);
        return response()->json([], 200);
    }

    /**
     * @param string|int $id
     * @return RedirectResponse|View
     * @function delete ,update the deliveryMan data
     */
    public function delete(string|int $id): RedirectResponse|View
    {
        $deliveryMan = $this->deliveryManRepo->getFirstWhere(['seller_id' => auth('seller')->id(), 'id' => $id]);
        // Ownership guard: the delete below keyed off the raw $id, so a seller could delete another
        // seller's delivery man. Fail closed and scope the delete to this seller.
        if (!$deliveryMan) {
            ToastMagic::error(translate('delivery-man_not_found') . '!');
            return redirect()->back();
        }
        $this->deliveryManService->deleteImages(deliveryMan: $deliveryMan);
        $this->deliveryManRepo->delete(params: ['id' => $deliveryMan['id'], 'seller_id' => auth('seller')->id()]);
        ToastMagic::success(translate('delivery-man_removed') . '!');
        return redirect()->back();
    }

    /**
     * @param Request $request
     * @param string|int $id
     * @return RedirectResponse|View
     */
    public function getRatingView(Request $request, string|int $id): RedirectResponse|view
    {
        $deliveryMan = $this->deliveryManRepo->getFirstWhere(params: ['seller_id' => auth('seller')->id(), 'id' => $id]);
        if (!$deliveryMan) {
            ToastMagic::warning(translate('invalid_review') . '!');
            return redirect()->route('vendor.delivery-man.list');
        }
        $searchValue = $request['search'];
        $filters = [
            'delivery_man_id' => $id,
            'from' => $request['from_date'],
            'to' => $request['to_date'],
            'rating' => $request['rating'],
        ];

        $reviews = $this->reviewRepo->getListWhere(
            searchValue: $searchValue,
            filters: $filters,
            relations: ['order'],
            dataLimit: getWebConfig(name: 'pagination_limit'));

        $total = $reviews->total();
        $averageRatting = $reviews->avg('rating');
        $one = $reviews->where('rating', 1)->count();
        $two = $reviews->where('rating', 2)->count();
        $three = $reviews->where('rating', 3)->count();
        $four = $reviews->where('rating', 4)->count();
        $five = $reviews->where('rating', 5)->count();
        return view('vendor-views.delivery-man.rating', compact(
            'deliveryMan',
            'searchValue',
            'filters',
            'reviews',
            'averageRatting',
            'total',
            'one',
            'two',
            'three',
            'four',
            'five'
        ));

    }

    public function exportList(Request $request): BinaryFileResponse
    {
        $vendorId = auth('seller')->id();
        $vendor = $this->vendorRepo->getFirstWhere(params: ['id' => $vendorId]);
        $searchValue = $request['search'];
        $relations = [];
        if ($request['sort_by'] == 'rating') {
            $relations = ['deliveredOrders', 'rating', 'review'];
        }
        $deliveryMens = $this->deliveryManRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: $searchValue,
            filters: ['seller_id' => $vendorId, 'sort_by' => $request['sort_by'] ?? 'latest'],
            relations: $relations,
            dataLimit: getWebConfig(name: 'pagination_limit')
        );
        $active = $deliveryMens->where('is_active', 1)->count();
        $inactive = $deliveryMens->where('is_active', 0)->count();
        return Excel::download(new DeliveryManListExport([
            'data-from' => 'vendor',
            'vendor' => $vendor,
            'delivery_men' => $deliveryMens,
            'search' => $request['search'],
            'active' => $active,
            'inactive' => $inactive,
        ]), 'Delivery-Man-List.xlsx'
        );
    }
}
