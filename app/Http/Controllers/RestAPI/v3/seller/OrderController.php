<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Events\OrderStatusEvent;
use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\SellerInvoiceService;
use App\Http\Requests\API\v3\DigitalProductFileUploadAfterSell;
use App\Models\BusinessSetting;
use App\Models\DeliveryManTransaction;
use App\Models\DeliverymanWallet;
use App\Models\DeliveryZipCode;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderEditHistory;
use App\Models\ReferralCustomer;
use App\Traits\CommonTrait;
use App\Models\User;
use App\Traits\ProductTrait;
use App\Utils\BackEndHelper;
use App\Utils\Convert;
use App\Utils\CustomerManager;
use App\Utils\Helpers;
use App\Utils\ImageManager;
use App\Utils\OrderManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;
use App\Services\Marketplace\SellerOrderBreakdownService;
use App\Services\Marketplace\SellerOrderTimelineService;


class OrderController extends Controller
{
    use CommonTrait;
    use ProductTrait;

    public function __construct(
        private DeliveryZipCode                   $delivery_zip_code,
        private Order                             $order,
        private readonly OrderRepositoryInterface $orderRepo,
    )
    {
    }

    public function list(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $status = $request->status;

        $dateType = $request['date_type'];
        $paymentPaidStatus = json_decode($request['payment_status'] ?? '', true);
        $orderStatus = json_decode($request['order_current_status'] ?? '', true);

        $filters = [
            'filter' => $request['filter'] ?? 'all',
            'date_type' => $request['date_type'],
            'from' => $request['start_date'],
            'to' => $request['end_date'],
            'delivery_man_id' => $request['delivery_man_id'],
            'customer_id' => $request['customer_id'],
            'seller_id' => $seller['id'],
            'seller_is' => 'seller',
            'whereIn_order_status' => $orderStatus,
        ];
        $orderAmountSettlement = json_decode($request['order_amount_settlement'] ?? '');

        if (!empty($orderAmountSettlement)) {
            $filters['has_order_edit_settlement'] = $orderAmountSettlement;
        }

        $filterWhereIn = [];
        if (!empty($paymentPaidStatus)) {
            $filterWhereIn['payment_status'] = $paymentPaidStatus;
        }

        $orderTypes = json_decode($request['order_types'] ?? '', true);
        if (!empty($orderTypes)) {
            $filterWhereIn['order_type'] = $orderTypes;
        }

        $orders = $this->orderRepo->getListWhereIn(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('search_value') ?: null,
            filters: $filters,
            whereIn: $filterWhereIn,
            relations: ['customer', 'shipping', 'deliveryMan', 'orderDetails', 'offlinePayments'],
            dataLimit: $request['limit'],
            offset: $request['offset'],
        );

        $orders?->map(function ($data) {
            if (isset($data['offlinePayments'])) {
                $data['offlinePayments']->payment_info = $data->offlinePayments->payment_info;
            }

            $totalTaxAmount = 0;
            $totalProductPrice = 0;
            $totalProductDiscount = 0;
            if (isset($data['orderDetails']) && count($data['orderDetails']) > 0) {
                $totalTaxAmount = $data['orderDetails']->sum('tax');
                $totalProductPrice = $data['orderDetails']->sum('price');
                $totalProductDiscount = $data['orderDetails']->sum('discount');
            }
            $data['total_tax_amount'] = $totalTaxAmount;
            $data['total_product_price'] = $totalProductPrice;
            $data['total_product_discount'] = $totalProductDiscount;
            return $data;
        });

        return response()->json([
            'total_size' => $orders->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'orders' => $orders->items()
        ], 200);
    }

    #[ApiDoc(
        summary: "The seller's own invoice for one of their orders",
        description: 'The same document the vendor panel prints, returned as an array of byte values '
            . 'for a client that saves the file itself. Scoped to the seller who is asking: an invoice '
            . "carries a customer's name, address and phone, so an order that is not theirs is not found.",
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function invoice(Request $request, $id, SellerInvoiceService $invoices): JsonResponse
    {
        $order = $invoices->orderFor(orderId: $id, sellerId: $request->seller->id);

        if (!$order) {
            return response()->json(['errors' => [
                ['code' => 'order', 'message' => translate('order_not_found')],
            ]], 404);
        }

        return response()->json($invoices->bytes($order, $request->seller->id), 200);
    }

