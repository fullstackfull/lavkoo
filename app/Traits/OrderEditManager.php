<?php

namespace App\Traits;

use App\Events\OrderEditDuePaymentEvent;
use App\Events\OrderEditEvent;
use App\Exceptions\WalletDebitRefusedException;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\AdminWallet;
use App\Models\Cart;
use App\Models\CartShipping;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderDetailsRewards;
use App\Models\OrderEditHistory;
use App\Models\OrderTransaction;
use App\Models\Product;
use App\Models\Seller;
use App\Models\ShippingMethod;
use App\Models\ShippingType;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentOrderGuard;
use App\Services\Marketplace\RestockBudget;
use App\Services\OrderEditService;
use App\Services\Payments\GatewayCurrencySupport;
use App\Services\Storefront\LedgerOrigin;
use App\Services\Storefront\OrderStorefrontSnapshot;
use App\Services\Storefront\StorefrontCurrency;
use App\Utils\CartManager;
use App\Utils\Helpers;
use App\Utils\OrderManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\TaxModule\app\Models\OrderTax;
use Modules\TaxModule\app\Traits\VatTaxManagement;
use App\Utils\CustomerManager;

trait OrderEditManager
{
    use VatTaxManagement, Payment, PaymentGatewayTrait;

    public static function calculateTotalCouponAmount(object|array $order = [], object|array $editedOrder = [], object|array|null $coupon = null): array
    {
        $onlyProductTotalAmount = 0;
        foreach ($editedOrder as $editedItem) {
            $onlyProductTotalAmount += ($editedItem['price'] - $editedItem['discount']) * $editedItem['qty'];
        }

        if (!$coupon || $order['is_guest']) {
            return [
                'status' => false,
                'discount' => 0,
                'coupon_type' => null,
                'coupon_code' => null,
                'coupon_bearer' => null,
                'total_cart_amount' => $onlyProductTotalAmount,
            ];
        }

        $discount = 0;
        $couponDiscount = $coupon?->discount ?? 0;

        if ($coupon?->coupon_type == 'first_order' || $coupon?->coupon_type == 'discount_on_purchase') {
            if (!($onlyProductTotalAmount <= 0 || $couponDiscount > $onlyProductTotalAmount || $coupon['min_purchase'] > $onlyProductTotalAmount)) {
                if ($coupon->discount_type == 'percentage') {
                    $discountAmount = ($onlyProductTotalAmount * $couponDiscount) / 100;
                    $discount = min($discountAmount, $coupon['max_discount']);
                } else {
                    $discount = $couponDiscount;
                }
            }
        } elseif ($coupon?->coupon_type == 'free_delivery') {
            $discount = self::getEditOrderTotalShippingCost(order: $order, editedOrder: $editedOrder);
        }

        if ($discount > 0) {
            return [
                'status' => true,
                'discount' => $discount,
                'coupon_type' => $coupon?->coupon_type,
                'coupon_code' => $coupon?->code,
                'coupon_bearer' => $coupon?->coupon_bearer,
                'total_cart_amount' => $onlyProductTotalAmount,
            ];
        }

        return [
            'status' => false,
            'discount' => 0,
            'coupon_type' => null,
            'coupon_code' => null,
            'coupon_bearer' => null,
            'total_cart_amount' => $onlyProductTotalAmount,
        ];
    }

    public static function checkFreeDeliveryOrderAmountArray(object|array $request = [], object|array $order = [], object|array $editedOrder = [], object|array $data = []): array
    {
        return app(StorefrontCurrency::class)->withinOrder(order: $order, callback: fn (): array => self::freeDeliveryOrderAmountArrayInOrderMoney(request: $request, order: $order, editedOrder: $editedOrder, data: $data));
    }

    private static function freeDeliveryOrderAmountArrayInOrderMoney(object|array $request = [], object|array $order = [], object|array $editedOrder = [], object|array $data = []): array
    {
        $freeDeliveryData = [
            'amount' => 0, // free delivery amount
            'percentage' => 0, // completed percentage
            'amount_need' => 0, // need amount for free delivery
            'shipping_cost_saved' => 0,
        ];

        $freeDeliveryData['status'] = (int)(getWebConfig(name: 'free_delivery_status') ?? 0);
        $freeDeliveryData['responsibility'] = (string)getWebConfig(name: 'free_delivery_responsibility');
        $freeDeliveryOverAmount = (float)getWebConfig(name: 'free_delivery_over_amount');
        $freeDeliveryOverAmountSeller = (float)getWebConfig(name: 'free_delivery_over_amount_seller');

        if ($freeDeliveryData['status'] && !empty($editedOrder)) {
            if ($order['seller_is'] == 'admin') {
                $freeDeliveryData['amount'] = $freeDeliveryOverAmount;
                $freeDeliveryData['status'] = $freeDeliveryOverAmount > 0 ? 1 : 0;
            } else {
                $seller = Seller::where('id', $order['seller_id'])->first();
                $freeDeliveryData['status'] = $seller->free_delivery_status ?? 0;

                if ($freeDeliveryData['responsibility'] == 'admin') {
                    $freeDeliveryData['amount'] = $freeDeliveryOverAmountSeller;
                    $freeDeliveryData['status'] = $freeDeliveryOverAmountSeller > 0 ? 1 : 0;
                }

                if ($freeDeliveryData['responsibility'] == 'seller' && $freeDeliveryData['status'] == 1) {
                    $freeDeliveryData['amount'] = $seller->free_delivery_over_amount;
                    $freeDeliveryData['status'] = $seller->free_delivery_over_amount > 0 ? 1 : 0;
                }
            }

            $amount = self::calculateGrandTotalWithoutShippingCharge(editedOrder: $editedOrder);
            $freeDeliveryData['amount_need'] = $freeDeliveryData['amount'] - $amount;
            $freeDeliveryData['percentage'] = ($freeDeliveryData['amount'] > 0) && $amount > 0 && ($freeDeliveryData['amount'] >= $amount) ? number_format(($amount / $freeDeliveryData['amount']) * 100) : 100;
            if ($freeDeliveryData['status'] == 1 && $freeDeliveryData['percentage'] == 100) {
                $freeDeliveryData['shipping_cost_saved'] = self::getEditOrderTotalShippingCost(order: $order, editedOrder: $editedOrder);
            }
        }

        return $freeDeliveryData;
    }

