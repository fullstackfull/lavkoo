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
 * With the feature on, the answer is the NEWEST decision for that subject, market, channel and purpose. ONE PERSON
 * MAY BE KNOWN BY MORE THAN ONE SUBJECT — a registered customer who checks out as a guest is a customer subject and an
 * address subject — so `allowsPerson` asks about every subject the sender knows, and a recorded NO on any of them
 * wins. Asking only one of them let a withdrawal recorded through an unsubscribe link be bypassed by the same person's
 * next cart on the other side of the login, which two independent reviews measured on both engines. When there is
 * no decision on any subject, the market's policy answers:
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
     * Has the migration run? Remembered for the life of this instance: a request does not create the table halfway
     * through, and Schema::hasTable is a real information_schema query that was otherwise repeated for every customer
     * of a bulk send.
     */
    private ?bool $tableExists = null;

    /**
     * Is the gate in force? The switch AND the table, because the code is deployed before its migration runs and a
     * missing table must mean "behave exactly as before", never an error in a mail command.
     */
    public function enabled(): bool
    {
        return (bool) Config::get('marketing.consent.enabled') && $this->recordable();
    }

    /**
     * Can a decision be recorded at all, whatever the switch says?
     *
     * The unsubscribe route asks this rather than `enabled()`, and that is a DELIBERATE EXCEPTION to "the switch off
     * means nothing happens": a link that is already in somebody's inbox must still be able to remove consent after an
     * operator switches the feature off, because the alternative is a person who asked to be left alone and no record
     * of it. What the exception may never do is raise, which is why the table is checked here at all — with the table
     * absent the route answers its neutral page and writes nothing (found by an independent review, which measured a
     * QueryException out of the public route on both engines).
     */
    public function recordable(): bool
    {
        return $this->tableExists ??= Schema::hasTable('customer_marketing_consents');
    }

    /** May a marketing message of this channel and purpose go to this customer in this market? */
    public function allowsCustomer(int $customerId, string $market, string $channel, string $purpose): bool
    {
        return $this->allowsPerson(customerId: $customerId, email: null, market: $market, channel: $channel, purpose: $purpose);
    }

    /** The same question for a subject who has no account, such as a newsletter address or a guest's cart. */
    public function allowsEmail(string $email, string $market, string $channel, string $purpose): bool
    {
        return $this->allowsPerson(customerId: null, email: $email, market: $market, channel: $channel, purpose: $purpose);
    }

    /**
     * The question as a sender should ask it: about a PERSON, by every name the sender knows them under.
     *
     * A sender that knows both an account and an address must pass both. A withdrawal recorded under either one stops
     * the send, so unsubscribing from a reminder sent to an account also stops the next reminder for the same address
     * typed at a guest checkout, and the other way round.
     */
    public function allowsPerson(?int $customerId, ?string $email, string $market, string $channel, string $purpose): bool
    {
        if (!$this->enabled()) {
            return true;
        }

        return $this->decide(
            subjectHashes: $this->subjectHashesFor(customerId: $customerId, email: $email),
            market: $market,
            channel: $channel,
            purpose: $purpose,
        );
    }

    /**
     * Every name this person is known by, in one place so the two questions above cannot ask about different people.
     *
     * @return array<int, string>
     */
    private function subjectHashesFor(?int $customerId, ?string $email): array
    {
        $subjectHashes = [];

        if ($customerId) {
            $subjectHashes[] = MarketingSubject::forCustomer(customerId: $customerId);
        }

        if ($email !== null && $email !== '') {
            $subjectHashes[] = MarketingSubject::forEmail(email: $email);
        }

        return array_values(array_unique($subjectHashes));
    }

    /**
     * Has this person already said this, so that a form writing it again would only stack a duplicate row?
     *
     * Not the same question as allowsPerson: that one answers the market's policy when nobody has said anything, so in
     * an opt_out market it says yes to somebody who has never answered. This one looks only at what is written, and it
     * counts an unverified claim, so typing an address into the newsletter box twice records once. A WITHDRAWAL does
     * not count: after one, saying yes again is a new answer and has to be recorded.
     */
    public function choiceAlreadyRecorded(?int $customerId, ?string $email, string $market, string $channel, string $purpose): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        foreach ($this->subjectHashesFor(customerId: $customerId, email: $email) as $subjectHash) {
            $latest = CustomerMarketingConsent::latestRecord(subjectHash: $subjectHash, market: $market, channel: $channel, purpose: $purpose);

            if ($latest !== null && $latest->decision !== CustomerMarketingConsent::DECISION_WITHDRAWN) {
                return true;
            }
        }

        return false;
    }

    /** The policy in force for a market: what an unanswered question means there. */
    public function policyFor(string $market): string
    {
        $perMarket = Config::get('marketing.consent.policies.' . MarketingConsent::market($market));
        $policy = is_string($perMarket) && $perMarket !== '' ? $perMarket : Config::get('marketing.consent.default_policy');

        return $policy === MarketingConsent::POLICY_OPT_OUT ? MarketingConsent::POLICY_OPT_OUT : MarketingConsent::POLICY_OPT_IN;
    }

    /**
     * A recorded NO wins; otherwise a recorded YES allows; otherwise the market's policy answers.
     *
     * In that order on purpose. Requiring every subject to say yes would have stopped reminding a customer who agreed
     * by account but whose address has no row of its own, which is most of them.
     *
     * @param array<int, string> $subjectHashes
     */
    private function decide(array $subjectHashes, string $market, string $channel, string $purpose): bool
    {
        $granted = false;

        foreach ($subjectHashes as $subjectHash) {
            $latest = CustomerMarketingConsent::latestDecision(subjectHash: $subjectHash, market: $market, channel: $channel, purpose: $purpose);

            if ($latest === null) {
                continue;
            }

            if (!$latest->granted()) {
                return false;
            }

            $granted = true;
        }

        return $granted || $this->policyFor(market: $market) === MarketingConsent::POLICY_OPT_OUT;
    }
}
