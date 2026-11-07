<?php

namespace Tests\Feature\Address;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsApiAddressSchema;

/**
 * Base for the REST API and order-placement address tests: the address book with its real migrations, the catalog of
 * seedAddressCatalog(), the few store tables the API code reads, and no way out to a real provider.
 *
 * translate() appends a key it does not know to the tracked language files; the files are put back after every test so a run
 * leaves the working tree as it found it.
 */
abstract class ApiAddressTestCase extends AddressTestCase
{
    use BuildsApiAddressSchema;

    /** @var array<string, int> */
    protected array $ids;

    /** @var array<string, string> */
    private array $languageFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['en', 'sa', 'ae'] as $locale) {
            $path = base_path("resources/lang/{$locale}/new-messages.php");
            $this->languageFiles[$path] = (string) file_get_contents($path);
        }

        Http::preventStrayRequests();
        $this->createApiStoreTables();
        $this->ids = $this->seedAddressCatalog();
        $this->useThrowawayPassportKeys();
    }

    protected function tearDown(): void
    {
        foreach ($this->languageFiles as $path => $contents) {
            file_put_contents($path, $contents);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
        }

        parent::tearDown();
    }

    /** Signs the request in the way a mobile app does: a bearer token that resolves to this customer. */
    protected function asCustomer(User $customer): static
    {
        $this->actingAs($customer, 'api');

        return $this->withHeader('Authorization', 'Bearer test-token');
    }
}
