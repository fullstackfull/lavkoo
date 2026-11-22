<?php

namespace App\Services\Marketing;

use App\Models\CustomerMarketingConsent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * May this marketing message be sent to this person?
 *
 * Every marketing sender asks here and nowhere else, so there is one answer in the whole application instead of one
 * per sender.
 *
 * WHILE THE FEATURE IS OFF THE ANSWER IS ALWAYS YES, and it is reached without reading the database: `enabled()` is
 * false, so no consent query is made, no address is looked up and the sender's own work is byte for byte what it was
 * before this class existed. That is what keeps Saudi behaviour where it is today, and it is what
 * MarketingConsentDormancyTest measures.
 *
 * With the feature on, the answer is the NEWEST decision for that subject, market, channel and purpose. When there is
 * no decision, the market's policy answers:
 *
 *   opt_in   no record means no send (the shipped default, for every market)
 *   opt_out  no record means send, until a withdrawal is recorded
 *
 * Which one applies is configuration, not a decision taken in this file: the owner's legal answer per market
 * (Discovery Q-15) is still open, and config/marketing.php is where it is written down when it arrives.
 */
final class MarketingConsentGate
{
    /**
     * Is the gate in force? The switch AND the table, because the code is deployed before its migration runs and a
     * missing table must mean "behave exactly as before", never an error in a mail command.
     */
    public function enabled(): bool
    {
        return (bool) Config::get('marketing.consent.enabled') && Schema::hasTable('customer_marketing_consents');
    }

    /** May a marketing message of this channel and purpose go to this customer in this market? */
    public function allowsCustomer(int $customerId, string $market, string $channel, string $purpose): bool
    {
        if (!$this->enabled()) {
            return true;
        }

        return $this->decide(
            subjectHash: MarketingSubject::forCustomer(customerId: $customerId),
            market: $market,
            channel: $channel,
            purpose: $purpose,
        );
    }

    /** The same question for a subject who has no account, such as a newsletter address or a guest's cart. */
    public function allowsEmail(string $email, string $market, string $channel, string $purpose): bool
    {
        if (!$this->enabled()) {
            return true;
        }

        return $this->decide(
            subjectHash: MarketingSubject::forEmail(email: $email),
            market: $market,
            channel: $channel,
            purpose: $purpose,
        );
    }

    /** The policy in force for a market: what an unanswered question means there. */
    public function policyFor(string $market): string
    {
        $perMarket = Config::get('marketing.consent.policies.' . mb_strtoupper($market));
        $policy = is_string($perMarket) && $perMarket !== '' ? $perMarket : Config::get('marketing.consent.default_policy');

        return $policy === MarketingConsent::POLICY_OPT_OUT ? MarketingConsent::POLICY_OPT_OUT : MarketingConsent::POLICY_OPT_IN;
    }

    private function decide(string $subjectHash, string $market, string $channel, string $purpose): bool
    {
        $latest = CustomerMarketingConsent::latestDecision(subjectHash: $subjectHash, market: $market, channel: $channel, purpose: $purpose);

        if ($latest !== null) {
            return $latest->granted();
        }

        return $this->policyFor(market: $market) === MarketingConsent::POLICY_OPT_OUT;
    }
}
