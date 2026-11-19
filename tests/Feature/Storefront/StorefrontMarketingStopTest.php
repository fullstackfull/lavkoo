<?php

namespace Tests\Feature\Storefront;

use App\Services\Notification\NotificationTypeRegistry;
use App\Services\Storefront\StorefrontLifecycle;
use App\Services\Storefront\StorefrontMarketingStop;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\StopsAStorefront;

/**
 * I1-07.2 and I1-07.3: a marketing send to the customers of a storefront is skipped while the storefront may not sell, using the decision of the selling gate, and a necessary one never
 * is. What the registry says decides which is which; a send tied to no storefront is never skipped, and nothing is skipped while storefronts are off.
 */
class StorefrontMarketingStopTest extends ApiAddressTestCase
{
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontLegalDocuments;
    use StopsAStorefront;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCartWorld();
        $this->seedTwoCountries();
        $this->buildStorefrontLegalDocumentsTable();
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_lifecycle_');
        $this->switchStorefrontsOn();
    }

    private function marketingStop(): StorefrontMarketingStop
    {
        return app(StorefrontMarketingStop::class);
    }

    private function skipsTheAbandonedCartReminder(?string $country): bool
    {
        return $this->marketingStop()->skips(channel: NotificationTypeRegistry::EMAIL, type: NotificationTypeRegistry::ABANDONED_CART_REMINDER, country: $country);
    }

    // ---- a marketing type ---------------------------------------------------------------------------------------------------------

    #[DataProvider('statesThatMayNotSell')]
    public function test_a_marketing_type_is_skipped_for_a_storefront_that_may_not_sell(Closure $stopTheEmirates): void
    {
        $stopTheEmirates($this);

        $this->assertTrue($this->skipsTheAbandonedCartReminder(country: 'AE'));
        $this->assertFalse($this->skipsTheAbandonedCartReminder(country: 'SA'), 'another storefront is not touched');
    }

    public function test_a_marketing_type_is_not_skipped_for_a_storefront_that_sells(): void
    {
        $this->assertFalse($this->skipsTheAbandonedCartReminder(country: 'AE'));
        $this->assertFalse($this->skipsTheAbandonedCartReminder(country: 'SA'), 'the default storefront sells without a record');
    }

    public function test_no_type_is_skipped_for_a_storefront_that_sells(): void
    {
        foreach (NotificationTypeRegistry::all() as $channel => $types) {
            foreach (array_keys($types) as $type) {
                $this->assertFalse($this->marketingStop()->skips(channel: $channel, type: $type, country: 'AE'), "{$channel} [{$type}]");
                $this->assertFalse($this->marketingStop()->skips(channel: $channel, type: $type, country: 'SA'), "{$channel} [{$type}]");
            }
        }
    }

    public function test_the_default_storefront_paused_browse_only_is_skipped(): void
    {
        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY, country: 'SA');

        $this->assertTrue($this->skipsTheAbandonedCartReminder(country: 'SA'));
        $this->assertFalse($this->skipsTheAbandonedCartReminder(country: 'AE'));
    }

    // ---- a necessary type ---------------------------------------------------------------------------------------------------------

    #[DataProvider('statesThatMayNotSell')]
    public function test_the_registry_decides_which_types_a_stopped_storefront_skips(Closure $stopTheEmirates): void
    {
        $stopTheEmirates($this);

        foreach (NotificationTypeRegistry::all() as $channel => $types) {
            foreach ($types as $type => $classification) {
                $this->assertSame(
                    $classification === NotificationTypeRegistry::MARKETING,
                    $this->marketingStop()->skips(channel: $channel, type: $type, country: 'AE'),
                    "{$channel} [{$type}] is {$classification}",
                );
            }
        }
    }

    #[DataProvider('statesThatMayNotSell')]
    public function test_the_messages_of_an_order_a_refund_a_payment_and_a_code_are_never_skipped(Closure $stopTheEmirates): void
    {
        $stopTheEmirates($this);

        foreach ([
            NotificationTypeRegistry::PUSH => ['order_pending_message', 'order_delivered_message', 'order_canceled', 'order_refunded_message', 'refund_request_canceled_message', 'fund_added_by_admin_message', 'withdraw_request_status_message'],
            NotificationTypeRegistry::EMAIL => ['order-place', 'order-delivered', 'add-fund-to-wallet', 'forgot-password', 'digital-product-otp', 'reset-password-verification'],
            NotificationTypeRegistry::WHATSAPP => ['order_created', 'order_status_changed', 'otp'],
        ] as $channel => $types) {
            foreach ($types as $type) {
                $this->assertFalse($this->marketingStop()->skips(channel: $channel, type: $type, country: 'AE'), "{$channel} [{$type}] must continue");
            }
        }
    }

    // ---- a send that is tied to no storefront, and a storefront that is not a market ----------------------------------------------------

    public function test_a_send_tied_to_no_storefront_is_never_skipped(): void
    {
        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY);

        $this->assertFalse($this->skipsTheAbandonedCartReminder(country: null));
    }

    #[DataProvider('statesThatMayNotSell')]
    public function test_nothing_is_skipped_while_storefronts_are_off(Closure $stopTheEmirates): void
    {
        $stopTheEmirates($this);
        config(['gcc.storefronts.enabled' => false]);

        $this->assertFalse($this->skipsTheAbandonedCartReminder(country: 'AE'));
    }

    public function test_nothing_is_read_while_storefronts_are_off(): void
    {
        $this->cartRow(customerId: 1, productId: 2, price: 250, group: 'emirati-one');
        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY);
        config(['gcc.storefronts.enabled' => false]);
        clearWebConfigCacheKeys();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $stopped = $this->marketingStop()->stoppedCartGroups(channel: NotificationTypeRegistry::EMAIL, type: NotificationTypeRegistry::ABANDONED_CART_REMINDER, cartGroupIds: ['emirati-one']);
        $skipped = $this->skipsTheAbandonedCartReminder(country: 'AE');

        $this->assertSame([], $stopped);
        $this->assertFalse($skipped);
        $this->assertSame([], $queries, 'not a setting, not a table, not a cart');
    }

    public function test_a_value_that_is_no_country_is_tied_to_no_storefront_and_a_country_that_is_no_storefront_cannot_sell(): void
    {
        $this->assertFalse($this->skipsTheAbandonedCartReminder(country: 'XYZ'));
        $this->assertFalse($this->skipsTheAbandonedCartReminder(country: ''));
        $this->assertTrue($this->skipsTheAbandonedCartReminder(country: 'EG'));
    }

    public function test_a_type_nobody_classified_is_refused_for_a_storefront(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->marketingStop()->skips(channel: NotificationTypeRegistry::PUSH, type: 'flash_sale_message', country: 'AE');
    }

    // ---- the carts of a storefront --------------------------------------------------------------------------------------------------

    public function test_the_carts_of_a_stopped_storefront_are_the_ones_it_names(): void
    {
        $this->cartRow(customerId: 1, productId: 1, price: 100, group: 'saudi-one');
        $this->cartRow(customerId: 2, productId: 2, price: 250, group: 'emirati-one');
        $this->cartRow(customerId: 3, productId: 2, price: 250, group: 'emirati-two');
        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY);

        $stopped = $this->marketingStop()->stoppedCartGroups(
            channel: NotificationTypeRegistry::EMAIL,
            type: NotificationTypeRegistry::ABANDONED_CART_REMINDER,
            cartGroupIds: ['saudi-one', 'emirati-one', 'emirati-two', 'a-group-with-no-cart'],
        );

        $this->assertSame(['emirati-one', 'emirati-two'], $stopped);
    }

    public function test_a_storefront_is_asked_once_for_all_the_carts_it_holds(): void
    {
        foreach (range(1, 6) as $number) {
            $this->cartRow(customerId: $number, productId: 2, price: 250, group: "emirati-{$number}");
        }
        config(['gcc.storefronts.legal.grandfathered' => ['SA']]);

        $askedFor = fn (array $groups): int => $this->countedReadsOfTheLegalTexts(
            fn () => $this->marketingStop()->stoppedCartGroups(channel: NotificationTypeRegistry::EMAIL, type: NotificationTypeRegistry::ABANDONED_CART_REMINDER, cartGroupIds: $groups),
        );

        $oneCart = $askedFor(['emirati-1']);
        $sixCarts = $askedFor(array_map(fn (int $number): string => "emirati-{$number}", range(1, 6)));

        $this->assertGreaterThan(0, $oneCart, 'the decision is read from the legal texts, so the count means something');
        $this->assertSame($oneCart, $sixCarts);
    }

    /** @param Closure(): mixed $work */
    private function countedReadsOfTheLegalTexts(Closure $work): int
    {
        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            $reads += str_contains($query->sql, 'storefront_legal_documents') ? 1 : 0;
        });

        $work();

        return $reads;
    }
}
