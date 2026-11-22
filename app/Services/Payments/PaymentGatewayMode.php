<?php

namespace App\Services\Payments;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Which environment a gateway's platform settings row is in, test or live. The one place that reads it, so a caller does not have to know the table. */
#[Singleton]
final class PaymentGatewayMode
{
    private const SETTINGS_TYPE = 'payment_config';

    public function of(string $gateway): string
    {
        if (!Schema::hasTable('addon_settings')) {
            return GatewayAccountRegistry::ENVIRONMENT_TEST;
        }

        $mode = DB::table('addon_settings')
            ->where('key_name', $gateway)
            ->where('settings_type', self::SETTINGS_TYPE)
            ->value('mode');

        return GatewayAccountRegistry::environment(is_string($mode) ? $mode : null);
    }
}