    #[ApiDoc(
        summary: 'What one order is worth, and how it got here',
        description: 'Two things the seller app has never been able to show. The money side: what the '
            . 'lines sold for, the commission that was actually charged with the rule that charged it, '
            . 'who bears shipping, what the seller receives, and when a pending earning becomes '
            . 'withdrawable. The history side: every recorded event on the order, oldest first, with '
            . 'who caused it — plus the processing deadline while the order still waits on the seller. '
            . 'Where an earning has not been recorded yet the answer says so rather than estimating a '
            . 'figure a seller would read as what they will be paid. An order that is not this '
            . "seller's answers 404.",
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function breakdown(
        Request $request,
        $id,
        SellerOrderBreakdownService $breakdowns,
        SellerOrderTimelineService $timelines,
    ): JsonResponse {
        $sellerId = $request->seller->id;
        $breakdown = $breakdowns->breakdownFor(orderId: $id, sellerId: $sellerId);

        if ($breakdown === null) {
            // Either no such order, or one belonging to another seller. Both are the same answer
            // from here.
            return response()->json(['errors' => [
                ['code' => 'order', 'message' => translate('order_not_found')],
            ]], 404);
        }

        $timeline = $timelines->timelineFor(orderId: $id, sellerId: $sellerId) ?? ['events' => [], 'sla' => null];

        return response()->json([
            'order_id' => (int) $id,
            'fees' => $breakdown,
            'timeline' => $timeline['events'],
            'sla' => $timeline['sla'],
        ], 200);
    }

    public function details(Request $request, $id): JsonResponse
    {
        $seller = $request->seller;
        $detailsList = OrderDetail::with(['order.offlinePayments', 'order.customer', 'order.deliveryMan', 'verificationImages', 'latestEditHistory', 'orderEditHistory' => function ($query) {
            return $query->orderBy('updated_at', 'desc');
        }])->where(['seller_id' => $seller['id'], 'order_id' => $id])->get();

        if ($detailsList->isEmpty()) {
            // Either no such order, or one belonging to another seller. Both are the same answer
            // from here: this seller has no order by that id.
            return response()->json(['errors' => [
                ['code' => 'order', 'message' => translate('order_not_found')],
            ]], 404);
        }

        $productList = $this->getProductListWithAllDetails(ids: $detailsList->pluck('product_id')->toArray());

        $orderEditPaymentHistory = OrderEditHistory::where('order_id', $id)->orderBy('id', 'asc')->get();
        $filteredEditPaymentHistory = $orderEditPaymentHistory->filter(function ($item) {
            return $item->order_due_payment_status === 'paid'
                || $item->order_return_payment_status === 'returned';
        });

        $latestHistory = $orderEditPaymentHistory->sortByDesc('updated_at')->first();
        $unpaidDue = [];
        if ($latestHistory && $latestHistory->order_due_payment_status !== 'paid' && $latestHistory->order_due_amount > 0) {
            $unpaidDue[] = $latestHistory;
        }
        $paymentInfo = collect()->merge($filteredEditPaymentHistory)->merge($unpaidDue)->values();

        $firstDetails = $detailsList->first();
        // `null <= 0` is true in PHP, so a missing order used to pass this guard and then fatal on
        // the next line — an order that was deleted, or that belongs to another seller, answered a
        // 500 HTML page instead of a not-found.
        if ($firstDetails?->order && $firstDetails->init_order_amount <= 0) {
            $firstDetails->order->update(['init_order_amount' => $firstDetails->order['order_amount']]);
        }

        foreach ($detailsList as $detail) {
            $product = json_decode($detail['product_details'], true);

            if (!isset($product['digital_variation'])) {
                $product['digital_variation'] = [];
            }

            $product['thumbnail_full_url'] = $detail?->productAllStatus?->thumbnail_full_url;
            if (isset($product['product_type']) && $product['product_type'] == 'digital' && $product['digital_product_type'] == 'ready_product' && $product['digital_file_ready']) {
                $checkFilePath = storageLink('product/digital-product', $product['digital_file_ready'], ($product['storage_path'] ?? 'public'));
                $product['digital_file_ready_full_url'] = $checkFilePath;
            }
            $detail['product_details'] = Helpers::product_data_formatting_for_json_data($product);


            $detailsVariation = is_array($detail['variation']) ? $detail['variation'] : json_decode($detail['variation'] ?? '', true);
            $modifiedVariation = [];
            if (count($detailsVariation) > 0) {
                foreach ($detailsVariation as $variationKey => $variation) {
                    $modifiedVariation[] = [
                        'key' => $variationKey,
                        'value' => $variation,
                    ];
                }
            }

            $detail['variation'] = $modifiedVariation;
            $detail['modified_variation'] = $modifiedVariation;

            $activeProduct = $productList?->firstWhere('id', $detail['product_id']) ?? $product;
            $unitPrice = $activeProduct ? $activeProduct['unit_price'] : $detail['price'];
            $currentStock = max(0, $activeProduct['current_stock']);
            $variations = is_array($activeProduct['variation']) ? $activeProduct['variation'] : json_decode($activeProduct['variation'], true);
            $firstVariation = collect($variations)->first(function ($variation) use ($detail) {
                return $variation['type'] == $detail['variant'];
            });

            if ($detail['variant'] && $firstVariation) {
                $currentStock = $firstVariation['qty'] ?? 0;
                $unitPrice = $firstVariation['price'] ?? 0;
            }

            if ($detail && $detail['is_stock_decreased'] == 1) {
                $currentStock += $details['qty'] ?? 1;
            }

            $detail['current_stock'] = $currentStock;
            $detail['current_price'] = $unitPrice;
            $detail['edit_order_payment_histories'] = $paymentInfo;
        }

        return response()->json($detailsList, 200);
    }

