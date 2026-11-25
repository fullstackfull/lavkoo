<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProductDestinationPermission as PermissionRow;
use App\Models\ProviderFulfillment;
use App\Services\Marketplace\ReturnLogisticsService;
use App\Services\Returns\CrossBorderReturn;
use App\Services\Shipping\CustomsDuty;
use App\Services\Shipping\CustomsDutyQuote;
use App\Services\Shipping\EligibilityDecision;
use App\Services\Shipping\OrderCustomsQuote;
use App\Services\Shipping\ProductDestinationPermission;
use App\Services\Shipping\RegulatedShippingGate;
use App\Services\Shipping\ShippingEligibility;
use App\Services\Shipping\ShippingRefusal;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsReturnDumpTables;

/**
 * H1-13: one order, followed from the decision at checkout to the parcel leaving or not, for every shape of order the six markets can
 * produce.
 *
 * The pieces each have their own tests. This asks the question those cannot: do they agree? A permission, a carrier's route, a regulated
 * class, a duty rule and a city's coverage are five separate facts, and an order is accepted or refused by all of them at once -- so the
 * case that matters is the one where four are in order and the fifth is not, and the shipment has to stop for the fifth and say which.
 *
 * Each case names its outcome rather than reading one back: the engine's decision, the reason when it refuses, what was quoted for duty,
 * and whether the dispatcher sent the parcel or held it and why. The decision and the dispatch are asked separately on purpose, because
 * the decision an order was taken under is a record and the dispatch re-asks: an order correctly accepted on Monday whose permission is
 * withdrawn on Tuesday must not leave on Wednesday.
 *
 * The amounts of the cross-border cases are in Kuwaiti dinars, whose third decimal is the thing most likely to be lost -- the duty on
 * 115.025 is 5.751, not 5.75.
 */
class ShippingScenarioMatrixTest extends FulfillmentTestCase
{
    use BuildsReturnDumpTables;

    private const HOME = 'SA';

    private const ABROAD = 'KW';

    /** The category the owner's lists call regulated in these scenarios. */
    private const MEDICINES = '7';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ordersCanRecordTheirMarket();
        $this->productsCanCarryTheirMarketAndClass();
        (require database_path('migrations/2026_12_20_100000_create_product_destination_permissions_table.php'))->up();
        (require database_path('migrations/2026_12_20_100300_add_clearance_reference_to_product_destination_permissions.php'))->up();
        (require database_path('migrations/2026_12_20_100400_add_customs_quote_to_orders_table.php'))->up();
        $this->createReturnDumpTables();
        $this->linesCanRecordWhetherTheirStockIsOffTheShelf();
        ProductDestinationPermission::forget();
        RegulatedShippingGate::forget();
        app(CrossBorderReturn::class)->forget();
        OrderCustomsQuote::forget();
        StorefrontCatalog::flush();

