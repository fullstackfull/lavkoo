<?php

namespace Tests\Feature\Storefront;

use App\Models\StorefrontLegalAcceptance;
use App\Services\Storefront\StorefrontLegalAcceptanceRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * An acceptance is kept only for the document types the configuration asks to accept explicitly. It names the text's version and hash, so
 * it stays provable after a later version, and identity is kept only when the privacy policy allows it.
 */
class StorefrontLegalAcceptanceTest extends TestCase
{
    use BuildsStorefrontLegalDocuments;

    private const ACCEPTANCE_MIGRATION = '2026_11_24_110000_create_storefront_legal_acceptances_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
        if (!Schema::hasTable('storefront_legal_acceptances')) {
            $migration = require database_path('migrations/' . self::ACCEPTANCE_MIGRATION);
            $migration->up();
        }

        DB::table('storefront_legal_acceptances')->delete();
    }

    private function recorder(): StorefrontLegalAcceptanceRecorder
    {
        return app(StorefrontLegalAcceptanceRecorder::class);
    }

    private function requireExplicitAcceptanceFor(string ...$types): void
    {
        config(['gcc.storefronts.legal.explicit_acceptance' => $types]);
    }

    public function test_nothing_is_recorded_for_a_type_that_does_not_need_explicit_acceptance(): void
    {
        $this->publishRequiredLegalDocuments(country: 'AE');

        $this->assertNull($this->recorder()->recordIfRequired('AE', 'terms', 'ar', customerId: 7));
        $this->assertSame(0, StorefrontLegalAcceptance::query()->count());
    }

    public function test_an_explicit_acceptance_records_the_current_text_version_and_its_hash(): void
    {
        $this->requireExplicitAcceptanceFor('terms');
        $this->publishRequiredLegalDocuments(country: 'AE');

        $acceptance = $this->recorder()->recordIfRequired('AE', 'terms', 'ar');

        $this->assertSame('AE', $acceptance->storefront_country);
        $this->assertSame('terms', $acceptance->document_type);
        $this->assertSame('ar', $acceptance->locale);
        $this->assertSame(1, $acceptance->document_version);
        $this->assertSame(hash('sha256', 'Approved ar text for terms'), $acceptance->document_hash);
    }

    public function test_the_acceptance_records_the_newest_effective_text_not_a_superseded_one(): void
    {
        $this->requireExplicitAcceptanceFor('terms');
        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->publishLegalDocument(country: 'AE', type: 'terms', locale: 'ar', overrides: ['version' => 2, 'effective_from' => now()->subHour()]);

        $acceptance = $this->recorder()->recordIfRequired('AE', 'terms', 'ar');

        $this->assertSame(2, $acceptance->document_version);
    }

    public function test_identity_is_not_kept_unless_the_privacy_policy_allows_it(): void
    {
        $this->requireExplicitAcceptanceFor('terms');
        $this->publishRequiredLegalDocuments(country: 'AE');

        $acceptance = $this->recorder()->recordIfRequired('AE', 'terms', 'ar', customerId: 7, guestId: 'guest-123');

        $this->assertNull($acceptance->customer_id);
        $this->assertNull($acceptance->guest_hash);
    }

    public function test_when_identity_is_allowed_a_guest_is_kept_only_as_a_hash(): void
    {
        $this->requireExplicitAcceptanceFor('terms');
        config(['gcc.storefronts.legal.store_identity' => true]);
        $this->publishRequiredLegalDocuments(country: 'AE');

        $acceptance = $this->recorder()->recordIfRequired('AE', 'terms', 'ar', guestId: 'guest-123');

        $this->assertSame(hash('sha256', 'guest-123'), $acceptance->guest_hash);
        $this->assertStringNotContainsString('guest-123', (string) $acceptance->guest_hash);
    }

    public function test_an_acceptance_keeps_its_text_when_a_later_version_supersedes_it(): void
    {
        $this->requireExplicitAcceptanceFor('terms');
        $this->publishRequiredLegalDocuments(country: 'AE');
        $acceptance = $this->recorder()->recordIfRequired('AE', 'terms', 'ar');

        $this->publishLegalDocument(
            country: 'AE',
            type: 'terms',
            locale: 'ar',
            overrides: [
                'version' => 2,
                'effective_from' => now()->subHour(),
                'body' => 'A new text',
                'body_hash' => hash('sha256', 'A new text'),
            ],
        );

        $kept = StorefrontLegalAcceptance::query()->findOrFail($acceptance->id);
        $this->assertSame(1, $kept->document_version);
        $this->assertSame(hash('sha256', 'Approved ar text for terms'), $kept->document_hash);
    }

    public function test_nothing_is_accepted_when_no_effective_text_exists(): void
    {
        $this->requireExplicitAcceptanceFor('terms');

        $this->expectException(RuntimeException::class);

        $this->recorder()->recordIfRequired('AE', 'terms', 'ar');
    }

    public function test_the_acceptance_names_the_legal_entity_from_configuration(): void
    {
        $this->requireExplicitAcceptanceFor('terms');
        config(['gcc.storefronts.legal.legal_entity' => 'Example Holdings LLC']);
        $this->publishRequiredLegalDocuments(country: 'AE');

        $acceptance = $this->recorder()->recordIfRequired('AE', 'terms', 'ar');

        $this->assertSame('Example Holdings LLC', $acceptance->legal_entity);
    }
}
