<?php

namespace App\Http\Controllers\Admin\ThirdParty;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Contracts\Repositories\CurrencyRepositoryInterface;
use App\Contracts\Repositories\OfflinePaymentMethodRepositoryInterface;
use App\Contracts\Repositories\SettingRepositoryInterface;
use App\Enums\GlobalConstant;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Admin\PaymentMethodUpdateRequest;
use App\Services\SettingService;
use App\Traits\PaymentGatewayTrait;
use App\Traits\Processor;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\MtnCashTerminal;


class PaymentMethodController extends BaseController
{
    use Processor;
    use PaymentGatewayTrait;

    public function __construct(
        private readonly SettingRepositoryInterface              $settingRepo,
        private readonly BusinessSettingRepositoryInterface      $businessSettingRepo,
        private readonly SettingService                          $settingService,
        private readonly CurrencyRepositoryInterface             $currencyRepo,
        private readonly OfflinePaymentMethodRepositoryInterface $offlinePaymentMethodRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View|Collection|LengthAwarePaginator|callable|RedirectResponse|null
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View|Collection|LengthAwarePaginator|null|callable|RedirectResponse
    {
        $paymentGatewayPublishedStatus = config('get_payment_publish_status') ?? 0;
        $paymentGatewaysList = $this->settingRepo->getListWhereIn(
            whereInFilters: ['settings_type' => ['payment_config'], 'key_name' => GlobalConstant::DEFAULT_PAYMENT_GATEWAYS],
            dataLimit: 'all',
        );

        $currencies = $this->currencyRepo->getListWhere(
            dataLimit: 'all',
        );

  $paymentGatewaysList->map(function ($gateway) use ($currencies, $paymentGatewaysList) {

    $checkedData = self::checkPaymentGatewaySupportedCurrencies(
        $gateway,
        $currencies,
        $paymentGatewaysList
    );

    $gateway['is_enabled_to_use'] = $checkedData['is_enabled_to_use'];
    $gateway['total_supported_currencies'] = $checkedData['total_supported_currencies'];
    $gateway['must_required_for_currency'] = $checkedData['must_required_for_currency'];
    $gateway['supported_currency'] = $checkedData['supported_currency'];

    // MTN Cash terminal status
    if ($gateway->key_name === 'mtn_cash') {

        $liveValues = is_array($gateway->live_values)
            ? $gateway->live_values
            : json_decode($gateway->live_values, true);

        $terminalId = $liveValues['terminal_id'] ?? null;

        $gateway['mtn_terminal'] = $terminalId
            ? MtnCashTerminal::where('terminal_id', $terminalId)->first()
            : null;
    }
});

        $paymentGatewaysList = $paymentGatewaysList->sortBy(function ($item) {
            return count($item['live_values']);
        })->values()->all();

        $paymentUrl = $this->settingService->getVacationData(type: 'payment_setup');
        return view('admin-views.third-party.payment-method.index', [
            'paymentGatewaysList' => $paymentGatewaysList,
            'paymentGatewayPublishedStatus' => $paymentGatewayPublishedStatus,
            'paymentUrl' => $paymentUrl,
            'cashOnDelivery' => getWebConfig(name: 'cash_on_delivery'),
            'digitalPayment' => getWebConfig(name: 'digital_payment'),
            'offlinePayment' => getWebConfig(name: 'offline_payment'),
        ]);
    }

    function checkPaymentGatewaySupportedCurrencies($gateway, $currencyCodes, $paymentGateways): array
    {
        $getPaymentGatewaySupportedCurrencies = $this->getPaymentGatewaySupportedCurrencies(key: $gateway->key_name);
        $isEnabledToUse = 0;
        $supportForCurrency = [];
        $totalSupportedCurrencies = 0;
        $mustRequiredForCurrency = 1;
        foreach ($currencyCodes as $singleCode) {
            if ($singleCode->status == 1 && array_key_exists($singleCode->code, $getPaymentGatewaySupportedCurrencies)) {
                $isEnabledToUse = 1;
                $totalSupportedCurrencies += 1;
                $supportForCurrency[] = $singleCode->code;
            }
        }
        if (count($supportForCurrency) != 1) {
            $mustRequiredForCurrency = 0;
        }

        return [
            'is_enabled_to_use' => $isEnabledToUse,
            'total_supported_currencies' => $totalSupportedCurrencies,
            'must_required_for_currency' => $mustRequiredForCurrency,
            'supported_currency' => $supportForCurrency,
        ];
    }

    private function checkMinimumOneDigitalPayment(): bool
    {
        $paymentGatewayStatus = config('get_payment_publish_status') ?? 0;

        $gatewayKeys = [];
        foreach (GATEWAYS_PAYMENT_METHODS as $method) {
            $gatewayKeys[] = $method["key"];
        }
        $paymentGatewaysList = $this->settingRepo->getListWhereIn(
            filters: ['is_active' => 1],
            whereInFilters: [
                'settings_type' => ['payment_config'],
                'key_name' => $paymentGatewayStatus ? $gatewayKeys : GlobalConstant::DEFAULT_PAYMENT_GATEWAYS
            ],
            dataLimit: 'all',
        );
        return !(count($paymentGatewaysList) == 0);
    }

    public function UpdatePaymentConfig(PaymentMethodUpdateRequest $request): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            Toastr::info(translate('This_option_is_disabled_for_demo'));
            return back();
        }
        collect(['status'])->each(fn($item, $key) => $request[$item] = $request->has($item) ? (int)$request[$item] : 0);
        $settings = $this->settingRepo->getFirstWhere(params: ['key_name' => $request['gateway'], 'settings_type' => 'payment_config']);
        $additionalDataImage = $settings['additional_data'] != null ? json_decode($settings['additional_data']) : null;
        if ($request->has('gateway_image')) {
            $gatewayImage = $this->file_uploader('payment_modules/gateway_image/', 'png', $request['gateway_image'], $additionalDataImage != null ? $additionalDataImage->gateway_image : '');
        } else {
            $gatewayImage = $additionalDataImage != null ? $additionalDataImage->gateway_image : '';
        }
        $request->validate(['gateway_title' => 'required']);

