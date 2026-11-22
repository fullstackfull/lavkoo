<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\DigitalProductVariationRepositoryInterface;
use App\Contracts\Repositories\PasswordResetRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\StorageRepositoryInterface;
use App\Enums\Address\AddressChannel;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\WalletDebitRefusedException;
use App\Events\CustomerRegistrationEvent;
use App\Events\DigitalProductDownloadEvent;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\Address\CheckoutAddressGuard;
use App\Services\Address\OrderAddressService;
use App\Services\Marketplace\InventoryService;
use App\Services\PasswordResetService;
use App\Models\User;
use App\Traits\API\v3\VendorPOSManagement;
use App\Traits\CustomerTrait;
use App\Utils\CartManager;
use App\Utils\Helpers;
use App\Utils\OrderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\TaxModule\app\Traits\VatTaxManagement;

class POSController extends Controller
{

    use CustomerTrait;
    use VatTaxManagement;
    use VendorPOSManagement;

    /**
     * @param PasswordResetRepositoryInterface $passwordResetRepo
     * @param DigitalProductVariationRepositoryInterface $digitalProductVariationRepo
     * @param ProductRepositoryInterface $productRepo
     * @param StorageRepositoryInterface $storageRepo
     * @param PasswordResetService $passwordResetService
     * @param CustomerRepositoryInterface $customerRepo
     * @param OrderAddressService $orderAddresses
     * @param CheckoutAddressGuard $addressGuard
     * @param InventoryService $inventoryService
     */
    public function __construct(

        private readonly PasswordResetRepositoryInterface           $passwordResetRepo,
        private readonly DigitalProductVariationRepositoryInterface $digitalProductVariationRepo,
        private readonly ProductRepositoryInterface                 $productRepo,
        private readonly StorageRepositoryInterface                 $storageRepo,
        private readonly PasswordResetService                       $passwordResetService,
        private readonly CustomerRepositoryInterface                $customerRepo,
        private readonly OrderAddressService                        $orderAddresses,
        private readonly CheckoutAddressGuard                       $addressGuard,
        private readonly InventoryService                           $inventoryService,
    )
    {
    }


    public function getProductDiscount($product, $price)
    {
        if ($product['discount_type'] == 'percent') {
            return ($price / 100) * $product['discount'];
        }
        return $product['discount'];
    }


    public static function getOrderNewId()
    {
        $generateOrderID = 100000 + Order::all()->count() + 1;
        if (Order::find($generateOrderID)) {
            $generateOrderID = Order::orderBy('id', 'DESC')->first()->id + 1;
        }
        return $generateOrderID;
    }

    public function get_categories(): JsonResponse
    {
        $categories = Category::with(['childes.childes'])->where(['position' => 0])->priority()->get();
        return response()->json($categories, 200);
    }

