<?php

namespace App\Utils;

use App\Services\Loyalty\LoyaltyEarning;
use App\Services\Loyalty\WhatAPointIsWorth;
use App\Services\Loyalty\WhenARefundWouldTakePointsBelowZero;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\OrderDetail;
use App\Models\SupportTicket;
use App\Models\ProductCompare;
use App\Models\RestockProduct;
use App\Models\BusinessSetting;
use App\Models\ReferralCustomer;
use App\Models\WalletTransaction;
use App\Models\LoyaltyPointTransaction;
use App\Services\Customer\CustomerBalanceLock;
use App\Services\Customer\WalletMinorUnit;
use App\Services\Storefront\LedgerAttribution;
use App\Services\Storefront\LedgerOrigin;
use App\Services\Wallet\WalletOverdraft;

class CustomerManager
{
    public static function create_support_ticket($data)
    {
        $support = new SupportTicket();
        $support->customer_id = $data['customer_id'];
        $support->subject = $data['subject'];
        $support->type = $data['type'];
        $support->priority = $data['priority'];
        $support->description = $data['description'];
        $support->attachment = $data['attachment'];
        $support->status = $data['status'];
        $support->save();

        return $support;
    }

    /**create_wallet_transaction -> function on Customer trait -> createWalletTransaction*/
    public static function create_wallet_transaction($user_id, float $amount, $transaction_type, $reference, $payment_data = [], bool $amountInUsd = false, ?LedgerOrigin $origin = null)
    {
        if (BusinessSetting::where('type', 'wallet_status')->first()->value != 1) return false;

        return CustomerBalanceLock::apply($user_id, function (User $user) use ($amount, $transaction_type, $reference, $payment_data, $amountInUsd, $origin) {
            $current_balance = $user->wallet_balance;

            $wallet_transaction = new WalletTransaction();
            $wallet_transaction->user_id = $user->id;
            $wallet_transaction->transaction_id = \Str::uuid();
            $wallet_transaction->reference = $reference;
            $wallet_transaction->transaction_type = $transaction_type;
            $wallet_transaction->payment_method = isset($payment_data['payment_method']) ? $payment_data['payment_method'] : null;

            $debit = 0.0;
            $credit = 0.0;
            $add_fund_to_wallet_bonus = 0;

            if (in_array($transaction_type, ['add_fund_by_admin', 'add_fund', 'order_refund', 'loyalty_point', 'due_payment_for_order','return_order_amount_by_admin'])) {
                $credit = $amount;
                if ($transaction_type == 'add_fund') {
                    $wallet_transaction->admin_bonus = Helpers::add_fund_to_wallet_bonus(Convert::usd($amount ?? 0));
                    $add_fund_to_wallet_bonus = Helpers::add_fund_to_wallet_bonus(Convert::usd($amount ?? 0));
                } else if ($transaction_type == 'loyalty_point') {
                    /*
                     * THE ONE PLACE THAT ANSWERS WHAT A POINT IS WORTH (E-14, LF-1), which this writer did not ask.
                     *
                     * `App\Services\Loyalty\WhatAPointIsWorth` was built to hold both of the platform's formulas so
                     * that answering LF-1 would be one setting rather than a hunt through the call sites -- and the only
                     * thing reading it was `WalletPosting`, which NOTHING CALLS. The REST conversion
                     * (RestAPI\v1\UserLoyaltyController) comes here, so the setting moved no money at all.
                     *
                     * The formula is the same one to the digit -- `points / rate * Convert::default(1)`, which is the
                     * class's API reading -- with one difference that is a repair: the rate comes from `getWebConfig`
                     * and not straight off `business_settings`, so a market with its own exchange rate converts at ITS
                     * rate (E-02). A market with no override, and every market while storefronts are off, reads the same
                     * platform value this line read before.
                     */
                    $credit = WhatAPointIsWorth::creditFor((float) $amount, WhatAPointIsWorth::API);
                } else if ($transaction_type == "due_payment_for_order") {
                    $debit = $amount;
                    $credit = 0;
                } else if ($transaction_type == "return_order_amount_by_admin") {
                    $debit = 0;
                    $credit = $amount;
                }
            } else if ($transaction_type == 'order_place') {
                $debit = $amount;
            }
            // One unit for the credit, whichever writer is asked (E-16): WalletMinorUnit is the one helper all five of them round
            // through, and it hands the amount straight back while its switch is off, which is how it ships.
            $credit_amount = WalletMinorUnit::credited($amountInUsd ? $credit : Convert::usd($credit));
            $debit_amount = $amountInUsd ? $debit : Convert::usd($debit);
            $balance_before = $amountInUsd ? round((float) $current_balance, 2) : $current_balance;
            if (WalletOverdraft::refuses($balance_before, $debit_amount)) {
                return false;
            }
            $settle = static fn (float $balance): float => $amountInUsd ? round($balance, 2) : $balance;
            // The row carries the balance the account ENDS with, bonus included (E-16). All four copies of this write set the
            // account to `previous + bonus + credit - debit` and wrote `previous + credit - debit` on the row, so an `add_fund`
            // row that earned a bonus understated the account by exactly the bonus it was printing in the next column -- on the
            // admin wallet report, which prints both on one line, and in the add-fund email, which prints the row's balance as
            // the customer's new balance. One figure, used twice, is why the two cannot disagree again
            // (App\Services\Customer\CustomerWalletWriter already writes it this way). Rows written before this are NOT
            // rewritten and keep the understated figure; they stay reconcilable, because `admin_bonus` is on the row in both
            // eras.
            $balanceAfter = $settle($balance_before + $add_fund_to_wallet_bonus + $credit_amount - $debit_amount);
            $wallet_transaction->credit = $credit_amount;
            $wallet_transaction->debit = $debit_amount;
            $wallet_transaction->balance = $balanceAfter;
            $wallet_transaction->created_at = now();
            $wallet_transaction->updated_at = now();
            // What stands behind the row (E-05); dormant until its migration runs, written inside the balance lock and
            // after the overdraft guard, so it records the write rather than deciding it.
            $wallet_transaction->fill(app(LedgerAttribution::class)->of(table: $wallet_transaction->getTable(), origin: $origin));
            $user->wallet_balance = $balanceAfter;

            $user->save();
            $wallet_transaction->save();

            if (in_array($transaction_type, ['loyalty_point', 'order_place', 'add_fund_by_admin', 'add_fund', 'due_payment_for_order','return_order_amount_by_admin'])) return $wallet_transaction;
            return true;
        });
    }
    public static function create_loyalty_point_transaction($user_id, $reference, $amount, $transaction_type, ?LedgerOrigin $origin = null): bool
    {
        // Read for the MARKET THE ORDER WAS SOLD IN (E-02, J-V2). Reading through getWebConfig was the first half of it: that answers for
        // the market of the REQUEST, and every caller of this is a seller API where the request serves no storefront, so it still earned
        // at the platform rate for an order of any market. Only the order knows which market it was sold in, and LoyaltyEarning asks it --
        // the same rule the repository writer now reads, in one place, because two writers of the same money must not disagree about it.
        $earning = app(LoyaltyEarning::class);

        if (!$earning->programmeIsOn(origin: $origin)) {
            return true;
        }

        $credit = 0;
        $debit = 0;
        if ($transaction_type == 'order_place') {
            $credit = $earning->pointsForAnOrder(amount: $amount, origin: $origin);
        } else if ($transaction_type == 'point_to_wallet' || $transaction_type == 'refund_order') {
            $debit = $amount;
        }

        return CustomerBalanceLock::apply($user_id, function (User $user) use ($reference, $transaction_type, $credit, $debit, $origin) {
            // The same question as the repository writer's, asked in the same place and answered by the same rule: two
            // writers of one customer's points must not disagree about whether a refund may take them below zero (E-14).
            if ($transaction_type == 'refund_order') {
                $allowed = WhenARefundWouldTakePointsBelowZero::debitFor(held: (float) $user->loyalty_point, debit: (float) $debit);
                if ($allowed === null) {
                    return false;
                }
                $debit = $allowed;
            }

            $current_balance = $user->loyalty_point + $credit - $debit;
            if ($transaction_type == 'point_to_wallet' && $current_balance < 0) {
                return false;
            }

            $loyalty_point_transaction = new LoyaltyPointTransaction();
            $loyalty_point_transaction->user_id = $user->id;
            $loyalty_point_transaction->transaction_id = \Str::uuid();
            $loyalty_point_transaction->reference = $reference;
            $loyalty_point_transaction->transaction_type = $transaction_type;
            $loyalty_point_transaction->balance = $current_balance;
            $loyalty_point_transaction->credit = $credit;
            $loyalty_point_transaction->debit = $debit;
            $loyalty_point_transaction->created_at = now();
            $loyalty_point_transaction->updated_at = now();
            $loyalty_point_transaction->fill(app(LedgerAttribution::class)->of(table: $loyalty_point_transaction->getTable(), origin: $origin));
            $user->loyalty_point = $current_balance;

            $user->save();
            $loyalty_point_transaction->save();

            return true;
        });
    }

