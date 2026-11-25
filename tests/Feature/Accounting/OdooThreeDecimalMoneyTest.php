<?php

namespace Tests\Feature\Accounting;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OperationalCostEntry;
use App\Services\Accounting\Odoo\OdooCurrencyDecimals;
use App\Services\Accounting\Odoo\OdooDraftBuilder;
use App\Services\Accounting\Odoo\OdooDraftResult;
use App\Services\Accounting\Odoo\OdooPreflight;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsOdooWorld;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesOperationalCostData;
use Tests\TestCase;

/**
 * An amount reaches Odoo at the posting currency's own decimals, or it does not reach Odoo at all (H2-11).
 *
 * `OdooMoney` has always taken a `decimals` argument and `OdooDraftBuilder` never passed one, so every figure was written at TWO places.
 * For the riyal that is correct. For the Kuwaiti dinar, the Bahraini dinar and the Omani rial it is wrong: ISO 4217 gives all three THREE,
 * so 12.345 KWD went into somebody's books as 12.35 and the fils vanished at the one step nobody is watching -- inside a float conversion,
 * in a draft nobody had reason to re-add.
 *
 * Two sources must agree before a figure is written: this platform's own answer (`gcc.storefronts.currency.decimals`, the same reader the
 * storefronts, the channels and the gateways use) and what the instance reports under `res.currency.decimal_places`. Where they cannot be
 * reconciled the entry is REFUSED BY NAME, which is this integration's existing answer to a figure it cannot carry honestly -- the same
 * shape as `odoo_currency_not_in_this_instance`.
 *
 * The first test is the Saudi one, and it is the one that matters: SAR has two places on both sides, so nothing about a riyal posting
 * moves, whether or not the instance reports anything at all.
 */