        $status = $request['status'] ?? 0;
        if ($request['status'] == 1) {
            $gateway = $this->settingRepo->getFirstWhere(params: ['key_name' => $request['gateway'], 'settings_type' => 'payment_config']);
            if ($gateway) {
                $paymentGatewayPublishedStatus = config('get_payment_publish_status') ?? 0;
                $paymentGatewaysList = $this->settingRepo->getListWhereIn(
                    whereInFilters: ['settings_type' => ['payment_config'], 'key_name' => GlobalConstant::DEFAULT_PAYMENT_GATEWAYS],
                    dataLimit: 'all',
                );
                $currencies = $this->currencyRepo->getListWhere(
                    dataLimit: 'all',
                );
                $checkedData = self::checkPaymentGatewaySupportedCurrencies($gateway, $currencies, $paymentGatewaysList);
                if ($checkedData['is_enabled_to_use'] != 1) {
                    $status = 0;
                    ToastMagic::error(translate(GATEWAYS_STATUS_UPDATE_FAIL['message']));
                }
            }
        }

        $this->settingRepo->updateOrInsert(params: ['key_name' => $request['gateway'], 'settings_type' => 'payment_config'], data: [
            'key_name' => $request['gateway'],
            'live_values' => $request->validated(),
            'test_values' => $request->validated(),
            'settings_type' => 'payment_config',
            'mode' => $request['mode'],
            'is_active' => $status,
            'additional_data' => json_encode(['gateway_title' => $request['gateway_title'], 'gateway_image' => $gatewayImage]),
        ]);

        if ($status != 0) {
            ToastMagic::success(translate('Updated_successfully'));
        }
        updateSetupGuideCacheKey(key: 'digital_payment_setup', panel: 'admin');
        return redirect()->route('admin.third-party.payment-method.index');
    }

    public function UpdateStatus(Request $request): RedirectResponse
    {
        $payment = $this->settingRepo->getFirstWhere(params: ['key_name' => $request->get('key_name')]);
        if ($request['status'] == 1) {
            foreach ($payment['live_values'] as $key => $value) {
                if (empty($value) && $value != 0) {
                    ToastMagic::error(translate('Please_update_the_configuration_first'));
                    return redirect()->route('admin.third-party.payment-method.index');
                }
            }
        }
        $this->settingRepo->updateWhere(params: ['key_name' => $request['key_name']], data: ['is_active' => $request['status'] ?? 0]);

        updateSetupGuideCacheKey(key: 'digital_payment_setup', panel: 'admin');
        ToastMagic::success(translate('Updated_successfully'));
        return redirect()->route('admin.third-party.payment-method.index');
    }
}