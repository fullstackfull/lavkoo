<?php

namespace App\Http\Controllers\Admin\Promotion;

use App\Contracts\Repositories\CouponRepositoryInterface;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Exports\CouponListExport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\CouponAddRequest;
use App\Http\Requests\Admin\CouponUpdateRequest;
use App\Services\CouponService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CouponController extends BaseController
{
    /**
     * @param CouponRepositoryInterface $couponRepo
     * @param CustomerRepositoryInterface $customerRepo
     * @param VendorRepositoryInterface $vendorRepo
     */
    public function __construct(
        private readonly CouponRepositoryInterface   $couponRepo,
        private readonly CustomerRepositoryInterface $customerRepo,
        private readonly VendorRepositoryInterface   $vendorRepo,
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
        return $this->getAddListView(request: $request);
    }

    public function getAddListView(Request $request): View
    {
        $filters = ['added_by' => 'admin'];
        if ($request->filled('status')) {
            $filters['status'] = (int) $request['status'];
        }
        $coupons = $this->couponRepo->getListWhere(searchValue: requestString('searchValue') ?: null, filters: $filters, dataLimit: getWebConfig(name: 'pagination_limit'));
        $customers = $this->customerRepo->getListWhereNotIn([0]);
        return view('admin-views.coupon.add-new', compact('coupons', 'customers'));
    }

    public function add(CouponAddRequest $request, CouponService $couponService): JsonResponse
    {
        try {
            if ($request['discount_type'] == 'amount' && $request['discount'] > $request['min_purchase']) {
                return response()->json([
                    'status' => 0,
                    'message' =>translate('the_minimum_purchase_amount_must_be_greater_than_discount_amount')
                ]);
            }
            $data = $couponService->getAddData(request: $request);
            $this->couponRepo->add(data: $data);
            return response()->json([
                'status' => 1,
                'redirect_url' => route('admin.coupon.add'),
                'message' => translate('coupon_added_successfully')
            ]);
        }catch (Exception $e){
            return response()->json([
                'status' => 0,
                'message' => translate($e->getMessage())
            ]);
        }
    }

    public function getUpdateView(string|int $id): View|RedirectResponse
    {
        $sellers = $this->vendorRepo->getListWhere(filters: ['status' => 'approved'], relations: ['shop'], dataLimit: 'all');
        $customers = $this->customerRepo->getListWhereNotIn([0]);
        $coupon = $this->couponRepo->getFirstWhere(['id' => $id]);
        if ($coupon) {
            return view('admin-views.coupon.edit', compact('coupon', 'customers', 'sellers'));
        }
        ToastMagic::error(translate('invalid_Coupon'));
        return redirect()->route('admin.coupon.add');
    }

    public function update(CouponUpdateRequest $request, string|int $id, CouponService $couponService): JsonResponse
    {
       try{
           if ($request['discount_type'] == 'amount' && $request['discount'] > $request['min_purchase']) {
               return response()->json([
                   'status' => 0,
                   'message' =>translate('the_minimum_purchase_amount_must_be_greater_than_discount_amount')
               ]);
           }
           $data = $couponService->getUpdateData(request: $request);
           $this->couponRepo->update(id: $id, data: $data);
           return response()->json([
               'status' => 1,
               'message' => translate('coupon_updated_successfully'),
               'redirect_url' => route('admin.coupon.add')
           ]);
       }catch (Exception $e){
           return response()->json([
               'status' => 0,
               'message' => translate($e->getMessage()),
           ]);
       }

    }

    public function updateStatus(Request $request): JsonResponse|RedirectResponse
    {
        $this->couponRepo->update(id: $request['id'], data: ['status' => $request->get('status', 0)]);
        if ($request->ajax()) {
            return response()->json([
                'status' => 1,
                'message' => translate('coupon_status_updated')
            ]);
        }
        ToastMagic::success(translate('coupon_status_updated'));
        return back();
    }

    /**
     * Enable or disable several coupons at once. Scoped to admin coupons the
     * same way delete() and quickView() are, so vendor coupons cannot be
     * flipped from this screen no matter what ids arrive.
     */
    public function bulkStatus(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'status' => 'required|in:0,1',
        ]);

        $updated = 0;
        foreach ($request['ids'] as $id) {
            $coupon = $this->couponRepo->getFirstWhere(params: ['id' => $id, 'added_by' => 'admin']);
            if ($coupon) {
                $this->couponRepo->update(id: $id, data: ['status' => (int) $request['status']]);
                $updated++;
            }
        }

        return response()->json([
            'status' => 1,
            'updated' => $updated,
            'message' => translate('coupon_status_updated'),
        ]);
    }

    public function quickView(Request $request): JsonResponse
    {
        $coupon = $this->couponRepo->getFirstWhere(params: ['id' => $request['id'], 'added_by' => 'admin']);
        return response()->json([
            'view' => view('admin-views.coupon.details-quick-view', compact('coupon'))->render(),
        ]);
    }

    public function delete(string|int $id): RedirectResponse
    {
        $this->couponRepo->delete(params: ['id' => $id, 'added_by' => 'admin']);
        ToastMagic::success(translate('Coupon_deleted_successfully'));
        return redirect()->back();
    }

    public function getVendorList(Request $request): JsonResponse
    {
        $sellers = $this->vendorRepo->getListWhere(filters: ['status' => 'approved'], relations: ['shop'], dataLimit: 'all');
        $output = '<option value="" disabled selected>' . translate('select_vendor') . '</option><option value="0">' . translate('all_vendor') . '</option>';
        $output .= $request['coupon_bearer'] == 'inhouse' ? '<option value="inhouse">' . getInHouseShopConfig(key:'name') . '</option>' : '';
        foreach ($sellers as $seller) {
            if ($seller?->shop) {
                $output .= '<option value="' . $seller->id . '">' . $seller->shop->name . '</option>';
            }
        }
        return response()->json($output);
    }

    public function exportList(Request $request): BinaryFileResponse
    {
        $coupons = $this->couponRepo->getListWhere(searchValue: requestString('searchValue') ?: null, filters: ['added_by' => 'admin'], dataLimit: 'all');
        return Excel::download(new CouponListExport([
            'coupon' => $coupons,
            'search' => $request['searchValue'],
        ]), 'Coupon-list.xlsx'
        );
    }

}
