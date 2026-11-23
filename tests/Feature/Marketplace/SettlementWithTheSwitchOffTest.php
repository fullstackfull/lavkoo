<?php

namespace Tests\Feature\Marketplace;

use App\Models\VendorSettlement;
use App\Services\Marketplace\SettlementEngine;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * With `gcc.settlements.one_currency` off, which is how the platform ships, a settlement is drawn exactly as it was before the switch existed, the defect of E-17.1 included: the engine does not look at the
 * currency of an entry, so entries of two currencies are added into one settlement, which takes the label of its first entry. The tests named "current behaviour" pin that so the shipped position cannot move
 * unnoticed; they are not an endorsement, and E-17.1 ("a settlement never nets entries of different currencies") holds only with the switch on (SettlementInOneCurrencyTest).
 *
 * This class was written and run green against the engine as it was before the switch, like SettlementOfAOneCurrencySellerTest.
 */
class SettlementWithTheSwitchOffTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;
    use WritesSellerLedger;

    private SettlementEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildVendorSettlementTables();
        $this->useDefaultCurrency(code: 'SAR');
        $this->engine = app(SettlementEngine::class);
    }

    /** @return list<mixed> the figures of a settlement that depend on which entries it drew */
    private function drawn(VendorSettlement $settlement): array
    {
        return [$settlement->currency, $settlement->opening_balance, $settlement->total_credits, $settlement->total_debits, $settlement->net_amount, $settlement->closing_balance, $settlement->entry_count];
    }

    public function test_current_behaviour_entries_of_two_currencies_are_added_into_one_settlement_named_after_the_first(): void
    {
        $entries = [$this->earning(amount: 100.0, currency: 'SAR')->id, $this->earning(amount: 12.345, currency: 'KWD')->id, $this->charge(amount: 10.0, currency: 'SAR')->id];

        $settlement = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertSame(['SAR', 0.0, 112.345, 10.0, 102.345, 102.345, 3], $this->drawn($settlement));
        $this->assertSame($entries, $this->entriesClaimedBy($settlement->id));
        $this->assertNull($this->engine->calculate(sellerId: self::SELLER));
        $this->assertSame(1, VendorSettlement::count());
    }

    public function test_current_behaviour_the_settlement_is_named_after_its_first_entry_whatever_the_others_are_in(): void
    {
        $this->earning(amount: 12.345, currency: 'KWD');
        $this->earning(amount: 100.0, currency: 'SAR');

        $this->assertSame(['KWD', 0.0, 112.345, 0.0, 112.345, 112.345, 2], $this->drawn($this->engine->calculate(sellerId: self::SELLER)));
    }

    public function test_current_behaviour_a_settlement_whose_first_entry_names_no_currency_names_none(): void
    {
        $this->withoutACurrency($this->earning(amount: 5.0));
        $this->earning(amount: 10.0, currency: 'SAR');

        $this->assertSame([null, 0.0, 15.0, 0.0, 15.0, 15.0, 2], $this->drawn($this->engine->calculate(sellerId: self::SELLER)));
    }

    public function test_current_behaviour_one_run_draws_one_settlement_for_a_seller_whatever_currencies_wait(): void
    {
        $this->earning(amount: 100.0, currency: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');
        $this->earning(amount: 3.5, currency: 'AED', changes: ['sellerId' => 8]);

        $settlements = $this->engine->calculateForAll();

        $nets = [];
        foreach ($settlements as $settlement) {
            $nets[$settlement->seller_id] = [$settlement->currency, $settlement->net_amount];
        }
        ksort($nets);
        $this->assertSame([7 => ['SAR', 112.345], 8 => ['AED', 3.5]], $nets);
        $this->assertCount(2, $settlements);
    }
}
