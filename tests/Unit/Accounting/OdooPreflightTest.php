<?php

namespace Tests\Unit\Accounting;

use App\Services\Accounting\Odoo\OdooInstance;
use App\Services\Accounting\Odoo\OdooPreflight;
use App\Services\Accounting\Odoo\OdooTransport;
use PHPUnit\Framework\TestCase;

/**
 * Reading what a customer's Odoo offers, and being honest about the one thing it cannot tell us.
 *
 * Odoo's developer reference documents no `account.move` or `account.move.line` page and states no field renames between
 * versions, so the field names are read from the instance rather than written into this code. These tests pin that, and pin the
 * refusal to overclaim: **there is no documented way to ask Odoo whether the user may write**, so a preflight that said "writes
 * permitted" would be inventing a check nobody performed.
 */
class OdooPreflightTest extends TestCase
{
    public function test_every_field_a_journal_entry_needs_is_declared_so_a_missing_one_can_be_named(): void
    {
        // The point of declaring them: the instance is asked for exactly these, and whatever is absent is reported with the
        // model it was expected on.
        $this->assertContains('move_type', OdooPreflight::REQUIRED_FIELDS['account.move']);
        $this->assertContains('line_ids', OdooPreflight::REQUIRED_FIELDS['account.move']);
        $this->assertContains('state', OdooPreflight::REQUIRED_FIELDS['account.move']);
        $this->assertContains('debit', OdooPreflight::REQUIRED_FIELDS['account.move.line']);
        $this->assertContains('credit', OdooPreflight::REQUIRED_FIELDS['account.move.line']);
        $this->assertContains('account_id', OdooPreflight::REQUIRED_FIELDS['account.move.line']);
    }

    public function test_the_models_it_reads_include_the_two_odoo_does_not_document(): void
    {
        foreach (['account.move', 'account.move.line', 'account.account', 'account.tax', 'res.company', 'res.currency'] as $model) {
            $this->assertContains($model, OdooPreflight::MODELS);
        }
    }

    public function test_an_instance_with_everything_could_build_an_entry(): void
    {
        $preflight = $this->preflight();

        $this->assertTrue($preflight->couldBuildAnEntry());
        $this->assertSame([], $preflight->missingRequired);
    }

    public function test_a_missing_field_is_named_with_its_model_and_stops_the_build(): void
    {
        $preflight = $this->preflight(missingRequired: ['account.move.line.debit']);

        $this->assertFalse($preflight->couldBuildAnEntry());
        $this->assertContains('odoo_field_missing:account.move.line.debit', $preflight->readinessCodes());
    }

    public function test_an_unusable_instance_could_not_build_an_entry_however_complete_its_fields(): void
    {
        $preflight = new OdooPreflight(
            instance: OdooInstance::unreachable('odoo_host_refused_the_connection'),
            fields: $this->fieldsOf(),
        );

        $this->assertFalse($preflight->couldBuildAnEntry());
    }

    // ---- the cost-centre field Odoo renamed and documents under neither name -------------------------------------

    public function test_the_newer_cost_centre_field_is_used_when_the_instance_has_it(): void
    {
        $this->assertSame('analytic_distribution', $this->preflight()->analyticField());
    }

    public function test_the_older_cost_centre_field_is_used_when_that_is_what_the_instance_has(): void
    {
        $fields = $this->fieldsOf();
        unset($fields['account.move.line']['analytic_distribution']);
        $fields['account.move.line']['analytic_account_id'] = ['type' => 'many2one', 'label' => 'Analytic Account'];

        $this->assertSame('analytic_account_id', (new OdooPreflight($this->usable(), fields: $fields))->analyticField());
    }

    public function test_an_instance_with_neither_records_no_cost_centre_and_that_is_not_an_error(): void
    {
        // An instance without analytic accounting is a fact to show a person, not a refusal: the mapping simply will not offer a
        // cost centre.
        $fields = $this->fieldsOf();
        unset($fields['account.move.line']['analytic_distribution']);
        $preflight = new OdooPreflight($this->usable(), fields: $fields);

        $this->assertNull($preflight->analyticField());
        $this->assertContains('odoo_records_no_cost_centre', $preflight->readinessCodes());
        $this->assertTrue($preflight->couldBuildAnEntry(), 'a missing cost centre never stops an entry');
    }

