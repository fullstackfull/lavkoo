<?php

namespace App\Http\Controllers\Admin\ThirdParty;

use App\Http\Controllers\Controller;
use App\Models\MetaAppIntegration;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MetaAppIntegrationController extends Controller
{
    public function index(Request $request): View
    {
        $appType = $request->get('app_type', 'customer');
        $platform = $request->get('platform', 'android');

        $integration = MetaAppIntegration::where('app_type', $appType)
            ->where('platform', $platform)
            ->first();

        return view('admin-views.third-party.meta-app.index', compact(
            'integration',
            'appType',
            'platform'
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_type' => 'required|in:customer,vendor,delivery',
            'platform' => 'required|in:android,ios',
            'meta_app_id' => 'nullable|string|max:255',
            'client_token' => 'nullable|string|max:255',
            'package_name' => 'nullable|string|max:255',
            'bundle_id' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'events' => 'nullable|array',
        ]);

        $availableEvents = [
            'app_open',
            'complete_registration',
            'search',
            'view_content',
            'add_to_cart',
            'initiate_checkout',
            'purchase',
        ];

        $selectedEvents = $request->input('events', []);

        $events = [];

        foreach ($availableEvents as $event) {
            $events[$event] = isset($selectedEvents[$event]);
        }

        MetaAppIntegration::updateOrCreate(
            [
                'app_type' => $validated['app_type'],
                'platform' => $validated['platform'],
            ],
            [
                'meta_app_id' => $validated['meta_app_id'] ?? null,
                'client_token' => $validated['client_token'] ?? null,

                'package_name' => $validated['platform'] === 'android'
                    ? ($validated['package_name'] ?? null)
                    : null,

                'bundle_id' => $validated['platform'] === 'ios'
                    ? ($validated['bundle_id'] ?? null)
                    : null,

                'is_active' => $request->boolean('is_active'),
                'events' => $events,
            ]
        );

        return back()->with(
            'success',
            'Meta App Tracking settings updated successfully.'
        );
    }
}