<?php

namespace Tests\Feature\Storefront;

use App\Services\Notification\NotificationTypeRegistry;
use App\Services\Storefront\StorefrontLifecycle;
use App\Services\Storefront\StorefrontMarketingStop;
use App\Services\Storefront\StorefrontSellingGate;
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

    private const SAUDI_ROW_FIRST = 'group-of-two-countries-saudi-row-first';

    private const EMIRATI_ROW_FIRST = 'group-of-two-countries-emirati-row-first';

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

    /**
     * @param list<string> $cartGroupIds
     * @return list<string>
     */
    private function stoppedAbandonedCartReminders(array $cartGroupIds): array
    {
        return $this->marketingStop()->stoppedCartGroups(channel: NotificationTypeRegistry::EMAIL, type: NotificationTypeRegistry::ABANDONED_CART_REMINDER, cartGroupIds: $cartGroupIds);
    }

    /** A cart group holding a Saudi row and an Emirati row, in each order, as a legacy group can (CartManager::cartGroupsSharedWithOtherStorefronts). */
    private function aCartGroupOfTwoCountriesInEachOrder(): void
    {
        $this->cartRow(customerId: 1, productId: 1, price: 100, group: self::SAUDI_ROW_FIRST);
        $this->cartRow(customerId: 1, productId: 2, price: 250, group: self::SAUDI_ROW_FIRST);
        $this->cartRow(customerId: 2, productId: 2, price: 250, group: self::EMIRATI_ROW_FIRST);
        $this->cartRow(customerId: 2, productId: 1, price: 100, group: self::EMIRATI_ROW_FIRST);
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

    // ---- a storefront the configuration does not serve ------------------------------------------------------------------------------

    public function test_a_storefront_switched_off_in_the_configuration_alone_is_skipped(): void
    {
        config(['gcc.storefronts.countries.AE.active' => false]);

        $this->assertTrue($this->skipsTheAbandonedCartReminder(country: 'AE'), 'nobody can reach it, so the invitation would lead nowhere');
        $this->assertFalse($this->skipsTheAbandonedCartReminder(country: 'SA'), 'another storefront is not touched');
    }

    public function test_a_storefront_switched_off_in_the_configuration_is_not_skipped_while_storefronts_are_off(): void
    {
        config(['gcc.storefronts.countries.AE.active' => false, 'gcc.storefronts.enabled' => false]);

        $this->assertFalse($this->skipsTheAbandonedCartReminder(country: 'AE'));
    }

    public function test_the_carts_of_a_storefront_switched_off_in_the_configuration_are_stopped(): void
    {
        $this->cartRow(customerId: 1, productId: 1, price: 100, group: 'saudi-one');
        $this->cartRow(customerId: 2, productId: 2, price: 250, group: 'emirati-one');
        config(['gcc.storefronts.countries.AE.active' => false]);

        $stopped = $this->marketingStop()->stoppedCartGroups(
            channel: NotificationTypeRegistry::EMAIL,
            type: NotificationTypeRegistry::ABANDONED_CART_REMINDER,
            cartGroupIds: ['saudi-one', 'emirati-one'],
        );

        $this->assertSame(['emirati-one'], $stopped);
    }

    /**
     * What the selling gate itself answers is left as it was: it reads the lifecycle and the preflight, not the configuration, so it still
     * says that a storefront switched off in the configuration alone may sell. Nothing that calls the gate today can hand it such a country
     * anyway: StorefrontListing maps over the storefronts the configuration serves, and EnsureStorefrontMaySell judges a context the
     * resolver bound, which is never one it does not serve. Only this stop, which judges the country recorded on a cart, can see one, so
     * moving the judgement into the gate would change the answer for the stop alone and the gate's own tests pass either way.
     *
     * It is kept out of the gate because the two questions are not the same one: the gate says whether a storefront may sell, and the
     * configuration says whether anybody can reach it. A recorded country outlives the configuration — a payment taken in a storefront that
     * was switched off afterwards is still settled in it (PaymentStorefront::within, StorefrontResolver::contextOfRecordedCountry) — and
     * while no settlement path asks the gate today, a gate that refused an unserved country would be the wrong place to find that out.
     * Unreachable means do not invite, which is this stop. Whether the gate should read the configuration too is the owner's to decide (I1-07).
     */
    public function test_the_selling_gate_is_unchanged_for_a_storefront_switched_off_in_the_configuration(): void
    {
        config(['gcc.storefronts.countries.AE.active' => false]);

        $this->assertTrue(app(StorefrontSellingGate::class)->maySell(country: 'AE', currencyCode: 'AED'));
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

    // ---- a cart group that holds the rows of two countries --------------------------------------------------------------------------

    /**
     * A legacy cart group can hold the rows of two countries, as CartManager::cartGroupsSharedWithOtherStorefronts says and handles at the
     * checkout: a group built while storefronts were off was narrowed by nothing. A reminder or an offer is one message about the whole
     * group, and it cannot be half sent, so it is withheld as soon as one of the storefronts its rows belong to may not sell, whichever row
     * comes first. A message that invites a purchase nobody can make is worse than one that does not arrive, and the one that does not
     * arrive is sent as soon as the storefront sells again.
     */
    #[DataProvider('statesThatMayNotSell')]
    public function test_a_cart_group_that_holds_the_rows_of_two_countries_is_stopped_by_either_of_them(Closure $stopTheEmirates): void
    {
        $this->aCartGroupOfTwoCountriesInEachOrder();
        $stopTheEmirates($this);

        $this->assertSame([self::SAUDI_ROW_FIRST, self::EMIRATI_ROW_FIRST], $this->stoppedAbandonedCartReminders([self::SAUDI_ROW_FIRST, self::EMIRATI_ROW_FIRST]));
    }

    public function test_a_cart_group_that_holds_the_rows_of_two_countries_that_both_sell_is_stopped_by_neither(): void
    {
        $this->aCartGroupOfTwoCountriesInEachOrder();

        $this->assertSame([], $this->stoppedAbandonedCartReminders([self::SAUDI_ROW_FIRST, self::EMIRATI_ROW_FIRST]));
    }

    public function test_a_cart_group_of_two_countries_is_stopped_by_the_default_storefront_too(): void
    {
        $this->aCartGroupOfTwoCountriesInEachOrder();
        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY, country: 'SA');

        $this->assertSame([self::SAUDI_ROW_FIRST, self::EMIRATI_ROW_FIRST], $this->stoppedAbandonedCartReminders([self::SAUDI_ROW_FIRST, self::EMIRATI_ROW_FIRST]));
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

    public function test_the_countries_of_many_carts_are_read_in_one_query(): void
    {
        foreach (range(1, 6) as $number) {
            $this->cartRow(customerId: $number, productId: 2, price: 250, group: "emirati-{$number}");
        }

        $readsOfTheCarts = 0;
        DB::listen(function ($query) use (&$readsOfTheCarts): void {
            $readsOfTheCarts += str_contains($query->sql, 'from "carts"') || str_contains($query->sql, 'from `carts`') ? 1 : 0;
        });

        $this->marketingStop()->stoppedCartGroups(
            channel: NotificationTypeRegistry::EMAIL,
            type: NotificationTypeRegistry::ABANDONED_CART_REMINDER,
            cartGroupIds: array_map(fn (int $number): string => "emirati-{$number}", range(1, 6)),
        );

        $this->assertSame(1, $readsOfTheCarts, 'the country of every cart group is read together, not one query per cart');
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
