<?php

namespace Tests\Support;

use App\Services\Storefront\StorefrontLifecycle;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The ways the UAE storefront of a test world, which sells, can be made to not sell (I1-07): paused in each of the three modes, a draft, ready but not active, active without approved
 * legal texts, and ready without them. For a test that uses BuildsStorefrontCart::switchStorefrontsOn() and BuildsStorefrontLegalDocuments, whose world has the lifecycle tables.
 */
trait StopsAStorefront
{
    private const PERSON = 'The head of the Emirates market';

    private const REASON = 'Decision of the operations meeting';

    private function lifecycle(): StorefrontLifecycle
    {
        return app(StorefrontLifecycle::class);
    }

    private function pause(string $mode, string $country = 'AE'): void
    {
        $this->lifecycle()->pause(country: $country, mode: $mode, by: self::PERSON, reason: self::REASON);
    }

    private function withdrawTheRecordOfTheEmirates(): void
    {
        DB::table('business_settings')->where('type', StorefrontLifecycle::settingName('AE'))->delete();
        clearWebConfigCacheKeys();
    }

    private function makeTheEmiratesReadyOnly(): void
    {
        $this->withdrawTheRecordOfTheEmirates();
        $this->lifecycle()->markReady(country: 'AE', by: self::PERSON, reason: self::REASON);
    }

    private function withdrawTheLegalTextsOfTheEmirates(): void
    {
        config(['gcc.storefronts.legal.grandfathered' => ['SA']]);
    }

    /** @return array<string, array{Closure}> each takes the test and stops the UAE storefront in one way */
    public static function statesThatMayNotSell(): array
    {
        return [
            'paused browse only' => [fn (self $test) => $test->pause(mode: StorefrontLifecycle::BROWSE_ONLY)],
            'paused in maintenance' => [fn (self $test) => $test->pause(mode: StorefrontLifecycle::MAINTENANCE)],
            'paused with a redirect' => [fn (self $test) => $test->pause(mode: StorefrontLifecycle::REDIRECT)],
            'a draft' => [fn (self $test) => $test->withdrawTheRecordOfTheEmirates()],
            'ready, not active' => [fn (self $test) => $test->makeTheEmiratesReadyOnly()],
            'active, without approved legal texts' => [fn (self $test) => $test->withdrawTheLegalTextsOfTheEmirates()],
            'ready, without approved legal texts' => [function (self $test): void {
                $test->makeTheEmiratesReadyOnly();
                $test->withdrawTheLegalTextsOfTheEmirates();
            }],
        ];
    }
}
