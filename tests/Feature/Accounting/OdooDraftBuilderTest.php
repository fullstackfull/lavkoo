<?php

namespace Tests\Feature\Accounting;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OdooAccountMapping;
use App\Models\OperationalCostEntry;
use App\Services\Accounting\Odoo\OdooDraftBuilder;
use App\Services\Accounting\Odoo\OdooDraftResult;
use App\Services\Accounting\Odoo\OdooInstance;
use App\Services\Accounting\Odoo\OdooPreflight;
use App\Services\Accounting\Odoo\OdooTransport;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsOdooWorld;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesOperationalCostData;
use Tests\TestCase;

/**
 * Turning one settled cost from our own ledger into one DRAFT journal entry for the customer's Odoo.
 *
 * **Nothing here computes a cost.** `OperationalCosts` is the only engine for costs, FIFO, commissions and profitability; this
 * reads an approved figure and expresses it in the customer's chart of accounts. These tests are therefore mostly about what is
 * REFUSED, because every refusal here is a wrong number that would otherwise have gone into somebody's books looking right:
 *
 *  - an estimate posted as a real expense, which is a policy decision nobody has made;
 *  - an estimate posted alongside the charge that superseded it, booking the service twice;
 *  - a `missing` cost posted as a zero, which is a claim rather than an absence;
 *  - a component posted to an account nobody chose;
 *  - a negative amount written as a negative debit instead of a credit;
 *  - a foreign-currency figure posted at its converted value with the original dropped.
 *
 * And no field name here is ours. Odoo's developer reference has no page for `account.move` or `account.move.line`, so every
 * field written is one the customer's own instance reported — which is also what survives Odoo renaming one between versions.
 */
