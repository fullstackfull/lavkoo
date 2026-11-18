<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontLegalReview;
use App\Services\Storefront\StorefrontLifecycle;
use App\Services\Storefront\StorefrontSellingGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * I2-11 to I2-17: a storefront is a draft until a person says it is ready, ready until a person approves it, and only an active storefront sells. A person can pause an active
 * storefront in one of three modes and resume it. Ready needs what a storefront needs to sell, active needs a person and a reference, every change is written with its audit line,
 * and the default storefront keeps selling without a record and cannot be stopped in a way that strands its own visitors. The names in the tests are examples, not people.
 */
class StorefrontLifecycleTest extends TestCase
{
    use ApprovesStorefrontTax;
    use BuildsStorefrontLegalDocuments;

    private const PERSON = 'The head of the Kuwait market';

    private const REASON = 'Decision of the launch meeting';

    private const REFERENCE = 'Minutes of the launch meeting, 2026-11-17';

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

    private function lifecycle(): StorefrontLifecycle
    {
        return app(StorefrontLifecycle::class);
    }

    private function stateOf(string $country = 'KW'): string
    {
        return $this->lifecycle()->statusOf($country)['state'];
    }

    /** What a storefront needs before it can sell: its texts, their independent review and a tax decision, without the lifecycle that is under test. */
    private function meetThePrerequisitesOf(string $country = 'KW'): void
    {
        $this->publishRequiredLegalDocuments(country: $country, activated: false);
        $this->approveNoTaxFor($country);
    }

    private function markReady(string $country = 'KW'): array
    {
        return $this->lifecycle()->markReady(country: $country, by: self::PERSON, reason: self::REASON);
    }

    private function activate(string $country = 'KW'): array
    {
        return $this->lifecycle()->activate(country: $country, by: self::PERSON, reference: self::REFERENCE, reason: self::REASON);
    }

    private function pause(string $mode = StorefrontLifecycle::BROWSE_ONLY, string $country = 'KW', ?string $message = null, ?string $until = null): array
    {
        return $this->lifecycle()->pause(country: $country, mode: $mode, by: self::PERSON, reason: self::REASON, message: $message, until: $until);
    }

    private function anActiveStorefront(string $country = 'KW'): void
    {
        $this->meetThePrerequisitesOf($country);
        $this->markReady($country);
        $this->activate($country);
    }

    private function refusalOf(callable $change): string
    {
        try {
            $change();
        } catch (InvalidArgumentException $refusal) {
            return $refusal->getMessage();
        }

        $this->fail('The change was not refused.');
    }

    private function auditLines(string $action): array
    {
        return DB::table('audit_logs')->where('action', $action)->orderBy('id')->get()->all();
    }

    private function mayStillSell(string $country = 'KW', string $currency = 'KWD'): bool
    {
        return app(StorefrontSellingGate::class)->maySell(country: $country, currencyCode: $currency);
    }

    // ---- where a storefront starts ----------------------------------------------------------------------------------------------------------

    public function test_the_default_storefront_is_active_without_a_record(): void
    {
        $status = $this->lifecycle()->statusOf('SA');

        $this->assertSame(StorefrontLifecycle::ACTIVE, $status['state']);
        $this->assertTrue($status['implicit']);
        $this->assertTrue($this->lifecycle()->isOpenForSale('SA'));
        $this->assertSame(0, DB::table('business_settings')->where('type', 'like', 'storefront_lifecycle_%')->count());
    }

    public function test_a_storefront_nobody_has_prepared_is_a_draft_and_cannot_sell(): void
    {
        $status = $this->lifecycle()->statusOf('KW');

        $this->assertSame(StorefrontLifecycle::DRAFT, $status['state']);
        $this->assertTrue($status['implicit']);
        $this->assertFalse($this->lifecycle()->isOpenForSale('KW'));
        $this->assertNull($this->lifecycle()->stopModeOf('KW'));
    }

