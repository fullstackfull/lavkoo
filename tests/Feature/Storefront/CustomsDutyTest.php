<?php

namespace Tests\Feature\Storefront;

use App\Services\Shipping\CustomsDuty;
use App\Services\Shipping\CustomsDutyQuote;
use Tests\TestCase;

/**
 * H1-09: customs duty on a cross-border order, and the sentence that matters most -- a route whose duty cannot be determined refuses the
 * order and never charges zero.
 *
 * A duty rate is not a thing a shop may invent. It belongs to the destination's tariff, it turns on what the goods are and where they were
 * made, and it is the owner's to give per route with a competent source behind it (Q-11). So nothing here guesses one: the rules ship
 * empty and every cross-border route therefore answers "not quoted", which is the honest answer and the safe one.
 *
 * The first test is the dormancy. With duty off -- how the platform ships, and the truth for a shop selling inside one country -- every
 * order quotes `none` and nothing is shown, stored or refused.
 *
 * The distinction this class exists to hold is between `none` and `not_quoted`. A zero is an ANSWER: it says the goods cross free. Nobody
 * may give that answer on the platform's behalf without a rule to give it from, and a platform that records zero where it means "we do not
 * know" is a platform that charges a customer nothing and then hands them a bill at the door.
 */
class CustomsDutyTest extends TestCase
{
    private const HOME = 'SA';

    private const ABROAD = 'KW';

    private function dutyIs(string $mode, array $routes = []): void
    {
        config(['gcc.shipping.duty.mode' => $mode, 'gcc.shipping.duty.routes' => $routes]);
    }

    private function quote(float $value = 1000.0, string $destination = self::ABROAD): CustomsDutyQuote
    {
        return CustomsDuty::quoteFor(source: self::HOME, destination: $destination, value: $value);
    }

    /** The dormancy, and why the Saudi platform is untouched: a shop selling inside one country has no duty to quote. */
    public function test_with_duty_off_every_order_quotes_nothing_payable(): void
    {
        $this->dutyIs(CustomsDuty::MODE_OFF, ['SA:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::BUYER_ON_DELIVERY]]);

        $this->assertFalse(CustomsDuty::isOn());
        $this->assertSame(CustomsDutyQuote::NONE, $this->quote()->kind, 'the rules are ignored while the mode is off');
        $this->assertSame(0.0, $this->quote()->amount);
    }

    /** An unrecognised value is read as off, so a typo in a setting never starts refusing orders. */
    public function test_a_mode_nobody_recognises_is_off(): void
    {
        $this->dutyIs('aggressive');

        $this->assertSame(CustomsDuty::MODE_OFF, CustomsDuty::mode());
        $this->assertFalse(CustomsDuty::isOn());
    }

    public function test_a_domestic_order_owes_no_duty_and_is_always_quotable(): void
    {
        $this->dutyIs(CustomsDuty::MODE_STRICT);

        $this->assertTrue(CustomsDuty::canQuote(self::HOME, self::HOME));
        $this->assertSame(CustomsDutyQuote::NONE, $this->quote(destination: self::HOME)->kind);
    }

    /** The whole point: no rule means no answer, and no answer is not zero. */
    public function test_a_route_with_no_rule_is_not_quoted_rather_than_free(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE);

        $quote = $this->quote();

        $this->assertSame(CustomsDutyQuote::NOT_QUOTED, $quote->kind);
        $this->assertFalse($quote->isQuoted());
        $this->assertFalse(CustomsDuty::canQuote(self::HOME, self::ABROAD));
        $this->assertSame(0.0, $quote->amount, 'the amount of an unquoted route is meaningless, and its kind says so');
    }

    public function test_a_rule_quotes_the_amount_its_percentage_comes_to(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['SA:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::BUYER_ON_DELIVERY]]);

        $quote = $this->quote(value: 1000.0);

        $this->assertSame(CustomsDutyQuote::PAYABLE, $quote->kind);
        $this->assertSame(50.0, $quote->amount);
        $this->assertSame(1000.0, $quote->basis, 'what it was charged on, so the figure can be checked later');
        $this->assertSame(CustomsDutyQuote::BUYER_ON_DELIVERY, $quote->payer);
        $this->assertSame('SA:KW', $quote->route);
        $this->assertTrue(CustomsDuty::canQuote(self::HOME, self::ABROAD));
    }

    /** Who pays is a commercial term, not a rounding: the buyer settling at the door and the seller paying up front are different prices. */
    public function test_a_rule_that_names_no_payer_is_not_a_quote(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['SA:KW' => ['percentage' => 5]]);

        $this->assertSame(CustomsDutyQuote::NOT_QUOTED, $this->quote()->kind);
    }

    public function test_a_payer_nobody_recognises_is_not_a_payer(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['SA:KW' => ['percentage' => 5, 'payer' => 'the_courier']]);

        $this->assertSame(CustomsDutyQuote::NOT_QUOTED, $this->quote()->kind);
    }

