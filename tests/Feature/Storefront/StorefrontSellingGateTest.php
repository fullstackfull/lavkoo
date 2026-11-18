<?php

namespace Tests\Feature\Storefront;

use App\Exceptions\StorefrontNotSellableException;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontSellingGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * The gate refuses only the start of a new sale in a storefront that may not sell yet. Nothing is refused while storefronts are off, and
 * the Saudi storefront is never refused.
 */
class StorefrontSellingGateTest extends TestCase
{
    use ApprovesStorefrontTax;
    use BuildsStorefrontLegalDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table) {
                $table->id();
                $table->string('type')->nullable();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }
    }

    private function gate(): StorefrontSellingGate
    {
        return app(StorefrontSellingGate::class);
    }

    private function contextOf(string $country): StorefrontContext
    {
        return new StorefrontContext(country: $country, currency: 'AED', storefrontsEnabled: true);
    }

    public function test_nothing_is_refused_while_storefronts_are_off(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.countries.AE.active' => true]);
        $this->expectNotToPerformAssertions();

        $this->gate()->assertMaySell($this->contextOf('AE'));
    }

    public function test_the_default_storefront_is_never_refused(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->expectNotToPerformAssertions();

        $this->gate()->assertMaySell($this->contextOf('SA'));
    }

    public function test_an_active_storefront_without_approved_legal_texts_is_refused(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $this->expectException(StorefrontNotSellableException::class);

        $this->gate()->assertMaySell($this->contextOf('AE'));
    }

    public function test_a_storefront_with_approved_legal_texts_and_a_tax_decision_may_sell(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->approveNoTaxFor('AE');
        $this->expectNotToPerformAssertions();

        $this->gate()->assertMaySell($this->contextOf('AE'));
    }

    public function test_a_missing_legal_table_refuses_the_sale_as_not_sellable_rather_than_failing(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        Schema::drop('storefront_legal_documents');

        try {
            $this->gate()->assertMaySell($this->contextOf('AE'));
            $this->fail('A storefront whose legal texts cannot be read must not sell.');
        } catch (StorefrontNotSellableException $refusal) {
            $this->assertSame('AE', $refusal->countryCode);
        }
    }

    public function test_an_api_request_is_answered_with_422_and_a_stable_code(): void
    {
        $request = Request::create('/api/v1/checkout', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);

        $response = (new StorefrontNotSellableException(countryCode: 'AE'))->render($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('storefront_not_sellable', $response->getData(true)['code']);
    }
}
