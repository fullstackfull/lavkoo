<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\DigitalProductVariationRepositoryInterface;
use App\Contracts\Repositories\PasswordResetRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\StorageRepositoryInterface;
use App\Enums\Address\AddressChannel;
use App\Events\CustomerRegistrationEvent;
use App\Events\DigitalProductDownloadEvent;
use App\Exceptions\PosSaleReplayedException;
use App\Exceptions\WalletDebitRefusedException;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\Address\CheckoutAddressGuard;
use App\Services\Address\OrderAddressService;
use App\Services\Marketplace\SellerMarketScope;
use App\Services\PasswordResetService;
use App\Services\Storefront\OrderStorefrontSnapshot;
use App\Services\Storefront\StorefrontCurrency;
use App\Models\User;
use App\Traits\API\v3\VendorPOSManagement;
use App\Traits\CustomerTrait;
use App\Utils\CartManager;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\TaxModule\app\Traits\VatTaxManagement;
use App\Services\Catalog\ProductCodeScope;
use App\Services\Catalog\ProductVariantSku;
use App\Exceptions\InsufficientStockException;
use App\Services\Marketplace\InventoryService;
use Illuminate\Support\Facades\Log;
use App\Utils\OrderManager;
use Illuminate\Support\Collection;

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
        private readonly SellerMarketScope                          $markets,
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

    /**
     * Takes the line's stock through InventoryService, which REFUSES rather than absorbing an over-sell.
     *
     * The three ways this till used to absorb one, all of them measured by tests/Feature/Pos/SellerAppTillStockTest:
     * a negative quantity satisfied `current_stock >= quantity`, so the conditional decrement ADDED stock and the
     * movement row logged the addition as a sale; a line the shelf could not cover set current_stock to 0 and sold
     * anyway; and a variant short of the line was floored at 0 while the parent's stock was still taken, so the
     * variant quantities stopped summing to current_stock. takeForSale throws InsufficientStockException for each,
     * which rolls the sale back and leaves the stock exactly as it was.
     */
    public function getProductStockCalculate($cartItem, $product, $orderId = null): void
    {
        if ($product['product_type'] != 'physical') {
            return;
        }

        app(InventoryService::class)->takeForSale(
            productId: $product['id'],
            quantity: (int) $cartItem['quantity'],
            variant: $cartItem['variant'],
            referenceType: 'pos_order',
            referenceId: $orderId,
        );
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
        $codeScope = app(ProductCodeScope::class);
        $requestedCountry = $request->query('country');
        if (!$codeScope->isScanCountryAllowed($requestedCountry)) {
            return response()->json(['errors' => [['code' => 'country', 'message' => translate('the_country_must_be_one_of_the_configured_storefront_countries')]]], 422);
        }

        $products = app(ProductVariantSku::class)->scan(
            ownedProducts: Product::withCount('reviews')->where([
                'added_by' => 'seller',
                'user_id' => $seller->id,
            ])->with(['clearanceSale' => function ($query) {
                return $query->active()->with(['setup']);
            }]),
            code: $request->code,
            country: $codeScope->countryOfScan($requestedCountry),
        );
        if ($products->count() > 1) {
            return $this->ambiguousCodeResponse(products: $products);
        }
        // Held to the markets the token was granted (K-09): the till is a way into the catalogue like any other, and a barcode
        // is a product id by another name.
        $product = $this->markets->narrowQuery(Product::withCount('reviews')->where([
            'added_by' => 'seller',
            'user_id' => $seller->id,
            'code' => $request->code
        ]))->with(['clearanceSale' => function ($query) {
            return $query->active()->with(['setup']);
        }])->first();

        $final_product = array();
        if ($products->isNotEmpty()) {
            $final_product = Helpers::product_data_formatting($products->first(), false);
        }

        return response()->json($final_product, 200);
    }

    /** A scanned code that more than one product answers to is never settled by picking one: the app is told which products it could be. */
    private function ambiguousCodeResponse(Collection $products): JsonResponse
    {
        return response()->json(['errors' => [[
            'code' => 'ambiguous_code',
            'message' => translate('the_scanned_code_matches_more_than_one_product'),
            'candidates' => $products->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'country' => $product->storefront_country])->values()->all(),
        ]]], 409);
    }

    public function product_list(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $search = $request['name'];
        $categoryIds = $request->has('category_id') && $request['category_id'] != 0 ? json_decode($request['category_id'], true) : [];

        // Held to the markets the token was granted, as the product list is (K-09). Without it the till served a product of a
        // market the token could not read anywhere else, in full, price and stock included.
        $products = $this->markets->narrowQuery(Product::active()->with(['digitalVariation', 'clearanceSale' => function ($query) {
            return $query->active();
        }])
            ->withCount('reviews')
            ->where(['added_by' => 'seller', 'user_id' => $seller['id'], 'status' => 1]))
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

    public function isDigitalProductExist($cartList, int|string $sellerId): bool
    {
        $cartIDs = [];
        $isDigitalProduct = false;
        foreach ($cartList as $cart) {
            if (is_array($cart)) {
                $cartIDs = [$cart['id']];
            }
        }

        if (!empty($cartIDs) && Product::ofSeller(sellerId: $sellerId)->whereIn('id', $cartIDs)->where(['product_type' => 'digital'])->count() > 0) {
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
        $paymentMethodValidator = Validator::make($request->all(), ['payment_method' => 'nullable|string']);
        if ($paymentMethodValidator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($paymentMethodValidator)], 422);
        }
        $paymentMethod = is_string($request['payment_method']) ? strtolower(trim($request['payment_method'])) : null;
        $tenderedAmount = floatval($request['paid_amount'] ?? 0);
        // Same lifecycle contract as the panel POS: a delivery-fulfillment sale
        // starts pending and, when paid in cash, is stored as a COD/unpaid
        // order settled through the standard status machinery.
        $fulfillment = $request['fulfillment'] == 'delivery' ? 'delivery' : 'instant';
        $isCashOnDelivery = $fulfillment == 'delivery' && $paymentMethod === 'cash';

        if ($paymentMethod === 'wallet' && $customerId == 0) {
            return response()->json(['errors' => [
                ['code' => 'customer_id', 'message' => translate('select_customer_is_required')],
            ]], 403);
        }

        $taxConfig = self::getTaxSystemType();

        if ($customerId == 0 && $fulfillment == 'delivery') {
            return response()->json([
                'checkProductTypeForWalkingCustomer' => true,
                'message' => translate('To_place_a_delivery_order') . ',' . translate('_kindly_select_a_customer_or_fill_up_the_“Add_New_Customer”_form') . '.'
            ]);
        }

        $isDigitalProduct = self::isDigitalProductExist(cartList: $carts, sellerId: $seller->id);
        if ($customerId == 0 && $isDigitalProduct) {
            return response()->json([
                'checkProductTypeForWalkingCustomer' => true,
                'message' => translate('To_order_digital_product') . ',' . translate('_kindly_fill_up_the_“Add_New_Customer”_form') . '.'
            ]);
        }

        if ($unavailableProductResponse = $this->unavailableProductResponse(modifiedCart: $request['modified_cart'], sellerId: $seller->id)) {
            return $unavailableProductResponse;
        }

        // A sale with no lines was written anyway: the id was taken, the line block below was skipped and an order row was stored with
        // no lines, order_amount 0 and paid_amount whatever was posted. Refused here, before anything is taken or written.
        if (collect($request['modified_cart'] ?? [])->filter(static fn ($cartItem): bool => is_array($cartItem))->isEmpty()) {
            return response()->json([
                'product_not_available' => true,
                'message' => translate('a_sale_needs_at_least_one_product'),
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

        $idempotencyKey = $this->idempotencyKeyOf(request: $request);
        if ($idempotencyKey !== null && strlen($idempotencyKey) > 191) {
            return response()->json(['message' => 'Idempotency-Key must be 191 characters or fewer.'], 422);
        }
        if ($idempotencyKey !== null && ($placedOrderId = $this->placedOrderIdOf(sellerId: $seller->id, key: $idempotencyKey)) !== null) {
            return response()->json(['order_id' => $placedOrderId], 200);
        }

        $orderTotal = self::cartsTotalAmount(modifiedCart: $request['modified_cart'] ?: []);
        if ($paymentMethod === 'wallet' && $orderTotal <= 0) {
            return response()->json(['errors' => [
                ['code' => 'paid_amount', 'message' => translate('You cannot place an order with an amount of zero. Please enter a valid amount.')],
            ]], 403);
        }
        $payByWallet = $paymentMethod === 'wallet' && $customerId != 0;
        if ($payByWallet) {
            $customerBalance = $this->customerRepo->getFirstWhere(params: ['id' => $customerId]) ?? 0;
            if ($customerBalance['wallet_balance'] < currencyConverter(amount: $orderTotal)) {
                return $this->walletBalanceNeededResponse();
            }
        }
        if ($paymentMethod == 'cash' && !$isCashOnDelivery && round($tenderedAmount, 6) < round($orderTotal, 6)) {
            return response()->json(['message' => translate('paid_amount_is_less_than_total_amount')], 403);
        }
        // What the ORDER records is a different question from what the WALLET is debited. The wallet holds one balance in the platform's own
        // money until the balances are per market (GCC-15), so that debit converts as it always did; the order's own amounts are converted
        // below, inside the money the till's lines trade in (D1-06). The server-computed total is still what a wallet sale is charged.
        $amountTendered = $payByWallet ? $orderTotal : $tenderedAmount;

        try {
            $generateOrderID = OrderManager::writeOrderRetryingOnDuplicateId(function () use ($request, $seller, $customerId, $carts, $extraDiscount, $extraDiscountType, $couponDiscountAmount, $couponCode, $paymentMethod, $orderTotal, $amountTendered, $fulfillment, $isCashOnDelivery, $taxConfig, $deliveryShippingAddress, $payByWallet, $idempotencyKey) {
                // Every product of the sale is locked here, in ascending id order, before a line is priced or taken and before the
                // customer's wallet is touched. InventoryService::lockProductsForSale says why the order is the point.
                app(InventoryService::class)->lockProductsForSale(productIds: collect($request['modified_cart'] ?? [])->pluck('id')->all());

                if ($idempotencyKey !== null && !$this->claimIdempotencyKey(sellerId: $seller->id, key: $idempotencyKey)) {
                    throw new PosSaleReplayedException(orderId: (int) $this->placedOrderIdOf(sellerId: $seller->id, key: $idempotencyKey, lock: true));
                }
                if ($payByWallet && $this->createWalletTransaction(user_id: $customerId, amount: floatval($orderTotal), transaction_type: 'order_place', reference: 'order_place_in_pos') === false) {
                    throw new WalletDebitRefusedException(customerId: $customerId);
                }

                $generateOrderID = self::getOrderNewId();

                if ($request['modified_cart']) {
                    // Ascending product id, as generateOrder locks: two sales never lock the same product rows in opposite orders.
                    foreach (collect($request['modified_cart'])->sortBy('id') as $cartItem) {
                        if (is_array($cartItem)) {
                            $product = $this->ownedCartProduct(cartItem: $cartItem, sellerId: $seller->id);
                            if ($product) {
                                $getProductArray = self::getOrderDetailsAddData(cartItem: $cartItem, product: $product);

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
                                self::getProductStockCalculate(cartItem: $cartItem, product: $product, orderId: $generateOrderID);
                                DB::table('order_details')->insert($orderDetailsData);
                            }
                        }
                    }
                }

                $deliveryShippingResponsibility = $fulfillment == 'delivery' ? getWebConfig(name: 'shipping_method') : null;
                $deliveryAddressSnapshot = $this->orderAddresses->snapshot(address: $deliveryShippingAddress);

                // The lines are already saved raw, in the money their storefront trades in, and the order is stamped from them below as
                // "every amount here is in that currency at rate one". These two were the only amounts converted into the platform's base
                // unit on the way in, so one order carried amounts in two units: a ten-dinar coupon was stored as 2.67 and a reader of the
                // order's own currency printed 2.666KD, a figure nobody was charged. Stored in the till's own money they are in the unit the
                // rest of the order is in. A till of the default storefront, and a shop with storefront currencies off, convert as before.
                $storefrontSnapshot = app(OrderStorefrontSnapshot::class);
                $tillMoney = app(StorefrontCurrency::class)->within(
                    country: $storefrontSnapshot->countryOfMoney(orderId: $generateOrderID),
                    callback: fn (): array => [
                        'paid_amount' => currencyConverter(amount: $amountTendered),
                        'discount_amount' => currencyConverter(amount: $couponDiscountAmount ?? 0),
                    ],
                );

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
                    'verification_code' => $fulfillment == 'delivery' ? random_int(100000, 999999) : '0',
                    'checked' => 1,
                    'extra_discount' => $extraDiscount ?? 0,
                    'extra_discount_type' => $extraDiscountType ?? null,
                    'order_amount' => $orderTotal,
                    'init_order_amount' => $orderTotal,
                    'total_tax_amount' => $request['total_tax_amount'] ?? 0,
                    'tax_type' => $taxConfig['SystemTaxVatType'],
                    'paid_amount' => $isCashOnDelivery ? 0 : $tillMoney['paid_amount'],
                    'discount_amount' => $tillMoney['discount_amount'],
                    'coupon_code' => $couponCode ?? null,
                    'discount_type' => (isset($carts['coupon_code']) && $carts['coupon_code']) ? 'coupon_discount' : NULL,
                    'coupon_discount_bearer' => $carts['coupon_bearer'] ?? 'inhouse',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                DB::table('orders')->insertGetId($orderData);
                // The till writes its lines before the order row exists, so the order is stamped from them here and the lines take that
                // currency afterwards. Asked any earlier, a line would read an order that is not there yet and record nothing.
                $storefrontSnapshot->record(orderId: $generateOrderID);
                $storefrontSnapshot->stampMoneyRows(table: 'order_details', orderId: $generateOrderID);
                if ($idempotencyKey !== null) {
                    $this->recordIdempotencyKeyOrder(sellerId: $seller->id, key: $idempotencyKey, orderId: $generateOrderID);
                }

                return $generateOrderID;
            });
        } catch (InsufficientStockException $exception) {
            // The transaction has rolled back: no order, no order line, no stock taken and no wallet debit. The response keeps
            // the shape the seller app already reads, so no app build has to change for the refusal to be understood.
            Log::warning('Seller POS sale refused: ' . $exception->getMessage(), ['product_id' => $exception->productId, 'requested' => $exception->requested]);

            return response()->json([
                'product_not_available' => true,
                'message' => trim(($exception->productName ?? '') . ' ' . translate('not_available_for_order_place')),
            ]);
        } catch (WalletDebitRefusedException) {
            return $this->walletBalanceNeededResponse();
        } catch (PosSaleReplayedException $exception) {
            return response()->json(['order_id' => $exception->orderId], 200);
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

    private function unavailableProductResponse(mixed $modifiedCart, int|string $sellerId): ?JsonResponse
    {
        foreach ($modifiedCart ?: [] as $cartItem) {
            if (!is_array($cartItem)) {
                continue;
            }

            $product = $this->ownedCartProduct(cartItem: $cartItem, sellerId: $sellerId);
            if (!$product || $cartItem['quantity'] > $product['current_stock']) {
                return response()->json([
                    'product_not_available' => true,
                    'message' => trim(($product['name'] ?? '') . ' ' . translate('not_available_for_order_place'))
                ]);
            }
        }

        return null;
    }

    private function ownedCartProduct(array $cartItem, int|string $sellerId): ?Product
    {
        return Product::ofSeller(sellerId: $sellerId)->where(['id' => $cartItem['id']])->with(['digitalVariation', 'clearanceSale' => function ($query) {
            return $query->active();
        }])->withCount('reviews')->first();
    }

    /** The total of the seller app's lines in the store currency. The sale is charged this, not the amount the client says it was paid. */
    private static function cartsTotalAmount(array $modifiedCart): float
    {
        $total = 0;
        foreach (collect($modifiedCart)->sortBy('id') as $cartItem) {
            if (is_array($cartItem)) {
                $total += $cartItem['discounted_price'] * $cartItem['quantity'];
                $total += $cartItem['applied_tax_amount'] * $cartItem['quantity'];
                $total -= $cartItem['coupon_discount'] ?? 0;
                $total -= $cartItem['extra_discount'] ?? 0;
            }
        }

        return $total;
    }

    private function idempotencyKeyOf(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        return $key === '' ? null : $key;
    }

    private function placedOrderIdOf(int $sellerId, string $key, bool $lock = false): ?int
    {
        $sale = DB::table('pos_sale_idempotency_keys')->where(['seller_id' => $sellerId, 'idempotency_key' => $key]);
        $orderId = $lock ? $sale->lockForUpdate()->value('order_id') : $sale->value('order_id');

        return $orderId === null ? null : (int) $orderId;
    }

    /** False when a sale already holds the key, whether that sale has been written or another request is still writing it. */
    private function claimIdempotencyKey(int $sellerId, string $key): bool
    {
        return DB::table('pos_sale_idempotency_keys')->insertOrIgnore([
            'seller_id' => $sellerId, 'idempotency_key' => $key, 'order_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]) === 1;
    }

    private function recordIdempotencyKeyOrder(int $sellerId, string $key, int $orderId): void
    {
        DB::table('pos_sale_idempotency_keys')->where(['seller_id' => $sellerId, 'idempotency_key' => $key])->update(['order_id' => $orderId, 'updated_at' => now()]);
    }

    private function walletBalanceNeededResponse(): JsonResponse
    {
        return response()->json([
            'need_balance' => true,
            'message' => translate('need_Sufficient_Amount_Balance')
        ]);
    }

    public function get_invoice(Request $request):JsonResponse
    {
        $seller = $request->seller;
        $seller_pos = BusinessSetting::where('type', 'seller_pos')->first()->value;
        if ($seller->pos_status == 0 || $seller_pos == 0) {
            return response()->json(['message' => translate('access_denied!')], 403);
        }

        $orders = Order::with('details', 'shipping')->where(['seller_id' => $seller['id'], 'seller_is' => 'seller'])->find($request['id']);
        // One order read by its id, bound like the order list (K-09): a till receipt is an order detail by another name.
        $orders = $this->markets->narrowOrders(Order::with('details', 'shipping')->where(['seller_id' => $seller['id']]))
            ->find($request['id']);
        if ($orders) {
            foreach ($orders['details'] as $order) {
                $order['product_details'] = Helpers::product_data_formatting(json_decode($order['product_details'], true));
            }
        }

        return response()->json($orders, 200);
    }


}
