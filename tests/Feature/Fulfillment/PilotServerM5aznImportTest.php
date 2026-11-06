<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\Data\ImportResult;
use App\Services\Fulfillment\Data\SubmissionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsPilotServerSchema;

/**
 * M5AZN on the pilot server's store (a single-currency store whose riyal is "KSA", rates kept as text): what an import writes into the
 * store's product tables, and what an order sends back to M5AZN. The store's currency rows are only ever read.
 */
class PilotServerM5aznImportTest extends M5aznTestCase
{
    use BuildsPilotServerSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutImageDownloads();
        $this->usePilotServerSchema();
    }

    /** @return list<object> */
    private function currencyRows(): array
    {
        return DB::table('currencies')->orderBy('id')->get()->all();
    }

    public function test_an_import_keeps_the_providers_retail_price_leaves_the_product_unpublished_and_labels_the_cost_sar(): void
    {
        $this->stage();
        $currenciesBefore = $this->currencyRows();

        $result = $this->importer()->import($this->staged()->id, actorId: 1);

        $this->assertSame(ImportResult::IMPORTED, $result->outcome);
        $product = DB::table('products')->find($result->productId);
        $this->assertEqualsWithDelta(19.0, (float) $product->unit_price, 0.0001, 'a riyal store stores riyals');
        $this->assertSame(0, (int) $product->status, 'imported products start unpublished for a human review');
        $this->assertSame('m5azn', $product->fulfillment_provider);
        $mapping = ProviderProductMapping::where('product_id', $result->productId)->first();
        $this->assertEqualsWithDelta(12.5, (float) $mapping->supplier_cost, 0.0001);
        $this->assertSame('SAR', $mapping->supplier_cost_currency, 'never the store\'s own "KSA"');
        $this->assertEquals($currenciesBefore, $this->currencyRows(), 'an import never writes to the currency table');
    }

    public function test_a_product_the_operator_chose_to_publish_on_import_is_published_only_because_it_has_a_real_price(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['publish_on_import' => true]);
        $this->stage();

        $result = $this->importer()->import($this->staged()->id);

        $product = DB::table('products')->find($result->productId);
        $this->assertSame(1, (int) $product->status);
        $this->assertEqualsWithDelta(19.0, (float) $product->unit_price, 0.0001);
    }

    public function test_when_the_price_cannot_be_converted_the_product_has_no_price_and_stays_unpublished_even_with_publishing_on(): void
    {
        config(['fulfillment.currency_aliases' => []]);
        $this->enableProvider(ProviderCode::M5azn, ['publish_on_import' => true]);
        $this->stage();

        $result = $this->importer()->import($this->staged()->id);

        $product = DB::table('products')->find($result->productId);
        $this->assertEqualsWithDelta(0.0, (float) $product->unit_price, 0.0001);
        $this->assertSame(0, (int) $product->status, 'a product with no price is never published');
    }

    public function test_a_multi_currency_store_converts_the_retail_price_with_the_text_rate(): void
    {
        $this->useStoreCurrencies(model: 'multi_currency', defaultCurrencyId: 8, rows: [1 => ['USD', '1'], 8 => ['KSA', '3.75']]);
        $this->stage();

        $productId = $this->importer()->import($this->staged()->id)->productId;

        $this->assertEqualsWithDelta(19.0 / 3.75, (float) DB::table('products')->where('id', $productId)->value('unit_price'), 0.0001);
    }

    public function test_an_unreadable_text_rate_prices_nothing_at_a_guess_and_publishes_nothing(): void
    {
        $this->useStoreCurrencies(model: 'multi_currency', defaultCurrencyId: 8, rows: [1 => ['USD', '1'], 8 => ['KSA', '3,75']]);
        $this->enableProvider(ProviderCode::M5azn, ['publish_on_import' => true]);
        $this->stage();

        $productId = $this->importer()->import($this->staged()->id)->productId;

        $product = DB::table('products')->find($productId);
        $this->assertEqualsWithDelta(0.0, (float) $product->unit_price, 0.0001);
        $this->assertSame(0, (int) $product->status);
    }

    public function test_arabic_variant_names_are_stored_in_the_products_variation_column_and_read_back_intact(): void
    {
        $payload = $this->variantPayload([
            ['sku' => 'TS-S', 'name' => 'صغير', 'qty' => 4, 'price' => 30, 'cost' => 18],
            ['sku' => 'TS-L', 'name' => 'كبير جدا', 'qty' => 6, 'price' => 32, 'cost' => 19],
        ]);
        $this->stage([$payload], $this->variantPaths());

        $result = $this->importer()->import($this->staged('555')->id);

        $product = DB::table('products')->find($result->productId);
        $variation = json_decode((string) $product->variation, true);
        $this->assertSame(['صغير', 'كبيرجدا'], array_column($variation, 'type'), 'the platform strips the spaces from a variant\'s type');
        $this->assertSame([30.0, 32.0], array_map('floatval', array_column($variation, 'price')));
        $this->assertSame(['صغير', 'كبير جدا'], json_decode((string) $product->choice_options, true)[0]['options']);
        $this->assertSame(['صغير', 'كبيرجدا'], ProviderProductMapping::where('product_id', $result->productId)->orderBy('id')->pluck('variant_key')->all());
        $this->assertEqualsWithDelta(30.0, (float) $product->unit_price, 0.0001, 'the lowest variant price is the display price');
    }

    public function test_a_product_with_many_arabic_variants_fits_the_variation_column_the_platform_reads_it_from(): void
    {
        $variants = [];
        foreach (range(1, 40) as $number) {
            $variants[] = ['sku' => "TS-{$number}", 'name' => "حجم رقم {$number}", 'qty' => $number, 'price' => 30 + $number, 'cost' => 18];
        }
        $this->stage([$this->variantPayload($variants)], $this->variantPaths());

        $result = $this->importer()->import($this->staged('555')->id);

        $stored = (string) DB::table('products')->where('id', $result->productId)->value('variation');
        $this->assertGreaterThan(255, strlen($stored), 'more than a varchar(255) could hold');
        $this->assertCount(40, json_decode($stored, true), 'and nothing was cut short');
    }

    public function test_an_order_back_to_m5azn_carries_the_riyal_total_and_collection_in_a_body_that_names_no_currency(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['dry_run' => true, 'dispatch_enabled' => true]);
        $fulfillment = $this->routeSingleProviderOrder(ProviderCode::M5azn, ['order_amount' => 215.5, 'payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid']);
        Http::fake();

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $preview = $result->meta['payload_preview'];
        $this->assertSame(SubmissionResult::DRY_RUN, $result->outcome);
        $this->assertSame('cod', $preview['payment_method']);
        $this->assertEqualsWithDelta(215.5, (float) $preview['total'], 0.001);
        $this->assertEqualsWithDelta(215.5, (float) $preview['cod_amount'], 0.001);
        $this->assertSame(2, (int) $preview['items'][0]['qty_ordered']);
        $this->assertArrayNotHasKey('currency', $preview, 'the documented body has no currency field: M5AZN reads the amounts as riyals');
        Http::assertNothingSent();
    }
}
