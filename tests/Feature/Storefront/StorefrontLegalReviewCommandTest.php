<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontLegalReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * gcc:storefront:legal-review records what the owner tells it: who reviewed the legal texts of a storefront, where the opinion is written and the day. It reviews nothing
 * itself, refuses what the service refuses with the reason in words, can say what is on record without changing it, and can withdraw the review so that the storefront stops
 * selling. It works while the switches are off.
 */
class StorefrontLegalReviewCommandTest extends TestCase
{
    use BuildsStorefrontLegalDocuments;

    private const REVIEWER = 'Al Sabah and Partners, Kuwait';

    private const OPINION = 'Opinion KW-2026-11';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_legal_review_');
        DB::table('audit_logs')->delete();
        DB::table('business_settings')->where('type', 'like', 'storefront_legal_review_%')->delete();
        clearWebConfigCacheKeys();
    }

    private function record(string $country = 'KW'): PendingCommand
    {
        return $this->artisan('gcc:storefront:legal-review', ['country' => $country, '--by' => self::REVIEWER, '--reference' => self::OPINION, '--on' => '2026-11-10']);
    }

    public function test_it_records_the_review_and_says_what_it_did_and_what_it_did_not(): void
    {
        $this->record()
            ->expectsOutputToContain('KW: the legal review is recorded: Al Sabah and Partners, Kuwait (Opinion KW-2026-11), on 2026-11-10.')
            ->expectsOutputToContain('A review does not make the storefront sellable by itself')
            ->expectsOutputToContain('the owner decides whether it needs another one')
            ->assertExitCode(0);

        $this->assertTrue(app(StorefrontLegalReview::class)->isRecorded('KW'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'storefront.legal_review.recorded')->count());
    }

    public function test_it_reads_the_country_without_regard_to_case(): void
    {
        $this->record('kw')->assertExitCode(0);

        $this->assertTrue(app(StorefrontLegalReview::class)->isRecorded('KW'));
    }

    public function test_it_records_while_every_switch_is_off(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.currency.enabled' => false]);

        $this->record()->assertExitCode(0);

        $this->assertTrue(app(StorefrontLegalReview::class)->isRecorded('KW'));
    }

    public function test_it_refuses_what_the_service_refuses_and_says_why(): void
    {
        $this->artisan('gcc:storefront:legal-review', ['country' => 'KW', '--by' => 'Claude', '--reference' => self::OPINION, '--on' => '2026-11-10'])
            ->expectsOutputToContain('not a tool or an assistant')
            ->assertExitCode(1);

        $this->artisan('gcc:storefront:legal-review', ['country' => 'KW', '--by' => self::REVIEWER, '--reference' => self::OPINION])
            ->expectsOutputToContain('Give the day the review was done as YYYY-MM-DD.')
            ->assertExitCode(1);

        $this->artisan('gcc:storefront:legal-review', ['country' => 'KW'])
            ->expectsOutputToContain('Say who reviewed the texts')
            ->assertExitCode(1);

        $this->record('XX')->expectsOutputToContain('XX is not a configured storefront.')->assertExitCode(1);
        $this->record('SA')->expectsOutputToContain('SA keeps the documents it has always had')->assertExitCode(1);

        $this->assertSame(0, DB::table('business_settings')->where('type', 'like', 'storefront_legal_review_%')->count());
    }

    public function test_status_says_what_is_on_record_and_changes_nothing(): void
    {
        $this->artisan('gcc:storefront:legal-review', ['country' => 'KW', '--status' => true])
            ->expectsOutputToContain('KW: missing')
            ->assertExitCode(1);

        $this->record()->assertExitCode(0);

        $this->artisan('gcc:storefront:legal-review', ['country' => 'KW', '--status' => true])
            ->expectsOutputToContain('KW: recorded (Al Sabah and Partners, Kuwait, Opinion KW-2026-11, on 2026-11-10, recorded ')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'like', 'storefront.legal_review.%')->count());
    }

    public function test_status_of_a_storefront_that_keeps_its_old_documents_needs_no_review(): void
    {
        $this->artisan('gcc:storefront:legal-review', ['country' => 'SA', '--status' => true])
            ->expectsOutputToContain('SA: not_required')
            ->expectsOutputToContain('Legal texts: it keeps the documents it has always had.')
            ->assertExitCode(0);
    }

    public function test_status_says_whether_the_texts_are_ready_next_to_the_review(): void
    {
        $this->record()->assertExitCode(0);

        $this->artisan('gcc:storefront:legal-review', ['country' => 'KW', '--status' => true])
            ->expectsOutputToContain('Legal texts: not ready')
            ->assertExitCode(0);

        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        $this->artisan('gcc:storefront:legal-review', ['country' => 'KW', '--status' => true])
            ->expectsOutputToContain('Legal texts: every required text is published and approved.')
            ->assertExitCode(0);
    }

    public function test_it_withdraws_the_review_and_says_so(): void
    {
        $this->record()->assertExitCode(0);

        $this->artisan('gcc:storefront:legal-review', ['country' => 'KW', '--revoke' => true, '--by' => 'The owner'])
            ->expectsOutputToContain('KW: the legal review is withdrawn: the storefront cannot sell until a review is recorded again.')
            ->assertExitCode(0);

        $this->assertFalse(app(StorefrontLegalReview::class)->isRecorded('KW'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'storefront.legal_review.revoked')->count());
    }

    public function test_withdrawing_what_is_not_there_is_not_a_failure(): void
    {
        $this->artisan('gcc:storefront:legal-review', ['country' => 'KW', '--revoke' => true])
            ->expectsOutputToContain('KW has no legal review on record: nothing to withdraw.')
            ->assertExitCode(0);
    }

    public function test_it_says_when_there_is_no_settings_table(): void
    {
        Schema::drop('business_settings');

        $this->record()->expectsOutputToContain('There is no business_settings table.')->assertExitCode(1);
    }
}
