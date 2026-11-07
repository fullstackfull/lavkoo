<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * Revenue is shown without tax: what is taken off it for a refund, and a channel that gave no VAT, must be on the same footing. (The platform stores amounts in its default
 * currency and the fixture rates make one stored unit worth 3.75 SAR, so a 100 line is 375.00 here.)
 */
class SaleDocumentReaderTest extends TestCase
{
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_details_id');
            $table->decimal('amount', 40, 20)->default(0);
            $table->string('status')->default('pending');
        });
    }

    private function storeOrder(int $id, string $price, string $tax, string $taxModel): void
    {
        DB::table('orders')->insert(['id' => $id, 'order_status' => 'delivered', 'payment_status' => 'paid', 'seller_is' => 'admin', 'order_amount' => 0, 'created_at' => '2026-11-05 10:00:00', 'updated_at' => '2026-11-05 10:00:00']);
        DB::table('order_details')->insert(['id' => $id * 10, 'order_id' => $id, 'product_id' => 50, 'qty' => 1, 'price' => $price, 'tax' => $tax, 'tax_model' => $taxModel]);
    }

    private function refund(int $detailId, string $amount): void
    {
        DB::table('refund_requests')->insert(['order_details_id' => $detailId, 'amount' => $amount, 'status' => 'refunded']);
    }

    public function test_a_fully_refunded_line_takes_back_its_revenue_not_its_revenue_and_its_tax(): void
    {
        $this->storeOrder(1, '100', '15', 'exclude');
        $this->refund(10, '115');

        $document = app(SaleDocumentReader::class)->forOrder(1);

        $this->assertSame('375.000000', $document->lines[0]->revenue->toStorage());
        $this->assertSame('375.000000', $document->lines[0]->refunded->toStorage(), 'The platform refunds the customer 115 (431.25 SAR); 100 (375.00 SAR) of that is revenue.');
        $this->assertSame('0.000000', $document->netRevenue()->toStorage(), 'Not -56.25.');
    }

    public function test_a_part_refund_takes_back_the_same_share_of_the_revenue(): void
    {
        $this->storeOrder(2, '80', '12', 'exclude');
        $this->refund(20, '46');

        $document = app(SaleDocumentReader::class)->forOrder(2);

        $this->assertSame('300.000000', $document->lines[0]->revenue->toStorage());
        $this->assertSame('150.000000', $document->lines[0]->refunded->toStorage(), 'Half of the 92 the customer paid is half of the revenue.');
        $this->assertSame('150.000000', $document->netRevenue()->toStorage());
    }

    public function test_a_refund_never_takes_back_more_than_the_line_earned(): void
    {
        $this->storeOrder(3, '115', '15', 'include');
        $this->refund(30, '130');

        $document = app(SaleDocumentReader::class)->forOrder(3);

        $this->assertSame('375.000000', $document->lines[0]->revenue->toStorage());
        $this->assertSame('375.000000', $document->lines[0]->refunded->toStorage(), 'The platform adds the tax to the refund again; the revenue is the most that can come back.');
        $this->assertSame('0.000000', $document->netRevenue()->toStorage());
    }

    private function channelOrder(string $externalId, string $taxTotal): ExternalOrderMapping
    {
        $account = SalesChannelAccount::first() ?? SalesChannelAccount::create(['channel' => 'amazon', 'name' => 'A']);
        $mapping = ExternalOrderMapping::create([
            'account_id' => $account->id, 'sales_channel' => 'amazon', 'seller_id' => 0, 'external_order_id' => $externalId, 'external_package_id' => '', 'status' => 'shipped',
            'currency' => 'SAR', 'prices_include_tax' => true, 'items_total' => '230.000000', 'purchased_at' => '2026-11-05 09:00:00',
        ]);
        ExternalOrderLine::create(['mapping_id' => $mapping->id, 'external_line_id' => '1', 'sku' => 'SKU-1', 'quantity' => 1, 'quantity_cancelled' => 0, 'line_total' => '230.000000', 'discount_total' => '5.000000', 'tax_total' => $taxTotal, 'currency' => 'SAR', 'status' => 'open']);

        return $mapping;
    }

    public function test_vat_inclusive_prices_that_came_without_vat_are_called_unverified_not_trusted(): void
    {
        $withoutVat = app(SaleDocumentReader::class)->forChannelOrder($this->channelOrder('171-1', '0.000000')->id);
        $withVat = app(SaleDocumentReader::class)->forChannelOrder($this->channelOrder('171-2', '29.347826')->id);

        $this->assertContains('tax_not_separated', $withoutVat->revenueNotes, 'The VAT is still inside this revenue and there is no rate here to take it out.');
        $this->assertNotContains('tax_not_separated', $withVat->revenueNotes);
        $this->assertSame('195.652174', $withVat->netRevenue()->toStorage(), '230 - 5 - 29.347826 VAT.');
    }
}
