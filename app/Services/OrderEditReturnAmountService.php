<?php

namespace App\Services;

class OrderEditReturnAmountService
{
    public function getReturnAmountData(array $data, int|float|null $amount = null): array
    {
        return [
            'order_id' => $data['order_id'] ?? null,
            'order_return_amount' => $amount ?? ($data['amount'] ?? 0),
            'order_return_payment_status' => 'returned',
            'order_return_payment_method' => $data['order_return_payment_method'] ?? null,
            'order_return_transaction_ref' => null,
            'order_return_payment_note' => $data['order_return_payment_note'] ?? null,
            'updated_at' => now(),
        ];
    }
}
