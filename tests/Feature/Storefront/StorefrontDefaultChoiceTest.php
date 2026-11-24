<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontDefault;
use App\Services\Storefront\StorefrontLifecycle;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\ActivatesStorefront;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * A1-03 and GCC-19: Saudi Arabia is the default storefront to begin with, and a person with the authority can move it without a deploy.
 *
 * The market a visitor who names none is served came from the configuration alone, so moving it took a code change and a release. It is now
 * a recorded decision -- who moved it, when and why, with an audit line -- read ONCE when the application boots and applied to the
 * configuration, so the resolver stays a value object with no database behind it and a request, a command and a worker all agree about the
 * market they are serving.
 *
 * Three refusals are what make it safe to hand to an operator: not while storefronts are off (with them off the default IS the platform),
 * not a market the platform does not serve, and not one that may not sell yet -- a default that cannot take an order is a shop with the
 * lights off. And a visitor who has already chosen a market is not moved by it: a choice outranks the default (A1-06).
 */
class StorefrontDefaultChoiceTest extends TestCase
{
    use ActivatesStorefront;
    use ApprovesStorefrontTax;
    use BuildsStorefrontLegalDocuments;

    /** What config/gcc.php ships, which is what a request starts from. */
    private const CONFIGURED_DEFAULT = 'SA';

    private const PERSON = 'The owner of the platform';

