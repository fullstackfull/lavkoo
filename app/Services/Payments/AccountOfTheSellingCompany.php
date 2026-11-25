<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewayAccount;
use App\Services\Storefront\LegalEntityRegistry;
use Illuminate\Container\Attributes\Singleton;

/**
 * Which gateway account a sale's money may be taken through (D1-03, D2-07): the market's account, confirmed to belong to the COMPANY THAT
 * SELLS in that market.
 *
 * One checkout names one selling company, the order is stamped with it, and the account the money goes through has to be that company's.
 * The first two were already true -- `OneSellingCompanyPerOrder` refuses an order whose rows name two selling scopes, and
 * `OrderLegalEntitySnapshot` stamps the order with the company of the one market its lines belong to -- and the third was not asked at all:
 * `payment_gateway_accounts.legal_entity_id` is a free column, so an operator could file the Kuwait Paymob account under the Saudi company
 * and every Kuwaiti sale would be collected into that company's merchant account, with the order saying one company and the money going to
 * another. Nothing anywhere compared the two.
 *
 * **The company is read from the same place the order's stamp is read from**, `LegalEntityRegistry::forCountry()`, and deliberately not
 * worked out a second way: two readers of "who sells here" that can disagree is how an order comes to name one company while its money is
 * drawn on another, which is the exact failure this closes. The payment is started before the order exists on the digital path, so the
 * market is what both have in common -- one company per country is the approved rule and `legal_entities.country` is unique, so the market
 * the lines belong to and the market the account is registered for name the same company or they name a disagreement.
 *
 * **SILENCE IS NOT DISAGREEMENT**, the rule this platform applies to every recorded fact. A market with no registered company has nothing
 * to disagree with, and an account that names NO company is already read as the market's own company by
 * `PaymentAccountBinding::companyOf()`, so neither is refused. What is refused is two recorded values that differ.
 *
 * **It ships off** (`gcc.storefronts.payment_accounts.account_belongs_to_the_selling_company`, absent and so false), and with the switch off
 * this answers exactly what the registry answers, which is what every payment gets today, Saudi Arabia included. With it on, a market whose
 * account belongs to another company has NO account of its own as far as this reader is concerned, so the payment records no account and the
 * platform's own settings take it -- the state of every market with no account at all, which is the dormant state this mechanism ships in
 * and the one the rest of the payment chain is built for.
 *
 * **What the owner still has to decide** is whether that configuration is legitimate at all: `PaymentBelongsToOrder` records, as a design
 * note, that "a market's gateway account may belong to another country's company", and at refund time it deliberately does not compare the
 * two. If that is right, this switch stays off for good and the disagreement is a fact to report rather than a reason to stop using the
 * account. If it is wrong, the refusal belongs in the market's own readiness so that an operator finds out before the market opens rather
 * than from a customer. Either way it is not a rule to guess at inside a payment, so this takes neither side by default.
 */
#[Singleton]
final class AccountOfTheSellingCompany
{
    public function __construct(
        private readonly GatewayAccountRegistry $accounts,
        private readonly LegalEntityRegistry $companies,
    ) {
    }

    /** Whether an account that belongs to another company stops answering for a market's sales. Off as shipped. */
    public function isEnforced(): bool
    {
        return (bool) config('gcc.storefronts.payment_accounts.account_belongs_to_the_selling_company');
    }

    /**
     * The account this market's sales may be taken through, which is the account the registry holds for it unless that account belongs to
     * another company and the owner has said it may not stand in.
     */
    public function of(string $country, string $gateway, string $environment): ?PaymentGatewayAccount
    {
        $account = $this->accounts->forStorefront(country: $country, gateway: $gateway, environment: $environment);

        if ($account === null || !$this->isEnforced()) {
            return $account;
        }

        return $this->sellsForTheCompanyOfThisMarket(account: $account, country: $country) ? $account : null;
    }

    /**
     * Whose account a market's gateway account is against who sells in that market, for a report an operator reads before the market opens:
     * the two company ids when they differ, and null when there is nothing to disagree about -- no account, no registered company, or an
     * account that names no company and so is the market's own.
     *
     * Answered whatever the switch says, because the point of a report is to show a disagreement that is not being acted on yet.
     *
     * @return array{account: int, selling: int}|null
     */
    public function disagreement(string $country, string $gateway, string $environment): ?array
    {
        $account = $this->accounts->forStorefront(country: $country, gateway: $gateway, environment: $environment);

        if ($account === null || $account->legal_entity_id === null) {
            return null;
        }

        $selling = $this->companies->forCountry($country);

        if ($selling === null || (int) $account->legal_entity_id === (int) $selling->id) {
            return null;
        }

        return ['account' => (int) $account->legal_entity_id, 'selling' => (int) $selling->id];
    }

    private function sellsForTheCompanyOfThisMarket(PaymentGatewayAccount $account, string $country): bool
    {
        return $this->disagreement(country: $country, gateway: (string) $account->gateway, environment: (string) $account->environment) === null;
    }
}