    /** A rule with a payer and no rate is half a rule, and half a rule quotes nothing. */
    public function test_a_rule_that_names_no_rate_is_not_a_quote(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['SA:KW' => ['payer' => CustomsDutyQuote::SELLER]]);

        $this->assertSame(CustomsDutyQuote::NOT_QUOTED, $this->quote()->kind);
    }

    /** A rate of nought IS an answer, where somebody has written it down: these goods cross free. */
    public function test_a_rule_whose_rate_is_nought_says_the_goods_cross_free(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['SA:KW' => ['percentage' => 0, 'payer' => CustomsDutyQuote::SELLER]]);

        $this->assertSame(CustomsDutyQuote::NONE, $this->quote()->kind);
    }

    /** The destination's own threshold, under which a parcel enters free. */
    public function test_a_value_under_the_de_minimis_owes_nothing(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, [
            'SA:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::BUYER_ON_DELIVERY, 'de_minimis' => 100],
        ]);

        $this->assertSame(CustomsDutyQuote::NONE, $this->quote(value: 100.0)->kind, 'the threshold itself is under it');
        $this->assertSame(CustomsDutyQuote::NONE, $this->quote(value: 99.99)->kind);
        $this->assertSame(CustomsDutyQuote::PAYABLE, $this->quote(value: 100.01)->kind);
    }

    /** One carrier's rule for everything going to a country, for an owner who would rather write one line than thirty-six. */
    public function test_a_rule_may_name_any_source_or_any_destination(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['*:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::SELLER]]);

        $this->assertSame(CustomsDutyQuote::PAYABLE, $this->quote()->kind);
        $this->assertSame(CustomsDutyQuote::NOT_QUOTED, $this->quote(destination: 'BH')->kind, 'and only to the country it names');
    }

    /** The exact route wins over a wildcard, because the specific rule is the one somebody wrote for this case. */
    public function test_the_exact_route_is_preferred_to_a_wildcard(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, [
            'SA:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::BUYER_ON_DELIVERY],
            '*:KW' => ['percentage' => 20, 'payer' => CustomsDutyQuote::SELLER],
        ]);

        $this->assertSame(50.0, $this->quote(value: 1000.0)->amount);
    }

    public function test_a_destination_nobody_named_cannot_be_quoted(): void
    {
        $this->dutyIs(CustomsDuty::MODE_STRICT);

        $this->assertFalse(CustomsDuty::canQuote(self::HOME, null));
        $this->assertFalse(CustomsDuty::canQuote(self::HOME, '  '));
        $this->assertFalse(CustomsDuty::canQuote(null, self::ABROAD));
    }

    public function test_the_codes_are_read_whatever_case_they_arrive_in(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['SA:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::SELLER]]);

        $this->assertSame(
            CustomsDutyQuote::PAYABLE,
            CustomsDuty::quoteFor(source: 'sa', destination: ' kw ', value: 1000.0)->kind,
        );
    }

    /** The quote as an order stores it, so that what the customer was told stays readable when the tariff changes. */
    public function test_the_quote_can_be_stored_as_it_was_given(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, [
            'SA:KW' => ['percentage' => 7.5, 'payer' => CustomsDutyQuote::BUYER_ON_DELIVERY],
        ]);

        $this->assertSame(
            ['kind' => 'payable', 'amount' => 24.75, 'payer' => 'buyer_on_delivery', 'basis' => 330.0, 'route' => 'SA:KW'],
            $this->quote(value: 330.0)->toArray(),
        );
    }

    /** Three decimals, because the dinar and the rial have three and a duty on a dinar order is a dinar amount (C-15). */
    public function test_an_amount_keeps_the_third_decimal_of_a_dinar(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['SA:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::SELLER]]);

        $this->assertSame(5.751, $this->quote(value: 115.025)->amount);
    }
}
