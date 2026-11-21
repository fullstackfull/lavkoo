<?php

namespace Tests\Feature\Storefront;

use App\Contracts\Repositories\AttributeRepositoryInterface;
use App\Http\Controllers\RestAPI\v1\AttributeController;
use App\Models\Attribute;
use App\Services\ProductService;
use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontVisibility;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsStorefrontVisibilityWorld;
use Tests\TestCase;

/**
 * A variation attribute defined for one country is offered there and nowhere else (B-12).
 *
 * Attributes are the fifth entity type of the one mapping, and they are `assigned`: an attribute with no mapping row is offered in
 * every storefront — which is what every attribute does today and what the live Saudi storefront keeps doing — and one with any row is
 * offered only where its rows name. The central `attributes` row is never touched and carries no country, so removing the mapping
 * restores the attribute everywhere.
 *
 * Two narrowings, because the surfaces are not the same:
 *
 *  - the storefront's own reads (`GET api/v1/attributes`) are narrowed by the global scope, to the storefront of the request;
 *  - the admin and vendor product forms belong to no storefront on purpose, so they name the country of the product being authored
 *    and are narrowed by {@see StorefrontVisibility::offeredIn()}.
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

    /** @return list<int> what the customer app's attribute list answers */
    private function attributeIdsOfTheApiList(): array
    {
        $attributes = app(AttributeController::class)->get_attributes()->getData(true);

        $ids = array_map(static fn (array $attribute): int => (int) $attribute['id'], $attributes);
        sort($ids);

        return $ids;
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

    public function test_the_ids_a_product_already_carries_are_read_from_its_own_row(): void
    {
        $service = app(ProductService::class);

        $this->assertSame([3, 7], $service->getProductAttributeIds(product: ['attributes' => '[3,7]']));
        $this->assertSame([], $service->getProductAttributeIds(product: ['attributes' => 'null']));
        $this->assertSame([], $service->getProductAttributeIds(product: ['attributes' => '[]']));
        $this->assertSame([], $service->getProductAttributeIds(product: []));
        $this->assertSame([], $service->getProductAttributeIds(product: null));
    }

    /** Which country each of the four product forms is filled in for. */
    public function test_a_product_form_is_filled_in_for_one_country(): void
    {
        $this->assertSame('SA', ProductCountry::countryOfForm(), 'nothing is tracked: every form is the default storefront’s');

        $this->switchStorefrontsOn();

        $this->assertSame('KW', ProductCountry::countryOfForm(requestedCountry: 'KW'), 'the admin add form: the country the form carries');
        $this->assertSame('SA', ProductCountry::countryOfForm(), 'the vendor add form: a seller product takes the table default');
        $this->assertSame('SA', ProductCountry::countryOfForm(requestedCountry: 'ZZ'), 'a country that is not configured falls back');
        $this->assertSame('KW', ProductCountry::countryOfForm(product: ['storefront_country' => 'KW']), 'an update form: the product’s own country');
        $this->assertSame('SA', ProductCountry::countryOfForm(product: ['storefront_country' => null]), 'a product with no country yet');
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
