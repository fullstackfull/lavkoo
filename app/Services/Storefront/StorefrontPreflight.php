<?php

namespace App\Services\Storefront;

use Closure;

/**
 * What a storefront needs before it may sell, asked in one place for the selling gate, the lifecycle and the report (I2-12): its required legal texts published and
 * approved, the independent review of them on record, the currency it trades in, a registered selling company where the owner requires one, and a decision about its tax. Every answer is derived from the stored texts, settings and
 * decisions, so no flag can stand in for one of them; the default storefront is excused where its own service says so.
 */
final class StorefrontPreflight
{
    public const LEGAL_TEXTS = 'legal_texts';

    public const LEGAL_REVIEW = 'legal_review';

    public const CURRENCY = 'currency';

    public const LEGAL_ENTITY = 'legal_entity';
    public const TAX = 'tax';

    public function __construct(
        private readonly StorefrontLegalReadiness $legalReadiness,
        private readonly StorefrontLegalReview $legalReview,
        private readonly StorefrontCurrency $currency,
        private readonly LegalEntityRegistry $legalEntities,
        private readonly StorefrontTaxApproval $taxApproval,
    ) {
    }

    /** Whether every prerequisite is met. It stops at the first one that is not, because the gate asks it on every sale. */
    public function isMet(string $country, string $currencyCode): bool
    {
        foreach ($this->checks(country: $country, currencyCode: $currencyCode) as $isMet) {
            if (!$isMet()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string> the prerequisites that are not met, in the order they are asked
     */
    public function unmet(string $country, string $currencyCode): array
    {
        return array_keys(array_filter(
            $this->checks(country: $country, currencyCode: $currencyCode),
            static fn (Closure $isMet): bool => !$isMet(),
        ));
    }

    /**
     * @return array<string, Closure(): bool>
     */
    private function checks(string $country, string $currencyCode): array
    {
        return [
            self::LEGAL_TEXTS => fn (): bool => $this->legalReadiness->isReady($country),
            self::LEGAL_REVIEW => fn (): bool => $this->legalReview->isRecorded($country),
            self::CURRENCY => fn (): bool => $this->currency->isReady(country: $country, currencyCode: $currencyCode),
            self::LEGAL_ENTITY => fn (): bool => $this->legalEntities->isReadyToSell($country),
            self::TAX => fn (): bool => $this->taxApproval->isApproved($country),
        ];
    }
}