        config(['gcc.shipping.cross_border.enabled' => true]);
        $this->enableProvider(ProviderCode::TorodPoint);
    }

    /**
     * The marker a restock claims (`order_details.is_stock_decreased`), which the real table has had since 2021 and the fulfilment world's narrower one
     * does not. Without it a receipt cannot read the line's budget at all.
     */
    private function linesCanRecordWhetherTheirStockIsOffTheShelf(): void
    {
        if (!Schema::hasColumn('order_details', 'is_stock_decreased')) {
            Schema::table('order_details', fn (Blueprint $table) => $table->boolean('is_stock_decreased')->default(1));
        }
    }

    /** The two product facts these scenarios turn on: which market a product belongs to, and what it is. */
    private function productsCanCarryTheirMarketAndClass(): void
    {
        foreach ([StorefrontCatalog::COLUMN => 'string', 'category_ids' => 'text', 'brand_id' => 'string'] as $column => $type) {
            if (!Schema::hasColumn('products', $column)) {
                Schema::table('products', function (Blueprint $table) use ($column, $type): void {
                    $type === 'text' ? $table->text($column)->nullable() : $table->string($column)->nullable();
                });
            }
        }
    }

    /**
     * Every shape of order the six markets can produce, with the one expected outcome of each.
     *
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function scenarios(): array
    {
        return [
            'a Saudi order delivered in the kingdom' => [
                ['market' => self::HOME, 'destination' => self::HOME],
                ['kind' => EligibilityDecision::LOCAL, 'reasons' => [], 'duty' => CustomsDutyQuote::NONE, 'held' => null],
            ],
            'a Kuwaiti order delivered in Kuwait, in dinars' => [
                // The journey is SA to KW: the platform's own country is where the goods are, and Kuwait is where the address is. Both
                // the engine and the dispatcher read it that way now, so one declaration covers both.
                ['market' => self::ABROAD, 'destination' => self::ABROAD, 'route' => 'SA:KW'],
                ['kind' => EligibilityDecision::LOCAL, 'reasons' => [], 'duty' => CustomsDutyQuote::NONE, 'held' => null],
            ],
            'a Saudi order delivered in Kuwait, everything in order' => [
                [
                    'market' => self::HOME, 'destination' => self::ABROAD, 'route' => 'SA:KW',
                    'permit' => true, 'duty_rule' => true, 'goods' => 115.025,
                ],
                [
                    'kind' => EligibilityDecision::CROSS_BORDER, 'reasons' => [], 'duty' => CustomsDutyQuote::PAYABLE,
                    'duty_amount' => 5.751, 'held' => null,
                ],
            ],
            'the same order with no permission to leave the kingdom' => [
                ['market' => self::HOME, 'destination' => self::ABROAD, 'route' => 'SA:KW', 'permit' => false, 'duty_rule' => true],
                [
                    'kind' => EligibilityDecision::CROSS_BORDER, 'reasons' => [ShippingRefusal::NOT_PERMITTED_ABROAD],
                    'duty' => CustomsDutyQuote::PAYABLE, 'held' => HoldReason::ROUTE_UNSUPPORTED,
                ],
            ],
            'the same order on a route the carrier does not declare' => [
                // The carrier declares a route that is not the one this parcel takes, so both the engine and the dispatcher refuse it.
                ['market' => self::HOME, 'destination' => self::ABROAD, 'route' => 'BH:BH', 'permit' => true, 'duty_rule' => true],
                [
                    'kind' => EligibilityDecision::CROSS_BORDER, 'reasons' => [ShippingRefusal::NO_CARRIER_SERVES_THIS_ROUTE],
                    'duty' => CustomsDutyQuote::PAYABLE, 'held' => HoldReason::ROUTE_UNSUPPORTED,
                ],
            ],
            'a regulated line with no licence on file' => [
                [
                    'market' => self::HOME, 'destination' => self::ABROAD, 'route' => 'SA:KW',
                    'permit' => true, 'duty_rule' => true, 'regulated' => true,
                ],
                [
                    'kind' => EligibilityDecision::CROSS_BORDER, 'reasons' => [ShippingRefusal::REGULATED_NEEDS_CLEARANCE],
                    'duty' => CustomsDutyQuote::PAYABLE, 'held' => HoldReason::ROUTE_UNSUPPORTED,
                ],
            ],
            'a route whose duty nobody has written down' => [
                ['market' => self::HOME, 'destination' => self::ABROAD, 'route' => 'SA:KW', 'permit' => true, 'duty_rule' => false],
                [
                    'kind' => EligibilityDecision::CROSS_BORDER, 'reasons' => [ShippingRefusal::DUTY_NOT_QUOTED],
                    'duty' => CustomsDutyQuote::NOT_QUOTED, 'held' => HoldReason::ROUTE_UNSUPPORTED,
                ],
            ],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_the_order_is_decided_quoted_and_dispatched_as_the_scenario_says(array $given, array $expected): void
    {
        $orderId = 7100;
        $this->givenTheOwnersRules($given);
        $fulfillment = $this->anOrderOf($given, $orderId);

        $decision = app(ShippingEligibility::class)->decideForOrder($orderId);
        $quote = OrderCustomsQuote::record($orderId);
        app(\App\Services\Fulfillment\ProviderOrderDispatcher::class)->dispatch($fulfillment->id);
        $dispatched = ProviderFulfillment::findOrFail($fulfillment->id);

        $this->assertSame($expected['kind'], $decision->kind, 'the kind of order it is');
        $this->assertSame($expected['reasons'], $decision->reasons, 'why it was refused, or that it was not');
        $this->assertSame($expected['reasons'] === [], $decision->allowed);

        $this->assertSame($expected['duty'], $quote?->kind, 'what was quoted for duty');
        if (isset($expected['duty_amount'])) {
            $this->assertSame($expected['duty_amount'], $quote->amount, 'and the third decimal of the dinar survives it');
        }

        $expected['held'] === null
            ? $this->assertNotSame(FulfillmentStatus::Held, $dispatched->status, 'the parcel was not held, but was: ' . ($dispatched->hold_reason ?? '?'))
            : $this->assertSame($expected['held'], $dispatched->hold_reason, 'and the dispatcher held it for the same reason');
    }

    /** The owner's lists and rules for one scenario: the route the carrier declares, the regulated classes, the duty rule. */
    private function givenTheOwnersRules(array $given): void
    {
        config(['fulfillment.providers.torod_point.routes' => [$given['route'] ?? 'SA:SA']]);

        config([
            'gcc.shipping.regulated.mode' => ($given['regulated'] ?? false)
                ? RegulatedShippingGate::MODE_STRICT
                : RegulatedShippingGate::MODE_OFF,
            'gcc.shipping.regulated.category_ids' => [self::MEDICINES],
        ]);

        config([
            'gcc.shipping.duty.mode' => CustomsDuty::MODE_STRICT,
            'gcc.shipping.duty.routes' => ($given['duty_rule'] ?? false)
                ? ['SA:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::BUYER_ON_DELIVERY]]
                : [],
        ]);
    }

    /**
     * H1-13, criteria 3 and 4: ONE order walked the whole way -- decided, quoted for duty, dispatched, returned, received -- so that the two ends of a
     * cross-border sale are pinned by the same order and not by two tests that each assume the other's half.
     *
     * The dinar case, because the third decimal is the thing most easily lost on the way back: 115.025 of goods, 5.751 of duty, and a refund owed in the
     * currency the customer paid in rather than in whatever the platform's default is on the day the box arrives.
     */
    public function test_a_cross_border_order_walks_from_checkout_to_the_shelf_and_the_money_it_owes_back(): void
    {
        config([CrossBorderReturn::SETTING => true]);
        $orderId = 7200;
        $given = [
            'market' => self::HOME, 'destination' => self::ABROAD, 'route' => 'SA:KW',
            'permit' => true, 'duty_rule' => true, 'goods' => 115.025, 'currency' => 'KWD',
        ];
        $this->givenTheOwnersRules($given);
        $fulfillment = $this->anOrderOf($given, $orderId);
        $stockBefore = (int) DB::table('products')->where('id', 1)->value('current_stock');

        $decision = app(ShippingEligibility::class)->decideForOrder($orderId);
        $quote = OrderCustomsQuote::record($orderId);
        app(\App\Services\Fulfillment\ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $this->assertSame(EligibilityDecision::CROSS_BORDER, $decision->kind);
        $this->assertTrue($decision->allowed, 'the order was refused: ' . implode(', ', $decision->reasons));
        $this->assertSame(5.751, $quote->amount, 'the duty on the goods, to the third decimal');
        $this->assertNotSame(FulfillmentStatus::Held, ProviderFulfillment::findOrFail($fulfillment->id)->status);

        $returns = app(ReturnLogisticsService::class);
        $rma = $returns->authorize(
            data: [
                'order_id' => $orderId,
                'order_details_id' => DB::table('order_details')->where('order_id', $orderId)->value('id'),
                'product_id' => 1,
                'seller_id' => 0,
                'qty' => 1,
                'restock' => true,
            ],
            by: 1,
        );
        $received = $returns->receive(rma: $rma, by: 1);

        $this->assertTrue($received['restocked'], 'the goods came back and the shelf took them');
        $this->assertNull($received['refused']);
        $this->assertSame($stockBefore + 1, (int) DB::table('products')->where('id', 1)->value('current_stock'));
        $this->assertSame(self::HOME, $rma->fresh()->source_country, 'back on the shelf of the market that sold them');
        $this->assertSame('KWD', $rma->fresh()->refund_currency, 'owed back in the money it was paid in');
    }

    /** One routed order of one provider, in the market the scenario names and going where it says. */
    private function anOrderOf(array $given, int $orderId): ProviderFulfillment
    {
        $goods = (float) ($given['goods'] ?? 100.0);

        $fulfillment = $this->routeSingleProviderOrder(
            ProviderCode::TorodPoint,
            orderOverrides: [
                'storefront_country' => $given['market'],
                'currency_code' => $given['currency'] ?? null,
                'order_amount' => $goods,
                'shipping_address_data' => json_encode([
                    'contact_person_name' => 'Sara Alharbi', 'phone' => '0551234567', 'address' => 'King Fahd Rd 12',
                    'city' => 'Anywhere', 'country' => $given['destination'], 'email' => 'sara@example.test',
                ]),
            ],
            orderId: $orderId,
            lineTotal: $goods,
            quantity: 1,
        );

        DB::table('products')->where('id', 1)->update([
            StorefrontCatalog::COLUMN => $given['market'],
            'category_ids' => json_encode([['id' => self::MEDICINES, 'position' => 1]]),
        ]);
        StorefrontCatalog::flush();

        if ($given['permit'] ?? false) {
            PermissionRow::create([
                'product_id' => 1, 'destination_country' => $given['destination'],
                'may_ship' => true, 'may_export' => true, 'may_import' => true,
            ]);
            ProductDestinationPermission::forget();
        }

        return $fulfillment;
    }
}
