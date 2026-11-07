<?php

namespace App\Utils;

use App\Services\Analytics\Analytics;
use App\Events\OrderEditDuePaymentEvent;
use App\Models\OrderDetailsRewards;
use App\Models\OrderEditHistory;
use App\Models\OrderStatusHistory;
use App\Models\ShippingMethod;
use Carbon\Carbon;
use App\Models\Cart;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\DigitalProductVariation;
use Modules\TaxModule\app\Traits\VatTaxManagement;
use App\Services\DeliverySyria\DeliverySyriaClient;
use App\Services\DeliverySyria\DeliverySyriaRateCalculator;
use App\Models\DeliverySyriaGovernorateRate;
use App\Models\DeliverySyriaParcel;
use App\Services\Fulfillment\FulfillmentHooks;
use App\Services\Fulfillment\OrderItemOwnershipSnapshotter;
use App\Services\SalesChannels\Dispatch\ChannelOrderMarker;
use App\Enums\Address\AddressChannel;
use App\Services\Address\OrderAddressService;


use App\Services\DeliverySyria\DeliverySyriaConfigService;


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

                $flipped = OrderDetail::where(['id' => $detail['id'], 'is_stock_decreased' => $fromFlag])
                    ->update(['is_stock_decreased' => $toFlag, 'delivery_status' => $status]);

                if (!$flipped) {
                    return;   // already in the target state — another run handled it; do not touch stock
                }

                $product = Product::where('id', $detail['product_id'])->lockForUpdate()->first();
                if (!$product) {
                    return;
                }

                // Adjust the specific variant's quantity under the same lock.
                $type = $detail['variant'];
                $variationData = [];
                foreach (json_decode($product['variation'], true) ?? [] as $var) {
                    if ($type == $var['type']) {
                        $var['qty'] = $restoring ? ($var['qty'] + $detail['qty']) : ($var['qty'] - $detail['qty']);
                    }
                    $variationData[] = $var;
                }
                Product::where(['id' => $product['id']])->update(['variation' => json_encode($variationData)]);

                if ($restoring) {
                    Product::where(['id' => $product['id']])->increment('current_stock', $detail['qty']);
                } else {
                    // Floor at zero on re-deduct so an out-of-band stock change can't drive it negative.
                    $taken = Product::where('id', $product['id'])
                        ->where('current_stock', '>=', $detail['qty'])
                        ->decrement('current_stock', $detail['qty']);
                    if ($taken === 0) {
                        Product::where(['id' => $product['id']])->update(['current_stock' => 0]);
                    }
                }
            });
        }
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

    public static function getTotalCouponAmount($request, $couponCode): array
    {
        $user = Helpers::getCustomerInformation($request);
        if ($user == 'offline') {
            return [
                'status' => false,
                'messages' => translate('Coupon_not_applicable'),
            ];
        }
        $couponLimit = Order::where(['customer_id' => $user['id'], 'coupon_code' => $couponCode])
            ->groupBy('order_group_id')->get()->count();

        $firstCoupon = Coupon::where(['code' => $couponCode])
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
            if (Order::where(['customer_id' => $user['id']])->count() > 0) {
                return [
                    'status' => false,
                    'messages' => translate('sorry_this_coupon_is_not_valid_for_this_user') . '!',
                ];
            }
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
            if ($onlyProductTotalAmount <= 0 || $couponDiscount > $onlyProductTotalAmount || $coupon['min_purchase'] > $onlyProductTotalAmount) {
                return [
                    'status' => false,
                    'messages' => translate('minimum_purchase_amount_not_reached_for_this_coupon.')
                ];
            }

            if ($couponDiscountType == 'percentage') {
                $discountAmount = ($onlyProductTotalAmount * $couponDiscount) / 100;
                $discount = $discountAmount > $coupon['max_discount'] ? $coupon['max_discount'] : $discountAmount;
            } else {
                $discount = $couponDiscount;
            }
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

        $couponLimit = Order::where(['customer_id' => $user['id'], 'coupon_code' => $couponCode])
            ->groupBy('order_group_id')->get()->count();

        $firstCoupon = Coupon::where(['code' => $couponCode])
            ->where('status', 1)
            ->whereDate('start_date', '<=', date('Y-m-d'))
            ->whereDate('expire_date', '>=', date('Y-m-d'))->first();

        $coupon = $firstCoupon && $firstCoupon['coupon_type'] == 'first_order' ? $firstCoupon : ($firstCoupon['limit'] > $couponLimit ? $firstCoupon : null);

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
        $customer = Helpers::getCustomerInformation($data['requestObj'] ?? request()->all());
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
        $baseID = 100001;
        $orderDetailsId = OrderDetail::orderBy('order_id', 'desc')->lockForUpdate()->first()?->order_id ?? $baseID;
        if (Order::find($orderDetailsId)) {
            $orderDetailsId = Order::orderBy('id', 'DESC')->lockForUpdate()->first()->id + 1;
        }
        return $orderDetailsId;
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
                self::createWalletTransaction(
                    user_id: $referredByUser['id'],
                    amount: usdToDefaultCurrency(amount: $refEarningExchangeRate),
                    transaction_type: 'add_fund_by_admin',
                    reference: 'earned_by_referral'
                );
            }
        }
    }

    public static function getVendorWiseCartList(array|object|null $data = []): array
    {
        $cartListQuery = CartManager::getCartListQuery(type: 'checked');
        $cartListQueryGroup = $cartListQuery?->groupBy('cart_group_id');
        $checkReferralDiscount = OrderManager::checkCustomerReferralDiscount(request: ($data['requestObj'] ?? request()->all()));
        $onlyProductPriceGrandTotal = CartManager::getOnlyCartProductPriceGrandTotal(type: 'checked');

        $shippingModel = getWebConfig(name: 'shipping_method');
        $adminShipping = ShippingType::where('seller_id', 0)->first();

        $couponCode = $data['coupon_code'] ?? session('coupon_code');
        $totalCouponDiscountInfo = OrderManager::getTotalCouponAmount(request: ($data['requestObj'] ?? request()->all()), couponCode: $couponCode);
        $totalCouponDiscount = $totalCouponDiscountInfo['discount'] ?? 0;

        $vendorWiseCartList = [];
        foreach ($cartListQueryGroup as $groupId => $cartListQueryGroupItem) {

            $couponInfo = OrderManager::getGroupWiseCouponArray(
                request: ($data['requestObj'] ?? request()->all()),
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
                    $vendorWiseCart['applied_tax_cart_list'][] = [
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
            $modifiedVendorWiseCartList[] = $vendorWiseCart;
        }

        return $modifiedVendorWiseCartList;
    }

    public static function getOrderAddData(int $orderId, string $orderGroupId, object|array $customerData = [], object|array $cartData = [], object|array $orderData = [], object|array $totalTax = []): array
    {
        $taxConfig = self::getTaxSystemType();
        $adminCommission = (float)str_replace(",", "", Helpers::sales_commission_before_order($cartData['cart_group_id'], $cartData['coupon_discount']));
        return [
            'id' => $orderId,
            'verification_code' => rand(100000, 999999),
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

                $ledger->record(
                    sellerId: $sellerId,
                    entryType: \App\Models\VendorLedgerEntry::TYPE_ORDER_EARNING,
                    credit: (float) $calculated['commissionable_amount'],
                    referenceType: 'order_details',
                    referenceId: $orderDetailsId,
                    description: 'Order #' . $orderId,
                    availableAt: $availableAt,
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
                    );
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Commission snapshot failed for order detail ' . $orderDetailsId . ': ' . $exception->getMessage());
        }
    }

    public static function addOrderDetailsData(int $orderId, object|array|null $vendorCart = []): void
    {
        $orderDetailsRewardsData = [];
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
                    foreach ($cartItem['applied_tax_ids'] as $taxItem) {
                        if ($taxItem) {
                            $appliedTaxRate += $taxItem['tax_rate'];
                        }
                    }
                }
            }

            $orderDetails['tax'] = $appliedTaxAmount;
            $orderDetails['tax_model'] = $taxConfig['is_included'] ? 'include' : 'exclude';

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
                orderDetailsId: $orderDetailsId,
                cartItem: $cartSingleItem,
                product: $product,
                netLineAmount: $finalAmount,
            );

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

            $orderDetailsRewardsData[] = [
                'order_id' => $orderId,
                'product_id' => $cartSingleItem['product_id'],
                'order_details_id' => $orderDetailsId,
                'price' => $finalAmount,
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

    public static function addOrderDetailsRewardData($orderId, $productId, $orderDetailsId, $price, $totalPrice, $couponCode, $couponDiscount): void
    {
        $loyaltyPointKeys = self::getLoyaltyPointKeys();
        $businessSettings = BusinessSetting::whereIn('type', $loyaltyPointKeys)->pluck('value', 'type')->toArray();
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
            'taxable_id' => '',
            'taxable_type' => '',
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

        // Order ids are chosen by generateNewOrderID(), not by the database, so two checkouts that
        // start together can pick the same number and the second insert dies on the primary key.
        // Observed on the running store: "Duplicate entry '100002' for key 'PRIMARY'" — a hard 500
        // at the last step of checkout. Locking does not help, because the row being counted may
        // not exist yet and an absent row cannot be locked. Re-running the transaction does: each
        // attempt re-reads the id, so the loser of the race simply takes the next number.
        for ($idCollisionAttempt = 1; ; $idCollisionAttempt++) {
        try {
            DB::transaction(function () use (
                $vendorWiseCartList, $taxConfig, $getCustomerInfo, $orderGroupId, $data, $stockPolicy,
                &$orderPlacedIds, &$orderPlacedNotificationEvents, &$orderPlacedMailEvents
            ) {
                // Reset per attempt. DB::transaction retries on deadlock, and these accumulate —
                // without this a retried attempt would report each order twice.
                $orderPlacedIds = [];
                $orderPlacedNotificationEvents = [];
                $orderPlacedMailEvents = [];

                // Lock every product in the order up front, ordered by id. Two carts holding the
                // same two products in opposite orders would otherwise each hold the row the other
                // needs — a deadlock. Acquiring them in one deterministic order removes the cycle;
                // the retry count below is the belt to this pair of braces.
                $lockProductIds = collect($vendorWiseCartList)
                    ->pluck('cart_list')->flatten(1)->pluck('product_id')
                    ->filter()->unique()->sort()->values()->all();

                if ($lockProductIds) {
                    Product::whereIn('id', $lockProductIds)->orderBy('id')->lockForUpdate()->get();
                }

        foreach ($vendorWiseCartList as $vendorWiseGroupId => $vendorWiseCart) {
            $vendorWiseCart['stock_policy'] = $stockPolicy;
            $order_id = OrderManager::generateNewOrderID();
            $orderPlacedIds[] = $order_id;

            $appliedTaxAmount = 0;
            $appliedTaxRate = 0;
            foreach ($vendorWiseCart['applied_tax_cart_list'] as $cartItem) {
                if ($cartItem['seller_id'] == $vendorWiseCart['seller_id'] && $cartItem['seller_is'] == $vendorWiseCart['seller_is']) {
                    $appliedTaxAmount = collect($vendorWiseCart['applied_tax_cart_list'])->where('cart_id', $cartItem['cart_id'])->sum('applied_shipping_cost_tax') ?? 0;
                    foreach ($cartItem['applied_shipping_cost_tax_ids'] as $taxIdGroup) {
                        foreach ($taxIdGroup['tax_ids'] as $taxId) {
                            $taxItem = $taxConfig['taxVats']->firstWhere('id', $taxId);
                            if ($taxItem) {
                                $appliedTaxRate += $taxItem['tax_rate'];
                            }
                        }
                    }
                }
            }

            foreach (collect($vendorWiseCart['applied_tax_cart_list'])->pluck('applied_shipping_cost_tax_ids')->toArray() as $taxGroups) {
                foreach ($taxGroups as $taxGroup) {
                    foreach ($taxGroup['tax_ids'] as $taxId) {
                        $taxItem = $taxConfig['taxVats']->firstWhere('id', $taxId);
                        if ($taxItem) {
                            self::getAddOrderTaxDetails(
                                systemTaxVat: $taxConfig['SystemTaxVat'],
                                taxRate: $taxItem,
                                orderId: $order_id,
                                data: [
                                    'tax_amount' => ($appliedTaxAmount > 0 && $appliedTaxRate > 0) ? ($appliedTaxAmount * $taxItem['tax_rate']) / $appliedTaxRate : 0,
                                    'before_tax_amount' => $vendorWiseCart['order_amount_with_tax'] - $vendorWiseCart['total_tax_amount'],
                                    'after_tax_amount' => $vendorWiseCart['order_amount_with_tax'],
                                    'quantity' => 0,
                                    'seller_id' => $vendorWiseCart['seller_id'],
                                    'seller_type' => $vendorWiseCart['seller_is'],
                                ],
                                taxOn: $taxGroup['name'],
                            );
                        }
                    }
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

            OrderManager::addOrderDetailsData(
                orderId: $order_id,
                vendorCart: $vendorWiseCart
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


        $user = Helpers::getCustomerInformation(($data['requestObj'] ?? request()->all()));

        $notificationSent = false;
        foreach ($orderPlacedIds as $orderPlacedId) {
            if (!$notificationSent && $user != 'offline') {
                $getOrder = Order::where('id', $orderPlacedId)->first();
                $referralUser = ReferralCustomer::where('user_id', $user['id'])->first();
                if ($referralUser && $referralUser->is_used != 1 && $referralUser->ordered_notify != 1) {
                    $orderPlacedNotificationEvents[] = [
                        'notification' => true,
                        'notificationData' => (object)[
                            'key' => 'your_referred_customer_has_been_place_order',
                            'type' => 'promoter',
                            'order' => $getOrder,
                        ],
                    ];
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

        CartManager::cartCleanByCartGroupIds(cartGroupIDs: collect($vendorWiseCartList)?->pluck('cart_group_id')->toArray() ?? []);

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


    public static function createWalletTransaction($user_id, float $amount, $transaction_type, $reference, $payment_data = []): bool|WalletTransaction
    {
        if (BusinessSetting::where('type', 'wallet_status')->first()->value != 1) return false;
        $user = User::find($user_id);
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

        $creditAmount = currencyConverter($credit);
        $debitAmount = currencyConverter($debit);
        $walletTransaction->credit = $creditAmount;
        $walletTransaction->debit = $debitAmount;
        $walletTransaction->balance = $currentBalance + $creditAmount - $debitAmount;
        $walletTransaction->created_at = now();
        $walletTransaction->updated_at = now();
        $user->wallet_balance = $currentBalance + $addFundToWalletBonus + $creditAmount - $debitAmount;

        try {
            DB::beginTransaction();
            $user->save();
            $walletTransaction->save();
            DB::commit();
            if (in_array($transaction_type, ['loyalty_point', 'order_place', 'add_fund_by_admin'])) return $walletTransaction;
            return true;
        } catch (Exception $ex) {
            info($ex);
            DB::rollback();
            return false;
        }
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
        $mpdf_view = \View::make(VIEW_FILE_NAMES['order_invoice'], compact('order', 'invoiceSettings'));
        return self::storePdf(view: $mpdf_view, filePrefix: 'order_invoice_', filePostfix: $order['id'], pdfType: 'invoice', requestFrom: 'web');
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
        foreach ($cartItemGroupIDs as $groupId) {
            $isPhysicalProductExist = false;
            $cartList = Cart::whereHas('product', function ($query) {
                return $query->active();
            })->with(['product' => function ($query) {
                return $query->active();
            }])->where(['cart_group_id' => $groupId, 'is_checked' => 1])->get();
            foreach ($cartList as $cart) {
                if ($cart->product_type == 'physical') {
                    $isPhysicalProductExist = true;
                    $response['physical_product_view'] = true;
                }
            }

            $cartList = Cart::whereHas('product', function ($query) {
                return $query->active();
            })->with(['product' => function ($query) {
                return $query->active();
            }])->groupBy('cart_group_id')->where(['cart_group_id' => $groupId, 'is_checked' => 1])->get();
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