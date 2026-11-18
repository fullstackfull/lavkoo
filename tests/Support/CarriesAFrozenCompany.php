<?php

namespace Tests\Support;

/** The company an order was placed with (GCC-13) and the shop's own business identity, for the tests that read an invoice. */
trait CarriesAFrozenCompany
{
    /** @return array<string, mixed> the company as OrderLegalEntitySnapshot freezes it: Kuwait has no tax registration */
    protected function frozenKuwaitCompany(): array
    {
        return [
            'legal_entity_id' => 4, 'country' => 'KW', 'legal_name' => 'Lavkoo Kuwait Trading Co.', 'trade_name' => 'Lavkoo KW', 'tax_registration_number' => null,
            'commercial_registration_number' => 'CR-KW-777', 'invoice_series_key' => 'INV-KW', 'address' => '1 Example Street, Kuwait City',
        ];
    }

    /** @return array<string, mixed> the business identity line of the invoice settings: the shop's own tax number */
    protected function shopIdentity(): array
    {
        return ['business_identity_status' => 1, 'business_identity' => 'VAT', 'business_identity_value' => '300000000000003'];
    }

    protected function turnLegalEntitiesOnOrOff(bool $on): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.legal_entities.enabled' => $on]);
    }
}
