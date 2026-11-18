<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * gcc:storefront:state: the read-only report of what each storefront still needs before it can sell (it exits 1 while one is blocked), and the commands that record the decision of the
 * person who makes it. The names in the tests are examples, not people.
 */
class StorefrontLifecycleCommandTest extends TestCase
{
    use ApprovesStorefrontTax;
    use BuildsStorefrontLegalDocuments;

    private const PERSON = 'The head of the Kuwait market';

    private const REASON = 'Decision of the launch meeting';

    private const REFERENCE = 'Minutes of the launch meeting';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_lifecycle_');
        DB::table('audit_logs')->delete();
        DB::table('business_settings')->where('type', 'like', 'storefront_lifecycle_%')->delete();
        clearWebConfigCacheKeys();

        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
    }

    private function meetThePrerequisitesOfKuwait(): void
    {
        $this->publishRequiredLegalDocuments(country: 'KW', activated: false);
        $this->approveNoTaxFor('KW');
    }

    private function row(string $prerequisite, string $verdict): string
    {
        return sprintf('%-13s %s', $prerequisite, $verdict);
    }

    private function stateOf(string $country = 'KW'): string
    {
        return app(StorefrontLifecycle::class)->statusOf($country)['state'];
    }

    private function recordedRows(): int
    {
        return DB::table('business_settings')->where('type', 'like', 'storefront_lifecycle_%')->count() + DB::table('audit_logs')->count();
    }

    // ---- status -----------------------------------------------------------------------------------------------------------------------------

    public function test_status_reports_every_configured_storefront_and_exits_1_while_one_is_blocked(): void
    {
        $this->artisan('gcc:storefront:state')
            ->expectsOutputToContain('SA: active (the default storefront: active without a record)')
            ->expectsOutputToContain('KW: draft')
            ->expectsOutputToContain('QA: draft')
            ->expectsOutputToContain('record the independent review: php artisan gcc:storefront:legal-review')
            ->expectsOutputToContain('approve its tax setup, or record that it charges none: php artisan gcc:storefront:tax-approve')
            ->assertExitCode(1);
    }

    public function test_status_of_a_storefront_that_meets_what_it_needs_says_so_and_exits_0(): void
    {
        $this->meetThePrerequisitesOfKuwait();

        $this->artisan('gcc:storefront:state', ['country' => 'kw'])
            ->expectsOutputToContain('KW: draft')
            ->expectsOutputToContain($this->row('legal_texts', 'ok'))
            ->expectsOutputToContain($this->row('legal_review', 'ok'))
            ->expectsOutputToContain($this->row('currency', 'ok'))
            ->expectsOutputToContain($this->row('tax', 'ok'))
            ->assertExitCode(0);
    }

    public function test_status_names_only_the_prerequisite_that_is_not_met(): void
    {
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false, activated: false);
        $this->approveNoTaxFor('KW');

        $this->artisan('gcc:storefront:state', ['country' => 'KW'])
            ->expectsOutputToContain($this->row('legal_texts', 'ok'))
            ->expectsOutputToContain($this->row('tax', 'ok'))
            ->expectsOutputToContain('record the independent review')
            ->assertExitCode(1);
    }

    public function test_status_says_who_changed_a_storefront_last_and_why_and_what_its_customers_are_told(): void
    {
        $this->meetThePrerequisitesOfKuwait();
        $lifecycle = app(StorefrontLifecycle::class);
        $lifecycle->markReady(country: 'KW', by: self::PERSON, reason: self::REASON);
        $lifecycle->activate(country: 'KW', by: self::PERSON, reference: self::REFERENCE, reason: self::REASON);
        $lifecycle->pause(country: 'KW', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: 'The catalogue import', message: 'Back on Sunday.');

        $this->artisan('gcc:storefront:state', ['country' => 'KW'])
            ->expectsOutputToContain('KW: paused (maintenance)')
            ->expectsOutputToContain('because The catalogue import (approval: ' . self::REFERENCE . ')')
            ->expectsOutputToContain('message for its customers: Back on Sunday.')
            ->assertExitCode(0);
    }

    public function test_status_changes_nothing(): void
    {
        $this->meetThePrerequisitesOfKuwait();
        $before = $this->recordedRows();

        $this->artisan('gcc:storefront:state')->assertExitCode(1);

        $this->assertSame($before, $this->recordedRows());
    }

    public function test_status_of_a_storefront_that_is_not_configured_is_an_error(): void
    {
        $this->artisan('gcc:storefront:state', ['country' => 'ZZ'])
            ->expectsOutputToContain('ZZ is not a configured storefront.')
            ->assertExitCode(1);
    }

    // ---- the decisions ----------------------------------------------------------------------------------------------------------------------

    public function test_a_storefront_goes_from_draft_to_ready_to_active_to_paused_and_back_by_the_commands(): void
    {
        $this->meetThePrerequisitesOfKuwait();

        $this->artisan('gcc:storefront:state', ['country' => 'KW', 'action' => 'ready', '--by' => self::PERSON, '--reason' => self::REASON])
            ->expectsOutputToContain('KW is now ready.')
            ->assertExitCode(0);
        $this->assertSame(StorefrontLifecycle::READY, $this->stateOf());

        $this->artisan('gcc:storefront:state', ['country' => 'KW', 'action' => 'activate', '--by' => self::PERSON, '--reason' => self::REASON, '--reference' => self::REFERENCE])
            ->expectsOutputToContain('KW is now active.')
            ->assertExitCode(0);
        $this->assertSame(StorefrontLifecycle::ACTIVE, $this->stateOf());

        $until = now()->utc()->addDays(2)->format('Y-m-d H:i');
        $this->artisan('gcc:storefront:state', ['country' => 'KW', 'action' => 'pause', '--by' => self::PERSON, '--reason' => 'Stock count', '--mode' => 'browse_only', '--message' => 'Closed for a stock count.', '--until' => $until])
            ->expectsOutputToContain('KW is now paused (browse_only).')
            ->assertExitCode(0);
        $status = app(StorefrontLifecycle::class)->statusOf('KW');
        $this->assertSame([StorefrontLifecycle::PAUSED, StorefrontLifecycle::BROWSE_ONLY, 'Closed for a stock count.', $until], [$status['state'], $status['mode'], $status['message'], $status['until']]);

        $this->artisan('gcc:storefront:state', ['country' => 'KW', 'action' => 'resume', '--by' => self::PERSON, '--reason' => 'Stock count done'])
            ->expectsOutputToContain('KW is now active.')
            ->assertExitCode(0);
        $this->assertSame(StorefrontLifecycle::ACTIVE, $this->stateOf());

        $this->assertSame(['storefront.lifecycle.ready', 'storefront.lifecycle.activated', 'storefront.lifecycle.paused', 'storefront.lifecycle.resumed'], DB::table('audit_logs')->where('action', 'like', 'storefront.lifecycle.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_a_refusal_is_printed_and_exits_1_and_nothing_is_written(): void
    {
        $this->artisan('gcc:storefront:state', ['country' => 'KW', 'action' => 'ready', '--by' => self::PERSON, '--reason' => self::REASON])
            ->expectsOutputToContain('KW does not meet what it needs to sell: legal_texts, legal_review, tax.')
            ->assertExitCode(1);

        $this->assertSame(StorefrontLifecycle::DRAFT, $this->stateOf());
        $this->assertSame(0, $this->recordedRows());
    }

    public function test_an_activation_without_a_reference_is_refused(): void
    {
        $this->meetThePrerequisitesOfKuwait();
        $this->artisan('gcc:storefront:state', ['country' => 'KW', 'action' => 'ready', '--by' => self::PERSON, '--reason' => self::REASON])->assertExitCode(0);

        $this->artisan('gcc:storefront:state', ['country' => 'KW', 'action' => 'activate', '--by' => self::PERSON, '--reason' => self::REASON])
            ->expectsOutputToContain('Say where the approval is written down')
            ->assertExitCode(1);

        $this->assertSame(StorefrontLifecycle::READY, $this->stateOf());
    }

    public function test_a_tool_is_refused_as_the_person_who_decides(): void
    {
        $this->meetThePrerequisitesOfKuwait();

        $this->artisan('gcc:storefront:state', ['country' => 'KW', 'action' => 'ready', '--by' => 'Claude', '--reason' => self::REASON])
            ->expectsOutputToContain('not by a tool or an assistant')
            ->assertExitCode(1);

        $this->assertSame(StorefrontLifecycle::DRAFT, $this->stateOf());
    }

    public function test_a_change_names_a_storefront(): void
    {
        $this->artisan('gcc:storefront:state', ['action' => 'ready', '--by' => self::PERSON, '--reason' => self::REASON])
            ->expectsOutputToContain('Say which storefront: php artisan gcc:storefront:state KW ready')
            ->assertExitCode(1);
    }

    public function test_an_action_that_does_not_exist_is_refused(): void
    {
        $this->artisan('gcc:storefront:state', ['country' => 'KW', 'action' => 'explode'])
            ->expectsOutputToContain('Unknown action [explode]: status, ready, activate, pause or resume.')
            ->assertExitCode(1);
    }

    public function test_a_pause_without_a_mode_is_refused_with_the_choices(): void
    {
        $this->artisan('gcc:storefront:state', ['country' => 'SA', 'action' => 'pause', '--by' => self::PERSON, '--reason' => self::REASON])
            ->expectsOutputToContain('Choose a stop mode: browse_only, maintenance, redirect.')
            ->assertExitCode(1);
    }

    public function test_the_commands_work_while_storefronts_are_off_and_say_that_nothing_reads_them_yet(): void
    {
        $this->meetThePrerequisitesOfKuwait();
        config(['gcc.storefronts.enabled' => false]);

        $this->artisan('gcc:storefront:state', ['country' => 'KW', 'action' => 'ready', '--by' => self::PERSON, '--reason' => self::REASON])
            ->expectsOutputToContain('KW is now ready.')
            ->expectsOutputToContain('Storefronts are off (GCC_STOREFRONTS_ENABLED): nothing reads this yet.')
            ->assertExitCode(0);
        $this->assertSame(StorefrontLifecycle::READY, $this->stateOf());
    }
}
