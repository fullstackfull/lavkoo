<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontLegalReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\TestCase;

/**
 * I2-02 and I2-07: the readiness command says, for each storefront that is switched on, whether its required legal texts are published and approved and whether an independent
 * review of them is on record. A storefront with either missing cannot sell, so the report marks it UNSAFE and fails; the storefront that keeps its old documents needs
 * neither. What a reviewer must conclude is the owner's. The command reads and writes nothing.
 */
class StorefrontLegalLaunchReportTest extends TestCase
{
    use ApprovesStorefrontTax;
    use BuildsStorefrontLegalDocuments;
    use BuildsStorefrontMoneyWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontMoneyWorld();
        $this->buildStorefrontLegalDocumentsTable();
        DB::table('business_settings')->where('type', 'like', 'storefront_legal_review_%')->delete();
        Schema::dropIfExists('shipping_types');
        config(['gcc.storefronts.countries.QA.active' => false, 'gcc.storefronts.countries.BH.active' => false, 'gcc.storefronts.countries.OM.active' => false]);
        $this->approveNoTaxFor('AE', 'KW');
    }

    protected function tearDown(): void
    {
        $this->dropStorefrontMoneyWorld();
        Schema::dropIfExists('storefront_legal_documents');

        parent::tearDown();
    }

    public function test_a_storefront_with_approved_texts_and_a_review_on_record_says_so_and_the_report_succeeds(): void
    {
        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->publishRequiredLegalDocuments(country: 'KW');

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('AE   legal: texts approved, independent review recorded (The legal counsel of AE, Legal opinion of the test world, on ' . now()->subDay()->toDateString() . ').')
            ->expectsOutputToContain('KW   legal: texts approved, independent review recorded')
            ->expectsOutputToContain('Ready: every storefront that is switched on has the currency it trades in.')
            ->assertExitCode(0);
    }

    public function test_a_storefront_with_no_texts_and_no_review_is_unsafe_for_both_reasons(): void
    {
        $this->publishRequiredLegalDocuments(country: 'KW');

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('AE   UNSAFE: its required legal texts are not all published and approved (or none is required), so it cannot sell')
            ->expectsOutputToContain('AE   UNSAFE: no independent legal review on record, so it cannot sell')
            ->expectsOutputToContain('Not ready: what is marked UNSAFE above has to be fixed first')
            ->assertExitCode(1);
    }

    public function test_approved_texts_with_no_review_are_unsafe_and_name_the_command_that_records_it(): void
    {
        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   UNSAFE: no independent legal review on record, so it cannot sell: record who reviewed the texts, where the opinion is written and the day, with gcc:storefront:legal-review.')
            ->doesntExpectOutputToContain('KW   UNSAFE: its required legal texts')
            ->assertExitCode(1);
    }

    public function test_a_review_with_approved_texts_missing_is_unsafe_for_the_texts_only(): void
    {
        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->recordLegalReviewFor('KW');

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   UNSAFE: its required legal texts are not all published and approved')
            ->doesntExpectOutputToContain('KW   UNSAFE: no independent legal review')
            ->assertExitCode(1);
    }

    public function test_a_row_that_does_not_say_who_reviewed_counts_for_nothing_and_the_report_says_so(): void
    {
        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->publishRequiredLegalDocuments(country: 'KW', reviewed: false);
        DB::table('business_settings')->insert(['type' => StorefrontLegalReview::settingName('KW'), 'value' => json_encode(['reference' => 'Opinion 1']), 'created_at' => now(), 'updated_at' => now()]);
        clearWebConfigCacheKeys();

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('KW   UNSAFE: the legal review on record does not say who reviewed, where the opinion is written or on which day, so it counts for nothing')
            ->assertExitCode(1);
    }

    public function test_a_storefront_that_keeps_its_old_documents_needs_neither_texts_nor_review(): void
    {
        $this->leaveTheLegalTextsOutOfThisTest();

        $this->artisan('gcc:storefront:currency-readiness')
            ->expectsOutputToContain('AE   legal: keeps the documents it has always had, so neither their texts nor a review of them are required.')
            ->doesntExpectOutputToContain('UNSAFE: its required legal texts')
            ->doesntExpectOutputToContain('UNSAFE: no independent legal review')
            ->assertExitCode(0);
    }

    public function test_the_default_storefront_and_a_storefront_that_is_not_switched_on_have_no_legal_line(): void
    {
        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->publishRequiredLegalDocuments(country: 'KW');

        $this->artisan('gcc:storefront:currency-readiness')
            ->doesntExpectOutputToContain('SA   legal')
            ->doesntExpectOutputToContain('QA   legal')
            ->doesntExpectOutputToContain('QA   UNSAFE')
            ->assertExitCode(0);
    }

    public function test_the_report_writes_no_review_and_no_audit_line(): void
    {
        $this->publishRequiredLegalDocuments(country: 'AE', reviewed: false);
        $reviews = DB::table('business_settings')->where('type', 'like', 'storefront_legal_review_%')->count();
        $audits = DB::table('audit_logs')->count();

        $this->artisan('gcc:storefront:currency-readiness')->assertExitCode(1);

        $this->assertSame([$reviews, $audits], [DB::table('business_settings')->where('type', 'like', 'storefront_legal_review_%')->count(), DB::table('audit_logs')->count()]);
    }
}
