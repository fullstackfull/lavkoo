<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Models\AbandonedCartReminder;
use App\Models\BusinessSetting;
use App\Services\Retention\AbandonedCartService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Where the store owner turns abandoned-cart reminders on (Phase 2.4).
 *
 * The feature ships off. Nothing in an upgrade should start emailing customers on its own, so the
 * switch lives here and the console command refuses to run until it is set.
 */
class AbandonedCartSettingsController extends BaseController
{
    public function __construct(private readonly AbandonedCartService $service)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        return view('admin-views.business-settings.abandoned-cart', [
            'enabled' => $this->service->isEnabled(),
            'includeGuests' => $this->service->includesGuests(),
            'idleHours' => $this->service->idleHours(),
            'maxAgeHours' => $this->service->maxAgeHours(),
            'minimumValue' => $this->service->minimumCartValue(),
            'stats' => $this->stats(),
            'pending' => $this->service->isEnabled() ? $this->service->findAbandoned()->count() : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|boolean',
            'include_guests' => 'nullable|boolean',
            'idle_hours' => 'required|integer|min:1|max:720',
            'max_age_hours' => 'required|integer|min:2|max:8760',
            'minimum_cart_value' => 'required|numeric|min:0',
        ]);

        // An inverted window matches nothing and gives no clue why, so it is refused here rather
        // than silently corrected — the owner should see that the numbers were wrong.
        if ((int) $validated['max_age_hours'] <= (int) $validated['idle_hours']) {
            ToastMagic::error(translate('the_stop_reminding_hours_must_be_greater_than_the_idle_hours'));

            return back()->withInput();
        }

        foreach ([
            AbandonedCartService::SETTING_ENABLED => (int) ($validated['status'] ?? 0),
            AbandonedCartService::SETTING_INCLUDE_GUESTS => (int) ($validated['include_guests'] ?? 0),
            AbandonedCartService::SETTING_IDLE_HOURS => (int) $validated['idle_hours'],
            AbandonedCartService::SETTING_MAX_AGE_HOURS => (int) $validated['max_age_hours'],
            AbandonedCartService::SETTING_MIN_VALUE => (float) $validated['minimum_cart_value'],
        ] as $settingType => $value) {
            BusinessSetting::updateOrCreate(['type' => $settingType], ['value' => $value]);
        }

        // getWebConfig() caches, so the page would otherwise show the previous values back.
        cache()->flush();

        ToastMagic::success(translate('settings_updated_successfully'));

        return back();
    }

    /**
     * @return array{sent: int, clicked: int, recovered: int, recovered_value: float}
     */
    private function stats(): array
    {
        if (!Schema::hasTable('abandoned_cart_reminders')) {
            return ['sent' => 0, 'clicked' => 0, 'recovered' => 0, 'recovered_value' => 0.0];
        }

        return [
            'sent' => AbandonedCartReminder::whereNotNull('sent_at')->count(),
            'clicked' => AbandonedCartReminder::whereNotNull('clicked_at')->count(),
            'recovered' => AbandonedCartReminder::whereNotNull('recovered_at')->count(),
            'recovered_value' => (float) AbandonedCartReminder::whereNotNull('recovered_at')->sum('cart_value'),
        ];
    }
}
