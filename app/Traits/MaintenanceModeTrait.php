<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

trait MaintenanceModeTrait
{
    public function checkMaintenanceMode(): bool
    {
        $maintenanceModeStatus = false;
        if (!auth()->guard('admin')->check()) {
            $maintenance = Cache::get('system_maintenance_mode') ?? $this->maintenanceModeFromSettings();
            if ($maintenance) {
                $maintenanceStatus = $maintenance['status'];
                if ($maintenanceStatus && isset($maintenance['selectedSystems']) && $maintenance['selectedSystems']) {
                    if ($maintenance['selectedSystems']['vendor_panel'] == 1 && (request()->is('vendor/*') || request('maintenance_system') == 'vendor')) {
                        $maintenanceModeStatus = $this->checkForMaintenanceMode($maintenance);
                    }
                    if (!request()->is('admin/*') && !request()->is('vendor/*') && $maintenance['selectedSystems']['user_website'] == 1) {
                        $maintenanceModeStatus = $this->checkForMaintenanceMode($maintenance);
                    }
                    if (request()->is('api/*')) {
                        $maintenanceModeStatus = $this->checkForMaintenanceMode($maintenance);
                    }

                }
            }
        }
        return $maintenanceModeStatus;
    }

    /**
     * The maintenance mode as the settings hold it, put back into the cache. The entry the check reads is a copy the settings
     * screen makes, and a cache that is emptied took the copy with it: the shop then opened again by itself while its settings
     * still said it was closed.
     */
    private function maintenanceModeFromSettings(): ?array
    {
        if (getWebConfig(name: 'maintenance_mode') != 1) {
            return null;
        }

        $duration = (array) getWebConfig(name: 'maintenance_duration_setup');
        $maintenance = [
            'status' => 1,
            'start_date' => $duration['start_date'] ?? null,
            'end_date' => $duration['end_date'] ?? null,
            'selectedSystems' => (array) getWebConfig(name: 'maintenance_system_setup'),
            'maintenance_duration' => $duration,
            'maintenance_message' => (array) getWebConfig(name: 'maintenance_message_setup'),
        ];
        Cache::put('system_maintenance_mode', $maintenance, now()->addYears(1));

        return $maintenance;
    }

    public function checkForMaintenanceMode($maintenance): bool
    {
        $status = false;
        $maintenanceDuration = $maintenance['maintenance_duration'] ?? [];
        if ($maintenanceDuration) {
            if (isset($maintenanceDuration['maintenance_duration']) && $maintenanceDuration['maintenance_duration'] == 'until_change') {
                $status = true;
            } else {
                if (isset($maintenance['start_date']) && isset($maintenance['end_date'])) {
                    $start = Carbon::parse($maintenance['start_date']);
                    $end = Carbon::parse($maintenance['end_date']);
                    $today = Carbon::now();
                    if ($today->between($start, $end)) {
                        $status = true;
                    }
                }
            }
        }
        return $status;
    }
}
