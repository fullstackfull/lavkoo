<?php

namespace Tests\Feature\Storefront;

use App\Models\LegalEntity;
use App\Models\StorefrontLegalAcceptance;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontLegalAcceptanceRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsLegalEntityWorld;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * A legal acceptance is scoped by storefront AND by selling company (the marketing-and-legal-consents criterion of M2-04).
 *
 * The receipt has always carried the storefront. The company it carried was `GCC_LEGAL_ENTITY`, one platform-wide string, so six storefronts
 * selling under six companies wrote six receipts naming one. These tests pin the three things that matter: with legal entities off nothing
 * about the row changes, with them on the receipt names the storefront's own company, and what it names stays what it named after the
 * company's own row is edited.
 */
class LegalAcceptanceNamesItsSellingCompanyTest extends TestCase
{
    use BuildsLegalEntityWorld;
    use BuildsStorefrontLegalDocuments;

    private const ACCEPTANCE_TABLE = 'storefront_legal_acceptances';

    private const ACCEPTANCE_MIGRATION = '2026_11_24_110000_create_storefront_legal_acceptances_table.php';

    private const COMPANY_MIGRATION = '2027_01_06_100000_add_legal_entity_to_storefront_legal_acceptances_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLegalEntityWorld();
        $this->buildStorefrontLegalDocumentsTable();
        $this->buildAcceptanceTable();

        config(['gcc.storefronts.legal.explicit_acceptance' => ['terms'], 'gcc.storefronts.legal.legal_entity' => 'LAVKOO-PLATFORM']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::ACCEPTANCE_TABLE);
        $this->tearDownLegalEntityWorld();