    public static function countLoyaltyPointForAmount($id): int
    {
        $orderDetails = OrderDetail::find($id);
        $loyaltyPointStatus = getWebConfig(name: 'loyalty_point_status');
        $loyaltyPoint = 0;
        if ($loyaltyPointStatus == 1) {
            $loyaltyPointItemPurchasePoint = getWebConfig(name: 'loyalty_point_item_purchase_point');
            $subtotal = ($orderDetails->price * $orderDetails->qty) - $orderDetails->discount + $orderDetails->tax;
            return (int)(Convert::default($subtotal) * $loyaltyPointItemPurchasePoint / 100);
        }
        return $loyaltyPoint;
    }

    public static function updateCustomerSessionData($userId): void
    {
        session()->forget('coupon_code');
        session()->forget('coupon_type');
        session()->forget('coupon_bearer');
        session()->forget('coupon_discount');
        session()->forget('payment_method');
        session()->forget('shipping_method_id');
        session()->forget('billing_address_id');
        session()->forget('order_id');
        session()->forget('cart_group_id');
        session()->forget('order_note');
        session()->forget('wish_list');
        session()->forget('compare_list');

        $compareListArray = ProductCompare::whereHas('product')->where('user_id', $userId)->pluck('product_id')->toArray();
        $wishList = Wishlist::whereHas('wishlistProduct', function ($query) {
            return $query->active();
        })->where('customer_id', $userId)->pluck('product_id')->toArray();

        session()->forget('customer_fcm_topic');
        session()->put('wish_list', $wishList);
        session()->put('compare_list', $compareListArray);


        $restockProductList = RestockProduct::whereHas('restockProductCustomers', function ($query) use ($userId) {
            return $query->where('customer_id', $userId);
        })->get();

        if ($restockProductList) {
            $customerFCMTopics = [];
            foreach ($restockProductList as $restockProduct) {
                $customerFCMTopics[] = getRestockProductFCMTopic(restockRequest: $restockProduct);
            }
            session()->put('customer_fcm_topic', $customerFCMTopics);
        }
    }

