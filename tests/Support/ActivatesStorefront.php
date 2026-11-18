<?php

namespace Tests\Support;

use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * The lifecycle a storefront needs before it may sell (I2-11): active. A test of the checkout, of a payment, of a cart or of the list of storefronts calls it, through the helper that
 * builds a world that may sell, so that the sale gate is not what is under test; a test of the lifecycle itself builds the world without it. It writes the record the way an approved
 * activation leaves it and makes none of the checks the service makes, because the prerequisites of a test world are built in whatever order the test needs. What it wrote is taken
 * away when the test ends, because a real server keeps the tables and the rows of one test class for the next.
 */
trait ActivatesStorefront
{
    use BuildsStorefrontDecisionTables;

    protected function activateStorefrontFor(string ...$countries): void
    {
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: StorefrontLifecycle::settingName(country: ''));

        foreach ($countries as $country) {
            DB::table('business_settings')->updateOrInsert(
                ['type' => StorefrontLifecycle::settingName($country)],
                ['value' => json_encode([
                    'state' => StorefrontLifecycle::ACTIVE,
                    'mode' => null,
                    'reason' => 'The test world',
                    'message' => null,
                    'until' => null,
                    'changed_by' => 'The owner of the test world',
                    'reference' => 'The test world',
                    'changed_at' => now()->toIso8601String(),
                ]), 'created_at' => now(), 'updated_at' => now()],
            );
        }

        clearWebConfigCacheKeys();
    }
}
