<?php

namespace App\Services;

use App\Models\Currency;
use App\Utils\Convert;

class WithdrawRequestService
{
    /**
     * The one conversion every withdraw path uses for the amount a seller asks for.
     *
     * The guard that decides whether the balance covers the request, the amount stored on the
     * request row and the wallet debit must all be this single value. They used to be computed
     * separately — the guard through Convert::usd (the default currency as the settings cache has
     * it) and the debit through BackEndHelper::currency_to_usd (the default currency read straight
     * from the row) — so while those two disagreed a request could pass the guard and then debit
     * more than the seller had, taking total_earning below zero and leaving the request row
     * recording a smaller amount than was actually taken.
     *
     * Returns null when the amount cannot be converted, which the callers refuse: converting at a
     * 1:1 USD rate because the store has no usable USD row would move real money at an invented
     * rate.
     */
    public function getWithdrawAmountInWalletCurrency(float|int|string|null $requestedAmount): float|int|null
    {
        return $this->isCurrencyConversionAvailable() ? Convert::usd($requestedAmount) : null;
    }

    private function isCurrencyConversionAvailable(): bool
    {
        if (getWebConfig(name: 'currency_model') !== MULTI_CURRENCY) {
            return true;
        }

        $defaultCurrencyRate = Currency::where('id', getWebConfig(name: 'system_default_currency'))->value('exchange_rate');
        $usdRate = Currency::where('code', 'USD')->value('exchange_rate');

        return (float) $defaultCurrencyRate > 0 && (float) $usdRate > 0;
    }

    /**
     * @return array[seller_id: int|string, amount: float|int, transaction_note: null, withdrawal_method_id: mixed, withdrawal_method_fields: false|string, approved: int, created_at: \Illuminate\Support\Carbon, updated_at: \Illuminate\Support\Carbon]
     */
    public function getWithdrawRequestData(object $withdrawMethod, object $request, string $addedBy, int|string $vendorId, float|int $amountInWalletCurrency): array
    {
        return [
            'seller_id' => $addedBy === 'vendor' ? $vendorId : '',
            'amount' => $amountInWalletCurrency,
            'transaction_note' => null,
            'withdrawal_method_id' => $request['withdraw_method'],
            'withdrawal_method_fields' => json_encode($this->getWithdrawMethodFields(request: $request, withdrawMethod: $withdrawMethod)),
            'approved' => 0,
            'request_method_type' => $request['request_method_type'] ?? 'system',
            'created_at' => now(),
            'updated_at' => now()
        ];
    }

    /**
     * @param object $request
     * @param object $withdrawMethod
     * @return array
     */
    public function getWithdrawMethodFields(object $request, object $withdrawMethod): array
    {
        $inputFields = array_column($withdrawMethod['method_fields'], 'input_name');
        $method['method_name'] = $withdrawMethod['method_name'];
        $methodInfoValues = $request['method_info'] ?? [];

        foreach ($inputFields as $field) {
            if (key_exists($field, $methodInfoValues)) {
                $method[$field] = $methodInfoValues[$field];
            }
        }
        return $method;
    }

}
