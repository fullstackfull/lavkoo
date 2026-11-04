<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\BusinessSetting;
use App\Models\ShippingZone;
use App\Services\Marketplace\ShippingRateService;
use App\Services\AuditLogger;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin shipping zones (Phase 3, Stage C). Manage destination rate rules and preview the rate a
 * destination resolves to. The resolver is non-breaking — where no zone matches it returns null and
 * the storefront keeps its existing flat shipping.
 */
class ShippingZoneController extends BaseController
{
    public function __construct(
        private readonly ShippingRateService $shipping,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $zones = Schema::hasTable('shipping_zones')
            ? ShippingZone::orderBy('priority')->orderBy('name')->get()
            : collect();

        // Optional rate preview.
        $preview = null;
        if ($request?->filled('country')) {
            $rate = $this->shipping->rateFor(
                $request->input('country'),
                (float) $request->input('weight', 0),
                (float) $request->input('order_value', 0),
                $request->input('region'),
            );
            $preview = [
                'input' => $request->only('country', 'region', 'weight', 'order_value'),
                'result' => $rate,
            ];
        }

        return view('admin-views.marketplace.shipping-zones', [
            'zones' => $zones,
            'preview' => $preview,
            'zoneShippingEnabled' => (bool) (getWebConfig(name: 'zone_wise_shipping') ?? false),
        ]);
    }

    /**
     * Switch destination-based (zone) shipping on or off for web checkout.
     *
     * Off by default and off until switched here, so checkout keeps its existing method-based shipping.
     * When on, the web checkout charges the zone rate for the destination (falling back to the chosen
     * method's cost where no zone matches).
     */
    public function toggle(Request $request): RedirectResponse
    {
        $enabled = $request->boolean('status');
        BusinessSetting::updateOrCreate(['type' => 'zone_wise_shipping'], ['value' => $enabled ? '1' : '0']);
        clearWebConfigCacheKeys();

        $this->audit->record(action: 'shipping.zone_shipping_toggled', subject: ['type' => 'business_setting', 'id' => 'zone_wise_shipping'], after: ['enabled' => $enabled]);
        ToastMagic::success($enabled ? translate('zone_shipping_enabled') : translate('zone_shipping_disabled'));

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = auth('admin')->id();
        $zone = ShippingZone::create($data);

        $this->audit->record(action: 'shipping.zone_created', subject: ['type' => 'shipping_zone', 'id' => $zone->id], after: ['name' => $zone->name]);
        ToastMagic::success(translate('shipping_zone_created'));

        return back();
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $zone = ShippingZone::findOrFail($id);
        $zone->update($this->validated($request));
        ToastMagic::success(translate('shipping_zone_updated'));

        return back();
    }

    public function destroy(int $id): RedirectResponse
    {
        ShippingZone::findOrFail($id)->delete();
        ToastMagic::success(translate('shipping_zone_deleted'));

        return back();
    }

    private function validated(Request $request): array
    {
        $v = $request->validate([
            'name' => 'required|string|max:191',
            'countries' => 'nullable|string|max:2000',
            'regions' => 'nullable|string|max:2000',
            'base_cost' => 'required|numeric|min:0',
            'per_kg_cost' => 'nullable|numeric|min:0',
            'free_over' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer|min:0',
            'status' => 'nullable|in:active,inactive',
        ]);

        $split = fn ($s) => array_values(array_filter(array_map('trim', explode(',', (string) $s))));

        return [
            'name' => $v['name'],
            'countries' => $split($v['countries'] ?? ''),
            'regions' => $split($v['regions'] ?? ''),
            'base_cost' => $v['base_cost'],
            'per_kg_cost' => $v['per_kg_cost'] ?? 0,
            'free_over' => ($v['free_over'] === null || $v['free_over'] === '') ? null : $v['free_over'],
            'priority' => $v['priority'] ?? 10,
            'status' => $v['status'] ?? 'active',
        ];
    }
}
