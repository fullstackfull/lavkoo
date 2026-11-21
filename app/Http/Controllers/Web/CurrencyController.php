<?php

namespace App\Http\Controllers\Web;

use App\Contracts\Repositories\CurrencyRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    public function __construct(
        private readonly CurrencyRepositoryInterface $currencyRepo,
        private readonly StorefrontCurrency $storefrontCurrency,
    )
    {
    }

    public function changeCurrency(Request $request): JsonResponse
    {
        // A storefront that trades in its own currency (GCC-05) prices its catalogue in that currency and converts nothing, so there is no second
        // currency to switch to: a pick kept in the session would put another storefront's sign on this one's amounts. Elsewhere the switch stands.
        if ($this->storefrontCurrency->currencyCodeInForce() !== null) {
            return response()->json(['message' => translate('this_storefront_trades_in_one_currency')], 403);
        }

        session()->put('currency_code', $request['currency_code']);
        $currency = $this->currencyRepo->getFirstWhere(params: ['code' => $request['currency_code']]);
        session()->put('currency_symbol', $currency['symbol']);
        session()->put('currency_exchange_rate', $currency['exchange_rate']);
        session()->forget('default');
        session()->forget('usd');
        $message = translate('currency_changed_to') . ' ' . $currency['name'];
        return response()->json(['message' => $message]);
    }
}
