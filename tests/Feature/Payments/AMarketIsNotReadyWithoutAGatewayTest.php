<?php

namespace Tests\Feature\Payments;

use App\Services\Payments\GatewayCapabilityRegistry;
use App\Services\Payments\StorefrontPaymentReadiness;
use App\Services\Storefront\StorefrontPreflight;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A market that takes online payments and has no gateway that could take one is not ready to sell (D2-05, D1-16),
 * and a provider's support for a market counts for nothing until somebody has written down that it is confirmed.
 *
 * The selling gate asked a market four things -- its legal texts, their independent review, its currency and its
 * selling company -- and nothing about whether a customer who reached its checkout could pay. So a market whose
 * gateway account was missing, or whose credentials were saved under the mode that is switched off, was allowed to
 * open and the refusal happened at the customer's checkout. An operator found out from a customer.
 *
 * The registry is the other half and is the foundation rule written down: a gateway's own currency list says what
 * the GATEWAY can do in general and says nothing about whether this merchant's account with that provider is live
 * in Kuwait, in dinars. An entry therefore has to carry `confirmed` strictly true, who confirmed it, when, and in
 * which currencies -- and the strictness is the point: `1`, `'yes'` and `'true'` are all refused, because a value
 * that merely looks affirmative is one somebody typed without asking the provider.
 *
 * The first two cases are the dormancy claim. Both switches ship off and the gate's answer is the answer it gives
 * today, to the market.
 */
class AMarketIsNotReadyWithoutAGatewayTest extends TestCase
{
    private const MARKET = 'KW';

    private const MARKET_CURRENCY = 'KWD';

    /** Declares the Kuwaiti dinar and the dirham, so a Kuwaiti market has something that could take its money. */
    private const GATEWAY = 'paytabs';

    /** Declares no Gulf currency at all. */
    private const GATEWAY_WITH_NO_GULF_CURRENCY = 'ssl_commerz';

    protected function setUp(): void
    {
        parent::setUp();

        $this->theGatewaySettingsTable();
        $this->theBusinessSettings();
        // Three decimals for the dinar, as the platform's configuration names them, and nobody verified for three.
        config([
            'gcc.storefronts.currency.decimals' => ['SAR' => 2, 'AED' => 2, 'KWD' => 3],
            'gcc.storefronts.currency.verified_three_decimal_gateways' => [],
            'gcc.payments.capabilities.mode' => GatewayCapabilityRegistry::OFF,
            'gcc.payments.capabilities.gateways' => [],
            'gcc.storefronts.sale_gate.needs_a_payment_account' => false,
        ]);
    }

