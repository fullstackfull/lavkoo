<?php

namespace Tests\Unit\Storefront;

use App\Services\Shipping\CrossBorderPolicy;
use App\Services\Shipping\ShippingRefusal;
use Tests\TestCase;

/**
 * May this storefront sell into that country at all (H1-01)?
 *
 * The question before every other shipping question, and the one nothing asked. The engine checked whether a PRODUCT may reach a country
 * and whether a CARRIER serves the route; neither says anything about whether the SHOP is allowed to trade there, so a storefront with all
 * three product permissions granted and a carrier declaring the route would have sold into a country the owner had never opened -- the kind
 * of mistake a regulator finds rather than a test.
 *
 * It ships CLOSED, which is the requirement's own default: the pair list is empty, so every storefront sells inside its own country and
 * nowhere else. These tests read the configuration only; the stored pairs, the audit and who may change one are the screen's own file.
 */
class CrossBorderPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['gcc.storefronts.default_country' => 'SA']);
    }

    public function test_it_ships_closed_so_every_destination_but_its_own_country_is_refused(): void
    {
        $this->assertSame([], config('gcc.shipping.cross_border.pairs'), 'the list ships empty, which is what closed means');

        $this->assertTrue(CrossBorderPolicy::allows('SA', 'SA'), 'a sale inside one country is not a cross-border sale');
        $this->assertFalse(CrossBorderPolicy::allows('SA', 'KW'));
        $this->assertFalse(CrossBorderPolicy::allows('KW', 'SA'));
    }

    public function test_an_open_pair_is_accepted_and_every_other_destination_is_still_refused(): void
    {
        config(['gcc.shipping.cross_border.pairs' => ['SA' => ['KW']]]);

        $this->assertTrue(CrossBorderPolicy::allows('SA', 'KW'));
        $this->assertFalse(CrossBorderPolicy::allows('SA', 'BH'), 'one pair is one pair');
        $this->assertFalse(CrossBorderPolicy::allows('KW', 'SA'), 'and a pair is one direction: opening SA to KW does not open KW to SA');
        $this->assertFalse(CrossBorderPolicy::allows('BH', 'KW'), 'a source with no list of its own sells nowhere abroad');
    }

    public function test_a_source_may_be_opened_to_every_destination_deliberately(): void
    {
        config(['gcc.shipping.cross_border.pairs' => ['SA' => [CrossBorderPolicy::EVERY_DESTINATION]]]);

        $this->assertTrue(CrossBorderPolicy::allows('SA', 'KW'));
        $this->assertTrue(CrossBorderPolicy::allows('SA', 'OM'));
        $this->assertFalse(CrossBorderPolicy::allows('KW', 'SA'), 'which is still one source and not a free-for-all');
    }

    public function test_the_kill_switch_refuses_every_pair_whatever_the_list_says(): void
    {
        config([
            'gcc.shipping.cross_border.pairs' => ['SA' => [CrossBorderPolicy::EVERY_DESTINATION], 'KW' => ['SA']],
            'gcc.shipping.cross_border.closed' => true,
        ]);

        $this->assertTrue(CrossBorderPolicy::isClosed());
        $this->assertFalse(CrossBorderPolicy::allows('SA', 'KW'));
        $this->assertFalse(CrossBorderPolicy::allows('KW', 'SA'));
        $this->assertTrue(CrossBorderPolicy::allows('SA', 'SA'), 'a shop never stops selling inside its own country');
    }

    /** The codes do not agree across this platform: a product's market is a code and the address book holds the English name. */
    public function test_the_country_is_read_through_the_one_normaliser_so_a_name_and_a_code_are_one_country(): void
    {
        config(['gcc.shipping.cross_border.pairs' => ['SA' => ['KW']]]);

        $this->assertTrue(CrossBorderPolicy::allows('Saudi Arabia', 'Kuwait'));
        $this->assertTrue(CrossBorderPolicy::allows('sa', 'kw'), 'letter case is not a country');
        $this->assertTrue(CrossBorderPolicy::allows('SA', 'Saudi Arabia'), 'and one country spelt two ways is still not a border');
    }

    public function test_a_destination_nobody_named_is_refused_rather_than_guessed(): void
    {
        config(['gcc.shipping.cross_border.pairs' => ['SA' => [CrossBorderPolicy::EVERY_DESTINATION]]]);

        $this->assertFalse(CrossBorderPolicy::allows('SA', null));
        $this->assertFalse(CrossBorderPolicy::allows('SA', ''));
        $this->assertFalse(CrossBorderPolicy::allows('SA', 'Atlantis'));
    }

    /** A line whose product carries no market is the default storefront's, which is every product of a single-market platform. */
    public function test_a_source_nobody_named_is_the_default_storefront(): void
    {
        config(['gcc.shipping.cross_border.pairs' => ['SA' => ['KW']]]);

        $this->assertTrue(CrossBorderPolicy::allows(null, 'KW'));
        $this->assertTrue(CrossBorderPolicy::allows(null, 'SA'), 'and its own country, which is not a border');
    }

    public function test_the_open_destinations_of_a_market_are_what_a_screen_may_offer(): void
    {
        config([
            // Set in this test's own configuration, never in `config/gcc.php`: which markets are active is the owner's G0 decision and
            // never a test's. A country needs a path and a currency to be complete, or the resolver falls back to the built-in Saudi one.
            'gcc.storefronts.countries' => [
                'SA' => ['path' => 'sa', 'currency' => 'SAR', 'active' => true],
                'KW' => ['path' => 'kw', 'currency' => 'KWD', 'active' => true],
                'BH' => ['path' => 'bh', 'currency' => 'BHD', 'active' => true],
            ],
            'gcc.shipping.cross_border.pairs' => ['SA' => ['KW']],
        ]);

        $this->assertSame(['SA', 'KW'], CrossBorderPolicy::destinationsOpenTo('SA'), 'its own country and the one pair it may sell into');
        $this->assertSame(['KW'], CrossBorderPolicy::destinationsOpenTo('KW'), 'and a market with no pair of its own sells only at home');
    }

    /** The refusal has to read in Arabic and in English before a customer can be shown it, so it names a key the vocabulary already holds. */
    public function test_the_refusal_is_a_reason_the_vocabulary_knows(): void
    {
        $this->assertContains(CrossBorderPolicy::REFUSAL, ShippingRefusal::all());
    }
}
