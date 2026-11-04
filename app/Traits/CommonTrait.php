<?php

namespace App\Traits;

use App\Models\DeliveryCountryCode;
use App\Models\DeliverymanNotification;
use App\Models\DeliverymanWallet;
use App\Models\DeliveryZipCode;
use App\Models\OrderExpectedDeliveryHistory;
use App\Models\OrderStatusHistory;
use App\Traits\PreciseNumber;

trait CommonTrait
{
    use PreciseNumber;
    public static function add_expected_delivery_date_history($order_id, $user_id, $value, $user_type, $cause = null): void
    {
        if ($order_id && $user_id && $value && $user_type) {
            $delivery_history = new OrderExpectedDeliveryHistory();
            $delivery_history->order_id = $order_id;
            $delivery_history->user_id = $user_id;
            $delivery_history->user_type = $user_type;
            $delivery_history->expected_delivery_date = $value;
            $delivery_history->cause = $cause;
            $delivery_history->save();
        }
    }

    public static function add_order_status_history($order_id, $user_id, $status, $user_type, $cause = null): void
    {
        if ($order_id && ($user_id || $user_id=='0') && $status && $user_type) {
            $delivery_history = new OrderStatusHistory();
            $delivery_history->order_id = $order_id;
            $delivery_history->user_id = $user_id;
            $delivery_history->user_type = $user_type;
            $delivery_history->status = $status;
            $delivery_history->cause = $cause;
            $delivery_history->save();
        }
    }

    public static function add_deliveryman_push_notification($data, $delivery_man_id): void
    {
        if ($data && $delivery_man_id) {
            $notification = new DeliverymanNotification();
            $notification->order_id = $data['order_id'];
            $notification->delivery_man_id = $delivery_man_id;
            $notification->description = $data['description'];
            $notification->save();
        }
    }

   public static function delivery_man_withdrawable_balance($delivery_man_id)
{
    $wallet = DeliverymanWallet::where('delivery_man_id', $delivery_man_id)->first();

    if (!$wallet) {
        return '0';
    }

    $current = $wallet->current_balance ?? 0;
    $cash    = $wallet->cash_in_hand ?? 0;
    $pending = $wallet->pending_withdraw ?? 0;
    $deduction = PreciseNumber::add($cash, $pending);
    $withdrawalBalance = PreciseNumber::sub($current, $deduction);

    return PreciseNumber::compare($withdrawalBalance, '0') > 0 ? $withdrawalBalance : '0';
}

    public static function delivery_man_total_earn($delivery_man_id)
    {
        $totalEarn = 0;
        $wallet = DeliverymanWallet::where('delivery_man_id', $delivery_man_id)->first();
        if ($wallet) {
            $totalEarn = ($wallet->current_balance ?? 0) + ($wallet->total_withdraw ?? 0);
        }
        return $totalEarn;
    }

    public function get_delivery_country_array(): array
    {
        $data = [];
        foreach (DeliveryCountryCode::all() as $delivery_country_code) {
            foreach (COUNTRIES as $key => $country) {
                if ($country['code'] == $delivery_country_code->country_code) {
                    $data[$key]['code'] = $country['code'];
                    $data[$key]['name'] = $country['name'];
                }
            }

        }
        return $data;
    }

    public function delivery_country_exist_check($input_country): bool
    {
        $data = [];
        foreach (DeliveryCountryCode::pluck('country_code') as $code)
        {
            foreach (COUNTRIES as $country) {
                $country['code'] == $code ?  $data[] = $country['name'] : '';
            }
        }
        return in_array($input_country, $data);
    }

    public function delivery_zipcode_exist_check($input_zip): bool
    {
        return in_array($input_zip, DeliveryZipCode::pluck('zipcode')->toArray());
    }
}