    public static function onlyUpdateCustomerCompareAndWishListSession($userId): void
    {
        $compareListArray = ProductCompare::whereHas('product')->where('user_id', $userId)->pluck('product_id')->toArray();
        $wishList = Wishlist::whereHas('wishlistProduct', function ($query) {
            return $query->active();
        })->where('customer_id', $userId)->pluck('product_id')->toArray();

        session()->put('wish_list', $wishList);
        session()->put('compare_list', $compareListArray);
    }


    public static function getReferralDiscountAmount($user = null, $couponDiscount = null)
    {
        if ($user && isset($user['id'])) {
            $cart = CartManager::getCartListQuery(type: 'checked');
            $totalAmount = 0;
            if (!empty($cart)) {
                foreach ($cart as $item) {
                    $discount = getProductPriceByType(product: $item['product'], type: 'discounted_amount', result: 'value', price: $item['price']);
                    $totalAmount += ($item['price'] - $discount) * $item['quantity'];
                }
            }
            $couponDiscount = is_null($couponDiscount) ? 0 : (float)$couponDiscount;
            if ($totalAmount > $couponDiscount && $couponDiscount != 0) {
                $totalAmount = $totalAmount - $couponDiscount;
            }

            $referralCustomerCheck = ReferralCustomer::where('user_id', $user['id'])->where('is_used', 0)->first();
            if (!empty($referralCustomerCheck)) {
                $type = $referralCustomerCheck->customer_discount_amount_type;
                $amount = $referralCustomerCheck->customer_discount_amount;
                $validity = $referralCustomerCheck->customer_discount_validity;
                $validityType = $referralCustomerCheck->customer_discount_validity_type;

                $expirationDate = Carbon::parse($referralCustomerCheck->created_at);
                if ($validityType == 'day') {
                    $expirationDate->addDays($validity);
                } else if ($validityType == 'week') {
                    $expirationDate->addWeeks($validity);
                } else if ($validityType == 'month') {
                    $expirationDate->addMonths($validity);
                } else {
                    return 0;
                }

                if (Carbon::now()->greaterThan($expirationDate)) {
                    return 0;
                }
                if ($type == 'flat') {
                    return $amount;
                }
                return ($totalAmount * $amount) / 100;
            }
        }
        return 0;
    }

}
