<?php

namespace App\Utils;

use App\Services\Storefront\StorefrontCurrency;
use App\Services\Analytics\Analytics;
use App\Events\OrderEditDuePaymentEvent;
use App\Models\OrderDetailsRewards;
use App\Models\OrderEditHistory;
use App\Models\OrderStatusHistory;
use App\Models\ShippingMethod;
use Carbon\Carbon;
use App\Models\Cart;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use App\Models\Shop;
use App\Models\User;
use App\Models\Admin;
use App\Models\Color;
use App\Models\Order;
use App\Models\Coupon;
use App\Models\Seller;
use App\Models\Product;
use App\Models\Storage;
use App\Models\AdminWallet;
use App\Models\OrderDetail;
use App\Models\Transaction;
use App\Traits\CommonTrait;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\CartShipping;
use App\Models\SellerWallet;
use App\Models\ShippingType;
use App\Traits\PdfGenerator;
use App\Traits\CustomerTrait;
use App\Models\BusinessSetting;
use App\Models\OfflinePayments;
use App\Models\ShippingAddress;
use App\Events\OrderPlacedEvent;
use App\Models\OrderTransaction;
use App\Models\ReferralCustomer;
use App\Models\WalletTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\DigitalProductVariation;
use Modules\TaxModule\app\Traits\VatTaxManagement;
use App\Services\DeliverySyria\DeliverySyriaClient;
use App\Services\Customer\CustomerBalanceLock;
use App\Services\Wallet\WalletOverdraft;
use App\Services\Wallet\WalletPaymentRefused;
use App\Services\DeliverySyria\DeliverySyriaRateCalculator;
use App\Models\DeliverySyriaGovernorateRate;
use App\Models\DeliverySyriaParcel;
use App\Services\Fulfillment\FulfillmentHooks;
use App\Services\Fulfillment\OrderItemOwnershipSnapshotter;
use App\Services\Marketplace\RestockBudget;
use App\Services\Storefront\LedgerAttribution;
use App\Services\Storefront\LedgerOrigin;
use App\Services\Storefront\CheckoutLegalAcceptance;
use App\Services\Storefront\OrderStorefrontSnapshot;
use App\Services\SalesChannels\Dispatch\ChannelOrderMarker;
use App\Enums\Address\AddressChannel;
use App\Services\Address\OrderAddressService;
use App\Services\Referral\ReferralRewardService;
use App\Services\Storefront\OneSellingCompanyPerOrder;


use App\Services\DeliverySyria\DeliverySyriaConfigService;
use App\Services\Storefront\StorefrontShippingMethods;
use App\Services\Customer\CustomerWalletWriter;
use App\Services\Customer\WalletMinorUnit;
use App\Services\Shipping\OrderCustomsQuote;
use App\Services\Shipping\OrderShippingDecision;
use App\Services\Shipping\ShippingEligibility;
use App\Services\Checkout\OrderRefused;
use App\Exceptions\CouponNoLongerAvailableException;
use App\Services\Coupon\CouponAllowance;
use App\Services\Coupon\CouponDiscount;
use App\Services\Coupon\CouponGrant;
use App\Services\Coupon\CouponRedemption;
use App\Services\Checkout\OneOrderPerCheckoutSubmit;
use App\Services\Payments\OneOrderPerPayment;
use App\Services\Tax\IncludedTax;
use Illuminate\Support\Facades\Schema;


class OrderManager
{
    use CommonTrait, PdfGenerator;
    use CustomerTrait;
    use VatTaxManagement;

    public static function generateUniqueOrderID(): string
    {
        return rand(1000, 9999) . '-' . Str::random(5) . '-' . time();
    }

    public static function getOrderSummaryBeforePlaceOrder($cart, $coupon_discount): array
    {
        $coupon_code = session()->has('coupon_code') ? session('coupon_code') : 0;
        $coupon = Coupon::where(['code' => $coupon_code])->where('status', 1)->first();

        $subTotal = 0;
        $totalDiscountOnProduct = 0;

        if ($coupon && ($coupon->seller_id == NULL || $coupon->seller_id == '0' || $coupon->seller_id == $cart[0]->seller_id)) {
            $coupon_discount = $coupon->coupon_type == 'free_delivery' ? 0 : $coupon_discount;
        } else {
            $coupon_discount = 0;
        }

        foreach ($cart as $item) {
            $subTotal += $item->price * $item->quantity;
            $totalDiscountOnProduct += $item->discount * $item->quantity;
        }

        $orderTotal = $subTotal - $totalDiscountOnProduct - $coupon_discount;
        return [
            'order_total' => $orderTotal
        ];
    }

    public static function getStockUpdateOnOrderStatusChange($order, $status): void
    {
        // Restore stock when an order is returned/failed/cancelled; take it back when it moves out of
        // those states. Each detail is handled in its own transaction where the `is_stock_decreased`
        // flag is flipped by a CONDITIONAL update that doubles as the idempotency guard — only the caller
        // that actually flips the flag adjusts stock, so a double status-change (double-click, retry,
        // concurrent admin/API) can never restock or re-deduct twice. The product row is locked and the
        // amount changed with atomic increment/decrement, so the old unlocked absolute write
        // (current_stock +/- qty from a stale read) can no longer lose a concurrent update or go negative.
        $restoring = in_array($status, ['returned', 'failed', 'canceled'], true);
        // An order made for a channel order took nothing from the store's stock and has nothing to give back: its lines only follow the status.
        $holdsNoStock = ChannelOrderMarker::holdsNoStock($order);

        foreach ($order->details as $detail) {
            if ($holdsNoStock) {
                OrderDetail::where('id', $detail['id'])->update(['delivery_status' => $status]);
                continue;
            }

            DB::transaction(function () use ($detail, $status, $restoring) {
                $fromFlag = $restoring ? 1 : 0;
                $toFlag = $restoring ? 0 : 1;

                // The line is locked before the budget below is read, so a return receipt cannot spend the
                // same units between the reading and the claim; the receipt takes the same two locks in the
                // same order (order_details, then products).
                // Read with the query builder and not the model: OrderDetail eager-loads its storage
                // relation on every hydration, which this does not need and which needs a table a stock
                // writer has no business requiring.
                if (!DB::table('order_details')->where('id', $detail['id'])->lockForUpdate()->first()) {
                    return;
                }

                $units = self::unitsThisStatusChangeMoves($detail, $restoring);

                // A RESTORE THAT PUTS NOTHING BACK MUST NOT TAKE THE LINE'S CLAIM WITH IT. The flag is the
                // claim on the line's units: 1 means they are off the shelf. Flipping it for a restore of
                // nothing said they were back when they were not, so the next re-open took them off a second
                // time and the shelf lost the line's quantity on every sell -> return -> re-open -> return
                // round, without bound. The status is still recorded; only the claim is left where it is.
                if ($restoring && $units === 0) {
                    OrderDetail::where('id', $detail['id'])->update(['delivery_status' => $status]);

                    return;
                }

                $flipped = OrderDetail::where(['id' => $detail['id'], 'is_stock_decreased' => $fromFlag])
                    ->update(['is_stock_decreased' => $toFlag, 'delivery_status' => $status]);

                if (!$flipped) {
                    return;   // already in the target state — another run handled it; do not touch stock
                }

                $product = Product::where('id', $detail['product_id'])->lockForUpdate()->first();
                if (!$product) {
                    return;
                }

                if ($units === 0) {
                    return;   // a line of no units to re-take
                }

                // Adjust the specific variant's quantity under the same lock. The options keep their own
                // keys: this column is an object keyed by variant name in some installations and a list in
                // others, and rebuilding it as a list destroyed the keys of the first kind -- a shape the
                // storefront and the seller app read, and one the return receipt beside this deliberately
                // preserves.
                $type = $detail['variant'];
                $variationData = [];
                foreach (json_decode($product['variation'], true) ?? [] as $key => $var) {
                    if ($type == $var['type']) {
                        // Floored on re-deduct for the same reason current_stock is below: an out-of-band
                        // stock edit while the order sat cancelled used to drive the variant JSON negative.
                        $var['qty'] = $restoring ? ($var['qty'] + $units) : max(0, $var['qty'] - $units);
                    }
                    $variationData[$key] = $var;
                }
                Product::where(['id' => $product['id']])->update(['variation' => json_encode($variationData)]);

                if ($restoring) {
                    Product::where(['id' => $product['id']])->increment('current_stock', $units);
                } else {
                    // Floor at zero on re-deduct so an out-of-band stock change can't drive it negative.
                    $taken = Product::where('id', $product['id'])
                        ->where('current_stock', '>=', $units)
                        ->decrement('current_stock', $units);
                    if ($taken === 0) {
                        Product::where(['id' => $product['id']])->update(['current_stock' => 0]);
                    }
                }
            });
        }
    }

    /**
     * How many units this status change may move for one line.
     *
     * RESTORING puts back only what a return has not already put back. This writer used to move the whole
     * line however much of it had come back through a received return, so a partial return followed by
     * marking the order returned put four units back for three that left. The line's quantity is a budget
     * shared with the return receipt (RestockBudget); the is_stock_decreased claim still guards the repeat,
     * and this guards the overlap.
     *
     * RE-TAKING takes the whole line, NOT the remainder. A re-opened line is live for all of its units
     * again, so all of them are committed to the customer again whatever came back earlier. Bounding this
     * direction by the budget too left the shelf saying it held units that an open order had already
     * promised -- an oversell, which is the one error this must not make.
     */
    private static function unitsThisStatusChangeMoves(array|object $detail, bool $restoring): int
    {
        return $restoring
            ? app(RestockBudget::class)->unspentUnitsOf($detail['id'], $detail['qty'])
            : max(0, (int) $detail['qty']);
    }

