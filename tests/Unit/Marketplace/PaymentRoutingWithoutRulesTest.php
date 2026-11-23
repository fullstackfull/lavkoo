<?php

namespace Tests\Unit\Marketplace;

use App\Services\Marketplace\PaymentRoutingService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Checkout gateways while no routing rule exists.
 *
 * `resolve()` documents the non-breaking contract: gateways come back unchanged when no rule matches
 * (app/Services/Marketplace/PaymentRoutingService.php:12-15). Checkout routes its payment list through it
 * (app/Http/Controllers/Web/WebController.php:509). No migration creates payment_routing_rules, so these expectations
 * describe the baseline only while that table is absent. If it appears, the rules path needs its own owner-approved
 * characterization.
 */
class PaymentRoutingWithoutRulesTest extends TestCase
{
    private PaymentRoutingService $routing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->routing = new PaymentRoutingService();
    }

    public function test_the_routing_rules_table_is_absent_from_the_baseline_schema(): void
    {
        $this->assertFalse(Schema::hasTable('payment_routing_rules'));
    }

    public function test_gateway_codes_come_back_in_the_order_they_were_offered(): void
    {
        $this->assertSame(
            ['myfatoorah', 'cash_on_delivery', 'wallet'],
            $this->routing->resolve(availableGateways: ['myfatoorah', 'cash_on_delivery', 'wallet']),
        );
    }

    public function test_a_gateway_offered_twice_is_listed_once_at_its_first_position(): void
    {
        $this->assertSame(
            ['cash_on_delivery', 'myfatoorah'],
            $this->routing->resolve(availableGateways: ['cash_on_delivery', 'myfatoorah', 'cash_on_delivery']),
        );
    }

    public function test_the_amount_and_the_country_do_not_change_the_list_without_rules(): void
    {
        $this->assertSame(
            ['myfatoorah'],
            $this->routing->resolve(availableGateways: ['myfatoorah'], amount: 25000.0, country: 'SA'),
        );
    }

    public function test_no_offered_gateways_resolve_to_none(): void
    {
        $this->assertSame([], $this->routing->resolve(availableGateways: []));
    }

    public function test_gateway_models_keep_their_identity_and_order(): void
    {
        $myfatoorah = (object) ['key_name' => 'myfatoorah'];
        $cashOnDelivery = (object) ['key_name' => 'cash_on_delivery'];

        $routed = $this->routing->applyToModels(models: collect([$myfatoorah, $cashOnDelivery]), amount: 120.0, country: 'SA');

        $this->assertCount(2, $routed);
        $this->assertSame($myfatoorah, $routed[0]);
        $this->assertSame($cashOnDelivery, $routed[1]);
    }

    public function test_an_empty_gateway_collection_stays_empty(): void
    {
        $this->assertTrue($this->routing->applyToModels(models: collect(), amount: 120.0, country: 'SA')->isEmpty());
    }
}
