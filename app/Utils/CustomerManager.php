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
