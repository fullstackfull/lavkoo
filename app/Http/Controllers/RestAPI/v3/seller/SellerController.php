<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Services\Marketplace\PayoutService;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\ReviewRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v3\SellerUpdateRequest;
use App\Http\Requests\API\v3\ShopInfoUpdateRequest;
use App\Models\Coupon;
use App\Models\DeliveryMan;
use App\Models\Order;
use App\Models\OrderTransaction;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\Shop;
use App\Models\WithdrawalMethod;
use App\Models\WithdrawRequest;
use App\Models\User;
use App\Repositories\OrderTransactionRepository;
use App\Services\DashboardService;
use App\Traits\FileManagerTrait;
use App\Utils\BackEndHelper;
use App\Utils\Convert;
use App\Utils\Helpers;
use App\Utils\ImageManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Auction\app\Enums\ApprovalStatus;
use Modules\Auction\app\Enums\OwnerType;
use Modules\Auction\app\Models\AuctionProduct;
use Modules\Auction\app\Services\AuctionVendorWalletStatsService;
use phpDocumentor\Reflection\Types\Boolean;

class SellerController extends Controller
{
    use FileManagerTrait;

    public function __construct(
        private readonly OrderTransactionRepository $orderTransactionRepo,
        private readonly DashboardService           $dashboardService,
        private readonly OrderRepositoryInterface   $orderRepo,
        private readonly ProductRepositoryInterface $productRepo,
        private readonly ReviewRepositoryInterface  $reviewRepo,
    )
    {
    }

    public function shop_info(Request $request): JsonResponse
    {
        $seller = $request->seller;

        $allProductids = $this->productRepo->getListWithScope(filters: ['added_by' => 'seller', 'seller_id' => $seller['id']], dataLimit: 'all')->pluck('id')->toArray();
        $shop = Shop::where(['seller_id' => $seller['id']])->first();
        $shop['rating'] = round(Review::whereIn('product_id', $allProductids)->avg('rating'), 3);
        $shop['rating_count'] = Review::whereIn('product_id', $allProductids)->count();

        $shop['stock_limit'] = $seller['stock_limit'];
        $shop['total_products'] = count($allProductids);
        $shop['total_orders'] = $this->orderRepo->getListWhere(filters: ['seller_is' => 'seller', 'seller_id' => $seller['id']], dataLimit: 'all')->count();;
        $shop['total_reviews'] = $this->reviewRepo->getListWhereIn(filters: [
            'seller_is' => 'seller',
            'seller_id' => $seller['id'],
        ], whereInFilters: [
            'product_id' => !empty($allProductids) ? $allProductids : [null],
        ], dataLimit: 'all')->count();

        return response()->json($shop, 200);
    }

    public function seller_delivery_man(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $delivery_men = DeliveryMan::where(['seller_id' => $seller['id']])->get();

        return response()->json($delivery_men, 200);
    }

