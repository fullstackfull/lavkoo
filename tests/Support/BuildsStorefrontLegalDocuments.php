<?php

namespace Tests\Support;

use App\Models\StorefrontLegalDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the legal-documents table at its current schema and publishes texts the way readiness reads them: effective, approved by
 * someone other than the author, and in every locale the storefront requires, and records the independent legal review of them (unless a test says it is
 * the missing review that it tests).
 */
trait BuildsStorefrontLegalDocuments
{
    use RecordsStorefrontLegalReview;

    /** Creates the table once, at its current schema, and empties it, because MySQL keeps tables and rows between tests. */
    protected function buildStorefrontLegalDocumentsTable(): void
    {
        if (!Schema::hasTable('storefront_legal_documents')) {
            $this->runLegalDocumentsMigration('2026_11_23_120000_create_storefront_legal_documents_table.php');
        }
        if (!Schema::hasColumn('storefront_legal_documents', 'effective_from')) {
            $this->runLegalDocumentsMigration('2026_11_24_100000_add_effective_window_to_storefront_legal_documents.php');
        }

        DB::table('storefront_legal_documents')->delete();
    }

    private function runLegalDocumentsMigration(string $file): void
    {
        $migration = require database_path("migrations/{$file}");
        $migration->up();
    }

    protected function publishLegalDocument(string $country, string $type, string $locale = 'en', array $overrides = []): void
    {
        $body = "Approved {$locale} text for {$type}";

        DB::table('storefront_legal_documents')->insert(array_merge([
            'country_code' => $country,
            'document_type' => $type,
            'locale' => $locale,
            'version' => 1,
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

    protected function publishRequiredLegalDocuments(string $country, ?string $withheldType = null, bool $reviewed = true): void
    {
        if ($reviewed) {
            $this->recordLegalReviewFor($country);
        }

        $locales = config("gcc.storefronts.legal.locales_by_country.{$country}") ?? config('gcc.storefronts.legal.required_locales');

        foreach (config('gcc.storefronts.legal.required_documents') as $type) {
            if ($type === $withheldType) {
                continue;
            }
            foreach ($locales as $locale) {
                $this->publishLegalDocument(country: $country, type: $type, locale: $locale);
            }
        }
    }
}