    // ---- what it refuses to claim --------------------------------------------------------------------------------

    public function test_nothing_here_claims_a_write_permission_was_verified(): void
    {
        // Odoo documents no `check_access_rights` and no `has_group` in its external API, and its JSON-2 page says only that
        // operations are validated when they run. So this object has no method that could answer "may we write", and the one it
        // does have is named for what it actually checks.
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(OdooPreflight::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach ($methods as $method) {
            $this->assertStringNotContainsStringIgnoringCase('maywrite', str_replace('_', '', $method));
            $this->assertStringNotContainsStringIgnoringCase('canpost', str_replace('_', '', $method));
            $this->assertStringNotContainsStringIgnoringCase('permission', $method);
        }
        $this->assertContains('couldBuildAnEntry', $methods, 'the honest question is whether an entry can be BUILT');
    }

    public function test_what_was_read_is_counted_so_a_person_sees_evidence_rather_than_a_verdict(): void
    {
        // Counts of what came back are real evidence and stop short of a promise. An instance that answered with 180 accounts
        // and 12 taxes is plainly reachable and readable; whether it will accept a write is Odoo's to say at the time.
        $preflight = $this->preflight();

        $this->assertSame(180, $preflight->accountsRead);
        $this->assertSame(12, $preflight->taxesRead);
        $this->assertSame(4, $preflight->journalsRead);
    }

    public function test_a_problem_the_instance_itself_reported_survives_into_the_codes(): void
    {
        $preflight = $this->preflight(problems: ['odoo_external_api_needs_a_custom_plan']);

        $this->assertFalse($preflight->couldBuildAnEntry());
        $this->assertContains('odoo_external_api_needs_a_custom_plan', $preflight->readinessCodes());
    }

    public function test_the_codes_do_not_repeat_themselves(): void
    {
        $preflight = $this->preflight(problems: ['a', 'a', 'b']);

        $this->assertSame(array_unique($preflight->readinessCodes()), $preflight->readinessCodes());
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function usable(): OdooInstance
    {
        return new OdooInstance(
            reachable: true,
            authenticated: true,
            transport: OdooTransport::XmlRpc,
            serverVersion: '18.0',
            majorVersion: 18,
            userId: 2,
            database: 'acme',
        );
    }

    /**
     * @param list<string> $missingRequired
     * @param list<string> $problems
     */
    private function preflight(array $missingRequired = [], array $problems = []): OdooPreflight
    {
        return new OdooPreflight(
            instance: $this->usable(),
            models: array_fill_keys(OdooPreflight::MODELS, true),
            fields: $this->fieldsOf(),
            missingRequired: $missingRequired,
            problems: $problems,
            company: ['id' => 1, 'name' => 'Acme Pharma', 'currency_id' => [1, 'SAR']],
            accountsRead: 180,
            taxesRead: 12,
            journalsRead: 4,
            currenciesRead: 2,
        );
    }

    /** What `fields_get` answers for a complete instance, in the shape this code keeps it. */
    private function fieldsOf(): array
    {
        $of = static fn (array $names): array => array_map(
            static fn (string $type): array => ['type' => $type, 'label' => null],
            $names,
        );

        return [
            'account.move' => $of([
                'move_type' => 'selection', 'journal_id' => 'many2one', 'date' => 'date', 'ref' => 'char',
                'line_ids' => 'one2many', 'company_id' => 'many2one', 'currency_id' => 'many2one', 'state' => 'selection',
            ]),
            'account.move.line' => $of([
                'account_id' => 'many2one', 'name' => 'char', 'debit' => 'monetary', 'credit' => 'monetary',
                'tax_ids' => 'many2many', 'analytic_distribution' => 'json',
            ]),
        ];
    }
}
