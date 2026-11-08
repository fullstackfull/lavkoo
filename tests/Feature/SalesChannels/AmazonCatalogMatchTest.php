<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\CatalogMatch;
use App\Services\SalesChannels\Listings\ListingCatalogLookup;
use App\Services\SalesChannels\Listings\ListingDraftService;
use App\Services\SalesChannels\Listings\ListingOutcome;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * Asking AMAZON'S OWN CATALOGUE what it already holds for one of our barcodes — and keeping the four possible answers apart.
 *
 * The call earns its keep by bringing back Amazon's `productType`, the one string without which the requirements of a listing cannot be asked for. But the reason this file is
 * long is the OTHER thing: three of the four answers must never be read as the fourth.
 *
 *  - one ASIN      → matched, and the draft may learn the product type from it;
 *  - several       → a person chooses; a machine must not, because the wrong ASIN is another seller's product and a listing written against it edits their offer;
 *  - a real zero   → nothing is there, and that is a fact to act on;
 *  - unreachable   → **we do not know**, which is not the same fact at all.
 *
 * The last distinction is the whole file. Every fixture here is shaped after the published Catalog Items 2022-04-01 models, stray requests are refused, and nothing proves a
 * live seller account works.
 */
class AmazonCatalogMatchTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const LWA = 'https://api.amazon.com/auth/o2/token';
    private const SP = 'https://sellingpartnerapi-eu.amazon.com';
    private const CATALOG = self::SP . '/catalog/2022-04-01/items*';
    private const PRODUCT = 90;
    private const BARCODE = '5901234123457';
    private const TYPE = 'HEALTH_PERSONAL_CARE';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 09:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->account = $this->amazonAccount();
        $this->storeProduct(self::PRODUCT, provider: null);
        DB::table('products')->where('id', self::PRODUCT)->update(['barcode' => self::BARCODE]);
    }

    // ---- the four answers ---------------------------------------------------------------------------------------

    public function test_one_asin_for_our_barcode_is_a_match_and_teaches_the_draft_amazons_product_type(): void
    {
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([$this->item('B07N4M94X4', self::TYPE)]))]);
        $draft = $this->draft();
        $this->assertSame('', (string) $draft->product_type, 'the draft starts without a product type, which is what makes the requirements unaskable');

        $match = app(ListingCatalogLookup::class)->identify($draft);

        $this->assertSame(CatalogMatch::MATCHED, $match->state);
        $this->assertSame('B07N4M94X4', $match->theOne()?->externalId);
        $this->assertSame(self::TYPE, $match->theOne()?->productType);
        $this->assertFalse($match->couldNotAsk());
        $this->assertFalse($match->saysNothingIsThere());

        $draft->refresh();
        $this->assertSame(self::TYPE, (string) $draft->product_type, 'the one candidate filled the empty field, so nobody has to guess the one string the requirements call needs');
        $this->assertSame('B07N4M94X4', (string) $draft->catalog_external_id);
        $this->assertNull($draft->requirements_read_at, 'the requirements were read for the old product type and say nothing about this one');
    }

    public function test_two_asins_for_one_barcode_are_never_chosen_between_by_the_code(): void
    {
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([
            $this->item('B07N4M94X4', self::TYPE),
            $this->item('B08AAAAAAA', 'BEAUTY'),
        ]))]);
        $draft = $this->draft();

        $match = app(ListingCatalogLookup::class)->identify($draft);

        $this->assertSame(CatalogMatch::SEVERAL, $match->state);
        $this->assertTrue($match->needsAPerson());
        $this->assertNull($match->theOne(), 'with two candidates there is no "the one"');
        $this->assertCount(2, $match->candidates);

        $draft->refresh();
        $this->assertSame('', (string) $draft->product_type, 'neither candidate may fill the field: the wrong ASIN is another seller\'s product');
        $this->assertNull($draft->catalog_external_id);
    }

    public function test_a_catalogue_that_answers_and_holds_nothing_is_a_definite_zero(): void
    {
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([]))]);

        $match = app(ListingCatalogLookup::class)->identify($this->draft());

        $this->assertSame(CatalogMatch::NONE, $match->state);
        $this->assertTrue($match->saysNothingIsThere(), 'asked, answered, holds nothing: this is the one answer a caller may create on');
        $this->assertFalse($match->couldNotAsk());
        $this->assertNotNull($match->readAt);
    }

    /** The test this whole class exists for. */
    public function test_a_catalogue_that_could_not_be_reached_is_not_a_catalogue_that_holds_nothing(): void
    {
        $this->fake([self::CATALOG => Http::response(['errors' => [['message' => 'Too many requests']]], 429)]);

        $match = app(ListingCatalogLookup::class)->identify($this->draft());

        $this->assertSame(CatalogMatch::UNKNOWN, $match->state);
        $this->assertTrue($match->couldNotAsk());
        $this->assertFalse($match->saysNothingIsThere(), 'a rate-limited search answered no items, and reading THAT as an empty catalogue is how a listed product gets listed twice');
        $this->assertSame('channel_unreachable', $match->reason);
        $this->assertSame([], $match->candidates);
    }

    public function test_a_server_error_is_also_unknown_and_not_a_zero(): void
    {
        $this->fake([self::CATALOG => Http::response('', 503)]);

        $match = app(ListingCatalogLookup::class)->identify($this->draft());

        $this->assertTrue($match->couldNotAsk());
        $this->assertFalse($match->saysNothingIsThere());
    }

    public function test_with_the_writes_switched_off_the_rehearsal_is_unknown_not_a_zero(): void
    {
        config(['sales_channels.external_writes_enabled' => true]);
        $this->fake([]);
        // A read of the catalogue needs the product-sync capability; without it the transport never sends the call.
        $this->switchOn($this->account, capabilities: [], writes: false);

        $match = app(ListingCatalogLookup::class)->identify($this->draft());

        $this->assertTrue($match->couldNotAsk(), 'a call the transport refused tells us nothing about Amazon\'s catalogue');
        $this->assertFalse($match->saysNothingIsThere());
        Http::assertNothingSent();
    }

    // ---- which identifiers are offered, and under what kind ------------------------------------------------------

    public function test_a_thirteen_digit_barcode_is_asked_as_an_ean(): void
    {
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([$this->item('B07N4M94X4', self::TYPE)]))]);

        app(ListingCatalogLookup::class)->identify($this->draft());

        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), '/catalog/2022-04-01/items')
                && $request->data()['identifiersType'] === 'EAN'
                && $request->data()['identifiers'] === self::BARCODE;
        });
    }

    public function test_a_twelve_digit_barcode_is_asked_as_a_upc(): void
    {
        DB::table('products')->where('id', self::PRODUCT)->update(['barcode' => '012345678905']);
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([$this->item('B07N4M94X4', self::TYPE)]))]);

        app(ListingCatalogLookup::class)->identify($this->draft());

        Http::assertSent(static fn ($request): bool => ($request->data()['identifiersType'] ?? null) === 'UPC');
    }

    public function test_a_barcode_of_no_known_shape_is_never_offered_under_a_kind_it_is_not(): void
    {
        DB::table('products')->where('id', self::PRODUCT)->update(['barcode' => '12345']);
        $this->fake([]);

        $match = app(ListingCatalogLookup::class)->identify($this->draft());

        $this->assertTrue($match->couldNotAsk());
        $this->assertSame('no_identifier_the_catalogue_knows', $match->reason);
        Http::assertNothingSent();
    }

    public function test_a_product_with_no_barcode_at_all_says_so_rather_than_asking(): void
    {
        DB::table('products')->where('id', self::PRODUCT)->update(['barcode' => '']);
        $this->fake([]);

        $match = app(ListingCatalogLookup::class)->identify($this->draft());

        $this->assertTrue($match->couldNotAsk());
        $this->assertSame('product_has_no_barcode_or_code', $match->reason);
        Http::assertNothingSent();
    }

    // ---- what is kept, and what is asked again -------------------------------------------------------------------

    public function test_the_answer_is_kept_on_the_draft_so_a_screen_does_not_spend_the_quota_twice(): void
    {
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([$this->item('B07N4M94X4', self::TYPE)]))]);
        $draft = $this->draft();
        app(ListingCatalogLookup::class)->identify($draft);

        $again = app(ListingCatalogLookup::class)->identify($draft->refresh());

        $this->assertSame(CatalogMatch::MATCHED, $again->state);
        $this->assertSame('B07N4M94X4', $again->theOne()?->externalId);
        Http::assertSentCount(2, 'the token and the one search: the second identify read what was stored');
    }

    public function test_a_stored_unknown_is_asked_again_rather_than_frozen_for_a_week(): void
    {
        $this->fake([self::CATALOG => Http::response('', 503)]);
        $draft = $this->draft();
        $this->assertTrue(app(ListingCatalogLookup::class)->identify($draft)->couldNotAsk());

        $this->fake([self::CATALOG => Http::response($this->catalogHolding([$this->item('B07N4M94X4', self::TYPE)]))]);
        $match = app(ListingCatalogLookup::class)->identify($draft->refresh());

        $this->assertSame(CatalogMatch::MATCHED, $match->state, 'the answer that stops everybody from listing is the one that must not be cached');
    }

    public function test_an_answer_older_than_the_window_is_asked_again(): void
    {
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([$this->item('B07N4M94X4', self::TYPE)]))]);
        $draft = $this->draft();
        app(ListingCatalogLookup::class)->identify($draft);

        $this->travel((int) config('sales_channels.listing.catalog_fresh_hours') + 1)->hours();
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([]))]);
        $match = app(ListingCatalogLookup::class)->identify($draft->refresh());

        $this->assertSame(CatalogMatch::NONE, $match->state);
    }

    // ---- a product type somebody chose is never overwritten ------------------------------------------------------

    public function test_a_product_type_a_person_chose_is_not_overwritten_by_the_catalogue(): void
    {
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([$this->item('B07N4M94X4', 'BEAUTY')]))]);
        $draft = $this->draft(productType: self::TYPE);

        app(ListingCatalogLookup::class)->identify($draft);

        $draft->refresh();
        $this->assertSame(self::TYPE, (string) $draft->product_type, 'the catalogue proposes; it does not overrule a person');
    }

    public function test_choosing_a_candidate_records_the_disagreement_rather_than_resolving_it(): void
    {
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([
            $this->item('B07N4M94X4', 'BEAUTY'),
            $this->item('B08AAAAAAA', 'LUGGAGE'),
        ]))]);
        $draft = $this->draft(productType: self::TYPE);
        app(ListingCatalogLookup::class)->identify($draft);

        $outcome = app(ListingCatalogLookup::class)->choose($draft->refresh(), 'B07N4M94X4', adminId: 1);

        $this->assertSame(ListingOutcome::VALIDATED, $outcome->state);
        $this->assertContains('catalog_product_type_differs', $outcome->problems);
        $draft->refresh();
        $this->assertSame('B07N4M94X4', (string) $draft->catalog_external_id);
        $this->assertSame(self::TYPE, (string) $draft->product_type);
    }

    public function test_choosing_a_candidate_fills_a_product_type_the_draft_does_not_have(): void
    {
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([
            $this->item('B07N4M94X4', 'BEAUTY'),
            $this->item('B08AAAAAAA', 'LUGGAGE'),
        ]))]);
        $draft = $this->draft();
        app(ListingCatalogLookup::class)->identify($draft);

        app(ListingCatalogLookup::class)->choose($draft->refresh(), 'B08AAAAAAA', adminId: 1);

        $this->assertSame('LUGGAGE', (string) $draft->refresh()->product_type);
    }

    public function test_a_candidate_the_catalogue_never_offered_is_refused(): void
    {
        // Two candidates, so nothing was filled in for us: this is the case where a bogus choice could do real harm by writing an id Amazon never named.
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([
            $this->item('B07N4M94X4', self::TYPE),
            $this->item('B08AAAAAAA', 'BEAUTY'),
        ]))]);
        $draft = $this->draft();
        app(ListingCatalogLookup::class)->identify($draft);
        $this->assertNull($draft->refresh()->catalog_external_id);

        $outcome = app(ListingCatalogLookup::class)->choose($draft, 'B0SOMETHINGELSE', adminId: 1);

        $this->assertSame(ListingOutcome::REFUSED, $outcome->state);
        $this->assertSame('catalog_candidate_not_offered', $outcome->reason);
        $this->assertNull($draft->refresh()->catalog_external_id, 'an id the catalogue never named is never written');
    }

    public function test_a_single_match_records_its_id_and_a_later_bogus_choice_does_not_replace_it(): void
    {
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([$this->item('B07N4M94X4', self::TYPE)]))]);
        $draft = $this->draft();
        app(ListingCatalogLookup::class)->identify($draft);
        $this->assertSame('B07N4M94X4', (string) $draft->refresh()->catalog_external_id, 'one candidate and one only: recording it is safe');

        $outcome = app(ListingCatalogLookup::class)->choose($draft, 'B0SOMETHINGELSE', adminId: 1);

        $this->assertSame(ListingOutcome::REFUSED, $outcome->state);
        $this->assertSame('B07N4M94X4', (string) $draft->refresh()->catalog_external_id, 'the refused choice left the real one alone');
    }

    public function test_choosing_before_the_catalogue_was_ever_asked_is_refused(): void
    {
        $outcome = app(ListingCatalogLookup::class)->choose($this->draft(), 'B07N4M94X4', adminId: 1);

        $this->assertSame(ListingOutcome::REFUSED, $outcome->state);
        $this->assertSame('catalog_not_read_yet', $outcome->reason);
    }

    // ---- the marketplace the answer belongs to -------------------------------------------------------------------

    public function test_the_product_type_is_read_from_our_marketplace_and_not_another(): void
    {
        $item = [
            'asin' => 'B07N4M94X4',
            'productTypes' => [
                ['marketplaceId' => 'A1PA6795UKMFR9', 'productType' => 'LUGGAGE'],
                ['marketplaceId' => 'A17E79C6D8DWNP', 'productType' => self::TYPE],
            ],
            'summaries' => [
                ['marketplaceId' => 'A1PA6795UKMFR9', 'itemName' => 'German listing', 'brand' => 'Wrong'],
                ['marketplaceId' => 'A17E79C6D8DWNP', 'itemName' => 'Vitamin C 500mg', 'brand' => 'Right'],
            ],
        ];
        $this->fake([self::CATALOG => Http::response($this->catalogHolding([$item]))]);

        $match = app(ListingCatalogLookup::class)->identify($this->draft());

        $this->assertSame(self::TYPE, $match->theOne()?->productType, 'the requirements of a German listing are not the requirements of the one we are making');
        $this->assertSame('Right', $match->theOne()?->brand);
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $responses */
    private function fake(array $responses): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake([self::LWA => Http::response(['access_token' => 'Atza|token', 'token_type' => 'bearer', 'expires_in' => 3600])] + $responses);
    }

    private function draft(?string $productType = null): ChannelListingDraft
    {
        return app(ListingDraftService::class)->draftFor($this->account, self::PRODUCT, productType: $productType);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function catalogHolding(array $items): array
    {
        return ['numberOfResults' => count($items), 'items' => $items, 'pagination' => []];
    }

    /** @return array<string, mixed> one `items[]` element in the published shape: everything keyed by marketplace. */
    private function item(string $asin, string $productType): array
    {
        return [
            'asin' => $asin,
            'identifiers' => [['marketplaceId' => 'A17E79C6D8DWNP', 'identifiers' => [['identifier' => self::BARCODE, 'identifierType' => 'EAN']]]],
            'images' => [['marketplaceId' => 'A17E79C6D8DWNP', 'images' => [['variant' => 'MAIN', 'link' => 'https://m.media-amazon.com/images/I/' . $asin . '.jpg']]]],
            'productTypes' => [['marketplaceId' => 'A17E79C6D8DWNP', 'productType' => $productType]],
            'summaries' => [['marketplaceId' => 'A17E79C6D8DWNP', 'itemName' => 'Vitamin C 500mg', 'brand' => 'Acme', 'itemClassification' => 'BASE_PRODUCT']],
        ];
    }
}
