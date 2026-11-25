<?php

namespace App\Services\Storefront;

use App\Services\Payments\StorefrontPaymentReadiness;
use Closure;

/**
 * What a storefront needs before it may sell, asked in one place for the selling gate, the lifecycle and the report (I2-12): its required legal texts published and
 * approved, the independent review of them on record, the currency it trades in, a registered selling company where the owner requires one, and a decision about its tax. Every answer is derived from the stored texts, settings and
 * decisions, so no flag can stand in for one of them; the default storefront is excused where its own service says so.
 *
 * A fifth prerequisite, {@see PAYMENT_ACCOUNT}, asks whether a market that takes online payments has a gateway that could actually take one
 * (D2-05, D1-16). It answers true until `gcc.storefronts.sale_gate.needs_a_payment_account` is switched on, which it has to: what "ready to
 * sell" includes beyond the legal texts is the owner's open decision, and the provider confirmations the check reads are the owner's open
 * Q-33, so a market must not be refused for the absence of a record nobody has been asked for yet.
 */
final class StorefrontPreflight
{
    public const LEGAL_TEXTS = 'legal_texts';

    public const LEGAL_REVIEW = 'legal_review';

    public const CURRENCY = 'currency';

    public const LEGAL_ENTITY = 'legal_entity';
    public const TAX = 'tax';

    public const PAYMENT_ACCOUNT = 'payment_account';

    public function __construct(
        private readonly StorefrontLegalReadiness $legalReadiness,
        private readonly StorefrontLegalReview $legalReview,
        private readonly StorefrontCurrency $currency,
        private readonly LegalEntityRegistry $legalEntities,
        private readonly StorefrontTaxApproval $taxApproval,
        private readonly StorefrontPaymentReadiness $paymentReadiness,
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
            // Last, because it is the only one of the five that can be switched off, and `isMet()` stops at the first that fails: while it is
            // off it costs one config read and nothing else.
            self::PAYMENT_ACCOUNT => fn (): bool => $this->paymentReadiness->isReady(country: $country, currencyCode: $currencyCode),
        ];
    }
}
