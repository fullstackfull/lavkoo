<?php

namespace Tests\Feature\Storefront;

use App\Models\StorefrontLegalDocument;
use App\Services\Storefront\StorefrontLegalReadiness;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * A storefront sells only once each required legal document is published and approved by someone other than its author. The default
 * storefront is grandfathered, and nothing about another country's documents can open a storefront.
 */
class StorefrontLegalReadinessTest extends TestCase
{
    use BuildsStorefrontLegalDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
    }

    private function readiness(): StorefrontLegalReadiness
    {
        return app(StorefrontLegalReadiness::class);
    }

    private function assertStorefrontStaysClosedWithDefectiveDocument(string $country, string $documentType, array $defect): void
    {
        $this->publishRequiredLegalDocuments(country: $country, withheldType: $documentType);
        $this->publishLegalDocument(country: $country, type: $documentType, overrides: $defect);

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
        $this->publishRequiredLegalDocuments(country: 'AE');

        $this->assertTrue($this->readiness()->isReady('AE'));
    }

    public function test_the_country_code_is_matched_regardless_of_case(): void
    {
        $this->publishRequiredLegalDocuments(country: 'AE');

        $this->assertTrue($this->readiness()->isReady('ae'));
    }

    public function test_one_missing_required_document_keeps_the_storefront_closed(): void
    {
        $this->publishRequiredLegalDocuments(country: 'KW', withheldType: 'refund');

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
        $this->publishRequiredLegalDocuments(country: 'AE', withheldType: 'return');
        $this->publishLegalDocument(
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
        $this->publishRequiredLegalDocuments(country: 'KW');

        $this->assertFalse($this->readiness()->isReady('AE'));
        $this->assertTrue($this->readiness()->isReady('KW'));
    }
}
