<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class DatabaseSettingController extends BaseController
{

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', DB::getDatabaseName())
            ->pluck('TABLE_NAME')
            ->toArray();

        $filterTables = array(
            'addon_settings',
            'admin_roles',
            'admins',
            'business_settings',
            'colors',
            'currencies',
            'failed_jobs',
            'migrations',
            'oauth_access_tokens',
            'oauth_auth_codes',
            'oauth_clients',
            'oauth_personal_access_clients',
            'oauth_refresh_tokens',
            'password_resets',
            'personal_access_tokens',
            'phone_or_email_verifications',
            'social_medias',
            'soft_credentials',
            'users'
        );
        $tables = array_values(array_diff($tables, $filterTables));

        $rows = [];
        foreach ($tables as $table) {
            $count = DB::table($table)->count();
            $rows[] = $count;
        }

        return view('admin-views.business-settings.db-index', compact('tables', 'rows'));
    }

    public function delete(Request $request): RedirectResponse
    {
        if (env('APP_MODE', 'dev') == 'demo') {
            ToastMagic::error(translate('Uploading_ZIP_files_is_currently_unavailable_in_demo_mode'));
            return back();
        }

        $tables = (array)$request['tables'];

        if(count($tables) == 0) {
            ToastMagic::error(translate('No_Table_Updated'));
            return back();
        }

        try {
            DB::transaction(function () use ($tables) {
                foreach ($tables as $table) {
                    DB::table($table)->delete();
                }
            });
        } catch (Exception $exception) {
            ToastMagic::error(translate('Failed_to_update'));
            return back();
        }

        ToastMagic::success(translate('Updated_successfully'));
        return back();
    }
}
