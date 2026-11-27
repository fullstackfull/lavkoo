<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Amazon\AmazonConnector;
use App\Services\SalesChannels\Amazon\AmazonMarketplaceChoice;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Data\PublishUpdate;
use App\Services\SalesChannels\Data\ShipmentConfirmation;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\Fixtures\AmazonFixtures as Amazon;
use Tests\TestCase;

/**
 * H2-07: an Amazon account of several marketplaces no longer writes everything into the first one.
 *
 * Amazon's writes each take ONE marketplace and change the listing or the order there and nowhere else. Reads already go through an
 * account's marketplaces one after another; the writes have always taken the first id of the list, which is right for an account of one
 * country and wrong for an account of two -- a Saudi price went into the Emirati catalogue, or the Emirati listing never changed, and the
 * publish was reported as accepted either way because Amazon accepted it for the marketplace it was told.
 *
 * `gcc.sales_channels.amazon.marketplace_of_the_write` is off and off is today's platform, to the id in the request, which the first two
 * tests measure on an account of two countries -- the only account whose answer the switch can change.
 *
 * With it on, the two halves are deliberately different, and that is the point of the design:
 *
 *  - a SHIPMENT CONFIRMATION names an order, and an Amazon order records the marketplace it was bought in, so the right answer is a READ
 *    of a fact the order already holds;
 *  - a LISTING write names one of OUR products, and the listing row is keyed `(account_id, listing_key)` -- one SKU sold in two
 *    marketplaces of one account is ONE row. There is no fact to read, so the write is REFUSED and not guessed. A listing row per
 *    marketplace, and the per-marketplace currency it would need, are the owner's decision.
 *
 * Every response is a fixture; no test can reach Amazon.
 */
class AmazonMarketplaceOfTheWriteTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const LWA = 'https://api.amazon.com/auth/o2/token';

    private const SP = 'https://sellingpartnerapi-eu.amazon.com';

    /** Amazon's own ids: Saudi Arabia and the United Arab Emirates. */
    private const SAUDI = 'A17E79C6D8DWNP';

    private const EMIRATI = 'A2VIGQ35RCS4UG';

    private const ORDER = '171-0000001-0000001';

    private AmazonConnector $amazon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->amazon = app(AmazonConnector::class);
    }

    private function switchOn(): void
    {
        config(['gcc.sales_channels.amazon.marketplace_of_the_write' => true]);
    }

    /** @param list<string> $marketplaceIds */
    private function account(array $marketplaceIds): SalesChannelAccount
    {
        $account = app(ChannelAccountService::class)->create(
            ['channel' => 'amazon', 'name' => 'Amazon Gulf', 'external_account_id' => 'A1SELLER', 'currency' => 'SAR', 'settings' => ['region' => 'eu', 'marketplace_ids' => $marketplaceIds]],
            ['lwa_client_id' => 'amzn1.application-oa2-client.test', 'lwa_client_secret' => 'client-secret-xyz', 'refresh_token' => 'Atzr|refresh-token-abc'],
            adminId: 1,
        );
        $account->forceFill([
            'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'product_sync', 'order_import', 'inventory_publishing', 'price_publishing', 'fulfillment_dispatch'],
            'capability_approvals' => ['inventory_publishing' => ['by' => 1], 'price_publishing' => ['by' => 1], 'fulfillment_dispatch' => ['by' => 1]],
        ])->save();

        return $account->fresh();
    }

    private function token(): array
    {
        return [self::LWA => Http::response(['access_token' => 'Atza|access-token-123', 'token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => 'Atzr|refresh-token-abc'])];
    }

    /** @return list<Request> */
    private function sentTo(string $needle): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(fn (array $pair): Request => $pair[0])->all();
    }

    private function mapping(SalesChannelAccount $account): SalesChannelProductMapping
    {
        return SalesChannelProductMapping::create([
            'account_id' => $account->id, 'listing_key' => 'SKU-1', 'external_sku' => 'SKU-1',
            'status' => 'mapped', 'product_id' => 5, 'snapshot' => ['product_type' => 'COSMETICS'],
        ]);
    }

    private function priceUpdate(SalesChannelProductMapping $mapping): PublishUpdate
    {
        return new PublishUpdate($mapping->id, 'SKU-1', 'SKU-1', null, null, Money::of('49.95'), null, 'SAR');
    }

    private function confirmation(?string $marketplaceId): ShipmentConfirmation
    {
        return new ShipmentConfirmation(
            self::ORDER, '1', 'SMSA', 'SMSA Express', 'TRK-9',
            CarbonImmutable::parse('2026-11-06T09:30:00Z'), ['44000000000001' => 2], $marketplaceId,
        );
    }

    // ---- the switch is off: today's platform, to the id in the request --------------------------------------------

    public function test_with_the_switch_off_a_price_still_goes_to_the_first_marketplace_of_a_two_country_account(): void
    {
        $account = $this->account([self::SAUDI, self::EMIRATI]);
        Http::fake($this->token() + [self::SP . '/*' => Http::sequence()
            ->push(['sku' => 'SKU-1', 'status' => 'VALID', 'issues' => []])
            ->push(['sku' => 'SKU-1', 'status' => 'ACCEPTED', 'submissionId' => 'sub-1', 'issues' => []])]);
        $mapping = $this->mapping($account);

        $result = $this->amazon->publishPrices($account, [$this->priceUpdate($mapping)]);

        $this->assertSame([$mapping->id => 'sub-1'], $result->accepted);
        $this->assertSame([], $result->failed);
        foreach ($this->sentTo('/listings/2021-08-01/items/A1SELLER/SKU-1') as $request) {
            $this->assertStringContainsString('marketplaceIds=' . self::SAUDI, $request->url());
        }
    }

    public function test_with_the_switch_off_a_shipment_is_still_confirmed_in_the_first_marketplace_whatever_the_order_says(): void
    {
        $account = $this->account([self::SAUDI, self::EMIRATI]);
        Http::fake($this->token() + [self::SP . '/*' => Http::response('', 204)]);

        $this->assertTrue($this->amazon->confirmShipment($account, $this->confirmation(self::EMIRATI)));

        $this->assertSame(self::SAUDI, $this->sentTo('/shipmentConfirmation')[0]->data()['marketplaceId']);
    }

    public function test_with_the_switch_off_every_answer_is_the_first_id(): void
    {
        $choice = app(AmazonMarketplaceChoice::class);

        $this->assertFalse($choice->enabled(), 'the switch ships off');
        $this->assertMatchesRegularExpression('/^GCC_AMAZON_MARKETPLACE_OF_THE_WRITE=false$/m', (string) file_get_contents(base_path('.env.example')), 'and the example file each CI job copies ships it off too');
        $this->assertSame(self::SAUDI, $choice->forListingWrite([self::SAUDI, self::EMIRATI]));
        $this->assertSame(self::SAUDI, $choice->forOrderWrite([self::SAUDI, self::EMIRATI], self::EMIRATI));
    }

    // ---- an account of ONE country is unaffected either way -------------------------------------------------------

    public function test_an_account_of_one_country_writes_exactly_as_before_with_the_switch_on(): void
    {
        $this->switchOn();
        $account = $this->account([self::SAUDI]);
        Http::fake($this->token() + [self::SP . '/*' => Http::sequence()
            ->push(['sku' => 'SKU-1', 'status' => 'VALID', 'issues' => []])
            ->push(['sku' => 'SKU-1', 'status' => 'ACCEPTED', 'submissionId' => 'sub-1', 'issues' => []])]);
        $mapping = $this->mapping($account);

        $result = $this->amazon->publishPrices($account, [$this->priceUpdate($mapping)]);

        $this->assertSame([$mapping->id => 'sub-1'], $result->accepted);
        foreach ($this->sentTo('/listings/2021-08-01/items/A1SELLER/SKU-1') as $request) {
            $this->assertStringContainsString('marketplaceIds=' . self::SAUDI, $request->url());
        }
    }

    // ---- the switch is on, for an account of two countries --------------------------------------------------------

    public function test_a_shipment_is_confirmed_in_the_marketplace_the_order_was_bought_in(): void
    {
        $this->switchOn();
        $account = $this->account([self::SAUDI, self::EMIRATI]);
        Http::fake($this->token() + [self::SP . '/*' => Http::response('', 204)]);

        $this->assertTrue($this->amazon->confirmShipment($account, $this->confirmation(self::EMIRATI)));

        $this->assertSame(self::EMIRATI, $this->sentTo('/shipmentConfirmation')[0]->data()['marketplaceId']);
    }

    /** The id the request carries is the ACCOUNT's own spelling, never the one a payload happened to use. */
    public function test_the_id_sent_is_the_one_the_account_was_set_up_with(): void
    {
        $this->switchOn();
        $account = $this->account([self::SAUDI, self::EMIRATI]);
        Http::fake($this->token() + [self::SP . '/*' => Http::response('', 204)]);

        $this->amazon->confirmShipment($account, $this->confirmation(strtolower(self::EMIRATI)));

        $this->assertSame(self::EMIRATI, $this->sentTo('/shipmentConfirmation')[0]->data()['marketplaceId']);
    }

    public function test_a_shipment_whose_order_names_no_marketplace_is_refused_rather_than_sent_to_the_first(): void
    {
        $this->switchOn();
        $account = $this->account([self::SAUDI, self::EMIRATI]);
        Http::fake($this->token() + [self::SP . '/*' => Http::response('', 204)]);

        try {
            $this->amazon->confirmShipment($account, $this->confirmation(null));
            $this->fail('A confirmation that names no marketplace must not be sent to a guessed one.');
        } catch (ChannelUnavailableException $refused) {
            $this->assertSame(ChannelUnavailableException::MARKETPLACE_AMBIGUOUS, $refused->reason);
        }

        $this->assertSame([], $this->sentTo('/shipmentConfirmation'), 'nothing left this host');
    }

    public function test_a_shipment_whose_order_names_a_marketplace_the_account_does_not_sell_in_is_refused(): void
    {
        $this->switchOn();
        $account = $this->account([self::SAUDI, self::EMIRATI]);
        Http::fake($this->token() + [self::SP . '/*' => Http::response('', 204)]);

        try {
            $this->amazon->confirmShipment($account, $this->confirmation('ATVPDKIKX0DER'));
            $this->fail('A marketplace the account does not hold decides nothing.');
        } catch (ChannelUnavailableException $refused) {
            $this->assertSame(ChannelUnavailableException::MARKETPLACE_AMBIGUOUS, $refused->reason);
        }

        $this->assertSame([], $this->sentTo('/shipmentConfirmation'));
    }

    public function test_a_price_is_refused_with_a_reason_and_nothing_leaves_the_host(): void
    {
        $this->switchOn();
        $account = $this->account([self::SAUDI, self::EMIRATI]);
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['sku' => 'SKU-1', 'status' => 'VALID', 'issues' => []])]);
        $mapping = $this->mapping($account);

        $result = $this->amazon->publishPrices($account, [$this->priceUpdate($mapping)]);

        $this->assertSame([], $result->accepted);
        $this->assertSame([$mapping->id => AmazonMarketplaceChoice::CANNOT_BE_DECIDED], $result->failed);
        $this->assertSame([], $result->transient, 'it is not a failure to retry: nothing about the account will change by itself');
        $this->assertSame([], $this->sentTo('/listings/2021-08-01/items'), 'the validation preview is a write too, and it is not sent either');
    }

    public function test_a_quantity_is_refused_the_same_way(): void
    {
        $this->switchOn();
        $account = $this->account([self::SAUDI, self::EMIRATI]);
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['sku' => 'SKU-1', 'status' => 'VALID', 'issues' => []])]);
        $mapping = $this->mapping($account);

        $result = $this->amazon->publishStock($account, [new PublishUpdate($mapping->id, 'SKU-1', 'SKU-1', null, 7)]);

        $this->assertSame([$mapping->id => AmazonMarketplaceChoice::CANNOT_BE_DECIDED], $result->failed);
        $this->assertSame([], $this->sentTo('/listings/2021-08-01/items'));
    }

    /** The reason is written into a publish result and a log, so it may not carry the account's or the seller's identity. */
    public function test_the_reason_names_no_account_and_no_seller(): void
    {
        $this->assertStringNotContainsString('A1SELLER', AmazonMarketplaceChoice::CANNOT_BE_DECIDED);
        $this->assertStringNotContainsString(self::SAUDI, AmazonMarketplaceChoice::CANNOT_BE_DECIDED);
    }

    // ---- reads are untouched in both positions --------------------------------------------------------------------

    public function test_a_connection_test_still_probes_the_first_marketplace_with_the_switch_on(): void
    {
        $this->switchOn();
        $account = $this->account([self::SAUDI, self::EMIRATI]);
        Http::fake($this->token() + [self::SP . '/*' => Http::response(Amazon::searchOrders([]))]);

        $this->amazon->testConnection($account);

        $probes = $this->sentTo('/orders/2026-01-01/orders');
        $this->assertNotSame([], $probes, 'refusing to TEST an account of two countries would stop it ever being set up');
        $this->assertStringContainsString('marketplaceIds=' . self::SAUDI, $probes[0]->url());
    }

    public function test_a_listing_read_still_goes_through_both_marketplaces_with_the_switch_on(): void
    {
        $this->switchOn();
        $account = $this->account([self::SAUDI, self::EMIRATI]);
        Http::fake($this->token() + [self::SP . '/*' => Http::response(['items' => [], 'numberOfResults' => 0])]);

        $first = $this->amazon->listings($account, null);
        $this->amazon->listings($account, $first->nextCursor);

        $reads = $this->sentTo('/listings/2021-08-01/items/A1SELLER');
        $this->assertCount(2, $reads);
        $this->assertStringContainsString('marketplaceIds=' . self::SAUDI, $reads[0]->url());
        $this->assertStringContainsString('marketplaceIds=' . self::EMIRATI, $reads[1]->url());
    }

    // ---- the rule itself ------------------------------------------------------------------------------------------

    public function test_a_marketplace_this_platform_does_not_know_is_its_own_answer(): void
    {
        $this->switchOn();
        $choice = app(AmazonMarketplaceChoice::class);

        $this->assertTrue($choice->spansMoreThanOneCountry([self::SAUDI, 'NOT-A-MARKETPLACE']));
        $this->assertNull($choice->forListingWrite([self::SAUDI, 'NOT-A-MARKETPLACE']));
        $this->assertSame(self::SAUDI, $choice->forListingWrite([self::SAUDI, self::SAUDI]), 'one country twice is still one country');
    }
}