    public function test_a_country_is_read_whatever_its_case(): void
    {
        $this->anActiveStorefront();

        $this->assertSame(StorefrontLifecycle::ACTIVE, $this->lifecycle()->statusOf(' kw ')['state']);
        $this->assertSame('storefront_lifecycle_kw', StorefrontLifecycle::settingName(' KW '));
    }

    // ---- draft to ready ---------------------------------------------------------------------------------------------------------------------

    public function test_a_storefront_that_does_not_meet_what_it_needs_cannot_be_marked_ready_and_nothing_is_written(): void
    {
        $refusal = $this->refusalOf(fn () => $this->markReady());

        $this->assertStringContainsString('legal_texts', $refusal);
        $this->assertStringContainsString('legal_review', $refusal);
        $this->assertStringContainsString('tax', $refusal);
        $this->assertStringNotContainsString('currency', $refusal);
        $this->assertSame(StorefrontLifecycle::DRAFT, $this->stateOf());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_each_unmet_prerequisite_is_named_and_only_the_unmet_ones(): void
    {
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false, activated: false);

        $refusal = $this->refusalOf(fn () => $this->markReady());

        $this->assertStringContainsString('legal_review', $refusal);
        $this->assertStringContainsString('tax', $refusal);
        $this->assertStringNotContainsString('legal_texts', $refusal);
    }

    public function test_a_storefront_that_meets_what_it_needs_is_marked_ready_by_a_person_with_a_trace(): void
    {
        $this->meetThePrerequisitesOf();

        $record = $this->markReady();

        $this->assertSame(StorefrontLifecycle::READY, $record['state']);
        $this->assertSame(StorefrontLifecycle::READY, $this->stateOf());
        $this->assertFalse($this->lifecycle()->isOpenForSale('KW'));

        $line = $this->auditLines('storefront.lifecycle.ready')[0];
        $this->assertSame('KW', $line->subject_id);
        $this->assertEquals(
            ['from' => 'draft', 'to' => 'ready', 'mode' => null, 'reason' => self::REASON, 'by' => self::PERSON, 'reference' => null],
            json_decode((string) $line->context, true),
        );
    }

    public function test_the_default_storefront_has_nothing_to_prepare(): void
    {
        $this->assertStringContainsString('default storefront', $this->refusalOf(fn () => $this->markReady('SA')));
    }

    public function test_only_a_draft_can_be_marked_ready(): void
    {
        $this->meetThePrerequisitesOf();
        $this->markReady();

        $this->assertStringContainsString('KW is ready: only a draft storefront can be marked ready', $this->refusalOf(fn () => $this->markReady()));
    }

    // ---- ready to active --------------------------------------------------------------------------------------------------------------------

    public function test_a_draft_cannot_be_activated_and_is_told_to_be_marked_ready_first(): void
    {
        $this->meetThePrerequisitesOf();

        $this->assertStringContainsString('mark it ready first', $this->refusalOf(fn () => $this->activate()));
        $this->assertSame(StorefrontLifecycle::DRAFT, $this->stateOf());
    }

    public function test_a_ready_storefront_is_activated_by_a_person_who_says_where_the_approval_is_written(): void
    {
        $this->meetThePrerequisitesOf();
        $this->markReady();

        $record = $this->activate();

        $this->assertSame(StorefrontLifecycle::ACTIVE, $record['state']);
        $this->assertSame(self::REFERENCE, $record['reference']);
        $this->assertTrue($this->lifecycle()->isOpenForSale('KW'));

        $line = $this->auditLines('storefront.lifecycle.activated')[0];
        $this->assertEquals(['from' => 'ready', 'to' => 'active', 'reference' => self::REFERENCE], array_intersect_key(json_decode((string) $line->context, true), ['from' => 1, 'to' => 1, 'reference' => 1]));
    }

    public function test_an_activation_without_a_reference_is_refused(): void
    {
        $this->meetThePrerequisitesOf();
        $this->markReady();

        $refusal = $this->refusalOf(fn () => $this->lifecycle()->activate(country: 'KW', by: self::PERSON, reference: '  ', reason: self::REASON));

        $this->assertStringContainsString('Say where the approval is written down', $refusal);
        $this->assertSame(StorefrontLifecycle::READY, $this->stateOf());
    }