        parent::tearDown();
    }

    private function buildAcceptanceTable(): void
    {
        Schema::dropIfExists(self::ACCEPTANCE_TABLE);
        (require database_path('migrations/' . self::ACCEPTANCE_MIGRATION))->up();
        (require database_path('migrations/' . self::COMPANY_MIGRATION))->up();
    }

    private function recorder(): StorefrontLegalAcceptanceRecorder
    {
        return app(StorefrontLegalAcceptanceRecorder::class);
    }

    /**
     * THE FIRST TEST, and the one every other capability of this track is held to: with the registry switched off -- which is how the
     * platform ships and how Saudi Arabia runs -- a receipt is the receipt that was written before any of this existed. The configured
     * label, and not one byte more.
     */
    public function test_with_legal_entities_off_the_receipt_is_exactly_the_row_that_was_written_before(): void
    {
        config(['gcc.storefronts.legal_entities.enabled' => false]);
        app(LegalEntityRegistry::class)->flush();
        $this->registerEntity('AE');
        $this->publishRequiredLegalDocuments(country: 'AE');

        $acceptance = $this->recorder()->recordIfRequired('AE', 'terms', 'en');

        $this->assertSame('LAVKOO-PLATFORM', $acceptance->legal_entity);
        $this->assertNull($acceptance->legal_entity_id);
        $this->assertNull($acceptance->entity_snapshot);
    }

    public function test_two_storefronts_name_two_different_selling_companies(): void
    {
        $emirates = $this->registerEntity('AE');
        $kuwait = $this->registerEntity('KW');
        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->publishRequiredLegalDocuments(country: 'KW');

        $accepted = [
            'AE' => $this->recorder()->recordIfRequired('AE', 'terms', 'en'),
            'KW' => $this->recorder()->recordIfRequired('KW', 'terms', 'en'),
        ];

        $this->assertSame((int) $emirates->id, $accepted['AE']->legal_entity_id);
        $this->assertSame((int) $kuwait->id, $accepted['KW']->legal_entity_id);
        $this->assertNotSame($accepted['AE']->legal_entity_id, $accepted['KW']->legal_entity_id);
    }

    public function test_the_receipt_freezes_the_company_identity_it_was_given(): void
    {
        $entity = $this->registerEntity('AE');
        $this->publishRequiredLegalDocuments(country: 'AE');

        $acceptance = $this->recorder()->recordIfRequired('AE', 'terms', 'en');
        $frozen = $this->frozenCompanyOf($acceptance);

        $this->assertSame((int) $entity->id, $frozen['legal_entity_id']);
        $this->assertSame('AE', $frozen['country']);
        $this->assertSame('Lavkoo AE Trading Co.', $frozen['legal_name']);
        $this->assertSame('TAX-AE-001', $frozen['tax_registration_number']);
    }

    public function test_a_later_change_of_the_company_never_rewrites_a_receipt(): void
    {
        $entity = $this->registerEntity('AE');
        $this->publishRequiredLegalDocuments(country: 'AE');
        $acceptance = $this->recorder()->recordIfRequired('AE', 'terms', 'en');

        LegalEntity::query()->whereKey($entity->id)->update(['legal_name' => 'Renamed AE Holding Co.', 'tax_registration_number' => 'TAX-AE-999']);
        app(LegalEntityRegistry::class)->flush();

        $kept = $this->frozenCompanyOf(StorefrontLegalAcceptance::query()->findOrFail($acceptance->id));

        $this->assertSame('Lavkoo AE Trading Co.', $kept['legal_name']);
        $this->assertSame('TAX-AE-001', $kept['tax_registration_number']);
    }

    /** @return array<string, int|string|null> */
    private function frozenCompanyOf(StorefrontLegalAcceptance $acceptance): array
    {
        $frozen = json_decode((string) $acceptance->entity_snapshot, true);

        $this->assertIsArray($frozen, 'The receipt carries no frozen copy of the company that sold under it.');

        return $frozen;
    }

    /** A storefront with no registered company writes the receipt it always wrote: there is nothing to name, and a text still has to be accepted. */
    public function test_a_storefront_with_no_registered_company_still_records_its_acceptance(): void
    {
        $this->publishRequiredLegalDocuments(country: 'AE');

        $acceptance = $this->recorder()->recordIfRequired('AE', 'terms', 'en');

        $this->assertSame('AE', $acceptance->storefront_country);
        $this->assertNull($acceptance->legal_entity_id);
        $this->assertNull($acceptance->entity_snapshot);
    }

    /** An inactive company is no company: the receipt names none rather than one the registry refuses to sell under. */
    public function test_an_inactive_company_is_not_named_on_a_receipt(): void
    {
        $this->registerEntity('AE', ['status' => false]);
        $this->publishRequiredLegalDocuments(country: 'AE');

        $acceptance = $this->recorder()->recordIfRequired('AE', 'terms', 'en');

        $this->assertNull($acceptance->legal_entity_id);
    }

    /** Before the migration the recorder must not ask for columns the table has not got; the receipt is written all the same. */
    public function test_nothing_is_named_before_this_tracks_migration_has_run(): void
    {
        Schema::dropIfExists(self::ACCEPTANCE_TABLE);
        (require database_path('migrations/' . self::ACCEPTANCE_MIGRATION))->up();
        $this->registerEntity('AE');
        $this->publishRequiredLegalDocuments(country: 'AE');

        $acceptance = $this->recorder()->recordIfRequired('AE', 'terms', 'en');

        $this->assertFalse(Schema::hasColumn(self::ACCEPTANCE_TABLE, 'legal_entity_id'));
        $this->assertSame('AE', $acceptance->storefront_country);
        $this->assertSame(1, DB::table(self::ACCEPTANCE_TABLE)->count());
    }

    /** The migration refuses to forget which company a customer accepted a text from. */
    public function test_the_migration_is_not_rolled_back_while_a_receipt_names_a_company(): void
    {
        $this->registerEntity('AE');
        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->recorder()->recordIfRequired('AE', 'terms', 'en');

        $this->expectExceptionMessage('Rolling this migration back would forget who each customer accepted a text from.');

        (require database_path('migrations/' . self::COMPANY_MIGRATION))->down();
    }
}
