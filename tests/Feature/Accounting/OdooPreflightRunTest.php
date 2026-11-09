<?php

namespace Tests\Feature\Accounting;

use App\Models\SalesChannelAccount;
use App\Services\Accounting\Odoo\OdooPreflight;
use App\Services\Accounting\Odoo\OdooPreflightRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsOdooWorld;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * Asking a customer's Odoo what it actually is — every call a READ, with the write switches off.
 *
 * This is the step that makes the rest honest. Odoo's developer reference has **no page for `account.move` or
 * `account.move.line`**, so the field names a journal entry needs are not in any documentation to copy from: they come from the
 * instance, and a name it does not have is a refusal somebody can read rather than a fault at posting time.
 *
 * Two things it deliberately does not do, and both are asserted here. It never reports a WRITE permission as verified, because
 * Odoo documents no way to ask for one. And it never picks a company: in a multi-company database, posting into the wrong one
 * reads as correct on every screen until somebody consolidates.
 */
class OdooPreflightRunTest extends TestCase
{
    use BuildsOdooWorld;
    use BuildsSalesChannelSchema;

    private SalesChannelAccount $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00 UTC'));
        // Reads only: the write switches stay OFF for every test in this file, which is the state the mapping work is done in.
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => false, 'odoo.draft_creation_enabled' => false]);
        $this->connection = $this->odooConnection(signedIn: false);
    }

    public function test_a_ready_instance_reports_its_fields_its_company_and_what_it_holds(): void
    {
        $this->fakeInstance();

        $preflight = app(OdooPreflightRun::class)->run($this->connection);

        $this->assertTrue($preflight->couldBuildAnEntry());
        $this->assertSame([], $preflight->missingRequired);
        $this->assertSame(1, $preflight->company['id']);
        $this->assertSame('Pharmacy KSA', $preflight->company['name']);
        $this->assertSame(3, $preflight->company['currency_id'], 'the id a later call needs');
        $this->assertSame('SAR', $preflight->company['currency_name'], 'and the label a person reads');
        $this->assertSame(120, $preflight->accountsRead);
        $this->assertSame('char', $preflight->fields['account.move']['ref']['type']);
        $this->assertSame('Reference', $preflight->fields['account.move']['ref']['label']);
    }

    public function test_every_call_it_makes_is_a_read_and_the_write_switches_stay_off(): void
    {
        // The whole point of running it first: a person connects an instance, looks at it and builds the mapping with writing
        // still refused. If any call here needed the write switch, that order would be impossible.
        $this->fakeInstance();

        $preflight = app(OdooPreflightRun::class)->run($this->connection);

        $this->assertTrue($preflight->couldBuildAnEntry());
        $this->assertFalse(config('odoo.external_writes_enabled'), 'still off, and nothing refused');
        foreach ($this->bodies() as $body) {
            $this->assertStringNotContainsString('<string>create</string>', $body);
            $this->assertStringNotContainsString('<string>write</string>', $body);
            $this->assertStringNotContainsString('<string>unlink</string>', $body);
        }
    }

    public function test_a_field_this_instance_does_not_have_is_named_with_its_model(): void
    {
        // A rename between versions is exactly this case, and Odoo's own documentation does not announce one.
        $this->fakeInstance(moveFields: ['move_type', 'journal_id', 'date', 'line_ids', 'company_id', 'currency_id', 'state']);

        $preflight = app(OdooPreflightRun::class)->run($this->connection);

        $this->assertFalse($preflight->couldBuildAnEntry());
        $this->assertSame(['account.move.ref'], $preflight->missingRequired);
        $this->assertContains('odoo_field_missing:account.move.ref', $preflight->readinessCodes());
    }

    public function test_a_database_without_accounting_is_a_fact_rather_than_a_field_list_to_attempt(): void
    {
        $this->fakeInstance(models: ['res.company', 'res.currency']);

        $preflight = app(OdooPreflightRun::class)->run($this->connection);

        $this->assertFalse($preflight->couldBuildAnEntry());
        $this->assertContains('odoo_model_missing:account.move', $preflight->problems);
        $this->assertSame([], $preflight->fields, 'and no fields were asked for');
    }

    public function test_several_companies_and_no_choice_is_refused_rather_than_resolved_to_the_first_row(): void
    {
        $this->fakeInstance(companies: [
            ['id' => 1, 'name' => 'Pharmacy KSA', 'currency_id' => [3, 'SAR']],
            ['id' => 2, 'name' => 'Pharmacy UAE', 'currency_id' => [4, 'AED']],
        ]);

        $preflight = app(OdooPreflightRun::class)->run($this->connection);

        $this->assertFalse($preflight->couldBuildAnEntry());
        $this->assertSame(['odoo_several_companies_and_none_chosen'], $preflight->problems);
        $this->assertSame([], $preflight->company);
    }

    public function test_the_company_a_person_chose_is_the_one_read(): void
    {
        $this->connection->forceFill(['settings' => array_merge((array) $this->connection->settings, ['company_id' => 2])])->save();
        $this->fakeInstance(companies: [['id' => 2, 'name' => 'Pharmacy UAE', 'currency_id' => [4, 'AED']]]);

        $preflight = app(OdooPreflightRun::class)->run($this->connection->fresh());

        $this->assertSame(2, $preflight->company['id']);
        $this->assertTrue($preflight->couldBuildAnEntry());
    }

    public function test_a_chosen_company_that_is_not_in_the_instance_is_named(): void
    {
        $this->connection->forceFill(['settings' => array_merge((array) $this->connection->settings, ['company_id' => 9])])->save();
        $this->fakeInstance(companies: []);

        $preflight = app(OdooPreflightRun::class)->run($this->connection->fresh());

        $this->assertSame(['odoo_chosen_company_is_not_in_this_instance:9'], $preflight->problems);
    }

    public function test_an_instance_that_cannot_be_reached_carries_its_reason_and_asks_nothing_else(): void
    {
        Http::fake([
            self::ODOO_COMMON => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: books.example.test'),
            self::ODOO_HOST . '/web/version' => Http::response([], 503),
        ]);

        $preflight = app(OdooPreflightRun::class)->run($this->connection);

        $this->assertFalse($preflight->couldBuildAnEntry());
        $this->assertSame(['odoo_version_could_not_be_read'], $preflight->problems);
        $this->assertSame([], $this->bodies(), 'nothing was asked of a host nobody could reach');
    }

    public function test_an_instance_that_refuses_the_credentials_is_a_different_problem_from_one_nobody_can_reach(): void
    {
        // Reachable and not signed in: a person fixes those in different places, so they are never merged into "failed".
        Http::fake([self::ODOO_COMMON => Http::sequence()
            ->push($this->odooXml(['server_version' => '18.0']))
            ->push($this->odooXml(false))]);

        $preflight = app(OdooPreflightRun::class)->run($this->connection);

        $this->assertTrue($preflight->instance->reachable);
        $this->assertFalse($preflight->instance->authenticated);
        $this->assertSame(['odoo_refused_the_credentials'], $preflight->problems);
    }

    public function test_an_instance_with_no_cost_centre_field_says_so_without_being_refused(): void
    {
        // Not every Odoo has analytic accounting, and Odoo has used two different names for the field. Neither is an error.
        $this->fakeInstance(lineFields: ['account_id', 'name', 'debit', 'credit']);

        $preflight = app(OdooPreflightRun::class)->run($this->connection);

        $this->assertTrue($preflight->couldBuildAnEntry());
        $this->assertNull($preflight->analyticField());
        $this->assertContains('odoo_records_no_cost_centre', $preflight->readinessCodes());
    }

    public function test_the_models_are_asked_for_in_one_call_rather_than_one_each(): void
    {
        $this->fakeInstance();

        app(OdooPreflightRun::class)->run($this->connection);

        $modelCalls = array_filter($this->bodies(), static fn (string $body): bool => str_contains($body, '<string>ir.model</string>'));
        $this->assertCount(1, $modelCalls, 'ir.model is a table like any other and one domain answers the whole question');
    }

    // ---- fixtures -----------------------------------------------------------------------------------------------

    /**
     * An instance that answers everything the run asks, in the order it asks.
     *
     * @param list<string>|null $models
     * @param list<string>|null $moveFields
     * @param list<string>|null $lineFields
     * @param list<array<string, mixed>>|null $companies
     */
    private function fakeInstance(?array $models = null, ?array $moveFields = null, ?array $lineFields = null, ?array $companies = null): void
    {
        $models ??= OdooPreflight::MODELS;
        $moveFields ??= [...OdooPreflight::REQUIRED_FIELDS['account.move'], ...OdooPreflight::OPTIONAL_FIELDS['account.move']];
        $lineFields ??= [...OdooPreflight::REQUIRED_FIELDS['account.move.line'], 'tax_ids', 'analytic_distribution'];
        $companies ??= [['id' => 1, 'name' => 'Pharmacy KSA', 'currency_id' => [3, 'SAR']]];

        $describe = static fn (array $names): array => array_combine(
            $names,
            array_map(static fn (string $name): array => ['type' => 'char', 'string' => ucfirst(str_replace('_', ' ', $name === 'ref' ? 'reference' : $name))], $names),
        );

        Http::fake([
            self::ODOO_COMMON => Http::sequence()
                ->push($this->odooXml(['server_version' => '18.0', 'server_version_info' => [18, 0, 0, 'final', 0]]))
                ->push($this->odooXml(7)),
            self::ODOO_OBJECT => function (\Illuminate\Http\Client\Request $request) use ($models, $moveFields, $lineFields, $companies, $describe) {
                $body = $request->body();

                return match (true) {
                    str_contains($body, '<string>ir.model</string>') => Http::response($this->odooXml(array_map(static fn (string $model): array => ['model' => $model], $models))),
                    str_contains($body, '<string>account.move.line</string>') => Http::response($this->odooXml($describe($lineFields))),
                    str_contains($body, '<string>account.move</string>') => Http::response($this->odooXml($describe($moveFields))),
                    str_contains($body, '<string>res.company</string>') => Http::response($this->odooXml($companies)),
                    str_contains($body, '<string>search_count</string>') => Http::response($this->odooXml(120)),
                    default => Http::response('', 500),
                };
            },
        ]);
    }

    /** @return list<string> */
    private function bodies(): array
    {
        return Http::recorded(static fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->url(), '/xmlrpc/2/object'))
            ->values()->map(static fn (array $pair): string => $pair[0]->body())->all();
    }
}
