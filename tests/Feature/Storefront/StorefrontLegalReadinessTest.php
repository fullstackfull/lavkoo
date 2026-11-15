<?php

namespace Tests\Feature\Storefront;

use App\Models\StorefrontLegalDocument;
use App\Services\Storefront\StorefrontLegalReadiness;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A storefront sells only once each required legal document is published and approved by someone other than its author. The default
 * storefront is grandfathered, and nothing about another country's documents can open a storefront.
 */
class StorefrontLegalReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migration = require database_path('migrations/2026_11_23_120000_create_storefront_legal_documents_table.php');
        $migration->up();
    }

    private function readiness(): StorefrontLegalReadiness
    {
        return app(StorefrontLegalReadiness::class);
    }

    private function publish(string $country, string $type, array $overrides = []): void
    {
        $body = 'Approved text for ' . $type;

        DB::table('storefront_legal_documents')->insert(array_merge([
            'country_code' => $country,
            'document_type' => $type,
            'locale' => 'en',
            'version' => 1,
            'status' => StorefrontLegalDocument::STATUS_PUBLISHED,
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'template_hash' => null,
            'created_by' => 1,
            'approved_by' => 2,
            'approved_at' => now(),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function publishRequiredDocuments(string $country, ?string $withheldType = null): void
    {
        foreach (config('gcc.storefronts.legal.required_documents') as $type) {
            if ($type !== $withheldType) {
                $this->publish(country: $country, type: $type);
            }
        }
    }

    private function assertStorefrontStaysClosedWithDefectiveDocument(string $country, string $documentType, array $defect): void
    {
        $this->publishRequiredDocuments(country: $country, withheldType: $documentType);
        $this->publish(country: $country, type: $documentType, overrides: $defect);

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

    public function test_a_storefront_with_every_required_document_published_and_approved_is_ready(): void
    {
        $this->publishRequiredDocuments(country: 'AE');

        $this->assertTrue($this->readiness()->isReady('AE'));
    }

    public function test_the_country_code_is_matched_regardless_of_case(): void
    {
        $this->publishRequiredDocuments(country: 'AE');

        $this->assertTrue($this->readiness()->isReady('ae'));
    }

    public function test_one_missing_required_document_keeps_the_storefront_closed(): void
    {
        $this->publishRequiredDocuments(country: 'KW', withheldType: 'refund');

        $this->assertFalse($this->readiness()->isReady('KW'));
    }

    public function test_a_document_approved_by_its_own_author_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveDocument(
            country: 'QA',
            documentType: 'terms',
            defect: ['approved_by' => 1, 'created_by' => 1],
        );
    }

    public function test_a_document_without_a_known_author_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveDocument(
            country: 'QA',
            documentType: 'privacy',
            defect: ['created_by' => null],
        );
    }

    public function test_a_document_without_approval_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveDocument(
            country: 'BH',
            documentType: 'refund',
            defect: ['approved_by' => null, 'approved_at' => null],
        );
    }

    public function test_a_draft_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveDocument(
            country: 'OM',
            documentType: 'return',
            defect: ['status' => StorefrontLegalDocument::STATUS_DRAFT],
        );
    }

    public function test_an_approved_but_unpublished_version_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveDocument(
            country: 'OM',
            documentType: 'cancellation',
            defect: ['status' => StorefrontLegalDocument::STATUS_APPROVED],
        );
    }

    public function test_an_archived_version_does_not_count(): void
    {
        $this->assertStorefrontStaysClosedWithDefectiveDocument(
            country: 'AE',
            documentType: 'shipping',
            defect: ['status' => StorefrontLegalDocument::STATUS_ARCHIVED],
        );
    }

    public function test_a_whitespace_only_body_does_not_count(): void
    {
        $blankBody = " \n\t ";

        $this->assertStorefrontStaysClosedWithDefectiveDocument(
            country: 'KW',
            documentType: 'shipping',
            defect: ['body' => $blankBody, 'body_hash' => hash('sha256', $blankBody)],
        );
    }

    public function test_an_unedited_template_does_not_count(): void
    {
        $template = 'Template text';

        $this->assertStorefrontStaysClosedWithDefectiveDocument(
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
        $this->publish(
            country: 'AE',
            type: 'return',
            overrides: [
                'body' => 'Edited for the UAE',
                'body_hash' => hash('sha256', 'Edited for the UAE'),
                'template_hash' => hash('sha256', 'Template text'),
            ],
        );

        $this->assertTrue($this->readiness()->isReady('AE'));
    }

    public function test_documents_of_another_country_do_not_open_a_storefront(): void
    {
        $this->publishRequiredDocuments(country: 'KW');

        $this->assertFalse($this->readiness()->isReady('AE'));
        $this->assertTrue($this->readiness()->isReady('KW'));
    }
}
