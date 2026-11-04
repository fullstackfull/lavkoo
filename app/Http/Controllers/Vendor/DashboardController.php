<?php

namespace App\Http\Controllers\Vendor;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\DeliveryManRepositoryInterface;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\VendorWalletRepositoryInterface;
use App\Contracts\Repositories\VendorWithdrawMethodInfoRepositoryInterface;
use App\Contracts\Repositories\WithdrawalMethodRepositoryInterface;
use App\Contracts\Repositories\WithdrawRequestRepositoryInterface;
use App\Contracts\Repositories\RestockProductRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Vendor\WithdrawRequest;
use App\Repositories\BrandRepository;
use App\Repositories\OrderTransactionRepository;
use App\Services\DashboardService;
use App\Services\VendorWalletService;
use App\Services\WithdrawRequestService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use App\Services\Vendor\VendorDashboardStatsService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Auction\app\Services\AuctionVendorWalletStatsService;

class DashboardController extends BaseController
{
    public function __construct(
        private readonly OrderTransactionRepository                  $orderTransactionRepo,
        private readonly ProductRepositoryInterface                  $productRepo,
        private readonly DeliveryManRepositoryInterface              $deliveryManRepo,
        private readonly OrderRepositoryInterface                    $orderRepo,
        private readonly CustomerRepositoryInterface                 $customerRepo,
        private readonly BrandRepository                             $brandRepo,
        private readonly VendorWalletRepositoryInterface             $vendorWalletRepo,
        private readonly VendorWalletService                         $vendorWalletService,
        private readonly WithdrawalMethodRepositoryInterface         $withdrawalMethodRepo,
        private readonly WithdrawRequestRepositoryInterface          $withdrawRequestRepo,
        private readonly WithdrawRequestService                      $withdrawRequestService,
        private readonly DashboardService                            $dashboardService,
        private readonly RestockProductRepositoryInterface           $restockProductRepo,
        private readonly VendorWithdrawMethodInfoRepositoryInterface $vendorWithdrawMethodInfoRepo,
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
        $vendorId = auth('seller')->id();
        $topSell = $this->productRepo->getTopSellList(
            filters: [
                'added_by' => 'seller',
                'seller_id' => $vendorId,
                'request_status' => 1
            ],
            relations: ['orderDetails', 'refundRequest']
        )->take(DASHBOARD_TOP_SELL_DATA_LIMIT);
        $topRatedProducts = $this->productRepo->getTopRatedList(
            filters: [
                'user_id' => $vendorId,
                'added_by' => 'seller',
                'request_status' => 1
            ],
            relations: ['reviews'],
        )->take(DASHBOARD_DATA_LIMIT);
        $topRatedDeliveryMan = $this->deliveryManRepo->getTopRatedList(
            orderBy: ['delivered_orders_count' => 'desc'],
            filters: [
                'seller_id' => $vendorId
            ],
            whereHasFilters: [
                'seller_is' => 'seller',
                'seller_id' => $vendorId
            ],
            relations: ['deliveredOrders', 'rating', 'review'],
        )->take(DASHBOARD_DATA_LIMIT);

        $from = now()->startOfYear()->format('Y-m-d');
        $to = now()->endOfYear()->format('Y-m-d');
        $range = range(1, 12);
        $vendorEarning = $this->getVendorEarning(from: $from, to: $to, range: $range, type: 'month');
        $commissionEarn = $this->getAdminCommission(from: $from, to: $to, range: $range, type: 'month');
        $vendorWallet = $this->vendorWalletRepo->getFirstWhere(params: ['seller_id' => $vendorId]);
        $label = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
        $dateType = 'yearEarn';
        $dashboardData = [
            'orderStatus' => $this->getOrderStatusArray(type: 'overall'),
            'customers' => $this->customerRepo->getList(dataLimit: 'all')->count(),
            'products' => $this->productRepo->getListWhere(filters: ['seller_id' => $vendorId, 'added_by' => 'seller'])->count(),
            'orders' => $this->orderRepo->getListWhere(filters: ['seller_id' => $vendorId, 'seller_is' => 'seller'])->count(),
            'brands' => $this->brandRepo->getListWhere(dataLimit: 'all')->count(),
            'topSell' => $topSell,
            'topRatedProducts' => $topRatedProducts,
            'topRatedDeliveryMan' => $topRatedDeliveryMan,
            'totalEarning' => $vendorWallet->total_earning ?? 0,
            'withdrawn' => $vendorWallet->withdrawn ?? 0,
            'pendingWithdraw' => $vendorWallet->pending_withdraw ?? 0,
            'adminCommission' => $vendorWallet->commission_given ?? 0,
            'deliveryManChargeEarned' => $vendorWallet->delivery_charge_earned ?? 0,
            'collectedCash' => $vendorWallet->collected_cash ?? 0,
            'collectedTotalTax' => $vendorWallet->total_tax_collected ?? 0,
        ];

        if (getCheckAddonPublishedStatus(moduleName: 'Auction')) {
            $dashboardData = array_merge($dashboardData, app(AuctionVendorWalletStatsService::class)->getStats($vendorId));
        }
        $withdrawalMethods = $this->withdrawalMethodRepo->getListWhere(filters: ['is_active' => 1], dataLimit: 'all');

        $vendorId = auth('seller')->id();
        $vendorWallet = $this->vendorWalletRepo->getFirstWhere(params: ['seller_id' => $vendorId]);
        $withdrawRequests = $this->withdrawRequestRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            filters: ['vendorId' => $vendorId],
            relations: ['seller'],
            dataLimit: getWebConfig('pagination_limit')
        );

