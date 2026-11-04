<?php

namespace Modules\AI\app\Traits;

use Illuminate\Support\Facades\Cache;
use Modules\AI\app\Models\AISetting;

trait AIModuleManager
{
    public function getActiveAIProviderConfig()
    {
        return Cache::remember('active_ai_provider', 60, function () {
            return AISetting::where('status', 1)
                ->whereNotNull('api_key')
                ->where('api_key', '!=', '')
                ->first();
        });
    }
}
