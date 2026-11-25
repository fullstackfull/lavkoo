<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Amazon\AmazonConnector;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Data\PublishUpdate;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Noon\NoonConnector;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Support\ChannelMoney;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * A dinar price keeps its third decimal, and an account that sells outside Saudi Arabia is never sent the riyal (H2-08).
 *
 * The publisher and all three connectors rounded every price to TWO decimals. For the riyal, the dirham and the Qatari riyal that
 * is right; for the Kuwaiti dinar, the Bahraini dinar and the Omani rial, which have three (ISO 4217), it published a figure
 * nobody set -- 2.375 became 2.38, and the difference is real money on every unit sold. Amazon's price patch had the second half
 * of the same fault: with no currency on the account it named `SAR`, so an account selling on amazon.ae would have had its
 * dirham prices labelled as riyals, which at 2.6 to 1 is not a rounding error.
 *
 * Every case has its two-decimal twin, and those twins are the proof that the Saudi payload did not move: the same price, on the
 * same account with the riyal, is still the figure the suite has always asserted.
 *
 * No test reaches a channel. The payloads are read out of the faked HTTP calls.
 */
class ChannelMoneyDecimalsTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const TRENDYOL = 'https://apigw.trendyol.com/integration';

    private const NOON = 'https://noon-api-gateway.noon.partners';

    private const NOON_LOGIN = self::NOON . '/identity/public/v1/api/login';

    private const NOON_PRICING = self::NOON . '/pricing/v1/pricing/upsert';

    private const AMAZON_TOKEN = 'https://api.amazon.com/auth/o2/token';

    private const AMAZON = 'https://sellingpartnerapi-eu.amazon.com';

    /** The Emirati marketplace Amazon publishes, which is how an account says it sells in the Emirates. */
    private const AMAZON_AE = 'A2VIGQ35RCS4UG';

    /** An Amazon marketplace this platform has no storefront for, so nothing says what currency it trades in. */
    private const AMAZON_EG = 'ARBP9OOSHTCHU';

    private const PRODUCT = 50;

    /** A price whose third decimal is not zero: the whole point is that it survives. */
    private const THREE_DECIMAL_PRICE = '2.375';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSalesChannelSchema();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00 UTC'));
    }

    // ---- what a currency's decimals are ------------------------------------------------------------------------------

    public function test_the_three_gulf_currencies_with_three_decimals_are_the_three_that_have_them(): void
    {
        foreach (['SAR' => 2, 'AED' => 2, 'QAR' => 2, 'KWD' => 3, 'BHD' => 3, 'OMR' => 3] as $currency => $decimals) {
            $this->assertSame($decimals, ChannelMoney::decimalsOf($currency), $currency);
        }

        $this->assertSame(2, ChannelMoney::decimalsOf(null), 'a currency nothing names keeps the two decimals every price was rounded to');
    }

    // ---- the publisher, through Trendyol -----------------------------------------------------------------------------

    public function test_a_dinar_price_reaches_trendyol_with_its_third_decimal(): void
    {
        $account = $this->trendyolAccount();
        $account->forceFill(['currency' => 'KWD'])->save();
        $this->listing($account);
        $this->fakeOnly([self::TRENDYOL . '/*' => Http::response(['batchRequestId' => 'batch-1'])]);

        $result = app(StockPricePublisher::class)->publishPrices($account->fresh());

        $this->assertSame(1, $result['stats']['accepted'], 'the price was sent: ' . json_encode($result['stats']));
        $this->assertSame(
            ['items' => [['barcode' => 'BC-1', 'salePrice' => 2.375]]],
            $this->bodyOf('price-and-inventory'),
            'a Kuwaiti price keeps all three of its decimals',
        );
    }

    public function test_the_same_price_in_riyals_is_still_rounded_to_two(): void
    {
        $account = $this->trendyolAccount();
        $this->listing($account);
        $this->fakeOnly([self::TRENDYOL . '/*' => Http::response(['batchRequestId' => 'batch-1'])]);

        app(StockPricePublisher::class)->publishPrices($account->fresh());

        $this->assertSame(
            ['items' => [['barcode' => 'BC-1', 'salePrice' => 2.38]]],
            $this->bodyOf('price-and-inventory'),
            'the two-decimal twin: the Saudi payload is what it has always been',
        );
    }

    // ---- noon --------------------------------------------------------------------------------------------------------

    public function test_a_dinar_price_reaches_noon_with_its_third_decimal_and_a_riyal_one_with_two(): void
    {
        $kuwaiti = $this->noonAccount();
        $kuwaiti->forceFill(['currency' => 'KWD'])->save();
        $this->noonAccepts();

        app(NoonConnector::class)->publishPrices($kuwaiti->fresh(), [$this->update(11, price: self::THREE_DECIMAL_PRICE, listPrice: '3.125')]);

        $this->assertSame(
            [['partner_sku' => 'SKU-1', 'country_code' => 'sa', 'price' => 2.375, 'msrp' => 3.125]],
            $this->bodyOf(self::NOON_PRICING)['items'],
            'the price and the list price both keep the third decimal',
        );

        $saudi = $this->noonAccount(partnerCode: 'partner-2');
        $this->noonAccepts();

        app(NoonConnector::class)->publishPrices($saudi->fresh(), [$this->update(12, price: self::THREE_DECIMAL_PRICE, listPrice: '3.125')]);

        $this->assertSame(
            [['partner_sku' => 'SKU-1', 'country_code' => 'sa', 'price' => 2.38, 'msrp' => 3.13]],
            $this->bodyOf(self::NOON_PRICING)['items'],
            'the two-decimal twin',
        );
    }

    // ---- Amazon ------------------------------------------------------------------------------------------------------

    public function test_the_amazon_patch_keeps_the_third_decimal(): void
    {
        $account = $this->amazonAccount();
        $account->forceFill(['currency' => 'KWD'])->save();
        $this->amazonAccepts();

        app(AmazonConnector::class)->publishPrices($account->fresh(), [$this->update(11, price: self::THREE_DECIMAL_PRICE)]);

        $this->assertSame(['KWD', 2.375], $this->amazonCurrencyAndPrice(), 'the account names dinars, so three decimals and dinars go out');
    }

    public function test_an_emirati_account_that_names_no_currency_is_sent_dirhams_and_never_the_riyal(): void
    {
        $account = $this->amazonAccount();
        $account->forceFill(['currency' => null, 'marketplace_ids' => [self::AMAZON_AE], 'settings' => ['region' => 'eu', 'marketplace_ids' => [self::AMAZON_AE]]])->save();
        $this->amazonAccepts();

        app(AmazonConnector::class)->publishPrices($account->fresh(), [$this->update(11, price: '49.95')]);

        $this->assertSame(['AED', 49.95], $this->amazonCurrencyAndPrice(), 'the currency of the market the account sells in, which the platform has written down');
    }

    /**
     * The last resort, kept deliberately: an account whose marketplace is one this platform has no storefront for has no
     * configured currency to read, and the riyal is what every price of such an account has been labelled with until now. It is
     * the only case left where a currency is assumed, and it is here so that changing it is a decision somebody takes on purpose.
     */
    public function test_an_account_selling_where_the_platform_has_no_storefront_is_still_sent_the_riyal(): void
    {
        $account = $this->amazonAccount();
        $account->forceFill(['currency' => null, 'marketplace_ids' => [self::AMAZON_EG], 'settings' => ['region' => 'eu', 'marketplace_ids' => [self::AMAZON_EG]]])->save();
        $this->amazonAccepts();

        app(AmazonConnector::class)->publishPrices($account->fresh(), [$this->update(11, price: '49.95')]);

        $this->assertSame(['SAR', 49.95], $this->amazonCurrencyAndPrice(), 'today\'s answer, unchanged');
    }

    private function listing(SalesChannelAccount $account): SalesChannelProductMapping
    {
        $location = app(InventoryLocationService::class)->ensureDefaults()[2];

        return SalesChannelProductMapping::create([
            'account_id' => $account->id, 'product_id' => self::PRODUCT, 'listing_key' => 'BC-1', 'external_sku' => 'SKU-1',
            'external_barcode' => 'BC-1', 'status' => 'mapped', 'stock_location_id' => $location->id,
            'inventory_publish' => false, 'price_publish' => true, 'price_source' => 'manual',
            'snapshot' => ['manual_price' => self::THREE_DECIMAL_PRICE],
        ]);
    }

    private function update(int $mappingId, string $price, ?string $listPrice = null): PublishUpdate
    {
        return new PublishUpdate($mappingId, 'SKU-1', 'SKU-1', null, null, Money::of($price), $listPrice === null ? null : Money::of($listPrice), null);
    }

    /** @return array{0: string, 1: float} the currency and the price of the patch Amazon was sent */
    private function amazonCurrencyAndPrice(): array
    {
        $requests = $this->sentTo('/listings/2021-08-01/items');
        $offer = $requests[count($requests) - 1]->data()['patches'][0]['value'][0];

        return [(string) $offer['currency'], (float) $offer['our_price'][0]['schedule'][0]['value_with_tax']];
    }

    private function amazonAccepts(): void
    {
        $this->fakeOnly([
            self::AMAZON_TOKEN => Http::response(['access_token' => 'Atza|access-token-123', 'token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => 'Atzr|refresh-token-abc']),
            self::AMAZON . '/*' => Http::sequence()
                ->push(['sku' => 'SKU-1', 'status' => 'VALID', 'issues' => []])
                ->push(['sku' => 'SKU-1', 'status' => 'ACCEPTED', 'submissionId' => 'sub-1', 'issues' => []]),
        ]);
    }

    /** noon's own shape: a sign-in that sets the session cookie, then a 200 with one entry per item, each with its own status. */
    private function noonAccepts(): void
    {
        $this->fakeOnly([
            self::NOON_LOGIN => Http::response('', 200, ['Set-Cookie' => 'noon_session=abc123; Path=/']),
            self::NOON . '/*' => Http::response(['items' => [
                ['partner_sku' => 'SKU-1', 'country_code' => 'sa', 'status' => ['code' => 'OK', 'id' => 0, 'message' => null]],
            ]]),
        ]);
    }

    /** @return array<string, mixed> */
    private function bodyOf(string $needle): array
    {
        return (array) $this->sentTo($needle)[0]->data();
    }

    /** @return list<Request> */
    private function sentTo(string $needle): array
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(static fn (array $pair): Request => $pair[0])->all();
    }

    /** A later Http::fake() adds stubs behind the earlier ones; a case that changes what a channel says starts from a clean fake. */
    private function fakeOnly(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake($stubs);
    }
}