        $withdrawalMethods = $this->withdrawalMethodRepo->getListWhere(filters: ['is_active' => 1], dataLimit: 'all');
        $vendorWithdrawMethods = $this->vendorWithdrawMethodInfoRepo->getListWhere(
            orderBy: ['method_name' => 'asc'],
            filters: ['user_id' => $vendorId, 'is_active' => 1],
            relations: ['withdraw_method'],
            dataLimit: 'all'
        );
        /*
         * Operational KPIs the seller acts on: inventory needing attention, and revenue/orders
         * compared against the PREVIOUS EQUAL PERIOD. The comparison reports 'no_baseline' or 'new'
         * rather than inventing a percentage when there is no comparable history — a fabricated
         * trend makes a seller distrust every number on the page.
         */
        $statsService = app(VendorDashboardStatsService::class);
        $inventoryAlerts = $statsService->inventoryAlerts(sellerId: (int) $vendorId);
        $salesComparison = $statsService->salesWithComparison(
            sellerId: (int) $vendorId,
            from: now()->startOfMonth()->toDateTimeString(),
            to: now()->toDateTimeString(),
        );

        return view('vendor-views.dashboard.index', [
            'inventoryAlerts' => $inventoryAlerts,
            'salesComparison' => $salesComparison,
            'vendorWallet' => $vendorWallet,
            'withdrawRequests' => $withdrawRequests,
            'vendorWithdrawMethods' => $vendorWithdrawMethods,

            'dashboardData' => $dashboardData,
            'vendorEarning' => $vendorEarning,
            'commissionEarn' => $commissionEarn,
            'withdrawalMethods' => $withdrawalMethods,
            'dateType' => $dateType,
            'label' => $label,
        ]);
    }

    /**
     * @param string $type
     * @return JsonResponse
     * @throws \Throwable
     */
    public function getOrderStatus(string $type): JsonResponse
    {
        session()->put('vendor_statistics_type', $type);
        $orderStatus = $this->getOrderStatusArray($type);
        return response()->json([
            'view' => view('vendor-views.partials._dashboard-order-status', compact('orderStatus'))->render()
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws \Throwable
     */
    public function getEarningStatistics(Request $request): JsonResponse
    {
        $dateType = $request['type'];
        $dateTypeArray = $this->dashboardService->getDateTypeData(dateType: $dateType);
        $from = $dateTypeArray['from'];
        $to = $dateTypeArray['to'];
        $type = $dateTypeArray['type'];
        $range = $dateTypeArray['range'];
        $vendorEarning = $this->getVendorEarning(from: $from, to: $to, range: $range, type: $type);
        $commissionEarn = $this->getAdminCommission(from: $from, to: $to, range: $range, type: $type);
        $vendorEarning = array_values($vendorEarning);
        $commissionEarn = array_values($commissionEarn);
        $label = $dateTypeArray['keyRange'] ?? [];
        return response()->json([
            'view' => view('vendor-views.dashboard.partials.earning-statistics', compact('vendorEarning', 'commissionEarn', 'label', 'dateType'))->render(),
        ]);
    }

    /**
     * @param WithdrawRequest $request
     * @return RedirectResponse
     * @throws Exception
     */
    public function getWithdrawRequest(WithdrawRequest $request): RedirectResponse
    {
        $vendorId = auth('seller')->id();

        // Same anti-takeover controls the ledger payout path enforces, applied to the legacy withdraw
        // flow so it can't be used as a bypass: block during the bank-change cooling window, and honor
        // the (default-off) KYC-for-payout gate.
        $payoutService = app(\App\Services\Marketplace\PayoutService::class);
        if ($payoutService->isInCoolingPeriod($vendorId)) {
            ToastMagic::error(translate('bank_details_recently_changed_payouts_are_temporarily_paused'));
            return redirect()->back();
        }
        if (!app(\App\Services\Marketplace\SellerVerificationService::class)->isPayoutEligible($vendorId)) {
            ToastMagic::error(translate('kyc_verification_required'));
            return redirect()->back();
        }

        $withdrawMethod = $this->withdrawalMethodRepo->getFirstWhere(params: ['id' => $request['withdraw_method']]);
        $wallet = $this->vendorWalletRepo->getFirstWhere(params: ['seller_id' => auth('seller')->id()]);
        if (($wallet['total_earning'] ?? 0) >= currencyConverter($request['amount']) && $request['amount'] > 0) {
            $this->withdrawRequestRepo->add($this->withdrawRequestService->getWithdrawRequestData(
                withdrawMethod: $withdrawMethod,
                request: $request,
                addedBy: 'vendor',
                vendorId: $vendorId
            ));
            $totalEarning = $wallet['total_earning'] - currencyConverter($request['amount']);
            $pendingWithdraw = $wallet['pending_withdraw'] + currencyConverter($request['amount']);
            $this->vendorWalletRepo->update(
                id: $wallet['id'],
                data: $this->vendorWalletService->getVendorWalletData(totalEarning: $totalEarning, pendingWithdraw: $pendingWithdraw)
            );
            ToastMagic::success(translate('withdraw_request_has_been_sent'));
        } else {
            ToastMagic::error(translate('invalid_request') . '!');
        }
        return redirect()->back();
    }

    /**
     * @param WithdrawRequest $request
     * @return RedirectResponse
     * @throws Exception
     */
    public function updateWithdrawRequest(WithdrawRequest $request): RedirectResponse
    {
        $vendorId = auth('seller')->id();
        $withdrawMethod = $this->withdrawalMethodRepo->getFirstWhere(params: ['id' => $request['withdraw_method']]);
        $wallet = $this->vendorWalletRepo->getFirstWhere(params: ['seller_id' => auth('seller')->id()]);
        $withdrawRequest = $this->withdrawRequestRepo->getFirstWhere(params: ['id' => $request['withdraw_request_id']]);

        if ($withdrawRequest) {
            $totalEarning = $wallet['total_earning'] + $withdrawRequest['amount'];
            $pendingWithdraw = $wallet['pending_withdraw'] - $withdrawRequest['amount'];

            if ($totalEarning >= currencyConverter($request['amount']) && $request['amount'] > 0) {
                $this->withdrawRequestRepo->update(id: $withdrawRequest['id'], data: $this->withdrawRequestService->getWithdrawRequestData(
                    withdrawMethod: $withdrawMethod,
                    request: $request,
                    addedBy: 'vendor',
                    vendorId: $vendorId
                ));

                $this->vendorWalletRepo->update(
                    id: $wallet['id'],
                    data: [
                        'total_earning' => $totalEarning - currencyConverter($request['amount']),
                        'pending_withdraw' => $pendingWithdraw + currencyConverter($request['amount']),
                    ],
                );
                ToastMagic::success(translate('withdraw_request_has_been_update'));
            } else {
                ToastMagic::error(translate('Currently this amount not withdrawable') . '!');
            }
            return redirect()->back();
        }

        ToastMagic::error(translate('invalid_request') . '!');
        return redirect()->back();
    }

    /**
     * @param string $type
     * @return array
     */
    protected function getOrderStatusArray(string $type): array
    {
        $vendorId = auth('seller')->id();
        $status = getOrderStatusList();

        $statusWiseOrders = [];
        foreach ($status as $key) {
            $count = $this->orderRepo->getListWhereDate(
                filters: [
                    'seller_is' => 'seller',
                    'seller_id' => $vendorId,
                    'order_status' => $key
                ],
                dateType: $type,
            )->count();
            $statusWiseOrders[$key] = $count;
        }
        return $statusWiseOrders;
    }

    /**
     * @param string|Carbon $from
     * @param string|Carbon $to
     * @param array $range
     * @param string $type
     * @return array
     */
    protected function getVendorEarning(string|Carbon $from, string|Carbon $to, array $range, string $type): array
    {
        $vendorId = auth('seller')->id();
        $vendorEarnings = $this->orderTransactionRepo->getListWhereBetween(
            filters: [
                'seller_is' => 'seller',
                'seller_id' => $vendorId,
                'status' => 'disburse',
            ],
            selectColumn: 'seller_amount',
            whereBetween: 'created_at',
            groupBy: $type,
            whereBetweenFilters: [$from, $to],
        );
        return $this->dashboardService->getDateWiseAmount(range: $range, type: $type, amountArray: $vendorEarnings);
    }

    /**
     * @param string|Carbon $from
     * @param string|Carbon $to
     * @param array $range
     * @param string $type
     * @return array
     */
    protected function getAdminCommission(string|Carbon $from, string|Carbon $to, array $range, string $type): array
    {
        $vendorId = auth('seller')->id();
        $commissionGiven = $this->orderTransactionRepo->getListWhereBetween(
            filters: [
                'seller_is' => 'seller',
                'seller_id' => $vendorId,
                'status' => 'disburse',
            ],
            selectColumn: 'admin_commission',
            whereBetween: 'created_at',
            groupBy: $type,
            whereBetweenFilters: [$from, $to],
        );
        return $this->dashboardService->getDateWiseAmount(range: $range, type: $type, amountArray: $commissionGiven);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getMethodList(Request $request): JsonResponse
    {
        $method = $this->withdrawalMethodRepo->getFirstWhere(params: ['id' => $request['method_id'], 'is_active' => 1]);
        return response()->json(['content' => $method]);
    }

    public function getRealTimeActivities(): JsonResponse
    {
        $newOrder = $this->orderRepo->getListWhere(
            filters: ['seller_is' => 'seller', 'seller_id' => auth('seller')->id(), 'checked' => 0],
            dataLimit: 'all'
        )->count();
        $restockProductList = $this->restockProductRepo->getListWhere(filters: ['added_by' => 'seller', 'seller_id' => auth('seller')->id()], dataLimit: 'all')->groupBy('product_id');
        $restockProduct = [];
        if (count($restockProductList) == 1) {
            $products = $this->restockProductRepo->getListWhere(
                orderBy: ['updated_at' => 'desc'],
                filters: ['added_by' => 'seller', 'seller_id' => auth('seller')->id()],
                relations: ['product'],
                dataLimit: 'all');
            $firstProduct = $products->first();

            $count = $products?->sum('restock_product_customers_count') ?? 0;
            $restockProduct = [
                'title' => $firstProduct?->product?->name ?? '',
                'body' => $count < 100 ? translate('This_product_has') . ' ' . $count . ' ' . translate('restock_request') : translate('This_product_has') . ' 99+ ' . translate('restock_request'),
                'image' => getStorageImages(path: $firstProduct?->product?->thumbnail_full_url ?? '', type: 'product'),
                'route' => route('vendor.products.request-restock-list')
            ];
        } elseif (count($restockProductList) > 1) {
            $restockProduct = [
                'title' => translate('Restock_Request'),
                'body' => (count($restockProductList) < 100 ? count($restockProductList) : '99 +') . ' ' . translate('more_products_have_restock_request'),
                'image' => dynamicAsset(path: 'public/assets/back-end/img/icons/restock-request-icon.svg'),
                'route' => route('vendor.products.request-restock-list')
            ];
        }

        return response()->json([
            'success' => 1,
            'new_order_count' => $newOrder,
            'restockProductCount' => $restockProductList->count(),
            'restockProduct' => $restockProduct
        ]);
    }
}