class OdooThreeDecimalMoneyTest extends TestCase
{
    use BuildsOdooWorld;
    use BuildsSalesChannelSchema;
    use CreatesOperationalCostData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->buildOdooSchema();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00 UTC'));
        $this->mapOdooComponent(CostComponent::Shipping->value);
    }

    private function cost(string $amount, array $with = []): OperationalCostEntry
    {
        $entry = $this->recordCost(CostState::Actual, $amount, CostComponent::Shipping, '2026-11-10');

        if ($with !== []) {
            $entry->forceFill($with)->saveQuietly();
        }

        return $entry->fresh();
    }

    private function build(OperationalCostEntry $entry, ?OdooPreflight $preflight = null): OdooDraftResult
    {
        return app(OdooDraftBuilder::class)->forCostEntry($entry, $preflight ?? $this->odooPreflight());
    }

    /** A company whose books are kept in one currency, with whatever the instance says about that currency's places. */
    private function booksIn(string $code, int $id = 7, ?array $decimals = null): OdooPreflight
    {
        return $this->odooPreflight(
            company: ['id' => self::ODOO_COMPANY, 'currency_id' => $id, 'currency_name' => $code],
            currencies: [$code => $id, 'KWD' => 9],
            currencyDecimals: $decimals,
        );
    }

    public function test_a_riyal_posting_is_written_at_two_places_exactly_as_it_always_was(): void
    {
        $result = $this->build($this->cost('24.505'));

        $this->assertTrue($result->isReady(), implode(', ', $result->refusals));
        $this->assertSame(24.51, $result->draft->lines[0]->debit, 'rounded half-up at two places, as before');
        $this->assertSame(24.51, $result->draft->lines[1]->credit);
        $this->assertSame(2, OdooCurrencyDecimals::for($this->odooPreflight(), 'SAR'), 'and the rule itself answers two for the riyal');
    }

    public function test_a_dinar_posting_keeps_its_third_decimal(): void
    {
        $result = $this->build($this->cost('12.345'), $this->booksIn('KWD', 9, ['KWD' => 3]));

        $this->assertTrue($result->isReady(), implode(', ', $result->refusals));
        $this->assertSame(12.345, $result->draft->lines[0]->debit, 'the fils is a third decimal, not a rounding error');
        $this->assertSame(12.345, $result->draft->lines[1]->credit, 'and the entry still balances at that precision');
        $this->assertTrue($result->draft->balances());
    }

    /** The instance holding FEWER places than the currency has: Odoo would round it by its own rule, so nothing is sent. */
    public function test_an_instance_that_would_round_a_dinar_to_two_places_is_refused_by_name(): void
    {
        $result = $this->build($this->cost('12.345'), $this->booksIn('KWD', 9, ['KWD' => 2]));

        $this->assertFalse($result->isReady());
        $this->assertContains('odoo_currency_decimals_are_too_few:KWD', $result->refusals);
    }

    /** The instance saying NOTHING about a three-decimal currency is the same silent rounding, arrived at by omission. */
    public function test_an_instance_that_says_nothing_about_a_dinar_is_refused_by_name(): void
    {
        $result = $this->build($this->cost('12.345'), $this->booksIn('KWD', 9));

        $this->assertFalse($result->isReady());
        $this->assertContains('odoo_currency_decimals_were_not_read:KWD', $result->refusals);
    }

    /** But saying nothing about a TWO-decimal currency refuses nothing: two is what it would have been written at anyway. */
    public function test_an_instance_that_says_nothing_about_the_riyal_refuses_nothing(): void
    {
        $this->assertTrue($this->build($this->cost('10'))->isReady());
    }

    /** More places than the currency has is not an invitation: a riyal is written at two, whatever the instance offers. */
    public function test_an_instance_offering_more_places_than_the_currency_has_does_not_widen_it(): void
    {
        $preflight = $this->odooPreflight(currencyDecimals: ['SAR' => 4]);

        $this->assertSame(2, OdooCurrencyDecimals::for($preflight, 'SAR'));
        $this->assertSame(24.51, $this->build($this->cost('24.505'), $preflight)->draft->lines[0]->debit);
    }

    /**
     * The ORIGINAL figure carries ITS OWN currency's decimals, not the company's.
     *
     * A Kuwaiti invoice on a riyal-keeping company's entry is still a dinar figure, and rounding it to two places would make the line
     * disagree with the invoice it exists to be checked against -- which is the whole reason the original rides along.
     */
    public function test_a_dinar_invoice_on_a_riyal_companys_entry_carries_three_places(): void
    {
        $preflight = $this->odooPreflight(
            currencies: [self::ODOO_CURRENCY_CODE => self::ODOO_CURRENCY_ID, 'KWD' => 9],
            currencyDecimals: ['KWD' => 3],
        );
        $entry = $this->cost('12.345', ['currency' => 'KWD', 'base_currency' => 'SAR', 'base_amount' => '151.230000', 'fx_rate' => '12.2500000000']);

        $result = $this->build($entry, $preflight);

        $this->assertTrue($result->isReady(), implode(', ', $result->refusals));
        $this->assertSame(151.23, $result->draft->lines[0]->debit, 'booked in the money the books are kept in');
        $this->assertSame(12.345, $result->draft->lines[0]->amountCurrency, 'and the original keeps its fils');
        $this->assertSame(-12.345, $result->draft->lines[1]->amountCurrency);
    }

    /** And a dinar original the instance says nothing about is refused rather than quietly rounded to two. */
    public function test_a_dinar_invoice_the_instance_says_nothing_about_is_refused(): void
    {
        $preflight = $this->odooPreflight(currencies: [self::ODOO_CURRENCY_CODE => self::ODOO_CURRENCY_ID, 'KWD' => 9]);
        $entry = $this->cost('12.345', ['currency' => 'KWD', 'base_currency' => 'SAR', 'base_amount' => '151.230000']);

        $result = $this->build($entry, $preflight);

        $this->assertFalse($result->isReady());
        $this->assertContains('odoo_currency_decimals_were_not_read:KWD', $result->refusals);
    }
}