    public static function getWalletManageOnOrderStatusChange($order, $received_by): void
    {
        $order = Order::find($order['id']);
        $order_summary = OrderManager::getOrderTotalAndSubTotalAmountSummary($order);
        $order_amount = $order_summary['subtotal'] - $order_summary['total_discount_on_product'] - $order['discount_amount'];
        $commission = $order['admin_commission'];
        $shipping_model = $order->shipping_responsibility;

        OrderManager::getCheckOrCreateAdminWallet();

        if (!SellerWallet::where('seller_id', $order['seller_id'])->first()) {
            DB::table('seller_wallets')->insert([
                'seller_id' => $order['seller_id'],
                'withdrawn' => 0,
                'commission_given' => 0,
                'total_earning' => 0,
                'pending_withdraw' => 0,
                'delivery_charge_earned' => 0,
                'collected_cash' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($order->coupon_code && $order->coupon_code != '0' && $order->seller_is == 'seller' && $order->discount_type == 'coupon_discount') {
            if ($order->coupon_discount_bearer == 'inhouse') {
                $seller_wallet = SellerWallet::where('seller_id', $order->seller_id)->first();
                $seller_wallet->total_earning += $order->discount_amount;
                $seller_wallet->save();

                $paid_by = 'admin';
                $payer_id = 1;
                $payment_receiver_id = $order->seller_id;
                $paid_to = 'seller';
            } elseif ($order->coupon_discount_bearer == 'seller') {
                $paid_by = 'seller';
                $payer_id = $order->seller_id;
                $payment_receiver_id = $order->seller_id;
                $paid_to = 'admin';
            }

            if (isset($payer_id) && isset($payment_receiver_id) && isset($paid_by) && isset($paid_to)) {
                $transaction = new Transaction();
                $transaction->order_id = $order->id;
                $transaction->payment_for = 'coupon_discount';
                $transaction->payer_id = $payer_id;
                $transaction->payment_receiver_id = $payment_receiver_id;
                $transaction->paid_by = $paid_by;
                $transaction->paid_to = $paid_to;
                $transaction->payment_status = 'disburse';
                $transaction->amount = $order->discount_amount;
                $transaction->transaction_type = 'expense';
                $transaction->save();
            }
        }

        // free delivery over amount transaction start
        if ($order->is_shipping_free && $order->seller_is == 'seller') {

            $seller_wallet = SellerWallet::where('seller_id', $order->seller_id)->first();
            $admin_wallet = AdminWallet::where('admin_id', 1)->first();

            if ($order->free_delivery_bearer == 'admin' && $order->shipping_responsibility == 'sellerwise_shipping') {
                $seller_wallet->delivery_charge_earned += $order->extra_discount;
                $seller_wallet->total_earning += $order->extra_discount;

                $admin_wallet->delivery_charge_earned -= $order->extra_discount;
                // $admin_wallet->inhouse_earning -= $order->extra_discount;

                $paid_by = 'admin';
                $payer_id = 1;
                $payment_receiver_id = $order->seller_id;
                $paid_to = 'seller';
            } elseif ($order->free_delivery_bearer == 'seller' && $order->shipping_responsibility == 'inhouse_shipping') {
                $seller_wallet->delivery_charge_earned -= $order->extra_discount;
                $seller_wallet->total_earning -= $order->extra_discount;

                $admin_wallet->delivery_charge_earned += $order->extra_discount;
                // $admin_wallet->inhouse_earning += $order->extra_discount;

                $paid_by = 'seller';
                $payer_id = $order->seller_id;
                $payment_receiver_id = $order->seller_id;
                $paid_to = 'admin';
            } elseif ($order->free_delivery_bearer == 'seller' && $order->shipping_responsibility == 'sellerwise_shipping') {
                $paid_by = 'seller';
                $payer_id = $order->seller_id;
                $payment_receiver_id = $order->seller_id;
                $paid_to = 'seller';
            } elseif ($order->free_delivery_bearer == 'admin' && $order->shipping_responsibility == 'inhouse_shipping') {
                $paid_by = 'admin';
                $payer_id = 1;
                $payment_receiver_id = $order->seller_id;
                $paid_to = 'admin';
            }

            $seller_wallet->save();
            $admin_wallet->save();

            $transaction = new Transaction();
            $transaction->order_id = $order->id;
            $transaction->payment_for = 'free_shipping_over_order_amount';
            $transaction->payer_id = $payer_id;
            $transaction->payment_receiver_id = $payment_receiver_id;
            $transaction->paid_by = $paid_by;
            $transaction->paid_to = $paid_to;
            $transaction->payment_status = 'disburse';
            $transaction->amount = $order->extra_discount;
            $transaction->transaction_type = 'expense';
            $transaction->save();
        }
        // free delivery over amount transaction end

        if ($order['seller_is'] == 'seller' &&
            $shipping_model == 'sellerwise_shipping'
        ) {
            $editHistoryAmount = OrderEditHistory::where([
                'order_id' => $order->id,
                'order_due_payment_status' => 'paid',
                'order_due_payment_method' => 'cash_on_delivery',
            ])?->sum('order_due_amount') ?? 0;
            $wallet = SellerWallet::where('seller_id', $order['seller_id'])->first();
            $wallet->collected_cash += $editHistoryAmount;
            $wallet->save();
        }

        if ($order['payment_method'] == 'cash_on_delivery' || $order['payment_method'] == 'offline_payment') {
            $shop = Shop::when($order['seller_is'] == 'admin', function ($query) {
                return $query->where(['author_type' => 'admin']);
            })->when($order['seller_is'] == 'seller', function ($query) use ($order) {
                return $query->where(['author_type' => 'vendor', 'seller_id' => $order['seller_id']]);
            })->first();

            DB::table('order_transactions')->insert([
                'transaction_id' => OrderManager::generateUniqueOrderID(),
                'customer_id' => $order['customer_id'],
                'seller_id' => $order['seller_id'],
                'shop_id' => $shop['id'],
                'seller_is' => $order['seller_is'],
                'order_id' => $order['id'],
                'order_amount' => $order['order_amount'],
                'seller_amount' => $order_amount - $commission,
                'admin_commission' => $commission,
                'received_by' => $received_by,
                'status' => 'disburse',
                'delivery_charge' => $order['shipping_cost'] - ($order['is_shipping_free'] ? $order['extra_discount'] : 0),
                'tax' => $order_summary['total_tax'],
                'delivered_by' => $received_by,
                'payment_method' => $order['payment_method'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(OrderStorefrontSnapshot::class)->stampMoneyRows(table: 'order_transactions', orderId: $order['id']);

            $wallet = AdminWallet::where('admin_id', 1)->first();
            $wallet->commission_earned += $commission;
            if ($shipping_model === 'inhouse_shipping' && !$order['is_shipping_free']) {
                $wallet->delivery_charge_earned += $order['shipping_cost'];
            }
            $wallet->save();

            if ($order['seller_is'] == 'admin') {
                $wallet = AdminWallet::where('admin_id', 1)->first();
                $wallet->inhouse_earning += $order_amount;
                if ($shipping_model === 'sellerwise_shipping' && !$order['is_shipping_free']) {
                    $wallet->delivery_charge_earned += $order['shipping_cost'];
                }
                $wallet->total_tax_collected += $order_summary['total_tax'];
            } else {
                $wallet = SellerWallet::where('seller_id', $order['seller_id'])->first();
                $wallet->commission_given += $commission;
                $wallet->total_tax_collected += $order_summary['total_tax'];

                if ($shipping_model == 'sellerwise_shipping') {
                    if (!$order['is_shipping_free']) {
                        $wallet->delivery_charge_earned += $order['shipping_cost'];
                    }
                    $currentOrderAmount = $order['order_amount'];
                    if ($order['payment_method'] == 'offline_payment') {
                        $editHistoryAmount = OrderEditHistory::where([
                            'order_id' => $order->id,
                            'order_due_payment_status' => 'paid',
                            'order_due_payment_method' => 'cash_on_delivery',
                        ])?->sum('order_due_amount') ?? 0;
                        $currentOrderAmount -= $editHistoryAmount;
                    }

                    if ($order['payment_method'] == 'cash_on_delivery') {
                        $wallet->collected_cash += $currentOrderAmount;
                    }

                    if ($order['payment_method'] == 'offline_payment') {
                        $wallet->total_earning += ($currentOrderAmount - $commission);
                    }
                } else {
                    $wallet->total_earning += ($order_amount - $commission) + $order_summary['total_tax'];
                }
            }
            $wallet->save();
        } else {
            $transaction = OrderTransaction::where(['order_id' => $order['id']])->first();
            // No transaction row means no money ever entered the platform's pending
            // pipeline — a card/wallet POS sale collected at the counter. Web digital
            // payments always create the row at payment success, so this only skips
            // settlements that would drive pending_amount negative.
            if (!$transaction) {
                return;
            }
            $transaction->status = 'disburse';
            $transaction->save();

            $wallet = AdminWallet::where('admin_id', 1)->first();
            $wallet->commission_earned += $commission;

            $currentOrderAmount = $order['order_amount'];
            if (
                $order?->latestEditHistory &&
                $order?->latestEditHistory?->order_due_payment_status == 'paid' &&
                $order?->latestEditHistory?->order_due_payment_method == 'cash_on_delivery'
            ) {
                $currentOrderAmount -= $order?->latestEditHistory?->order_due_amount ?? 0;
            }
            $wallet->pending_amount -= $currentOrderAmount;

            if ($shipping_model == 'inhouse_shipping' && !$order['is_shipping_free']) {
                $wallet->delivery_charge_earned += $order['shipping_cost'];
            }
            $wallet->save();

            if ($order['seller_is'] == 'admin') {
                $wallet = AdminWallet::where('admin_id', 1)->first();
                $wallet->inhouse_earning += $order_amount;
                if ($shipping_model == 'sellerwise_shipping' && !$order['is_shipping_free']) {
                    $wallet->delivery_charge_earned += $order['shipping_cost'];
                }
            } else {
                $wallet = SellerWallet::where('seller_id', $order['seller_id'])->first();
                $wallet->commission_given += $commission;

                if ($shipping_model == 'sellerwise_shipping') {
                    if (!$order['is_shipping_free']) {
                        $wallet->delivery_charge_earned += $order['shipping_cost'];
                    }
                    $currentOrderAmount = ($order_amount - $commission) + $order_summary['total_tax'] + ($order['is_shipping_free'] ? 0 : $order['shipping_cost']);
                    $editHistoryAmount = OrderEditHistory::where([
                        'order_id' => $order->id,
                        'order_due_payment_status' => 'paid',
                        'order_due_payment_method' => 'cash_on_delivery',
                    ])?->sum('order_due_amount') ?? 0;
                    $currentOrderAmount -= $editHistoryAmount;

                    $wallet->total_earning += $currentOrderAmount;
                } else {
                    $wallet->total_earning += ($order_amount - $commission) + $order_summary['total_tax'];
                }
            }
            $wallet->total_tax_collected += $order_summary['total_tax'];
            $wallet->save();
        }
    }

    public static function getOrderAddressId(string|null $type = 'shipping_address', int|null $id = null): int|null
    {
        $addressId = 0;
        if ($type == 'shipping_address') {
            $addressId = session('address_id') ? session('address_id') : 0;
            if (!is_null($id) && !session()->has('address_id')) {
                $addressId = $id;
            }
        }

        if ($type == 'billing_address') {
            $addressId = session('billing_address_id') ? session('billing_address_id') : 0;
            if (!is_null($id) && !session()->has('billing_address_id')) {
                $addressId = $id;
            }

            // The customer's own address IS the billing address unless they entered a different
            // one. Storing null here (which is what happened whenever billing input is off, or
            // the customer left "same as shipping" checked) left invoices with no billing block
            // at all.
            if (empty($addressId)) {
                $addressId = session('address_id') ?: null;
            }
        }
        return $addressId;
    }

    public static function updateCustomerShippingAddressForOrder($guestID, $customerID, $addressId): void
    {
        ShippingAddress::where(['customer_id' => $guestID, 'is_guest' => 1, 'id' => $addressId])
            ->update(['customer_id' => $customerID, 'is_guest' => 0]);
    }

    /**
     * The JSON an order keeps of its address (null when it has none): written once, from the address as it is right now, and never
     * touched again, so later edits to the saved address or to the city list do not rewrite a past order.
     */
    public static function getOrderAddressSnapshot(int|string|null $addressId): ?string
    {
        $address = empty($addressId) ? null : ShippingAddress::find($addressId);
        $snapshot = app(OrderAddressService::class)->snapshot(address: $address);

        return $snapshot === null ? null : json_encode($snapshot);
    }

    /**
     * Before an order is written, the delivery and billing address it carries must be the customer's own; the customer who has not
     * paid yet must also be allowed to ship to them (an old Saudi address has to be completed first). An order that is already paid
     * is never refused here, because the money is taken: it only never keeps an address that is not the customer's.
     *
     * @param array<int, array<string, mixed>> $vendorWiseCartList
     * @param array<string, mixed> $data what generateOrder was given
     * @param array<string, mixed> $customerInfo what getCustomerInfoForOrder worked out
     * @return array<int, array<string, mixed>>
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function scopeOrderAddresses(array $vendorWiseCartList, object|array|null $data, array $customerInfo): array
    {
        $firstCart = reset($vendorWiseCartList);
        if ($firstCart === false) {
            return $vendorWiseCartList;
        }

        $addresses = app(OrderAddressService::class);
        $channel = $data['address_channel'] ?? AddressChannel::Web;
        $owner = [
            'customerId' => $customerInfo['customer_id'],
            'isGuest' => (bool)$customerInfo['is_guest'],
            'guestId' => $customerInfo['guest_id'] ?: ($data['guest_id'] ?? null),
        ];

        if (($data['enforce_address_rules'] ?? true) !== false) {
            $addresses->assertOrderable(...$owner, shippingId: $firstCart['shipping_address_id'], billingId: $firstCart['billing_address_id'], channel: $channel);

            return $vendorWiseCartList;
        }

        $shippingId = $addresses->owned(...$owner, addressId: $firstCart['shipping_address_id'], allowUnsaved: $channel === AddressChannel::Web)?->id ?? 0;
        $billingId = $addresses->owned(...$owner, addressId: $firstCart['billing_address_id'], allowUnsaved: $channel === AddressChannel::Web)?->id ?? 0;

        return array_map(
            static fn (array $cart): array => [...$cart, 'shipping_address_id' => $shippingId, 'billing_address_id' => $billingId],
            $vendorWiseCartList
        );
    }

    /** How many checkouts of the customer used the coupon: the orders of one checkout share a group and count once. */
    /**
     * How many times this customer has redeemed this code.
     *
     * The ledger answers it when it is in use (I1-14): it records one row per coupon and order group, so it counts the same thing this
     * query counts -- and it can also count within ONE MARKET, which an order-group count over `orders` cannot without re-reading every
     * order's storefront. While the ledger is off, which is how the platform ships, this is the query it has always been, to the row.
     */
    public static function countCouponUses(int|string $customerId, mixed $couponCode): int
    {
        $recorded = CouponRedemption::usesByCustomer(
            customerId: $customerId,
            couponCode: is_string($couponCode) ? $couponCode : null,
            market: CouponAllowance::marketOfTheCount(),
        );

        if ($recorded !== null) {
            return $recorded;
        }

        return Order::where(['customer_id' => $customerId, 'coupon_code' => $couponCode])
            ->select('order_group_id')
            ->groupBy('order_group_id')
            ->get()
            ->count();
    }

    /**
     * Whether this customer has ordered before, which is what a `first_order` coupon asks (I1-14).
     *
     * Counted within one market when the counting is per store (Q-04) and over every order otherwise, which is what it has always been.
     * The market is the order's own record and not the request's: a customer's first order in Kuwait is a first order there whichever
     * storefront they are looking at now.
     */
    private static function customerHasOrderedBefore(int|string $customerId): bool
    {
        $orders = Order::where(['customer_id' => $customerId]);
        $market = CouponAllowance::marketOfTheCount();

        if ($market !== null && Schema::hasColumn('orders', 'storefront_country')) {
            $orders->where('storefront_country', $market);
        }

        return $orders->count() > 0;
    }

    public static function getTotalCouponAmount($request, $couponCode): array
    {
        $user = Helpers::getCustomerInformation($request);
        if ($user == 'offline') {
            return [
                'status' => false,
                'messages' => translate('Coupon_not_applicable'),
            ];
        }
        $couponLimit = self::countCouponUses(customerId: $user['id'], couponCode: $couponCode);

        $firstCoupon = Coupon::where(['code' => $couponCode])
            ->forStorefront()
            ->where('status', 1)
            ->whereDate('start_date', '<=', date('Y-m-d'))
            ->whereDate('expire_date', '>=', date('Y-m-d'))->first();

        if (!$firstCoupon) {
            return [
                'status' => false,
                'messages' => translate('invalid_coupon')
            ];
        }

        $coupon = null;
        if ($firstCoupon['coupon_type'] == 'first_order') {
            $coupon = $firstCoupon;
        } elseif ($firstCoupon['limit'] > $couponLimit) {
            $coupon = $firstCoupon;
        } else {
            return [
                'status' => false,
                'messages' => translate('sorry_this_coupon_is_not_valid_for_this_user') . '!',
            ];
        }

        if ($coupon && $coupon['coupon_type'] == 'first_order') {
            if (self::customerHasOrderedBefore(customerId: $user['id'])) {
                return [
                    'status' => false,
                    'messages' => translate('sorry_this_coupon_is_not_valid_for_this_user') . '!',
                ];
            }
        }

        // I1-14: what is left of the coupon itself -- its total cap and its spending budget -- after the per-customer limit and the
        // first-order rule, which are about this customer. Null while the cap and budget are off, which is how the platform ships, so
        // the coupon reaches the lines below exactly as it always has. The eligible total is not known yet, so the budget is judged on
        // what it has already given away; the amount is added where the discount is known, below.
        $exhausted = CouponAllowance::refusalFor(coupon: $coupon);

        if ($exhausted !== null) {
            return [
                'status' => false,
                'messages' => translate($exhausted) . '!',
            ];
        }

        $cartList = CartManager::getCartListQueryAPI(request: $request, type: 'checked');
        if (count($cartList) <= 0) {
            return [
                'status' => false,
                'messages' => translate('Please_add_item_to_cart')
            ];
        }

        $couponType = $coupon?->coupon_type ?? null;
        $couponDiscountType = $coupon?->discount_type ?? null;
        $couponDiscount = $coupon?->discount ?? 0;

        if ($coupon['customer_id'] != 0 && $coupon['customer_id'] != $user['id']) {
            return [
                'status' => false,
                'messages' => translate('coupon_not_valid')
            ];
        }

        $onlyProductTotalAmount = 0;
        if ($coupon->coupon_type == 'first_order') {
            foreach ($cartList as $cartItem) {
                $onlyProductTotalAmount += ($cartItem['price'] - $cartItem['discount']) * $cartItem['quantity'];
            }
        }

        $couponVendorsApplicable = false;
        if ($coupon->coupon_type != 'first_order') {
            foreach ($cartList as $cartItem) {
                if (($coupon->seller_id == '0') || (is_null($coupon->seller_id) && $cartItem['seller_is'] == 'admin') || ($coupon->seller_id == $cartItem['seller_id'] && $cartItem['seller_is'] == 'seller')) {
                    $couponVendorsApplicable = true;
                }
            }
            if (!$couponVendorsApplicable) {
                return [
                    'status' => false,
                    'messages' => translate('coupon_not_applicable_to_selected_vendors')
                ];
            }
        }

        if ($coupon->coupon_type == 'discount_on_purchase') {
            foreach ($cartList as $cartItem) {
                if (($coupon->seller_id == '0') || (is_null($coupon->seller_id) && $cartItem['seller_is'] == 'admin') || ($coupon->seller_id == $cartItem['seller_id'] && $cartItem['seller_is'] == 'seller')) {
                    $onlyProductTotalAmount += ($cartItem['price'] - $cartItem['discount']) * $cartItem['quantity'];
                }
            }
        }

        $discount = 0;
        if ($coupon->coupon_type == 'first_order' || $coupon->coupon_type == 'discount_on_purchase') {
            // What the coupon would take off these lines, worked out BEFORE the limits are judged rather than after: a percentage coupon's
            // `discount` column is a figure of per cent, and the rule below is about an amount of money. CouponDiscount says why nothing
            // rounds it, which is what keeps a dinar's third decimal and a riyal's figures as they are.
            $discountTheCouponGives = CouponDiscount::of(
                eligibleTotal: $onlyProductTotalAmount,
                discountType: $couponDiscountType,
                figure: $couponDiscount,
                maximumDiscount: $coupon['max_discount'],
            );

            $doesNotFit = self::refusalOfACartTheCouponDoesNotFit(
                eligibleTotal: $onlyProductTotalAmount,
                minimumPurchase: (float) $coupon['min_purchase'],
                discountType: $couponDiscountType,
                figure: (float) $couponDiscount,
                discountTheCouponGives: (float) $discountTheCouponGives,
            );

            if ($doesNotFit !== null) {
                return [
                    'status' => false,
                    'messages' => translate($doesNotFit),
                ];
            }

            $discount = $discountTheCouponGives;
        } elseif ($coupon->coupon_type == 'free_delivery') {
            foreach ($cartList as $cartItem) {
                if (($coupon->seller_id == '0') || (is_null($coupon->seller_id) && $cartItem['seller_is'] == 'admin') || ($coupon->seller_id == $cartItem['seller_id'] && $cartItem['seller_is'] == 'seller')) {
                    $onlyProductTotalAmount += ($cartItem['price'] - $cartItem['discount']) * $cartItem['quantity'];
                }
            }

            foreach ($cartList->groupBy('cart_group_id') as $cartGroupItem) {
                $cartItem = $cartGroupItem->first();
                if ($cartItem) {
                    if (($coupon->seller_id == '0') || (is_null($coupon->seller_id) && $cartItem['seller_is'] == 'admin') || ($coupon->seller_id == $cartItem['seller_id'] && $cartItem['seller_is'] == 'seller')) {
                        $discount += CartManager::get_shipping_cost(groupId: $cartItem['cart_group_id'], type: 'checked');
                    }
                }
            }

            if ($discount <= 0) {
                return [
                    'status' => false,
                    'messages' => translate('Not_applicable_for_current_shipping_cost')
                ];
            }
        }

        // I1-14, the other half of the budget: above, the coupon was judged on what it had already given away, because the eligible total
        // was not known yet. Here the discount IS known, and a budget with 3 left refuses a 5 and would have allowed a 2.
        $overBudget = CouponAllowance::refusalFor(coupon: $coupon, discount: $discount);

        if ($discount > 0 && $overBudget !== null) {
            return [
                'status' => false,
                'messages' => translate($overBudget) . '!',
            ];
        }

        if ($discount > 0) {
            session()->put('coupon_code', $coupon['code']);
            session()->put('coupon_type', $couponType);
            session()->put('coupon_discount', $discount);
            session()->put('coupon_bearer', $coupon->coupon_bearer);
            session()->put('coupon_seller_id', $coupon->seller_id);

            return [
                'status' => true,
                'discount' => $discount,
                'coupon_type' => $couponType,
                'total_cart_amount' => $onlyProductTotalAmount,
                'messages' => translate('coupon_applied_successfully') . '!'
            ];
        }

        return [
            'status' => false,
            'messages' => translate('coupon_not_valid')
        ];
    }

    /**
     * Why this cart cannot carry this coupon, as a translation key, or null when it can (I1-11, and the per-cent correction of 2026-11-26).
     *
     * Three different facts shared one condition and one sentence: nothing in the cart is eligible, the coupon's discount is larger than
     * the lines it applies to, and the minimum purchase is not reached. Only the third is what the sentence says, and the second is the one
     * a customer meets: a cart of 30 with a 50-off coupon whose minimum is 20 was told "minimum purchase amount not reached", which is
     * FALSE -- the minimum was reached, and the customer who adds items to reach a minimum they already met is being sent to do the one
     * thing that cannot help.
     *
     * The order is the order of truth and not of convenience. A cart below the minimum is told so first, whatever else is also true of it,
     * because that sentence is then correct and it is the rule the admin form and the owner's note are about -- `min_purchase > eligible
     * total` is untouched by either reading below and nothing in this method moves it.
     *
     * **The two readings, and which is live.** With `gcc.coupons.percentage_of_the_eligible_total` OFF, which is how the platform ships,
     * the condition is the one it has always been: the coupon's `discount` COLUMN against the eligible total. For a percentage coupon that
     * column is a figure of per cent, so a 31% coupon is refused on a cart of 30 and applied to a cart of 100 -- a defect in the condition
     * rather than in the wording, which is why that case keeps the minimum sentence here: correcting only the words would put a third
     * sentence on a refusal nobody should be given at all.
     *
     * ON, the figure of per cent is not compared with money at all. What is compared is the discount the coupon would actually give
     * ({@see CouponDiscount::of}), so a percentage is judged as a share of the eligible value, and a coupon whose share is larger than the
     * lines it applies to -- which needs a figure above 100, something the admin form refuses and only a direct edit of the table can
     * produce -- is told the true sentence. A money coupon is judged by the same number under both readings and so does not move.
     *
     * A cart with nothing eligible at all (`<= 0`) keeps the minimum sentence under both readings. Its honest sentence is a third one
     * again, and a message nobody asked for is a message nobody has read in Arabic.
     */
    private static function refusalOfACartTheCouponDoesNotFit(
        float|int $eligibleTotal,
        float $minimumPurchase,
        ?string $discountType,
        float $figure,
        float $discountTheCouponGives,
    ): ?string {
        $minimumNotReached = 'minimum_purchase_amount_not_reached_for_this_coupon.';
        $largerThanTheItems = 'this_coupons_discount_is_more_than_the_items_it_applies_to';

        if ($eligibleTotal <= 0 || $minimumPurchase > $eligibleTotal) {
            return $minimumNotReached;
        }

        if (CouponDiscount::isJudgedByItsShare()) {
            return $discountTheCouponGives > $eligibleTotal ? $largerThanTheItems : null;
        }

        if ($figure <= $eligibleTotal) {
            return null;
        }

        return $discountType === 'percentage' ? $minimumNotReached : $largerThanTheItems;
    }

    public static function getGroupWiseCouponArray($request, $couponCode, $applicableAmount, $discountAmount, $groupId, $cartListGroup): array
    {
        $user = Helpers::getCustomerInformation($request);
        if ($applicableAmount <= 0 || $discountAmount <= 0 || $user == 'offline') {
            return [
                'discount' => 0,
                'coupon_bearer' => 'inhouse',
                'coupon_code' => 0,
                'coupon_type' => NULL,
            ];
        }

        $couponLimit = self::countCouponUses(customerId: $user['id'], couponCode: $couponCode);

        $firstCoupon = Coupon::where(['code' => $couponCode])
            ->forStorefront()
            ->where('status', 1)
            ->whereDate('start_date', '<=', date('Y-m-d'))
            ->whereDate('expire_date', '>=', date('Y-m-d'))->first();

        $coupon = $firstCoupon && $firstCoupon['coupon_type'] == 'first_order' ? $firstCoupon : ($firstCoupon['limit'] > $couponLimit ? $firstCoupon : null);

        // I1-14: a coupon whose cap or budget is spent gives this group nothing, which is the answer this function already gives for a
        // coupon that does not apply -- discount 0 and coupon code 0 on the order. Asked here as well as in getTotalCouponAmount because
        // this is the function whose answer is WRITTEN onto the order, and the two are called from the same request a moment apart.
        if (CouponAllowance::refusalFor(coupon: $coupon, discount: $discountAmount) !== null) {
            return [
                'discount' => 0,
                'coupon_bearer' => 'inhouse',
                'coupon_code' => 0,
                'coupon_type' => NULL,
            ];
        }

        $firstOrderCoupon = $coupon->coupon_type == 'first_order';
        $discountOnPurchaseCoupon = $coupon->coupon_type == 'discount_on_purchase';
        $freeDeliveryCoupon = $coupon->coupon_type == 'free_delivery';

        $onlyProductTotalAmount = 0;
        if ($firstOrderCoupon) {
            foreach ($cartListGroup as $cartListGroupKey => $cartList) {
                if ($cartListGroupKey == $groupId) {
                    foreach ($cartList as $cartItem) {
                        $onlyProductTotalAmount += ($cartItem['price'] - $cartItem['discount']) * $cartItem['quantity'];
                    }
                }
            }
        }

        if ($discountOnPurchaseCoupon) {
            foreach ($cartListGroup as $cartListGroupKey => $cartList) {
                if ($cartListGroupKey == $groupId) {
                    foreach ($cartList as $cartItem) {
                        if (($coupon->seller_id == '0') || (is_null($coupon->seller_id) && $cartItem['seller_is'] == 'admin') || ($coupon->seller_id == $cartItem['seller_id'] && $cartItem['seller_is'] == 'seller')) {
                            $onlyProductTotalAmount += ($cartItem['price'] - $cartItem['discount']) * $cartItem['quantity'];
                        }
                    }
                }
            }
        }

        $discount = 0;
        if ($firstOrderCoupon || $discountOnPurchaseCoupon) {
            $discount = ($onlyProductTotalAmount * $discountAmount) / $applicableAmount;
        } elseif ($freeDeliveryCoupon) {
            foreach ($cartListGroup as $cartListGroupKey => $cartList) {
                if ($cartListGroupKey == $groupId) {
                    $cartItem = $cartList->first();
                    if ($cartItem) {
                        if (($coupon->seller_id == '0') || (is_null($coupon->seller_id) && $cartItem['seller_is'] == 'admin') || ($coupon->seller_id == $cartItem['seller_id'] && $cartItem['seller_is'] == 'seller')) {
                            $discount += CartManager::get_shipping_cost(groupId: $groupId, type: 'checked');
                        }
                    }
                }
            }
        }

        $couponBearer = $coupon->coupon_bearer;
        return [
            'discount' => $discount,
            'coupon_bearer' => $couponBearer,
            'coupon_code' => $coupon->code,
            'coupon_type' => $coupon->coupon_type,
        ];
    }

    public static function getCustomerInfoForOrder($data): array
    {
        $customer = Helpers::getCustomerInformation($data['requestObj'] ?? request());
        $isGuest = ($customer == 'offline') ? 1 : 0;
        if (isset($data['is_guest'])) {
            $isGuest = $data['is_guest'] ?? 0;
        }

        if ($customer == 'offline' && isset($data['customer_id']) && isset($data['is_guest']) && $data['is_guest'] == 0) {
            $userCheck = User::where(['id' => $data['customer_id']])->first();
            $customer = $userCheck ?? $customer;
        }

        $guestId = session('guest_id') ? session('guest_id') : ($data['is_guest'] ? $data['guest_id'] : 0);
        $customerId = $customer == 'offline' ? $guestId : $customer['id'];

        if ($customer == 'offline' && session('newCustomerRegister') && session('newRegisterCustomerInfo')) {
            $addCustomer = session('newRegisterCustomerInfo');
            $customerId = $addCustomer['id'];
            $isGuest = 0;
            $guestID = session()->has('guest_id') ? session('guest_id') : 0;

            self::updateCustomerShippingAddressForOrder($guestID, $customerId, session('address_id'));
            self::updateCustomerShippingAddressForOrder($guestID, $customerId, session('billing_address_id'));
        } elseif ($customer == 'offline' && isset($data['newCustomerRegister'])) {
            $customerId = $data['newCustomerRegister'] ? $data['newCustomerRegister']['id'] : $isGuest;
            $isGuest = $data['newCustomerRegister'] ? 0 : 1;

            if (isset($data['is_guest']) && isset($data['guest_id']) && isset($data['address_id']) && isset($data['billing_address_id'])) {
                self::updateCustomerShippingAddressForOrder($data['guest_id'], $customerId, $data['address_id']);
                self::updateCustomerShippingAddressForOrder($data['guest_id'], $customerId, $data['billing_address_id']);
            }
        } elseif ($customer == 'offline' && isset($data['new_customer_id'])) {
            $customerId = $data['new_customer_id'];
            $isGuest = $data['new_customer_id'] ? 0 : 1;
        }

        $newCustomerRegister = isset($data['newCustomerRegister']) && empty($data['newCustomerRegister']) ? $data['newCustomerRegister'] : session('newRegisterCustomerInfo');

        return [
            'is_guest' => $isGuest,
            'guest_id' => $guestId,
            'customer' => $customer,
            'customer_id' => $customerId,
            'newCustomerRegister' => $newCustomerRegister,
            'new_customer_id' => $newCustomerRegister ? $newCustomerRegister['id'] : null,
        ];
    }

    /**
     * The next order id — read-max-then-increment, so it has to be serialised.
     *
     * `orders.id` is not auto-increment; this function picks the number. Two concurrent checkouts
     * therefore read the same maximum and both return it, and the second insert dies on the primary
     * key. Observed on the running store with two simultaneous orders:
     *
     *     SQLSTATE[23000]: Integrity constraint violation: 1062
     *     Duplicate entry '100002' for key 'PRIMARY'
     *
     * — a hard 500 at the last step of checkout, after the customer pressed pay.
     *
     * `lockForUpdate()` makes the read-then-insert atomic for the duration of the order
     * transaction, so the second checkout waits, then reads the id the first one just wrote.
     * Outside a transaction it is a harmless no-op, so callers that are not in one are unaffected.
     *
     * The selection rule itself is left exactly as it was — this changes when the read happens,
     * not which number it produces.
     */
    public static function generateNewOrderID()
    {
        // Read with the QUERY BUILDER and not the models, for three reasons that all point the same way.
        //
        // `OrderDetail` eager-loads `storage` (`protected $with`), so hydrating one row to learn one number fetched a relation this
        // never looks at -- a whole extra query per sale, which is what took the seller app's till from 25 statements to 27 when it
        // started asking this function instead of counting rows.
        //
        // Both models carry `StaffCountryOrderScope`, which narrows an order through its lines and the market of each product. The
        // scope is inert unless a request asked to be narrowed, so this was not wrong today -- but an id picker that can see FEWER
        // rows than exist hands out a number that is already taken, and the retry around it would then choose the same one again.
        // A builder cannot be narrowed by a scope, so that cannot happen whatever a future route asks for.
        //
        // The selection RULE is unchanged, as it was when the locking read was added: this changes which API asks the question, not
        // which number comes back. `value()` returns the column or null, which is the same `?? $baseID` case as before.
        $baseID = 100001;
        $orderDetailsId = DB::table('order_details')->orderByDesc('order_id')->lockForUpdate()->value('order_id') ?? $baseID;
        if (DB::table('orders')->where('id', $orderDetailsId)->exists()) {
            $orderDetailsId = (int) DB::table('orders')->orderByDesc('id')->lockForUpdate()->value('id') + 1;
        }
        return $orderDetailsId;
    }

    /**
     * Write an order in a transaction, retrying the whole write when another writer took the id this one had chosen.
     *
     * The locking read above closes the common case and does not close every case, which was measured rather than assumed:
     *
     *  - with `order_details` empty, two overlapping writers both read no rows. InnoDB gap locks CO-EXIST, so neither waits,
     *    both choose 100001 and the second insert dies on the primary key.
     *  - with rows present, the locking read does serialise — but the existence check beside it (`Order::find`) is a
     *    CONSISTENT read, so under REPEATABLE READ it answers from a snapshot taken before the other writer committed, does
     *    not see the order that writer just wrote, and hands back the id it already used.
     *
     * Measured on MariaDB 10.11, two tills posting at the same instant: one HTTP 500 in three runs out of three, with and
     * without a prior order. A duplicate key is not what Laravel's own `attempts:` retries — `causedByConcurrencyError`
     * matches deadlocks and lock-wait timeouts, not 1062 — so the retry is here.
     *
     * Retrying is safe because the failure is a rolled-back transaction: nothing the first attempt wrote survives it, so the
     * second attempt re-reads the id, the stock and the wallet as they now stand. A refusal is NOT retried — a shelf that was
     * short a moment ago is still short — and only a unique-constraint violation is, so a genuine failure still surfaces.
     *
     * `attempts: 3` is the second half of the same job and was ALSO measured, not assumed. Choosing the id with locking reads
     * makes the writer hold a lock on `order_details` and `orders` as well as on its products, and two sales over DIFFERENT
     * products then take their locks in two different orders: with the duplicate key closed, a third of the runs turned into
     * `SQLSTATE[40001] 1213 Deadlock found` instead. Laravel retries that for us when asked — `generateOrder` asks for the
     * same three attempts, for the same reason — and a deadlock victim is rolled back whole, so the retry is as safe as the
     * one above.
     *
     * @template TReturn
     * @param  callable(): TReturn  $write
     * @return TReturn
     */
    public static function writeOrderRetryingOnDuplicateId(callable $write, int $attempts = 3)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($write, attempts: 3);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= $attempts) {
                    throw $exception;
                }
                Log::warning('Order id was taken by another writer; retrying the order write (attempt ' . $attempt . ').');
            }
        }
    }

    public static function getCheckOrCreateAdminWallet(): void
    {
        if (!AdminWallet::where('admin_id', 1)->first()) {
            DB::table('admin_wallets')->insert([
                'admin_id' => 1,
                'withdrawn' => 0,
                'commission_earned' => 0,
                'inhouse_earning' => 0,
                'delivery_charge_earned' => 0,
                'pending_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public static function checkCustomerReferralDiscount(object|array|null $request = null)
    {
        $user = Helpers::getCustomerInformation($request);
        if ($user != 'offline') {
            $referralCustomer = ReferralCustomer::where('user_id', $user['id'])->where('is_used', 0)->first();
            $isFirstOrder = Order::where(['customer_id' => $user['id'], 'order_status' => 'delivered', 'payment_status' => 'paid', 'order_type' => 'default_type'])->count();
            return $referralCustomer && $isFirstOrder <= 0 ? $referralCustomer : null;
        }
        return null;
    }

    public static function redeemReferralDiscount($referralCustomer, $totalAmount, $groupAmount): float|int
    {
        if ($referralCustomer) {
            $discountAmount = $referralCustomer->customer_discount_amount;
            $validity = $referralCustomer->customer_discount_validity;
            $type = $referralCustomer->customer_discount_validity_type;

            $expirationDate = Carbon::parse($referralCustomer->created_at);
            if ($type == 'day') {
                $expirationDate->addDays($validity);
            } else if ($type == 'week') {
                $expirationDate->addWeeks($validity);
            } else if ($type == 'month') {
                $expirationDate->addMonths($validity);
            } else {
                return 0;
            }

            if (Carbon::now()->greaterThan($expirationDate)) {
                return 0;
            }

            if ($referralCustomer->customer_discount_amount_type === 'percentage') {
                return ($groupAmount * $discountAmount) / 100;
            }
            if (app(StorefrontCurrency::class)->currencyCodeInForce() !== null) {
                return 0;
            }
            return ($discountAmount * $groupAmount) / $totalAmount;
        }
        return 0;
    }

    public static function generateReferBonusForFirstOrder(int|string $orderId): void
    {
        $refEarningStatus = getWebConfig(name: 'ref_earning_status') ?? 0;
        $refEarningExchangeRate = getWebConfig(name: 'ref_earning_exchange_rate') ?? 0;
        $order = Order::with(['customer', 'seller.shop', 'deliveryMan'])->where(['id' => $orderId])->first();

        if (!$order['is_guest'] && $refEarningStatus == 1 && $order['order_status'] == 'delivered') {
            $customer = User::where(['id' => $order['customer_id']])->first();
            $isFirstOrder = Order::where(['customer_id' => $order['customer_id'], 'order_status' => 'delivered', 'payment_status' => 'paid'])->count();
            $referredByUser = User::where(['id' => $customer['referred_by']])->first();
            if ($isFirstOrder == 1 && isset($customer->referred_by) && isset($referredByUser)) {
                app(ReferralRewardService::class)->creditOnce(
                    refereeId: $customer['id'],
                    beneficiaryId: $referredByUser['id'],
                    orderId: $order['id'],
                    credit: fn () => self::createWalletTransaction(
                        user_id: $referredByUser['id'],
                        amount: usdToDefaultCurrency(amount: $refEarningExchangeRate),
                        transaction_type: 'add_fund_by_admin',
                        reference: 'earned_by_referral',
                        origin: LedgerOrigin::order(id: $orderId)
                    ),
                );
            }
        }
    }

    public static function getVendorWiseCartList(array|object|null $data = []): array
    {
        $cartListQuery = CartManager::getCartListQuery(type: 'checked');
        $cartListQueryGroup = $cartListQuery?->groupBy('cart_group_id');
        $checkReferralDiscount = OrderManager::checkCustomerReferralDiscount(request: ($data['requestObj'] ?? request()));
        $onlyProductPriceGrandTotal = CartManager::getOnlyCartProductPriceGrandTotal(type: 'checked');

        $shippingModel = getWebConfig(name: 'shipping_method');
        $adminShipping = ShippingType::where('seller_id', 0)->first();

        $couponCode = $data['coupon_code'] ?? session('coupon_code');
        $totalCouponDiscountInfo = OrderManager::getTotalCouponAmount(request: ($data['requestObj'] ?? request()), couponCode: $couponCode);
        $totalCouponDiscount = $totalCouponDiscountInfo['discount'] ?? 0;

        $vendorWiseCartList = [];
        foreach ($cartListQueryGroup as $groupId => $cartListQueryGroupItem) {

            $couponInfo = OrderManager::getGroupWiseCouponArray(
                request: ($data['requestObj'] ?? request()),
                couponCode: $couponCode,
                applicableAmount: $totalCouponDiscountInfo['total_cart_amount'] ?? 0,
                discountAmount: $totalCouponDiscountInfo['discount'] ?? 0,
                groupId: $groupId,
                cartListGroup: $cartListQueryGroup
            );
            $shippingCost = CartManager::get_shipping_cost(groupId: $groupId, type: 'checked');
            $freeDelivery = OrderManager::getFreeDeliveryOrderAmountArray($groupId);
            $isShippingFree = 0;
            $freeShippingDiscount = 0;
            $freeDeliveryBearer = null;
            $extraDiscountType = null;
            if ($freeDelivery['status'] && $freeDelivery['shipping_cost_saved'] > 0 && $couponInfo['coupon_type'] != 'free_delivery') {
                $isShippingFree = 1;
                $extraDiscountType = 'free_shipping_over_order_amount';
                $freeShippingDiscount = $shippingCost;
                $freeDeliveryBearer = $freeDelivery['responsibility'];
            }

            $firstCartItem = $cartListQueryGroupItem->first();
            $cartGroupGrandTotal = CartManager::cart_grand_total(cartGroupId: $groupId, type: 'checked');
            $onlyGroupPriceGrandTotal = CartManager::getOnlyCartProductPriceGrandTotal(cartGroupId: $groupId, type: 'checked');

            if ($shippingModel == 'inhouse_shipping' || $firstCartItem['seller_is'] == 'admin') {
                $shippingType = $adminShipping;
            } else if ($firstCartItem['seller_is'] != 'admin') {
                $shippingType = ShippingType::where(['seller_id' => $firstCartItem['seller_id']])->first();
            }

            $shippingMethod = CartShipping::where(['cart_group_id' => $groupId])->first();
            $shippingMethodId = isset($shippingMethod) ? $shippingMethod->shipping_method_id : 0;
            $cartGroupOrderAmount = $cartGroupGrandTotal - ($couponInfo['discount'] ?? 0) - $freeShippingDiscount;

            $referAndEarnDiscount = OrderManager::redeemReferralDiscount(
                referralCustomer: $checkReferralDiscount,
                totalAmount: $onlyProductPriceGrandTotal - $totalCouponDiscount,
                groupAmount: $onlyGroupPriceGrandTotal - ($couponInfo['discount'] ?? 0),
            );

            $vendorWiseCartList[$groupId] = [
                'seller_id' => $firstCartItem['seller_id'],
                'seller_is' => $firstCartItem['seller_is'],
                'grand_total' => $cartGroupGrandTotal,
                'order_amount' => $cartGroupOrderAmount,
                'coupon_code' => $couponInfo['coupon_code'] ?? 0,
                'coupon_bearer' => $couponInfo['coupon_bearer'] ?? 'inhouse',
                'coupon_discount' => $couponInfo['discount'] ?? 0,
                'discount_type' => $couponInfo['discount'] == 0 ? null : 'coupon_discount',
                'shipping_cost' => $shippingCost,
                // Which rule produced that charge (H1-08): a destination zone, the method the customer chose, or the lines themselves in
                // a shop that charges per product or per category. CartManager answers all three, because all three land in one column.
                'shipping_rate_source' => CartManager::getShippingRateSource(groupId: $groupId),
                'shipping_address_id' => OrderManager::getOrderAddressId(type: 'shipping_address', id: $data['address_id'] ?? null),
                'billing_address_id' => OrderManager::getOrderAddressId(type: 'billing_address', id: $data['billing_address_id'] ?? null),
                'shipping_type' => isset($shippingType?->shipping_type) ? $shippingType->shipping_type : 'order_wise',
                'is_shipping_free' => $isShippingFree,
                'shipping_method_id' => $shippingMethodId,
                'free_delivery_discount' => $freeShippingDiscount ?? 0,
                'extra_discount_type' => $extraDiscountType,
                'free_delivery_bearer' => $firstCartItem['seller_is'] == 'seller' ? $freeDeliveryBearer : 'admin',
                'refer_and_earn_discount' => $referAndEarnDiscount,
                'cart_list' => $cartListQueryGroupItem,
                'cart_group_id' => $groupId,
            ];
        }

        return $vendorWiseCartList;
    }

    public static function processOrderGenerateData(array|object|null $data = []): array
    {
        $taxConfig = self::getTaxSystemType();
        $cartItemsArray = [];

        // Whether the tax already inside a tax-included price is recorded (D1-07). Read ONCE for the whole cart, not per line, and the
        // keys below are absent rather than zero while it is off, so a cart of a store that has not answered that question carries
        // exactly what it carries today. The presence of `included_tax_amount` is what tells the order writer to record it.
        $recordIncludedTax = !empty($taxConfig['is_included']) && app(IncludedTax::class)->isRecorded();

        $vendorWiseCartList = OrderManager::getVendorWiseCartList(data: [
            'coupon_code' => $data['coupon_code'] ?? (session('coupon_code') ?? ''),
            'address_id' => $data['address_id'] ?? session('address_id'),
            'billing_address_id' => $data['billing_address_id'] ?? session('billing_address_id'),
            'requestObj' => $data['requestObj'] ?? null,
        ]);

        $modifiedVendorWiseCartList = [];
        foreach ($vendorWiseCartList as $vendorWiseGroupId => $vendorWiseCart) {

            if ($vendorWiseCart['is_shipping_free'] == 1) {
                $totalDiscountOnProduct = $vendorWiseCart['coupon_discount'] + $vendorWiseCart['refer_and_earn_discount'];
            } else {
                $totalDiscountOnProduct = $vendorWiseCart['coupon_discount'] + $vendorWiseCart['free_delivery_discount'] + $vendorWiseCart['refer_and_earn_discount'];
            }

            $totalDiscountProductPrice = 0;
            $totalApplicableShippingAmount = 0;
            foreach ($vendorWiseCart['cart_list'] as $cartItem) {
                if ($cartItem->product) {
                    $currentProductDiscountedPrice = ($cartItem->price - $cartItem->discount) * $cartItem->quantity;
                    $totalDiscountProductPrice += $currentProductDiscountedPrice;
                    $totalApplicableShippingAmount += $cartItem?->product?->product_type == 'digital' ? 0 : $currentProductDiscountedPrice;
                }
            }

            $vendorWiseCartAppliedTax = 0;
            $vendorWiseCartIncludedTax = 0;
            $vendorWiseCart['applied_tax_cart_list'] = [];
            foreach ($vendorWiseCart['cart_list'] as $cartItem) {
                if ($cartItem->product) {
                    $totalDiscountPrice = ($cartItem->price - $cartItem->discount) * $cartItem->quantity;
                    $appliedDiscountAmount = $totalDiscountOnProduct > 0 ? ($totalDiscountOnProduct * $totalDiscountPrice) / $totalDiscountProductPrice : 0;
                    $appliedTaxAmount = CartManager::getAppliedTaxAmount(
                        product: $cartItem->product,
                        taxConfig: $taxConfig,
                        totalDiscountedPrice: $totalDiscountPrice,
                        appliedDiscountedAmount: $appliedDiscountAmount,
                    );

                    $appliedTaxIds = CartManager::getAppliedTaxIds(
                        product: $cartItem->product,
                        taxConfig: $taxConfig
                    );
                    $appliedShippingTaxIds = CartManager::getAppliedShippingTaxIds(
                        taxConfig: $taxConfig,
                    );
                    $appliedShippingTaxAmount = CartManager::getAppliedShippingTaxAmount(
                        taxConfig: $taxConfig,
                        totalShippingCost: $vendorWiseCart['is_shipping_free'] == 1 ? $vendorWiseCart['shipping_cost'] - $vendorWiseCart['free_delivery_discount'] : $vendorWiseCart['shipping_cost'],
                        totalDiscountedPrice: $totalDiscountPrice,
                        applicableShippingAmount: $totalApplicableShippingAmount,
                    );

                    $vendorWiseCartAppliedTax += $appliedTaxAmount;
                    $vendorWiseCartAppliedTax += $cartItem?->product?->product_type == 'digital' ? 0 : $appliedShippingTaxAmount;

                    // The tax already inside this line's gross price, and inside the share of the shipping charge it carries. Worked out
                    // from the same figures the charged tax is -- the discounted price and the discount apportioned to this line -- so a
                    // coupon reduces the tax inside a line exactly as it reduces the tax added to one.
                    $includedTax = [];
                    if ($recordIncludedTax) {
                        $includedShippingTax = $cartItem?->product?->product_type == 'digital' ? 0 : CartManager::getIncludedShippingTaxAmount(
                            taxConfig: $taxConfig,
                            totalShippingCost: $vendorWiseCart['is_shipping_free'] == 1 ? $vendorWiseCart['shipping_cost'] - $vendorWiseCart['free_delivery_discount'] : $vendorWiseCart['shipping_cost'],
                            totalDiscountedPrice: $totalDiscountPrice,
                            applicableShippingAmount: $totalApplicableShippingAmount,
                        );
                        $includedTax = [
                            'included_tax_amount' => CartManager::getIncludedTaxAmount(
                                product: $cartItem->product,
                                taxConfig: $taxConfig,
                                totalDiscountedPrice: $totalDiscountPrice,
                                appliedDiscountedAmount: $appliedDiscountAmount,
                            ),
                            'included_shipping_cost_tax' => $includedShippingTax,
                        ];
                        $vendorWiseCartIncludedTax += $includedTax['included_tax_amount'] + $includedShippingTax;
                    }

                    $vendorWiseCart['applied_tax_cart_list'][] = $includedTax + [
                        'cart_id' => $cartItem->id,
                        'cart_group_id' => $cartItem->cart_group_id,
                        'product_id' => $cartItem->product->id,
                        'quantity' => $cartItem->quantity,
                        'price' => $cartItem->price,
                        'discount' => $cartItem->discount,
                        'seller_id' => $cartItem->seller_id,
                        'seller_is' => $cartItem->seller_is,
                        'discounted_price' => ($cartItem->price - $cartItem->discount),
                        'total_discounted_price' => $totalDiscountPrice,
                        'applied_discounted_amount' => $appliedDiscountAmount,
                        'applied_tax_amount' => $appliedTaxAmount,
                        'applied_shipping_cost_tax' => $cartItem?->product?->product_type == 'digital' ? 0 : $appliedShippingTaxAmount,
                        'applied_tax_ids' => $appliedTaxIds,
                        'applied_shipping_cost_tax_ids' => $cartItem?->product?->product_type == 'digital' ? [] : $appliedShippingTaxIds,
                    ];
                }
            }
            $vendorWiseCart['total_tax_amount'] = $vendorWiseCartAppliedTax;
            $vendorWiseCart['order_amount_with_tax'] = $vendorWiseCart['order_amount'] + $vendorWiseCartAppliedTax;

            // Recorded beside the order's amount and never added to it: the tax is already in the price the customer agreed to, so an
            // order that records it costs the customer the same as one that does not.
            if ($recordIncludedTax) {
                $vendorWiseCart['included_tax_amount'] = $vendorWiseCartIncludedTax;
            }
            $modifiedVendorWiseCartList[] = $vendorWiseCart;
        }

        return $modifiedVendorWiseCartList;
    }

    public static function getOrderAddData(int $orderId, string $orderGroupId, object|array $customerData = [], object|array $cartData = [], object|array $orderData = [], object|array $totalTax = []): array
    {
        $taxConfig = self::getTaxSystemType();
        $adminCommission = (float)str_replace(",", "", Helpers::sales_commission_before_order($cartData['cart_group_id'], $cartData['coupon_discount']));

        // Which rule produced the shipping charge (H1-08), added only where the column exists: a shop that has not run the migration must
        // place orders exactly as it does today, so the key is absent rather than null.
        $rateSource = \Illuminate\Support\Facades\Schema::hasColumn('orders', 'shipping_rate_source')
            ? ['shipping_rate_source' => $cartData['shipping_rate_source'] ?? null]
            : [];

        // The tax already inside a tax-included order's price (D1-07). Added only where the cart worked it out -- which it does only while
        // `gcc.tax.record_included_amount` is on -- and only where the column exists, so an order of a store that has answered neither
        // question is written with neither key and the column stays NULL rather than claiming a zero.
        $includedTax = (isset($cartData['included_tax_amount']) && \Illuminate\Support\Facades\Schema::hasColumn('orders', 'included_tax_amount'))
            ? ['included_tax_amount' => $cartData['included_tax_amount']]
            : [];

        return $rateSource + $includedTax + [
            'id' => $orderId,
            'verification_code' => random_int(100000, 999999),
            'customer_id' => $customerData['customer_id'],
            'is_guest' => $customerData['is_guest'],
            'seller_id' => $cartData['seller_id'],
            'seller_is' => $cartData['seller_is'],
            'customer_type' => 'customer',
            'payment_status' => $orderData['payment_status'],
            'order_status' => $orderData['order_status'],
            'payment_method' => $orderData['payment_method'],
            'transaction_ref' => $orderData['transaction_ref'] ?? null,
            'payment_by' => $orderData['payment_by'] ?? NULL,
            'payment_note' => $orderData['payment_note'] ?? NULL,
            'order_group_id' => $orderGroupId,
            'discount_amount' => $cartData['coupon_discount'],
            'discount_type' => $cartData['discount_type'],
            'coupon_code' => $cartData['coupon_code'],
            'coupon_discount_bearer' => $cartData['coupon_bearer'],
            'order_amount' => $cartData['order_amount_with_tax'] - $cartData['refer_and_earn_discount'],
            'init_order_amount' => $cartData['order_amount_with_tax'] - $cartData['refer_and_earn_discount'],
            'total_tax_amount' => $cartData['total_tax_amount'],
            'tax_type' => $taxConfig['SystemTaxVatType'],
            'tax_model' => $taxConfig['is_included'] ? 'include' : 'exclude',
            'bring_change_amount' => $orderData['payment_method'] == 'cash_on_delivery' ? $orderData['bring_change_amount'] ?? 0 : null,
            'bring_change_amount_currency' => $orderData['bring_change_amount_currency'] ?? null,
            'admin_commission' => $adminCommission,
            'shipping_address' => $cartData['shipping_address_id'],
            'shipping_address_data' => self::getOrderAddressSnapshot(addressId: $cartData['shipping_address_id']),
            // Falls back to the shipping address so every order carries a billing address.
            'billing_address' => $cartData['billing_address_id'] ?: $cartData['shipping_address_id'],
            'billing_address_data' => self::getOrderAddressSnapshot(addressId: $cartData['billing_address_id'] ?: $cartData['shipping_address_id']),
            'shipping_responsibility' => getWebConfig(name: 'shipping_method'),
            'shipping_cost' => $cartData['shipping_cost'],
            'extra_discount' => $cartData['free_delivery_discount'],
            'extra_discount_type' => $cartData['extra_discount_type'],
            'refer_and_earn_discount' => $cartData['refer_and_earn_discount'],
            'free_delivery_bearer' => $cartData['free_delivery_bearer'],
            'is_shipping_free' => $cartData['is_shipping_free'],
            'shipping_method_id' => $cartData['shipping_method_id'],
            'shipping_type' => $cartData['shipping_type'],
            'created_at' => now(),
            'updated_at' => now(),
            'order_note' => $orderData['order_note'] ?? session('order_note'),
        ];
    }

    /**
     * Write the immutable commission snapshot for one order line (Phase 3, Stage B).
     *
     * Wrapped whole: marketplace accounting must never be the reason an order fails. If the table
     * is absent because the migration has not run, or anything else goes wrong, the order still
     * completes — the worst outcome is a line without a snapshot, which reconciliation can surface.
     *
     * The unique key on order_details_id makes this idempotent, so the order transaction retrying
     * on a deadlock or an id collision cannot double-charge commission.
     */
    public static function recordOrderItemCommission(int|string $orderId, int|string $orderDetailsId, $cartItem, $product, float $netLineAmount): void
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('order_item_commissions')) {
                return;
            }

            $sellerIs = $cartItem['seller_is'] ?? 'admin';
            $sellerId = $cartItem['seller_id'] ?? null;

            // `products.category_id` is the leaf category; category rules are matched against it.
            $categoryId = $product['category_id'] ?? null;

            $calculated = app(\App\Services\Marketplace\CommissionEngine::class)->calculate(
                line: [
                    'product_id' => $cartItem['product_id'] ?? ($product['id'] ?? null),
                    'category_id' => $categoryId,
                    'seller_id' => $sellerId,
                    'seller_is' => $sellerIs,
                ],
                commissionableAmount: $netLineAmount,
            );

            $snapshot = \App\Models\OrderItemCommission::updateOrCreate(
                ['order_details_id' => $orderDetailsId],
                array_merge($calculated, [
                    'order_id' => $orderId,
                    'product_id' => $cartItem['product_id'] ?? ($product['id'] ?? null),
                    'seller_id' => $sellerId,
                    'seller_is' => $sellerIs,
                    'commission_rule_id' => $calculated['rule_id'],
                    // Base currency, not the viewer's display currency — the amounts are stored in base.
                    'currency' => optional(\App\Models\Currency::find(getWebConfig(name: 'system_default_currency')))->code ?? session('currency_code'),
                ])
            );

            // The seller's side of the same event, on their ledger.
            //
            // Two entries, not one net figure: the sale and the marketplace's cut are separate
            // business events, and netting them at write time destroys the ability to answer "how
            // much did we charge this seller in commission last month?" without re-deriving it.
            //
            // They land as PENDING, because money owed during the return window is not money the
            // seller can be paid. The earning carries an `available_at` = now + the return window so the
            // settlement command can mature it once the window passes AND the order is delivered
            // (VendorLedger::releaseMatured gates on order state, so a cancelled/failed order never
            // matures its placement-time earning). Without this timestamp the earning could never leave
            // pending and no seller could ever be settled or paid.
            if ($sellerIs === 'seller' && $sellerId) {
                $ledger = app(\App\Services\Marketplace\VendorLedger::class);

                $returnWindowDays = (int) app(\App\Services\Marketplace\CategoryGovernanceService::class)->returnWindowDays($categoryId);
                // The earning and its commission MUST mature together. releaseMatured() only matures
                // pending entries whose available_at is set (whereNotNull('available_at')); a commission
                // debit booked with a NULL available_at would stay pending forever, so settlement (which
                // claims only `available`) would settle the GROSS earning and withdrawable (balance −
                // pending) would add the pending commission debit back — the marketplace would collect no
                // commission and the seller could draw the gross. Same timestamp => both mature as one.
                $availableAt = now()->addDays(max(0, $returnWindowDays));

                // Whose money this is: the company the ORDER was stamped with when it was placed, read once for both entries. The order's own
                // frozen copy, never a fresh lookup of the registry, so a company that changes later never reaches money already recorded, and
                // the earning and its commission can never land in two different companies. Null while legal entities are off.
                $legalEntityId = app(\App\Services\Storefront\OrderLegalEntitySnapshot::class)->companyOf(orderId: $orderId);

                $ledger->record(
                    sellerId: $sellerId,
                    entryType: \App\Models\VendorLedgerEntry::TYPE_ORDER_EARNING,
                    credit: (float) $calculated['commissionable_amount'],
                    referenceType: 'order_details',
                    referenceId: $orderDetailsId,
                    description: 'Order #' . $orderId,
                    availableAt: $availableAt,
                    legalEntityId: $legalEntityId,
                );

                if ((float) $calculated['commission_amount'] > 0) {
                    $ledger->record(
                        sellerId: $sellerId,
                        entryType: \App\Models\VendorLedgerEntry::TYPE_COMMISSION_CHARGE,
                        debit: (float) $calculated['commission_amount'],
                        referenceType: 'order_details',
                        referenceId: $orderDetailsId,
                        description: $calculated['rule_label'] ?? 'Marketplace commission',
                        availableAt: $availableAt,
                        legalEntityId: $legalEntityId,
                    );
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Commission snapshot failed for order detail ' . $orderDetailsId . ': ' . $exception->getMessage());
        }
    }

    /**
     * Save an order's lines, then freeze the company that sold it, then record the money that follows from them.
     *
     * The three steps are in that order on purpose, and the order is the whole point: see the comment at the stamp below.
     */
    public static function addOrderDetailsData(int $orderId, object|array|null $vendorCart = []): void
    {
        $orderDetailsRewardsData = [];
        $savedLines = [];
        $totalPrice = 0;

        $taxConfig = self::getTaxSystemType();

        // Reject overselling by default; the post-payment path opts out (see generateOrder).
        $stockPolicy = $vendorCart['stock_policy'] ?? 'reject';

        foreach ($vendorCart['cart_list'] as $cartSingleItem) {
            // lockForUpdate() serialises concurrent orders for the same product for the rest of this
            // transaction. It is what makes the variant read-modify-write below safe: that block
            // decodes `variation`, subtracts from one variant and writes the whole JSON back, so
            // without a lock two orders for the same variant read the same array and one decrement
            // is lost outright — the identical bug that was fixed for `current_stock`, still live on
            // the variant path.
            $productModel = Product::where(['id' => $cartSingleItem['product_id']])->with(['digitalVariation', 'clearanceSale' => function ($query) {
                return $query->active();
            }])->lockForUpdate()->first();
            $product = $productModel->toArray();
            unset($product['is_shop_temporary_close']);
            unset($product['meta_image_full_url']);
            unset($product['reviews']);
            unset($product['order_details']);
            unset($product['order_delivered']);
            unset($product['seo_info']);

            if (!isset($product['digital_variation'])) {
                $allDigitalVariation = DigitalProductVariation::with(['storage'])->where(['product_id' => $cartSingleItem['product_id']])->get()->toArray() ?? [];
                $product['digital_variation'] = $allDigitalVariation;
            }

            $digitalProductVariation = DigitalProductVariation::with(['storage'])->where(['product_id' => $cartSingleItem['product_id'], 'variant_key' => $cartSingleItem['variant']])->first();
            if ($product['digital_product_type'] == 'ready_product' && $digitalProductVariation) {
                $getStoragePath = Storage::where([
                    'data_id' => $digitalProductVariation['id'],
                    "data_type" => "App\Models\DigitalProductVariation",
                ])->first();

                $product['digital_file_ready'] = $digitalProductVariation['file'];
                $product['storage_path'] = $getStoragePath ? $getStoragePath['value'] : 'public';
            } elseif ($product['digital_product_type'] == 'ready_product' && !empty($product['digital_file_ready'])) {
                $product['storage_path'] = $product['digital_file_ready_storage_type'] ?? 'public';
            }

            $productDiscount = getProductPriceByType(product: $product, type: 'discounted_amount', result: 'value', price: $cartSingleItem['price']);
            $orderDetails = [
                'order_id' => $orderId,
                'product_id' => $cartSingleItem['product_id'],
                'seller_id' => $cartSingleItem['seller_id'],
                'product_details' => json_encode($product),
                'qty' => $cartSingleItem['quantity'],
                'price' => $cartSingleItem['price'],
                'discount' => $productDiscount * $cartSingleItem['quantity'],
                'discount_type' => 'discount_on_product',
                'variant' => $cartSingleItem['variant'],
                'variation' => $cartSingleItem['variations'],
                'delivery_status' => 'pending',
                'shipping_method_id' => null,
                'payment_status' => 'unpaid',
                'created_at' => now(),
                'updated_at' => now()
            ];

            $appliedTaxAmount = 0;
            $appliedTaxRate = 0;
            foreach ($vendorCart['applied_tax_cart_list'] as $cartItem) {
                if ($cartItem['seller_id'] == $cartSingleItem['seller_id'] && $cartItem['seller_is'] == $cartSingleItem['seller_is']) {
                    $appliedTaxAmount = collect($vendorCart['applied_tax_cart_list'])->where('cart_id', $cartSingleItem['id'])->sum('applied_tax_amount') ?? 0;
                    foreach ($cartItem['cart_id'] == $cartSingleItem['id'] ? $cartItem['applied_tax_ids'] : [] as $taxItem) {
                        if ($taxItem) {
                            $appliedTaxRate += $taxItem['tax_rate'];
                        }
                    }
                }
            }

            $orderDetails['tax'] = $appliedTaxAmount;
            $orderDetails['tax_model'] = $taxConfig['is_included'] ? 'include' : 'exclude';

            // The share of the included tax this line carries (D1-07), mirroring `tax` above: the product's own tax and not the shipping
            // tax, which only the order records. The key the cart carries is what says the recording is on.
            if (isset($vendorCart['included_tax_amount']) && Schema::hasColumn('order_details', 'included_tax')) {
                $orderDetails['included_tax'] = collect($vendorCart['applied_tax_cart_list'])->where('cart_id', $cartSingleItem['id'])->sum('included_tax_amount');
            }

            $finalAmount = ($cartSingleItem['price'] - $productDiscount) * $cartSingleItem['quantity'];
            $totalPrice += $finalAmount;

            if ($cartSingleItem['variant'] != null) {
                $type = $cartSingleItem['variant'];
                $variationData = [];
                $variantWentNegative = false;
                foreach (json_decode($product['variation'], true) as $var) {
                    if ($type == $var['type']) {
                        $var['qty'] -= $cartSingleItem['quantity'];
                        if ($var['qty'] < 0) {
                            $variantWentNegative = true;
                        }
                    }
                    $variationData[] = $var;
                }

                // Guard the SPECIFIC variant, not just the product total. The conditional decrement below
                // checks current_stock (the sum of all variants), so ordering more of one variant than it
                // holds could pass — another variant's stock masking the shortfall — and drive that
                // variant negative. Reject it here, inside the same locked/transactional section, exactly
                // as the product-total guard does. (allow_oversell — the post-payment path — is honoured.)
                if ($variantWentNegative && ($product['product_type'] ?? 'physical') === 'physical' && $stockPolicy === 'reject') {
                    throw new \App\Exceptions\InsufficientStockException(
                        productId: $product['id'],
                        productName: $product['name'] ?? null,
                        requested: (int) $cartSingleItem['quantity'],
                    );
                }

                Product::where(['id' => $product['id']])->update([
                    'variation' => json_encode($variationData),
                ]);
            }
            // An atomic decrement, not a computed absolute value.
            //
            // This used to write `current_stock => $product['current_stock'] - $quantity`, where
            // current_stock came from a model read earlier in the request. Two orders overlapping
            // therefore both read the same number and both wrote their own absolute result, so one
            // decrement was lost entirely. Demonstrated: stock 10, two orders of 3, final stock 7
            // instead of 4.
            //
            // `decrement` issues `SET current_stock = current_stock - N`, which the database
            // resolves per statement, so concurrent orders subtract from each other rather than
            // overwriting each other. Inventory stops drifting upward, and the store stops showing
            // stock it does not have.
            //
            // The decrement is now also CONDITIONAL for physical products: `where('current_stock',
            // '>=', $qty)` means the database itself refuses to take stock that is not there, and
            // an affected-row count of zero is the signal that it refused. Atomicity alone stopped
            // the arithmetic being wrong; it did not stop stock going negative.
            //
            // Digital products keep the unconditional decrement they always had — they have no
            // real stock, so guarding them would reject orders for a number that means nothing.
            $orderedQuantity = (int) $cartSingleItem['quantity'];
            $isPhysical = ($product['product_type'] ?? 'physical') === 'physical';

            if ($isPhysical && $stockPolicy === 'reject') {
                $stockTaken = Product::where(['id' => $product['id']])
                    ->where('current_stock', '>=', $orderedQuantity)
                    ->decrement('current_stock', $orderedQuantity);

                if ($stockTaken === 0) {
                    throw new \App\Exceptions\InsufficientStockException(
                        productId: $product['id'],
                        productName: $product['name'] ?? null,
                        requested: $orderedQuantity,
                    );
                }
            } else {
                Product::where(['id' => $product['id']])->decrement('current_stock', $orderedQuantity);
            }

            $orderDetailsId = DB::table('order_details')->insertGetId($orderDetails);

            // Freeze who fulfils this line (M5AZN / Torod Point / in-house), here, inside the order
            // transaction. A product with no provider — every existing one — writes nothing. A
            // provider-owned product must be recorded or the order rolls back: it may not silently
            // fall through to in-house handling.
            app(OrderItemOwnershipSnapshotter::class)->capture(
                orderId: $orderId,
                orderDetailId: $orderDetailsId,
                product: $product,
                variant: $cartSingleItem['variant'],
                quantity: $orderedQuantity,
                unitPrice: (float) $cartSingleItem['price'],
                netAmount: (float) $finalAmount,
                lineTax: (float) $appliedTaxAmount,
                taxModel: (string) $orderDetails['tax_model'],
            );

            // Log the sale into the stock-movement history. TYPE_SALE was defined but never emitted, so
            // the movement ledger was missing its largest, most frequent movement and could never
            // reconcile against current_stock. Physical products only, and non-throwing — a missing log
            // line must never roll back the order.
            if ($isPhysical) {
                try {
                    $balanceAfter = (int) (Product::where('id', $product['id'])->value('current_stock') ?? 0);
                    app(\App\Services\Marketplace\InventoryService::class)->record(
                        productId: $product['id'],
                        type: \App\Models\StockMovement::TYPE_SALE,
                        qtyChange: -$orderedQuantity,
                        balanceAfter: $balanceAfter,
                        referenceType: 'order',
                        referenceId: $orderId,
                        sellerId: ($product['added_by'] ?? 'admin') === 'admin' ? null : ($product['user_id'] ?? null),
                    );
                } catch (\Throwable $exception) {
                    Log::warning('Sale stock-movement log failed for order detail ' . $orderDetailsId . ': ' . $exception->getMessage());
                }
            }

            $savedLines[] = [
                'cart_item' => $cartSingleItem,
                'product' => $product,
                'order_details_id' => $orderDetailsId,
                'net_amount' => $finalAmount,
                'applied_tax_amount' => $appliedTaxAmount,
                'applied_tax_rate' => $appliedTaxRate,
            ];
        }

        // The company that sold this order is frozen here: once every line is saved, and before a single figure of the order's money names one.
        //
        // It has to be both. The country an order belongs to is read from the products of the lines it was saved with, so nothing can be frozen
        // while they are still being written; and recordOrderItemCommission below reads that frozen company for the seller's earning and for the
        // commission charged on it, so a stamp written after them reaches no row the order wrote — which is what used to happen, and why every
        // ledger row of a running system named no company however the registry was set up. Stamping mid-loop would be worse than either: the first
        // line's country would be frozen and the lines after it ignored, because an order is stamped once and never restamped.
        app(OrderStorefrontSnapshot::class)->record(orderId: $orderId);

        // What the shipping engine decided about this order, kept on the order (H1-06), from the order's own lines and the address copy it
        // keeps -- the same question the checkout asked before payment, asked of what the order actually holds. A decision that is
        // recomputed later is a decision that changes, so it is written once and never rewritten. Silent while the cross-border switch is
        // off, which is how the platform ships.
        OrderShippingDecision::record($orderId, app(ShippingEligibility::class)->decideForOrder($orderId));

        // And what was quoted for customs duty (H1-09), beside it and for the same reason: a tariff changes, a rule is written or
        // withdrawn, and an order recomputed a year later would show a figure nobody ever charged. Silent while duty is off.
        OrderCustomsQuote::record($orderId);

        // And the coupon this order redeemed (I1-14), from the order's own row: the code it carries, the market it was sold in and the
        // money it was priced in. One row per ORDER GROUP, so a group of two vendors reaches this twice and writes one redemption -- the
        // discount is re-summed from the group each time rather than added to. Silent while the coupon ledger is off.
        CouponRedemption::record($orderId);

        foreach ($savedLines as $savedLine) {
            // Freeze the commission for this line, here, inside the order transaction.
            //
            // Until now the only figure kept was one aggregate `orders.admin_commission`, derived
            // from whatever the seller's percentage happened to be that day. Change the rate and
            // there is no record of what an old order was charged under; refund one line of five
            // and its commission cannot be netted off, because it was never a separate number.
            //
            // Written once and never recalculated: settlements and statements read this rather than
            // re-deriving from live rules, which is the difference between accounting and guessing.
            OrderManager::recordOrderItemCommission(
                orderId: $orderId,
                orderDetailsId: $savedLine['order_details_id'],
                cartItem: $savedLine['cart_item'],
                product: $savedLine['product'],
                netLineAmount: $savedLine['net_amount'],
            );

            self::addOrderLineTaxDetails(orderId: $orderId, taxConfig: $taxConfig, vendorCart: $vendorCart, savedLine: $savedLine);

            $orderDetailsRewardsData[] = [
                'order_id' => $orderId,
                'product_id' => $savedLine['cart_item']['product_id'],
                'order_details_id' => $savedLine['order_details_id'],
                'price' => $savedLine['net_amount'],
                'coupon_code' => $vendorCart['coupon_code'],
                'coupon_discount' => $vendorCart['coupon_discount'],
            ];
        }

        foreach ($orderDetailsRewardsData as $key => $orderReward) {
            self::addOrderDetailsRewardData(
                orderId: $orderReward['order_id'],
                productId: $orderReward['product_id'],
                orderDetailsId: $orderReward['order_details_id'],
                price: $orderReward['price'],
                totalPrice: $totalPrice,
                couponCode: $orderReward['coupon_code'],
                couponDiscount: $orderReward['coupon_discount'],
            );
        }
    }

    /**
     * The tax rows of one saved order line: one for each rate the cart applied to it, each carrying its share of the line's tax.
     *
     * @param array<string, mixed> $taxConfig
     * @param array<string, mixed> $savedLine
     */
    private static function addOrderLineTaxDetails(int $orderId, array $taxConfig, object|array|null $vendorCart, array $savedLine): void
    {
        $cartSingleItem = $savedLine['cart_item'];
        $appliedTaxAmount = $savedLine['applied_tax_amount'];
        $appliedTaxRate = $savedLine['applied_tax_rate'];
        $finalAmount = $savedLine['net_amount'];

        foreach ($vendorCart['applied_tax_cart_list'] as $cartItem) {
            if ($cartItem['cart_id'] == $cartSingleItem['id']) {
                foreach ($cartItem['applied_tax_ids'] as $taxItem) {
                    if ($taxItem) {
                        self::getAddOrderTaxDetails(
                            systemTaxVat: $taxConfig['SystemTaxVat'],
                            taxRate: $taxItem,
                            orderId: $orderId,
                            data: [
                                'tax_amount' => ($appliedTaxAmount > 0 && $appliedTaxRate > 0) ? ($appliedTaxAmount * $taxItem['tax_rate']) / $appliedTaxRate : 0,
                                'before_tax_amount' => $finalAmount,
                                'after_tax_amount' => $finalAmount + $appliedTaxAmount,
                                'quantity' => $cartSingleItem['quantity'],
                                'seller_id' => $cartSingleItem['seller_id'],
                                'seller_type' => $cartSingleItem['seller_is'],
                            ]
                        );
                    }
                }
            }
        }
    }

    public static function addOrderDetailsRewardData($orderId, $productId, $orderDetailsId, $price, $totalPrice, $couponCode, $couponDiscount): void
    {
        // Through getWebConfig, so a market with its own loyalty programme records its own points on its own lines (E-02); the platform's
        // market, and anything serving no market, read the platform's values exactly as before.
        $businessSettings = [];
        foreach (self::getLoyaltyPointKeys() as $loyaltyPointKey) {
            $businessSettings[$loyaltyPointKey] = getWebConfig(name: $loyaltyPointKey);
        }
        $loyaltyPointStatus = (int)($businessSettings['loyalty_point_status'] ?? 0);
        $loyaltyPointForEachOrder = (int)($businessSettings['loyalty_point_for_each_order'] ?? 0);

        if (!empty($couponCode)) {
            $coupon = Coupon::where('code', $couponCode)->where('status', 1)->first();
            if ($couponDiscount > 0) {
                $individualCouponDiscount = ((float)$couponDiscount * (float)$price) / $totalPrice;
                $coupon['individual_coupon_discount'] = $individualCouponDiscount;
            }
            $orderRewardDetails = self::getOrderRewardDetails(
                type: 'coupon',
                data: $coupon,
                orderId: $orderId,
                orderDetailsId: $orderDetailsId
            );
            if (!empty($orderRewardDetails)) {
                OrderDetailsRewards::updateOrCreate([
                    'order_id' => $orderId,
                    'order_details_id' => $orderDetailsId,
                    'reward_type' => 'coupon'
                ], $orderRewardDetails);
            }
        }
        if ($loyaltyPointStatus && $loyaltyPointForEachOrder) {
            if (isset($businessSettings['loyalty_point_item_purchase_point']) && $businessSettings['loyalty_point_item_purchase_point'] > 0) {
                $loyaltyPointCredit = (float)($price * $businessSettings['loyalty_point_item_purchase_point'] / 100);
                $individualLoyaltyPoint = ($loyaltyPointCredit * (float)$price) / $totalPrice;
                $businessSettings['individual_loyalty_point'] = $individualLoyaltyPoint;
                $orderRewardDetails = self::getOrderRewardDetails(
                    type: 'loyalty_point',
                    data: $businessSettings,
                    orderId: $orderId,
                    orderDetailsId: $orderDetailsId
                );
                if (!empty($orderRewardDetails)) {
                    OrderDetailsRewards::updateOrCreate([
                        'order_id' => $orderId,
                        'order_details_id' => $orderDetailsId,
                        'reward_type' => 'loyalty_point'
                    ], $orderRewardDetails);
                }
            }
        }
    }

    public static function getAddOrderTaxDetails($systemTaxVat, $taxRate, $orderId, $data = [], $taxOn = 'basic'): void
    {
        \Modules\TaxModule\app\Models\OrderTax::insert([
            'tax_name' => $taxRate['name'],
            'tax_type' => $systemTaxVat->tax_type,
            'tax_on' => $taxOn,
            'tax_rate' => $taxRate['tax_rate'],
            'tax_amount' => $data['tax_amount'] ?? 0,
            'before_tax_amount' => $data['before_tax_amount'] ?? 0,
            'after_tax_amount' => $data['after_tax_amount'] ?? 0,
            'tax_payer' => 'vendor',
            'order_id' => $orderId,
            'order_type' => 'order',
            'quantity' => $data['quantity'] ?? 0,
            'tax_id' => $taxRate['id'],
            // NULL, not '': both columns are nullable() and an ORDER-level tax row has no taxable entity, so '' was
            // never a value — it was a non-strict server quietly coercing it. On a server with STRICT_ALL_TABLES the
            // same insert is refused outright with "1366 Incorrect integer value: '' for column
            // order_taxes.taxable_id", and because this insert is inside the sale's transaction the WHOLE SALE rolls
            // back: on a strict server no sale carrying any tax could be written at all, by any till or by the web
            // checkout. Found by this branch's own VAT test in CI's strict-mode group, which is the first test in the
            // repository to ring up a taxed sale. Nothing reads order_taxes.taxable_id anywhere.
            'taxable_id' => null,
            'taxable_type' => null,
            'seller_id' => $data['seller_id'] ?? 0,
            'seller_type' => $data['seller_type'] ?? 'admin',
            'system_tax_setup_id' => $systemTaxVat->id,
            'tax_for' => 'tax_for_orders',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function generateOrder(object|array|null $data = []): array
    {
        // Why the LAST call refused, if it did, belongs to that call. A console command or a queue worker places more than one order in one
        // process, and a reason that outlived its call would be reported against the next customer's order.
        OrderRefused::forget();

        $taxConfig = self::getTaxSystemType();
        $orderPlacedIds = [];
        $orderPlacedNotificationEvents = [];
        $orderPlacedMailEvents = [];
        $getCustomerInfo = OrderManager::getCustomerInfoForOrder(data: [
            'is_guest' => $data['is_guest'] ?? null,
            'guest_id' => $data['guest_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'newCustomerRegister' => $data['newCustomerRegister'] ?? null,
            'new_customer_id' => $data['new_customer_id'] ?? null,
            'requestObj' => $data['requestObj'] ?? null,
        ]);
        $orderGroupId = OrderManager::generateUniqueOrderID();
        $vendorWiseCartList = OrderManager::processOrderGenerateData(data: [
            'coupon_code' => $data['coupon_code'] ?? (session('coupon_code') ?? ''),
            'address_id' => $data['address_id'] ?? session('address_id'),
            'billing_address_id' => $data['billing_address_id'] ?? session('billing_address_id'),
            'requestObj' => $data['requestObj'] ?? null,
        ]);
        $vendorWiseCartList = OrderManager::scopeOrderAddresses(vendorWiseCartList: $vendorWiseCartList, data: $data, customerInfo: $getCustomerInfo);

        // No company may invoice another company's sale, so an order never carries two countries' or two selling companies' rows
        // (D1-02). The cart keeps a storefront's rows apart, but only for a request a storefront was resolved for and only while
        // storefronts are on; this reads the rows that are about to be written, so the rule holds for every caller. Before the
        // transaction, so an order that would mix is refused without opening one and is never partly written; and again inside it on
        // the locked rows, which are the ones the lines are written from.
        OneSellingCompanyPerOrder::assertRowsBelongToOne(
            cartRows: collect($vendorWiseCartList)->pluck('cart_list')->flatten(1),
        );

        // Everything that writes an order is one transaction; everything with a side effect the
        // database cannot undo — notifications, emails, clearing the cart, the session — stays
        // outside it and runs only after the commit. The loop below was already written that way:
        // it *collects* notification and mail payloads and fires them further down, so this
        // boundary follows a seam that already existed rather than restructuring the path.
        //
        // Without it, a failure part-way through left some stock decremented and some order rows
        // written, with nothing to undo either.
        //
        // The post-payment caller opts out of rejection: by then the customer's money has been
        // taken, and refusing the order would leave them paid with nothing to show for it, which is
        // strictly worse than an oversold line a human can reconcile. See generateOrder's callers.
        $stockPolicy = ($data['stock_policy'] ?? 'reject') === 'allow_oversell' ? 'allow_oversell' : 'reject';

        // A wallet checkout pays inside this transaction: the debit is written with the order, and a refused debit rolls the order
        // back. Debiting after the commit would leave a paid order behind whenever the debit is refused.
        $walletPayment = isset($data['walletPayment'])
            ? [
                'customerId' => $data['walletPayment']['customerId'],
                'amount' => (float) $data['walletPayment']['amount'],
                'amountInUsd' => (bool) ($data['walletPayment']['amountInUsd'] ?? false),
            ]
            : null;

        // Which submit of the customer's checkout this is (D1-14), read from the request before the transaction opens because the key is
        // the client's header and the transaction is no place to read a request from. Null for every checkout of this platform today:
        // no app sends the header and the table it is claimed in arrives empty.
        $checkoutSubmit = OneOrderPerCheckoutSubmit::of(data: $data, customerInfo: $getCustomerInfo);

        // Whether this call answered with an EARLIER submit's orders instead of writing its own. Such a call must leave nothing behind
        // but the answer: it did not place the orders it is reporting, so it must not clear the cart it happened to read on the way in.
        // A customer who added a line and retried a stale key would otherwise lose that line to an order that never contained it.
        $answeredFromAnEarlierSubmit = false;

        // Order ids are chosen by generateNewOrderID(), not by the database, so two checkouts that
        // start together can pick the same number and the second insert dies on the primary key.
        // Observed on the running store: "Duplicate entry '100002' for key 'PRIMARY'" — a hard 500
        // at the last step of checkout. Locking does not help, because the row being counted may
        // not exist yet and an absent row cannot be locked. Re-running the transaction does: each
        // attempt re-reads the id, so the loser of the race simply takes the next number.
        for ($idCollisionAttempt = 1; ; $idCollisionAttempt++) {
        try {
            DB::transaction(function () use (
                $vendorWiseCartList, $taxConfig, $getCustomerInfo, $orderGroupId, $data, $stockPolicy, $walletPayment, $checkoutSubmit,
                &$orderPlacedIds, &$orderPlacedNotificationEvents, &$orderPlacedMailEvents, &$answeredFromAnEarlierSubmit
            ) {
                // Reset per attempt. DB::transaction retries on deadlock, and these accumulate —
                // without this a retried attempt would report each order twice.
                $orderPlacedIds = [];
                $orderPlacedNotificationEvents = [];
                $orderPlacedMailEvents = [];

                // One paid payment builds one order, even when two deliveries of its callback are in flight (D1-328). The settlement
                // hook's own check is a read, and two deliveries both pass a read before either writes. The claim is made HERE, in the
                // transaction the order is written in, so the lock is released the moment the order is committed and a build that fails
                // gives it back: OneOrderPerPayment says why neither property survives taking it anywhere else. It is a no-op for every
                // checkout that carries no gateway reference, which is every unpaid path.
                if (!app(OneOrderPerPayment::class)->mayBuild(
                    paymentId: $data['payment_record_id'] ?? null,
                    transactionRef: $data['transaction_ref'] ?? null,
                    paymentStatus: $data['payment_status'] ?? null,
                )) {
                    return;
                }

                // One submit of a checkout places one order, however many times that submit arrives (D1-14). The claim is an INSERT under
                // a unique key, made here so that the row is the lock a second submit waits on, a failed order write frees the key, and the
                // repeat is ANSWERED WITH the orders the first submit placed rather than refused: the customer who tapped twice meant one
                // order, and one order is what they get back. OneOrderPerCheckoutSubmit says why none of that survives moving the claim.
                if ($checkoutSubmit !== null && !$checkoutSubmit->claim()) {
                    $orderPlacedIds = $checkoutSubmit->theOrdersItAlreadyPlaced();
                    $answeredFromAnEarlierSubmit = true;

                    return;
                }

                // The customer row is locked before any product row, and the wallet writer locks the same row again. Every wallet
                // checkout therefore takes its locks in one order, customer then stock, and two of them cannot wait on each other.
                if ($walletPayment !== null) {
                    User::query()->lockForUpdate()->find($walletPayment['customerId']);
                }

                // Lock every product in the order up front, ordered by id. Two carts holding the
                // same two products in opposite orders would otherwise each hold the row the other
                // needs — a deadlock. Acquiring them in one deterministic order removes the cycle;
                // the retry count below is the belt to this pair of braces.
                $lockProductIds = collect($vendorWiseCartList)
                    ->pluck('cart_list')->flatten(1)->pluck('product_id')
                    ->filter()->unique()->sort()->values()->all();

                if ($lockProductIds) {
                    $lockedProducts = Product::whereIn('id', $lockProductIds)->orderBy('id')->lockForUpdate()->get();

                    // Judged again, on the locked rows. The assertion above read the copies the cart loaded before the transaction
                    // opened; addOrderDetailsData re-reads each product under this lock and writes the line from what it finds, so a
                    // product moved between the two reads would be judged on the old row and invoiced from the new one. These are the
                    // rows the lines are written from, already read for the lock, so the order costs no extra query.
                    OneSellingCompanyPerOrder::assertProductsBelongToOne(products: $lockedProducts);
                }

                // The coupon's cap, budget and per-customer limit, judged HERE and not only at eligibility time (I1-14, the owner's
                // decision of 2026-11-26). The eligibility check is a read, and two checkouts both pass a read before either records a
                // redemption, so a coupon capped at one redemption was granted twice. CouponGrant takes the coupon's own row FOR UPDATE
                // and counts the ledger with a locking read; read it for why BOTH are needed and why SQLite can prove neither.
                //
                // Third in the lock order -- the customer's row, then the products, then the coupon -- so that every transaction that
                // reaches here has taken the same locks in the same sequence and two of them cannot wait on each other.
                //
                // Dormant while `gcc.coupons.limits.enabled` is off, which is how the platform ships: no lock is taken, nothing is
                // counted, and an order carrying a coupon is written exactly as it is today.
                $couponRefusal = CouponGrant::refusalForTheCart(
                    vendorWiseCartList: $vendorWiseCartList,
                    customerId: $getCustomerInfo['customer_id'],
                );

                if ($couponRefusal !== null) {
                    ['code' => $refusedCode, 'discount' => $refusedDiscount] = CouponGrant::couponOfTheCart($vendorWiseCartList);

                    // The post-payment caller opts out of this refusal for the same reason it opts out of rejecting an oversold line: the
                    // gateway has already taken the discounted amount, and refusing the order would leave the customer paid with nothing
                    // to show for it. One redemption past a cap is a figure a human can reconcile against a real payment record; a paid
                    // customer with no order is not. So it is reported and allowed.
                    if ($stockPolicy === 'allow_oversell') {
                        Log::warning('A paid order redeemed a coupon that had already reached a limit of its own.', [
                            'reason' => $couponRefusal,
                            'coupon_code' => $refusedCode,
                            'discount' => $refusedDiscount,
                            'order_group_id' => $orderGroupId,
                            'customer_id' => $getCustomerInfo['customer_id'],
                        ]);
                    } else {
                        throw new CouponNoLongerAvailableException(reason: $couponRefusal, couponCode: $refusedCode, discount: $refusedDiscount);
                    }
                }

        foreach ($vendorWiseCartList as $vendorWiseGroupId => $vendorWiseCart) {
            $vendorWiseCart['stock_policy'] = $stockPolicy;
            $order_id = OrderManager::generateNewOrderID();
            $orderPlacedIds[] = $order_id;

            foreach ($vendorWiseCart['applied_tax_cart_list'] as $cartItem) {
                $shippingTaxes = collect($cartItem['applied_shipping_cost_tax_ids'])
                    ->flatMap(static fn ($taxGroup): array => collect($taxGroup['tax_ids'])
                        ->map(static fn ($taxId): array => ['group' => $taxGroup['name'], 'tax' => $taxConfig['taxVats']->firstWhere('id', $taxId)])
                        ->all())
                    ->filter(static fn (array $shippingTax): bool => (bool) $shippingTax['tax']);
                $shippingTaxRate = $shippingTaxes->sum(static fn (array $shippingTax) => $shippingTax['tax']['tax_rate']);

                foreach ($shippingTaxes as $shippingTax) {
                    self::getAddOrderTaxDetails(
                        systemTaxVat: $taxConfig['SystemTaxVat'],
                        taxRate: $shippingTax['tax'],
                        orderId: $order_id,
                        data: [
                            'tax_amount' => ($cartItem['applied_shipping_cost_tax'] > 0 && $shippingTaxRate > 0) ? ($cartItem['applied_shipping_cost_tax'] * $shippingTax['tax']['tax_rate']) / $shippingTaxRate : 0,
                            'before_tax_amount' => $vendorWiseCart['order_amount_with_tax'] - $vendorWiseCart['total_tax_amount'],
                            'after_tax_amount' => $vendorWiseCart['order_amount_with_tax'],
                            'quantity' => 0,
                            'seller_id' => $vendorWiseCart['seller_id'],
                            'seller_type' => $vendorWiseCart['seller_is'],
                        ],
                        taxOn: $shippingTax['group'],
                    );
                }
            }

            $ordersData = OrderManager::getOrderAddData(
                orderId: $order_id,
                orderGroupId: $orderGroupId,
                customerData: $getCustomerInfo,
                cartData: $vendorWiseCart,
                orderData: $data
            );
            DB::table('orders')->insertGetId($ordersData);

            if ($data['payment_method'] == 'offline_payment') {
                OfflinePayments::insert(['order_id' => $order_id, 'payment_info' => json_encode($data['offline_payment_info']), 'created_at' => Carbon::now()]);
            }

            self::add_order_status_history($order_id, $getCustomerInfo['customer_id'], $data['payment_status'] == 'paid' ? 'confirmed' : 'pending', 'customer');

            // Saves the lines, freezes the company that sold the order and records its money, in that order.
            OrderManager::addOrderDetailsData(
                orderId: $order_id,
                vendorCart: $vendorWiseCart
            );

            // The lines are saved, the order is stamped once from the country they belong to, and only
            // then do the money rows take that currency. The order cannot be stamped before its lines
            // exist, so a line that asked for the order's currency as it was inserted would read an
            // order that was still empty — which is exactly how this read came back NULL every time.
            $storefrontSnapshot = app(OrderStorefrontSnapshot::class);
            $storefrontSnapshot->record(orderId: $order_id);
            $storefrontSnapshot->stampMoneyRows(table: 'order_details', orderId: $order_id);

            // Which legal texts the customer confirmed they were shown, recorded against THIS order while its
            // transaction is still open (GCC-11, I2-05).
            //
            // Here, and not at the callers, because this is the one line every order of this platform is written at:
            // the web checkout, both API versions and the point-of-sale helper all arrive here. The recorder used to
            // be called from the three v1 endpoints the customer app uses, which left the four other paths -- the
            // whole website included -- writing no receipt at all.
            //
            // Inside the transaction, so a receipt cannot outlive its order: one written after generateOrder returned
            // would survive an order the transaction refused, claiming a customer accepted terms for a sale that
            // never happened. It also means the market is read from the order row the snapshot above has just
            // written rather than from the request, which is the rule every per-market read on this platform follows.
            //
            // The request is the caller's own where there IS one, and `requestObj` is NOT always a request: the payment
            // settlement path builds it as an ARRAY (`app/Utils/module-helper.php`) and merges that array into the global
            // request before it calls this, so the global one is where its `accepted_documents` actually is. An order
            // placed by a queue or a console command names no accepted document and writes nothing.
            //
            // `customer_id` holds the GUEST id for a guest order (getCustomerInfoForOrder), so is_guest decides which
            // of the two this is. Handing a guest id over as a customer id would store an identity the privacy
            // setting says to keep only as a hash.
            app(CheckoutLegalAcceptance::class)->record(
                request: ($data['requestObj'] ?? null) instanceof Request ? $data['requestObj'] : request(),
                orderIds: [$order_id],
                customerId: ($getCustomerInfo['is_guest'] ?? 0) ? null : (int) $getCustomerInfo['customer_id'],
                guestId: ($getCustomerInfo['is_guest'] ?? 0) ? (string) $getCustomerInfo['guest_id'] : null,
            );

            $order = Order::with('customer', 'seller.shop', 'details')->find($order_id);
            
            
            
            
            
            
            OrderManager::getAddOrderTransactionsOnGenerateOrder(order: $order, ordersData: $ordersData);

            // Analytics. Every order in this application is written here, by every caller — the web
            // checkout, both API versions and the two POS screens — with DB::table()->insertGetId(),
            // which fires no model event. This is therefore the only place the event can be raised
            // once and cover all of them.
            //
            // afterCommit, not inline: this runs inside a retried transaction, and an order that
            // rolls back must not leave a sale in the reports that never happened.
            DB::afterCommit(function () use ($order, $ordersData, $vendorWiseCart) {
                try {
                    app(Analytics::class)->orderPlaced(
                        orderId: (int) $ordersData['id'],
                        amount: (float) $ordersData['order_amount'],
                        vendorId: ($ordersData['seller_is'] ?? null) === 'seller' ? (int) $ordersData['seller_id'] : null,
                        items: $order?->details?->count(),
                        properties: array_filter([
                            'payment_method' => $ordersData['payment_method'] ?? null,
                            'payment_status' => $ordersData['payment_status'] ?? null,
                            'coupon_code' => $ordersData['coupon_code'] ?? null,
                            'is_guest' => (bool) ($ordersData['is_guest'] ?? false),
                            'shipping_cost' => (float) ($ordersData['shipping_cost'] ?? 0),
                        ], static fn ($value) => $value !== null && $value !== ''),
                    );

                    // A paid-on-placement order is a completed payment. Counting only gateway
                    // callbacks would leave every cash-on-delivery and wallet order out of payment
                    // analytics — which, in this shop, is most of them.
                    if (($ordersData['payment_status'] ?? null) === 'paid') {
                        app(Analytics::class)->paymentAttempted(
                            gateway: (string) ($ordersData['payment_method'] ?: 'unknown'),
                            outcome: 'succeeded',
                            amount: (float) $ordersData['order_amount'],
                            orderId: (int) $ordersData['id'],
                        );
                    }
                } catch (\Throwable) {
                    // The order is already committed by the time this runs. Nothing analytics can
                    // fail at is worth turning a completed sale into an error page.
                }
            });

            $orderPlacedNotificationEvents[] = OrderManager::getGenerateOrderNotificationInfo(
                vendorType: $vendorWiseCart['seller_is'],
                vendorId: $vendorWiseCart['seller_id'],
                order: $order,
                customer: $getCustomerInfo['customer'],
            );

            $orderPlacedMailEvents[] = OrderManager::getGenerateOrderMailInfo(
                vendorType: $vendorWiseCart['seller_is'],
                vendorId: $vendorWiseCart['seller_id'],
                vendorWiseCart: $vendorWiseCart,
                order: $order,
                customer: $getCustomerInfo['customer'],
            );
        }

                if ($walletPayment !== null) {
                    $walletDebit = CustomerManager::create_wallet_transaction($walletPayment['customerId'], $walletPayment['amount'], 'order_place', 'order payment', amountInUsd: $walletPayment['amountInUsd'], origin: LedgerOrigin::checkout(orderIds: $orderPlacedIds));
                    if ($walletDebit === false) {
                        throw new WalletPaymentRefused(customerId: $walletPayment['customerId'], amount: $walletPayment['amount']);
                    }
                }

                // Last, inside the same transaction: the key now names the orders it placed, so a repeat is answered with them. Written
                // after the wallet debit, because a refused debit throws and this submit then wrote no order to name. A submit that
                // placed NOTHING gives the key back rather than burning it -- an empty cart is not a submit that was already honoured.
                $orderPlacedIds === [] ? $checkoutSubmit?->release() : $checkoutSubmit?->record(orderIds: $orderPlacedIds);
            }, attempts: 3);

            break;
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            // Nothing was committed — the whole attempt rolled back, stock included. Retrying
            // re-reads the id, so the loser of the race simply takes the next number.
            if ($idCollisionAttempt >= 3) {
                throw $exception;
            }

            usleep(random_int(20000, 90000));
            continue;
        } catch (\App\Exceptions\InsufficientStockException $exception) {
            // The transaction has already rolled back: no order rows, no stock taken. Report the
            // failure as "no orders created" rather than throwing, because every caller treats the
            // return value as a list of ids and none of them expects an exception. Callers guard on
            // an empty result and tell the customer their cart is unchanged.
            Log::warning('Order refused: ' . $exception->getMessage(), [
                'product_id' => $exception->productId,
                'requested' => $exception->requested,
            ]);

            return [];
        } catch (CouponNoLongerAvailableException $exception) {
            // The coupon reached a limit of its own between the eligibility check and the write. The transaction has rolled back, so no
            // order row, no stock taken and no redemption recorded: the customer's cart is exactly as it was and they can place the same
            // order without the coupon.
            //
            // Reported as "no orders created", like the stock refusal above, because every caller reads the return value as a list of ids
            // and none of them expects an exception. What is different is that the REASON travels with it: a customer whose coupon is spent
            // is told so instead of being sent to look for an item that went out of stock (App\Services\Checkout\OrderRefused).
            Log::warning('Order refused: ' . $exception->getMessage(), ['reason' => $exception->reason]);
            OrderRefused::because($exception->reason);

            return [];
        }
        }




// Provider fulfillment (M5AZN / Torod Point). The order and its ownership snapshots are committed; this
// only asks the queue to route it. No provider call happens here — see FulfillmentHooks.
app(FulfillmentHooks::class)->ordersPlaced(orderIds: $orderPlacedIds);

/*
|--------------------------------------------------------------------------
| Delivery Syria automatic dispatch hook
|--------------------------------------------------------------------------
|
| After the order transaction has committed successfully, check every
| created order. If its selected shipping method is active and its
| shipping_methods.name is exactly "DeliverySyria", continue with the
| Delivery Syria integration.
|
| This runs for COD and online-paid orders alike because all of them
| pass through generateOrder().
|
*/

foreach ($orderPlacedIds as $deliverySyriaOrderId) {


    Log::warning('Delivery Syria DEBUG: hook reached', [
        'order_id' => $deliverySyriaOrderId,
    ]);


    try {
        $order = Order::find($deliverySyriaOrderId);

        if (!$order) {
            Log::warning('Delivery Syria: order not found after creation', [
                'order_id' => $deliverySyriaOrderId,
            ]);

            continue;
        }

        if (empty($order->shipping_method_id)) {
            continue;
        }

        $shippingMethod = ShippingMethod::where('id', $order->shipping_method_id)
            ->where('status', 1)
            ->first();



            Log::warning('Delivery Syria DEBUG: shipping method found', [
    'order_id' => $order->id,
    'shipping_method_id' => $order->shipping_method_id,
    'shipping_method_name' => $shippingMethod?->name,
    'shipping_method_status' => $shippingMethod?->status,
]);



        if (!$shippingMethod) {
            continue;
        }

        /*
         * مهم:
         * الربط حسب name وليس ID حسب ما اتفقنا.
         */
        if ((string) $shippingMethod->name !== 'DeliverySyria') {
            continue;
        }

        /*
        | H1-11: a market is served only by a shipping method that belongs to it.
        |
        | Delivery Syria is a Syrian courier, and a Gulf order reaching this hook would be handed to a company that does not deliver in
        | that country. A market lists only its own methods (H1-14), so such an order should not exist -- but an id stored before
        | storefronts, or one an API caller chose for itself, would arrive here with nothing to stop it, because the test above is the
        | method's NAME and says nothing about where the parcel is going.
        |
        | It refuses only a DISAGREEMENT: with storefronts off, before the market column exists, or for an order that records no market --
        | which is every order of the platform as it stands -- this passes and the hook runs exactly as it always has.
        */
        if (!app(StorefrontShippingMethods::class)->mayBeUsedBy(methodId: $order->shipping_method_id, market: $order->storefront_country)) {
            Log::warning('Delivery Syria: the order belongs to a market this shipping method does not', [
                'order_id' => $order->id,
                'shipping_method_id' => $order->shipping_method_id,
                'market' => $order->storefront_country,
            ]);

            continue;
        }

        $deliverySyriaConfig = app(DeliverySyriaConfigService::class);

        if (!$deliverySyriaConfig->isReadyForOutbound()) {
            Log::warning('Delivery Syria: selected but integration is not ready', [
                'order_id' => $order->id,
                'shipping_method_id' => $order->shipping_method_id,
            ]);

            continue;
        }

        /*
         * إذا وصلنا لهون:
         * 1. الأوردر اننشأ بنجاح
         * 2. وسيلة الشحن فعالة
         * 3. name = DeliverySyria
         * 4. التكامل فعال
         * 5. Secret موجود
         */
   $deliverySyriaClient = app(DeliverySyriaClient::class);
$deliveryCost = (float) $shippingMethod->cost;

Log::warning('Delivery Syria: shipping cost loaded', [
    'order_id' => $order->id,
    'shipping_cost' => $deliveryCost,
]);



$address = $order->shipping_address_data;

if (!$address) {
    Log::warning('Delivery Syria: shipping address missing', [
        'order_id' => $order->id,
    ]);
    continue;
}

/*
|--------------------------------------------------------------------------
| Delivery Syria weight
|--------------------------------------------------------------------------
|
| الوزن ينرسل لـ Delivery Syria فقط.
| لا يُستخدم لحساب تكلفة الشحن داخل Pharmacy Syria.
|
*/

$weight = $order->details->sum(function ($detail) {

    $productDetails = json_decode($detail->product_details, true);

    $productWeight = (float) ($productDetails['weight'] ?? 0);
    $quantity = (float) ($detail->qty ?? 1);

    return $productWeight * $quantity;
});

if ($weight <= 0) {
    $weight = 1;
}

/*
|--------------------------------------------------------------------------
| Delivery Syria governorate
|--------------------------------------------------------------------------
*/

$governorateName = trim((string) ($address->state ?? ''));

if ($governorateName === '') {
    $governorateName = trim((string) ($address->city ?? ''));
}

$invoiceNo = (string) $order->id;

/*
 * COD:
 * إذا الأوردر غير مدفوع، Delivery Syria تجمع قيمة الطلب.
 * إذا مدفوع أونلاين، لا تجمع أي مبلغ.
 */
$cashCollection = $order->payment_status === 'paid'
    ? 0
    : (float) $order->order_amount;



$payload = [
    'hub_id' => $deliverySyriaConfig->get('hub_id'),
    'delivery_type_id' => $deliverySyriaConfig->get('delivery_type_id', '1'),
    'packaging_id' => $deliverySyriaConfig->get('packaging_id', '1'),

    'weight' => (string) $weight,

    'pickup_phone' => $deliverySyriaConfig->get('pickup_phone'),
    'pickup_address' => $deliverySyriaConfig->get('pickup_address'),
    'pickup_lat' => $deliverySyriaConfig->get('pickup_lat'),
    'pickup_long' => $deliverySyriaConfig->get('pickup_long'),

    'invoice_no' => $invoiceNo,

    'cash_collection' => (string) $cashCollection,
    'cod_amount2' => (string) $cashCollection,

    'selling_price' => (string) (float) $order->order_amount,

    'customer_name' => (string) ($address->contact_person_name ?? ''),

    'customer_address' => trim(
        (string) ($address->address ?? '') . ' ' .
        (string) ($address->city ?? '')
    ),

    'lat' => (string) ($address->latitude ?? ''),
    'long' => (string) ($address->longitude ?? ''),

    'customer_phone' => (string) ($address->phone ?? ''),

    'note' => (string) ($order->order_note ?? ''),

    'Governorate' => $governorateName,

    'chargeDetails' => json_encode([
        'deliveryChargeAmount' => number_format($deliveryCost, 2, '.', ''),
        'totalDeliveryChargeAmount' => number_format($deliveryCost, 2, '.', ''),
        'currentPayable' => number_format($deliveryCost, 2, '.', ''),
        'VatAmount' => '0.00',
        'vatTex' => 0,
        'codChargeAmount' => '0.00',
        'packagingAmount' => '0.00',
        'liquidFragileAmount' => '0.00',
    ]),
];
Log::warning('Delivery Syria: sending parcel now', [
    'order_id' => $order->id,
    'invoice_no' => $invoiceNo,
    'weight' => $weight,
    'governorate' => $governorateName,
    'delivery_cost' => $deliveryCost,
    'cash_collection' => $cashCollection,
]);

$response = $deliverySyriaClient->createParcel($payload);

Log::warning('Delivery Syria: parcel response', [
    'order_id' => $order->id,
    'success' => $response['success'] ?? false,
    'message' => $response['message'] ?? null,
    'parcel_id' => $response['parcel_id'] ?? null,
    'tracking_number' => $response['tracking_number'] ?? null,
    'wallet_status' => $response['wallet_status'] ?? null,
]);

if (empty($response['success'])) {
    Log::error('Delivery Syria: parcel creation failed', [
        'order_id' => $order->id,
        'message' => $response['message'] ?? 'Unknown error',
    ]);

    continue;
}

$trackingNumber = $response['tracking_number'] ?? null;
$walletStatus = $response['wallet_status'] ?? null;

DeliverySyriaParcel::updateOrCreate(
    [
        'invoice_no' => $invoiceNo,
    ],
    [
        'order_id' => $order->id,
        'parcel_id' => $response['parcel_id'] ?? null,
        'tracking_number' => $trackingNumber,
        'wallet_status' => $walletStatus,
        'delivery_charge' => $deliveryCost,
        'company_type' => $response['calculation']['company_type'] ?? null,
        'governorate' => $governorateName,
        'weight' => $weight,
    ]
);

Order::where('id', $order->id)->update([
    'delivery_type' => 'third_party_delivery',
    'delivery_service_name' => 'Delivery Syria',
    'third_party_delivery_tracking_id' => $trackingNumber,
    'delivery_man_id' => null,
    'updated_at' => now(),
]);

Log::warning('Delivery Syria: parcel saved successfully', [
    'order_id' => $order->id,
    'parcel_id' => $response['parcel_id'] ?? null,
    'tracking_number' => $trackingNumber,
]);







    } catch (\Throwable $e) {
        /*
         * فشل Delivery Syria لا يجب أن يلغي Order تم إنشاؤه بنجاح.
         */
        Log::error('Delivery Syria: automatic dispatch hook failed', [
            'order_id' => $deliverySyriaOrderId,
            'message' => $e->getMessage(),
        ]);
    }
}


        $user = Helpers::getCustomerInformation(($data['requestObj'] ?? request()));

        $notificationSent = false;
        foreach ($orderPlacedIds as $orderPlacedId) {
            if (!$notificationSent && $user != 'offline') {
                $getOrder = Order::where('id', $orderPlacedId)->first();
                $referralUser = ReferralCustomer::where('user_id', $user['id'])->first();
                if ($referralUser && $referralUser->is_used != 1 && $referralUser->ordered_notify != 1) {
                    $orderPlacedNotificationEvents[] = [[
                        'notification' => true,
                        'notificationData' => (object)[
                            'key' => 'your_referred_customer_has_been_place_order',
                            'type' => 'promoter',
                            'order' => $getOrder,
                        ],
                    ]];
                }
                $notificationSent = true;
            }
        }

        if ($user != 'offline') {
            ReferralCustomer::where('user_id', $user['id'])->update(['is_used' => 1]);
        }


        foreach ($orderPlacedNotificationEvents as $orderPlacedEventGroup) {
            foreach ($orderPlacedEventGroup as $orderPlacedEvent) {
                if (!empty($orderPlacedEvent)) {
                    event(new OrderPlacedEvent(notification: $orderPlacedEvent['notificationData']));
                }
            }
        }

        foreach ($orderPlacedMailEvents as $orderPlacedMailEventGroup) {
            foreach ($orderPlacedMailEventGroup as $orderPlacedMailEvent) {
                try {
                    event(new OrderPlacedEvent(email: $orderPlacedMailEvent['email'], data: $orderPlacedMailEvent['data']));
                } catch (Exception $exception) {
                }
            }
        }

        if (!$answeredFromAnEarlierSubmit) {
            CartManager::cartCleanByCartGroupIds(cartGroupIDs: collect($vendorWiseCartList)?->pluck('cart_group_id')->toArray() ?? []);
        }

        session()->forget('coupon_code');
        session()->forget('coupon_type');
        session()->forget('coupon_bearer');
        session()->forget('coupon_discount');
        session()->forget('coupon_seller_id');

        return $orderPlacedIds;
    }

    public static function getLoyaltyPointKeys(): array
    {
        return [
            'loyalty_point_status',
            'loyalty_point_exchange_rate',
            'loyalty_point_item_purchase_point',
            'loyalty_point_minimum_point',
            'loyalty_point_each_order_status',
            'loyalty_point_for_each_order',
        ];
    }

    public static function getOrderRewardDetails(string $type, mixed $data, int $orderId, int $orderDetailsId): array
    {
        if ($type === 'coupon' && $data instanceof \App\Models\Coupon && $data['status'] == 1) {
            return [
                'order_id' => $orderId,
                'order_details_id' => $orderDetailsId,
                'reward_type' => 'coupon',
                'reward_amount' => $data['individual_coupon_discount'] ?? 0,
                'reward_details' => $data,
                'reward_delivered' => 1
            ];
        }
        if ($type === 'loyalty_point' && is_array($data) && isset($data['individual_loyalty_point'])) {
            return [
                'order_id' => $orderId,
                'order_details_id' => $orderDetailsId,
                'reward_type' => 'loyalty_point',
                'reward_amount' => $data['individual_loyalty_point'] ?? 0,
                'reward_details' => $data,
                'reward_delivered' => 0
            ];
        }
        return [];
    }

    public static function getAddOrderTransactionsOnGenerateOrder($order, $ordersData): void
    {
        if ($ordersData['payment_method'] != 'cash_on_delivery' && $ordersData['payment_method'] != 'offline_payment') {
            $orderSummary = OrderManager::getOrderTotalAndSubTotalAmountSummary($order);
            $orderAmount = $orderSummary['subtotal'] + $orderSummary['total_tax'] - $orderSummary['total_discount_on_product'] - $order['discount'];

            $shop = Shop::when($order['seller_is'] == 'admin', function ($query) {
                return $query->where(['author_type' => 'admin']);
            })->when($order['seller_is'] == 'seller', function ($query) use ($order) {
                return $query->where(['author_type' => 'vendor', 'seller_id' => $order['seller_id']]);
            })->first();

            DB::table('order_transactions')->insert([
                'transaction_id' => OrderManager::generateUniqueOrderID(),
                'customer_id' => $order['customer_id'],
                'seller_id' => $order['seller_id'],
                'shop_id' => $shop['id'],
                'seller_is' => $order['seller_is'],
                'order_id' => $order['id'],
                'order_amount' => $orderAmount,
                'seller_amount' => $orderAmount - $ordersData['admin_commission'],
                'admin_commission' => $ordersData['admin_commission'],
                'received_by' => 'admin',
                'status' => 'hold',
                'delivery_charge' => $order['shipping_cost'] - $order['extra_discount'],
                'tax' => $orderSummary['total_tax'],
                'delivered_by' => 'admin',
                'payment_method' => $ordersData['payment_method'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(OrderStorefrontSnapshot::class)->stampMoneyRows(table: 'order_transactions', orderId: $order['id']);

            OrderManager::getCheckOrCreateAdminWallet();
            AdminWallet::where(['admin_id' => 1])->increment('pending_amount', $order['order_amount']);
        }
    }

    public static function getGenerateOrderNotificationInfo(string $vendorType, int $vendorId, object|array $order, mixed $customer): array
    {
        $orderPlacedNotificationEvents = [];
        if ($vendorType == 'seller') {
            $seller = Seller::find($vendorId);
            if ($seller) {
                $orderPlacedNotificationEvents[] = [
                    'notification' => true,
                    'notificationData' => (object)[
                        'key' => 'new_order_message',
                        'type' => 'seller',
                        'order' => $order,
                    ],
                ];
            }
        }
        if ($customer != 'offline') {
            if ($order['payment_method'] != 'cash_on_delivery' && $order['payment_method'] != 'offline_payment') {
                $orderPlacedNotificationEvents[] = [
                    'notification' => true,
                    'notificationData' => (object)[
                        'key' => 'confirmed',
                        'type' => 'customer',
                        'order' => $order,
                    ],
                ];
            } else {
                $orderPlacedNotificationEvents[] = [
                    'notification' => true,
                    'notificationData' => (object)[
                        'key' => 'pending',
                        'type' => 'customer',
                        'order' => $order,
                    ],
                ];
            }
        }
        return $orderPlacedNotificationEvents;
    }

    public static function getGenerateOrderMailInfo(string $vendorType, int $vendorId, object|array $vendorWiseCart, object|array $order, mixed $customer): array
    {
        $orderPlacedMailEvents = [];
        $emailServicesSmtp = getWebConfig(name: 'mail_config');
        if ($emailServicesSmtp['status'] == 0) {
            $emailServicesSmtp = getWebConfig(name: 'mail_config_sendgrid');
        }
        if ($emailServicesSmtp['status'] == 1) {
            if ($customer == 'offline') {
                $offlineUser = ShippingAddress::where('id', $vendorWiseCart['shipping_address_id'])->first();
                if (!$offlineUser) {
                    $offlineUser = ShippingAddress::find($vendorWiseCart['billing_address_id']);
                }
                $email = $offlineUser['email'];
                $userName = $offlineUser['contact_person_name'];
            } else {
                $email = $customer['email'];
                $userName = $customer['f_name'];
            }

            $vendor = $vendorType == 'admin' ? Admin::find($vendorId) : Seller::find($vendorId);

            $orderPlacedMailEvents[] = [
                'email' => $email,
                'data' => [
                    'subject' => translate('order_placed'),
                    'title' => translate('order_placed'),
                    'userName' => $userName,
                    'userType' => 'customer',
                    'templateName' => 'order-place',
                    'order' => $order,
                    'orderId' => $order['id'],
                    'shopName' => $vendorType == 'admin' ? getInHouseShopConfig(key: 'name') : $vendor?->shop?->name ?? getWebConfig('company_name'),
                    'vendorName' => $vendorType == 'admin' ? getInHouseShopConfig(key: 'name') : $vendor?->shop?->name ?? getWebConfig('company_name'),
                    'shopId' => $vendor?->shop?->id ?? 0,
                    'attachmentPath' => self::storeInvoice($order['id']),
                ]
            ];

            $orderPlacedMailEvents[] = [
                'email' => $vendor['email'],
                'data' => [
                    'subject' => translate('new_order_received'),
                    'title' => translate('new_order_received'),
                    'userType' => $vendorType == 'admin' ? 'admin' : 'vendor',
                    'templateName' => 'order-received',
                    'order' => $order,
                    'orderId' => $order['id'],
                    'vendorName' => $vendor?->f_name,
                    'adminName' => $vendor?->name,
                ]
            ];
        }
        return $orderPlacedMailEvents;
    }


    public static function getEditOrderNotificationInfo(string $vendorType, int $vendorId, object|array $order = [], object|array $data = [], mixed $customer = null): array
    {
        $orderPlacedNotificationEvents = [];
        if ($vendorType == 'seller' && $data['edit_by'] == 'admin') {
            $seller = Seller::find($vendorId);
            if ($seller) {
                $orderPlacedNotificationEvents[] = [
                    'notification' => true,
                    'notificationData' => (object)[
                        'key' => 'order_edit_message',
                        'type' => 'seller',
                        'order' => $order,
                    ],
                ];
            }
        }
        if ($customer != 'offline') {
            $orderPlacedNotificationEvents[] = [
                'notification' => true,
                'notificationData' => (object)[
                    'key' => 'order_edit_message',
                    'type' => 'customer',
                    'order' => $order,
                ],
            ];
        }
        return $orderPlacedNotificationEvents;
    }


    public static function createWalletTransaction($user_id, float $amount, $transaction_type, $reference, $payment_data = [], ?LedgerOrigin $origin = null): bool|WalletTransaction
    {
        if (BusinessSetting::where('type', 'wallet_status')->first()->value != 1) return false;

        return CustomerBalanceLock::apply($user_id, function (User $user) use ($amount, $transaction_type, $reference, $payment_data, $origin) {
            $currentBalance = $user->wallet_balance;

            $walletTransaction = new WalletTransaction();
            $walletTransaction->user_id = $user->id;
            $walletTransaction->transaction_id = \Str::uuid();
            $walletTransaction->reference = $reference;
            $walletTransaction->transaction_type = $transaction_type;
            $walletTransaction->payment_method = isset($payment_data['payment_method']) ? $payment_data['payment_method'] : null;

            $debit = 0.0;
            $credit = 0.0;
            $addFundToWalletBonus = 0;

            if (in_array($transaction_type, ['add_fund_by_admin', 'add_fund', 'order_refund', 'loyalty_point'])) {
                $credit = $amount;
                if ($transaction_type == 'add_fund') {
                    $walletTransaction->admin_bonus = Helpers::add_fund_to_wallet_bonus(Convert::usd($amount ?? 0));
                    $addFundToWalletBonus = Helpers::add_fund_to_wallet_bonus(Convert::usd($amount ?? 0));
                } else if ($transaction_type == 'loyalty_point') {
                    $credit = (($amount / BusinessSetting::where('type', 'loyalty_point_exchange_rate')->first()->value) * Convert::default(1));
                }
            } else if ($transaction_type == 'order_place') {
                $debit = $amount;
            }

            // One unit for the credit, whichever writer is asked (E-16). Off as it ships: the amount is handed straight back.
            $creditAmount = WalletMinorUnit::credited(currencyConverter($credit));
            $debitAmount = currencyConverter($debit);
            if (WalletOverdraft::refuses($currentBalance, $debitAmount)) {
                return false;
            }
            // The row carries the balance the account ENDS with, bonus included (E-16). All four copies of this write set the
            // account to `previous + bonus + credit - debit` and wrote `previous + credit - debit` on the row, so an `add_fund`
            // row that earned a bonus understated the account by exactly the bonus it was printing in the next column -- on the
            // admin wallet report, which prints both on one line, and in the add-fund email, which prints the row's balance as
            // the customer's new balance. One figure, used twice, is why the two cannot disagree again
            // (App\Services\Customer\CustomerWalletWriter already writes it this way). Rows written before this are NOT
            // rewritten and keep the understated figure; they stay reconcilable, because `admin_bonus` is on the row in both
            // eras.
            $balanceAfter = $currentBalance + $addFundToWalletBonus + $creditAmount - $debitAmount;
            $walletTransaction->credit = $creditAmount;
            $walletTransaction->debit = $debitAmount;
            $walletTransaction->balance = $balanceAfter;
            $walletTransaction->created_at = now();
            $walletTransaction->updated_at = now();
            $walletTransaction->fill(app(LedgerAttribution::class)->of(table: $walletTransaction->getTable(), origin: $origin));
            $user->wallet_balance = $balanceAfter;

            $user->save();
            $walletTransaction->save();

            if (in_array($transaction_type, ['loyalty_point', 'order_place', 'add_fund_by_admin'])) return $walletTransaction;
            return true;
        });
    }
    public static function generateOrderAgain($request): array
    {
        $orderProducts = OrderDetail::where('order_id', $request->order_id)->get();
        $orderProductCount = $orderProducts->count();
        $addToCartCount = 0;
        $failedAddToCartCount = 0;
        $errorMessages = [];


        foreach ($orderProducts as $key => $orderProduct) {
            $productDetails = json_decode($orderProduct->product_details, true);
            $product = Product::active()->where(['id' => $orderProduct->product_id])->with(['digitalVariation'])->first();
            if (!$product) {
                $errorMessages[] = ($productDetails['name'] ?? '') . translate(' currently_not_available');
            }
            if ($product) {
                $productValid = true;
                if (($product['product_type'] == 'physical') && (($product['current_stock'] < $orderProduct['qty']) || ($product['minimum_order_qty'] > $product['current_stock']))) {
                    $productValid = false;
                    $errorMessages[] = ($product['name'] ?? '') . translate(' cannot be ordered because the available stock is insufficient or does not meet the minimum order quantity requirement.');
                }
                if ($productValid) {
                    $color = null;
                    $choices = [];
                    if ($orderProduct->variation) {
                        $variation = is_array($orderProduct?->variation) ? $orderProduct?->variation : json_decode($orderProduct?->variation ?? '', true);

                        if (isset($variation['color']) && $variation['color']) {
                            $color = Color::where('name', $variation['color'])->first()->code;
                            $i = 1;
                            foreach ($variation as $variationKey => $var) {
                                if ($variationKey != 'color') {
                                    $choices['choice_' . $i] = $var;
                                    $i++;
                                }
                            }
                        } else {
                            $i = 1;
                            if ($variation) {
                                foreach ($variation as $index => $var) {
                                    if ($var) {
                                        $choices['choice_' . $i] = $var;
                                    }
                                    $i++;
                                }
                            }
                        }
                    }

                    $user = Helpers::getCustomerInformation($request);
                    // Generate Group ID Start
                    $cartCheck = Cart::where([
                        'customer_id' => $user['id'],
                        'seller_id' => ($product->added_by == 'admin') ? 1 : $product->user_id,
                        'seller_is' => $product->added_by
                    ])->first();

                    if (isset($cartCheck)) {
                        $cartGroupId = $cartCheck['cart_group_id'];
                    } else {
                        $cartGroupId = $user['id'] . '-' . Str::random(5) . '-' . time();
                    }
                    // Generate Group ID End

                    $price = 0;
                    if (json_decode($product->variation)) {
                        $count = count(json_decode($product->variation));
                        for ($i = 0; $i < $count; $i++) {
                            if (json_decode($product->variation)[$i]->type == $orderProduct->variant) {
                                $price = json_decode($product->variation)[$i]->price;
                                if (json_decode($product->variation)[$i]->qty < $orderProduct->qty) {
                                    $productValid = false;
                                    $errorMessages[] = $product['name'] . translate(' variation does not have enough stock to fulfill the requested quantity.');
                                }
                            }
                        }
                    } else {
                        $price = $product->unit_price;
                    }

                    if ($product->product_type == 'digital') {
                        if ($product->digital_product_type == "ready_after_sell") {
                            $price = $product->unit_price;
                        } elseif ($product->digital_product_type == "ready_product" && !empty($product->digital_file_ready)) {
                            $price = $product->unit_price;
                        } elseif ($product->digital_product_type == "ready_product" && empty($product->digital_file_ready) && $product->digitalVariation) {
                            $productValid = false;
                            $errorMessages[] = translate('This digital ready product is missing its associated file. Please upload the ready file before proceeding.');
                            foreach ($product->digitalVariation as $digitalVariation) {
                                if ($digitalVariation['variant_key'] == $orderProduct['variant']) {
                                    $price = $digitalVariation['price'];
                                    $productValid = true;
                                }
                            }
                        }
                    }

                    $tax = Helpers::tax_calculation(product: $product, price: $price, tax: $product['tax'], tax_type: 'percent');
                    if ($productValid && $price != 0) {
                        $cartExist = Cart::where(['customer_id' => $user['id'], 'variations' => $orderProduct->variation, 'product_id' => $orderProduct->product_id])->first();
                        $orderProductQuantity = $orderProduct->qty < $product['minimum_order_qty'] ? $product['minimum_order_qty'] : $orderProduct->qty;

                        if (!$cartExist) {
                            $cart = new Cart();
                            $cart['cart_group_id'] = $cartGroupId;
                            $cart['color'] = $color;
                            $cart['product_id'] = $orderProduct->product_id;
                            $cart['product_type'] = $product->product_type;
                            $cart['choices'] = json_encode($choices);
                            $cart['variations'] = !is_null($color) || !empty($choices) ? (is_array($orderProduct?->variation) ? json_encode($orderProduct?->variation) : $orderProduct?->variation) : json_encode([]);
                            $cart['variant'] = $orderProduct->variant;
                            $cart['customer_id'] = $user->id ?? 0;
                            $cart['quantity'] = $orderProductQuantity;
                            $cart['price'] = $price;
                            $cart['tax'] = $tax;
                            $cart['tax_model'] = $product->tax_model;
                            $cart['slug'] = $product->slug;
                            $cart['name'] = $product->name;
                            $cart['is_checked'] = 1;
                            $cart['discount'] = Helpers::getProductDiscount($product, $price);
                            $cart['thumbnail'] = $product->thumbnail;
                            $cart['seller_id'] = ($product->added_by == 'admin') ? 1 : $product->user_id;
                            $cart['seller_is'] = $product->added_by;
                            $cart['shipping_cost'] = $product->product_type == 'physical' ? CartManager::get_shipping_cost_for_product_category_wise($product, $orderProductQuantity) : 0;
                            if ($product->added_by == 'seller') {
                                $cart['shop_info'] = Shop::where(['seller_id' => $product->user_id])->first()->name;
                            } else {
                                $cart['shop_info'] = getInHouseShopConfig(key: 'name');
                            }

                            $shippingMethod = getWebConfig(name: 'shipping_method');

                            if ($shippingMethod == 'inhouse_shipping') {
                                $adminShipping = ShippingType::where('seller_id', 0)->first();
                                $shippingType = isset($adminShipping) ? $adminShipping->shipping_type : 'order_wise';
                            } else {
                                if ($product->added_by == 'admin') {
                                    $adminShipping = ShippingType::where('seller_id', 0)->first();
                                    $shippingType = isset($adminShipping) ? $adminShipping->shipping_type : 'order_wise';
                                } else {
                                    $seller_shipping = ShippingType::where('seller_id', $product->user_id)->first();
                                    $shippingType = isset($seller_shipping) ? $seller_shipping->shipping_type : 'order_wise';
                                }
                            }

                            $cart['shipping_type'] = $shippingType;
                            $cart->save();
                        } else {
                            $cart['is_checked'] = 1;
                            $cartExist->quantity = $orderProductQuantity;
                            $cartExist->save();
                        }
                        $addToCartCount++;
                    } else {
                        $failedAddToCartCount++;
                    }
                } else {
                    $failedAddToCartCount++;
                }
            }
        }

        return [
            'order_product_count' => $orderProductCount,
            'add_to_cart_count' => $addToCartCount,
            'failedAddToCartCount' => $failedAddToCartCount,
            'errorMessages' => $errorMessages
        ];
    }

    public static function verifyCartListMinimumOrderAmount($request, $cart_group_id = null): array
    {
        $user = Helpers::getCustomerInformation($request);
        $status = 1;
        $amount = 0;
        $shippingCost = 0;
        $messages = [];
        $minimumOrderAmount = 0;
        $minimumOrderAmountStatus = getWebConfig(name: 'minimum_order_amount_status');
        $minimumOrderAmountBySeller = getWebConfig(name: 'minimum_order_amount_by_seller');
        $inhouseMinimumOrderAmount = getWebConfig(name: 'minimum_order_amount');
        $decimal_point_settings = getWebConfig(name: 'decimal_point_settings');

        if ($minimumOrderAmountStatus) {
            $query = Cart::whereHas('product', function ($query) {
                    return $query->active();
                })->with(['seller', 'allProducts' => function ($query) {
                    return $query->active()->with(['reviews' => function ($query) {
                        return $query->active();
                    }]);
                }])
                ->where([
                    'customer_id' => ($user == 'offline' ? (session('guest_id') ?? $request->guest_id) : $user['id']),
                    'is_guest' => ($user == 'offline' ? 1 : '0'),
                    'is_checked' => 1,
                ]);

            if ($cart_group_id) {
                $cartItem = $query->where('cart_group_id', $cart_group_id)->first();

                if (isset($cartItem) && $cartItem->allProducts) {
                    if ($cartItem->allProducts->added_by == 'admin') {
                        $minimumOrderAmount = $inhouseMinimumOrderAmount;
                    } else {
                        $minimumOrderAmount = $minimumOrderAmountBySeller ? ($cartItem?->seller?->minimum_order_amount ?? 0) : 0;
                    }

                    $shippingCost = CartManager::get_shipping_cost(groupId: $cart_group_id, type: 'checked');
                    $newAmount = CartManager::cart_grand_total(cartGroupId: $cart_group_id, type: 'checked') - $shippingCost;
                    $status = $minimumOrderAmount > $newAmount ? 0 : 1;

                    if ($minimumOrderAmount > $newAmount) {
                        $status = 0;
                        $shopIdentity = $cartItem->allProducts->added_by == 'admin' ? getInHouseShopConfig(key: 'name') : $cartItem->seller->shop->name;
                        if (isset($request['payment_request_from']) && $request['payment_request_from'] == 'app') {
                            $messages[] = translate('Please_complete_minimum_Order_Amount') . ' ' . translate('for') . ' ' . $shopIdentity;
                        } else {
                            $messages[] = translate('minimum_Order_Amount') . ' ' . webCurrencyConverter(amount: $minimumOrderAmount) . ' ' . translate('for') . ' ' . $shopIdentity;
                        }
                    }
                    $amount = $amount + $newAmount;
                }
            } else {
                $cartGroups = $query->get()->groupBy('cart_group_id');
                foreach ($cartGroups as $group_key => $cart_group) {
                    $cartGroupFirstItem = $cart_group->first();
                    $seller = $cartGroupFirstItem->seller_is;
                    if ($seller == 'admin') {
                        $minimumOrderAmount = $inhouseMinimumOrderAmount;
                    } else {
                        $minimumOrderAmount = $minimumOrderAmountBySeller ? ($cartGroupFirstItem?->seller?->minimum_order_amount ?? 0) : 0;
                    }

                    $shippingCost = CartManager::get_shipping_cost(groupId: $group_key, type: 'checked');
                    $newAmount = CartManager::cart_grand_total(cartGroupId: $group_key, type: 'checked') - $shippingCost;

                    if ($minimumOrderAmount > $newAmount) {
                        $status = 0;
                        $shopIdentity = $seller == 'admin' ? getInHouseShopConfig(key: 'name') : $cartGroupFirstItem->seller->shop->name;
                        if (isset($request['payment_request_from']) && $request['payment_request_from'] == 'app') {
                            $messages[] = translate('Please_complete_minimum_Order_Amount') . ' ' . translate('for') . ' ' . $shopIdentity;
                        } else {
                            $messages[] = translate('minimum_Order_Amount') . ' ' . webCurrencyConverter(amount: $minimumOrderAmount) . ' ' . translate('for') . ' ' . $shopIdentity;
                        }
                    }
                    $amount = $amount + $newAmount;
                }
            }
        }

        return [
            'minimum_order_amount' => $minimumOrderAmount ?? 0,
            'amount' => $amount ? floatval($amount) : 0,
            'status' => $status,
            'messages' => $messages,
            'shippingCost' => $shippingCost ?? 0,
            'cart_group_id' => $cart_group_id ?? null
        ];
    }

    public static function checkSingleProductMinimumOrderAmountVerify($request, $product, $totalAmount): array
    {
        $status = 1;
        $message = '';
        $minimumOrderAmount = 0;
        $minimumOrderAmountStatus = getWebConfig(name: 'minimum_order_amount_status');
        $minimumOrderAmountBySeller = getWebConfig(name: 'minimum_order_amount_by_seller');
        $inhouseMinimumOrderAmount = getWebConfig(name: 'minimum_order_amount');

        if ($minimumOrderAmountStatus) {
            if ($product->added_by == 'admin') {
                $minimumOrderAmount = $inhouseMinimumOrderAmount;
            } else {
                $minimumOrderAmount = $minimumOrderAmountBySeller ? ($product->seller->minimum_order_amount ?? 0) : 0;
            }
            $status = $minimumOrderAmount > $totalAmount ? 0 : 1;

            if ($minimumOrderAmount > $totalAmount) {
                $shopIdentity = $product->added_by == 'admin' ? getInHouseShopConfig(key: 'name') : $product->seller->shop->name;
                $message = translate('minimum_Order_Amount') . ' ' . webCurrencyConverter(amount: $minimumOrderAmount) . ' ' . translate('for') . ' ' . $shopIdentity;
            }
        }

        return [
            'minimum_order_amount' => $minimumOrderAmount,
            'amount' => $totalAmount,
            'added_by' => $product->added_by,
            'status' => $status,
            'message' => $message,
        ];
    }

    public static function getFreeDeliveryOrderAmountArray($cart_group_id = null): array
    {
        $freeDeliveryData = [
            'amount' => 0, // free delivery amount
            'percentage' => 0, // completed percentage
            'amount_need' => 0, // need amount for free delivery
            'shipping_cost_saved' => 0,
            'cart_id' => $cart_group_id
        ];

        $freeDeliveryData['status'] = (int)(getWebConfig(name: 'free_delivery_status') ?? 0);
        $freeDeliveryData['responsibility'] = (string)getWebConfig(name: 'free_delivery_responsibility');
        $freeDeliveryOverAmount = (float)getWebConfig(name: 'free_delivery_over_amount');
        $freeDeliveryOverAmountSeller = (float)getWebConfig(name: 'free_delivery_over_amount_seller');

        if ($freeDeliveryData['status'] && $cart_group_id) {
            $getCartList = Cart::whereHas('product', function ($query) {
                return $query->active();
            })->where(['product_type' => 'physical', 'cart_group_id' => $cart_group_id, 'is_checked' => 1])->first();

            if ($getCartList) {
                if ($getCartList->seller_is == 'admin') {
                    $freeDeliveryData['amount'] = $freeDeliveryOverAmount;
                    $freeDeliveryData['status'] = $freeDeliveryOverAmount > 0 ? 1 : 0;
                } else {
                    $seller = Seller::where('id', $getCartList->seller_id)->first();
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

                $amount = CartManager::getCartGrandTotalWithoutShippingCharge(cartGroupId: $getCartList->cart_group_id, type: 'checked');
                $freeDeliveryData['amount_need'] = $freeDeliveryData['amount'] - $amount;
                $freeDeliveryData['percentage'] = ($freeDeliveryData['amount'] > 0) && $amount > 0 && ($freeDeliveryData['amount'] >= $amount) ? number_format(($amount / $freeDeliveryData['amount']) * 100) : 100;
                if ($freeDeliveryData['status'] == 1 && $freeDeliveryData['percentage'] == 100) {
                    $freeDeliveryData['shipping_cost_saved'] = CartManager::get_shipping_cost(groupId: $getCartList->cart_group_id, type: 'checked');
                }
            } else {
                $freeDeliveryData['status'] = 0;
            }
        }

        return $freeDeliveryData;
    }

    public static function getOrderTotalAndSubTotalAmountSummary($order): array
    {
        $subTotal = 0;
        $totalTax = $order['total_tax_amount'];
        $totalDiscountOnProduct = 0;
        foreach ($order->details as $detail) {
            $subTotal += $detail->price * $detail->qty;
            $totalDiscountOnProduct += $detail->discount;
        }
        $totalShippingCost = $order['shipping_cost'];
        return [
            'subtotal' => $subTotal,
            'total_tax' => $totalTax,
            'total_discount_on_product' => $totalDiscountOnProduct,
            'total_shipping_cost' => $totalShippingCost,
        ];
    }

    public static function getRefundDetailsForSingleOrderDetails($orderDetailsId): array
    {
        $orderDetails = OrderDetail::where(['id' => $orderDetailsId])->first();
        $order = Order::where(['id' => $orderDetails['order_id']])->with('details')->first();

        $totalProductPrice = 0;
        foreach ($order->details as $key => $orderDetail) {
            $totalProductPrice += ($orderDetail->qty * $orderDetail->price) + $orderDetail->tax - $orderDetail->discount;
        }
        $subtotal = ($orderDetails->price * $orderDetails->qty) - $orderDetails->discount + $orderDetails->tax;

        $couponDiscount = $totalProductPrice > 0 ? (($order->discount_amount * $subtotal) / $totalProductPrice) : 0;
        $referAndEarnDiscount = OrderManager::getReferDiscountAmountForSingleOrderDetails(orderDetailsId: $orderDetailsId);

        return [
            'product_price' => $orderDetails->price,
            'product_discount' => $orderDetails->discount,
            'tax' => $orderDetails->tax,
            'sub_total' => $subtotal,
            'coupon_discount' => $couponDiscount,
            'referral_discount' => $referAndEarnDiscount,
            'total_refundable_amount' => $subtotal - $couponDiscount - $referAndEarnDiscount,
        ];
    }

    public static function getReferDiscountAmountForSingleOrderDetails($orderDetailsId): int|float
    {
        $orderDetails = OrderDetail::where(['id' => $orderDetailsId])->first();
        $order = Order::where(['id' => $orderDetails['order_id']])->with('details')->first();

        $totalProductPriceWithOutDiscount = 0;
        foreach ($order->details as $key => $details) {
            $totalProductPriceWithOutDiscount += ($details['price'] - $details->discount) * $details['qty'];
        }
        $rate = 0;
        if ($totalProductPriceWithOutDiscount > 0) {
            $rate = (($orderDetails['price'] - $orderDetails->discount) * $orderDetails['qty'] * 100) / $totalProductPriceWithOutDiscount;
        }
        $orderReferralDiscount = ($order?->refer_and_earn_discount ?? 0);
        return $orderReferralDiscount > 0 ? ($rate * $orderReferralDiscount) / 100 : 0;
    }

    public static function getRefundAmountFromOrderDetails($orderId, $orderDetailsId): int|float
    {
        $order = Order::where(['id' => $orderId])->with('details')->first();
        $orderDetails = OrderDetail::where(['id' => $orderDetailsId])->first();
        $totalProductPrice = 0;
        foreach ($order->details as $key => $details) {
            $totalProductPrice += ($details->qty * $details->price) + $details->tax - $details->discount;
        }
        $subtotal = ($orderDetails->price * $orderDetails->qty) - $orderDetails->discount + $orderDetails->tax;
        $couponDiscount = ($orderDetails->discount_amount * $subtotal) / $totalProductPrice;
        return $subtotal - $couponDiscount - OrderManager::getReferDiscountAmountForSingleOrderDetails($orderDetailsId);
    }

    public static function storeInvoice($id): string
    {
        $order = Order::with('seller')->with('shipping')->where('id', $id)->first();
        $invoiceSettings = getWebConfig('invoice_settings');

        return app(StorefrontCurrency::class)->withinOrderCurrency(order: $order, callback: function () use ($order, $invoiceSettings): string {
            $mpdf_view = \View::make(VIEW_FILE_NAMES['order_invoice'], compact('order', 'invoiceSettings'));

            return self::storePdf(view: $mpdf_view, filePrefix: 'order_invoice_', filePostfix: $order['id'], pdfType: 'invoice', requestFrom: 'web');
        });
    }


    /**
     * The one cart line that stands for a cart group in the checkout validation: the first checked line by id, as the statement returned it when
     * it was `select *` with a bare GROUP BY, which a server enforcing ONLY_FULL_GROUP_BY refuses. Which lines of the group the stock check should
     * cover is a separate question; this keeps what it did.
     */
    public static function firstCheckedCartLineOfGroup(string|int $groupId): EloquentCollection
    {
        return Cart::whereHas('product', function ($query) {
            return $query->active();
        })->with(['product' => function ($query) {
            return $query->active();
        }])->where(['cart_group_id' => $groupId, 'is_checked' => 1])->orderBy('id')->limit(1)->get();
    }

    public static function checkValidationForCheckoutPages(Request $request): array
    {
        $response['status'] = 1;
        $response['physical_product_view'] = false;
        $message = [];

        $verifyStatus = OrderManager::verifyCartListMinimumOrderAmount($request);
        if ($verifyStatus['status'] == 0) {
            $response['status'] = 0;
            $response['errorType'] = 'minimum-order-amount';
            $response['redirect'] = route('shop-cart');
            foreach ($verifyStatus['messages'] as $verifyStatusMessages) {
                $message[] = $verifyStatusMessages;
            }
        }

        $cartItemGroupIDsAll = CartManager::get_cart_group_ids($request);
        $cartItemGroupIDs = CartManager::get_cart_group_ids(request: $request, type: 'checked');
        $shippingMethod = getWebConfig(name: 'shipping_method');

        if (count($cartItemGroupIDsAll) <= 0) {
            $response['status'] = 0;
            $response['errorType'] = 'empty-cart';
            $response['redirect'] = url('/');
            $message[] = translate('no_items_in_basket');
        } elseif (count($cartItemGroupIDs) <= 0) {
            $response['status'] = 0;
            $response['errorType'] = 'empty-shipping';
            $response['redirect'] = route('shop-cart');
            $message[] = translate('Please_add_or_checked_items_before_proceeding_to_checkout');
        }

        $unavailableVendorsStatus = 0;
        $inhouseShippingMsgCount = 0;

        $isPhysicalProductExist = false;
        $productStockStatus = true;
        $checkedCartLists = [];
        foreach ($cartItemGroupIDs as $groupId) {
            $isPhysicalProductExist = false;
            $cartList = Cart::whereHas('product', function ($query) {
                return $query->active();
            })->with(['product' => function ($query) {
                return $query->active();
            }])->where(['cart_group_id' => $groupId, 'is_checked' => 1])->get();
            $checkedCartLists[] = $cartList;
            foreach ($cartList as $cart) {
                if ($cart->product_type == 'physical') {
                    $isPhysicalProductExist = true;
                    $response['physical_product_view'] = true;
                }
            }

            $cartList = self::firstCheckedCartLineOfGroup(groupId: $groupId);
            $productStockCheck = CartManager::product_stock_check($cartList);
            if (!$productStockCheck) {
                $productStockStatus = false;
            }

            foreach ($cartList as $cartKey => $cart) {
                if ($cartKey == 0) {
                    $vendorType = $cart->seller_is == "seller" ? 'vendor' : 'inhouse';
                    $vendorInfo = $cart->seller_is == "seller" ? Shop::where('seller_id', $cart?->seller_id)->first() : null;
                    $temporaryClose = checkVendorAbility(type: $vendorType, status: 'temporary_close', vendor: $vendorInfo);
                    $vacationStatus = checkVendorAbility(type: $vendorType, status: 'vacation_status', vendor: $vendorInfo);
                    if ($temporaryClose || $vacationStatus) {
                        $unavailableVendorsStatus = 1;
                    }
                }
            }

            if ($isPhysicalProductExist) {
                foreach ($cartList as $cart) {
                    if ($shippingMethod == 'inhouse_shipping') {
                        $adminShipping = ShippingType::where('seller_id', 0)->first();
                        $shippingType = isset($adminShipping) ? $adminShipping->shipping_type : 'order_wise';
                    } else {
                        if ($cart->seller_is == 'admin') {
                            $adminShipping = ShippingType::where('seller_id', 0)->first();
                            $shippingType = isset($adminShipping) ? $adminShipping->shipping_type : 'order_wise';
                        } else {
                            $sellerShipping = ShippingType::where('seller_id', $cart->seller_id)->first();
                            $shippingType = isset($sellerShipping) ? $sellerShipping->shipping_type : 'order_wise';
                        }
                    }

                    if ($isPhysicalProductExist && $shippingType == 'order_wise') {
                        $sellerShippingCount = 0;
                        if ($shippingMethod == 'inhouse_shipping') {
                            $sellerShippingCount = ShippingMethod::where(['status' => 1])->where(['creator_type' => 'admin'])->count();
                            if ($sellerShippingCount <= 0 && isset($cart->seller->shop)) {
                                $message[] = translate('shipping_Not_Available_for') . ' ' . getWebConfig(name: 'company_name');
                                $response['status'] = 0;
                                $response['redirect'] = route('shop-cart');
                            }
                        } else {
                            if ($cart->seller_is == 'admin') {
                                $sellerShippingCount = ShippingMethod::where(['status' => 1])->where(['creator_type' => 'admin'])->count();
                                if ($sellerShippingCount <= 0 && isset($cart->seller->shop)) {
                                    $message[] = translate('shipping_Not_Available_for') . ' ' . getInHouseShopConfig(key: 'name');
                                    $response['status'] = 0;
                                    $response['redirect'] = route('shop-cart');
                                }
                            } else if ($cart->seller_is == 'seller') {
                                $sellerShippingCount = ShippingMethod::where(['status' => 1])->where(['creator_id' => $cart->seller_id, 'creator_type' => 'seller'])->count();
                                if ($sellerShippingCount <= 0 && isset($cart->seller->shop)) {
                                    $message[] = translate('shipping_Not_Available_for') . ' ' . $cart->seller->shop->name;
                                    $response['status'] = 0;
                                    $response['redirect'] = route('shop-cart');
                                }
                            }
                        }

                        if ($sellerShippingCount > 0 && $shippingMethod == 'inhouse_shipping' && $inhouseShippingMsgCount < 1) {
                            $cartShipping = CartShipping::where('cart_group_id', $cart->cart_group_id)->first();
                            if (!isset($cartShipping)) {
                                $response['status'] = 0;
                                $response['errorType'] = 'empty-shipping';
                                $response['redirect'] = route('shop-cart');
                                $message[] = translate('select_shipping_method');
                            }
                            $inhouseShippingMsgCount++;
                        } elseif ($sellerShippingCount > 0 && $shippingMethod != 'inhouse_shipping') {
                            $cartShipping = CartShipping::where('cart_group_id', $cart->cart_group_id)->first();
                            if (!isset($cartShipping)) {
                                $response['status'] = 0;
                                $response['errorType'] = 'empty-shipping';
                                $response['redirect'] = route('shop-cart');
                                $shopIdentity = $cart->seller_is == 'admin' ? getInHouseShopConfig(key: 'name') : $cart->seller->shop->name;
                                $message[] = translate('select') . ' ' . $shopIdentity . ' ' . translate('shipping_method');
                            }
                        }
                    }
                }
            }
        }

        if ($unavailableVendorsStatus) {
            $message[] = translate('please_remove_all_products_from_unavailable_vendors');
            $response['status'] = 0;
            $response['redirect'] = route('shop-cart');
        }

        if (!$productStockStatus) {
            $message[] = translate('Please_remove_this_unavailable_product_for_continue');
            $response['status'] = 0;
            $response['redirect'] = route('shop-cart');
        }

        // One order may name one country and one selling company (D1-02). The rows already read above are judged, so the page refuses
        // the checkout here instead of letting generateOrder() throw at the customer; each country's rows can still be bought on their own.
        if (OneSellingCompanyPerOrder::spansMoreThanOne(cartRows: collect($checkedCartLists)->flatten(1))) {
            $message[] = translate('Product_is_unavailable');
            $response['status'] = 0;
            $response['redirect'] = route('shop-cart');
        }

        $response['message'] = $message;
        return $response ?? [];
    }

    public static function validateStockAvailability($request): array
    {
        $user = Helpers::getCustomerInformation($request);
        $query = Cart::whereHas('product', function ($query) {
            return $query->active();
        })->with(['seller', 'allProducts' => function ($query) {
            return $query->active();
        }])->where([
            'customer_id' => ($user == 'offline' ? (session('guest_id') ?? $request->guest_id) : $user->id),
            'is_guest' => ($user == 'offline' ? 1 : '0'),
            'is_checked' => 1,
        ])->get();

        $response['status'] = 1;
        foreach ($query as $cart) {
            if ($cart->allProducts && $cart->allProducts->minimum_order_qty > $cart->quantity) {
                $response['status'] = 0;
                $response['redirect'] = route('shop-cart');
                $response['message'] = "The product '{$cart->allProducts->name}' requires a minimum quantity of {$cart->allProducts->minimum_order_qty}.";
                break;
            }
        }
        return $response ?? [];
    }

    public static function getOrderTotalPriceSummary($order): array
    {
        $itemPrice = 0;
        $subTotal = 0;
        $itemDiscount = 0;
        $totalProductPrice = 0;
        $couponDiscount = 0;
        $referAndEarnDiscount = 0;
        $deliveryFeeDiscount = 0;
        $totalItemQuantity = 0;
        $editedTotalPaidAmount = 0;

        foreach ($order->details as $detailKey => $detail) {
            $itemPrice += $detail['price'] * $detail['qty'];
            $subTotal += $detail['price'] * $detail['qty'];
            $totalProductPrice += ($detail['price'] * $detail['qty']);
            $itemDiscount += $detail['discount'];
            $totalItemQuantity += $detail['qty'];
        }
        $total = $itemPrice - $itemDiscount;
        $shipping = $order['shipping_cost'];

        if ($order['extra_discount_type'] == 'percent') {
            $extraDiscount = (($totalProductPrice - $itemDiscount - (isset($order['discount_amount']) ? $order['discount_amount'] : 0)) / 100) * $order['extra_discount'];
        } else {
            $extraDiscount = $order['extra_discount'];
        }

        if (isset($order['discount_amount'])) {
            $couponDiscount = $order['discount_amount'];
        }
        if (isset($order['refer_and_earn_discount'])) {
            $referAndEarnDiscount = $order['refer_and_earn_discount'];
        }
        if ($order['is_shipping_free'] == 1) {
            $deliveryFeeDiscount = $shipping;
        }

        $totalAmount = ($total + $shipping - $extraDiscount - $couponDiscount - $referAndEarnDiscount + $order['total_tax_amount']);

        if ($order['edit_due_amount'] > 0) {
            $editedTotalPaidAmount =  $totalAmount - $order['edit_due_amount'];
        } else if ($order['edit_return_amount'] > 0) {
            $editedTotalPaidAmount =  $totalAmount + $order['edit_return_amount'];
        }

        return [
            'itemPrice' => $itemPrice,
            'itemDiscount' => $itemDiscount,
            'extraDiscount' => $extraDiscount,
            'subTotal' => $subTotal - $itemDiscount - ($order['is_shipping_free'] == 1 ? 0 : $extraDiscount),
            'couponDiscount' => $couponDiscount,
            'referAndEarnDiscount' => $referAndEarnDiscount,
            'taxTotal' => $order['total_tax_amount'],
            'tax_model' => $order['tax_model'],
            'shippingTotal' => $shipping,
            'deliveryFeeDiscount' => $deliveryFeeDiscount,
            'totalItemQuantity' => $totalItemQuantity,
            'totalAmount' => $totalAmount,
            'totalTaxAmount' => $order['total_tax_amount'],
            'paidAmount' => $order['paid_amount'],
            'changeAmount' => ($order['paid_amount'] - $totalAmount),
            'due_amount_after_edit' => $order['edit_due_amount'],
            'edited_total_paid_amount' => $editedTotalPaidAmount,
        ];
    }

    public static function getTrackOrderStatusHistory(int $orderId, bool $isOrderOnlyDigital): array
    {
        $statusHistory = OrderStatusHistory::where('order_id', $orderId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->unique('status')
            ->keyBy('status');

        $order = Order::where('id', $orderId)->select('created_at')->first();

        if (!$isOrderOnlyDigital) {
            $orderTracking = [
                'order_placed' => ['key' => 'order_placed', 'label' => translate('order_placed'), 'status' => true, 'date_time' => $order->created_at],
                'order_confirmed' => ['key' => 'order_confirmed', 'label' => translate('order_confirmed'), 'status' => false, 'date_time' => null],
                'preparing_for_shipment' => ['key' => 'preparing_for_shipment', 'label' => translate('preparing_for_shipment'), 'status' => false, 'date_time' => null],
                'order_is_on_the_way' => ['key' => 'order_is_on_the_way', 'label' => translate('order_is_on_the_way'), 'status' => false, 'date_time' => null],
                'order_delivered' => ['key' => 'order_delivered', 'label' => translate('order_delivered'), 'status' => false, 'date_time' => null],
                'order_returned' => ['key' => 'order_returned', 'label' => translate('order_returned'), 'status' => false, 'date_time' => null],
                'order_failed' => ['key' => 'order_failed', 'label' => translate('order_failed'), 'status' => false, 'date_time' => null],
                'order_canceled' => ['key' => 'order_canceled', 'label' => translate('order_canceled'), 'status' => false, 'date_time' => null],
            ];

            $statusMapping = [
                'order_placed' => 'pending',
                'order_confirmed' => 'confirmed',
                'preparing_for_shipment' => 'processing',
                'order_is_on_the_way' => 'out_for_delivery',
                'order_delivered' => 'delivered',
                'order_returned' => 'returned',
                'order_failed' => 'failed',
                'order_canceled' => 'canceled',
            ];
        } else {
            $orderTracking = [
                'order_placed' => ['key' => 'order_placed', 'label' => translate('order_placed'), 'status' => true, 'date_time' => $order->created_at],
                'order_confirmed' => ['key' => 'order_confirmed', 'label' => translate('order_confirmed'), 'status' => false, 'date_time' => null],
                'order_delivered' => ['key' => 'order_delivered', 'label' => translate('order_delivered'), 'status' => false, 'date_time' => null],
            ];

            $statusMapping = [
                'order_placed' => 'pending',
                'order_confirmed' => 'confirmed',
                'order_delivered' => 'delivered',
            ];
        }

        foreach ($orderTracking as $statusKey => &$statusData) {
            if (isset($statusMapping[$statusKey]) && isset($statusHistory[$statusMapping[$statusKey]])) {
                $statusData['status'] = true;
                $statusData['date_time'] = $statusHistory[$statusMapping[$statusKey]]->created_at;
            }
        }
        unset($statusData);

        $statusKeys = array_keys($orderTracking);

        $terminalStatuses = ['order_canceled', 'order_returned', 'order_failed'];

        $activeTerminalStatus = null;
        $terminalIndex = -1;
        foreach ($terminalStatuses as $terminalStatus) {
            if (isset($orderTracking[$terminalStatus]) && $orderTracking[$terminalStatus]['status'] === true) {
                $activeTerminalStatus = $terminalStatus;
                $terminalIndex = array_search($terminalStatus, $statusKeys);
                break;
            }
        }

        if ($activeTerminalStatus !== null) {
            if (isset($orderTracking['order_delivered'])) {
                $orderTracking['order_delivered']['status'] = false;
                $orderTracking['order_delivered']['date_time'] = null;
            }
            $lastActualCompletedIndex = -1;

            for ($i = 0; $i < $terminalIndex; $i++) {
                if (!in_array($statusKeys[$i], $terminalStatuses)
                    && $statusKeys[$i] !== 'order_delivered'
                    && isset($orderTracking[$statusKeys[$i]])
                    && is_array($orderTracking[$statusKeys[$i]])
                    && $orderTracking[$statusKeys[$i]]['status'] === true
                    && $orderTracking[$statusKeys[$i]]['date_time'] !== null) {
                    $lastActualCompletedIndex = $i;
                }
            }

            if ($lastActualCompletedIndex >= 0) {
                $backfillDateTime = $orderTracking[$statusKeys[$lastActualCompletedIndex]]['date_time'];

                for ($j = 0; $j < $lastActualCompletedIndex; $j++) {
                    if (is_array($orderTracking[$statusKeys[$j]])
                        && $orderTracking[$statusKeys[$j]]['status'] === false
                        && $statusKeys[$j] !== 'order_delivered'
                        && !in_array($statusKeys[$j], $terminalStatuses)) {
                        $orderTracking[$statusKeys[$j]]['status'] = true;
                        $orderTracking[$statusKeys[$j]]['date_time'] = $backfillDateTime;
                    }
                }
            }


            if ($lastActualCompletedIndex >= 0) {
                for ($j = $lastActualCompletedIndex + 1; $j < $terminalIndex; $j++) {
                    if ($statusKeys[$j] !== 'order_delivered' && !in_array($statusKeys[$j], $terminalStatuses)) {
                        $orderTracking[$statusKeys[$j]]['status'] = false;
                        $orderTracking[$statusKeys[$j]]['date_time'] = null;
                    }
                }
            }


            foreach ($terminalStatuses as $terminalStatus) {
                if ($terminalStatus !== $activeTerminalStatus && isset($orderTracking[$terminalStatus])) {
                    $orderTracking[$terminalStatus]['status'] = false;
                    $orderTracking[$terminalStatus]['date_time'] = null;
                }
            }

        } else {

            $lastCompletedIndex = null;

            for ($i = count($statusKeys) - 1; $i >= 0; $i--) {
                if ($orderTracking[$statusKeys[$i]]['status'] === true) {
                    $lastCompletedIndex = $i;
                    break;
                }
            }

            if ($lastCompletedIndex !== null) {
                for ($j = 0; $j < $lastCompletedIndex; $j++) {
                    if ($orderTracking[$statusKeys[$j]]['status'] === false) {
                        $orderTracking[$statusKeys[$j]]['status'] = true;
                        $orderTracking[$statusKeys[$j]]['date_time'] = $orderTracking[$statusKeys[$lastCompletedIndex]]['date_time'];
                    }
                }
            }
        }


        $withDate = [];
        $withoutDate = [];
        foreach ($statusKeys as $statusKey) {
            if (isset($orderTracking[$statusKey]) && is_array($orderTracking[$statusKey]) && $orderTracking[$statusKey]['date_time'] !== null) {
                $withDate[$statusKey] = $orderTracking[$statusKey];
            } elseif (isset($orderTracking[$statusKey]) && is_array($orderTracking[$statusKey])) {
                $withoutDate[$statusKey] = $orderTracking[$statusKey];
            }
        }

        return [
            'history' => $withDate + $withoutDate,
            'is_digital_order' => $isOrderOnlyDigital
        ];
    }

    public static function removeOldStatusHistory($orderId, $orderStatus): void
    {
        $statusMapping = [
            'pending' => 'order_placed',
            'confirmed' => 'order_confirmed',
            'processing' => 'preparing_for_shipment',
            'out_for_delivery' => 'order_is_on_the_way',
            'delivered' => 'order_delivered',
            'returned' => 'order_returned',
            'failed' => 'order_failed',
            'canceled' => 'order_canceled',
        ];

        $statusKeys = array_keys($statusMapping);
        $currentIndex = array_search($orderStatus, $statusKeys, true);

        if ($currentIndex === false) {
            return;
        }
        if ($currentIndex < count($statusKeys) - 1) {
            $futureStatuses = array_slice($statusKeys, $currentIndex + 1);
            OrderStatusHistory::where('order_id', $orderId)
                ->whereIn('status', $futureStatuses)
                ->delete();
        }

        $orderHistories = OrderStatusHistory::where('order_id', $orderId)
            ->orderBy('created_at', 'desc')
            ->get();

        $grouped = $orderHistories->groupBy('status');

        foreach ($grouped as $records) {
            $duplicates = $records->slice(1);
            foreach ($duplicates as $duplicate) {
                $duplicate->delete();
            }
        }
    }
    public static function sendPushNotificationAfterDuePayment($order):void{
        $orderEditNotificationEvent = [];
        if ($order['seller_is'] == 'seller') {
            $seller = Seller::find($order['seller_id']);
            if ($seller) {
                $orderEditNotificationEvent[] = [
                    'notification' => true,
                    'notificationData' => (object)[
                        'key' => 'order_edit_due_payment_message',
                        'type' => 'seller',
                        'order' => $order,
                    ],
                ];
            }
        }

        foreach ($orderEditNotificationEvent as $orderEditDuePaymentEvent) {
            if (!empty($orderEditDuePaymentEvent)) {
                event(new OrderEditDuePaymentEvent(notification: $orderEditDuePaymentEvent['notificationData']));
            }
        }
    }
}