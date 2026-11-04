<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(): View
    {
        $chatwoot = IntegrationSetting::where('provider', 'chatwoot')->first();

        return view('admin-views.integrations.index', compact('chatwoot'));
    }

    public function saveChatwoot(Request $request): RedirectResponse
    {
        $request->validate([
            'base_url' => ['nullable', 'url', 'max:255'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'api_access_token' => ['nullable', 'string'],
            'inbox_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $integration = IntegrationSetting::firstOrNew([
            'provider' => 'chatwoot',
        ]);

        $integration->name = 'Chatwoot';
        $integration->enabled = $request->boolean('enabled');
        $integration->base_url = $request->filled('base_url')
            ? rtrim($request->base_url, '/')
            : null;

        $settings = $integration->settings ?? [];
        $settings['account_id'] = $request->account_id;
        $settings['inbox_id'] = $request->inbox_id;
        $integration->settings = $settings;

        /*
         * إذا المستخدم أدخل Token جديد فقط، نغيره.
         * إذا ترك الحقل فارغ، نحافظ على القديم.
         */
        if ($request->filled('api_access_token')) {
            $integration->credentials = Crypt::encryptString(
                json_encode([
                    'api_access_token' => $request->api_access_token,
                ])
            );
        }

        $integration->save();

        return redirect()
            ->route('admin.integrations.index')
            ->with('success', 'Chatwoot settings saved successfully.');
    }
}