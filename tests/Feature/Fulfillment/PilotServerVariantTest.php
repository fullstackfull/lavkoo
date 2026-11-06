<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\OrderItemOwnershipSnapshotter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsPilotServerSchema;

/**
 * How the integration treats a product's variants on the pilot server: it reads the variant from order_details.variant and the product's
 * own variation list, never from order_details.variation (the platform's copy of the chosen options, a varchar(255) there), so what is in
 * that column cannot change what a provider is sent. The column's width matters to the platform's own checkout, and is proved here.
 */
class PilotServerVariantTest extends FulfillmentTestCase
{
    use BuildsPilotServerSchema;

    private const ARABIC_SMALL = 'صغير';

    private const ARABIC_LARGE = 'كبيرجدا';

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePilotServerSchema();
    }

    private function productVariation(): string
    {
        return json_encode([
            ['type' => self::ARABIC_SMALL, 'price' => 30, 'sku' => 'TS-S', 'qty' => 4],
            ['type' => self::ARABIC_LARGE, 'price' => 32, 'sku' => 'TS-L', 'qty' => 6],
        ]);
    }

    /** What the platform writes to order_details.variation for one chosen option, as JSON with every Arabic letter escaped. */
    private function chosenOptions(int $options): string
    {
        $chosen = [];
        foreach (range(1, $options) as $number) {
            $chosen["الخيار رقم {$number}"] = 'قيمة طويلة جدا للخيار';
        }

        return json_encode($chosen);
    }

    private function insertLine(?string $variation): void
    {
        DB::table('order_details')->insert(['order_id' => 1, 'product_id' => 1, 'qty' => 1, 'variation' => $variation]);
    }

    private function storedVariation(): ?string
    {
        return DB::table('order_details')->orderByDesc('id')->value('variation');
    }

    private function requireEnforcedLengths(): void
    {
        if (!$this->engineEnforcesColumnLengths()) {
            $this->markTestSkipped('SQLite does not enforce a declared column length: the consequences are proved on MySQL and MariaDB.');
        }
    }

    public function test_an_arabic_variant_is_found_in_the_products_variation_list_and_frozen_with_its_sku_and_mapping(): void
    {
        $this->makeProduct(10, ProviderCode::M5azn);
        $this->makeOrder(1);
        $mapping = ProviderProductMapping::create([
            'provider' => 'm5azn', 'product_id' => 10, 'variant_key' => self::ARABIC_LARGE, 'external_id' => '555',
            'external_variant_id' => 'TS-L', 'external_sku' => 'TS-L', 'status' => MappingStatus::Approved,
        ]);

        $item = app(OrderItemOwnershipSnapshotter::class)->capture(
            orderId: 1,
            orderDetailId: 1,
            product: ['id' => 10, 'name' => 'T-Shirt', 'code' => 'TS', 'fulfillment_provider' => 'm5azn', 'variation' => $this->productVariation()],
            variant: self::ARABIC_LARGE,
            quantity: 1,
            unitPrice: 32.0,
            netAmount: 32.0,
            lineTax: 0.0,
            taxModel: 'include',
        );

        $this->assertSame(self::ARABIC_LARGE, $item->variant_key);
        $this->assertSame('TS-L', $item->sku, 'the variant\'s own sku, not the product\'s');
        $this->assertSame('TS-L', $item->snapshot['variation']['sku']);
        $this->assertSame($mapping->id, $item->mapping_id);
        $this->assertSame('TS-L', $item->external_sku);
    }

    public function test_a_variant_name_in_arabic_survives_the_order_line_column_it_is_stored_in(): void
    {
        $this->makeProduct(10, ProviderCode::M5azn);
        $this->makeOrder(1);

        $detailId = $this->addLine(1, 10, 1, 32, null, self::ARABIC_LARGE);

        $this->assertSame(self::ARABIC_LARGE, DB::table('order_details')->where('id', $detailId)->value('variant'));
    }

    public function test_nothing_in_the_orders_variation_column_changes_what_is_routed_to_a_provider(): void
    {
        foreach ([null, '', 'not json at all', '{"broken', str_repeat('ا', 255)] as $garbage) {
            DB::table('order_details')->delete();
            DB::table('provider_fulfillment_items')->delete();
            DB::table('provider_fulfillments')->delete();
            DB::table('orders')->delete();
            $this->makeProduct(1, ProviderCode::TorodPoint, ['id' => 1]);
            $this->makeOrder(100);
            $detailId = $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);
            DB::table('order_details')->where('id', $detailId)->update(['variation' => $garbage]);

            $created = app(FulfillmentRouter::class)->route(100);

            $this->assertCount(1, $created, 'order_details.variation = ' . json_encode($garbage));
            $this->assertEqualsWithDelta(50.0, (float) $this->fulfillmentFor(100, ProviderCode::TorodPoint)->items_total, 0.001);
            DB::table('products')->delete();
        }
    }

    public function test_a_long_set_of_chosen_options_is_cut_short_on_the_narrow_column_in_a_lenient_session(): void
    {
        $this->requireEnforcedLengths();
        $json = $this->chosenOptions(6);
        $this->assertGreaterThan(255, strlen($json));

        $this->inSqlMode(self::LENIENT_SQL_MODE, fn () => $this->insertLine($json));

        $stored = (string) $this->storedVariation();
        $this->assertSame(255, mb_strlen($stored));
        $this->assertNull(json_decode($stored), 'cut in the middle of the JSON, which can no longer be read');
    }

    public function test_a_strict_session_refuses_the_order_line_on_the_narrow_column(): void
    {
        $this->requireEnforcedLengths();

        $this->inSqlMode(self::STRICT_SQL_MODE, function (): void {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessage('Data too long');

            $this->insertLine($this->chosenOptions(6));
        });
    }

    public function test_once_the_column_is_text_the_same_options_are_stored_whole_in_either_mode(): void
    {
        $this->requireEnforcedLengths();
        $this->runCompatibilityMigrations();
        $json = $this->chosenOptions(60);
        $this->assertGreaterThan(2000, strlen($json));

        $this->insertLine($json);
        $this->assertSame($json, $this->storedVariation());

        $this->inSqlMode(self::LENIENT_SQL_MODE, fn () => $this->insertLine($json));
        $this->assertSame($json, $this->storedVariation());
        $this->assertSame($this->chosenOptions(60), json_encode(json_decode((string) $this->storedVariation(), true)));
    }
}