    public static function calculateGrandTotalWithoutShippingCharge($editedOrder): float|int
    {
        $total = 0;
        if (!empty($editedOrder)) {
            foreach ($editedOrder as $item) {
                $total += ($item['price'] - $item['discount']) * $item['qty'];
            }
        }
        return $total;
    }

    public static function getEditOrderTotalShippingCost($order, $editedOrder): float|int
    {
        return app(StorefrontCurrency::class)->withinOrder(order: $order, callback: fn (): float|int => self::editOrderTotalShippingCostInOrderMoney(order: $order, editedOrder: $editedOrder));
    }

    private static function editOrderTotalShippingCostInOrderMoney($order, $editedOrder): float|int
    {
        $shippingMethod = getWebConfig(name: 'shipping_method');
        $adminShipping = ShippingType::where('seller_id', 0)->first();

        $shippingType = '';
        if ($shippingMethod == 'inhouse_shipping') {
            $shippingType = isset($adminShipping) ? $adminShipping->shipping_type : 'order_wise';
        } else if ($shippingMethod == 'sellerwise_shipping') {
            if ($order['seller_is'] == 'admin') {
                $shippingType = isset($adminShipping) ? $adminShipping->shipping_type : 'order_wise';
            } else {
                $sellerShipping = ShippingType::where('seller_id', $order['seller_id'])->first();
                $shippingType = isset($sellerShipping) ? $sellerShipping->shipping_type : 'order_wise';
            }
        }

        $shippingCost = 0;
        if ($shippingType == 'order_wise') {
            $shippingCost = ShippingMethod::where('id', $order['shipping_method_id'])->first()?->cost ?? $order['shipping_cost'];
        } else {
            foreach ($editedOrder as $item) {
                $shippingCost += $item['product_type'] == 'physical' ? CartManager::get_shipping_cost_for_product_category_wise((is_array($item['active_product']) ? collect($item['active_product']) : $item['active_product']), $item['qty']) : 0;
            }
        }
        return $shippingCost;
    }


    public function addAndUpdateProductsInOrderDetails(object|array $order = [], object|array $editedOrder = [], object|array $taxSummary = []): void
    {
        if (empty($order) || empty($editedOrder)) {
            return;
        }
        $this->lockTheLinesThisEditGivesBack($order);
        foreach (collect($order->details ?? []) as $detail) {
            $this->adjustProductStock($detail['product_id'] ?? null, $detail['variant'] ?? null, $this->unitsThisEditMayGiveBack($detail), 'increment');
        }
        // A line without a product is kept as it is, with its rewards: it is never written, and it counts in the total the rewards are shared over.
        $linesWithoutProduct = collect($order->details ?? [])->filter(fn ($detail) => empty($detail['product_id']));
        $keepIds = $linesWithoutProduct->pluck('id')->all();
        $totalPrice = $linesWithoutProduct->sum(fn ($detail) => $detail['price'] * $detail['qty'] - $detail['discount']);
        $rewardsData = [];
        $taxList = collect($taxSummary['applied_tax_cart_list'] ?? []);
        $now = Carbon::now();

        foreach ($editedOrder as $item) {
            $productId = $item['product_id'] ?? null;
            $variant = $item['variant'] ?? null;

            if (!$productId) {continue;}

            $appliedTaxAmount = $taxList
                ->where('product_id', $productId)
                ->firstWhere('variant', $variant)
                ?->applied_tax_amount ?? 0;

            $detail = OrderDetail::updateOrCreate(
                [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'variant' => $variant,
                ],
                [
                    'seller_id' => $item['seller_id'] ?? null,
                    'digital_file_after_sell' => $item['digital_file_after_sell'] ?? null,
                    'product_details' => $item['product_details'] ?? null,
                    'qty' => $item['qty'],
                    'price' => $item['price'],
                    'tax' => $appliedTaxAmount,
                    'discount' => ($item['discount'] ?? 0) * ($item['qty'] ?? 0),
                    'tax_model' => $item['tax_model'] ?? 'exclude',
                    'delivery_status' => $item['delivery_status'] ?? 'pending',
                    'payment_status' => $item['payment_status'] ?? 'unpaid',
                    'shipping_method_id' => $item['shipping_method_id'] ?? null,
                    'variation' => $item['variation'] ?? null,
                    'discount_type' => $item['discount_type'] ?? null,
                    'is_stock_decreased' => $item['is_stock_decreased'] ?? 1,
                    'refund_request' => $item['refund_request'] ?? 0,
                    'refund_started_at' => $item['refund_started_at'] ?? $now,
                    'updated_at' => $now,
                ]
            );

            $keepIds[] = $detail->id;
            $finalAmount = (($item['price'] ?? 0) - ($item['discount'] ?? 0)) * ($item['qty'] ?? 0);
            $totalPrice += $finalAmount;

            $rewardsData[] = [
                'order_id' => $order->id,
                'product_id' => $productId,
                'order_details_id' => $detail->id,
                'price' => $finalAmount,
                'coupon_code' => $order?->coupon_code ?? null,
                'coupon_discount' => $order?->discount_amount ?? 0,
            ];
        }

        if (!empty($keepIds)) {
            OrderDetailsRewards::where('order_id', $order->id)->whereNotIn('order_details_id', $keepIds)->delete();
            OrderDetail::where('order_id', $order->id)->whereNotIn('id', $keepIds)->delete();
        }

        // An edit adds lines to an order that is already stamped, and updateOrCreate writes no currency on a
        // line it creates: an edited order ended with some lines naming the currency the order was sold in and
        // some naming nothing. The order's own record is the source here as everywhere else, and only a line
        // that carries no currency yet is written, so the lines the edit kept are left exactly as they are.
        app(OrderStorefrontSnapshot::class)->stampMoneyRows(table: 'order_details', orderId: $order->id);

        foreach (OrderDetail::where('order_id', $order->id)->get() as $detail) {
            $this->adjustProductStock($detail['product_id'] ?? null, $detail['variant'] ?? null, (int)($detail['qty'] ?? 0), 'decrement');
        }
        $this->syncOrderDetailsRewards(rewardsData: $rewardsData, totalPrice: $totalPrice);
    }

