<?php

namespace App\Services\Storefront;

use App\Services\Payments\StorefrontPaymentReadiness;
use App\Services\Theme\StorefrontThemeReadiness;
use Closure;

/**
 * What a storefront needs before it may sell, asked in one place for the selling gate, the lifecycle and the report (I2-12): its required legal texts published and
 * approved, the independent review of them on record, the currency it trades in, a registered selling company where the owner requires one, and a decision about its tax. Every answer is derived from the stored texts, settings and
 * decisions, so no flag can stand in for one of them; the default storefront is excused where its own service says so.
 *
 * Three further prerequisites ask whether the market could actually serve a customer, and each of the three answers TRUE until its own
 * switch is turned on -- which they have to: what "ready to sell" includes beyond the legal texts is the owner's open decision (D1-16), so
 * a market must not be refused for the absence of a record nobody has been asked for yet. While they are off each costs one config read:
 *
 *  - {@see PAYMENT_ACCOUNT} -- a market that takes online payments has a gateway that could actually take one (D2-05, D1-16), under
 *    `gcc.storefronts.sale_gate.needs_a_payment_account`. The provider confirmations it reads are the owner's open Q-33;
 *  - {@see CATALOGUE} -- the market has a product a customer of it could buy ({@see StorefrontCatalogueReadiness}), under
 *    `gcc.storefronts.sale_gate.needs_a_catalogue`. A market with published legal texts, a currency and a working gateway still sells
 *    nothing when its catalogue is empty, and only a customer reports that;
 *  - {@see THEME} -- its pages render ({@see StorefrontThemeReadiness}), under `gcc.storefronts.sale_gate.needs_a_theme`.
 */
final class StorefrontPreflight
{
    public const LEGAL_TEXTS = 'legal_texts';

    public const LEGAL_REVIEW = 'legal_review';

    public const CURRENCY = 'currency';

    public const LEGAL_ENTITY = 'legal_entity';
    public const TAX = 'tax';

    public const PAYMENT_ACCOUNT = 'payment_account';

    public const CATALOGUE = 'catalogue';

    public const THEME = 'theme';

    public function __construct(
        private readonly StorefrontLegalReadiness $legalReadiness,
        private readonly StorefrontLegalReview $legalReview,
        private readonly StorefrontCurrency $currency,
        private readonly LegalEntityRegistry $legalEntities,
        private readonly StorefrontTaxApproval $taxApproval,
        private readonly StorefrontPaymentReadiness $paymentReadiness,
        private readonly StorefrontCatalogueReadiness $catalogueReadiness,
        private readonly StorefrontThemeReadiness $themeReadiness,
    ) {
    }

    /**
     * Every prerequisite this preflight asks about, in the order it asks them, so that a screen or a report cannot show a list that is
     * missing one.
     *
     * It exists because one already was missing: `gcc:storefront:state status` printed its lines from a list of its own, which named five
     * of the six prerequisites, so a market blocked on its payment account was reported as blocked with every line it printed reading
     * "ok". A reader that iterates this cannot do that again.
     *
     * @return list<string>
     */
    public function prerequisites(): array
    {
        return array_keys($this->checks(country: '', currencyCode: ''));
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
            // Last, because these three are the ones that can be switched off, and `isMet()` stops at the first that fails: while they are
            // off each costs one config read and asks the database nothing.
            self::PAYMENT_ACCOUNT => fn (): bool => $this->paymentReadiness->isReady(country: $country, currencyCode: $currencyCode),
            self::CATALOGUE => fn (): bool => $this->catalogueReadiness->isReady(country: $country),
            self::THEME => fn (): bool => $this->themeReadiness->isReady(country: $country),
        ];
    }
}
