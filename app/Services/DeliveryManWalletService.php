<?php

namespace App\Services;

class DeliveryManWalletService
{
    public function getDeliveryManData(string|int $id, float $deliverymanCharge, float $cashInHand): array
    {
        return [
            'delivery_man_id' => $id,
            'current_balance' => $deliverymanCharge,
            'cash_in_hand' => $cashInHand,
            'pending_withdraw' => 0,
            'total_withdraw' => 0,
        ];
    }
}