    public function test_a_prerequisite_withdrawn_after_ready_stops_the_activation(): void
    {
        $this->meetThePrerequisitesOf();
        $this->markReady();
        app(StorefrontLegalReview::class)->revoke(country: 'KW');

        $refusal = $this->refusalOf(fn () => $this->activate());

        $this->assertStringContainsString('legal_review', $refusal);
        $this->assertSame(StorefrontLifecycle::READY, $this->stateOf());
    }

    // ---- who may decide ---------------------------------------------------------------------------------------------------------------------

    #[DataProvider('toolsAndEmptyNames')]
    public function test_a_tool_or_nobody_is_refused_as_the_person_of_every_change(string $by): void
    {
        $this->meetThePrerequisitesOf();

        $refuse = function (callable $change, string $stateAfter) {
            $this->assertMatchesRegularExpression('/not by a tool or an assistant|Say who makes the change/', $this->refusalOf($change));
            $this->assertSame($stateAfter, $this->stateOf());
        };

        $refuse(fn () => $this->lifecycle()->markReady(country: 'KW', by: $by, reason: self::REASON), StorefrontLifecycle::DRAFT);
        $this->markReady();

        $refuse(fn () => $this->lifecycle()->activate(country: 'KW', by: $by, reference: self::REFERENCE, reason: self::REASON), StorefrontLifecycle::READY);
        $this->activate();

        $refuse(fn () => $this->lifecycle()->pause(country: 'KW', mode: StorefrontLifecycle::BROWSE_ONLY, by: $by, reason: self::REASON), StorefrontLifecycle::ACTIVE);
        $this->pause();

        $refuse(fn () => $this->lifecycle()->resume(country: 'KW', by: $by, reason: self::REASON), StorefrontLifecycle::PAUSED);
    }

    public static function toolsAndEmptyNames(): array
    {
        return [
            'an assistant' => ['Claude'],
            'a pipeline' => ['the CI pipeline'],
            'a bot' => ['deploy bot'],
            'nobody' => ['   '],
        ];
    }

    public function test_a_change_without_a_reason_or_with_too_long_a_text_is_refused(): void
    {
        $this->meetThePrerequisitesOf();

        $this->assertStringContainsString('Say why', $this->refusalOf(fn () => $this->lifecycle()->markReady(country: 'KW', by: self::PERSON, reason: ' ')));
        $this->assertStringContainsString('Say who', $this->refusalOf(fn () => $this->lifecycle()->markReady(country: 'KW', by: str_repeat('a', 192), reason: self::REASON)));
        $this->assertSame(StorefrontLifecycle::DRAFT, $this->stateOf());
    }

    public function test_a_storefront_that_is_not_configured_is_refused_by_every_change(): void
    {
        foreach ([
            fn () => $this->lifecycle()->markReady(country: 'ZZ', by: self::PERSON, reason: self::REASON),
            fn () => $this->lifecycle()->activate(country: 'ZZ', by: self::PERSON, reference: self::REFERENCE, reason: self::REASON),
            fn () => $this->lifecycle()->pause(country: 'ZZ', mode: StorefrontLifecycle::BROWSE_ONLY, by: self::PERSON, reason: self::REASON),
            fn () => $this->lifecycle()->resume(country: 'ZZ', by: self::PERSON, reason: self::REASON),
        ] as $change) {
            $this->assertSame('ZZ is not a configured storefront.', $this->refusalOf($change));
        }
    }

    // ---- active to paused -------------------------------------------------------------------------------------------------------------------

    public function test_only_an_active_storefront_can_be_paused(): void
    {
        $this->assertStringContainsString('KW is draft: only an active storefront can be paused', $this->refusalOf(fn () => $this->pause()));

        $this->meetThePrerequisitesOf();
        $this->markReady();

        $this->assertStringContainsString('KW is ready: only an active storefront can be paused', $this->refusalOf(fn () => $this->pause()));
    }