    private function theGatewaySettingsTable(): void
    {
        Schema::dropIfExists('addon_settings');
        Schema::create('addon_settings', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('key_name')->nullable();
            $table->text('live_values')->nullable();
            $table->text('test_values')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->default('test');
            $table->integer('is_active')->default(0);
            $table->text('additional_data')->nullable();
            $table->timestamps();
        });
    }

    private function theBusinessSettings(): void
    {
        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('type');
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        DB::table('business_settings')->updateOrInsert(['type' => 'digital_payment'], ['value' => '1', 'updated_at' => now()]);
        clearWebConfigCacheKeys();
    }

    /** A gateway as an operator leaves one: switched on, in a mode, with its credentials in one of the two blobs. */
    private function aGateway(string $key, string $mode = 'test', bool $credentialsInForce = true, bool $active = true): void
    {
        $filled = json_encode(['gateway' => $key, 'mode' => $mode, 'status' => 1, 'api_key' => 'a-key', 'secret' => 'a-secret']);
        $empty = json_encode(['gateway' => $key, 'mode' => $mode, 'status' => 1, 'api_key' => '', 'secret' => '']);
        $live = $mode === 'live';

        DB::table('addon_settings')->updateOrInsert(['key_name' => $key, 'settings_type' => 'payment_config'], [
            'id' => (string) Str::uuid(),
            'mode' => $mode,
            'is_active' => $active ? 1 : 0,
            'live_values' => $live === $credentialsInForce ? $filled : $empty,
            'test_values' => $live === $credentialsInForce ? $empty : $filled,
            'additional_data' => json_encode(['gateway_title' => $key]),
        ]);
    }

    private function theCheckIsOn(): void
    {
        config(['gcc.storefronts.sale_gate.needs_a_payment_account' => true]);
    }

    private function readiness(): StorefrontPaymentReadiness
    {
        return app(StorefrontPaymentReadiness::class);
    }

    private function verdict(): string
    {
        return $this->readiness()->verdict(country: self::MARKET, currencyCode: self::MARKET_CURRENCY);
    }

    // ---------------------------------------------------------------- off as it ships

    public function test_with_the_check_off_a_market_with_no_gateway_at_all_is_ready(): void
    {
        $this->assertSame(StorefrontPaymentReadiness::NOT_ASKED, $this->verdict());
        $this->assertTrue($this->readiness()->isReady(country: self::MARKET, currencyCode: self::MARKET_CURRENCY));
    }

    public function test_with_the_registry_off_every_gateway_is_confirmed_for_every_market(): void
    {
        $registry = app(GatewayCapabilityRegistry::class);

        $this->assertFalse($registry->isEnforced());
        $this->assertTrue($registry->confirms(gateway: self::GATEWAY, country: self::MARKET, currency: self::MARKET_CURRENCY));
        $this->assertSame([self::GATEWAY], $registry->confirmedOf(gateways: [self::GATEWAY], country: self::MARKET));
        // The reason is still answerable, so a readiness report can say what is missing before the switch is turned on.
        $this->assertSame(GatewayCapabilityRegistry::NOT_LISTED, $registry->refusalFor(gateway: self::GATEWAY, country: self::MARKET));
    }

    // ---------------------------------------------------------------- the check, switched on

    public function test_a_market_with_no_gateway_switched_on_is_not_ready(): void
    {
        $this->theCheckIsOn();

        $this->assertSame(StorefrontPaymentReadiness::NO_ELIGIBLE_GATEWAY, $this->verdict());
        $this->assertFalse($this->readiness()->isReady(country: self::MARKET, currencyCode: self::MARKET_CURRENCY));
    }

    public function test_a_market_with_a_gateway_that_can_take_its_money_is_ready(): void
    {
        $this->theCheckIsOn();
        $this->aGateway(self::GATEWAY);
        config(['gcc.storefronts.currency.verified_three_decimal_gateways' => [self::GATEWAY]]);

        $this->assertSame(StorefrontPaymentReadiness::READY, $this->verdict());
        $this->assertSame(self::GATEWAY, $this->readiness()->anEligibleGateway(country: self::MARKET, currencyCode: self::MARKET_CURRENCY));
    }

    /**
     * THE FAILURE A MERCHANT CANNOT SEE FROM THE FORM: the gateway is switched on and the admin screen is green,
     * and the keys are in the blob the mode that is switched off reads.
     */
    public function test_a_gateway_whose_credentials_are_in_the_other_mode_does_not_make_a_market_ready(): void
    {
        $this->theCheckIsOn();
        config(['gcc.storefronts.currency.verified_three_decimal_gateways' => [self::GATEWAY]]);
        $this->aGateway(self::GATEWAY, mode: 'live', credentialsInForce: false);

        $this->assertSame(StorefrontPaymentReadiness::NO_ELIGIBLE_GATEWAY, $this->verdict());
    }

    public function test_a_gateway_that_is_not_switched_on_does_not_make_a_market_ready(): void
    {
        $this->theCheckIsOn();
        config(['gcc.storefronts.currency.verified_three_decimal_gateways' => [self::GATEWAY]]);
        $this->aGateway(self::GATEWAY, active: false);

        $this->assertSame(StorefrontPaymentReadiness::NO_ELIGIBLE_GATEWAY, $this->verdict());
    }

    public function test_a_gateway_that_cannot_take_the_markets_currency_does_not_make_it_ready(): void
    {
        $this->theCheckIsOn();
        $this->aGateway(self::GATEWAY_WITH_NO_GULF_CURRENCY);

        $this->assertSame(StorefrontPaymentReadiness::NO_ELIGIBLE_GATEWAY, $this->verdict());
    }

    /** A three-decimal currency goes only to a gateway somebody has verified for three, which is the payment start's own rule. */
    public function test_a_dinar_market_needs_a_gateway_verified_for_three_decimals(): void
    {
        $this->theCheckIsOn();
        $this->aGateway(self::GATEWAY);

        $this->assertSame(StorefrontPaymentReadiness::NO_ELIGIBLE_GATEWAY, $this->verdict(), 'an unverified gateway was accepted for a three-decimal currency');

        config(['gcc.storefronts.currency.verified_three_decimal_gateways' => [self::GATEWAY]]);

        $this->assertSame(StorefrontPaymentReadiness::READY, $this->verdict());
    }

    /** A market selling cash-on-delivery only needs no gateway, and refusing it would stop a market the platform can serve. */
    public function test_a_market_that_takes_no_online_payments_is_ready_without_a_gateway(): void
    {
        $this->theCheckIsOn();
        DB::table('business_settings')->updateOrInsert(['type' => 'digital_payment'], ['value' => '0', 'updated_at' => now()]);
        clearWebConfigCacheKeys();

        $this->assertSame(StorefrontPaymentReadiness::NO_ONLINE_PAYMENT, $this->verdict());
        $this->assertTrue($this->readiness()->isReady(country: self::MARKET, currencyCode: self::MARKET_CURRENCY));
    }

    // ---------------------------------------------------------------- a country name grants nothing

    public function test_a_confirmed_entry_grants_the_market_it_names(): void
    {
        $this->theRegistryIsEnforced();
        $this->anEntry(['confirmed' => true, 'provider' => 'PayTabs', 'confirmed_on' => '2026-01-15', 'currencies' => ['KWD']]);

        $registry = app(GatewayCapabilityRegistry::class);

        $this->assertNull($registry->refusalFor(gateway: self::GATEWAY, country: self::MARKET, currency: 'KWD'));
        $this->assertTrue($registry->confirms(gateway: self::GATEWAY, country: self::MARKET, currency: 'KWD'));
    }

    public function test_a_gateway_with_no_entry_for_the_market_grants_nothing(): void
    {
        $this->theRegistryIsEnforced();

        $this->assertSame(GatewayCapabilityRegistry::NOT_LISTED, app(GatewayCapabilityRegistry::class)->refusalFor(gateway: self::GATEWAY, country: self::MARKET));
        $this->assertFalse(app(GatewayCapabilityRegistry::class)->confirms(gateway: self::GATEWAY, country: self::MARKET));
    }

    /**
     * STRICTLY TRUE. Each of these looks affirmative and each is refused: a provider confirmation is written down
     * deliberately or not at all, and a loose comparison would let a default-deny registry be opened by a value
     * somebody pasted.
     */
    public function test_a_value_that_merely_looks_affirmative_confirms_nothing(): void
    {
        $this->theRegistryIsEnforced();

        foreach ([1, '1', 'yes', 'true', 'TRUE', [], 0.0, null] as $looksAffirmative) {
            $this->anEntry(['confirmed' => $looksAffirmative, 'provider' => 'PayTabs', 'confirmed_on' => '2026-01-15', 'currencies' => ['KWD']]);

            $this->assertSame(
                GatewayCapabilityRegistry::NOT_CONFIRMED,
                app(GatewayCapabilityRegistry::class)->refusalFor(gateway: self::GATEWAY, country: self::MARKET),
                var_export($looksAffirmative, true) . ' was read as a confirmation',
            );
        }
    }

    public function test_an_entry_must_name_who_confirmed_it_and_when(): void
    {
        $this->theRegistryIsEnforced();
        $complete = ['confirmed' => true, 'provider' => 'PayTabs', 'confirmed_on' => '2026-01-15', 'currencies' => ['KWD']];

        foreach ([
            GatewayCapabilityRegistry::NO_PROVIDER => ['provider' => ''],
            GatewayCapabilityRegistry::NO_DATE => ['confirmed_on' => null],
            GatewayCapabilityRegistry::DATE_IN_THE_FUTURE => ['confirmed_on' => '2099-01-01'],
            GatewayCapabilityRegistry::NO_CURRENCY => ['currencies' => []],
        ] as $expected => $missing) {
            $this->anEntry(array_merge($complete, $missing));

            $this->assertSame($expected, app(GatewayCapabilityRegistry::class)->refusalFor(gateway: self::GATEWAY, country: self::MARKET), $expected);
        }
    }

    /** An unreadable date is no date, and it is not an exception out of a payment path either. */
    public function test_a_date_nobody_can_read_is_no_date(): void
    {
        $this->theRegistryIsEnforced();
        $this->anEntry(['confirmed' => true, 'provider' => 'PayTabs', 'confirmed_on' => 'last spring', 'currencies' => ['KWD']]);

        $this->assertSame(GatewayCapabilityRegistry::NO_DATE, app(GatewayCapabilityRegistry::class)->refusalFor(gateway: self::GATEWAY, country: self::MARKET));
    }

    public function test_a_currency_the_entry_does_not_name_is_not_confirmed(): void
    {
        $this->theRegistryIsEnforced();
        $this->anEntry(['confirmed' => true, 'provider' => 'PayTabs', 'confirmed_on' => '2026-01-15', 'currencies' => ['AED']]);

        $registry = app(GatewayCapabilityRegistry::class);

        $this->assertSame(GatewayCapabilityRegistry::CURRENCY_NOT_CONFIRMED, $registry->refusalFor(gateway: self::GATEWAY, country: self::MARKET, currency: 'KWD'));
        $this->assertNull($registry->refusalFor(gateway: self::GATEWAY, country: self::MARKET, currency: 'aed'), 'the currency is compared by code and not by case');
    }

    /** `methods` absent means every method, which is the ordinary shape of a provider confirmation. */
    public function test_an_entry_that_names_no_method_confirms_every_method(): void
    {
        $this->theRegistryIsEnforced();
        $this->anEntry(['confirmed' => true, 'provider' => 'PayTabs', 'confirmed_on' => '2026-01-15', 'currencies' => ['KWD']]);

        $this->assertNull(app(GatewayCapabilityRegistry::class)->refusalFor(gateway: self::GATEWAY, country: self::MARKET, currency: 'KWD', method: 'apple_pay'));
    }

    public function test_an_entry_that_names_methods_confirms_only_those(): void
    {
        $this->theRegistryIsEnforced();
        $this->anEntry(['confirmed' => true, 'provider' => 'PayTabs', 'confirmed_on' => '2026-01-15', 'currencies' => ['KWD'], 'methods' => ['card']]);

        $registry = app(GatewayCapabilityRegistry::class);

        $this->assertNull($registry->refusalFor(gateway: self::GATEWAY, country: self::MARKET, currency: 'KWD', method: 'card'));
        $this->assertSame(GatewayCapabilityRegistry::METHOD_NOT_CONFIRMED, $registry->refusalFor(gateway: self::GATEWAY, country: self::MARKET, currency: 'KWD', method: 'apple_pay'));
    }

    /** The register, with every cell's refusal beside it: what tells an operator which cells are still a question for the provider. */
    public function test_the_register_reports_every_cell_with_its_refusal(): void
    {
        $this->theRegistryIsEnforced();
        config(['gcc.payments.capabilities.gateways' => [
            self::GATEWAY => [
                'KW' => ['confirmed' => true, 'provider' => 'PayTabs', 'confirmed_on' => '2026-01-15', 'currencies' => ['KWD']],
                'AE' => ['confirmed' => false, 'provider' => 'PayTabs', 'confirmed_on' => '2026-01-15', 'currencies' => ['AED']],
            ],
        ]]);

        $entries = app(GatewayCapabilityRegistry::class)->entries();

        $this->assertCount(2, $entries);
        $this->assertSame(['AE', 'KW'], array_column($entries, 'country'));
        $this->assertSame(GatewayCapabilityRegistry::NOT_CONFIRMED, $entries[0]['refusal']);
        $this->assertNull($entries[1]['refusal']);
        $this->assertSame('2026-01-15', $entries[1]['confirmed_on']);
        $this->assertSame(['KWD'], $entries[1]['currencies']);
    }

    /** An unconfirmed market takes its gateway out of the readiness answer, which is what the two halves together are for. */
    public function test_an_unconfirmed_market_has_no_eligible_gateway_however_well_configured_it_is(): void
    {
        $this->theCheckIsOn();
        $this->theRegistryIsEnforced();
        $this->aGateway(self::GATEWAY);
        config(['gcc.storefronts.currency.verified_three_decimal_gateways' => [self::GATEWAY]]);

        $this->assertSame(StorefrontPaymentReadiness::NO_ELIGIBLE_GATEWAY, $this->verdict());

        $this->anEntry(['confirmed' => true, 'provider' => 'PayTabs', 'confirmed_on' => '2026-01-15', 'currencies' => ['KWD']]);

        $this->assertSame(StorefrontPaymentReadiness::READY, $this->verdict());
    }

    // ---------------------------------------------------------------- the selling gate asks it

    /** The prerequisite is one of the gate's five, and it is the gate that has to ask it: a service nobody asks refuses nothing. */
    public function test_the_preflight_names_the_payment_account_among_what_a_market_is_missing(): void
    {
        $this->theCheckIsOn();

        $unmet = app(StorefrontPreflight::class)->unmet(country: self::MARKET, currencyCode: self::MARKET_CURRENCY);

        $this->assertContains(StorefrontPreflight::PAYMENT_ACCOUNT, $unmet);
    }

    public function test_the_preflight_does_not_name_it_while_the_check_is_off(): void
    {
        $unmet = app(StorefrontPreflight::class)->unmet(country: self::MARKET, currencyCode: self::MARKET_CURRENCY);

        $this->assertNotContains(StorefrontPreflight::PAYMENT_ACCOUNT, $unmet, 'a market was refused for a record nobody has been asked for');
    }

    private function theRegistryIsEnforced(): void
    {
        config(['gcc.payments.capabilities.mode' => GatewayCapabilityRegistry::ENFORCE]);
    }

    /** @param array<string, mixed> $entry */
    private function anEntry(array $entry): void
    {
        config(['gcc.payments.capabilities.gateways' => [self::GATEWAY => [self::MARKET => $entry]]]);
    }
}
