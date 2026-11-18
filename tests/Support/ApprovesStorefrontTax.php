<?php

namespace Tests\Support;

use App\Services\Storefront\StorefrontTaxApproval;

/**
 * The tax decision a storefront needs before it may sell (D1-07), recorded the way the command records it: through StorefrontTaxApproval, with its audit line. A test of the
 * checkout, of a payment or of a cart in a storefront other than the Saudi one calls it so that the sale gate is not what is under test; a test of the gate itself does not.
 * What it wrote is taken away when the test ends, because a real server keeps the tables and the rows of one test class for the next.
 */
trait ApprovesStorefrontTax
{
    use BuildsStorefrontDecisionTables;

    /** The documented decision that each storefront charges no tax. It holds only while the storefront has no setup that charges one. */
    protected function approveNoTaxFor(string ...$countries): void
    {
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_tax_approval_');

        foreach ($countries as $country) {
            app(StorefrontTaxApproval::class)->approve(country: $country, decision: StorefrontTaxApproval::DECISION_NO_TAX, approvedBy: 'The finance director', reference: 'Tax decision of the test world');
        }
    }

    /** The approval of the tax setup each storefront has now. It holds only while the setup stays as it is. */
    protected function approveTheTaxSetupOf(string ...$countries): void
    {
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_tax_approval_');

        foreach ($countries as $country) {
            app(StorefrontTaxApproval::class)->approve(country: $country, decision: StorefrontTaxApproval::DECISION_SETUP, approvedBy: 'The tax adviser', reference: 'Tax opinion of the test world');
        }
    }
}
