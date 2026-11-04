<?php

namespace App\Services;

use App\Models\ReferralCustomer;


class ReferByEarnCustomerService
{


    public function getEarnByReferralData(array $data): array
    {

        return [
            'ref_earning_discount_status' => $data['ref_earning_discount_status'] ?? 0,
            'discount_amount' => $data['discount_amount'] ?? 0,
            'discount_type' => $data['discount_type'] ?? '',
            'validity' => $data['validity'] ?? 0,
            'validity_type' => $data['validity_type'] ?? '',
        ];
    }


    public function addReferralCustomerData($referralData, $referralEarningRate, $referUser, $userId): mixed
    {
        $earningAmount = json_validate($referralEarningRate->value) ? json_decode($referralEarningRate->value, true) : $referralEarningRate->value;
        $data = [
            'user_id' => $userId,
            'refer_by' => $referUser['id'],
            'ref_by_earning_amount' => $earningAmount,
            'customer_discount_amount' => $referralData['discount_amount'] ?? 0,
            'customer_discount_amount_type' => $referralData['discount_type'] ?? 'percentage',
            'customer_discount_validity' => $referralData['validity'] ?? 1,
            'customer_discount_validity_type' => $referralData['validity_type'] ?? 'day',
            'registered_notify' => 0,
            'ordered_notify' => 0,
            'delivered_notify' => 0,
            'is_used_by_refer' => 0,
            'is_checked' => 0,
        ];
        return ReferralCustomer::create($data);
    }
}
