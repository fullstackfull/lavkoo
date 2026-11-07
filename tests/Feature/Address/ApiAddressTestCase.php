<?php

namespace Tests\Feature\Address;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\BuildsApiAddressSchema;

/**
 * Base for the REST API and order-placement address tests: the address book with its real migrations, the catalog of
 * seedAddressCatalog(), the few store tables the API code reads, and no way out to a real provider.
 *
 * The few phrases of older templates that never had a text are written into the language files by translate(); the files are put back
 * after every test so a run leaves the working tree as it found it.
 */
abstract class ApiAddressTestCase extends AddressTestCase
{
    use BuildsApiAddressSchema;
    use RestoresLanguageFiles;

    /** @var array<string, int> */
    protected array $ids;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();

        Http::preventStrayRequests();
        $this->createApiStoreTables();
        $this->ids = $this->seedAddressCatalog();
        $this->useThrowawayPassportKeys();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** Signs the request in the way a mobile app does: a bearer token that resolves to this customer. */
    protected function asCustomer(User $customer): static
    {
        $this->actingAs($customer, 'api');

        return $this->withHeader('Authorization', 'Bearer test-token');
    }
}