class OdooDraftBuilderTest extends TestCase
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
    }

    // ---- what is built ------------------------------------------------------------------------------------------

    public function test_a_settled_cost_becomes_a_two_line_entry_that_balances(): void
    {
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $entry = $this->settledCost('24.50');

        $result = $this->build($entry);

        $this->assertTrue($result->isReady(), implode(', ', $result->refusals));
        $this->assertTrue($result->draft->balances());
        $this->assertCount(2, $result->draft->lines);
        $this->assertSame(24.5, $result->draft->lines[0]->debit, 'the expense is debited');
        $this->assertSame(0.0, $result->draft->lines[0]->credit);
        $this->assertSame(0.0, $result->draft->lines[1]->debit);
        $this->assertSame(24.5, $result->draft->lines[1]->credit, 'and the counterpart credited');
        $this->assertSame(self::ODOO_EXPENSE_ACCOUNT, $result->draft->lines[0]->accountId);
        $this->assertSame(self::ODOO_COUNTERPART_ACCOUNT, $result->draft->lines[1]->accountId);
    }

    public function test_the_entry_carries_our_own_reference_so_odoo_can_be_asked_about_it(): void
    {
        // The only uniqueness Odoo can be asked for: it documents no external-reference field, no constraint and no upsert, so
        // the reference in `ref` is what a retry searches for instead of guessing.
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $entry = $this->settledCost('10');

        $result = $this->build($entry);

        $this->assertSame($result->key->reference, $result->draft->reference);
        $this->assertStringStartsWith('PKSA-COST-' . $entry->id . '-', $result->draft->reference);
        $this->assertSame([['ref', '=', $result->draft->reference]], $result->key->searchDomain());
    }

    public function test_the_entry_is_never_given_a_state_because_a_draft_is_all_this_creates(): void
    {
        // Odoo documents no method that moves an entry to posted, and the final posting is behind its own switch and a recorded
        // approval. So `state` is checked to EXIST on the instance and never written: a new move is a draft because Odoo makes
        // it one.
        $this->mapOdooComponent(CostComponent::Shipping->value);

        $values = $this->build($this->settledCost('10'))->draft->toValues($this->odooPreflight());

        $this->assertArrayNotHasKey('state', $values);
        $this->assertSame('entry', $values['move_type'], 'a miscellaneous journal entry, not an invoice or a bill');
        $this->assertSame(self::ODOO_JOURNAL, $values['journal_id']);
        $this->assertSame(self::ODOO_COMPANY, $values['company_id']);
        $this->assertSame('2026-11-10', $values['date'], 'the ledger\'s own period date, which every cost report groups by');
    }

    public function test_the_lines_are_written_as_odoos_own_create_commands(): void
    {
        $this->mapOdooComponent(CostComponent::Shipping->value, taxId: 9, analyticId: 31);

        $values = $this->build($this->settledCost('10'))->draft->toValues($this->odooPreflight());

        $this->assertSame(0, $values['line_ids'][0][0], 'nought means "create a line from these values"');
        $this->assertSame([[6, 0, [9]]], $values['line_ids'][0][2]['tax_ids'], 'six means "replace the set with exactly these"');
        $this->assertSame(['31' => 100], $values['line_ids'][0][2]['analytic_distribution'], 'the newer cost-centre field is a distribution');
    }

    public function test_the_cost_centre_uses_whichever_field_this_instance_has(): void
    {
        // Odoo renamed it and documents NEITHER name, so both are looked for and the one found is the one written. An instance
        // with the older field gets a plain id, not a distribution map.
        $this->mapOdooComponent(CostComponent::Shipping->value, analyticId: 31);
        $older = $this->odooPreflight(lineFields: ['account_id', 'name', 'debit', 'credit', 'analytic_account_id']);

        $values = $this->build($this->settledCost('10'))->draft->toValues($older);

        $this->assertSame(31, $values['line_ids'][0][2]['analytic_account_id']);
        $this->assertArrayNotHasKey('analytic_distribution', $values['line_ids'][0][2]);
    }

    public function test_an_instance_with_no_cost_centre_field_writes_none_rather_than_refusing(): void
    {
        // Not every Odoo has analytic accounting. That is a fact to show a person, not an error: the entry posts without a cost
        // centre.
        $this->mapOdooComponent(CostComponent::Shipping->value, analyticId: 31);
        $plain = $this->odooPreflight(lineFields: ['account_id', 'name', 'debit', 'credit']);

        $result = $this->build($this->settledCost('10'), $plain);

        $this->assertTrue($result->isReady());
        $this->assertSame(['account_id', 'name', 'debit', 'credit'], array_keys($result->draft->toValues($plain)['line_ids'][0][2]));
    }

    public function test_an_instance_without_a_narration_field_is_not_sent_one(): void
    {
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $bare = $this->odooPreflight(moveFields: ['move_type', 'journal_id', 'date', 'ref', 'line_ids', 'company_id', 'currency_id', 'state']);

        $values = $this->build($this->settledCost('10'), $bare)->draft->toValues($bare);

        $this->assertArrayNotHasKey('narration', $values);
    }

    public function test_the_note_records_where_the_figure_came_from(): void
    {
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $entry = $this->settledCost('10');

        $values = $this->build($entry)->draft->toValues($this->odooPreflight());

        $this->assertStringContainsString('ledger entry #' . $entry->id, $values['narration']);
        $this->assertStringContainsString('actual', $values['narration'], 'and which state the ledger had it in');
    }

    public function test_a_line_name_carries_no_buyer_detail(): void
    {
        // A journal entry is not a place for somebody's name or address, and this integration never has a reason to put one
        // there. The component, the channel and the ledger row are enough to find it.
        $this->mapOdooComponent(CostComponent::MarketplaceCommission->value);
        $entry = $this->settledCost('12', CostComponent::MarketplaceCommission, ['channel' => 'noon']);

        $name = $this->build($entry)->draft->lines[0]->name;

        $this->assertSame('marketplace_commission · noon · ledger #' . $entry->id, $name);
    }

    // ---- money ---------------------------------------------------------------------------------------------------

    public function test_a_negative_amount_is_a_credit_and_never_a_negative_debit(): void
    {
        // Accounting, not arithmetic: `account.move.line` carries two non-negative columns and the direction is which one holds
        // the figure. `debit: -12.00` is a different statement from `credit: 12.00`, and it makes a reversal impossible to tell
        // from a mistake.
        $this->mapOdooComponent(CostComponent::MarketplaceRefund->value);
        $entry = $this->settledCost('-12.00', CostComponent::MarketplaceRefund);

        $draft = $this->build($entry)->draft;

        $this->assertSame(0.0, $draft->lines[0]->debit);
        $this->assertSame(12.0, $draft->lines[0]->credit);
        $this->assertSame(12.0, $draft->lines[1]->debit, 'and the counterpart turns the other way');
        foreach ($draft->amounts() as $amount) {
            $this->assertGreaterThanOrEqual(0.0, $amount, 'no figure on an entry is ever negative');
        }
    }

    public function test_halalas_survive_the_journey_to_a_monetary_field(): void
    {
        $this->mapOdooComponent(CostComponent::Shipping->value);

        $draft = $this->build($this->settledCost('24.555'))->draft;

        $this->assertSame(24.56, $draft->lines[0]->debit, 'rounded once, here, half-up -- not by Odoo at a moment nobody is watching');
        $this->assertTrue($draft->balances());
    }

    public function test_a_cost_of_nothing_is_an_entry_of_nothing_and_still_balances(): void
    {
        $this->mapOdooComponent(CostComponent::Shipping->value);

        $draft = $this->build($this->settledCost('0'))->draft;

        $this->assertSame([0.0, 0.0, 0.0, 0.0], $draft->amounts());
        $this->assertTrue($draft->balances());
    }

    // ---- what is refused -----------------------------------------------------------------------------------------

    public function test_an_estimate_is_not_posted_as_a_real_expense(): void
    {
        // THE policy refusal. The ledger knows the difference between what a rule computed and what the provider charged, and
        // posting the first as an expense is an accounting decision the owner has to approve. Until then the estimate is for
        // analysis and nothing else.
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $entry = $this->recordCost(CostState::Estimated, '30', CostComponent::Shipping);

        $result = $this->build($entry);

        $this->assertFalse($result->isReady());
        $this->assertSame('cost_is_an_estimate_and_no_approved_policy_posts_estimates', $result->firstRefusal());
    }

    public function test_a_disputed_cost_is_not_posted(): void
    {
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $entry = $this->recordCost(CostState::Disputed, '30', CostComponent::Shipping);

        $result = $this->build($entry);

        $this->assertFalse($result->isReady());
        $this->assertContains('cost_is_not_settled:disputed', $result->refusals);
    }

    public function test_a_cost_nobody_could_determine_is_not_posted_as_a_zero(): void
    {
        // `missing` means nobody knew the amount. A zero in a ledger is a claim that it cost nothing.
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $entry = $this->recordCost(CostState::Missing, null, CostComponent::Shipping);

        $result = $this->build($entry);

        $this->assertFalse($result->isReady());
        $this->assertContains('cost_has_no_amount', $result->refusals);
    }

    public function test_a_superseded_estimate_is_not_posted_beside_the_charge_that_replaced_it(): void
    {
        // The ledger supersedes the estimate when the real charge arrives, so only one row of a slot is effective. Posting both
        // would book the same service twice -- which is exactly the mistake Odoo cannot undo by itself.
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $slot = ['subjectType' => 'shipment', 'subjectId' => 4242, 'serviceRef' => 'S1'];
        $estimate = $this->recordCost(CostState::Estimated, '30', CostComponent::Shipping, '2026-11-10', $slot + ['identity' => 'same-slot-estimate']);
        $this->recordCost(CostState::Actual, '28', CostComponent::Shipping, '2026-11-10', $slot + ['identity' => 'same-slot-actual']);

        $result = $this->build($estimate->fresh());

        $this->assertFalse($result->isReady());
        $this->assertContains('cost_was_superseded', $result->refusals);
    }

    public function test_an_unmapped_component_is_refused_by_name_and_never_defaulted(): void
    {
        // No fallback account and no suspense account. An entry posted to an account nobody chose is wrong in a way that looks
        // right on a report until somebody reconciles the quarter.
        $entry = $this->settledCost('10', CostComponent::Picking);

        $result = $this->build($entry);

        $this->assertFalse($result->isReady());
        $this->assertContains('odoo_account_not_mapped:cost_component:picking', $result->refusals);
        $this->assertContains('odoo_account_not_mapped:counterpart:picking', $result->refusals);
    }

    public function test_a_component_mapped_without_a_counterpart_is_not_half_postable(): void
    {
        $this->odooMapping(OdooAccountMapping::KIND_COST_COMPONENT, 'shipping');
        $entry = $this->settledCost('10');

        $result = $this->build($entry);

        $this->assertFalse($result->isReady());
        $this->assertSame(['odoo_account_not_mapped:counterpart:shipping'], $result->refusals);
    }

    public function test_a_mapping_nobody_approved_is_not_used(): void
    {
        // A row somebody is still drafting must not start posting into their books.
        $this->mapOdooComponent(CostComponent::Shipping->value);
        OdooAccountMapping::query()->update(['approved_at' => null]);

        $result = $this->build($this->settledCost('10'));

        $this->assertFalse($result->isReady());
        $this->assertContains('odoo_account_not_mapped:cost_component:shipping', $result->refusals);
    }

    public function test_an_instance_that_is_not_ready_is_refused_before_anything_is_looked_up(): void
    {
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $notReady = new OdooPreflight(
            instance: new OdooInstance(true, true, OdooTransport::XmlRpc, '18.0', 18, 7, 'books'),
            models: array_fill_keys(OdooPreflight::MODELS, true),
            fields: ['account.move' => [], 'account.move.line' => []],
            missingRequired: ['account.move.ref'],
            company: ['id' => self::ODOO_COMPANY, 'currency_id' => self::ODOO_CURRENCY_ID],
        );

        $result = $this->build($this->settledCost('10'), $notReady);

        $this->assertFalse($result->isReady());
        $this->assertContains('odoo_field_missing:account.move.ref', $result->refusals);
    }

    public function test_a_foreign_currency_cost_is_refused_rather_than_posted_without_its_original_figure(): void
    {
        // A DECLARED GAP. The line can carry `currency_id` and `amount_currency`, but Odoo's own id for that currency comes from
        // a currency map that is not built yet -- and posting the converted figure alone would leave an entry nobody can check
        // against the invoice it came from.
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $entry = $this->settledCost('10');
        $entry->forceFill(['currency' => 'USD', 'base_currency' => 'SAR', 'base_amount' => '37.500000', 'fx_rate' => '3.7500000000'])->saveQuietly();

        $result = $this->build($entry->fresh());

        $this->assertFalse($result->isReady());
        $this->assertSame('odoo_currency_map_not_built:USD', $result->firstRefusal());
    }

    public function test_an_instance_with_no_currency_field_on_a_line_says_that_instead(): void
    {
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $entry = $this->settledCost('10');
        $entry->forceFill(['currency' => 'USD', 'base_currency' => 'SAR', 'base_amount' => '37.500000'])->saveQuietly();
        $bare = $this->odooPreflight(lineFields: ['account_id', 'name', 'debit', 'credit']);

        $result = $this->build($entry->fresh(), $bare);

        $this->assertSame('odoo_cannot_record_a_foreign_currency_amount', $result->firstRefusal());
    }

    public function test_a_company_nobody_read_is_refused_rather_than_assumed_to_be_the_first_one(): void
    {
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $noCompany = $this->odooPreflight(company: []);

        $result = $this->build($this->settledCost('10'), $noCompany);

        $this->assertFalse($result->isReady());
        $this->assertSame(['odoo_company_was_not_read'], $result->refusals);
    }

    // ---- the mapping narrows -------------------------------------------------------------------------------------

    public function test_the_most_specific_mapping_wins_and_not_the_first_row_inserted(): void
    {
        // Otherwise which account an entry posts to would depend on insertion order, which is not a decision anybody made.
        $this->odooMapping(OdooAccountMapping::KIND_COST_COMPONENT, 'shipping', account: 100);
        $this->odooMapping(OdooAccountMapping::KIND_COST_COMPONENT, 'shipping', account: 200, channel: 'noon');
        $this->odooMapping(OdooAccountMapping::KIND_COST_COMPONENT, 'shipping', account: 300, channel: 'noon', currency: 'SAR');
        $this->odooMapping(OdooAccountMapping::KIND_COUNTERPART, OdooAccountMapping::CODE_DEFAULT);
        $entry = $this->settledCost('10', CostComponent::Shipping, ['channel' => 'noon']);

        $draft = $this->build($entry)->draft;

        $this->assertSame(300, $draft->lines[0]->accountId, 'channel AND currency beats channel, which beats the general row');
    }

    public function test_a_general_mapping_serves_a_channel_with_none_of_its_own(): void
    {
        $this->odooMapping(OdooAccountMapping::KIND_COST_COMPONENT, 'shipping', account: 100);
        $this->odooMapping(OdooAccountMapping::KIND_COUNTERPART, OdooAccountMapping::CODE_DEFAULT);
        $entry = $this->settledCost('10', CostComponent::Shipping, ['channel' => 'amazon']);

        $draft = $this->build($entry)->draft;

        $this->assertSame(100, $draft->lines[0]->accountId);
        $this->assertSame('amazon', $draft->salesChannel, 'and the entry says which channel it is, because two are never combined');
    }

    public function test_a_components_own_counterpart_beats_the_general_one(): void
    {
        $this->odooMapping(OdooAccountMapping::KIND_COST_COMPONENT, 'shipping');
        $this->odooMapping(OdooAccountMapping::KIND_COUNTERPART, OdooAccountMapping::CODE_DEFAULT, account: 900);
        $this->odooMapping(OdooAccountMapping::KIND_COUNTERPART, 'shipping', account: 901);

        $draft = $this->build($this->settledCost('10'))->draft;

        $this->assertSame(901, $draft->lines[1]->accountId);
    }

    // ---- fixtures -----------------------------------------------------------------------------------------------

    private function build(OperationalCostEntry $entry, ?OdooPreflight $preflight = null): OdooDraftResult
    {
        return app(OdooDraftBuilder::class)->forCostEntry($entry, $preflight ?? $this->odooPreflight());
    }

    /** @param array<string, mixed> $with */
    private function settledCost(string $amount, CostComponent $component = CostComponent::Shipping, array $with = []): OperationalCostEntry
    {
        return $this->recordCost(CostState::Actual, $amount, $component, '2026-11-10', $with);
    }
}
