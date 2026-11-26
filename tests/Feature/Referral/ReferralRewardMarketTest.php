<?php

namespace Tests\Feature\Referral;

use App\Services\Storefront\LedgerAttribution;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsReferralRewardWorld;
use Tests\TestCase;

/**
 * E-15's last criterion: the first-order referral reward belongs to the market the ORDER was placed in, on every delivery path.
 *
 * The reward is a credit to the REFERRER, and the referrer is somebody who may never have shopped in that market at all. Nothing in the
 * request that pays it says which market it is: five of the six paths are a panel or a seller API, where `StorefrontCatalog::country()` is
 * null, and the sixth is a delivery man's app. So the only thing that can say which market a reward belongs to is the order that earned it.
 *
 * Why that matters NOW, while there is still one wallet per customer: the market of the ledger row is what the mirror of E-07 reads to
 * decide which market's account a movement moves (`MarketShadowWrite::mirror()`), and it is what a per-market statement is narrowed by
 * (E-04, E-01). A reward row naming no market, or naming the market of whatever request happened to pay it, lands in the wrong account the
 * day a market is switched over and is missing from the referrer's statement until then.
 *
 * **These tests drive the PATHS, not the writer.** `WalletLedgerAttributionTest` already proves that each of the four wallet writers copies
 * the market from the order it is told about; that stays green when a referral call site stops telling it. What is cut here is the CALL: each
 * case delivers a Kuwaiti order through one real path and reads the row the referral credit left.
 */
class ReferralRewardMarketTest extends TestCase
{
    use BuildsReferralRewardWorld;

    private const KUWAITI_ORDER = 401;

    private const SAUDI_ORDER = 402;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReferralRewardWorld();
        $this->migrateReferralRewardGrants();
        $this->recordTheMarketOnTheOrdersAndTheLedger();
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.default_country' => 'SA']);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /**
     * The six delivery paths that pay a first-order referral reward, each by the world's own driver, so a path that stops paying -- or a
     * seventh that starts -- shows up here rather than in nothing.
     *
     * @return array<string, array{0: string}>
     */
    public static function paths(): array
    {
        $vendorOrder = ['order_status' => 'confirmed', 'payment_status' => 'unpaid', 'payment_method' => 'cash_on_delivery', 'seller_id' => 5, 'seller_is' => 'seller'];

        return [
            'the admin panel' => ['deliverThroughAdminPanel', ['order_status' => 'confirmed']],
            'the vendor panel' => ['deliverThroughVendorPanel', ['order_status' => 'confirmed', 'seller_id' => 5, 'seller_is' => 'seller']],
            'the delivery man app' => ['deliverThroughDeliveryManApp', ['order_status' => 'out_for_delivery', 'delivery_man_id' => 7]],
            'the seller API v2' => ['deliverThroughSellerApiV2', ['order_status' => 'confirmed', 'payment_status' => 'paid', 'seller_id' => 5, 'seller_is' => 'seller']],
            'the seller API v3, order detail status' => ['deliverThroughSellerApiV3OrderDetailStatus', $vendorOrder],
            'the seller API v3, update order details' => ['deliverThroughSellerApiV3UpdateOrderDetails', $vendorOrder],
        ];
    }

    /** @param array<string, mixed> $order the state each path needs the order to be in before it delivers it, as its own idempotency tests build it */
    #[DataProvider('paths')]
    public function test_the_reward_names_the_market_of_the_order_that_earned_it(string $path, array $order): void
    {
        $this->makeOrder(id: self::KUWAITI_ORDER, attributes: $order + ['storefront_country' => 'KW']);

        $this->{$path}(self::KUWAITI_ORDER);

        $this->assertSame('KW', $this->marketOfTheReward(), $path . ' pays a reward that belongs to the market of the order');
    }