    private function syncOrderDetailsRewards(array $rewardsData, float $totalPrice): void
    {
        if (!empty($rewardsData)) {
            foreach ($rewardsData as $reward) {
                OrderManager::addOrderDetailsRewardData(
                    orderId: $reward['order_id'],
                    productId: $reward['product_id'],
                    orderDetailsId: $reward['order_details_id'],
                    price: $reward['price'],
                    totalPrice: $totalPrice,
                    couponCode: $reward['coupon_code'],
                    couponDiscount: $reward['coupon_discount'],
                );
            }
        }
    }

    /**
     * The order's lines, locked for the rest of the edit's transaction before any budget is read.
     *
     * The other three writers of this shelf lock the line they are about to spend from before they read the
     * budget, so a return receipt cannot spend the same units between the reading and the write; this one
     * read the budget unlocked, so a receipt racing an edit could put the same unit back twice.
     *
     * ALL of them, in id order, and before the first product row is locked: `adjustProductStock` holds each
     * product lock until the edit's own transaction commits, so locking a line after a product would let an
     * edit hold a product another writer wants while waiting for a line that writer holds. Taking every line
     * first means this writer never waits for a line while holding a product, which is what rules the cycle
     * out rather than making it unlikely.
     */
    private function lockTheLinesThisEditGivesBack(object|array $order): void
    {
        $lineIds = collect($order->details ?? [])->pluck('id')->filter()->sort()->values()->all();

        if ($lineIds === []) {
            return;
        }

        DB::table('order_details')->whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * How many of a line's units this edit may put back on the shelf.
     *
     * The fourth writer of an order line's stock, reached from the order edit screen by eight controllers.
     * It gave every current line's units back unconditionally before rebuilding the lines, so a line whose
     * goods had already come back was given back a second time; then it asked a question of its own, "is the
     * line's claim still taken?", which a PARTIAL return answers 1 -- only the receipt that completes a line
     * takes the claim -- so all three units of a line with one unit already back were put on the shelf.
     *
     * Two questions, and each writer of this shelf asks both the same way: `is_stock_decreased` is the CLAIM
     * (0 means the line's units are on the shelf already -- a received return, a cancellation, a restore by
     * the order status writer -- and giving them back again puts units there that never left), and
     * RestockBudget is how many of the claimed units are still off it. Re-taking, below, is unbounded on
     * purpose: an edited line is live for all of its units again, which is OrderManager's rule too.
     */
    private function unitsThisEditMayGiveBack(object|array $detail): int
    {
        if ((int)($detail['is_stock_decreased'] ?? 1) !== 1) {
            return 0;
        }

        return app(RestockBudget::class)->unspentUnitsOf(
            orderDetailsId: $detail['id'] ?? null,
            lineQuantity: $detail['qty'] ?? 0,
        );
    }

    private function adjustProductStock(?int $productId, ?string $variant, int $qty, string $mode): void
    {
        if (!$productId || !$qty) {return;}

        // Lock the product row and change stock atomically. The old unlocked read-modify-write
        // (current_stock +/- qty from a stale Product::find) lost concurrent updates when two order
        // edits touched the same product, and the decrement branch could drive stock negative.
        \Illuminate\Support\Facades\DB::transaction(function () use ($productId, $variant, $qty, $mode) {
            $product = Product::where('id', $productId)->lockForUpdate()->first();
            if (!$product) {return;}

            // The options keep their own keys: see OrderManager::getStockUpdateOnOrderStatusChange.
            $variationData = [];
            foreach (json_decode($product['variation'], true) ?? [] as $key => $var) {
                if ($variant !== null && $variant === ($var['type'] ?? null)) {
                    // Floored on the way down for the same reason current_stock is below: an out-of-band
                    // stock edit between the sale and the edit drove the variant's quantity negative.
                    $var['qty'] = $mode === 'increment' ? ($var['qty'] ?? 0) + $qty : max(0, ($var['qty'] ?? 0) - $qty);
                }
                $variationData[$key] = $var;
            }
            Product::where('id', $product['id'])->update(['variation' => json_encode($variationData)]);

            if ($mode === 'increment') {
                Product::where('id', $product['id'])->increment('current_stock', $qty);
            } else {
                $taken = Product::where('id', $product['id'])->where('current_stock', '>=', $qty)->decrement('current_stock', $qty);
                if ($taken === 0) {
                    Product::where('id', $product['id'])->update(['current_stock' => 0]);
                }
            }
        });
    }

    /** The pages and the routes that print the summary's total read it from a refused summary too: it names the total the order holds, since nothing was priced. */
    private function getRefusedEditOrderSummary(object|array $order, string $message): array
    {
        return ['status' => 'error', 'message' => $message, 'order_amount' => $order['order_amount'] ?? 0];
    }

    public function generateEditOrderSummary(object|array $request = [], object|array $order = [], object|array $editedOrder = [], object|array $data = []): array
    {
        if (!app(OrderEditService::class)->areEditedLinesAllowedOnOrder(order: $order, editedLines: $editedOrder)) {
            return $this->getRefusedEditOrderSummary(order: $order, message: translate('Product_is_unavailable'));
        }

        $linesWithoutProductRefusal = app(OrderEditService::class)->getRefusalOfLinesWithoutProduct(order: $order, editedLines: $editedOrder);
        if ($linesWithoutProductRefusal !== null) {
            return $this->getRefusedEditOrderSummary(order: $order, message: $linesWithoutProductRefusal);
        }

        $productList = Product::whereIn('id', collect($editedOrder)->pluck('product_id')->toArray())->get();

        foreach ($editedOrder as $details) {
            // A line without a product takes no stock: the edit keeps it as it is.
            if (empty($details['product_id'])) {
                continue;
            }

            $product = json_decode($details['product_details'] ?? '', true);
            $activeProduct = $productList?->firstWhere('id', $details['product_id']) ?? $product;
            $currentStock = max(0, $activeProduct['current_stock']);
            $variations = is_array($activeProduct['variation']) ? $activeProduct['variation'] : json_decode($activeProduct['variation'], true);
            $firstVariation = collect($variations)->first(function ($variation) use ($details) {
                return $variation['type'] == $details['variant'];
            });

            if ($details['variant'] && $firstVariation) {
                $currentStock = $firstVariation['qty'] ?? 0;
            }

            $checkDetails = collect($order?->details)?->where('product_id', $activeProduct['id'])->firstWhere('variant', $details['variant']);
            if ($checkDetails) {
                $currentStock += $checkDetails['qty'] ?? 1;
            }

            if ($currentStock <= 0) {
                return $this->getRefusedEditOrderSummary(order: $order, message: translate('Out of stock'));
            }
        }

        $coupon = Coupon::active()->with('seller')
            ->whereIn('customer_id', [$order?->customer_id, '0'])
            ->whereDate('start_date', '<=', date('Y-m-d'))
            ->whereDate('expire_date', '>=', date('Y-m-d'))
            ->when($order?->seller_is == 'admin', function ($query) use ($order) {
                return $query->whereNull('seller_id');
            })
            ->when($order?->seller_is == 'seller', function ($query) use ($order) {
                return $query->where('seller_id', $order?->seller_id);
            })
            ->where('code', $order?->coupon_code)->first();

        $shippingCost = self::getEditOrderTotalShippingCost(order: $order, editedOrder: $editedOrder);
        $couponSummary = self::calculateTotalCouponAmount(order: $order, editedOrder: $editedOrder, coupon: $coupon);
        $freeDeliveryOverAmount = self::checkFreeDeliveryOrderAmountArray(request: $request, order: $order, editedOrder: $editedOrder);
        $orderAmount = $couponSummary['total_cart_amount'] - $couponSummary['discount'] + $shippingCost;

        $isShippingFree = 0;
        if ($freeDeliveryOverAmount['status'] && $freeDeliveryOverAmount['shipping_cost_saved'] > 0 && $couponSummary['coupon_type'] != 'free_delivery') {
            $isShippingFree = 1;
            $orderAmount = ($orderAmount - $freeDeliveryOverAmount['shipping_cost_saved']);
        }

        $data += [
            'shipping_cost' => $shippingCost,
            'order_amount' => $orderAmount,
            'is_shipping_free' => $isShippingFree || ($couponSummary['coupon_type'] == 'free_delivery' ?? false),
            'coupon_discount' => $couponSummary['discount'] ?? 0,
            'refer_and_earn_discount' => $order['refer_and_earn_discount'] ?? 0,
            'free_delivery_discount' => $isShippingFree || ($couponSummary['coupon_type'] == 'free_delivery') ? $shippingCost : 0,
        ];

        $taxSummary = self::calculateEditOrderTaxAmount(order: $order, editedOrder: $editedOrder, couponSummary: $couponSummary, data: $data);

        $orderAmount += $taxSummary['total_tax_amount'];

        $orderDueAmount = 0;
        $orderReturnAmount = 0;
        $initOrderAmount = $order['order_amount'];

        if ($initOrderAmount > $orderAmount) {
            $orderReturnAmount = $initOrderAmount - $orderAmount;
        }

        if ($initOrderAmount < $orderAmount) {
            $orderDueAmount = $orderAmount - $initOrderAmount;
        }

        $taxConfig = self::getTaxSystemTypeOfOrder(order: $order);

        $adminCommission = (float)str_replace(",", "", Helpers::seller_sales_commission($order['seller_is'], $order['seller_id'], $couponSummary['total_cart_amount']));

        $orderArray = [
            'discount_amount' => $couponSummary['discount'] ?? 0,
            'discount_type' => ($couponSummary['discount'] ?? 0) <= 0 ? null : 'coupon_discount',
            'coupon_code' => $couponSummary['coupon_code'],
            'coupon_discount_bearer' => $couponSummary['coupon_bearer'],
            'tax_type' => $taxConfig['SystemTaxVatType'],
            'tax_model' => $taxConfig['is_included'] ? 'include' : 'exclude',
            'admin_commission' => $adminCommission,

            'edit_due_amount' => $orderDueAmount,
            'edit_return_amount' => $orderReturnAmount,
            'total_tax_amount' => $taxSummary['total_tax_amount'],

            'shipping_cost' => $shippingCost,
            'extra_discount' => $isShippingFree ? $freeDeliveryOverAmount['shipping_cost_saved'] : 0,
            'extra_discount_type' => $isShippingFree ? 'free_shipping_over_order_amount' : null,
            'free_delivery_bearer' => $isShippingFree ? $freeDeliveryOverAmount['responsibility'] : null,
            'is_shipping_free' => $isShippingFree,
            'edited_status' => 1,
            'updated_at' => Carbon::now(),
        ];

        if ($order['payment_method'] == 'cash_on_delivery') {
            $orderDueAmount = 0;
            $orderReturnAmount = 0;

            $orderArray['order_amount'] = $orderAmount;
            $orderArray['init_order_amount'] = $orderAmount;
            $orderArray['edit_due_amount'] = $orderDueAmount;
            $orderArray['edit_return_amount'] = $orderReturnAmount;
        }

        if (collect($editedOrder)->where('product_type', 'physical')->count() <= 0) {
            $orderArray['delivery_man_id'] = null;
            $orderArray['deliveryman_assigned_at'] = null;
            $orderArray['deliveryman_charge'] = 0;
        }

        return [
            'status' => 'success',
            'message' => translate('Order Amount Calculation Success'),
            'order_data' => $orderArray,
            'order_amount' => $orderAmount,
            'edit_due_amount' => $orderDueAmount,
            'order_return_amount' => $orderReturnAmount,
            'tax_summary' => $taxSummary,
            'coupon_summary' => $couponSummary,
        ];
    }

    public function checkEditOrderMinimumOrderAmount(object|array $order = [], int|float|null $totalCartAmount = 0, object|array $data = []): array
    {
        return app(StorefrontCurrency::class)->withinOrder(order: $order, callback: fn (): array => $this->minimumOrderAmountOfEditInOrderMoney(order: $order, totalCartAmount: $totalCartAmount, data: $data));
    }

    private function minimumOrderAmountOfEditInOrderMoney(object|array $order = [], int|float|null $totalCartAmount = 0, object|array $data = []): array
    {
        $status = 'success';
        $amount = 0;
        $message = translate('Minimum_Order_Amount_Requirement_Completed');
        $minimumOrderAmount = 0;

        if (getWebConfig(name: 'minimum_order_amount_status') ?? 0) {
            if ($order['seller_is'] == 'admin') {
                $minimumOrderAmount = getWebConfig(name: 'minimum_order_amount');
                $shopIdentity = getInHouseShopConfig(key: 'name');
            } else {
                $seller = Seller::with(['shop'])->where('id', $order['seller_id'])->first();
                $shopIdentity = $seller?->shop?->name ?? '';
                $minimumOrderAmount = (getWebConfig(name: 'minimum_order_amount_by_seller') ?? 0) ? ($seller?->minimum_order_amount ?? 0) : 0;
            }

            $status = $minimumOrderAmount > $totalCartAmount ? 'error' : 'success';
            $amount = $amount + $totalCartAmount;
            if ($minimumOrderAmount > $totalCartAmount) {
                $status = 'error';
                if (isset($data['order_request_from']) && $data['order_request_from'] == 'app') {
                    $message = translate('Please_complete_minimum_Order_Amount') . ' ' . translate('for') . ' ' . $shopIdentity;
                } elseif (isset($data['order_request_from']) && $data['order_request_from'] == 'panel') {
                    $message = translate('Minimum_Order_Amount') . ' ' . setCurrencySymbol(amount: usdToDefaultCurrency(amount: $minimumOrderAmount)) . ' ' . translate('for') . ' ' . $shopIdentity;
                } else {
                    $message = translate('Please_Complete_Minimum_Order_Amount_Requirement');
                }
            }
        }

        return [
            'minimum_order_amount' => $minimumOrderAmount ?? 0,
            'amount' => $amount ? floatval($amount) : 0,
            'status' => $status,
            'message' => $message,
        ];
    }

    /**
     * The page's rule for offering the edit (OrderEditService::checkIsOrderEditable), asked of the writer too: the routes of the vendor panel and of
     * the seller app take any order of the seller, whatever its page would offer. An edit that does not say it is the admin's is held to the sellers' rule.
     *
     * @return array{status: bool, message: string}
     */
    private function checkOrderIsEditableByTheEditor(object $order, object|array $data): array
    {
        $order->loadMissing(['details', 'latestEditHistory']);

        return app(OrderEditService::class)->checkIsOrderEditable(order: $order, type: ($data['edit_by'] ?? null) === 'admin' ? 'admin' : 'vendor');
    }

    public function generateEditOrder(object|array $request = [], object|array $order = [], object|array $editedOrder = [], object|array $data = []): array
    {
        // Admin panel, vendor panel and seller API all save an edit through here. A provider-owned line cannot be
        // edited (see FulfillmentOrderGuard::editBlockedReason), and this must hold before anything is written.
        $providerBlock = app(FulfillmentOrderGuard::class)->editBlockedReason(orderId: (int) $order['id'], editedLines: $editedOrder);
        if ($providerBlock !== null) {
            return ['status' => 'error', 'message' => $providerBlock];
        }

        // Nor can an order the page does not offer the edit for (its status, its type, only digital products, the sellers' switch, an unpaid offline payment).
        $editable = $this->checkOrderIsEditableByTheEditor(order: $order, data: $data);
        if (!$editable['status']) {
            return ['status' => 'error', 'message' => $editable['message']];
        }

        $editOrderSummary = $this->generateEditOrderSummary(request: $request, order: $order, editedOrder: $editedOrder, data: $data);

        if ($editOrderSummary['status'] != 'success') {
            return [
                'status' => $editOrderSummary['status'],
                'message' => $editOrderSummary['message'],
            ];
        }

        $orderData = $editOrderSummary['order_data'];
        $couponSummary = $editOrderSummary['coupon_summary'];

        $minimumOrderAmount = $this->checkEditOrderMinimumOrderAmount(order: $order, totalCartAmount: $couponSummary['total_cart_amount'], data: $data);
        if ($minimumOrderAmount['status'] == 'error') {
            return [
                'status' => $minimumOrderAmount['status'],
                'message' => $minimumOrderAmount['message'],
            ];
        }

        if (!$order['is_guest']) {
            $customer = User::where('id', $order['customer_id'])->first();
            $detailsRewards = OrderDetailsRewards::where(['order_id' => $order->id, 'reward_delivered' => 1])->get();
            $loyaltyPointReward = $detailsRewards->where('reward_type', 'loyalty_point')?->sum('reward_amount') ?? 0;
            if ($customer && ($customer['loyalty_point'] < $loyaltyPointReward)) {
                return [
                    'status' => 'error',
                    'message' => translate('This order cannot be modified because ')
                        . number_format($loyaltyPointReward, 2) .
                        translate(' loyalty points have already been redeemed.') . ' ' . translate('Need the redeemed points before making any changes to the order.'),
                ];
            }
        }

        DB::transaction(function () use ($order, $editedOrder, $editOrderSummary, $data) {
            $this->writeEditedOrder(order: $order, editedOrder: $editedOrder, editOrderSummary: $editOrderSummary, data: $data);
            DB::afterCommit(fn () => $this->dispatchOrderEditNotifications(order: $order, data: $data));
        });

        return [
            'status' => 'success',
            'message' => translate('Order_Edit_Successfully'),
        ];
    }

    /** Every write of a saved edit, for the caller to make as one: an edit that fails halfway must leave the order, its lines, its stock and its records as they were. */
    private function writeEditedOrder(object $order, object|array $editedOrder, array $editOrderSummary, object|array $data): void
    {
        $orderData = $editOrderSummary['order_data'];
        $taxConfig = self::getTaxSystemTypeOfOrder(order: $order);
        OrderTax::where('order_id', $order['id'])->where('tax_for', 'tax_for_orders')->delete();
        self::updateEditOrderTaxAmount(order: $order, taxConfig: $taxConfig, taxSummary: $editOrderSummary['tax_summary']);

        Order::where('id', $order->id)->update($orderData);

        self::addAndUpdateProductsInOrderDetails(order: $order, editedOrder: $editedOrder, taxSummary: $editOrderSummary['tax_summary']);

        OrderEditHistory::create([
            'order_id' => $order['id'],
            'edit_by' => $data['edit_by'],
            'edited_user_id' => $data['edited_user_id'],
            'edited_user_name' => $data['edited_user_name'],
            'order_amount' => $editOrderSummary['order_amount'],
            'order_due_amount' => $editOrderSummary['edit_due_amount'],
            'order_due_payment_status' => $editOrderSummary['edit_due_amount'] > 0 ? 'unpaid' : '',
            'order_return_amount' => $editOrderSummary['order_return_amount'],
            'order_return_payment_status' => $editOrderSummary['order_return_amount'] > 0 ? 'pending' : '',
        ]);

        OrderTransaction::where([
            'order_id' => $order['id'],
            'seller_is' => $order['seller_is'],
            'seller_id' => $order['seller_id']
        ])->update([
            'order_amount' => $editOrderSummary['order_amount'],
            'seller_amount' => $editOrderSummary['order_amount'] - $orderData['admin_commission'],
            'admin_commission' => $orderData['admin_commission'],
            'delivery_charge' => $orderData['shipping_cost'] - $orderData['extra_discount'],
            'tax' => $orderData['total_tax_amount'],
        ]);
    }

    /** Sent once the edit is committed: a listener, or a queued job, that ran before it would read an order that is not edited yet, or never will be. */
    private function dispatchOrderEditNotifications(object $order, object|array $data): void
    {
        $orderEditNotificationEvent = OrderManager::getEditOrderNotificationInfo(
            vendorType: $order['seller_is'],
            vendorId: $order['seller_id'],
            order: $order,
            data: $data,
            customer: $order['customer'],
        );

        foreach ($orderEditNotificationEvent as $orderEditEvent) {
            if (!empty($orderEditEvent)) {
                event(new OrderEditEvent(notification: $orderEditEvent['notificationData']));
            }
        }
    }

    public function updateEditOrderTaxAmount(object|array $request = [], object|array $order = [], object|array|null $taxConfig = [], object|array $taxSummary = []): void
    {
        $appliedTaxAmount = 0;
        $appliedTaxRate = 0;
        foreach ($taxSummary['applied_tax_cart_list'] as $cartItem) {
            $appliedTaxAmount = collect($taxSummary['applied_tax_cart_list'])->sum('applied_tax_amount') ?? 0;
            foreach ($cartItem['applied_tax_ids'] as $taxItem) {
                if ($taxItem) {
                    $appliedTaxRate += $taxItem['tax_rate'];
                }
            }
        }

        foreach ($taxSummary['applied_tax_cart_list'] as $cartItem) {
            foreach ($cartItem['applied_tax_ids'] as $taxItem) {
                if ($taxItem) {
                    OrderManager::getAddOrderTaxDetails(
                        systemTaxVat: $taxConfig['SystemTaxVat'],
                        taxRate: $taxItem,
                        orderId: $order['id'],
                        data: [
                            'tax_amount' => ($appliedTaxAmount > 0 && $appliedTaxRate > 0) ? ($appliedTaxAmount * $taxItem['tax_rate']) / $appliedTaxRate : 0,
                            'before_tax_amount' => $taxSummary['order_amount_with_tax'] - $taxSummary['total_tax_amount'],
                            'after_tax_amount' => $taxSummary['order_amount_with_tax'],
                            'quantity' => $cartItem['quantity'],
                            'seller_id' => $order['seller_id'],
                            'seller_type' => $order['seller_is'],
                        ]
                    );
                }
            }
        }

        $appliedShippingTaxAmount = 0;
        $appliedShippingTaxRate = 0;
        foreach ($taxSummary['applied_tax_cart_list'] as $cartItem) {
            $appliedShippingTaxAmount = collect($taxSummary['applied_tax_cart_list'])->sum('applied_shipping_cost_tax') ?? 0;
            foreach ($cartItem['applied_shipping_cost_tax_ids'] as $taxIdGroup) {
                foreach ($taxIdGroup['tax_ids'] as $taxId) {
                    $taxItem = $taxConfig['taxVats']->firstWhere('id', $taxId);
                    if ($taxItem) {
                        $appliedShippingTaxRate += $taxItem['tax_rate'];
                    }
                }
            }
        }

        foreach (collect($taxSummary['applied_tax_cart_list'])->pluck('applied_shipping_cost_tax_ids')->toArray() as $taxGroups) {
            foreach ($taxGroups as $taxGroup) {
                foreach ($taxGroup['tax_ids'] as $taxId) {
                    $taxItem = $taxConfig['taxVats']->firstWhere('id', $taxId);
                    if ($taxItem) {
                        OrderManager::getAddOrderTaxDetails(
                            systemTaxVat: $taxConfig['SystemTaxVat'],
                            taxRate: $taxItem,
                            orderId: $order['id'],
                            data: [
                                'tax_amount' => ($appliedShippingTaxAmount > 0 && $appliedShippingTaxRate > 0) ? ($appliedShippingTaxAmount * $taxItem['tax_rate']) / $appliedShippingTaxRate : 0,
                                'before_tax_amount' => $taxSummary['order_amount_with_tax'] - $taxSummary['total_tax_amount'],
                                'after_tax_amount' => $taxSummary['order_amount_with_tax'],
                                'quantity' => 0,
                                'seller_id' => $order['seller_id'],
                                'seller_type' => $order['seller_is'],
                            ],
                            taxOn: $taxGroup['name'],
                        );
                    }
                }
            }
        }
    }

    public function calculateEditOrderTaxAmount(object|array $request = [], object|array $order = [], object|array $editedOrder = [], object|array $couponSummary = [], object|array $data = []): array
    {
        $taxConfig = self::getTaxSystemTypeOfOrder(order: $order);

        $totalDiscountOnProduct = 0;
        if ($data['is_shipping_free'] == 1) {
            $totalDiscountOnProduct += $data['coupon_discount'] + $data['refer_and_earn_discount'];
        } else {
            $totalDiscountOnProduct += $data['coupon_discount'] + $data['free_delivery_discount'] + $data['refer_and_earn_discount'];
        }

        $totalDiscountProductPrice = 0;
        $totalApplicableShippingAmount = 0;
        foreach ($editedOrder as $editedItem) {
            $currentProductDiscountedPrice = ($editedItem['price'] - $editedItem['discount']) * $editedItem['qty'];
            $totalDiscountProductPrice += $currentProductDiscountedPrice;
            $totalApplicableShippingAmount += $editedItem['product_type'] == 'digital' ? 0 : $currentProductDiscountedPrice;
        }

        $vendorWiseCartAppliedTax = 0;
        $data['applied_tax_cart_list'] = [];
        foreach ($editedOrder as $editedItem) {
            if ($editedItem['active_product']) {
                $totalDiscountPrice = ($editedItem['price'] - $editedItem['discount']) * $editedItem['qty'];
                $appliedDiscountAmount = $totalDiscountOnProduct > 0 ? ($totalDiscountOnProduct * $totalDiscountPrice) / $totalDiscountProductPrice : 0;
                // A line without a product keeps the tax the order stored for it, none: an edit of an order whose such line carries tax is refused.
                $appliedTaxAmount = empty($editedItem['product_id']) ? 0 : CartManager::getAppliedTaxAmount(
                    product: $editedItem['active_product'],
                    taxConfig: $taxConfig,
                    totalDiscountedPrice: $totalDiscountPrice,
                    appliedDiscountedAmount: $appliedDiscountAmount,
                );

                $appliedTaxIds = empty($editedItem['product_id']) ? [] : CartManager::getAppliedTaxIds(
                    product: $editedItem['active_product'],
                    taxConfig: $taxConfig
                );
                $appliedShippingTaxIds = CartManager::getAppliedShippingTaxIds(
                    taxConfig: $taxConfig,
                );
                $appliedShippingTaxAmount = CartManager::getAppliedShippingTaxAmount(
                    taxConfig: $taxConfig,
                    totalShippingCost: $data['is_shipping_free'] == 1 ? $data['shipping_cost'] - $data['free_delivery_discount'] : $data['shipping_cost'],
                    totalDiscountedPrice: $totalDiscountPrice,
                    applicableShippingAmount: $totalApplicableShippingAmount,
                );

                $vendorWiseCartAppliedTax += $appliedTaxAmount;
                $vendorWiseCartAppliedTax += $editedItem['product_type'] == 'digital' ? 0 : $appliedShippingTaxAmount;
                $data['applied_tax_cart_list'][] = [
                    'product_id' => $editedItem['product_id'],
                    'variant' => $editedItem['variant'] ?? '',
                    'quantity' => $editedItem['qty'],
                    'price' => $editedItem['price'],
                    'discount' => $editedItem['discount'],
                    'seller_id' => $editedItem['seller_id'],
                    'seller_is' => $editedItem['seller_is'],
                    'discounted_price' => ($editedItem['price'] - $editedItem['discount']),
                    'total_discounted_price' => $totalDiscountPrice,
                    'applied_discounted_amount' => $appliedDiscountAmount,
                    'applied_tax_amount' => $appliedTaxAmount,
                    'applied_shipping_cost_tax' => $editedItem['product_type'] == 'digital' ? 0 : $appliedShippingTaxAmount,
                    'applied_tax_ids' => $appliedTaxIds,
                    'applied_shipping_cost_tax_ids' => $editedItem['product_type'] == 'digital' ? [] : $appliedShippingTaxIds,
                ];
            }
        }
        $data['total_tax_amount'] = $vendorWiseCartAppliedTax;
        $data['order_amount_with_tax'] = $data['order_amount'] + $vendorWiseCartAppliedTax;
        return $data;
    }

    /**
     * Pays the due of an edited order from the customer's own wallet, completely or not at all.
     *
     * The order row is locked and its due read again under the lock, so a second submission, or a concurrent one, finds nothing left
     * to pay and debits nothing. The debit, the order, its edit history and the admin wallet are written in one transaction: a debit
     * the wallet refuses leaves all of them as they were. Only the customer who owns the order may pay its due.
     *
     * @return array{status: bool, message: string}
     */
    public function payEditOrderDueByCustomerWallet(object|array $order, object|array $customer): array
    {
        try {
            return DB::transaction(function () use ($order, $customer) {
                $lockedOrder = Order::query()
                    ->where(['id' => $order['id'], 'customer_id' => $customer['id'], 'is_guest' => 0])
                    ->lockForUpdate()
                    ->first();
                if (!$lockedOrder) {
                    return ['status' => false, 'message' => translate('Order_not_found')];
                }

                $dueAmount = (float) $lockedOrder['edit_due_amount'];
                if ($dueAmount <= 0) {
                    return ['status' => false, 'message' => translate('No_due_amount_to_pay')];
                }
                if (($customer['wallet_balance'] ?? 0) < $dueAmount) {
                    return ['status' => false, 'message' => translate('Insufficient_Wallet_Balance')];
                }

                $debit = CustomerManager::create_wallet_transaction($customer['id'], $dueAmount, 'due_payment_for_order', 'reduce_wallet_amount', ['payment_method' => 'wallet'], origin: LedgerOrigin::order(id: $lockedOrder['id']));
                if ($debit === false) {
                    throw new WalletDebitRefusedException(customerId: $customer['id']);
                }

                AdminWallet::where(['admin_id' => 1])->increment('pending_amount', $dueAmount);
                OrderEditHistory::where('id', $lockedOrder->latestEditHistory?->id)->update([
                    'order_due_payment_status' => 'paid',
                    'order_due_payment_method' => 'wallet',
                ]);
                Order::where('id', $lockedOrder['id'])->update([
                    'edit_due_amount' => 0,
                    'order_amount' => $lockedOrder['order_amount'] + $dueAmount,
                    'payment_status' => 'paid'
                ]);

                return ['status' => true, 'message' => translate('Payment_Successful')];
            });
        } catch (WalletDebitRefusedException) {
            return ['status' => false, 'message' => translate('Insufficient_Wallet_Balance')];
        }
    }

    public function payEditOrderDueByDigitalPayment(object|array $request, object|array $order, object|array|string $customer): array
    {
        $guestId = session('guest_id') ?? ($request->guest_id ?? 0);
        $customerId = $customer == 'offline' ? $guestId : $customer['id'];
        $isGuest = $customer == 'offline' ? 1 : 0;
        $payerId = $customerId;

        /*
         * The same refusal as the payment start and the wallet top-up (D2-V1): the due amount must not be
         * converted into a currency the customer never chose. Answered in this method's own shape -- a
         * status, a message and no link -- which both the website controller and the app controller
         * already read. `redirect_link` is spelled out rather than left out because both of them go on to
         * read that key.
         */
        $currencyDecision = app(GatewayCurrencySupport::class)->decideForRequest($request);
        if ($currencyDecision->refused()) {
            return [
                'status' => false,
                'message' => $currencyDecision->message(),
                'redirect_link' => null,
                // Named so the app controller can answer 403 rather than the 401 it gives a dead session:
                // the customer app clears the login token on ANY 401, so an ordinary refusal would sign
                // them out mid-payment (measured in the Flutter client; found by both reviewers).
                'refusal' => 'payment-currency',
            ];
        }

        [$paymentAmount, $currency_code] = self::resolvePaymentAmount($order['edit_due_amount'], $request['payment_method'], $request);

        $additional_data = [
            'business_name' => getWebConfig(name: 'company_name'),
            'business_logo' => getWebConfig('company_web_logo')['path'],
            'payment_mode' => $request->payment_platform ?? 'web',
            'payment_method' => $request['payment_method'],
            'order_id' => $order['id'],
            'order_due_payment_note' => $request['order_due_payment_note'] ?? '',
            'customer_id' => $customerId,
            'is_guest' => $isGuest,
            'order_amount' => $order['order_amount'] + $order['edit_due_amount'],
        ];
        if ($request->payment_request_from === 'app') {
            $additional_data['payment_request_from'] = 'app';
        }

        if ($customer == 'offline') {
            $shippingAddress = (array)$order?->shipping_address_data;
            $payer = new Payer(
                ($shippingAddress['contact_person_name'] ?? 'Guest User'),
                ($shippingAddress['email'] ?? 'customer@example.com'),
                ($shippingAddress['phone'] ?? '000'),
                ''
            );
        } else {
            $payer = new Payer(
                name: "{$customer['f_name']} {$customer['l_name']}",
                email: $customer['email'],
                phone: $customer['phone'],
                address: ''
            );
        }

        $paymentInfo = new PaymentInfo(
            success_hook: 'customer_order_edit_pay_due_amount_success',
            failure_hook: 'customer_order_edit_pay_due_amount_failed',
            currency_code: $currency_code,
            payment_method: $request['payment_method'],
            payment_platform: $request['payment_platform'],
            payer_id: $payerId,
            receiver_id: '100',
            additional_data: $additional_data,
            payment_amount: $paymentAmount,
            external_redirect_link: $request['payment_platform'] === 'web' ? route('web-payment-success') : null,
            attribute: 'customer_order_edit_pay_due_amount',
            attribute_id: now()->timestamp
        );
        $receiverInfo = new Receiver('receiver_name', 'example.png');
        try {
            $redirectLink = $this->generate_link($payer, $paymentInfo, $receiverInfo);
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => $e->getMessage()
            ];
        }

        return [
            'status' => true,
            'redirect_link' => $redirectLink,
        ];
    }

    private function resolvePaymentAmount($amount, $paymentMethod, $request): array
    {
        if (getWebConfig('currency_model') === 'multi_currency') {
            // The code the refusal in payEditOrderDueByDigitalPayment() judged, so the due amount goes out
            // in the currency that was allowed. Switched off, the same value as the expression it replaces.
            $currencySupport = app(GatewayCurrencySupport::class);
            $currentCurrency = $currencySupport->chargedCurrencyCode($request);
            $currency_code = $this->getPaymentGatewayCurrencyCode($currencySupport->chargedGatewayKey($request), $currentCurrency);
            $convertedAmount = usdToAnotherCurrencyConverter(currencyCode: $currency_code, amount: $amount);
            return [$convertedAmount, $currency_code];
        }
        $defaultCurrency = Currency::find(getWebConfig('system_default_currency'))->code;
        return [$amount, $defaultCurrency];
    }

}
