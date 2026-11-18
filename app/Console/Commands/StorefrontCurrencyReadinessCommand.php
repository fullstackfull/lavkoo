<?php

namespace App\Console\Commands;

use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Console\Command;

class StorefrontCurrencyReadinessCommand extends Command
{
    protected $signature = 'gcc:storefront:currency-readiness';

    protected $description = 'Read-only report before storefront currencies are turned on: for each configured storefront, the currency it trades in, its decimals and whether the currency exists and is active. Exits 1 when a storefront that is switched on lacks its currency';

    public function handle(StorefrontCurrency $currency): int
    {
        $this->line('Storefront currencies: ' . ($currency->isEnabled() ? 'on' : 'off') . ' (GCC_STOREFRONT_CURRENCY, and GCC_STOREFRONTS_ENABLED must be on too).');

        $blocked = false;

        foreach ($currency->report() as $row) {
            $this->line(sprintf('  %-4s %-3s %d decimals  %-8s %s', $row['country'], $row['currency'], $row['decimals'], $row['active'] ? 'active' : 'inactive', $row['status']));
            $blocked = $blocked || ($row['active'] && !str_starts_with($row['status'], 'default') && $row['status'] !== 'ready');
        }

        $this->line($blocked ? 'Not ready: a storefront that is switched on lacks its currency, so it cannot sell. Fix what is listed above, then run this again.' : 'Ready: every storefront that is switched on has the currency it trades in.');

        return $blocked ? self::FAILURE : self::SUCCESS;
    }
}