    /**
     * The trap this exists to stop, and the one every market-aware figure on this platform has fallen into: reading the market of the
     * REQUEST. A delivery man's app can be served a storefront, and the market it is served is nothing to do with the market the order it
     * is delivering was sold in.
     */
    public function test_a_request_served_another_market_does_not_change_the_market_of_the_reward(): void
    {
        $this->makeOrder(id: self::KUWAITI_ORDER, attributes: ['storefront_country' => 'KW', 'order_status' => 'out_for_delivery', 'delivery_man_id' => 7]);

        $this->servedFrom('SA', fn () => $this->deliverThroughDeliveryManApp(self::KUWAITI_ORDER));

        $this->assertSame('SA', StorefrontResolver::fromConfig()->contextOfRecordedCountry('SA')->serve(static fn (): ?string => StorefrontCatalog::country()), 'the request really was served a market, or the reading below proves nothing');
        $this->assertSame('KW', $this->marketOfTheReward(), 'the order was sold in Kuwait whatever market the request was served');
    }

    /** And the twin of it: a Saudi order's reward is Saudi, so the reading above is the order's market and not a constant. */
    public function test_a_saudi_order_pays_a_saudi_reward(): void
    {
        $this->makeOrder(id: self::SAUDI_ORDER, attributes: ['storefront_country' => 'SA', 'order_status' => 'confirmed']);

        $this->deliverThroughAdminPanel(self::SAUDI_ORDER);

        $this->assertSame('SA', $this->marketOfTheReward());
    }

    /**
     * An order that recorded no market at all -- every order of this platform before `orders.storefront_country` existed -- leaves the
     * reward naming no market rather than being filed under the default one by this path. That is `LedgerAttribution`'s rule for every
     * movement and is not restated here; what this pins is that a referral reward follows it.
     */
    public function test_an_order_that_recorded_no_market_pays_a_reward_that_names_none(): void
    {
        $this->makeOrder(id: self::SAUDI_ORDER, attributes: ['order_status' => 'confirmed']);

        $this->deliverThroughAdminPanel(self::SAUDI_ORDER);

        $this->assertNull($this->marketOfTheReward(), 'nothing recorded is nothing to claim about the market');
    }

    /** Before the attribution migration the ledger has no such column, so every path writes exactly the row it writes today. */
    public function test_before_the_migration_the_reward_row_is_what_it_has_always_been(): void
    {
        Schema::table('wallet_transactions', static function (Blueprint $table): void {
            $table->dropColumn(['storefront_country', 'currency_code', 'legal_entity_id', 'source_type', 'source_id', 'actor_type', 'actor_id']);
        });
        app(LedgerAttribution::class)->flush();
        $this->makeOrder(id: self::KUWAITI_ORDER, attributes: ['storefront_country' => 'KW', 'order_status' => 'confirmed']);

        $this->deliverThroughAdminPanel(self::KUWAITI_ORDER);

        $row = $this->referralRows()->sole();
        $this->assertEqualsWithDelta(10.0, (float) $row->credit, 0.000001);
        $this->assertSame('earned_by_referral', $row->reference);
        $this->assertArrayNotHasKey('storefront_country', (array) $row);
    }

    /** Runs the callback as a request of one storefront, the way the storefront middleware binds it. */
    private function servedFrom(string $country, callable $callback): mixed
    {
        return StorefrontResolver::fromConfig()->contextOfRecordedCountry($country)->serve(callback: $callback);
    }

    private function marketOfTheReward(): ?string
    {
        $row = $this->referralRows()->sole();

        return $row->storefront_country === null ? null : (string) $row->storefront_country;
    }

    /**
     * The columns E-05's migration adds, built onto the world's hand-made tables: the world predates them and `LedgerAttribution` answers
     * nothing at all while the ledger has none of them, so without this every assertion here would read null for the right reason and
     * prove nothing.
     */
    private function recordTheMarketOnTheOrdersAndTheLedger(): void
    {
        Schema::table('orders', static function (Blueprint $table): void {
            $table->string('order_group_id')->nullable();
            $table->char('storefront_country', 2)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
        });
        Schema::table('wallet_transactions', static function (Blueprint $table): void {
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->char('storefront_country', 2)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
        });

        app(LedgerAttribution::class)->flush();
        DB::table('orders')->whereNotNull('id')->update(['order_group_id' => null]);
    }
}
