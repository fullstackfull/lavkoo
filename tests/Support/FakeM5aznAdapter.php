<?php

namespace Tests\Support;

use App\Enums\Fulfillment\ProviderCode;

class FakeM5aznAdapter extends FakeProviderAdapter
{
    public function code(): ProviderCode
    {
        return ProviderCode::M5azn;
    }

    /** @return array<string, mixed> */
    public static function defaultSettings(): array
    {
        return ['dispatch_enabled' => true, 'dry_run' => false];
    }
}
