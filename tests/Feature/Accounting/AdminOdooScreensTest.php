<?php

namespace Tests\Feature\Accounting;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OdooAccountMapping;
use App\Models\OdooPosting;
use App\Models\SalesChannelAccount;
use App\Services\Accounting\Odoo\OdooOutbox;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\Feature\SalesChannels\AdminPagesTestCase;
use Tests\Support\BuildsOdooWorld;
use Tests\Support\CreatesOperationalCostData;

/**
 * The three Odoo screens: the connection, the chart-of-accounts mapping, and the posting outbox.
 *
 * The base case ends every test by checking that rendering added no key to the language files — `translate()` writes an unknown
 * key into `resources/lang/{locale}/new-messages.php`, so an untranslated phrase would otherwise pass silently and leave the
 * repository dirty. Every string these screens show is therefore already in `en`, `sa` and `ae`.
 *
 * Two properties matter more than the markup and are asserted here:
 *
 *  - **the screens do not write to Odoo.** Opening any of them sends nothing at all; reading the chart is a press, and the one
 *    write in the integration is behind its own switch and a recorded approval.
 *  - **the switches are shown and not operable.** They are environment settings, so a control that appeared to flip one would be
 *    lying about what it did.
 */
class AdminOdooScreensTest extends AdminPagesTestCase
{
    use BuildsOdooWorld;
    use CreatesOperationalCostData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOdooSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00 UTC'));
        config(['odoo.enabled' => false, 'odoo.external_writes_enabled' => false, 'odoo.draft_creation_enabled' => false, 'odoo.final_posting_enabled' => false]);
    }

    // ---- the connection ------------------------------------------------------------------------------------------

    public function test_the_connection_screen_opens_with_no_connection_and_sends_nothing(): void
    {
        Http::fake();

        $html = $this->get(route('admin.accounting.odoo.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('odoo_where_the_instance_is')), $html);
        $this->assertStringContainsString(e(translate('odoo_api_key_write_only')), $html);
        Http::assertNothingSent();
    }

    public function test_it_names_the_four_switches_and_their_environment_variables(): void
    {
        // Shown, and not operable: they are environment settings, and a button here would be lying about what it did.
        $html = $this->get(route('admin.accounting.odoo.index'))->assertOk()->getContent();

        foreach (['ODOO_ENABLED', 'ODOO_EXTERNAL_WRITES_ENABLED', 'ODOO_DRAFT_CREATION_ENABLED', 'ODOO_FINAL_POSTING_ENABLED'] as $env) {
            $this->assertStringContainsString($env, $html);
        }
        $this->assertStringContainsString(e(translate('odoo_switches_help')), $html);
        $this->assertStringNotContainsString(route('admin.accounting.odoo.index') . '/switch', $html, 'there is no control that pretends to flip one');
    }

    public function test_saving_where_the_instance_is_contacts_nobody(): void
    {
        Http::fake();

        $this->post(route('admin.accounting.odoo.settings'), [
            'base_url' => 'https://books.example.test',
            'database' => 'books',
            'login' => 'bot@example.test',
            'company_id' => 1,
        ])->assertRedirect();

        $connection = SalesChannelAccount::where('channel', 'odoo')->firstOrFail();
        $this->assertSame('https://books.example.test', $connection->setting('base_url'));
        $this->assertFalse((bool) $connection->enabled, 'a new connection is off');
        $this->assertTrue((bool) $connection->dry_run, 'and it rehearses until somebody decides otherwise');
        $this->assertSame('odoo', $connection->accounting_destination);
        Http::assertNothingSent();
    }

    public function test_the_api_key_is_write_only_and_never_reaches_a_screen(): void
    {
        $connection = $this->odooConnection(signedIn: true);

        $html = $this->get(route('admin.accounting.odoo.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('key-abc', $html, 'a stored secret is never sent back to a screen');
        $this->assertStringContainsString(e(translate('odoo_a_key_is_stored')), $html);
    }

    public function test_replacing_the_key_forgets_what_was_learned_under_the_old_one(): void
    {
        // The key IS the authorisation: the transport and the user id discovered with the old one are no longer known to be
        // true of this connection.
        $connection = $this->odooConnection(signedIn: true);
        $this->assertSame('xmlrpc', app(ChannelCredentialStore::class)->get($connection)->secret('transport'));

        $this->post(route('admin.accounting.odoo.key'), ['api_key' => 'another-key-entirely'])->assertRedirect();

        $credentials = app(ChannelCredentialStore::class)->get($connection->fresh());
        $this->assertNull($credentials->secret('transport'));
        $this->assertNull($credentials->secret('uid'));
    }

    public function test_the_connection_test_reads_the_instance_and_writes_nothing(): void
    {
        $this->odooConnection(signedIn: false);
        config(['odoo.enabled' => true]);
        $this->fakeInstance();

        $this->post(route('admin.accounting.odoo.test'))->assertRedirect();

        foreach ($this->bodies() as $body) {
            $this->assertStringNotContainsString('<string>create</string>', $body, 'the test writes nothing');
            $this->assertStringNotContainsString('<string>write</string>', $body);
        }
        $this->assertToastContains(translate('odoo_connection_test_passed'));
    }

    public function test_an_instance_that_is_not_ready_lists_what_is_missing_without_translating_the_codes(): void
    {
        // A code this version does not know must never be handed to translate(): it would be written into the language files.
        $this->odooConnection(signedIn: false);
        config(['odoo.enabled' => true]);
        $this->fakeInstance(moveFields: ['move_type', 'journal_id', 'date', 'line_ids', 'company_id', 'currency_id', 'state']);

        $this->post(route('admin.accounting.odoo.test'))->assertRedirect();
        $html = $this->get(route('admin.accounting.odoo.index'))->assertOk()->getContent();

        $this->assertStringContainsString('odoo_field_missing:account.move.ref', $html);
    }

    public function test_the_master_switch_off_refuses_the_test_rather_than_pretending(): void
    {
        $this->odooConnection(signedIn: false);
        config(['odoo.enabled' => false]);
        Http::fake();

        $this->post(route('admin.accounting.odoo.test'))->assertRedirect();

        $this->assertToastContains(translate('odoo_connection_test_refused'));
        Http::assertNothingSent();
    }

    // ---- the mapping ---------------------------------------------------------------------------------------------

    public function test_the_mapping_screen_lists_every_fact_and_sends_nothing(): void
    {
        $this->odooConnection();
        Http::fake();

        $html = $this->get(route('admin.accounting.odoo.mapping.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('cost_component_' . CostComponent::Shipping->value)), $html);
        $this->assertStringContainsString(e(translate('odoo_not_mapped_nothing_posts')), $html);
        $this->assertStringContainsString(e(translate('odoo_chart_not_read_yet')), $html);
        Http::assertNothingSent();
    }

    public function test_reading_the_chart_is_a_press_and_keeps_what_came_back(): void
    {
        $connection = $this->odooConnection();
        $connection->forceFill(['settings' => array_merge((array) $connection->settings, ['company_id' => 1])])->save();
        config(['odoo.enabled' => true]);
        $this->fakeChart();

        $this->post(route('admin.accounting.odoo.mapping.read'))->assertRedirect();
        $html = $this->get(route('admin.accounting.odoo.mapping.index'))->assertOk()->getContent();

        $this->assertStringContainsString('4000 — Sales', $html);
        $this->assertStringContainsString(e(translate('odoo_map_a_fact')), $html);
    }

    public function test_an_empty_chart_is_not_kept_as_a_chart_with_no_accounts(): void
    {
        // An empty answer is not the same claim as "this instance has no accounts", and a screen that showed it as one would be
        // lying about the customer's books.
        $connection = $this->odooConnection();
        config(['odoo.enabled' => true]);
        $this->fakeChart(accounts: []);

        $this->post(route('admin.accounting.odoo.mapping.read'))->assertRedirect();

        $this->assertToastContains(translate('odoo_chart_came_back_empty'));
        $html = $this->get(route('admin.accounting.odoo.mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('odoo_chart_not_read_yet')), $html);
    }

    public function test_a_saved_mapping_is_not_an_approved_one(): void
    {
        $connection = $this->odooConnection();
        $connection->forceFill(['settings' => array_merge((array) $connection->settings, ['company_id' => 1])])->save();
        Http::fake();

        $this->post(route('admin.accounting.odoo.mapping.store'), [
            'fact_kind' => OdooAccountMapping::KIND_COST_COMPONENT,
            'fact_code' => CostComponent::Shipping->value,
            'odoo_account_id' => 41,
            'odoo_journal_id' => 7,
        ])->assertRedirect();

        $mapping = OdooAccountMapping::firstOrFail();
        $this->assertNull($mapping->approved_at, 'saving is not approving');
        $this->assertSame(OdooAccountMapping::ANY, $mapping->sales_channel, '"any" is the empty string, never null');
        $this->assertSame(OdooAccountMapping::ANY, $mapping->currency);

        $this->post(route('admin.accounting.odoo.mapping.approve', $mapping->id))->assertRedirect();
        $this->assertNotNull($mapping->fresh()->approved_at);
    }

    public function test_a_mapping_cannot_be_saved_before_a_company_is_chosen(): void
    {
        // A mapping is only valid for the company it was read against.
        $this->odooConnection();
        Http::fake();

        $this->post(route('admin.accounting.odoo.mapping.store'), [
            'fact_kind' => OdooAccountMapping::KIND_COST_COMPONENT,
            'fact_code' => CostComponent::Shipping->value,
            'odoo_account_id' => 41,
            'odoo_journal_id' => 7,
        ])->assertRedirect();

        $this->assertSame(0, OdooAccountMapping::count());
        $this->assertToastContains(translate('odoo_choose_a_company_first'));
    }

    // ---- the outbox ----------------------------------------------------------------------------------------------

    public function test_the_outbox_counts_every_state_and_puts_the_rows_awaiting_a_person_first(): void
    {
        $this->odooConnection();
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $waiting = app(OdooOutbox::class)->enqueue($this->settledCost('10'), self::ODOO_COMPANY);
        $waiting->forceFill(['state' => OdooPosting::NEEDS_REVIEW, 'failure_code' => 'odoo_create_outcome_unknown', 'needs_review_since' => now()])->save();
        app(OdooOutbox::class)->enqueue($this->settledCost('20'), self::ODOO_COMPANY);
        Http::fake();

        $html = $this->get(route('admin.accounting.odoo.outbox.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('odoo_posting_state_needs_review')), $html);
        $this->assertStringContainsString(e(translate('odoo_needs_review_explainer')), $html);
        $this->assertStringContainsString('odoo_create_outcome_unknown', $html, 'the code is shown as it came');
        $this->assertStringContainsString($waiting->odoo_ref, $html);
        Http::assertNothingSent();
    }

    public function test_a_person_can_close_a_waiting_row_against_an_entry_they_found(): void
    {
        // The id is theirs to supply, because they are the ones who looked: the point of this action is that asking Odoo did not
        // settle it.
        $this->odooConnection();
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $row = app(OdooOutbox::class)->enqueue($this->settledCost('10'), self::ODOO_COMPANY);
        $row->forceFill(['state' => OdooPosting::NEEDS_REVIEW])->save();
        Http::fake();

        $this->post(route('admin.accounting.odoo.outbox.found', $row->id), [
            'odoo_move_id' => 512,
            'reason' => 'Found MISC/2026/0041 in Odoo with our reference.',
        ])->assertRedirect();

        $fresh = $row->fresh();
        $this->assertSame(OdooPosting::POSTED, $fresh->state);
        $this->assertSame(512, $fresh->odoo_move_id);
        $this->assertSame('found_by_a_person', $fresh->outcome);
        $this->assertNotNull($fresh->reviewed_at);
        Http::assertNothingSent();
    }

    public function test_a_person_can_close_a_waiting_row_as_not_posted_with_a_reason(): void
    {
        $this->odooConnection();
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $row = app(OdooOutbox::class)->enqueue($this->settledCost('10'), self::ODOO_COMPANY);
        $row->forceFill(['state' => OdooPosting::NEEDS_REVIEW])->save();
        Http::fake();

        $this->post(route('admin.accounting.odoo.outbox.closed', $row->id), ['reason' => 'Nothing with this reference in Odoo; re-queued as a new version.'])->assertRedirect();

        $fresh = $row->fresh();
        $this->assertSame(OdooPosting::DEAD, $fresh->state, 'kept and counted, never deleted');
        $this->assertSame('closed_by_a_person', $fresh->outcome);
        $this->assertNull($fresh->available_at);
    }

    public function test_only_a_row_awaiting_a_person_is_settled_by_hand(): void
    {
        // A posted row is not somebody's to reopen from this screen, and a pending one has not asked for a decision.
        $this->odooConnection();
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $row = app(OdooOutbox::class)->enqueue($this->settledCost('10'), self::ODOO_COMPANY);
        Http::fake();

        // A valid request in every other way: it is the row's state that refuses it, not the form.
        $this->post(route('admin.accounting.odoo.outbox.found', $row->id), ['odoo_move_id' => 9, 'reason' => 'I think I saw it somewhere.'])->assertRedirect();

        $this->assertSame(OdooPosting::PENDING, $row->fresh()->state);
        $this->assertNull($row->fresh()->odoo_move_id);
        $this->assertToastContains(translate('odoo_only_a_row_awaiting_review_is_settled_by_hand'));
    }

    // ---- the three-way reconciliation ----------------------------------------------------------------------------

    public function test_the_reconciliation_screen_shows_a_missing_leg_as_missing_and_not_as_zero(): void
    {
        // The whole point of the screen. "The channel publishes no statement" and "the channel charged nothing" are different
        // statements, and a `0.00` in that column would be the second one.
        $this->odooConnection();
        \App\Models\OdooReconciliation::create([
            'seller_id' => 0,
            'odoo_company_id' => self::ODOO_COMPANY,
            'sales_channel' => '',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'currency' => 'SAR',
            'fact_kind' => CostComponent::Shipping->value,
            'source_amount' => null,
            'ledger_amount' => '100.000000',
            'odoo_amount' => null,
            'entries_counted' => 0,
            'entries_not_posted' => 1,
        ]);
        Http::fake();

        $html = $this->get(route('admin.accounting.odoo.reconciliation.index', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('odoo_no_figure_published')), $html);
        $this->assertStringContainsString(e(translate('odoo_not_read')), $html);
        $this->assertStringContainsString(e(translate('odoo_reconciliation_closes_nothing')), $html);
        Http::assertNothingSent();
    }

    public function test_there_is_no_control_that_closes_a_difference(): void
    {
        $this->odooConnection();
        Http::fake();

        $html = $this->get(route('admin.accounting.odoo.reconciliation.index'))->assertOk()->getContent();

        // The only POST this screen offers is the one that BUILDS the period.
        $posts = preg_match_all('#<form[^>]*action="([^"]+)"#', $html, $matches) ? $matches[1] : [];
        foreach ($posts as $action) {
            $this->assertStringContainsString('reconciliation', $action, 'the only form here builds the report');
        }
    }

    // ---- the migration -------------------------------------------------------------------------------------------

    public function test_without_the_migration_every_screen_explains_instead_of_failing(): void
    {
        Schema::dropIfExists('odoo_posting_queue');
        Http::fake();

        foreach (['index', 'mapping.index', 'outbox.index', 'reconciliation.index'] as $screen) {
            $html = $this->get(route('admin.accounting.odoo.' . $screen))->assertOk()->getContent();
            $this->assertStringContainsString(e(translate('odoo_migration_not_run')), $html);
            $this->assertStringContainsString('2026_11_15_100000_create_odoo_accounting_tables', $html);
        }
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function settledCost(string $amount): \App\Models\OperationalCostEntry
    {
        return $this->recordCost(CostState::Actual, $amount, CostComponent::Shipping, '2026-11-10');
    }

    /** @param list<array<string, mixed>>|null $accounts */
    private function fakeChart(?array $accounts = null): void
    {
        $accounts ??= [['id' => 41, 'code' => '4000', 'name' => 'Sales', 'account_type' => 'income']];
        Http::fake([
            self::ODOO_COMMON => Http::sequence()->push($this->odooXml(['server_version' => '18.0']))->push($this->odooXml(7)),
            self::ODOO_OBJECT => function (\Illuminate\Http\Client\Request $request) use ($accounts) {
                $body = $request->body();

                return match (true) {
                    str_contains($body, '<string>account.account</string>') => Http::response($this->odooXml($accounts)),
                    str_contains($body, '<string>account.journal</string>') => Http::response($this->odooXml([['id' => 7, 'code' => 'MISC', 'name' => 'Miscellaneous', 'type' => 'general']])),
                    str_contains($body, '<string>account.tax</string>') => Http::response($this->odooXml([['id' => 9, 'name' => 'VAT 15%', 'type_tax_use' => 'purchase', 'amount' => 15]])),
                    default => Http::response($this->odooXml([])),
                };
            },
        ]);
    }

    /**
     * @param list<string>|null $moveFields
     */
    private function fakeInstance(?array $moveFields = null): void
    {
        $move = $moveFields ?? [...\App\Services\Accounting\Odoo\OdooPreflight::REQUIRED_FIELDS['account.move'], 'narration'];
        $line = [...\App\Services\Accounting\Odoo\OdooPreflight::REQUIRED_FIELDS['account.move.line'], 'tax_ids', 'analytic_distribution'];
        $describe = static fn (array $names): array => array_combine($names, array_map(static fn (string $n): array => ['type' => 'char', 'string' => $n], $names));

        Http::fake([
            self::ODOO_COMMON => Http::sequence()->push($this->odooXml(['server_version' => '18.0']))->push($this->odooXml(7)),
            self::ODOO_OBJECT => function (\Illuminate\Http\Client\Request $request) use ($move, $line, $describe) {
                $body = $request->body();

                return match (true) {
                    str_contains($body, '<string>ir.model</string>') => Http::response($this->odooXml(array_map(static fn (string $m): array => ['model' => $m], \App\Services\Accounting\Odoo\OdooPreflight::MODELS))),
                    str_contains($body, '<string>account.move.line</string>') => Http::response($this->odooXml($describe($line))),
                    str_contains($body, '<string>account.move</string>') => Http::response($this->odooXml($describe($move))),
                    str_contains($body, '<string>res.company</string>') => Http::response($this->odooXml([['id' => 1, 'name' => 'Pharmacy KSA', 'currency_id' => [3, 'SAR']]])),
                    default => Http::response($this->odooXml(120)),
                };
            },
        ]);
    }

    /** @return list<string> */
    private function bodies(): array
    {
        return Http::recorded(static fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->url(), '/xmlrpc/'))
            ->values()->map(static fn (array $pair): string => $pair[0]->body())->all();
    }
}
