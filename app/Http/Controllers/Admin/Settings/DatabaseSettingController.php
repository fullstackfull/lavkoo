<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


class DatabaseSettingController extends BaseController
{
    /**
     * The tables this screen never offers and never empties, whoever asks. Beside the settings, roles and session tables the store cannot
     * start without, the two money ledgers are here: a wallet or a loyalty movement is a financial record, so emptying the table would
     * destroy the history the books are made of, and no screen may do that by a tick in a list.
     */
    private const TABLES_NEVER_EMPTIED = [
        'addon_settings',
        'admin_roles',
        'admins',
        'business_settings',
        'colors',
        'currencies',
        'failed_jobs',
        'loyalty_point_transactions',
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
        'users',
        'wallet_transactions',
    ];

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        $tables = $this->emptiableTables();

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

        // Both guards. The intersect is the strong one: a name survives only if the database listed it AND
        // TABLES_NEVER_EMPTIED allows it, so nothing a request invents can reach the TRUNCATE. The is_string filter
        // is kept in front of it because array_intersect compares as strings and an array element would raise.
        $tables = array_values(array_intersect(
            array_filter((array)$request['tables'], static fn ($table): bool => is_string($table)),
            $this->emptiableTables()
        ));

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

    /**
     * The tables this screen offers, and the only ones it will act on: the current schema's own tables, less the ones it never empties.
     *
     * Both the screen and the action read this one list, so what is offered and what is accepted cannot drift apart. It is an ALLOWLIST on
     * purpose: subtracting the protected names from whatever the request asked for compares strings, and a protected table has more than
     * one spelling -- `<schema>.wallet_transactions` and `wallet_transactions as w` both reach the ledger while matching no protected name.
     * Keeping only the names that are in the offered list leaves a tampered form nothing to reach.
     *
     * @return list<string>
     */
    private function emptiableTables(): array
    {
        $tables = Schema::getTableListing(schema: Schema::getCurrentSchemaName(), schemaQualified: false);

        return array_values(array_diff($tables, self::TABLES_NEVER_EMPTIED));
    }
}
