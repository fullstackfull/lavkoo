<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\AddonPurchaseCodeRequest;
use App\Services\AddonService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Artisan;

class AddonActivationController extends BaseController
{

    public function __construct(
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
        private readonly AddonService                       $addonService,
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
        $addonData['deliveryman_app'] = getWebConfig(name: 'addon_activation_delivery_man_app') ?? [
            'activation_status' => 0,
            'username' => '',
            'purchase_key' => '',
        ];
        return view('admin-views.system-setup.addons.addon-activation', compact('addonData'));
    }

    public function activation(AddonPurchaseCodeRequest $request): Redirector|RedirectResponse|Application
    {
        $data = $this->addonService->addonActivationProcess(request: $request);
        if ($data['status']) {
            $this->businessSettingRepo->updateOrInsert(type: 'addon_activation_delivery_man_app', value: json_encode([
                'activation_status' => $request['status'] ?? 0,
                'name' => $request['name'] ?? '',
                'email' => $request['email'] ?? '',
                'username' => $request['username'],
                'purchase_key' => $request['purchase_key'],
            ]));
            ToastMagic::success(translate('activated_successfully'));
        } else {
            ToastMagic::error($data['message']);
        }
        return back();
    }

    public function activateFromList(AddonPurchaseCodeRequest $request): Redirector|RedirectResponse|Application|JsonResponse
    {
        $infoPath = base_path($request['path'] . '/Addon/info.php');
        if (!file_exists($infoPath)) {
            $message = translate('invalid_addon');
            if ($request->ajax()) {
                return response()->json(['status' => 0, 'message' => $message]);
            }
            ToastMagic::error($message);
            return back();
        }

        $fullData = include($infoPath);
        $moduleKey = strtolower($fullData['module_name'] ?? $request['addon_name']);

        $data = $this->addonService->addonActivationProcess(request: $request);

        if ($data['status']) {
            $fullData['is_published'] = 1;
            $fullData['username'] = $request['username'];
            $fullData['purchase_code'] = $request['purchase_key'];
            file_put_contents($infoPath, "<?php return " . var_export($fullData, true) . ";");

            $this->businessSettingRepo->updateOrInsert(
                type: 'addon_activation_' . $moduleKey,
                value: json_encode([
                    'activation_status' => 1,
                    'name' => $request['name'] ?? '',
                    'email' => $request['email'] ?? '',
                    'username' => $request['username'],
                    'purchase_key' => $request['purchase_key'],
                ])
            );

            Artisan::call('optimize:clear');
            Artisan::call('view:clear');

            $message = translate('activated_successfully');
            if ($request->ajax()) {
                return response()->json(['status' => 1, 'message' => $message]);
            }
            ToastMagic::success($message);
            return back();
        }

        $message = $data['message'] ?? translate('Activation_failed');
        if ($request->ajax()) {
            return response()->json(['status' => 0, 'message' => $message]);
        }
        ToastMagic::error($message);
        return back();
    }
}
