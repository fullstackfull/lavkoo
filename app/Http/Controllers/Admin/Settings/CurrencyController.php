<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Services\AuditLogger;
use App\Contracts\Repositories\CurrencyRepositoryInterface;
use App\Contracts\Repositories\SettingRepositoryInterface;
use App\Enums\GlobalConstant;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\CurrencyAddRequest;
use App\Services\SettingService;
use App\Traits\CalculatorTrait;
use App\Traits\PaymentGatewayTrait;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrencyController extends BaseController
{

    use PaymentGatewayTrait, CalculatorTrait;

    public function __construct(
        private readonly CurrencyRepositoryInterface        $currencyRepo,
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
        private readonly SettingRepositoryInterface         $settingRepo,
        private readonly SettingService                     $settingService,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $paymentGatewayPublishedStatus = config('get_payment_publish_status') ?? 0;
        $paymentGatewayUrl = $this->settingService->getVacationData(type: 'payment_setup');

        if ($paymentGatewayPublishedStatus) {
            $activePaymentGateway = $this->settingRepo->getListWhere(filters: ['settings_type' => 'payment_config', 'is_active' => 1], dataLimit: 'all');
        } else {
            $activePaymentGateway = $this->settingRepo->getListWhereIn(filters: ['settings_type' => 'payment_config', 'is_active' => 1], whereInFilters: ['key_name' => GlobalConstant::DEFAULT_PAYMENT_GATEWAYS], dataLimit: 'all');
        }
        $currencies = $this->currencyRepo->getListWhere(
            searchValue: requestString('searchValue') ?: null,
            dataLimit: 10,
        );

        $activeCurrencies = $this->currencyRepo->getListWhere(
            filters: ['status' => 1],
            dataLimit: 'all',
        );

        $currencies->map(function ($currency) use ($currencies, $activePaymentGateway) {
            $checkedData = self::checkPaymentGatewaySupportedCurrencies($currency->code, $currencies, $activePaymentGateway);
            $currency['is_enabled_to_use'] = $checkedData['is_enabled_to_use'];
            $currency['total_supported_gateway'] = $checkedData['total_supported_gateway'];
            $currency['must_required_for_gateway'] = $checkedData['must_required_for_gateway'];
            $currency['supported_gateway'] = $checkedData['supported_gateway'];
        });

        $digitalPaymentStatus = getWebConfig(name: 'digital_payment')['status'] ?? 0;
        $currencyModel = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'currency_model']);
        $default = $this->businessSettingRepo->getFirstWhere(params: ['type' => 'system_default_currency']);
        return view('admin-views.system-setup.currency.view', compact('activeCurrencies', 'currencies', 'currencyModel', 'default', 'paymentGatewayPublishedStatus', 'paymentGatewayUrl', 'digitalPaymentStatus'));
    }

    function checkPaymentGatewaySupportedCurrencies($currencyCode, $currencyCodes, $paymentGateways): array
    {
        $isEnabledToUse = 0;
        $totalSupportedGateway = 0;
        $supportForGateway = [];
        $mustRequiredForGateway = 1;
        foreach ($paymentGateways as $paymentGateway) {
            $getPaymentGatewaySupportedCurrencies = $this->getPaymentGatewaySupportedCurrencies(key: $paymentGateway->key_name);
            if ($getPaymentGatewaySupportedCurrencies && array_key_exists($currencyCode, $getPaymentGatewaySupportedCurrencies)) {
                $isEnabledToUse = 1;
                $totalSupportedGateway += 1;
                $supportForGateway[] = $paymentGateway->key_name;
                foreach ($currencyCodes as $singleCode) {
                    if ($singleCode->status == 1 && $singleCode->code != $currencyCode && array_key_exists($singleCode->code, $getPaymentGatewaySupportedCurrencies)) {
                        $mustRequiredForGateway = 0;
                    }
                }
            }
        }
        if (count($supportForGateway) == 0) {
            $mustRequiredForGateway = 0;
        }
        return [
            'is_enabled_to_use' => $isEnabledToUse,
            'total_supported_gateway' => $totalSupportedGateway,
            'must_required_for_gateway' => $mustRequiredForGateway,
            'supported_gateway' => $supportForGateway,
        ];
    }

    public function add(CurrencyAddRequest $request): RedirectResponse
    {
        $currencyExist = $this->currencyRepo->getFirstWhere(params: ['code' => $request['code']]);
        if ($currencyExist) {
            ToastMagic::warning(translate('Currency_already_exist'));
            return redirect()->back();
        }
        $this->currencyRepo->add([
            'name' => $request['name'],
            'symbol' => $request['symbol'],
            'code' => $request['code'],
            'exchange_rate' => $request->has('exchange_rate') ? $request['exchange_rate'] : 1,
        ]);

        updateSetupGuideCacheKey(key: 'currency_setup', panel: 'admin');
        ToastMagic::success(translate('New_Currency_inserted_successfully'));
        return redirect()->back();
    }

    public function update(Request $request): RedirectResponse
    {
        if ($request['exchange_rate'] <= 0) {
            ToastMagic::error(translate('exchange_rate_cannot_be_zero'));
            return back();
        }
        $currency = $this->currencyRepo->getFirstWhere(params: ['id' => $request['id']]);
        if ($currency['code'] == 'BDT' && $request['code'] != 'BDT') {
            $config = $this->settingRepo->getFirstWhere(params: ['key_name' => 'ssl_commerz']);
            if ($config['is_active']) {
                ToastMagic::warning(translate('Before_update_BDT') . ", " . translate('disable_the_SSLCOMMERZ_payment_gateway.'));
                return back();
            }
        } elseif ($currency['code'] == 'INR' && $request['code'] != 'INR') {
            $config = $this->settingRepo->getFirstWhere(params: ['key_name' => 'razor_pay']);
            if ($config['is_active']) {
                ToastMagic::warning(translate('Before_update_INR') . ", " . translate('disable_the_RAZOR_PAY_payment_gateway.'));
                return back();
            }
        } elseif ($currency['code'] == 'MYR' && $request['code'] != 'MYR') {
            $config = $this->settingRepo->getFirstWhere(params: ['key_name' => 'senang_pay']);
            if ($config['is_active']) {
                ToastMagic::warning(translate('Before_update_MYR') . ", " . translate('disable_the_SENANG_PAY_payment_gateway.'));
                return back();
            }
        } elseif ($currency['code'] == 'ZAR' && $request['code'] != 'ZAR') {
            $config = $this->settingRepo->getFirstWhere(params: ['key_name' => 'paystack']);
            if ($config['is_active']) {
                ToastMagic::warning(translate('Before_update_ZAR') . ", " . translate('disable_the_PAYSTACK_payment_gateway.'));
                return back();
            }
        }

        $dataArray = [
            'name' => $request['name'],
            'symbol' => $request['symbol'],
            'code' => $request['code'],
            'exchange_rate' => $request->has('exchange_rate') ? $request['exchange_rate'] : 1,
        ];
        $this->currencyRepo->update(id: $currency['id'], data: $dataArray);

        updateSetupGuideCacheKey(key: 'currency_setup', panel: 'admin');
        ToastMagic::success(translate('currency_updated_successfully'));
        return redirect()->back();
    }

    public function delete(Request $request): RedirectResponse
    {
        if (!in_array($request['id'], [1, 2, 3, 4, 5, 6, 7])) {
            $this->currencyRepo->delete(params: ['id' => $request['id']]);
            ToastMagic::success(translate('currency_delete_successfully'));
        } else {
            ToastMagic::warning(translate('default_currency_can_not_be_deleted'));
        }
        return redirect()->back();
    }

    public function status(Request $request): JsonResponse
    {
        if ($request['status'] != 1) {
            $count = $this->currencyRepo->getListWhere(filters: ['status' => 1], dataLimit: 'all')->count();
            if ($count == 1) {
                return response()->json([
                    'status' => 0,
                    'message' => translate('You_can_not_disable_all_currencies.')
                ]);
            }

            $paymentGatewayPublishedStatus = config('get_payment_publish_status') ?? 0;
            if ($paymentGatewayPublishedStatus) {
                $activePaymentGateway = $this->settingRepo->getListWhere(filters: ['settings_type' => 'payment_config', 'is_active' => 1], dataLimit: 'all');
            } else {
                $activePaymentGateway = $this->settingRepo->getListWhereIn(filters: ['settings_type' => 'payment_config', 'is_active' => 1], whereInFilters: ['key_name' => GlobalConstant::DEFAULT_PAYMENT_GATEWAYS], dataLimit: 'all');
            }
            $currencies = $this->currencyRepo->getListWhere(searchValue: requestString('searchValue') ?: null, dataLimit: getWebConfig(name: 'pagination_limit'));
            $currency = $this->currencyRepo->getFirstWhere(params: ['id' => $request['id']]);
            $checkedData = self::checkPaymentGatewaySupportedCurrencies($currency->code, $currencies, $activePaymentGateway);
            if ($checkedData['must_required_for_gateway']) {
                return response()->json([
                    'status' => 0,
                    'message' => translate('If_you_disable_this_currency_please_check_in_payment_gateway_settings_that_gateway_only_dependent_on_support_this_currency')
                ]);
            }
        }
        $this->currencyRepo->update(id: $request['id'], data: ['status' => $request->get('status', 0)]);
        updateSetupGuideCacheKey(key: 'currency_setup', panel: 'admin');
        return response()->json([
            'status' => 1,
            'message' => translate('Currency_status_successfully_changed.')
        ]);
    }

    public function updateSystemCurrency(Request $request): RedirectResponse
    {
        if (in_array(0, $request['exchange_rate'])) {
            ToastMagic::error(translate('exchange_rate_cannot_be_zero'));
            return back();
        }
        $this->businessSettingRepo->updateWhere(params: ['type' => 'system_default_currency'], data: ['value' => $request['default_currency_id']]);
        $currencyModel = getWebConfig(name: 'currency_model');
        if ($currencyModel == 'multi_currency') {
            foreach ($request['exchange_rate'] as $exchangeRateCode => $exchangeRateValue) {
                $this->currencyRepo->updateWhere(params: ['code' => $exchangeRateCode], data: ['exchange_rate' => $exchangeRateValue]);
            }
        }
        clearWebConfigCacheKeys();
        cacheRemoveByType(type: 'business_settings');
        session()->forget('usd');
        session()->forget('default');
        session()->forget('system_default_currency_info');
        session()->forget('currency_code');
        session()->forget('currency_symbol');
        session()->forget('currency_exchange_rate');
        ToastMagic::success(translate('System_Default_currency_updated_successfully'));
        return redirect()->route('admin.system-setup.currency.view');
    }

    /**
     * Single-currency or multi-currency, with exchange rates.
     *
     * The most consequential setting on this screen and the only one it could not change: 35 branch
     * sites depend on `currency_model`, including every conversion in app/Utils/currency.php, and
     * the only writer was the installer. So a marketplace that started single-currency was one
     * forever, and the audited bulk exchange-rate editor beside it could be maintaining rates the
     * platform would never apply.
     */
    public function updateCurrencyModel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'currency_model' => 'required|in:multi_currency,single_currency',
        ]);

        $before = getWebConfig(name: 'currency_model');

        $this->businessSettingRepo->updateWhere(
            params: ['type' => 'currency_model'],
            data: ['value' => $validated['currency_model']],
        );

        clearWebConfigCacheKeys();
        cacheRemoveByType(type: 'business_settings');
        session()->forget(['usd', 'default', 'system_default_currency_info', 'currency_code', 'currency_symbol', 'currency_exchange_rate']);

        app(AuditLogger::class)->record(
            action: 'settings.currency_model_updated',
            before: ['currency_model' => $before],
            after: ['currency_model' => $validated['currency_model']],
        );

        ToastMagic::success(translate('the_currency_model_was_updated'));

        return redirect()->route('admin.system-setup.currency.view');
    }

    public function checkSystemCurrency(Request $request): JsonResponse
    {
        $currencyExchangeRate = [];
        $defaultCurrency = $this->currencyRepo->getFirstWhere(params: ['code' => $request['code']]);

        $currencyModel = getWebConfig(name: 'currency_model');
        if ($currencyModel == 'multi_currency') {
            $allCurrencies = $this->currencyRepo->getListWhere(dataLimit: 'all');
            foreach ($allCurrencies as $currencyItem) {
                $exchangeRateWithPrecision = $this->getDivideWithDynamicPrecision(numerator: $currencyItem['exchange_rate'], denominator: $defaultCurrency['exchange_rate'], maxPrecision: 20);
                $currencyItem->exchange_rate = $exchangeRateWithPrecision;
                $currencyExchangeRate[] = $currencyItem;
            }
            return response()->json([
                'mode' => 'multi_currency',
                'view' => view('admin-views.system-setup.currency._default-currency', compact('currencyExchangeRate', 'defaultCurrency'))->render()
            ]);
        } else {
            $this->currencyRepo->updateWhere(params: ['code' => $defaultCurrency['code']], data: ['exchange_rate' => 1]);
            $this->businessSettingRepo->updateWhere(params: ['type' => 'system_default_currency'], data: ['value' => $defaultCurrency['id']]);
            return response()->json([
                'mode' => 'single_currency',
                'message' => translate('System_default_currency_changed_successfully'),
            ]);
        }
    }
}
