<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\SubmissionResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsPilotServerSchema;

/**
 * What the Torod Point warehouse is sent for the pilot server's store, whose riyal is stored as "KSA": riyal amounts, and never the store's
 * own spelling of the currency.
 */
class PilotServerTorodPayloadTest extends TorodTestCase
{
    use BuildsPilotServerSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePilotServerSchema();
    }

    private function cashOnDelivery(float $orderAmount): ProviderFulfillment
    {
        return $this->routeSingleProviderOrder(ProviderCode::TorodPoint, ['order_amount' => $orderAmount, 'payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid']);
    }

    public function test_a_rehearsed_cash_on_delivery_order_shows_riyal_amounts_and_no_trace_of_the_stores_spelling(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => true, 'company_id' => '714', 'cod_collection_method' => 'CASH']);
        $fulfillment = $this->cashOnDelivery(215.5);
        Http::fake();

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $preview = $result->meta['payload_preview'];
        $this->assertSame(SubmissionResult::DRY_RUN, $result->outcome);
        $this->assertSame('COD', $preview['shipmentType']);
        $this->assertSame('215.50', $preview['cod']);
        $this->assertEqualsWithDelta(100.0, (float) $preview['items'][0]['price'], 0.001, 'a unit of the 200-riyal, two-unit line');
        $this->assertSame(2, (int) $preview['items'][0]['quantity']);
        $this->assertStringNotContainsString('KSA', json_encode($preview));
        Http::assertNothingSent();
    }

    public function test_the_live_body_posted_to_the_warehouse_carries_the_same_riyal_amounts(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => false, 'company_id' => '714', 'cod_collection_method' => 'CASH']);
        $fulfillment = $this->cashOnDelivery(215.5);
        Http::fake([self::V5 . '/public/fulfillment/order' => Http::response(['id' => 4455, 'barcode' => 'O0015030400001'])]);

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $this->assertSame(SubmissionResult::ACCEPTED, $result->outcome);
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === self::V5 . '/public/fulfillment/order'
                && $body['shipmentType'] === 'COD'
                && $body['cod'] === '215.50'
                && abs($body['items'][0]['price'] - 100.0) < 0.001
                && !str_contains(json_encode($body), 'KSA');
        });
    }

    public function test_a_prepaid_order_asks_the_warehouse_to_collect_nothing(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => true, 'company_id' => '714']);
        $fulfillment = $this->routeSingleProviderOrder(ProviderCode::TorodPoint, ['order_amount' => 215.5]);
        Http::fake();

        $preview = $this->submitAsClaimed($this->adapter(), $fulfillment)->meta['payload_preview'];

        $this->assertSame('REGULAR', $preview['shipmentType']);
        $this->assertSame('0.00', $preview['cod']);
    }

    public function test_a_product_pushed_to_the_warehouse_carries_its_price_in_riyals(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['push_products_enabled' => true, 'dry_run' => true, 'company_id' => '714']);
        $this->makeProduct(1, null, ['name' => 'Vitamin C', 'code' => 'VC1000', 'unit_price' => 19.5]);
        Http::fake();

        $preview = $this->adapter()->pushProducts([1])['preview'];

        $this->assertSame('19.5', $preview[0]['price']);
        Http::assertNothingSent();
    }

    public function test_on_a_multi_currency_store_a_pushed_price_is_converted_with_the_text_rate(): void
    {
        $this->useStoreCurrencies(model: 'multi_currency', defaultCurrencyId: 8, rows: [1 => ['USD', '1'], 8 => ['KSA', '3.75']]);
        $this->enableProvider(ProviderCode::TorodPoint, ['push_products_enabled' => true, 'dry_run' => true, 'company_id' => '714']);
        $this->makeProduct(1, null, ['name' => 'Vitamin C', 'code' => 'VC1000', 'unit_price' => 10]);
        Http::fake();

        $preview = $this->adapter()->pushProducts([1])['preview'];

        $this->assertSame('37.5', $preview[0]['price']);
    }

    public function test_a_price_that_cannot_be_converted_is_left_out_rather_than_pushed_wrong(): void
    {
        $this->useStoreCurrencies(model: 'multi_currency', defaultCurrencyId: 8, rows: [1 => ['USD', '1'], 8 => ['KSA', '3,75']]);
        $this->enableProvider(ProviderCode::TorodPoint, ['push_products_enabled' => true, 'dry_run' => true, 'company_id' => '714']);
        $this->makeProduct(1, null, ['name' => 'Vitamin C', 'code' => 'VC1000', 'unit_price' => 10]);
        Http::fake();

        $preview = $this->adapter()->pushProducts([1])['preview'];

        $this->assertArrayNotHasKey('price', $preview[0]);
    }
}
