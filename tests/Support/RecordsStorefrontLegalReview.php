<?php

namespace Tests\Support;

use App\Services\Storefront\StorefrontLegalReview;

/**
 * The independent legal review a storefront needs before it may sell (I2-07), recorded the way the command records it: through StorefrontLegalReview, with its audit line.
 * A test of the checkout, of a payment or of the list of storefronts calls it so that the sale gate is not what is under test; a test of the gate itself may leave it out.
 * What it wrote is taken away when the test ends, because a real server keeps the tables and the rows of one test class for the next.
 */
trait RecordsStorefrontLegalReview
{
    use ActivatesStorefront;
    use BuildsStorefrontDecisionTables;

    /**
     * For a test about something else than the legal texts and their review: every storefront keeps the documents it has always had, so the gate and the readiness report ask for
     * neither, and every storefront is active, so its lifecycle is not what the test is about either.
     */
    protected function leaveTheLegalTextsOutOfThisTest(): void
    {
        config(['gcc.storefronts.legal.grandfathered' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM']]);
        $this->activateStorefrontFor('SA', 'AE', 'KW', 'QA', 'BH', 'OM');
    }

    protected function recordLegalReviewFor(string ...$countries): void
    {
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_legal_review_');

        foreach ($countries as $country) {
            app(StorefrontLegalReview::class)->record(country: $country, reviewedBy: 'The legal counsel of ' . $country, reference: 'Legal opinion of the test world', reviewedOn: now()->subDay()->toDateString());
        }
    }
}
