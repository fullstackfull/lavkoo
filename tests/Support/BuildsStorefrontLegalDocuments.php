<?php

namespace Tests\Support;

use App\Models\StorefrontLegalDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait BuildsStorefrontLegalDocuments
{
    /** Creates the table once and empties it, because MySQL keeps tables and rows between tests. */
    protected function buildStorefrontLegalDocumentsTable(): void
    {
        if (!Schema::hasTable('storefront_legal_documents')) {
            $migration = require database_path('migrations/2026_11_23_120000_create_storefront_legal_documents_table.php');
            $migration->up();
        }

        DB::table('storefront_legal_documents')->delete();
    }

    protected function publishLegalDocument(string $country, string $type, array $overrides = []): void
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

    protected function publishRequiredLegalDocuments(string $country, ?string $withheldType = null): void
    {
        foreach (config('gcc.storefronts.legal.required_documents') as $type) {
            if ($type !== $withheldType) {
                $this->publishLegalDocument(country: $country, type: $type);
            }
        }
    }
}
