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

        foreach ($currency->report() as $row) {
            if ($row['active'] && !str_starts_with($row['status'], 'default')) {
                $this->line($this->amountsLine(currency: $currency, country: $row['country'], currencyCode: $row['currency']));
            }
        }

        $this->line($blocked ? 'Not ready: a storefront that is switched on lacks its currency, so it cannot sell. Fix what is listed above, then run this again.' : 'Ready: every storefront that is switched on has the currency it trades in.');

        return $blocked ? self::FAILURE : self::SUCCESS;
    }

    /** What a storefront that is switched on has been given for its amounts, and what it has not: a warning, never a failure, since the neutral values cannot be wrong. */
    private function amountsLine(StorefrontCurrency $currency, string $country, string $currencyCode): string
    {
        $given = $currency->amountsGiven($country);

        if ($given['ignored_tag'] !== null) {
            return sprintf('  %-4s amounts: a row for %s is ignored, this storefront trades in %s: give them again with gcc:storefront:money. No minimum order, no free delivery, top-up closed.', $country, $given['ignored_tag'], $currencyCode);
        }

        $minimum = $given['minimum_order_amount'] === null ? 'no minimum order' : "minimum order {$given['minimum_order_amount']} {$currencyCode}";
        $freeDelivery = $given['free_delivery_over_amount'] === null ? 'no free delivery' : "free delivery over {$given['free_delivery_over_amount']} {$currencyCode}";

        return sprintf('  %-4s amounts: %s, %s, wallet top-up closed until the wallets are per market (GCC-15)%s.', $country, $minimum, $freeDelivery, $given['minimum_order_amount'] === null || $given['free_delivery_over_amount'] === null ? '; set what is missing with gcc:storefront:money' : '');
    }
}
