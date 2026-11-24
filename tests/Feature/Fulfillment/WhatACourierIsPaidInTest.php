<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\Order;
use App\Services\Fulfillment\FulfillmentMoney;
use App\Services\Fulfillment\Support\WhatACourierIsPaidIn;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * C-15, finding E11-F02: which currency a courier is actually paid in for a non-Saudi order.
 *
 * The question is open and it is the owner's: each answer changes what a courier invoices and who absorbs the difference between what
 * the customer paid and what the courier is accounted in (docs/gcc/OPEN-FINANCIAL-DECISIONS.md section 2). So this test does two things
 * and adopts nothing. It pins TODAY's answer -- the configured currency, whatever market the order was sold in -- so that choosing an
 * option later is a visible change rather than a silent one. And it proves the three options are reachable by one setting and that the
 * one dangerous combination is refused: a shipment stamped with a currency its figures were never converted into is held, not sent,
 * because that figure is the cash the courier collects.
 *
 * What is NOT here, because it is the decision itself: converting an order's money into a currency other than the configured one.
 * {@see \App\Services\Fulfillment\Support\ProviderCurrency} still converts into one currency alone.
 */
class WhatACourierIsPaidInTest extends FulfillmentTestCase
{
    private const CONFIGURED = 'SAR';

    private const KUWAIT = 'KWD';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ordersCanRecordTheirMarket();
    }

    /** An order of a market whose customers pay dinars. */
    private function kuwaitiOrder(): Order
    {
        return $this->makeOrder(1, ['storefront_country' => 'KW', 'currency_code' => self::KUWAIT, 'currency_rate' => 0.082]);
    }

    private function underRule(string $rule): void
    {
        config(['fulfillment.settlement_currency' => $rule]);
    }

    /**
     * Today's answer, which is the finding: a Kuwaiti order is stamped with the configured currency and the dinars its courier collects
     * would be reconciled against riyals. Nothing has paid for this because no non-Saudi provider account has ever been enabled.
     */
    public function test_today_an_order_is_stamped_with_the_configured_currency_whatever_market_sold_it(): void
    {
        $this->assertSame(self::CONFIGURED, WhatACourierIsPaidIn::forOrder($this->kuwaitiOrder(), ProviderCode::TorodPoint->value));
    }

    public function test_the_rule_in_force_is_todays_behaviour_until_the_owner_chooses(): void
    {
        $this->assertSame(WhatACourierIsPaidIn::AS_CONFIGURED_TODAY, WhatACourierIsPaidIn::rule());
    }

    /** A typo in the environment cannot move money: anything the class does not recognise is read as today's behaviour. */
    public function test_an_unrecognised_rule_is_read_as_todays_behaviour(): void
    {
        $this->underRule('the_currency_of_the_moon');

        $this->assertSame(WhatACourierIsPaidIn::AS_CONFIGURED_TODAY, WhatACourierIsPaidIn::rule());
        $this->assertSame(self::CONFIGURED, WhatACourierIsPaidIn::forOrder($this->kuwaitiOrder(), ProviderCode::TorodPoint->value));
    }

    public function test_option_b_stamps_the_currency_the_customer_was_charged_in(): void
    {
        $this->underRule(WhatACourierIsPaidIn::THE_ORDERS_MARKET);

        $this->assertSame(self::KUWAIT, WhatACourierIsPaidIn::forOrder($this->kuwaitiOrder(), ProviderCode::TorodPoint->value));
    }

    /** Every order placed before the storefront snapshot existed names no currency. It keeps the configured one rather than nothing. */
    public function test_option_b_falls_back_to_the_configured_currency_for_an_order_that_names_none(): void
    {
        $this->underRule(WhatACourierIsPaidIn::THE_ORDERS_MARKET);

        $this->assertSame(self::CONFIGURED, WhatACourierIsPaidIn::forOrder($this->makeOrder(2), ProviderCode::TorodPoint->value));
    }

    /** The store's own spelling of a currency is never what a provider is sent: this store's riyal is stored as "KSA". */
    public function test_option_b_sends_the_iso_code_and_not_the_stores_spelling(): void
    {
        $this->underRule(WhatACourierIsPaidIn::THE_ORDERS_MARKET);
        $order = $this->makeOrder(3, ['storefront_country' => 'SA', 'currency_code' => 'KSA']);

        $this->assertSame(self::CONFIGURED, WhatACourierIsPaidIn::forOrder($order, ProviderCode::TorodPoint->value));
    }

    public function test_option_c_stamps_the_currency_the_provider_account_settles_in(): void
    {
        $this->underRule(WhatACourierIsPaidIn::THE_PROVIDER_ACCOUNT);
        config(['fulfillment.providers.torod_point.settlement_currency' => self::KUWAIT]);

        $order = $this->kuwaitiOrder();

        $this->assertSame(self::KUWAIT, WhatACourierIsPaidIn::forOrder($order, ProviderCode::TorodPoint->value));
        $this->assertSame(self::CONFIGURED, WhatACourierIsPaidIn::forOrder($order, ProviderCode::M5azn->value), 'an account told nothing settles in the configured currency');
    }

    public static function optionsThatCanDisagreeWithTheConverter(): array
    {
        return [
            'the order\'s market' => [WhatACourierIsPaidIn::THE_ORDERS_MARKET],
            'the provider account' => [WhatACourierIsPaidIn::THE_PROVIDER_ACCOUNT],
        ];
    }

    /**
     * The rule that protects the money while the question is open. Every figure in an assessment is in the currency the converter
     * produced, which is the configured one; stamping the shipment with another currency would hand a courier a dinar label over a riyal
     * figure and have them collect it in cash. So the shipment is held instead -- and held for a reason an operator already knows.
     */
    #[DataProvider('optionsThatCanDisagreeWithTheConverter')]
    public function test_a_shipment_whose_stamp_is_not_the_currency_of_its_figures_is_held(string $rule): void
    {
        $this->underRule($rule);
        config(['fulfillment.providers.torod_point.settlement_currency' => self::KUWAIT]);

        $assessment = app(FulfillmentMoney::class)->assess($this->kuwaitiOrder(), ProviderCode::TorodPoint, collect(), collect());

        $this->assertSame(HoldReason::CURRENCY_UNCONVERTIBLE, $assessment->blockedReason);
    }

    /** And the control: with today's rule the stamp and the figures are the same currency, so nothing is ever held for this. */
    public function test_nothing_is_held_for_this_while_todays_rule_is_in_force(): void
    {
        $assessment = app(FulfillmentMoney::class)->assess($this->kuwaitiOrder(), ProviderCode::TorodPoint, collect(), collect());

        $this->assertNull($assessment->blockedReason);
    }
}