    public function shop_product_reviews(Request $request):JsonResponse
    {
        $seller = $request->seller;
        $product_ids = Product::where(['user_id' => $seller['id'], 'added_by' => 'seller'])->pluck('id')->toArray();

        if ($request->has('search')) {
            $key = searchTerms($request['search']);
            $product_id = Product::where('added_by', 'seller')->where('user_id', $seller->id)->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->where('name', 'like', "%{$value}%");
                }
            })->pluck('id')->toArray();
            $product_id = !empty($product_id) ? $product_id : [0];

            $customer_id = User::where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%");
                }
            })->pluck('id')->toArray();

            $reviews = Review::whereHas('product', function ($query) use ($seller) {
                $query->where('added_by', 'seller')->where('user_id', $seller->id);
            })
                ->with(['product', 'reply'])
                ->when($product_id, function ($query) use ($product_id, $customer_id) {
                    return $query->where(function ($query) use ($product_id, $customer_id) {
                        $query->whereIn('product_id', $product_id)->orWhereIn('customer_id', $customer_id);
                    });
                })
                ->when(($request['product_id'] != null && $request['product_id'] != 0), function ($query) use ($request) {
                    $query->where('product_id', $request['product_id']);
                })
                ->when($request['customer_id'] != null, function ($query) use ($request) {
                    $query->where('customer_id', $request['customer_id']);
                })
                ->when($request['status'] != null, function ($query) use ($request) {
                    $query->where('status', $request['status']);
                })
                ->when(!empty($request['from']) && !empty($request['to']), function ($query) use ($request) {
                    $query->whereBetween('created_at', [$request->from . ' 00:00:00', $request->to . ' 23:59:59']);
                });
        } else {
            $reviews = Review::with(['product', 'customer', 'reply'])->whereHas('product', function ($query) use ($seller) {
                $query->where('user_id', $seller->id)->where('added_by', 'seller');
            })
                ->when(($request['product_id'] != null && $request['product_id'] != 0), function ($query) use ($request) {
                    $query->where('product_id', $request['product_id']);
                })
                ->when($request['customer_id'] != null, function ($query) use ($request) {
                    $query->where('customer_id', $request['customer_id']);
                })
                ->when($request['status'] != null, function ($query) use ($request) {
                    $query->where('status', $request['status']);
                })
                ->when(!empty($request['from']) && !empty($request['to']), function ($query) use ($request) {
                    $query->whereBetween('created_at', [$request->from . ' 00:00:00', $request->to . ' 23:59:59']);
                });
        }
        $reviews = $reviews->latest()->paginate($request['limit'], ['*'], 'page', $request['offset']);

        $reviewsFilters = $reviews->map(function ($data) {
            $data['attachment_full_url'] = $data->attachment_full_url;
            $data['product'] = Helpers::product_data_formatting($data['product']);
            return $data;
        });

        return response()->json([
            'total_size' => $reviews->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'reviews' => $reviewsFilters->values()
        ], 200);
    }

    public function shopProductReviewReply(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $review = ReviewReply::where(['review_id' => $request['review_id'], 'added_by' => 'seller', 'added_by_id' => $seller['id']])->first();
        if (!$review) {
            ReviewReply::insert([
                'review_id' => $request['review_id'],
                'added_by' => 'seller',
                'added_by_id' => $seller['id'],
                'reply_text' => $request['reply_text'],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } else {
            ReviewReply::where([
                'review_id' => $request['review_id'],
                'added_by' => 'seller',
                'added_by_id' => $seller['id']
            ])->update([
                'reply_text' => $request['reply_text'],
                'updated_at' => Carbon::now(),
            ]);
        }

        return response()->json(['message' => translate('Review_reply_successfully')], 200);
    }

    public function shop_product_reviews_status(Request $request):JsonResponse
    {
        // A review that has since been deleted used to fatal here rather than answer, so the app
        // received a 500 HTML page in place of a JSON error it could show.
        $review = Review::find($request['id']);
        if (!$review) {
            return response()->json(['errors' => [
                ['code' => 'review', 'message' => translate('review_not_found')],
            ]], 404);
        }

        $review->status = $request['status'];
        $review->save();

        return response()->json(['message' => translate('status updated successfully!!')], 200);
    }

    public function getSellerInfo(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $data = Seller::with(['wallet'])->withCount(['product', 'orders' => function ($query) use ($seller) {
            $query->where(['seller_id' => $seller['id'], 'seller_is' => ($seller['id'] == 0 ? 'admin' : 'seller')]);
        }])->find($seller['id']);

        $data['free_delivery_features_status'] = getWebConfig(name: 'free_delivery_status');
        $data['free_delivery_responsibility'] = getWebConfig(name: 'free_delivery_responsibility');
        $data['minimum_order_amount_by_seller'] = getWebConfig(name: 'minimum_order_amount_by_seller');
        $data['minimum_order_amount'] = \App\Utils\Convert::default($data['minimum_order_amount']);
        $data['free_delivery_over_amount'] = \App\Utils\Convert::default($data['free_delivery_over_amount']);
        $auctionAddonPublished = getCheckAddonPublishedStatus(moduleName: 'Auction');
        $auctionFeatureEnabled = getWebConfig(name: 'auction_feature_status') == 1;

        $vendorHasAuctions = false;

        if (function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction')) {
            $vendorHasAuctions = $auctionAddonPublished && !$auctionFeatureEnabled
                && AuctionProduct::query()
                    ->where('owner_type', OwnerType::SELLER)
                    ->where('owner_id', $seller['id'])
                    ->whereNotIn('approval_status', [ApprovalStatus::PENDING, ApprovalStatus::REJECTED])
                    ->exists();
        }

        $data['show_auction_menu_for_vendor'] = $auctionAddonPublished && ($auctionFeatureEnabled || $vendorHasAuctions) ? 1 : 0;

        if (getCheckAddonPublishedStatus(moduleName: 'Auction')) {
            foreach (app(AuctionVendorWalletStatsService::class)->getStats($seller['id']) as $key => $value) {
                $data[$key] = $value;
            }
        }

        return response()->json($data, 200);
    }

    public function shop_info_update(ShopInfoUpdateRequest $request): JsonResponse
    {
        $seller = $request->seller;
        $shop = Shop::where(['seller_id' => $seller['id']])->first();

        $old_bottom_banner = $shop->bottom_banner;
        $image = $request->file('bottom_banner');
        if ($image != null) {
            $bottom_banner = ImageManager::upload('shop/banner/', 'webp', $request->file('bottom_banner'));
        } else {
            $bottom_banner = $old_bottom_banner;
        }

        $old_offer_banner = $shop->offer_banner;
        $image = $request->file('offer_banner');
        if ($image != null) {
            $offer_banner = ImageManager::upload('shop/banner/', 'webp', $request->file('offer_banner'));
        } else {
            $offer_banner = $old_offer_banner;
        }

        $old_banner = $shop->banner;
        $image = $request->file('banner');
        if ($image != null) {
            $banner = ImageManager::upload('shop/banner/', 'webp', $request->file('banner'));
        } else {
            $banner = $old_banner;
        }

        $old_image = $shop->image;
        $image = $request->file('logo');
        if ($image != null) {
            $imageName = ImageManager::update('shop/', $old_image, 'webp', $request->file('logo'));
        } else {
            $imageName = $old_image;
        }

        if ($request->has('minimum_order_amount')) {
            Seller::where(['id' => $seller['id']])->update([
                'minimum_order_amount' => BackEndHelper::currency_to_usd($request['minimum_order_amount'])
            ]);
        }

        if ($request->has('free_delivery_status')) {
            Seller::where(['id' => $seller['id']])->update([
                'free_delivery_status' => $request['free_delivery_status']
            ]);
        }

        if ($request->has('free_delivery_over_amount')) {
            Seller::where(['id' => $seller['id']])->update([
                'free_delivery_over_amount' => BackEndHelper::currency_to_usd($request['free_delivery_over_amount'])
            ]);
        }

        if ($request->has('stock_limit')) {
            Seller::where(['id' => $seller['id']])->update([
                'stock_limit' => $request['stock_limit']
            ]);
        }

        if ($request->file('tin_certificate')) {
            Shop::where(['seller_id' => $seller['id']])->update([
                'tin_certificate' => $this->fileUpload(dir: 'shop/documents/', format: $request->file('tin_certificate')->getClientOriginalExtension(), file: $request->file('tin_certificate')),
                'tin_certificate_storage_type' => config('filesystems.disks.default') ?? 'public',
                'updated_at' => now()
            ]);
        }

        Shop::where(['seller_id' => $seller['id']])->update([
            'name' => $request['name'],
            'address' => $request['address'],
            'contact' => $request['contact'],
            'image' => $imageName,
            'banner' => $banner,
            'bottom_banner' => $bottom_banner,
            'offer_banner' => $offer_banner,
            'tax_identification_number' => $request['tax_identification_number'],
            'tin_expire_date' => $request['tin_expire_date'],
            'updated_at' => now()
        ]);

        return response()->json(translate('Shop_info_updated_successfully'), 200);
    }

    public function updateSetupGuideApp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|string',
            'value' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $seller = $request->seller;
        $shop = Shop::where(['seller_id' => $seller['id']])->first();
        $setupGuideApp = is_array($shop['setup_guide_app']) ? $shop['setup_guide_app'] : json_decode($shop['setup_guide_app'], true);
        $setupGuideApp[$request['key']] = (int)($request['value'] ?? 0);

        Shop::where(['seller_id' => $seller['id']])->update([
            'setup_guide_app' => json_encode($setupGuideApp),
            'updated_at' => now()
        ]);

        return response()->json(translate('Shop_info_updated_successfully'), 200);
    }

    public function seller_info_update(SellerUpdateRequest $request): JsonResponse
    {
        $seller = $request->seller;

        // The bank columns as they stand, read before the write. The web path routes a bank change
        // through PayoutService — which records the before/after audit row and arms the payout
        // cooling window — and this path wrote the same four columns directly and did neither, so a
        // payout redirect performed from the app left no trace and no delay behind it.
        $current = Seller::where(['id' => $seller['id']])->first();
        $previousBank = [
            'bank_name' => $current->bank_name, 'branch' => $current->branch,
            'holder_name' => $current->holder_name, 'account_no' => $current->account_no,
        ];

        $old_image = $current->image;
        $image = $request->file('image');
        if ($image != null) {
            $imageName = ImageManager::update('seller/', $old_image, 'webp', $request->file('image'));
        } else {
            $imageName = $old_image;
        }

        Seller::where(['id' => $seller['id']])->update([
            'f_name' => $request['f_name'],
            'l_name' => $request['l_name'],
            'bank_name' => $request['bank_name'],
            'branch' => $request['branch'],
            'account_no' => $request['account_no'],
            'holder_name' => $request['holder_name'],
            'phone' => $request['phone'],
            'password' => $request['password'] != null ? bcrypt($request['password']) : $current->password,
            'image' => $imageName,
            'updated_at' => now()
        ]);

        $newBank = [
            'bank_name' => $request['bank_name'], 'branch' => $request['branch'],
            'holder_name' => $request['holder_name'], 'account_no' => $request['account_no'],
        ];

        if ($previousBank !== $newBank) {
            app(PayoutService::class)->recordBankChange(
                sellerId: $seller['id'],
                previous: $previousBank,
                current: $newBank,
                changedBy: $seller['id'],
                changedByType: 'seller',
                ip: $request->ip(),
            );
        }

        // Changing the password ends every other session, which is right — but the caller is not
        // one of them. This used to rotate the token and answer 200 with no way to learn the new
        // one, so the app kept sending the credential it had just invalidated and every request
        // after a password change came back 401, all the way to the login screen.
        $token = null;
        if ($request['password'] != null) {
            $token = Str::random(50);
            Seller::where(['id' => $seller['id']])->update(['auth_token' => $token]);
        }

        return response()->json([
            'message' => translate('Info updated successfully!'),
            'token' => $token,
        ], 200);
    }

    public function withdraw_method_list(Request $request): JsonResponse
    {
        $methods = WithdrawalMethod::ofStatus(1)->where('vendor_status', 1)->get();
        return response()->json($methods, 200);
    }

    public function withdraw_request(Request $request): JsonResponse
    {
        $method = WithdrawalMethod::find($request['withdraw_method_id']);

        if (!$method) {
            return response()->json(['message' => translate('Invalid_withdraw_request')], 403);
        }

        $fields = !is_null($method->method_fields) ? array_column($method->method_fields, 'input_name') : [];
        $values = $request->all();

        $data['method_name'] = $method->method_name;
        foreach ($fields as $field) {
            if (key_exists($field, $values)) {
                $data[$field] = $values[$field];
            }
        }

        $seller = $request->seller;

        // Same anti-takeover controls the ledger payout path enforces, so the seller app cannot be used
        // to bypass them: block during the bank-change cooling window and honor the KYC-for-payout gate.
        if (app(\App\Services\Marketplace\PayoutService::class)->isInCoolingPeriod($seller['id'])) {
            return response()->json(['message' => translate('bank_details_recently_changed_payouts_are_temporarily_paused')], 403);
        }
        if (!app(\App\Services\Marketplace\SellerVerificationService::class)->isPayoutEligible($seller['id'])) {
            return response()->json(['message' => translate('kyc_verification_required')], 403);
        }

        $wallet = SellerWallet::where('seller_id', $seller['id'])->first();
        if (($wallet->total_earning) >= Convert::usd($request['amount']) && $request['amount'] > 0) {
            DB::table('withdraw_requests')->insert([
                'seller_id' => $seller['id'],
                'amount' => Convert::usd($request['amount']),
                'transaction_note' => null,
                'withdrawal_method_id' => $request['withdraw_method_id'],
                'withdrawal_method_fields' => json_encode($data),
                'approved' => 0,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            $wallet->total_earning -= BackEndHelper::currency_to_usd($request['amount']);
            $wallet->pending_withdraw += BackEndHelper::currency_to_usd($request['amount']);
            $wallet->save();
            return response()->json(translate('Withdraw request sent successfully!'), 200);
        }
        return response()->json(['message' => translate('Invalid_withdraw_request')], 403);
    }


    public function withdraw_request_update(Request $request): JsonResponse
    {
        $method = WithdrawalMethod::find($request['withdraw_method_id']);
        if (!$method) {
            return response()->json(['message' => translate('Invalid_withdraw_request')], 403);
        }

        $fields = !is_null($method->method_fields) ? array_column($method->method_fields, 'input_name') : [];
        $values = $request->all();

        $data['method_name'] = $method->method_name;
        foreach ($fields as $field) {
            if (key_exists($field, $values)) {
                $data[$field] = $values[$field];
            }
        }

        $seller = $request->seller;
        $wallet = SellerWallet::where('seller_id', $seller['id'])->first();

        $withdrawRequest = WithdrawRequest::where(['id' => $request['withdraw_request_id']])->first();
        if ($withdrawRequest) {
            $totalEarning = $wallet->total_earning + $withdrawRequest['amount'];
            $pendingWithdraw = $wallet->pending_withdraw - $withdrawRequest['amount'];

            if (($totalEarning) >= Convert::usd($request['amount']) && $request['amount'] > 0) {
                WithdrawRequest::where(['id' => $request['withdraw_request_id']])->update([
                    'seller_id' => $seller['id'],
                    'amount' => Convert::usd($request['amount']),
                    'transaction_note' => null,
                    'withdrawal_method_id' => $request['withdraw_method_id'],
                    'withdrawal_method_fields' => json_encode($data),
                    'approved' => 0,
                    'updated_at' => now()
                ]);
                SellerWallet::where('seller_id', $seller['id'])->update([
                    'total_earning' => $totalEarning - BackEndHelper::currency_to_usd($request['amount']),
                    'pending_withdraw' => $pendingWithdraw + BackEndHelper::currency_to_usd($request['amount']),
                ]);
                return response()->json(translate('Withdraw request update successfully!'), 200);
            }
        }
        return response()->json(['message' => translate('Invalid_withdraw_request')], 403);
    }

    public function close_withdraw_request(Request $request): JsonResponse
    {
        $seller = $request->seller;

        $withdraw_request = WithdrawRequest::find($request['id']);
        $wallet = SellerWallet::where('seller_id', $seller['id'])->first();

        if (isset($withdraw_request) && $withdraw_request->approved == 0) {
            $wallet->total_earning += $withdraw_request['amount'];
            $wallet->pending_withdraw -= $withdraw_request['amount'];
            $wallet->save();
            $withdraw_request->delete();
            return response()->json(translate('Withdraw request has been closed successfully!'), 200);
        }

        return response()->json(translate('Withdraw request is invalid'), 403);
    }

    public function transaction(Request $request): JsonResponse
    {
        $status = $request->status;
        if ($status == 'pending') {
            $status = 0;
        } elseif ($status == 'approve') {
            $status = 1;
        } elseif ($status == 'deny') {
            $status = 2;
        }

        $seller = $request->seller;
        $transaction = WithdrawRequest::where('seller_id', $seller['id'])
            ->when(in_array($status, ['0', 1, 2]), function ($query) use ($status) {
                $query->where('approved', $status);
            })
            ->whereNull('delivery_man_id')
            ->when(($request->from && $request->to), function ($query) use ($request) {
                $query->whereBetween('created_at', [$request->from . ' 00:00:00', $request->to . ' 23:59:59']);
            })
            ->latest()->get();

        return response()->json($transaction, 200);
    }

    public function monthly_earning(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $from = Carbon::now()->startOfYear()->format('Y-m-d');
        $to = Carbon::now()->endOfYear()->format('Y-m-d');
        $seller_data = '';
        $seller_earnings = OrderTransaction::where([
            'seller_is' => 'seller',
            'seller_id' => $seller['id'],
            'status' => 'disburse'
        ])->select(
            DB::raw('IFNULL(sum(seller_amount),0) as sums'),
            DB::raw('YEAR(created_at) year, MONTH(created_at) month')
        )->whereBetween('created_at', [$from, $to])->groupby('year', 'month')->get()->toArray();
        for ($inc = 1; $inc <= 12; $inc++) {
            $default = 0;
            foreach ($seller_earnings as $match) {
                if ($match['month'] == $inc) {
                    $default = $match['sums'];
                }
            }
            $seller_data .= $default . ',';
        }

        return response()->json($seller_data, 200);
    }

    public function monthly_commission_given(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $from = Carbon::now()->startOfYear()->format('Y-m-d');
        $to = Carbon::now()->endOfYear()->format('Y-m-d');

        $commission_data = '';
        $commission_earnings = OrderTransaction::where([
            'seller_is' => 'seller',
            'seller_id' => $seller['id'],
            'status' => 'disburse'
        ])->select(
            DB::raw('IFNULL(sum(admin_commission),0) as sums'),
            DB::raw('YEAR(created_at) year, MONTH(created_at) month')
        )->whereBetween('created_at', [$from, $to])->groupby('year', 'month')->get()->toArray();
        for ($inc = 1; $inc <= 12; $inc++) {
            $default = 0;
            foreach ($commission_earnings as $match) {
                if ($match['month'] == $inc) {
                    $default = $match['sums'];
                }
            }
            $commission_data .= $default . ',';
        }

        return response()->json($commission_data, 200);
    }

    public function update_cm_firebase_token(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cm_firebase_token' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }
        $seller = $request->seller;

        DB::table('sellers')->where('id', $seller->id)->update([
            'cm_firebase_token' => $request['cm_firebase_token'],
        ]);

        return response()->json(['message' => translate('successfully updated!')], 200);
    }

    public function account_delete(Request $request): JsonResponse
    {
        $seller = $request->seller;
        if ($this->getCountOfOngoingOrderStatus(sellerId: $request->seller->id) > 0) {
            return response()->json(['status' => 'error', 'key' => 'ongoing_order_left', 'message' => translate('please_make_sure_you_don`t_have_any_ongoing_order')], 403);
        }

        if (!$this->checkAdminCommissionAmountClearance(sellerId: $request->seller->id)) {
            return response()->json(['status' => 'error', 'key' => 'admin_commission_not_paid', 'message' => translate('please_clear_all_the_transaction_with_admin')], 403);
        }

        if ($this->getCountOfDeliveryManTransactionNotClearWithSeller(sellerId: $request->seller->id) > 0) {
            return response()->json(['status' => 'error', 'key' => 'delivery_man_transaction_left', 'message' => translate('please_clear_all_the_transaction_with_delivery_man')], 403);
        }
        if ($seller->id) {
            Coupon::where(['coupon_bearer' => 'seller', 'seller_id' => $seller->id])->delete();
            ImageManager::delete('/seller/' . $seller['image']);
            $seller->delete();
            return response()->json(['message' => translate('Your_account_deleted_successfully!!')], 200);
        } else {
            return response()->json(['message' => 'access_denied!!'], 403);
        }
    }

    protected function checkAdminCommissionAmountClearance($sellerId)
    {
        $adminCommission = OrderTransaction::where(['seller_is' => 'seller', 'seller_id' => $sellerId])->sum('admin_commission') ?? 0;
        $sellerGivenToAdmin = SellerWallet::where('seller_id', $sellerId)->first()->admin_commission ?? 0;
        return $adminCommission >= $sellerGivenToAdmin;
    }

    protected function getCountOfDeliveryManTransactionNotClearWithSeller($sellerId): int
    {
        return DeliveryMan::with('wallet')->whereHas('wallet', function ($query) {
            return $query->where('current_balance', '!=', 0)->where('cash_in_hand', '!=', 0);
        })->where('seller_id', $sellerId)->get()->count();
    }

    protected function getCountOfOngoingOrderStatus($sellerId): int
    {
        return Order::whereIn('order_status', ['pending', 'confirmed', 'out_for_delivery', 'processing'])->where(['seller_is' => 'seller', 'seller_id' => $sellerId])->get()->count();
    }

    protected function getVendorEarning(object|array $seller, string|Carbon $from, string|Carbon $to, array $range, string $type): array
    {
        $vendorEarnings = $this->orderTransactionRepo->getListWhereBetween(
            filters: [
                'seller_is' => 'seller',
                'seller_id' => $seller?->id,
                'status' => 'disburse',
            ],
            selectColumn: 'seller_amount',
            whereBetween: 'created_at',
            groupBy: $type,
            whereBetweenFilters: [$from, $to],
        );
        return $this->dashboardService->getDateWiseAmountInUSD(range: $range, type: $type, amountArray: $vendorEarnings);
    }

    protected function getAdminCommission(object|array $seller, string|Carbon $from, string|Carbon $to, array $range, string $type): array
    {
        $commissionGiven = $this->orderTransactionRepo->getListWhereBetween(
            filters: [
                'seller_is' => 'seller',
                'seller_id' => $seller?->id,
                'status' => 'disburse',
            ],
            selectColumn: 'admin_commission',
            whereBetween: 'created_at',
            groupBy: $type,
            whereBetweenFilters: [$from, $to],
        );
        return $this->dashboardService->getDateWiseAmountInUSD(range: $range, type: $type, amountArray: $commissionGiven);;
    }

    public function getEarningStatics(Request $request): JsonResponse
    {
        // Coerced: getDateTypeData is typed `string`, so a `?type[]=` array would be an uncatchable
        // TypeError rather than a value it can fall back from.
        $dateType = is_string($request['type']) ? $request['type'] : '';
        $dateTypeArray = $this->dashboardService->getDateTypeData(dateType: $dateType);
        $from = $dateTypeArray['from'];
        $to = $dateTypeArray['to'];
        $type = $dateTypeArray['type'];
        $range = $dateTypeArray['range'];
        $vendorEarning = $this->getVendorEarning(seller: $request->seller, from: $from, to: $to, range: $range, type: $type);
        $commissionEarn = $this->getAdminCommission(seller: $request->seller, from: $from, to: $to, range: $range, type: $type);
        $vendorEarning = array_values($vendorEarning);
        $commissionEarn = array_values($commissionEarn);

        return response()->json([
            'seller_earn' => $vendorEarning,
            'commission_earn' => $commissionEarn
        ], 200);
    }

    public function order_statistics(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $type = $request->statistics_type ?? 'all';

        $statuses = [
            'pending',
            'confirmed',
            'processing',
            'out_for_delivery',
            'delivered',
            'canceled',
            'returned',
            'failed',
        ];

        $data = [];
        foreach ($statuses as $status) {
            $query = Order::where([
                'seller_is' => 'seller',
                'seller_id' => $seller->id,
                'order_status' => $status,
            ]);

            $data[$status] = $this->filterOrderStatisticsQuery($query, $type)->count();
        }

        return response()->json($data, 200);
    }

    public function filterOrderStatisticsQuery($query, $type)
    {
        return $query
            ->when($type === 'today', function ($query) {
                return $query->whereDate('created_at', Carbon::today());
            })
            ->when($type === 'this_week', function ($query) {
                return $query->whereBetween('created_at', [
                    Carbon::now()->startOfWeek(),
                    Carbon::now()->endOfWeek(),
                ]);
            })
            ->when($type === 'this_month', function ($query) {
                return $query->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year);
            })
            ->when($type === 'this_year', function ($query) {
                return $query->whereYear('created_at', Carbon::now()->year);
            });
    }

    public function language_change(Request $request): JsonResponse
    {
        $seller = $request->seller;
        Seller::where('id', $seller['id'])->update([
           'app_language' => $request['current_language'],
        ]);
        return response()->json(['message' => 'Successfully change'], 200);
    }
}
