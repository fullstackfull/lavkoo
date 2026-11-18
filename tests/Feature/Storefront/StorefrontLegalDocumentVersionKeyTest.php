<?php

namespace Tests\Feature\Storefront;

use App\Models\StorefrontLegalDocument;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * I2-01 criterion 2: a version number is unique per country, type and locale, and the database says so, not only the publisher that numbers the versions. The table is built by the
 * real migrations (BuildsStorefrontLegalDocuments), and every case writes through the query builder, past the publisher, so that what refuses a second row is the key of the table.
 *
 * Covered: a second row with the same country, type, locale and version is refused whatever the status of the first (a version number is never reused, because the rows are kept as
 * history), a row that differs in any one of the four parts is stored (so the key is neither wider nor narrower than the four), and the key has the name and the columns the migration
 * gives it. Not covered: the publisher numbering its versions (StorefrontLegalPublisherTest) and the key under a case-insensitive collation of the country code.
 */
class StorefrontLegalDocumentVersionKeyTest extends TestCase
{
    use BuildsStorefrontLegalDocuments;

    private const VERSION_KEY = 'storefront_legal_docs_version_unique';

    private const COLUMNS_OF_THE_KEY = ['country_code', 'document_type', 'locale', 'version'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('storefront_legal_documents')) {
            DB::table('storefront_legal_documents')->delete();
        }

        parent::tearDown();
    }

    private function storedTexts(): int
    {
        return DB::table('storefront_legal_documents')->count();
    }

    public function test_the_same_version_of_the_same_text_cannot_be_stored_twice(): void
    {
        $this->publishLegalDocument(country: 'AE', type: 'terms', locale: 'en', overrides: ['version' => 3]);

        $this->assertThrows(
            fn () => $this->publishLegalDocument(country: 'AE', type: 'terms', locale: 'en', overrides: ['version' => 3, 'body' => 'Another text', 'body_hash' => hash('sha256', 'Another text')]),
            UniqueConstraintViolationException::class,
        );

        $this->assertSame(1, $this->storedTexts());
        $this->assertSame('Approved en text for terms', DB::table('storefront_legal_documents')->value('body'), 'the first text stays as it was written');
    }

    #[DataProvider('everyStatusOfTheFirstText')]
    public function test_a_version_number_is_never_reused_whatever_became_of_the_first_text(string $statusOfTheFirst): void
    {
        $this->publishLegalDocument(country: 'KW', type: 'refund', locale: 'ar', overrides: ['version' => 2, 'status' => $statusOfTheFirst]);

        $this->assertThrows(
            fn () => $this->publishLegalDocument(country: 'KW', type: 'refund', locale: 'ar', overrides: ['version' => 2, 'status' => StorefrontLegalDocument::STATUS_DRAFT]),
            UniqueConstraintViolationException::class,
        );

        $this->assertSame(1, $this->storedTexts());
    }

    public static function everyStatusOfTheFirstText(): array
    {
        return [
            'draft' => [StorefrontLegalDocument::STATUS_DRAFT],
            'approved' => [StorefrontLegalDocument::STATUS_APPROVED],
            'published' => [StorefrontLegalDocument::STATUS_PUBLISHED],
            'archived' => [StorefrontLegalDocument::STATUS_ARCHIVED],
        ];
    }

    #[DataProvider('onePartOfTheKeyThatDiffers')]
    public function test_a_text_that_differs_from_a_stored_one_in_a_single_part_of_the_key_is_stored(array $theDifference): void
    {
        $this->publishLegalDocument(country: 'AE', type: 'terms', locale: 'en', overrides: ['version' => 1]);

        $this->publishLegalDocument(country: 'AE', type: 'terms', locale: 'en', overrides: $theDifference + ['version' => 1]);

        $this->assertSame(2, $this->storedTexts());
    }

    public static function onePartOfTheKeyThatDiffers(): array
    {
        return [
            'the country' => [['country_code' => 'KW']],
            'the document type' => [['document_type' => 'privacy']],
            'the locale' => [['locale' => 'ar']],
            'the version' => [['version' => 2]],
        ];
    }

    public function test_the_key_of_the_table_is_unique_and_covers_the_country_the_type_the_locale_and_the_version(): void
    {
        $key = collect(Schema::getIndexes('storefront_legal_documents'))->firstWhere('name', self::VERSION_KEY);

        $this->assertNotNull($key, 'the table has no key named ' . self::VERSION_KEY);
        $this->assertTrue($key['unique'], self::VERSION_KEY . ' is not a unique key');
        $this->assertSame(self::COLUMNS_OF_THE_KEY, $key['columns']);
    }
}
