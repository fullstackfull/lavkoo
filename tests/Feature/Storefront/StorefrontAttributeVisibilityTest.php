<?php

namespace Tests\Feature\Storefront;

use App\Contracts\Repositories\AttributeRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Http\Controllers\RestAPI\v1\AttributeController;
use App\Models\Attribute;
use App\Services\ProductService;
use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsStorefrontVisibilityWorld;
use Tests\TestCase;

/**
 * A variation attribute defined for one country is offered there and nowhere else (B-12).
 *
 * Attributes are the ninth entity type of the one mapping, and they are `assigned`: an attribute with no mapping row is offered in
 * every storefront — which is what every attribute does today and what the live Saudi storefront keeps doing — and one with any row is
 * offered only where its rows name. The central `attributes` row is never touched and carries no country, so removing the mapping
 * restores the attribute everywhere.
 *
 * Two narrowings, because the surfaces are not the same:
 *
 *  - the storefront's own reads (`GET api/v1/attributes` with no country named) are narrowed by the global scope, to the storefront of
 *    the request;
 *  - the admin and vendor product forms belong to no storefront on purpose, so they name the country of the product being authored
 *    and are narrowed by {@see StorefrontVisibility::offeredIn()};
 *  - the same endpoint narrows to a country the caller names (`?storefront_country=KW`), for the fifth product form — the seller
 *    app's add-product screen, which authors a product in one country over a request served from another storefront. Only a
 *    storefront the caller can already be served from may be named, so naming one discloses no catalogue it could not already read.
 */