    #[DataProvider('everyMode')]
    public function test_an_active_storefront_is_paused_in_a_mode_with_a_reason_a_message_and_an_end(string $mode): void
    {
        $this->anActiveStorefront();
        $until = now()->utc()->addDays(2)->format('Y-m-d H:i');

        $record = $this->pause(mode: $mode, message: 'Back on Sunday, with our thanks.', until: $until);

        $this->assertSame(StorefrontLifecycle::PAUSED, $record['state']);
        $status = $this->lifecycle()->statusOf('KW');
        $this->assertSame([StorefrontLifecycle::PAUSED, $mode, 'Back on Sunday, with our thanks.', $until, self::REASON, self::PERSON], [$status['state'], $status['mode'], $status['message'], $status['until'], $status['reason'], $status['changed_by']]);
        $this->assertSame($mode, $this->lifecycle()->stopModeOf('KW'));
        $this->assertSame('Back on Sunday, with our thanks.', $this->lifecycle()->customerMessageOf('KW'));
        $this->assertFalse($this->lifecycle()->isOpenForSale('KW'));

        $line = $this->auditLines('storefront.lifecycle.paused')[0];
        $this->assertSame(['active', 'paused', $mode], [json_decode((string) $line->context, true)['from'], json_decode((string) $line->context, true)['to'], json_decode((string) $line->context, true)['mode']]);
    }

    public static function everyMode(): array
    {
        return [
            'browse only' => [StorefrontLifecycle::BROWSE_ONLY],
            'maintenance' => [StorefrontLifecycle::MAINTENANCE],
            'redirect' => [StorefrontLifecycle::REDIRECT],
        ];
    }

    public function test_a_pause_needs_a_known_mode_a_message_of_a_decent_length_and_an_end_in_the_future(): void
    {
        $this->anActiveStorefront();

        $this->assertStringContainsString('Choose a stop mode', $this->refusalOf(fn () => $this->pause(mode: 'closed')));
        $this->assertStringContainsString('at most 500 characters', $this->refusalOf(fn () => $this->pause(message: str_repeat('a', 501))));
        $this->assertStringContainsString('YYYY-MM-DD HH:MM', $this->refusalOf(fn () => $this->pause(until: now()->utc()->subHour()->format('Y-m-d H:i'))));
        $this->assertStringContainsString('YYYY-MM-DD HH:MM', $this->refusalOf(fn () => $this->pause(until: 'next week')));
        $this->assertStringContainsString('YYYY-MM-DD HH:MM', $this->refusalOf(fn () => $this->pause(until: '2099-02-30 10:00')));
        $this->assertSame(StorefrontLifecycle::ACTIVE, $this->stateOf());
    }

    public function test_a_paused_storefront_can_be_paused_again_in_another_mode(): void
    {
        $this->anActiveStorefront();
        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY, message: 'First message');

        $this->pause(mode: StorefrontLifecycle::MAINTENANCE);

