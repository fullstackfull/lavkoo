<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePurchaseCodeRequest;
use App\Traits\EmailTemplateTrait;
use App\Traits\InstallationTrail;
use App\Traits\SettingsTrait;
use App\Traits\UpdateClass;
use App\Traits\ActivationClass;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class InstallController extends Controller
{
    use ActivationClass, EmailTemplateTrait, SettingsTrait, UpdateClass, InstallationTrail;

    public function step0(): View
    {
        return view('installation.step0');
    }

    public function step1(): View
    {
        $permission['curl_enabled'] = function_exists('curl_version');
        $permission['db_file_write_perm'] = is_writable(base_path('.env'));
        $permission['routes_file_write_perm'] = is_writable(base_path('app/Providers/RouteServiceProvider.php'));
        return view('installation.step1', compact('permission'));
    }

    public function step2(): View
    {
        return view('installation.step2');
    }

    public function step3(): View
    {
        return view('installation.step3');
    }

    public function step4(): View
    {
        return view('installation.step4');
    }

    public function step5(): View
    {
        if (DOMAIN_POINTED_DIRECTORY == 'public' && function_exists('shell_exec')) {
            shell_exec('ln -s ../resources/themes themes');
            Artisan::call('storage:link');
        }

        try {
            $this->setEnvironmentValue(envKey: 'APP_URL', envValue: url('/'));
        } catch (Exception $exception) {
        }

        $this->updateRobotTexFile();
        Artisan::call('file:permission');
        Artisan::call('config:cache');
        Artisan::call('config:clear');
        return view('installation.step5');
    }

    public function updatePurchaseCode(UpdatePurchaseCodeRequest $request): RedirectResponse
    {
        $this->setEnvironmentValue(envKey: 'SOFTWARE_ID', envValue: 'MzE0NDg1OTc=');

        $adminEmail = base64_encode(preg_replace('/\s+/', '', $request['email']));
        $username = preg_replace('/\s+/', '', $request['username']);
        $purchaseKey = preg_replace('/\s+/', '', $request['purchase_key']);

        $this->setEnvironmentValue(envKey: 'BUYER_USERNAME', envValue: $username);
        $this->setEnvironmentValue(envKey: 'PURCHASE_CODE', envValue: $purchaseKey);

        session()->put('admin_name', $request['name']);
        session()->put('admin_email', $adminEmail);
        session()->put('username', $username);
        session()->put('purchase_key', $purchaseKey);

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
            return redirect(base64_decode('c3RlcDM=') . '?token=' . bcrypt('step_3'));
        }

        if (!empty($response['errors'])) {
            foreach ($response['errors'] as $error) {
                $message = is_array($error) ? ($error[0] ?? 'Unknown error') : $error;
                ToastMagic::error($message);
            }
        } else {
            ToastMagic::error('Verification Failed Try Again');
        }

        return back();
    }

    public function updateSystemSettings(Request $request): View
    {
        $this->addSystemPrimaryData(request: $request);

        try {
            if (!Schema::hasTable('addon_settings')) {
                DB::unprepared(File::get(base_path('database/migrations/addon_settings.sql')));
            }

            if (!Schema::hasTable('payment_requests')) {
                DB::unprepared(File::get(base_path('database/migrations/payment_requests.sql')));
            }
        } catch (\Exception $exception) {
        }

        $previousRouteServiceProvider = base_path('app/Providers/RouteServiceProvider.php');
        $newRouteServiceProvider = base_path('app/Providers/RouteServiceProvider.txt');
        copy($newRouteServiceProvider, $previousRouteServiceProvider);
        return view('installation.step6');
    }

    function checkDatabaseConnection($db_host = "", $db_name = "", $db_user = "", $db_pass = ""): bool
    {
        try {
            return (bool)(@mysqli_connect($db_host, $db_user, $db_pass, $db_name));
        } catch (Exception $exception) {
            return false;
        }
    }

    public function databaseInstallation(Request $request): RedirectResponse
    {
        if (self::checkDatabaseConnection($request['DB_HOST'], $request['DB_DATABASE'], $request['DB_USERNAME'], $request['DB_PASSWORD'])) {
            $this->updateEnvironmentFile(request: $request);
            $this->cleanupEnvFileContext();
            $path = base_path('.env');
            if (file_exists($path)) {
                return redirect('step4');
            } else {
                session()->flash('error', 'Database error!');
                return redirect('step3');
            }
        } else {
            session()->flash('error', 'Database error!');
            return redirect('step3');
        }
    }

    public function importSQL(): RedirectResponse
    {
        try {
            $sql_path = base_path('installation/backup/database.sql');
            DB::unprepared(file_get_contents($sql_path));
            return redirect('step5');
        } catch (\Exception $exception) {
            session()->flash('error', 'Your database is not clean, do you want to clean database then import?');
            return back();
        }
    }

    public function forceImportSQL(): RedirectResponse
    {
        try {
            Artisan::call('db:wipe');
            $sql_path = base_path('installation/backup/database.sql');
            DB::unprepared(file_get_contents($sql_path));
            return redirect('step5');
        } catch (\Exception $exception) {
            session()->flash('error', 'Check your database permission!');
            return back();
        }
    }
}