    public function assign_delivery_man(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'delivery_man_id' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
        }

        $seller = $request->seller;
        $order = Order::with('deliveryMan')->where(['seller_id' => $seller['id'], 'id' => $request['order_id']])->first();

        if ($order['delivery_man_id'] != $request['delivery_man_id']) {
            $order->deliveryman_assigned_at = Carbon::now();
        }
        $order->delivery_man_id = $request['delivery_man_id'];
        $order->delivery_type = 'self_delivery';
        $order->delivery_service_name = null;
        $order->third_party_delivery_tracking_id = null;
        $order->save();
        OrderStatusEvent::dispatch('new_order_assigned_message', 'delivery_man', $order);
        return response()->json(['success' => 1, 'message' => translate('order_deliveryman_assigned_successfully')], 200);
    }

    public function amount_date_update(Request $request): JsonResponse
    {
        $seller = $request->seller;

        $deliveryManCharge = $request->deliveryman_charge;

        // Scope to the authenticated seller: this writes deliveryman_charge/expected_delivery_date, so an
        // unscoped find let one seller rewrite another seller's order.
        $order = Order::with('deliveryMan')->where(['seller_id' => $seller['id'], 'seller_is' => 'seller'])->find($request->order_id);
        if (!$order) {
            return response()->json(['success' => 0, 'message' => translate('order_not_found')], 404);
        }
        $db_expected_date = $order->expected_delivery_date;

        $order->deliveryman_charge = $deliveryManCharge;
        $order->expected_delivery_date = $request->expected_delivery_date;

        try {
            DB::beginTransaction();

            if (!empty($request->expected_delivery_date) && $db_expected_date != $request->expected_delivery_date) {
                CommonTrait::add_expected_delivery_date_history($request->order_id, $seller['id'], $request->expected_delivery_date, 'seller');
            }
            $order->save();

            DB::commit();
        } catch (\Exception $ex) {
            DB::rollback();
            return response()->json(['success' => 0, 'message' => translate('Update fail!')], 403);
        }

        if (!empty($request->expected_delivery_date) && $db_expected_date != $request->expected_delivery_date) {
            OrderStatusEvent::dispatch('expected_delivery_date', 'delivery_man', $order);
        }

        return response()->json(['success' => 0, 'message' => translate('Updated successfully!')], 200);
    }

    /**
     *  Digital file upload after sell
     */
    public function digital_file_upload_after_sell(DigitalProductFileUploadAfterSell $request): JsonResponse
    {
        $seller = $request->seller;
        // Scope the order line to the seller so one seller cannot overwrite another's delivered digital file.
        $order_details = OrderDetail::where('seller_id', $seller['id'])->find($request->order_id);
        if ($order_details) {
            $order_details->digital_file_after_sell = ImageManager::update('product/digital-product/', $order_details->digital_file_after_sell, $request->digital_file_after_sell->getClientOriginalExtension(), $request->file('digital_file_after_sell'), 'file');
            $order_details->save();
            return response()->json(['success' => 1, 'message' => translate('File_upload_successfully')], 200);
        } else {
            return response()->json(['success' => 0, 'message' => translate("File_upload_fail!")], 202);
        }
    }

    public function order_detail_status(Request $request): JsonResponse
    {
        $seller = $request->seller;
        // Scope to the seller: this mutates order/payment status with wallet/stock/settlement side-effects,
        // so an unscoped find let one seller force-deliver or cancel any vendor's order.
        $order = Order::with(['customer', 'seller.shop', 'deliveryMan', 'latestEditHistory'])
            ->where(['seller_id' => $seller['id'], 'seller_is' => 'seller'])->find($request['id']);
        if (!$order) {
            return response()->json(['success' => 0, 'message' => translate('order_not_found')], 404);
        }
        if (!$order->is_guest && empty($order->customer)) {
            return response()->json(['success' => 0, 'message' => translate("Customer_account_has_been_deleted") . ' ' . translate("you_cant_update_status")], 202);
        }

        if ($order['payment_method'] == 'offline_payment' && $order['payment_status'] == 'unpaid') {
            return response()->json(['status' => 0, 'message' => translate('Please confirm the offline payment information before changing the order status.')], 202);
        }

        if ($order['payment_method'] !== 'cash_on_delivery' && $order['edit_due_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method !== 'cash_on_delivery' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid') {
            return response()->json(['status' => 0, 'message' => translate('After admin confirm the due amount payment as paid then you can change this status.')], 202);
        }

        if ($order['payment_method'] !== 'cash_on_delivery' && $order['edit_return_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method !== 'cash_on_delivery' && $order?->latestEditHistory?->order_return_payment_status == 'pending') {
            return response()->json(['status' => 0, 'message' => translate('After admin confirm the return amount payment as returned then you can change this status.')], 202);
        }

        if ($order['edit_due_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method == 'cash_on_delivery' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid' && $order['shipping_responsibility'] == 'inhouse_shipping' && $request['order_status'] == 'delivered') {
            return response()->json(['status' => 0, 'message' => translate('Please mark as paid before delivered this order.')], 202);
        }

        $walletStatus = getWebConfig(name: 'wallet_status');
        $loyaltyPointStatus = getWebConfig(name: 'loyalty_point_status');

        if ($order->order_status == 'delivered') {
            return response()->json(['success' => 0, 'message' => translate('order is already delivered')], 200);
        }

        event(new OrderStatusEvent(key: $request['order_status'], type: 'customer', order: $order));
        if ($request->order_status == 'canceled') {
            event(new OrderStatusEvent(key: 'canceled', type: 'delivery_man', order: $order));
        }

        $order->order_status = $request['order_status'];
        if ($request['order_status'] == 'delivered') {
            $order->payment_status = 'paid';
            Order::where('id', $order->id)->update(['is_pause' => 0]);
            OrderDetail::where('order_id', $order->id)->update(['delivery_status' => 'delivered', 'payment_status' => 'paid']);
            OrderDetail::where('order_id', $order['id'])->whereNull('refund_started_at')->update(['refund_started_at' => now()]);
        }
        OrderManager::getStockUpdateOnOrderStatusChange($order, $request->order_status);
        if ($request->order_status == 'delivered' && $order['seller_id'] != null) {
            OrderManager::getWalletManageOnOrderStatusChange($order, 'seller');
        }

        $order->save();

        if ($order->delivery_man_id && $request->order_status == 'delivered') {
            $deliverymanWallet = DeliverymanWallet::where('delivery_man_id', $order->delivery_man_id)->first();
            $cashInHand = $order->payment_method == 'cash_on_delivery' ? $order->order_amount : 0;

            if (empty($deliverymanWallet)) {
                DeliverymanWallet::create([
                    'delivery_man_id' => $order->delivery_man_id,
                    'current_balance' => $order?->deliveryman_charge ?? 0,
                    'cash_in_hand' => $cashInHand,
                    'pending_withdraw' => 0,
                    'total_withdraw' => 0,
                ]);
            } else {
                $deliverymanWallet->current_balance += $order?->deliveryman_charge ?? 0;
                $deliverymanWallet->cash_in_hand += $cashInHand;
                $deliverymanWallet->save();
            }

            if ($order->deliveryman_charge && $request->order_status == 'delivered') {
                DeliveryManTransaction::create([
                    'delivery_man_id' => $order->delivery_man_id,
                    'user_id' => $seller->id,
                    'user_type' => 'seller',
                    'credit' => $order?->deliveryman_charge ?? 0,
                    'transaction_id' => Uuid::uuid4(),
                    'transaction_type' => 'deliveryman_charge'
                ]);
            }
        }

        if (!$order->is_guest && $walletStatus == 1 && $loyaltyPointStatus == 1) {
            if ($request->order_status == 'delivered') {
                CustomerManager::create_loyalty_point_transaction($order->customer_id, $order->id, Convert::default($order->order_amount - $order->shipping_cost), 'order_place');
            }
        }

        $refEarningStatus = BusinessSetting::where('type', 'ref_earning_status')->first()->value ?? 0;
        $refEarningExchangeRate = BusinessSetting::where('type', 'ref_earning_exchange_rate')->first()->value ?? 0;

        if (!$order->is_guest && $walletStatus == 1 && $refEarningStatus == 1 && $request->order_status == 'delivered') {

            $customer = User::find($order->customer_id);
            $isFirstOrder = Order::where(['customer_id' => $order->customer_id, 'order_status' => 'delivered', 'payment_status' => 'paid'])->count();
            $referredByUser = User::find($customer->referred_by);

            if ($isFirstOrder == 1 && isset($customer->referred_by) && isset($referredByUser)) {
                CustomerManager::create_wallet_transaction($referredByUser->id, floatval($refEarningExchangeRate), 'add_fund_by_admin', 'earned_by_referral');
            }
        }

        OrderManager::generateReferBonusForFirstOrder(orderId: $order['id']);
        if ($request['order_status'] == 'delivered') {
            $referredUser = ReferralCustomer::where('user_id', $order?->customer?->id)->first();
            if ($referredUser?->delivered_notify != 1) {
                event(new OrderStatusEvent(key: 'your_referred_customer_order_has_been_delivered', type: 'promoter', order: $order));
                ReferralCustomer::where('user_id', $order?->customer?->id)->update(['delivered_notify' => 1]);
            }
        }
        self::add_order_status_history($order->id, $seller->id, $request->order_status, 'seller');

        return response()->json(['success' => 1, 'message' => translate('order_status_updated_successfully')], 200);
    }

    public function assign_third_party_delivery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'delivery_service_name' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
        }

        // Scope to the authenticated seller: an unscoped find let one seller hijack/wipe the delivery
        // assignment on any vendor's order.
        $seller = $request->seller;
        $order = Order::where(['seller_id' => $seller['id'], 'seller_is' => 'seller'])->find($request->order_id);
        if (!$order) {
            return response()->json(['success' => 0, 'message' => translate('order_not_found')], 404);
        }
        $order->delivery_type = 'third_party_delivery';
        $order->delivery_service_name = $request->delivery_service_name;
        $order->third_party_delivery_tracking_id = $request->third_party_delivery_tracking_id;
        $order->delivery_man_id = null;
        $order->deliveryman_charge = 0;
        $order->expected_delivery_date = null;
        $order->save();

        return response()->json(['success' => 1, 'message' => translate('third_party_delivery_assigned_successfully')], 200);
    }

    public function update_payment_status(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'payment_status' => 'required|in:paid,unpaid'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }
        if ($request->payment_status != 'paid') {
            return response()->json(['success' => 0, 'message' => translate('When payment status paid then you can`t change payment status paid to unpaid') . '.'], 200);
        }
        // Scope to the authenticated seller: an unscoped find let one seller mark any vendor's order paid.
        $seller = $request->seller;
        $order = Order::where(['seller_id' => $seller['id'], 'seller_is' => 'seller'])->find($request['order_id']);
        if (isset($order)) {
            if ($order->is_guest == '0' && empty($order->customer)) {
                return response()->json(['success' => 0, 'message' => translate("Customer account has been deleted. you can't update status!")], 202);
            }

            if ($order['payment_method'] == 'cash_on_delivery' && $order['order_status'] != 'delivered' && $request['payment_status'] == 'paid') {
                return response()->json([
                    'errors' => [
                        ['code' => 'order', 'message' => translate('Can not change payment status before order delivered!')]
                    ]
                ], 404);
            }

            $order->payment_status = $request['payment_status'];
            $order->save();
            return response()->json(['message' => translate('Payment status updated')], 200);
        }
        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => translate('not found!')]
            ]
        ], 404);
    }

    public function address_update(Request $request)
    {
        $seller = $request->seller;
        // Only the seller who owns the order may rewrite its shipping/billing address.
        $orderModel = $this->order->where('seller_id', $seller['id'])->where('seller_is', 'seller')->find($request->order_id);
        if (!$orderModel) {
            return response()->json(['message' => translate('order_not_found')], 404);
        }
        $order = $orderModel->toArray();
        $shipping_address_data = $order['shipping_address_data'] ? json_decode(json_encode($order['shipping_address_data']), true) : [];
        $billing_address_data = $order['billing_address_data'] ? json_decode(json_encode($order['billing_address_data']), true) : [];

        $common_address_data = [
            'contact_person_name' => $request->contact_person_name,
            'phone' => $request->phone,
            'city' => $request->city,
            'zip' => $request->zip,
            'email' => $request->email,
            'address' => $request->address,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'updated_at' => now(),
        ];

        if ($request->address_type == 'shipping') {
            $shipping_address_data = array_merge($shipping_address_data, $common_address_data);
        } elseif ($request->address_type == 'billing') {
            $billing_address_data = array_merge($billing_address_data, $common_address_data);
        }
        $update_data = [];

        if ($request->address_type == 'shipping') {
            $update_data['shipping_address_data'] = json_encode($shipping_address_data);
        } elseif ($request->address_type == 'billing') {
            $update_data['billing_address_data'] = json_encode($billing_address_data);
        }

        if (!empty($update_data)) {
            DB::table('orders')->where('id', $request->order_id)
                ->where('seller_id', $seller['id'])->where('seller_is', 'seller')
                ->update($update_data);
        }

        return response()->json(['message' => 'Address updated successfully'], 200);
    }

    public function updateOrderDetails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'payment_status' => 'required|in:paid,unpaid',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $seller = $request->seller;
        // Scope to the authenticated seller: every mutation below keys off order_id, so without this
        // one seller could flip another seller's payment/order status, reassign delivery, etc.
        $order = Order::with(['customer', 'seller.shop', 'deliveryMan', 'latestEditHistory'])
            ->where('seller_id', $seller['id'])->where('seller_is', 'seller')
            ->find($request['order_id']);

        if (!$order) {
            return response()->json(['success' => 0, 'message' => translate('order_not_found')], 404);
        }

        if (isset($order)) {
            if ($order['payment_status'] == 'paid' && $request['payment_status'] != 'paid') {
                return response()->json(['success' => 0, 'message' => translate('when_payment_status_paid_then_you_can_not_change_payment_status_paid_to_unpaid.')], 403);
            }

            if ($order['payment_method'] == 'offline_payment' && $order['payment_status'] == 'unpaid') {
                return response()->json(['status' => 0, 'message' => translate('Please confirm the offline payment information before changing the order status.')], 403);
            }

            if ($order['payment_method'] !== 'cash_on_delivery' && $order['edit_due_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method !== 'cash_on_delivery' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid') {
                return response()->json(['status' => 0, 'message' => translate('After admin confirm the due amount payment as paid then you can change this status.')], 403);
            }

            if ($order['payment_method'] !== 'cash_on_delivery' && $order['edit_return_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method !== 'cash_on_delivery' && $order?->latestEditHistory?->order_return_payment_status == 'pending') {
                return response()->json(['status' => 0, 'message' => translate('After admin confirm the return amount payment as returned then you can change this status.')], 403);
            }

            if ($order['edit_due_amount'] > 0 && $order?->latestEditHistory?->order_due_payment_method == 'cash_on_delivery' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid' && $order['shipping_responsibility'] == 'inhouse_shipping' && $request['order_status'] == 'delivered') {
                return response()->json([
                    'status' => 0,
                    'message' => translate('Please mark as paid before delivered this order.'),
                ], 403);
            }

            if ($request['order_status'] == 'delivered') {
                foreach ($order['details'] as $orderDetail) {
                    $productDetails = json_decode($orderDetail?->product_details ?? '', true);
                    if (
                        $productDetails['product_type'] == 'digital' &&
                        (isset($productDetails['digital_product_type']) && $productDetails['digital_product_type'] == 'ready_after_sell') &&
                        is_null($orderDetail['digital_file_after_sell'])
                    ) {
                        return response()->json(['success' => 0, 'message' => translate('Please_upload_the_digital_product_files_first')], 403);
                    }
                }
            }

            if ($request['delivery_type'] == 'third_party_delivery') {
                Order::where('id', $request['order_id'])->update([
                    'delivery_man_id' => null,
                    'deliveryman_charge' => 0,
                    'expected_delivery_date' => null,
                    'delivery_type' => 'third_party_delivery',
                    'delivery_service_name' => $request['delivery_service_name'] ?? '',
                    'third_party_delivery_tracking_id' => $request['third_party_delivery_tracking_id'] ?? '',
                ]);
            } elseif ($request->has('delivery_man_id') && !empty($request['delivery_man_id']) && ($order['delivery_man_id'] != $request['delivery_man_id'])) {
                Order::where('id', $request['order_id'])->update([
                    'delivery_man_id' => $request['delivery_man_id'],
                    'delivery_type' => 'self_delivery',
                    'delivery_service_name' => null,
                    'third_party_delivery_tracking_id' => null,
                ]);
                OrderStatusEvent::dispatch('new_order_assigned_message', 'delivery_man', $order);
            }

            if ($request->has('deliveryman_charge') && !is_null($request['deliveryman_charge']) && ($order['deliveryman_charge'] != $request['deliveryman_charge'])) {
                Order::where(['id' => $request['order_id']])->update([
                    'deliveryman_charge' => $request['deliveryman_charge'],
                ]);
            }

            $orderInfo = Order::with('deliveryMan')->find($request['order_id']);
            if (!empty($request['expected_delivery_date']) && $orderInfo['expected_delivery_date'] != $request['expected_delivery_date']) {
                $orderInfo->expected_delivery_date = $request['expected_delivery_date'];
                try {
                    DB::beginTransaction();
                    $this->add_expected_delivery_date_history($request['order_id'], $seller['id'], $request['expected_delivery_date'], 'seller');
                    $orderInfo->save();
                    DB::commit();
                } catch (\Exception $ex) {
                    DB::rollback();
                }

                OrderStatusEvent::dispatch('expected_delivery_date', 'delivery_man', $order);
            }

            // Order Status
            if ($request->has('order_status') && !empty($request['order_status'])) {
                $order = Order::with(['customer', 'seller.shop', 'deliveryMan'])->find($request['order_id']);

                if ($order['is_guest'] == 0 && empty($order->customer)) {
                    return response()->json([
                        'success' => 0,
                        'message' => translate("Customer_account_has_been_deleted") . ' ' . translate("you_cant_update_status")
                    ], 202);
                }

                $walletStatus = getWebConfig(name: 'wallet_status');
                $loyaltyPointStatus = getWebConfig(name: 'loyalty_point_status');

                if ($order['order_status'] == 'delivered' && !in_array($request['order_status'], ['returned', 'failed', 'canceled'])) {
                    return response()->json(['success' => 0, 'message' => translate('order_is_already_delivered')], 200);
                }

                event(new OrderStatusEvent(key: $request['order_status'], type: 'customer', order: $order));
                if ($request['order_status'] == 'canceled') {
                    event(new OrderStatusEvent(key: 'canceled', type: 'delivery_man', order: $order));
                }

                Order::where('id', $request['order_id'])->update(['order_status' => $request['order_status']]);
                if ($request['order_status'] == 'delivered') {
                    Order::where('id', $request['order_id'])->update([
                        'payment_status' => 'paid',
                        'is_pause' => 0,
                    ]);
                    OrderDetail::where('order_id', $order->id)->update(['delivery_status' => 'delivered', 'payment_status' => 'paid']);
                }
                OrderManager::getStockUpdateOnOrderStatusChange($order, $request['order_status']);
                if ($request['order_status'] == 'delivered' && $order['seller_id'] != null) {
                    OrderManager::getWalletManageOnOrderStatusChange($order, 'seller');

                }

                if ($order['delivery_man_id'] && $request['order_status'] == 'delivered') {
                    $deliverymanWallet = DeliverymanWallet::where('delivery_man_id', $order['delivery_man_id'])->first();
                    $cashInHand = $order['payment_method'] == 'cash_on_delivery' ? $order['order_amount'] : 0;

                    if (empty($deliverymanWallet)) {
                        DeliverymanWallet::create([
                            'delivery_man_id' => $order['delivery_man_id'],
                            'current_balance' => $order?->deliveryman_charge ?? 0,
                            'cash_in_hand' => $cashInHand,
                            'pending_withdraw' => 0,
                            'total_withdraw' => 0,
                        ]);
                    } else {
                        DeliverymanWallet::where('delivery_man_id', $order['delivery_man_id'])->update([
                            'current_balance' => $order?->deliveryman_charge ?? 0,
                            'cash_in_hand' => $cashInHand,
                        ]);
                    }

                    if ($order['deliveryman_charge'] && $request['order_status'] == 'delivered') {
                        DeliveryManTransaction::create([
                            'delivery_man_id' => $order['delivery_man_id'],
                            'user_id' => $seller->id,
                            'user_type' => 'seller',
                            'credit' => $order?->deliveryman_charge ?? 0,
                            'transaction_id' => Uuid::uuid4(),
                            'transaction_type' => 'deliveryman_charge'
                        ]);
                    }
                }

                if (!$order['is_guest'] && $walletStatus == 1 && $loyaltyPointStatus == 1) {
                    if ($request['order_status'] == 'delivered') {
                        CustomerManager::create_loyalty_point_transaction($order['customer_id'], $order['id'], Convert::default($order['order_amount'] - $order['shipping_cost']), 'order_place');
                    }
                }

                $refEarningStatus = BusinessSetting::where('type', 'ref_earning_status')->first()->value ?? 0;
                $refEarningExchangeRate = BusinessSetting::where('type', 'ref_earning_exchange_rate')->first()->value ?? 0;

                if (!$order['is_guest'] && $walletStatus == 1 && $refEarningStatus == 1 && $request['order_status'] == 'delivered') {
                    $customer = User::find($order['customer_id']);
                    $isFirstOrder = Order::where(['customer_id' => $order['customer_id'], 'order_status' => 'delivered', 'payment_status' => 'paid'])->count();
                    $referredByUser = User::find($customer->referred_by);

                    if ($isFirstOrder == 1 && isset($customer->referred_by) && isset($referredByUser)) {
                        CustomerManager::create_wallet_transaction($referredByUser->id, floatval($refEarningExchangeRate), 'add_fund_by_admin', 'earned_by_referral');
                    }
                }

                self::add_order_status_history($order['id'], $seller->id, $request['order_status'], 'seller');
            }

            $order = Order::with(['customer', 'seller.shop', 'deliveryMan'])->find($request['order_id']);
            if ($order['payment_status'] != 'paid' && $request['payment_status'] == 'paid') {
                if ($order['is_guest'] == '0' && empty($order?->customer)) {
                    return response()->json([
                        'success' => 0,
                        'message' => translate("customer_account_has_been_deleted.") . ' ' . translate('you_can_not_update_status.'),
                    ], 200);
                }
                Order::where('id', $request['order_id'])->update(['payment_status' => $request['payment_status']]);
            }

            return response()->json([
                'success' => 1,
                'message' => translate("Order_updated_successfully")
            ], 200);
        }

        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => translate('not found!')]
            ]
        ], 404);
    }
}