    public function customer_store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'f_name' => 'required',
            'l_name' => 'required',
            'email' => 'required|email|unique:users',
            'phone' => 'unique:users',
            'country' => 'required',
            'city' => 'required',
            'zip_code' => 'required',
            'address' => 'required',
        ], [
            'f_name.required' => 'First name is required!',
            'l_name.required' => 'Last name is required!'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }
        User::create([
            'f_name' => $request['f_name'],
            'l_name' => $request['l_name'],
            'email' => $request['email'],
            'phone' => $request['phone'],
            'country' => $request['country'],
            'city' => $request['city'],
            'zip' => $request['zip_code'],
            'street_address' => $request['address'],
            'is_active' => 1,
            'password' => bcrypt('password')
        ]);

        $token = Str::random(120);
        $this->passwordResetRepo->add($this->passwordResetService->getAddData(identity: $request['phone'], token: $token, userType: 'customer'));
        $resetRoute = route('customer.auth.recover-password');
        $data = [
            'userName' => $request['f_name'],
            'userType' => 'customer',
            'templateName' => 'registration-from-pos',
            'subject' => translate('Customer_Registration_Successfully_Completed'),
            'title' => translate('welcome_to') . ' ' . getWebConfig(name: 'company_name') . '!',
            'resetPassword' => $resetRoute,
            'message' => translate('thank_you_for_joining') . ' ' . getWebConfig(name: 'company_name') . '.' . translate('if_you_want_to_become_a_registered_customer_then_reset_your_password_below_by_using_this_phone') . ' ' . ($request['phone']) . '.' . translate('then_you’ll_be_able_to_explore_the_website_and_app_as_a_registered_customer') . '.',
        ];
        event(new CustomerRegistrationEvent(email: $request['email'], data: $data));
        return response()->json(['message' => translate('customer added successfully!')], 200);
    }

    public function customers(Request $request):JsonResponse
    {
        $seller = $request->seller;
        $customers = User::when(!empty($request['name']), function ($query) use ($request) {
            $search = $request['name'];
                $query->where(function ($q) use ($search) {
                    $q->orWhere('f_name', 'like', "%{$search}%")
                        ->orWhere('l_name', 'like', "%{$search}%");
                });
            })
            ->whereNotNull(['f_name', 'l_name', 'phone'])
            ->take(10)
            ->get()
            ->toArray();

        if ($request->type != 'all') {
            array_shift($customers);
        }
        $data = array(
            'customers' => $customers
        );

        return response()->json($data, 200);
    }

    public function get_product_by_barcode(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $product = Product::withCount('reviews')->where([
            'added_by' => 'seller',
            'user_id' => $seller->id,
            'code' => $request->code
        ])->with(['clearanceSale' => function ($query) {
            return $query->active()->with(['setup']);
        }])->first();

        $final_product = array();
        if ($product) {
            $final_product = Helpers::product_data_formatting($product, false);
        }

        return response()->json($final_product, 200);
    }

    public function product_list(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $search = $request['name'];
        $categoryIds = $request->has('category_id') && $request['category_id'] != 0 ? json_decode($request['category_id'], true) : [];

        $products = Product::active()->with(['digitalVariation', 'clearanceSale' => function ($query) {
            return $query->active();
        }])
            ->withCount('reviews')
            ->where(['added_by' => 'seller', 'user_id' => $seller['id'], 'status' => 1])
            ->when(!empty($categoryIds), function ($query) use ($categoryIds) {
                $query->where(function ($query) use ($categoryIds) {
                    return $query->whereIn('category_id', $categoryIds)
                        ->orWhereIn('sub_category_id', $categoryIds)
                        ->orWhereIn('sub_sub_category_id', $categoryIds);
                });
            })
            ->when($request->has('name') && $search != null, function ($query) use ($search) {
                $key = $search ? explode(' ', $search) : '';
                foreach ($key as $value) {
                    $query->where('name', 'like', "%{$value}%");
                }
            })
            ->orderBy('id', 'DESC')
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        $products_final = Helpers::product_data_formatting($products, true);

        $data = array();
        $data['total_size'] = $products->total();
        $data['limit'] = $request['limit'];
        $data['offset'] = $request['offset'];
        $data['products'] = $products_final;
        return response()->json($data, 200);
    }

    public function isDigitalProductExist($cartList): bool
    {
        $cartIDs = [];
        $isDigitalProduct = false;
        foreach ($cartList as $cart) {
            if (is_array($cart)) {
                $cartIDs = [$cart['id']];
            }
        }

        if (!empty($cartIDs) && Product::whereIn('id', $cartIDs)->where(['product_type' => 'digital'])->count() > 0) {
            $isDigitalProduct = true;
        }
        return $isDigitalProduct;
    }

    public function place_order(Request $request): JsonResponse
    {
        $seller = $request->seller;

        $customerId = $request['customer_id'];
        $carts = $request['cart'];
        $extraDiscount = $request['extra_discount'];
        $extraDiscountType = $request['extra_discount_type'];
        $couponDiscountAmount = $request['coupon_discount_amount'];
        $couponCode = $request['coupon_code'];
        $paymentMethod = $request['payment_method'];
        $paidAmount = currencyConverter(amount: $request['paid_amount'] ?? 0);
        // Same lifecycle contract as the panel POS: a delivery-fulfillment sale
        // starts pending and, when paid in cash, is stored as a COD/unpaid
        // order settled through the standard status machinery.
        $fulfillment = $request['fulfillment'] == 'delivery' ? 'delivery' : 'instant';
        $isCashOnDelivery = $fulfillment == 'delivery' && $paymentMethod == 'cash';

        $taxConfig = self::getTaxSystemType();

        if ($customerId == 0 && $fulfillment == 'delivery') {
            return response()->json([
                'checkProductTypeForWalkingCustomer' => true,
                'message' => translate('To_place_a_delivery_order') . ',' . translate('_kindly_select_a_customer_or_fill_up_the_“Add_New_Customer”_form') . '.'
            ]);
        }

        $isDigitalProduct = self::isDigitalProductExist(cartList: $carts);
        if ($customerId == 0 && $isDigitalProduct) {
            return response()->json([
                'checkProductTypeForWalkingCustomer' => true,
                'message' => translate('To_order_digital_product') . ',' . translate('_kindly_fill_up_the_“Add_New_Customer”_form') . '.'
            ]);
        }

        $deliveryShippingAddress = $fulfillment == 'delivery' ? $this->orderAddresses->latestSavedAddress(customerId: $customerId) : null;
        if ($deliveryShippingAddress) {
            try {
                $this->addressGuard->assertUsable(address: $deliveryShippingAddress, channel: AddressChannel::Api);
            } catch (ValidationException $exception) {
                return response()->json(['errors' => Helpers::validationErrorProcessor($exception->validator)], 403);
            }
        }

        // One sale, one transaction. Before this, the wallet was debited and every line's stock was taken
        // outside any transaction and BEFORE the lines were checked, so a line the shelf could not cover
        // returned `product_not_available` with the customer's money already gone; and a request carrying no
        // `modified_cart` at all skipped the whole line block and still wrote an order row — an order with no
        // lines, `order_amount` 0 and `paid_amount` whatever was posted. Both panel tills were given this
        // shape in the same branch; this is the third till, the one the seller app uses.
        $cartLines = collect($request['modified_cart'] ?? [])
            ->filter(static fn($cartItem): bool => is_array($cartItem))
            ->values();

        if ($cartLines->isEmpty()) {
            return response()->json([
                'product_not_available' => true,
                'message' => translate('a_sale_needs_at_least_one_product'),
            ]);
        }

        try {
            $generateOrderID = OrderManager::writeOrderRetryingOnDuplicateId(function () use (
                $request,
                $cartLines,
                $carts,
                $taxConfig,
                $seller,
                $customerId,
                $paymentMethod,
                $paidAmount,
                $fulfillment,
                $isCashOnDelivery,
                $extraDiscount,
                $extraDiscountType,
                $couponDiscountAmount,
                $couponCode,
                $deliveryShippingAddress
            ) {
                // Every product of the sale is locked here, in ascending id order, before a line is priced or
                // taken and before the customer's wallet is touched. InventoryService::lockProductsForSale
                // says why the order is the point.
                $this->inventoryService->lockProductsForSale(productIds: $cartLines->pluck('id')->all());

                if ($paymentMethod == 'wallet' && $customerId != 0) {
                    $customerBalance = $this->customerRepo->getFirstWhere(params: ['id' => $customerId]) ?? 0;
                    if ($customerBalance['wallet_balance'] < $paidAmount) {
                        throw new WalletDebitRefusedException(
                            customerId: $customerId,
                            amount: (float)$paidAmount,
                            reason: WalletDebitRefusedException::REASON_INSUFFICIENT_BALANCE,
                        );
                    }

                    // The balance is now read and spent under the same lock, and a debit that did not happen
                    // refuses the sale: createWalletTransaction returns false when the wallet add-on is off or
                    // when its own write fails, which used to leave the order stored as paid with nothing taken.
                    if (!$this->createWalletTransaction(user_id: $customerId, amount: floatval($paidAmount), transaction_type: 'order_place', reference: 'order_place_in_pos')) {
                        throw new WalletDebitRefusedException(
                            customerId: $customerId,
                            amount: (float)$paidAmount,
                            reason: WalletDebitRefusedException::REASON_WRITE_REFUSED,
                        );
                    }
                }

                $cartsTotalAmount = 0;
                $generateOrderID = self::getOrderNewId();

                foreach ($cartLines as $cartItem) {
                    $product = Product::where(['id' => $cartItem['id']])->with(['digitalVariation', 'clearanceSale' => function ($query) {
                        return $query->active();
                    }])->withCount('reviews')->first();

                    // A line naming a product that is not there used to be skipped silently by the second
                    // loop, which is the other way this endpoint wrote an order with fewer lines than the
                    // cashier sold — or none at all.
                    if (!$product) {
                        throw new InsufficientStockException(
                            productId: $cartItem['id'] ?? null,
                            requested: (int)($cartItem['quantity'] ?? 0),
                        );
                    }

                    $getProductArray = self::getOrderDetailsAddData(cartItem: $cartItem, product: $product);
                    $cartsTotalAmount += $cartItem['discounted_price'] * $cartItem['quantity'];
                    $cartsTotalAmount += $cartItem['applied_tax_amount'] * $cartItem['quantity'];
                    $cartsTotalAmount -= $cartItem['coupon_discount'] ?? 0;
                    $cartsTotalAmount -= $cartItem['extra_discount'] ?? 0;

                    $orderDetailsData = [
                        'order_id' => $generateOrderID,
                        'product_id' => $cartItem['id'],
                        'product_details' => $getProductArray['product'],
                        'qty' => $cartItem['quantity'],
                        'price' => $cartItem['price'],
                        'seller_id' => $product['user_id'],
                        'tax' => $cartItem['applied_tax_amount'] * $cartItem['quantity'],
                        'tax_model' => $taxConfig['is_included'] ? 'include' : 'exclude',
                        'discount' => $cartItem['discount'] * $cartItem['quantity'],
                        'discount_type' => 'discount_on_product',
                        'delivery_status' => $fulfillment == 'delivery' ? 'pending' : 'delivered',
                        'payment_status' => $isCashOnDelivery ? 'unpaid' : 'paid',
                        'variant' => $getProductArray['variant'],
                        'variation' => json_encode($cartItem['variation']),
                        'created_at' => now(),
                        'updated_at' => now()
                    ];

                    // The shelf, the variant and the movement row in one call, under the lock taken above, by
                    // the rule the web checkout and both panel tills follow. This till used to floor the
                    // shortfall at zero and sell anyway, and floored the variant with max(0, ...) as well, so
                    // the variant quantities stopped summing to current_stock.
                    if ($product['product_type'] == 'physical') {
                        $this->inventoryService->takeForSale(
                            productId: $product['id'],
                            quantity: (int)$cartItem['quantity'],
                            variant: $cartItem['variant'],
                            referenceType: 'pos_order',
                            referenceId: $generateOrderID,
                        );
                    }

                    DB::table('order_details')->insert($orderDetailsData);
                }

                $deliveryShippingResponsibility = $fulfillment == 'delivery' ? getWebConfig(name: 'shipping_method') : null;
                $deliveryAddressSnapshot = $this->orderAddresses->snapshot(address: $deliveryShippingAddress);

                $orderData = [
                    'id' => $generateOrderID,
                    'customer_id' => $customerId,
                    'customer_type' => 'customer',
                    'payment_status' => $isCashOnDelivery ? 'unpaid' : 'paid',
                    'order_status' => $fulfillment == 'delivery' ? 'pending' : 'delivered',
                    'seller_id' => $seller->id,
                    'seller_is' => 'seller',
                    'payment_method' => $isCashOnDelivery ? 'cash_on_delivery' : $paymentMethod,
                    'order_type' => 'POS',
                    'shipping_address' => $deliveryShippingAddress?->id,
                    'shipping_address_data' => $deliveryAddressSnapshot ? json_encode($deliveryAddressSnapshot) : null,
                    'shipping_responsibility' => $deliveryShippingResponsibility,
                    'verification_code' => $fulfillment == 'delivery' ? rand(100000, 999999) : '0',
                    'checked' => 1,
                    'extra_discount' => $extraDiscount ?? 0,
                    'extra_discount_type' => $extraDiscountType ?? null,
                    'order_amount' => $cartsTotalAmount,
                    'init_order_amount' => $cartsTotalAmount,
                    'total_tax_amount' => $request['total_tax_amount'] ?? 0,
                    'tax_type' => $taxConfig['SystemTaxVatType'],
                    'paid_amount' => $isCashOnDelivery ? 0 : $paidAmount,
                    'discount_amount' => currencyConverter(amount: $couponDiscountAmount ?? 0),
                    'coupon_code' => $couponCode ?? null,
                    'discount_type' => (isset($carts['coupon_code']) && $carts['coupon_code']) ? 'coupon_discount' : NULL,
                    'coupon_discount_bearer' => $carts['coupon_bearer'] ?? 'inhouse',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                DB::table('orders')->insertGetId($orderData);

                return $generateOrderID;
            });
        } catch (InsufficientStockException $exception) {
            // The transaction has rolled back: no order, no order line, no stock taken and no wallet debit.
            // The response keeps the shape the seller app already reads, `product_not_available` with the
            // product's name, so no app build has to change for the refusal to be understood.
            Log::warning('Seller POS sale refused: ' . $exception->getMessage(), ['product_id' => $exception->productId, 'requested' => $exception->requested]);

            return response()->json([
                'product_not_available' => true,
                'message' => trim(($exception->productName ?? '') . ' ' . translate('not_available_for_order_place')),
            ]);
        } catch (WalletDebitRefusedException $exception) {
            Log::warning('Seller POS wallet sale refused: ' . $exception->getMessage(), ['customer_id' => $exception->customerId, 'reason' => $exception->reason]);

            return response()->json([
                'need_balance' => true,
                'message' => translate('need_Sufficient_Amount_Balance')
            ]);
        }

        if ($isDigitalProduct) {
            $order = Order::with(['details.productAllStatus', 'customer'])->find($generateOrderID);
            $data = [
                'userName' => $order->customer->f_name,
                'userType' => 'customer',
                'templateName' => 'digital-product-download',
                'order' => $order,
                'subject' => translate('download_Digital_Product'),
                'title' => translate('Congratulations') . '!',
                'emailId' => $order->customer['email'],
            ];
            event(new DigitalProductDownloadEvent(email: $order->customer['email'], data: $data));
        }
        return response()->json(['order_id' => $generateOrderID], 200);
    }

    public function get_invoice(Request $request):JsonResponse
    {
        $seller = $request->seller;
        $seller_pos = BusinessSetting::where('type', 'seller_pos')->first()->value;
        if ($seller->pos_status == 0 || $seller_pos == 0) {
            return response()->json(['message' => translate('access_denied!')], 403);
        }

        $orders = Order::with('details', 'shipping')->where(['seller_id' => $seller['id']])->find($request['id']);
        if ($orders) {
            foreach ($orders['details'] as $order) {
                $order['product_details'] = Helpers::product_data_formatting(json_decode($order['product_details'], true));
            }
        }

        return response()->json($orders, 200);
    }


}