        $status = $this->lifecycle()->statusOf('KW');
        $this->assertSame([StorefrontLifecycle::MAINTENANCE, null], [$status['mode'], $status['message']]);
        $line = $this->auditLines('storefront.lifecycle.paused')[1];
        $this->assertSame(['paused', 'paused'], [json_decode((string) $line->context, true)['from'], json_decode((string) $line->context, true)['to']]);
    }

    // ---- the default storefront -------------------------------------------------------------------------------------------------------------

    public function test_the_default_storefront_can_be_paused_browse_only_and_resumed_without_preparing_anything(): void
    {
        $record = $this->pause(country: 'SA');

        $this->assertSame(StorefrontLifecycle::PAUSED, $record['state']);
        $this->assertFalse($this->lifecycle()->isOpenForSale('SA'));
        $this->assertSame('active', json_decode((string) $this->auditLines('storefront.lifecycle.paused')[0]->context, true)['from']);

        $this->lifecycle()->resume(country: 'SA', by: self::PERSON, reason: self::REASON);

        $this->assertTrue($this->lifecycle()->isOpenForSale('SA'));
    }

    #[DataProvider('modesThatStrandTheDefaultStorefront')]
    public function test_the_default_storefront_cannot_be_stopped_in_a_way_that_strands_its_own_visitors(string $mode): void
    {
        $refusal = $this->refusalOf(fn () => $this->pause(mode: $mode, country: 'SA'));

        $this->assertStringContainsString('would strand its own visitors', $refusal);
        $this->assertTrue($this->lifecycle()->isOpenForSale('SA'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public static function modesThatStrandTheDefaultStorefront(): array
    {
        return [
            'maintenance' => [StorefrontLifecycle::MAINTENANCE],
            'redirect' => [StorefrontLifecycle::REDIRECT],
        ];
    }

    // ---- paused to active -------------------------------------------------------------------------------------------------------------------

    public function test_a_paused_storefront_is_resumed_and_keeps_the_reference_of_its_activation(): void
    {
        $this->anActiveStorefront();
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE, message: 'Maintenance', until: now()->utc()->addDay()->format('Y-m-d H:i'));

        $record = $this->lifecycle()->resume(country: 'KW', by: self::PERSON, reason: 'The work is done');

        $this->assertSame(StorefrontLifecycle::ACTIVE, $record['state']);
        $status = $this->lifecycle()->statusOf('KW');
        $this->assertSame([null, null, null, self::REFERENCE], [$status['mode'], $status['message'], $status['until'], $status['reference']]);
        $this->assertTrue($this->lifecycle()->isOpenForSale('KW'));
        $this->assertSame(['paused', 'active'], [json_decode((string) $this->auditLines('storefront.lifecycle.resumed')[0]->context, true)['from'], json_decode((string) $this->auditLines('storefront.lifecycle.resumed')[0]->context, true)['to']]);
    }

    public function test_only_a_paused_storefront_can_be_resumed(): void
    {
        $this->anActiveStorefront();

        $this->assertStringContainsString('KW is active: only a paused storefront can be resumed', $this->refusalOf(fn () => $this->lifecycle()->resume(country: 'KW', by: self::PERSON, reason: self::REASON)));
    }

    public function test_a_decision_withdrawn_while_the_storefront_was_paused_keeps_it_closed(): void
    {
        $this->anActiveStorefront();
        $this->pause();
        app(StorefrontLegalReview::class)->revoke(country: 'KW');

        $refusal = $this->refusalOf(fn () => $this->lifecycle()->resume(country: 'KW', by: self::PERSON, reason: self::REASON));

        $this->assertStringContainsString('legal_review', $refusal);
        $this->assertSame(StorefrontLifecycle::PAUSED, $this->stateOf());
    }

    // ---- what is kept -----------------------------------------------------------------------------------------------------------------------

    public function test_the_state_is_in_the_database_and_survives_a_cache_flush(): void
    {
        $this->anActiveStorefront();
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE, message: 'Maintenance');

        Cache::flush();

        $status = app()->make(StorefrontLifecycle::class)->statusOf('KW');
        $this->assertSame([StorefrontLifecycle::PAUSED, StorefrontLifecycle::MAINTENANCE], [$status['state'], $status['mode']]);
        $this->assertSame(1, DB::table('business_settings')->where('type', StorefrontLifecycle::settingName('KW'))->count());
    }

    public function test_one_storefront_in_maintenance_leaves_another_selling(): void
    {
        config(['gcc.storefronts.countries.AE.active' => true]);
        $this->anActiveStorefront('KW');
        $this->anActiveStorefront('AE');

        $this->pause(mode: StorefrontLifecycle::MAINTENANCE, country: 'KW');

        $this->assertSame([false, true, true], [$this->lifecycle()->isOpenForSale('KW'), $this->lifecycle()->isOpenForSale('AE'), $this->lifecycle()->isOpenForSale('SA')]);
    }

    public function test_a_change_whose_trace_cannot_be_written_is_not_made(): void
    {
        $this->meetThePrerequisitesOf();
        DB::statement('DROP TABLE audit_logs');
        $this->beforeApplicationDestroyed(fn () => $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_lifecycle_'));

        try {
            $this->markReady();
            $this->fail('The change was made without its trace.');
        } catch (\Throwable $failure) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $failure);
        }

        $this->assertSame(0, DB::table('business_settings')->where('type', StorefrontLifecycle::settingName('KW'))->count());
    }

    public function test_a_record_that_makes_no_sense_is_a_draft_and_the_default_storefront_stays_active(): void
    {
        DB::table('business_settings')->insert([
            ['type' => StorefrontLifecycle::settingName('KW'), 'value' => json_encode(['state' => 'open']), 'created_at' => now(), 'updated_at' => now()],
            ['type' => StorefrontLifecycle::settingName('SA'), 'value' => '"nonsense"', 'created_at' => now(), 'updated_at' => now()],
        ]);
        clearWebConfigCacheKeys();

        $this->assertSame(StorefrontLifecycle::DRAFT, $this->stateOf('KW'));
        $this->assertSame(StorefrontLifecycle::ACTIVE, $this->stateOf('SA'));
    }

    public function test_a_paused_record_with_no_known_mode_is_stopped_browse_only(): void
    {
        DB::table('business_settings')->insert(['type' => StorefrontLifecycle::settingName('KW'), 'value' => json_encode(['state' => 'paused', 'mode' => 'closed']), 'created_at' => now(), 'updated_at' => now()]);
        clearWebConfigCacheKeys();

        $this->assertSame(StorefrontLifecycle::BROWSE_ONLY, $this->lifecycle()->stopModeOf('KW'));
        $this->assertFalse($this->lifecycle()->isOpenForSale('KW'));
    }

    // ---- the gate ---------------------------------------------------------------------------------------------------------------------------

    public function test_a_storefront_that_meets_what_it_needs_still_does_not_sell_until_it_is_active(): void
    {
        $this->meetThePrerequisitesOf();
        $this->assertFalse($this->mayStillSell());

        $this->markReady();
        $this->assertFalse($this->mayStillSell());

        $this->activate();
        $this->assertTrue($this->mayStillSell());
    }

    public function test_an_active_storefront_stops_selling_while_it_is_paused_and_sells_again_when_resumed(): void
    {
        $this->anActiveStorefront();

        foreach (StorefrontLifecycle::MODES as $mode) {
            $this->pause(mode: $mode);
            $this->assertFalse($this->mayStillSell(), $mode);
        }

        $this->lifecycle()->resume(country: 'KW', by: self::PERSON, reason: self::REASON);
        $this->assertTrue($this->mayStillSell());
    }

    public function test_an_active_storefront_whose_prerequisite_is_withdrawn_stops_selling_and_stays_active_on_record(): void
    {
        $this->anActiveStorefront();
        app(StorefrontLegalReview::class)->revoke(country: 'KW');

        $this->assertFalse($this->mayStillSell());
        $this->assertSame(StorefrontLifecycle::ACTIVE, $this->stateOf());
    }

    public function test_the_default_storefront_is_refused_only_while_a_person_has_paused_it(): void
    {
        $this->assertTrue($this->mayStillSell('SA', 'SAR'));

        $this->pause(country: 'SA');
        $this->assertFalse($this->mayStillSell('SA', 'SAR'));

        $this->lifecycle()->resume(country: 'SA', by: self::PERSON, reason: self::REASON);
        $this->assertTrue($this->mayStillSell('SA', 'SAR'));
    }

    public function test_nothing_is_read_and_nothing_is_refused_while_storefronts_are_off(): void
    {
        $this->anActiveStorefront();
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE);
        config(['gcc.storefronts.enabled' => false]);

        $this->assertTrue($this->mayStillSell());
        $this->assertTrue($this->mayStillSell('SA', 'SAR'));
    }
}
