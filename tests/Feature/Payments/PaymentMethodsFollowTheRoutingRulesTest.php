<?php

namespace Tests\Feature\Payments;

use App\Http\Controllers\RestAPI\v1\ConfigController;
use App\Models\PaymentRoutingRule;
use App\Services\Marketplace\PaymentRoutingService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

use function App\Utils\payment_gateways;

/**
 * A routing rule reaches every surface that offers a payment method, and reaches none of them until
 * a rule exists (D2-06).
 *
 * Before this, the rules reached exactly one place: the web payment page. The mobile app's
 * /api/v1/config and the payment start itself never consulted them, so a gateway an operator had
 * ruled out stayed on every phone and would still take a payment if the method was posted straight
 * to the route.
 *
 * And the whole feature was unreachable: the model, the service and the admin screen were shipped
 * and `payment_routing_rules` had no migration in this repository, so every read was turned away by
 * the service's own hasTable guard. The table is created here from its REAL migration, not from a
 * shape this test invented -- a test that builds its own schema proves the test, not the platform.
 */
class PaymentMethodsFollowTheRoutingRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Same shape as tests/Support/BuildsMyFatoorahSchema's copy: `addon_settings` lives in the
        // installed SQL dump rather than in a migration, so every suite that needs it builds it.
        Schema::dropIfExists('addon_settings');
        Schema::create('addon_settings', function ($t): void {
            $t->string('id', 36)->primary();
            $t->string('key_name')->nullable();
            $t->text('live_values')->nullable();
            $t->text('test_values')->nullable();
            $t->string('settings_type')->nullable();
            $t->string('mode')->default('test');
            $t->integer('is_active')->default(0);
            $t->text('additional_data')->nullable();
            $t->timestamps();
        });

        Schema::dropIfExists('payment_routing_rules');
        (require database_path('migrations/2026_12_02_100000_create_payment_routing_rules_table.php'))->up();

        $this->aSwitchedOnGateway('paytabs');
        $this->aSwitchedOnGateway('stripe');
    }

    private function aSwitchedOnGateway(string $key): void
    {
        DB::table('addon_settings')->insert([
            'id' => (string) Str::uuid(),
            'key_name' => $key,
            'settings_type' => 'payment_config',
            'mode' => 'live',
            'is_active' => 1,
            'live_values' => json_encode(['status' => 1, 'mode' => 'live', 'server_key' => 'k']),
            'test_values' => json_encode(['status' => 1, 'mode' => 'live', 'server_key' => 'k']),
            'additional_data' => json_encode(['gateway_title' => ucfirst($key)]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function aRule(array $overrides = []): PaymentRoutingRule
    {
        return PaymentRoutingRule::create($overrides + [
            'name' => 'a rule',
            'gateway_code' => 'paytabs',
            'action' => PaymentRoutingRule::ACTION_HIDE,
            'priority' => 10,
            'status' => PaymentRoutingRule::STATUS_ACTIVE,
        ]);
    }

    /** What the mobile app is offered, through the real controller method the config answer uses. */
    private function offeredToTheApp(): array
    {
        return app(ConfigController::class)->offeredPaymentMethods()->pluck('key_name')->all();
    }

    public function test_the_table_the_feature_was_always_written_to_now_exists(): void
    {
        $this->assertTrue(Schema::hasTable('payment_routing_rules'));

        // Every column the admin screen validates and writes, and the two the model casts.
        foreach (['id', 'name', 'gateway_code', 'action', 'min_amount', 'max_amount', 'country', 'priority', 'status', 'created_by', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('payment_routing_rules', $column), "payment_routing_rules has no {$column}, which the admin screen writes");
        }

        // It arrives empty. That is what makes everything below dormant.
        $this->assertSame(0, PaymentRoutingRule::count());
    }

    public function test_with_no_rule_every_surface_answers_exactly_what_it_answered_before(): void
    {
        $unruled = payment_gateways()->pluck('key_name')->all();

        $this->assertSame($unruled, $this->offeredToTheApp(), 'the app answer changed while no rule exists');
        $this->assertSame($unruled, (new PaymentRoutingService())->resolve($unruled), 'the resolver changed the list while no rule exists');
        $this->assertSame($unruled, (new PaymentRoutingService())->resolveWithoutAnOrder($unruled));

        foreach ($unruled as $gateway) {
            $this->assertFalse(
                (new PaymentRoutingService())->hides(gateway: $gateway, availableGateways: $unruled, amount: 100.0, country: 'SA'),
                "{$gateway} is refused at the payment start while no rule exists"
            );
        }
    }

    public function test_a_rule_that_applies_everywhere_takes_the_gateway_off_the_app_too(): void
    {
        $this->aRule();

        $offered = $this->offeredToTheApp();

        $this->assertNotContains('paytabs', $offered, 'the app is still offered a gateway an everywhere rule hides');
        $this->assertContains('stripe', $offered, 'the other gateway was taken with it');
    }

    public function test_an_amount_bound_rule_does_not_take_a_method_off_the_app_answer(): void
    {
        // There is no cart when /api/v1/config is asked, so this rule cannot be decided here: the
        // customer's order may fall outside its band. Removing the method on a maybe would hide a
        // method the customer is entitled to. Deliberately NOT resolve($list, 0.0, null), which would
        // match this rule at an amount of zero.
        $this->aRule(['max_amount' => 100]);

        $this->assertContains('paytabs', $this->offeredToTheApp());

        // And at the payment start, where the amount IS known, it is applied.
        $offered = payment_gateways()->pluck('key_name')->all();
        $this->assertTrue((new PaymentRoutingService())->hides(gateway: 'paytabs', availableGateways: $offered, amount: 50.0));
        $this->assertFalse((new PaymentRoutingService())->hides(gateway: 'paytabs', availableGateways: $offered, amount: 500.0));
    }

    public function test_a_country_bound_rule_does_not_take_a_method_off_the_app_answer(): void
    {
        $this->aRule(['country' => 'KW']);

        $this->assertContains('paytabs', $this->offeredToTheApp(), 'a rule for one market hid a method from every phone');
    }

    public function test_an_inactive_rule_hides_nothing(): void
    {
        $this->aRule(['status' => 'inactive']);

        $this->assertContains('paytabs', $this->offeredToTheApp());
        $this->assertFalse((new PaymentRoutingService())->hides(gateway: 'paytabs', availableGateways: payment_gateways()->pluck('key_name')->all(), amount: 50.0));
    }

    public function test_a_prefer_rule_never_removes_a_method(): void
    {
        $this->aRule(['action' => PaymentRoutingRule::ACTION_PREFER]);

        $this->assertContains('paytabs', $this->offeredToTheApp());
        $this->assertFalse((new PaymentRoutingService())->hides(gateway: 'paytabs', availableGateways: payment_gateways()->pluck('key_name')->all(), amount: 50.0));
    }

    /**
     * The rule's country and the destination are written by different people in different places, so
     * they are compared by ISO code and not as text (D2-06). Before this, each pair below was a rule
     * that quietly did nothing.
     */
    public function test_a_rule_and_a_destination_that_name_one_country_in_different_words_match(): void
    {
        $offered = payment_gateways()->pluck('key_name')->all();
        $service = new PaymentRoutingService();

        foreach ([
            ['Kuwait', 'KW'],
            ['KW', 'Kuwait'],
            ['KSA', 'Saudi Arabia'],
            ['Saudi Arabia', 'SA'],
            ['الكويت', 'KW'],
            ['United Arab Emirates', 'AE'],
            ['Bahrain', 'BH'],
            ['sultanate of oman', 'OM'],
            ['QA', 'قطر'],
        ] as [$ruleCountry, $destination]) {
            PaymentRoutingRule::query()->delete();
            $this->aRule(['country' => $ruleCountry]);

            $this->assertTrue(
                $service->hides(gateway: 'paytabs', availableGateways: $offered, amount: 100.0, country: $destination),
                "a rule for '{$ruleCountry}' did not reach a destination of '{$destination}'"
            );
        }
    }

    public function test_a_rule_for_one_market_leaves_another_market_alone(): void
    {
        $this->aRule(['country' => 'Kuwait']);
        $offered = payment_gateways()->pluck('key_name')->all();
        $service = new PaymentRoutingService();

        $this->assertTrue($service->hides(gateway: 'paytabs', availableGateways: $offered, amount: 100.0, country: 'KW'));
        $this->assertFalse($service->hides(gateway: 'paytabs', availableGateways: $offered, amount: 100.0, country: 'SA'), 'a Kuwaiti rule reached a Saudi order');
        $this->assertFalse($service->hides(gateway: 'paytabs', availableGateways: $offered, amount: 100.0, country: 'Saudi Arabia'));
        $this->assertFalse($service->hides(gateway: 'paytabs', availableGateways: $offered, amount: 100.0, country: null), 'a country-bound rule reached an order with no destination');
    }

    public function test_a_country_nobody_can_recognise_is_compared_as_text_exactly_as_before(): void
    {
        // Falling back is the only change-free answer. Deciding an unrecognised rule matches nothing
        // would switch off a rule somebody wrote; deciding it matches everything would hide the
        // gateway in every market.
        $this->aRule(['country' => 'Narnia']);
        $offered = payment_gateways()->pluck('key_name')->all();
        $service = new PaymentRoutingService();

        $this->assertTrue($service->hides(gateway: 'paytabs', availableGateways: $offered, amount: 100.0, country: 'narnia'), 'the text comparison was lost');
        $this->assertFalse($service->hides(gateway: 'paytabs', availableGateways: $offered, amount: 100.0, country: 'KW'));
    }

    public function test_a_gateway_the_platform_does_not_offer_is_not_taken_over_by_this_guard(): void
    {
        // Whether an unknown gateway may start a payment is a different question with its own answer.
        $this->aRule(['gateway_code' => 'nonesuch']);
        $offered = payment_gateways()->pluck('key_name')->all();

        $this->assertFalse((new PaymentRoutingService())->hides(gateway: 'nonesuch', availableGateways: $offered, amount: 100.0));
        $this->assertSame($offered, $this->offeredToTheApp(), 'a rule naming an unknown gateway changed the offered list');
    }

    public function test_nothing_fails_before_the_migration_has_run(): void
    {
        // The state every installation is in until the migration is applied: the feature answers the
        // list unchanged rather than throwing.
        Schema::drop('payment_routing_rules');
        $offered = payment_gateways()->pluck('key_name')->all();

        $this->assertSame($offered, $this->offeredToTheApp());
        $this->assertSame($offered, (new PaymentRoutingService())->resolveWithoutAnOrder($offered));
        $this->assertFalse((new PaymentRoutingService())->hides(gateway: 'paytabs', availableGateways: $offered, amount: 100.0, country: 'KW'));
        $this->assertTrue((new PaymentRoutingService())->rules()->isEmpty());
    }
}
