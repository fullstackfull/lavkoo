<?php

namespace App\Utils;

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
use Illuminate\Support\Facades\DB;
use App\Models\LoyaltyPointTransaction;

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
    public static function create_wallet_transaction($user_id, float $amount, $transaction_type, $reference, $payment_data = [])
    {
        if (BusinessSetting::where('type', 'wallet_status')->first()->value != 1) return false;
        $user = User::find($user_id);
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
                $credit = (($amount / BusinessSetting::where('type', 'loyalty_point_exchange_rate')->first()->value) * Convert::default(1));
            } else if ($transaction_type == "due_payment_for_order") {
                $debit = $amount;
                $credit = 0;
            }else if ($transaction_type == "return_order_amount_by_admin") {
                $debit = 0;
                $credit = $amount;
            }
        } else if ($transaction_type == 'order_place') {
            $debit = $amount;
        }
        $credit_amount = Convert::usd($credit);
        $debit_amount = Convert::usd($debit);
        $wallet_transaction->credit = $credit_amount;
        $wallet_transaction->debit = $debit_amount;
        $wallet_transaction->balance = $current_balance + $credit_amount - $debit_amount;
        $wallet_transaction->created_at = now();
        $wallet_transaction->updated_at = now();
        $user->wallet_balance = $current_balance + $add_fund_to_wallet_bonus + $credit_amount - $debit_amount;

        try {
            DB::beginTransaction();
            $user->save();
            $wallet_transaction->save();
            DB::commit();
            if (in_array($transaction_type, ['loyalty_point', 'order_place', 'add_fund_by_admin', 'add_fund', 'due_payment_for_order','return_order_amount_by_admin'])) return $wallet_transaction;
            return true;
        } catch (\Exception $ex) {
            info($ex);
            DB::rollback();
            return false;
        }
    }

    public static function create_loyalty_point_transaction($user_id, $reference, $amount, $transaction_type): bool
    {
        $settings = array_column(BusinessSetting::whereIn('type', ['loyalty_point_status', 'loyalty_point_exchange_rate', 'loyalty_point_item_purchase_point'])->get()->toArray(), 'value', 'type');
        if ($settings['loyalty_point_status'] != 1) {
            return true;
        }

        $credit = 0;
        $debit = 0;
        $user = User::find($user_id);

        $loyalty_point_transaction = new LoyaltyPointTransaction();
        $loyalty_point_transaction->user_id = $user->id;
        $loyalty_point_transaction->transaction_id = \Str::uuid();
        $loyalty_point_transaction->reference = $reference;
        $loyalty_point_transaction->transaction_type = $transaction_type;

        if ($transaction_type == 'order_place') {
            $credit = (int)($amount * $settings['loyalty_point_item_purchase_point'] / 100);
        } else if ($transaction_type == 'point_to_wallet') {
            $debit = $amount;
        } else if ($transaction_type == 'refund_order') {
            $debit = $amount;
        }

        $current_balance = $user->loyalty_point + $credit - $debit;
        $loyalty_point_transaction->balance = $current_balance;
        $loyalty_point_transaction->credit = $credit;
        $loyalty_point_transaction->debit = $debit;
        $loyalty_point_transaction->created_at = now();
        $loyalty_point_transaction->updated_at = now();
        $user->loyalty_point = $current_balance;

        try {
            DB::beginTransaction();
            $user->save();
            $loyalty_point_transaction->save();
            DB::commit();
            return true;
        } catch (\Exception $ex) {
            info($ex);
            DB::rollback();
        }
        return false;
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
