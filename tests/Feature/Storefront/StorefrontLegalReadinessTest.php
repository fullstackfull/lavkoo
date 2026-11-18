<?php

namespace Tests\Feature\Storefront;

use App\Models\StorefrontLegalDocument;
use App\Services\Storefront\StorefrontLegalReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A storefront sells only once every required document type is in every required locale, and the newest effective text of each is
 * published, approved by someone other than its author, not revoked, not blank and not an unedited template. Older, scheduled and
 * drafted texts never count. The default storefront is grandfathered, and nothing about another country's documents opens a storefront.
 */
class StorefrontLegalReadinessTest extends TestCase
{
    private const CREATE_MIGRATION = '2026_11_23_120000_create_storefront_legal_documents_table.php';
    private const EFFECTIVE_WINDOW_MIGRATION = '2026_11_24_100000_add_effective_window_to_storefront_legal_documents.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('storefront_legal_documents')) {
            $this->runLegalDocumentsMigration(self::CREATE_MIGRATION);
        }
        if (!Schema::hasColumn('storefront_legal_documents', 'effective_from')) {
            $this->runLegalDocumentsMigration(self::EFFECTIVE_WINDOW_MIGRATION);
        }

        DB::table('storefront_legal_documents')->delete();
    }

    private function runLegalDocumentsMigration(string $file): void
    {
        $migration = require database_path("migrations/{$file}");
        $migration->up();
    }

    private function readiness(): StorefrontLegalReadiness
    {
        return app(StorefrontLegalReadiness::class);
    }

    private function localesOf(string $country): array
    {
        return config("gcc.storefronts.legal.locales_by_country.{$country}") ?? config('gcc.storefronts.legal.required_locales');
    }

    private function publish(
        string $country,
        string $type,
        string $locale = 'en',
        int $version = 1,
        array $overrides = [],
    ): void {
        $body = "Approved {$locale} text for {$type} version {$version}";

        DB::table('storefront_legal_documents')->insert(array_merge([
            'country_code' => $country,
            'document_type' => $type,
            'locale' => $locale,
            'version' => $version,
            'status' => StorefrontLegalDocument::STATUS_PUBLISHED,
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'template_hash' => null,
            'created_by' => 1,
            'approved_by' => 2,
            'approved_at' => now()->subDays(2),
            'published_at' => now()->subDays(2),
            'effective_from' => now()->subDay(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function publishRequiredDocuments(string $country, ?string $withheldType = null): void
    {
        foreach (config('gcc.storefronts.legal.required_documents') as $type) {
            if ($type === $withheldType) {
                continue;
            }
            foreach ($this->localesOf($country) as $locale) {
                $this->publish(country: $country, type: $type, locale: $locale);
            }
        }
    }

    private function assertStorefrontStaysClosedWithDefectiveText(string $country, string $documentType, array $defect): void
    {
        $this->publishRequiredDocuments(country: $country, withheldType: $documentType);
        foreach ($this->localesOf($country) as $locale) {
            $this->publish(country: $country, type: $documentType, locale: $locale, overrides: $defect);
        }

        $this->assertFalse($this->readiness()->isReady($country));
    }

    public function test_the_default_storefront_is_grandfathered_and_needs_no_rows(): void
    {
        $this->assertTrue($this->readiness()->isReady('SA'));
    }

    public function test_a_storefront_with_no_documents_is_not_ready(): void
    {
        $this->assertFalse($this->readiness()->isReady('AE'));
    }

    public function test_a_storefront_with_every_required_document_in_every_required_locale_is_ready(): void
    {
        $this->publishRequiredDocuments(country: 'AE');

        $this->assertTrue($this->readiness()->isReady('AE'));
    }

    public function test_the_country_code_is_matched_regardless_of_case(): void
    {
        $this->publishRequiredDocuments(country: 'AE');

        $this->assertTrue($this->readiness()->isReady('ae'));
    }

    public function test_a_configuration_that_requires_no_document_keeps_every_storefront_but_the_grandfathered_one_closed(): void
    {
        $this->publishRequiredDocuments(country: 'AE');
        config(['gcc.storefronts.legal.required_documents' => []]);

        $this->assertFalse($this->readiness()->isReady('AE'), 'nothing required is a missing configuration, not a reason to open a market');
        $this->assertFalse($this->readiness()->isReady('KW'));
        $this->assertTrue($this->readiness()->isReady('SA'));
    }

    public function test_a_configuration_that_requires_no_locale_keeps_the_storefront_closed(): void
    {
        $this->publishRequiredDocuments(country: 'AE');
        config(['gcc.storefronts.legal.required_locales' => []]);

        $this->assertFalse($this->readiness()->isReady('AE'));
    }

    public function test_a_country_configured_to_present_no_locale_stays_closed(): void
    {
        $this->publishRequiredDocuments(country: 'QA');
        config(['gcc.storefronts.legal.locales_by_country.QA' => []]);

        $this->assertFalse($this->readiness()->isReady('QA'));
        $this->assertTrue($this->readiness()->isReady('SA'));
    }

    public function test_the_grandfathered_list_is_read_without_regard_to_case_or_spaces(): void
    {
        $this->assertTrue($this->readiness()->isGrandfathered(' sa '));
        $this->assertFalse($this->readiness()->isGrandfathered('AE'));

        config(['gcc.storefronts.legal.grandfathered' => []]);

        $this->assertFalse($this->readiness()->isGrandfathered('SA'));
    }

    public function test_one_missing_required_document_keeps_the_storefront_closed(): void
    {
        $this->publishRequiredDocuments(country: 'KW', withheldType: 'refund');

        $this->assertFalse($this->readiness()->isReady('KW'));
    }

    public function test_english_only_does_not_satisfy_a_storefront_that_requires_arabic(): void
    {
        foreach (config('gcc.storefronts.legal.required_documents') as $type) {
            $this->publish(country: 'AE', type: $type, locale: 'en');
        }

        $this->assertFalse($this->readiness()->isReady('AE'));
    }

    public function test_arabic_only_satisfies_a_country_configured_not_to_present_english(): void
    {
        config(['gcc.storefronts.legal.locales_by_country.QA' => ['ar']]);
        foreach (config('gcc.storefronts.legal.required_documents') as $type) {
            $this->publish(country: 'QA', type: $type, locale: 'ar');
        }

        $this->assertTrue($this->readiness()->isReady('QA'));
    }

    public function test_a_document_approved_by_its_own_author_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveText(
            country: 'QA',
            documentType: 'terms',
            defect: ['approved_by' => 1, 'created_by' => 1],
        );
    }

    public function test_a_document_without_a_known_author_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveText(
            country: 'QA',
            documentType: 'privacy',
            defect: ['created_by' => null],
        );
    }

    public function test_a_document_without_approval_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveText(
            country: 'BH',
            documentType: 'refund',
            defect: ['approved_by' => null, 'approved_at' => null],
        );
    }

    public function test_a_draft_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveText(
            country: 'OM',
            documentType: 'return',
            defect: ['status' => StorefrontLegalDocument::STATUS_DRAFT],
        );
    }

    public function test_an_approved_but_unpublished_version_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveText(
            country: 'OM',
            documentType: 'cancellation',
            defect: ['status' => StorefrontLegalDocument::STATUS_APPROVED],
        );
    }

    public function test_an_archived_version_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveText(
            country: 'AE',
            documentType: 'shipping',
            defect: ['status' => StorefrontLegalDocument::STATUS_ARCHIVED],
        );
    }

    public function test_a_text_that_is_not_yet_effective_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveText(
            country: 'AE',
            documentType: 'terms',
            defect: ['effective_from' => now()->addDay()],
        );
    }

    public function test_a_published_text_without_an_effective_date_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveText(
            country: 'QA',
            documentType: 'privacy',
            defect: ['effective_from' => null],
        );
    }

    public function test_a_whitespace_only_body_does_not_count(): void
    {
        $blankBody = " \n\t ";

        $this->assertStorefrontStaysClosedWithDefectiveText(
            country: 'KW',
            documentType: 'shipping',
            defect: ['body' => $blankBody, 'body_hash' => hash('sha256', $blankBody)],
        );
    }

    public function test_an_unedited_template_does_not_count(): void
    {
        $template = 'Template text';

        $this->assertStorefrontStaysClosedWithDefectiveText(
            country: 'AE',
            documentType: 'return',
            defect: [
                'body' => $template,
                'body_hash' => hash('sha256', $template),
                'template_hash' => hash('sha256', $template),
            ],
        );
    }

    public function test_a_template_copy_that_was_edited_counts_as_approved_text(): void
    {
        $this->publishRequiredDocuments(country: 'AE', withheldType: 'return');
        foreach ($this->localesOf('AE') as $locale) {
            $this->publish(
                country: 'AE',
                type: 'return',
                locale: $locale,
                overrides: [
                    'body' => 'Edited text',
                    'body_hash' => hash('sha256', 'Edited text'),
                    'template_hash' => hash('sha256', 'Template text'),
                ],
            );
        }

        $this->assertTrue($this->readiness()->isReady('AE'));
    }

    public function test_a_newer_draft_does_not_replace_the_current_text(): void
    {
        $this->publishRequiredDocuments(country: 'AE');
        foreach ($this->localesOf('AE') as $locale) {
            $this->publish(
                country: 'AE',
                type: 'terms',
                locale: $locale,
                version: 2,
                overrides: ['status' => StorefrontLegalDocument::STATUS_DRAFT, 'body' => '', 'body_hash' => hash('sha256', '')],
            );
        }

        $this->assertTrue($this->readiness()->isReady('AE'));
    }

    public function test_a_scheduled_text_does_not_replace_the_current_text(): void
    {
        $this->publishRequiredDocuments(country: 'AE');
        foreach ($this->localesOf('AE') as $locale) {
            $this->publish(
                country: 'AE',
                type: 'terms',
                locale: $locale,
                version: 2,
                overrides: ['effective_from' => now()->addDay(), 'body' => '', 'body_hash' => hash('sha256', '')],
            );
        }

        $this->assertTrue($this->readiness()->isReady('AE'));
    }

    public function test_a_superseded_text_does_not_count_even_when_it_was_valid(): void
    {
        $this->publishRequiredDocuments(country: 'AE');
        foreach ($this->localesOf('AE') as $locale) {
            $this->publish(
                country: 'AE',
                type: 'terms',
                locale: $locale,
                version: 2,
                overrides: ['effective_from' => now()->subHour(), 'body' => '', 'body_hash' => hash('sha256', '')],
            );
        }

        $this->assertFalse($this->readiness()->isReady('AE'));
    }

    public function test_a_newer_valid_text_supersedes_an_older_defective_one(): void
    {
        $this->publishRequiredDocuments(country: 'AE', withheldType: 'terms');
        foreach ($this->localesOf('AE') as $locale) {
            $this->publish(
                country: 'AE',
                type: 'terms',
                locale: $locale,
                version: 1,
                overrides: ['body' => '', 'body_hash' => hash('sha256', ''), 'effective_from' => now()->subDays(3)],
            );
        }

        $this->assertFalse($this->readiness()->isReady('AE'));

        foreach ($this->localesOf('AE') as $locale) {
            $this->publish(country: 'AE', type: 'terms', locale: $locale, version: 2, overrides: ['effective_from' => now()->subHour()]);
        }

        $this->assertTrue($this->readiness()->isReady('AE'));
    }

    public function test_a_revoked_current_text_keeps_the_storefront_closed(): void
    {
        $this->publishRequiredDocuments(country: 'AE');
        foreach ($this->localesOf('AE') as $locale) {
            $this->publish(
                country: 'AE',
                type: 'return',
                locale: $locale,
                version: 2,
                overrides: ['effective_from' => now()->subHour(), 'revoked_at' => now()->subMinute()],
            );
        }

        $this->assertFalse($this->readiness()->isReady('AE'));
    }

    public function test_an_old_revoked_text_does_not_matter_once_a_valid_text_is_current(): void
    {
        $this->publishRequiredDocuments(country: 'AE', withheldType: 'return');
        foreach ($this->localesOf('AE') as $locale) {
            $this->publish(
                country: 'AE',
                type: 'return',
                locale: $locale,
                version: 1,
                overrides: ['effective_from' => now()->subDays(3), 'revoked_at' => now()->subDays(2)],
            );
            $this->publish(country: 'AE', type: 'return', locale: $locale, version: 2, overrides: ['effective_from' => now()->subHour()]);
        }

        $this->assertTrue($this->readiness()->isReady('AE'));
    }

    public function test_when_two_texts_share_an_effective_date_the_higher_version_is_current(): void
    {
        $effectiveAt = CarbonImmutable::now()->subDay();
        $this->publishRequiredDocuments(country: 'AE', withheldType: 'terms');
        foreach ($this->localesOf('AE') as $locale) {
            $this->publish(country: 'AE', type: 'terms', locale: $locale, version: 1, overrides: ['effective_from' => $effectiveAt]);
            $this->publish(
                country: 'AE',
                type: 'terms',
                locale: $locale,
                version: 2,
                overrides: ['effective_from' => $effectiveAt, 'body' => '', 'body_hash' => hash('sha256', '')],
            );
        }

        $this->assertFalse($this->readiness()->isReady('AE'));
    }

    public function test_a_blank_current_text_keeps_the_storefront_closed(): void
    {
        $this->publishRequiredDocuments(country: 'AE', withheldType: 'shipping');
        foreach ($this->localesOf('AE') as $locale) {
            $this->publish(country: 'AE', type: 'shipping', locale: $locale, overrides: ['body' => " \n\t ", 'body_hash' => hash('sha256', " \n\t ")]);
        }

        $this->assertFalse($this->readiness()->isReady('AE'));
    }

    public function test_documents_of_another_country_do_not_open_a_storefront(): void
    {
        $this->publishRequiredDocuments(country: 'KW');

        $this->assertFalse($this->readiness()->isReady('AE'));
        $this->assertTrue($this->readiness()->isReady('KW'));
    }

    public function test_a_missing_table_keeps_every_non_default_storefront_closed_without_an_error(): void
    {
        Schema::drop('storefront_legal_documents');

        $this->assertFalse($this->readiness()->isReady('AE'));
        $this->assertTrue($this->readiness()->isReady('SA'));
    }
}
