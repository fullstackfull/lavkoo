<?php

namespace App\Services;

class VendorWalletService
{
    /**
     * @param int|string|float $totalEarning
     * @param int|string|float $pendingWithdraw
     * @return int[]|string[]
     */
    public function getVendorWalletData(int|string|float $totalEarning, int|string|float $pendingWithdraw): array
    {
        return [
            'total_earning' => $totalEarning,
            'pending_withdraw' => $pendingWithdraw,
        ];
    }
}
