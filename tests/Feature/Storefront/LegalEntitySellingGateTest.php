<?php

namespace Tests\Feature\Storefront;

use App\Exceptions\StorefrontNotSellableException;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontSellingGate;
use Tests\Support\BuildsLegalEntityWorld;
use Tests\TestCase;

/**
 * The owner may require a company for a storefront to sell (GCC_LEGAL_ENTITIES_REQUIRED, off by default). The legal texts are treated as approved here
 * (every country is grandfathered), so the only thing the gate can refuse is the missing company.
 */
class LegalEntitySellingGateTest extends TestCase
{
    use BuildsLegalEntityWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLegalEntityWorld();
        config(['gcc.storefronts.legal.grandfathered' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM']]);
    }

    protected function tearDown(): void
    {
        $this->tearDownLegalEntityWorld();

        parent::tearDown();
    }

    private function mayHaveSold(string $country): bool
    {
        return app(StorefrontSellingGate::class)->maySell(country: $country, currencyCode: 'KWD');
    }

    public function test_a_missing_company_stops_nothing_until_the_owner_requires_one(): void
    {
        $this->assertTrue($this->mayHaveSold('KW'));
    }

    public function test_when_required_a_storefront_without_an_active_company_may_not_sell_and_one_with_it_may(): void
    {
        config(['gcc.storefronts.legal_entities.required_to_sell' => true]);

        $this->assertFalse($this->mayHaveSold('KW'));

        $this->registerEntity('KW');
        $this->assertTrue($this->mayHaveSold('KW'));

        $this->registerEntity('QA', ['status' => false]);
        $this->assertFalse($this->mayHaveSold('QA'), 'an inactive company does not count');
    }

    public function test_the_default_storefront_is_never_refused_for_lack_of_a_company(): void
    {
        config(['gcc.storefronts.legal_entities.required_to_sell' => true]);

        $this->assertTrue($this->mayHaveSold('SA'));
    }

    public function test_the_requirement_is_ignored_while_the_registry_is_off_or_storefronts_are_off(): void
    {
        config(['gcc.storefronts.legal_entities.required_to_sell' => true, 'gcc.storefronts.legal_entities.enabled' => false]);
        $this->assertTrue($this->mayHaveSold('KW'), 'the registry is off');

        config(['gcc.storefronts.legal_entities.enabled' => true, 'gcc.storefronts.enabled' => false]);
        $this->assertTrue($this->mayHaveSold('KW'), 'storefronts are off');
    }

    public function test_the_refusal_is_the_one_every_other_reason_gives(): void
    {
        config(['gcc.storefronts.legal_entities.required_to_sell' => true]);

        $this->expectException(StorefrontNotSellableException::class);

        app(StorefrontSellingGate::class)->assertMaySell(new StorefrontContext(country: 'KW', currency: 'KWD', storefrontsEnabled: true));
    }
}