class StorefrontAttributeVisibilityTest extends TestCase
{
    use BuildsStorefrontVisibilityWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontVisibilityWorld();
        $this->seedStorefrontAttributes();
    }

    /**
     * @param string|null $namedCountry the `storefront_country` the caller names, or null for the unnamed call the apps make today
     * @return list<int> what the app's attribute list answers
     */
    private function attributeIdsOfTheApiList(?string $namedCountry = null): array
    {
        $query = $namedCountry === null ? '' : '?' . StorefrontCatalog::COLUMN . '=' . urlencode($namedCountry);
        $attributes = app(AttributeController::class)
            ->get_attributes(request: Request::create('http://localhost/api/v1/attributes' . $query, 'GET'))
            ->getData(true);

        $ids = array_map(static fn (array $attribute): int => (int) $attribute['id'], $attributes);
        sort($ids);

        return $ids;
    }

    /**
     * A product as the four panel forms hand it over: the Eloquent row `ProductRepository::getFirstWhereWithoutGlobalScope()` returns,
     * not an array standing in for one.
     */
    private function productAsTheFormReadsIt(int $id, ?string $attributes, string $country = 'SA'): Model
    {
        $this->insertProduct(id: $id, name: 'Product ' . $id, extra: [StorefrontCatalog::COLUMN => $country, 'attributes' => $attributes]);

        return app(ProductRepositoryInterface::class)->getFirstWhereWithoutGlobalScope(params: ['id' => $id]);
    }

    /**
     * @param list<int> $alwaysOffered
     * @return list<int> what a product form in this country offers
     */
    private function attributeIdsOfferedOnTheFormFor(string $country, array $alwaysOffered = []): array
    {
        $ids = app(AttributeRepositoryInterface::class)
            ->getListOfferedIn(storefrontCountry: $country, alwaysOffered: $alwaysOffered, orderBy: ['id' => 'asc'], dataLimit: 'all')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        sort($ids);

        return $ids;
    }

    private function assignAttributeTo(string ...$countries): void
    {
        StorefrontVisibility::set(entityType: StorefrontVisibility::ATTRIBUTE, entityId: self::MAPPED_ATTRIBUTE, countries: $countries);
    }

    public function test_an_attribute_with_no_mapping_is_offered_everywhere_with_the_switch_off(): void
    {
        $both = [self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE];

        $this->serveStorefront('SA');
        $this->assertSame($both, $this->attributeIdsOfTheApiList());
        $this->assertSame($both, $this->attributeIdsOfferedOnTheFormFor('SA'));
        $this->assertSame($both, $this->attributeIdsOfferedOnTheFormFor('KW'));

        $this->serveStorefront('KW');
        $this->assertSame($both, $this->attributeIdsOfTheApiList());

        $this->assertFalse(StorefrontVisibility::isTracked());
    }

    public function test_an_attribute_with_no_mapping_is_offered_everywhere_with_the_switch_on(): void
    {
        $this->switchStorefrontsOn();
        $both = [self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE];

        $this->serveStorefront('SA');
        $this->assertSame($both, $this->attributeIdsOfTheApiList());

        $this->serveStorefront('KW');
        $this->assertSame($both, $this->attributeIdsOfTheApiList());

        $this->assertSame($both, $this->attributeIdsOfferedOnTheFormFor('SA'));
        $this->assertSame($both, $this->attributeIdsOfferedOnTheFormFor('KW'));
        $this->assertSame(0, DB::table(StorefrontVisibility::TABLE)->where('entity_type', StorefrontVisibility::ATTRIBUTE)->count());
    }

    public function test_an_attribute_assigned_to_kuwait_is_offered_on_kuwaits_product_form_and_not_on_saudis(): void
    {
        $this->switchStorefrontsOn();
        $this->assignAttributeTo('KW');

        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfferedOnTheFormFor('SA'));
        $this->assertSame([self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE], $this->attributeIdsOfferedOnTheFormFor('KW'));
    }

    public function test_a_local_attribute_never_reaches_another_storefronts_attribute_list(): void
    {
        $this->switchStorefrontsOn();
        $this->assignAttributeTo('KW');

        $this->serveStorefront('SA');
        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfTheApiList());

        $this->serveStorefront('KW');
        $this->assertSame([self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE], $this->attributeIdsOfTheApiList());
    }

    /** Warmed as one storefront, read as another, both directions: the per-process memo must not answer for the wrong country. */
    public function test_a_storefront_is_never_served_the_other_storefronts_attribute_list(): void
    {
        $this->switchStorefrontsOn();
        $this->assignAttributeTo('KW');

        $this->serveStorefront('SA');
        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfTheApiList());
        $this->serveStorefront('KW');
        $this->assertSame([self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE], $this->attributeIdsOfTheApiList());

        Cache::flush();

        $this->serveStorefront('KW');
        $this->assertSame([self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE], $this->attributeIdsOfTheApiList());
        $this->serveStorefront('SA');
        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfTheApiList());
    }

    public function test_the_central_row_is_untouched_and_removing_the_mapping_restores_the_attribute(): void
    {
        $this->switchStorefrontsOn();
        $centralRow = (array) DB::table('attributes')->where('id', self::MAPPED_ATTRIBUTE)->first();

        $this->assignAttributeTo('KW');
        $this->assertSame($centralRow, (array) DB::table('attributes')->where('id', self::MAPPED_ATTRIBUTE)->first());
        $this->assertSame(['KW'], StorefrontVisibility::storefrontsOf(entityType: StorefrontVisibility::ATTRIBUTE, entityId: self::MAPPED_ATTRIBUTE));

        $this->assignAttributeTo();

        $this->serveStorefront('SA');
        $this->assertSame([self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE], $this->attributeIdsOfTheApiList());
        $this->assertSame([self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE], $this->attributeIdsOfferedOnTheFormFor('SA'));
        $this->assertSame($centralRow, (array) DB::table('attributes')->where('id', self::MAPPED_ATTRIBUTE)->first());
        $this->assertSame(0, DB::table(StorefrontVisibility::TABLE)->where('entity_type', StorefrontVisibility::ATTRIBUTE)->count());
    }

    /** A product defined before the mapping keeps the attributes it already carries, so adding a mapping never silently strips a variation. */
    public function test_the_form_keeps_offering_an_attribute_the_product_already_carries(): void
    {
        $this->switchStorefrontsOn();
        $this->assignAttributeTo('KW');

        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfferedOnTheFormFor('SA'));
        $this->assertSame(
            [self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE],
            $this->attributeIdsOfferedOnTheFormFor('SA', alwaysOffered: [self::MAPPED_ATTRIBUTE]),
        );
    }

    /** Read from the Eloquent row the two update forms hand over, which is what their repository returns. */
    public function test_the_ids_a_product_already_carries_are_read_from_its_own_row(): void
    {
        $service = app(ProductService::class);

        $this->assertSame([3, 7], $service->getProductAttributeIds(product: $this->productAsTheFormReadsIt(id: 1, attributes: '[3,7]')));
        $this->assertSame([], $service->getProductAttributeIds(product: $this->productAsTheFormReadsIt(id: 2, attributes: 'null')));
        $this->assertSame([], $service->getProductAttributeIds(product: $this->productAsTheFormReadsIt(id: 3, attributes: '[]')));
        $this->assertSame([], $service->getProductAttributeIds(product: $this->productAsTheFormReadsIt(id: 4, attributes: null)));
        $this->assertSame([], $service->getProductAttributeIds(product: null));
    }

    /** Which country each of the four product forms is filled in for, from the Eloquent row the two update forms hand over. */
    public function test_a_product_form_is_filled_in_for_one_country(): void
    {
        $saudiProduct = $this->productAsTheFormReadsIt(id: 1, attributes: '[]', country: 'SA');
        $kuwaitiProduct = $this->productAsTheFormReadsIt(id: 2, attributes: '[]', country: 'KW');
        // A product of a database whose country migration has not run: the column is not on the row the form reads.
        $productWithNoCountryColumn = app(ProductRepositoryInterface::class)->getFirstWhereWithoutGlobalScope(params: ['id' => 1]);
        $productWithNoCountryColumn->offsetUnset(StorefrontCatalog::COLUMN);

        $this->assertSame('SA', ProductCountry::countryOfForm(), 'nothing is tracked: every form is the default storefront’s');
        $this->assertSame('SA', ProductCountry::countryOfForm(product: $kuwaitiProduct), 'and that holds for a product of another country too');

        $this->switchStorefrontsOn();

        $this->assertSame('KW', ProductCountry::countryOfForm(requestedCountry: 'KW'), 'the admin add form: the country the form carries');
        $this->assertSame('SA', ProductCountry::countryOfForm(), 'the vendor add form: a seller product takes the table default');
        $this->assertSame('SA', ProductCountry::countryOfForm(requestedCountry: 'ZZ'), 'a country that is not configured falls back');
        $this->assertSame('KW', ProductCountry::countryOfForm(product: $kuwaitiProduct), 'an update form: the product’s own country');
        $this->assertSame('SA', ProductCountry::countryOfForm(product: $saudiProduct), 'and the Saudi product’s form is Saudi');
        $this->assertSame('SA', ProductCountry::countryOfForm(product: $productWithNoCountryColumn), 'a product row with no country column');
    }

    public function test_the_panel_and_the_seller_apis_see_every_attribute(): void
    {
        $this->switchStorefrontsOn();
        $this->assignAttributeTo('KW');

        $this->serveNoStorefront();

        $ids = Attribute::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $this->assertSame([self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE], $ids);
    }

    public function test_a_country_that_is_not_configured_narrows_nothing(): void
    {
        $this->switchStorefrontsOn();
        $this->assignAttributeTo('KW');

        $this->assertSame([self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE], $this->attributeIdsOfferedOnTheFormFor('ZZ'));
        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfferedOnTheFormFor(' sa '), 'but a spelling of a configured country still narrows');
        $this->assertSame([self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE], $this->attributeIdsOfferedOnTheFormFor('kw'));
    }

    /** The named country is the one answer, whatever storefront the panel request happens to look like to the resolver. */
    public function test_naming_a_country_is_not_ANDed_with_the_storefront_of_the_request(): void
    {
        $this->switchStorefrontsOn();
        $this->assignAttributeTo('KW');

        $this->serveStorefront('SA');
        $this->assertSame([self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE], $this->attributeIdsOfferedOnTheFormFor('KW'));

        $this->serveStorefront('KW');
        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfferedOnTheFormFor('SA'));
    }

    /**
     * The fifth product form: the seller app's add-product screen reads this same endpoint, so it must be able to ask what the country
     * of the product it is authoring offers, not what the storefront serving the request offers. Four mapping corners over two
     * storefronts, with a third market open to show that an attribute assigned to one country reaches none of the others.
     */
    public function test_the_app_list_answers_the_named_countrys_catalogue_and_never_ands_it_with_the_requests(): void
    {
        $this->switchStorefrontsOn();
        config(['gcc.storefronts.countries.QA.active' => true]);
        $this->assignAttributeTo('KW');
        $both = [self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE];

        $this->serveStorefront('SA');
        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfTheApiList(), 'no country named: what this storefront offers, as today');
        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfTheApiList('SA'));
        $this->assertSame($both, $this->attributeIdsOfTheApiList('KW'), 'authoring a Kuwaiti product over the Saudi storefront');
        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfTheApiList('QA'), 'a third market offers only the unassigned attribute');

        $this->serveStorefront('KW');
        $this->assertSame($both, $this->attributeIdsOfTheApiList());
        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfTheApiList('SA'), 'the named country is the one answer');
        $this->assertSame($both, $this->attributeIdsOfTheApiList('KW'));
        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfTheApiList('QA'));
    }

    /**
     * A caller may name only a storefront it could already be served from, so a named answer is exactly the answer that storefront
     * gives it and the catalogue a market prepares before it opens stays its own. AE is configured and not active, so neither
     * `?storefront=AE` nor `?storefront_country=AE` can reach it.
     */
    public function test_a_market_that_is_not_open_yet_keeps_its_catalogue_to_itself(): void
    {
        $this->switchStorefrontsOn();
        StorefrontVisibility::set(entityType: StorefrontVisibility::ATTRIBUTE, entityId: self::MAPPED_ATTRIBUTE, countries: ['AE']);

        $this->serveStorefront('SA');
        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfTheApiList(), 'the attribute prepared for AE is not in the Saudi list');
        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfTheApiList('AE'), 'and naming AE does not disclose it');
        $this->assertNotContains('AE', StorefrontResolver::fromConfig()->reachableCountries());
    }

    /** Anything that is not a storefront the caller can be served from answers the unnamed question, so a stale code never empties the list. */
    public function test_a_value_that_names_no_reachable_storefront_answers_the_unnamed_question(): void
    {
        $this->switchStorefrontsOn();
        $this->assignAttributeTo('KW');

        $this->serveStorefront('KW');
        $both = [self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE];

        foreach (['', '   ', 'ZZ', 'S', 'sa-kw'] as $value) {
            $this->assertSame($both, $this->attributeIdsOfTheApiList($value), "[{$value}] names no reachable storefront");
        }

        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfTheApiList(' sa '), 'but a loose spelling of an open storefront still names it');
        $this->assertSame($both, $this->attributeIdsOfTheApiList('kw'));
    }

    /** With the switch off the endpoint answers the whole list whether or not a country is named: naming one cannot wake the mechanism. */
    public function test_naming_a_country_changes_nothing_while_the_mechanism_is_dormant(): void
    {
        $both = [self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE];

        $this->serveStorefront('SA');
        $this->assertSame($both, $this->attributeIdsOfTheApiList());
        $this->assertSame($both, $this->attributeIdsOfTheApiList('SA'));
        $this->assertSame($both, $this->attributeIdsOfTheApiList('KW'));
        $this->assertSame(['SA'], StorefrontResolver::fromConfig()->reachableCountries(), 'only the default storefront can be reached while they are off');
        $this->assertFalse(StorefrontVisibility::isTracked());
    }

    public function test_nothing_is_narrowed_before_the_mapping_migration_has_run(): void
    {
        $this->switchStorefrontsOn();
        $this->assignAttributeTo('KW');
        $this->storefrontVisibilityMigration()->down();
        StorefrontVisibility::flush();

        $this->serveStorefront('SA');
        $this->assertSame([self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE], $this->attributeIdsOfTheApiList());
        $this->assertSame([self::SHARED_ATTRIBUTE, self::MAPPED_ATTRIBUTE], $this->attributeIdsOfferedOnTheFormFor('SA'));
    }

    public function test_the_operator_command_writes_an_attribute_mapping(): void
    {
        $this->switchStorefrontsOn();

        $this->artisan('storefront:visibility', ['entity-type' => 'attribute', 'entity-id' => self::MAPPED_ATTRIBUTE, 'storefronts' => ['KW']])
            ->expectsOutputToContain('assigned to KW')
            ->assertExitCode(0);

        $this->assertSame([self::SHARED_ATTRIBUTE], $this->attributeIdsOfferedOnTheFormFor('SA'));
    }
}
