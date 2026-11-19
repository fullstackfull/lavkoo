<?php

namespace Tests\Feature\SalesChannels;

use App\Exceptions\StorefrontNotSellableException;
use App\Services\SalesChannels\Dispatch\DispatchOutcome;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontLifecycle;
use App\Services\Storefront\StorefrontSellingGate;
use Illuminate\Support\Facades\DB;

/**
 * I2-09.1: what the marketplace order materializer does while the storefront it runs in may not sell. The owner has recorded no rule for it: docs/gcc/DECISIONS.md is silent (it only says to keep
 * the marketplace integrations), and docs/gcc/STOREFRONT_FLAG_RUNBOOK.md names the materializer only among the paths that are not behind the sale gate. So the test pins what the code does today
 * under a name that says it is not a decision. The decision it needs is the owner's: whether a stopped storefront also stops the dispatch of a marketplace order to a provider (refused), or the
 * dispatch is exempt because the sale was made, and paid for, at the marketplace.
 */
class ChannelOrderSaleGateTest extends DispatchTestCase
{
    private function openStorefronts(): void
    {
        config(['gcc.storefronts.enabled' => true]);
    }

    private function stopTheDefaultStorefront(): void
    {
        app(StorefrontLifecycle::class)->pause(country: 'SA', mode: StorefrontLifecycle::BROWSE_ONLY, by: 'The head of the operations team', reason: 'Decision of the operations meeting');
        $this->beforeApplicationDestroyed(static fn () => DB::table('business_settings')->where('type', StorefrontLifecycle::settingName(country: 'SA'))->delete());
    }

    public function test_a_channel_order_is_made_into_an_internal_order_with_storefronts_on_and_the_storefront_selling(): void
    {
        $this->openStorefronts();
        $mapping = $this->placed();

        $this->assertTrue(app(StorefrontSellingGate::class)->maySell(country: 'SA', currencyCode: 'SAR'));
        $outcome = $this->dispatcher()->dispatch($mapping);

        $this->assertSame(DispatchOutcome::MATERIALIZED, $outcome->state);
        $this->assertSame([1, $outcome->internalOrderId], [DB::table('orders')->count(), $mapping->refresh()->order_id]);
    }

    /** Not endorsed. The sale gate refuses a new sale of the stopped storefront; the dispatch of a channel order is not asked, and makes its internal order all the same. */
    public function test_current_behaviour_a_channel_order_is_still_made_into_an_internal_order_while_the_storefront_may_not_sell(): void
    {
        $this->openStorefronts();
        $mapping = $this->placed();
        $this->stopTheDefaultStorefront();

        $this->assertFalse(app(StorefrontSellingGate::class)->maySell(country: 'SA', currencyCode: 'SAR'));
        $this->assertThrows(
            static fn () => app(StorefrontSellingGate::class)->assertMaySell(StorefrontContext::current()),
            StorefrontNotSellableException::class,
        );

        $outcome = $this->dispatcher()->dispatch($mapping);

        $this->assertSame(DispatchOutcome::MATERIALIZED, $outcome->state);
        $this->assertSame(1, DB::table('orders')->count());
        $order = DB::table('orders')->where('id', $outcome->internalOrderId)->first();
        $this->assertSame(['10234567890', 'confirmed', 'paid'], [$order->transaction_ref, $order->order_status, $order->payment_status]);
        $this->assertSame($outcome->internalOrderId, $mapping->refresh()->order_id);
    }
}