    private const REASON = 'The Emirates market opens first';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: StorefrontDefault::SETTING);
        DB::table('business_settings')->whereIn('type', [StorefrontDefault::SETTING])->delete();
        clearWebConfigCacheKeys();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
        ]);
    }

    private function theDefault(): StorefrontDefault
    {
        return app(StorefrontDefault::class);
    }

    /** A market a person has opened and that meets what it needs, so it may sell and could be the default. */
    private function marketReadyToSell(string $country): void
    {
        $this->publishRequiredLegalDocuments($country);
        $this->approveNoTaxFor($country);
    }

    /**
     * What the next request reads. A request starts from the configuration as the files have it and the boot applies the record to that, so
     * the configured value is put back first: a process that has already applied one record must not be mistaken for a fresh request.
     */
    private function theNextRequest(): string
    {
        config(['gcc.storefronts.default_country' => self::CONFIGURED_DEFAULT]);
        $this->theDefault()->applyToConfig();

        return StorefrontResolver::fromConfig()->defaultCountry();
    }

    public function test_the_platform_starts_with_saudi_arabia(): void
    {
        $this->assertSame('SA', $this->theDefault()->inForce());
        $this->assertNull($this->theDefault()->recorded(), 'nobody has moved it');
        $this->assertSame('SA', $this->theNextRequest());
    }

    public function test_a_person_moves_the_default_and_the_next_request_is_served_that_market(): void
    {
        $this->marketReadyToSell('AE');

        $record = $this->theDefault()->change(country: 'AE', by: self::PERSON, reason: self::REASON);

        $this->assertSame('AE', $record['country']);
        $this->assertSame(self::PERSON, $record['changed_by']);
        $this->assertSame('AE', $this->theDefault()->recorded());
        $this->assertSame('AE', $this->theNextRequest());
    }

    /** Every change of a storefront leaves a trace, and this one is no different. */
    public function test_moving_the_default_is_written_down_with_its_audit_line(): void
    {
        $this->marketReadyToSell('AE');

        $this->theDefault()->change(country: 'AE', by: self::PERSON, reason: self::REASON);

        $audit = DB::table('audit_logs')->where('action', 'storefront.default.changed')->first();

        $this->assertNotNull($audit, 'a decision whose trace cannot be written is not made');
        $this->assertSame('AE', json_decode((string) $audit->after, true)['country']);
        $this->assertSame('SA', json_decode((string) $audit->context, true)['from'], 'the trace says where it came from');
    }

    /** A default that cannot take an order is a shop with the lights off. */
    public function test_a_market_that_may_not_sell_yet_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AE may not sell yet');

        $this->theDefault()->change(country: 'AE', by: self::PERSON, reason: self::REASON);
    }

    public function test_a_market_that_was_opened_and_then_paused_is_refused(): void
    {
        $this->marketReadyToSell('AE');
        DB::table('business_settings')->where('type', StorefrontLifecycle::settingName('AE'))->update([
            'value' => json_encode(['state' => 'paused', 'mode' => 'browse_only', 'changed_by' => self::PERSON, 'changed_at' => now()->toIso8601String()]),
        ]);
        clearWebConfigCacheKeys();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AE may not sell yet');

        $this->theDefault()->change(country: 'AE', by: self::PERSON, reason: self::REASON);
    }

    public function test_a_market_the_platform_does_not_serve_is_refused(): void
    {
        config(['gcc.storefronts.countries.QA.active' => false]);

        foreach (['QA', 'ZZ', 'not-a-country'] as $asked) {
            try {
                $this->theDefault()->change(country: $asked, by: self::PERSON, reason: self::REASON);
                $this->fail("[{$asked}] was not refused.");
            } catch (InvalidArgumentException $refused) {
                $this->assertStringContainsString('is not a storefront this platform serves', $refused->getMessage());
            }
        }
    }

    /** With storefronts off the default market IS the platform, so moving it would move every visitor of a Saudi installation in one step. */
    public function test_the_default_cannot_be_moved_while_storefronts_are_off(): void
    {
        $this->marketReadyToSell('AE');
        config(['gcc.storefronts.enabled' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Storefronts are switched off');

        $this->theDefault()->change(country: 'AE', by: self::PERSON, reason: self::REASON);
    }

    public function test_a_record_made_while_storefronts_were_on_is_not_read_once_they_are_off(): void
    {
        $this->marketReadyToSell('AE');
        $this->theDefault()->change(country: 'AE', by: self::PERSON, reason: self::REASON);

        config(['gcc.storefronts.enabled' => false]);

        $this->assertNull($this->theDefault()->recorded());
        $this->assertSame('SA', $this->theNextRequest(), 'the whole feature is dormant, this included');
    }

    /** A recorded default that has since stopped being able to sell is ignored, because the resolver refuses a default it cannot serve. */
    public function test_a_recorded_default_that_can_no_longer_sell_is_ignored_and_the_site_stays_up(): void
    {
        $this->marketReadyToSell('AE');
        $this->theDefault()->change(country: 'AE', by: self::PERSON, reason: self::REASON);
        $this->assertSame('AE', $this->theNextRequest());

        config(['gcc.storefronts.countries.AE.active' => false]);

        $this->assertNull($this->theDefault()->recorded());
        $this->assertSame('SA', $this->theNextRequest());
    }

    public function test_a_tool_or_a_change_with_no_reason_is_refused(): void
    {
        $this->marketReadyToSell('AE');

        foreach ([['Claude', self::REASON], ['automation', self::REASON], [self::PERSON, ''], ['', self::REASON]] as [$by, $reason]) {
            try {
                $this->theDefault()->change(country: 'AE', by: $by, reason: $reason);
                $this->fail("[{$by}] with reason [{$reason}] was not refused.");
            } catch (InvalidArgumentException $refused) {
                $this->assertMatchesRegularExpression('/(is a tool|is recorded with the person)/', $refused->getMessage());
            }
        }
    }

    public function test_the_recorded_default_can_be_withdrawn_and_the_configuration_decides_again(): void
    {
        $this->marketReadyToSell('AE');
        $this->theDefault()->change(country: 'AE', by: self::PERSON, reason: self::REASON);

        $this->assertTrue($this->theDefault()->withdraw(by: self::PERSON, reason: 'The Saudi market leads again'));

        $this->assertNull($this->theDefault()->recorded());
        $this->assertSame('SA', $this->theNextRequest());
        $this->assertFalse($this->theDefault()->withdraw(by: self::PERSON, reason: 'again'), 'there is nothing left to withdraw');
    }

    /** A visitor who chose a market keeps it: a choice outranks the default, and moving the default does not move them (A1-06). */
    public function test_moving_the_default_does_not_move_a_visitor_who_chose_a_market(): void
    {
        $this->marketReadyToSell('AE');
        $this->theDefault()->change(country: 'AE', by: self::PERSON, reason: self::REASON);
        $this->theDefault()->applyToConfig();

        $this->assertSame('KW', StorefrontResolver::fromConfig()->resolve(explicitChoice: 'KW'));
        $this->assertSame('AE', StorefrontResolver::fromConfig()->resolve(), 'and a visitor who chose nothing is served the new default');
    }

    public function test_the_command_reports_what_is_in_force_and_which_markets_could_be_the_default(): void
    {
        $this->marketReadyToSell('AE');

        $this->assertSame(0, Artisan::call('gcc:storefront:default'));
        $printed = Artisan::output();

        $this->assertStringContainsString('The default storefront is SA, as the configuration says.', $printed);
        $this->assertStringContainsString('AE', $printed);
        $this->assertStringContainsString('could be the default', $printed);
        $this->assertStringContainsString('may not sell yet', $printed, 'and it says why a market could not be');
    }

    public function test_the_command_moves_the_default_and_refuses_a_market_that_may_not_sell(): void
    {
        $this->marketReadyToSell('AE');

        $this->assertSame(0, Artisan::call('gcc:storefront:default', ['country' => 'AE', '--by' => self::PERSON, '--reason' => self::REASON]));
        $this->assertSame('AE', $this->theDefault()->recorded());

        $this->assertSame(1, Artisan::call('gcc:storefront:default', ['country' => 'KW', '--by' => self::PERSON, '--reason' => self::REASON]));
        $this->assertStringContainsString('may not sell yet', Artisan::output());
        $this->assertSame('AE', $this->theDefault()->recorded(), 'the refused market did not replace the recorded one');
    }
}
