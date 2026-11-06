<?php

namespace Tests\Support;

use App\Enums\Fulfillment\ProviderCode;

class FakeTorodAdapter extends FakeProviderAdapter
{
    public function code(): ProviderCode
    {
        return ProviderCode::TorodPoint;
    }

    /** @return array<string, mixed> */
    public static function defaultSettings(): array
    {
        return ['dispatch_enabled' => true, 'dry_run' => false];
    }
}
