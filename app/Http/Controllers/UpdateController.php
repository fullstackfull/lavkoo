<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePurchaseCodeRequest;
use App\Utils\Helpers;
use App\Traits\ActivationClass;
use App\Traits\UpdateClass;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Artisan;

class UpdateController extends Controller
{
    use ActivationClass;
    use UpdateClass;

    public function index(): View
    {
        return view('update.update-software');
    }

    public function updateSoftware(UpdatePurchaseCodeRequest $request): Redirector|RedirectResponse
    {
        $adminEmail = base64_encode(preg_replace('/\s+/', '', $request['email']));
        $username = preg_replace('/\s+/', '', $request['username']);
        $purchaseKey = preg_replace('/\s+/', '', $request['purchase_key']);

        Helpers::setEnvironmentValue('ADMIN_NAME', $request['name']);
        Helpers::setEnvironmentValue('ADMIN_IDENTIFIER', $adminEmail);
        Helpers::setEnvironmentValue('SOFTWARE_ID', 'MzE0NDg1OTc=');
        Helpers::setEnvironmentValue('BUYER_USERNAME', $username);
        Helpers::setEnvironmentValue('PURCHASE_CODE', $purchaseKey);
        Helpers::setEnvironmentValue('SOFTWARE_VERSION', SOFTWARE_VERSION);
        Helpers::setEnvironmentValue('APP_MODE', 'live');
        Helpers::setEnvironmentValue('APP_NAME', '6valley' . time());
        Helpers::setEnvironmentValue('SESSION_LIFETIME', '60');

        $response = $this->getRequestConfig(
            username: $username,
            purchaseKey: $purchaseKey,
            softwareId: SOFTWARE_ID,
            softwareType: base64_decode('cHJvZHVjdA=='),
            name: $request['name'],
            identifier: $request['email'],
        );
        $this->updateActivationConfig(app: 'admin_panel', response: $response);
        $status = $response['active'] ?? 0;

        if ((int)$status) {
            Artisan::call('migrate', ['--force' => true]);
            $previousRouteServiceProvider = base_path('app/Providers/RouteServiceProvider.php');
            $newRouteServiceProvider = base_path('app/Providers/RouteServiceProvider.txt');
            copy($newRouteServiceProvider, $previousRouteServiceProvider);

            if (DOMAIN_POINTED_DIRECTORY == 'public') {
                shell_exec('ln -s ../resources/themes themes');
                Artisan::call('storage:link');
            }

            Artisan::call('optimize:clear');
            $this->getProcessAllVersionsUpdates();
            $this->cleanupEnvFileContext();
            return redirect(env('APP_URL'));
        }

        if (!empty($response['errors'])) {
            foreach ($response['errors'] as $error) {
                $message = is_array($error) ? ($error[0] ?? 'Unknown error') : $error;
                ToastMagic::error($message);
            }
        } else {
            ToastMagic::error(translate('Verification Failed Try Again'));
        }

        return back();
    }
}
