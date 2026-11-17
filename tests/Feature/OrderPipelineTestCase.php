<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsOrderPipelineSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Base for the tests that run the real cart, checkout and payment-callback code: the few store tables those read (BuildsOrderPipelineSchema),
 * a throwaway Passport key pair, and no way out to a real provider. It stands in for the base class of the address suite of the integration
 * branch, which needs that branch's address migrations.
 *
 * The few phrases of older templates that never had a text are written into the language files by translate(); the files are put back after
 * every test so a run leaves the working tree as it found it.
 */
abstract class OrderPipelineTestCase extends TestCase
{
    use BuildsOrderPipelineSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->resetOrderPipelineTestDatabase();
        $this->createLegacyAddressTables();

        Http::preventStrayRequests();
        $this->createApiStoreTables();
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
