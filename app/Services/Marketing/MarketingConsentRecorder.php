<?php

namespace App\Services\Marketing;

use App\Models\CustomerMarketingConsent;
use Illuminate\Support\Facades\DB;

/**
 * Writes what a person answered. The only class that writes a consent row.
 *
 * Every answer is an INSERT. Granting after a withdrawal writes a grant; withdrawing after a grant writes a
 * withdrawal; the grant row is left exactly as it was. Nothing here updates or deletes, and the model refuses both
 * anyway, so a mistake in a future caller cannot quietly rewrite history.
 *
 * WITHDRAWING IS IDEMPOTENT. A person who opens the unsubscribe link in an e-mail twice — or whose mail client
 * prefetches it and then they click it — has said one thing once, so the second call writes nothing and raises
 * nothing. That is read-then-insert inside a transaction, which is enough for a person clicking twice; two requests
 * arriving in the same instant can still write two withdrawal rows, and the answer in force is the same either way.
 *
 * EVERY ROW IS SCOPED, and no caller has to remember to do it. The storefront the answer was given on and the company
 * that sells there are filled by {@see MarketingConsentScope} whenever the caller names neither, so a form, a sender or
 * a future writer cannot file a decision under nobody by forgetting an argument — which is what every caller did, and
 * why every row of every head before this carried two nulls (A2-13, M-556). A caller that knows better may still name
 * both; a WITHDRAWAL names the grant's own pair, because where the grant was given is a recorded fact and the click
 * that withdraws it is a guess at one.
 */
final class MarketingConsentRecorder
{
    public function grantForCustomer(
        int $customerId,
        string $market,
        string $channel,
        string $purpose,
        string $source,
        ?string $textVersion = null,
        ?string $textHash = null,
        ?string $storefront = null,
        ?int $legalEntityId = null,
    ): CustomerMarketingConsent {
        return $this->record(
            decision: CustomerMarketingConsent::DECISION_GRANTED,
            customerId: $customerId,
            subjectHash: MarketingSubject::forCustomer(customerId: $customerId),
            market: $market,
            channel: $channel,
            purpose: $purpose,
            source: $source,
            textVersion: $textVersion,
            textHash: $textHash,
            storefront: $storefront,
            legalEntityId: $legalEntityId,
        );
    }

    public function grantForEmail(
        string $email,
        string $market,
        string $channel,
        string $purpose,
        string $source,
        ?string $textVersion = null,
        ?string $textHash = null,
        ?string $storefront = null,
        ?int $legalEntityId = null,
    ): CustomerMarketingConsent {
        return $this->record(
            decision: CustomerMarketingConsent::DECISION_GRANTED,
            customerId: null,
            subjectHash: MarketingSubject::forEmail(email: $email),
            market: $market,
            channel: $channel,
            purpose: $purpose,
            source: $source,
            textVersion: $textVersion,
            textHash: $textHash,
            storefront: $storefront,
            legalEntityId: $legalEntityId,
        );
    }

    /**
     * Records that an address was typed into a form by somebody who was not signed in.
     *
     * NOT A GRANT. The newsletter box takes an address and asks nothing of whoever typed it, so a visitor can enter a
     * stranger's address; an independent review drove that through the real route and watched the gate's answer turn
     * from refuse to allow. A legal record that any passer-by can create for somebody else is not evidence of
     * consent, so what is written is a claim that the gate does not read as permission. Confirming a claim (a
     * double-opt-in e-mail) is a sender and a screen this branch does not add — see the PR's open list.
     */
    public function claimForEmail(
        string $email,
        string $market,
        string $channel,
        string $purpose,
        string $source,
        ?string $textVersion = null,
        ?string $textHash = null,
    ): CustomerMarketingConsent {
        return $this->record(
            decision: CustomerMarketingConsent::DECISION_PENDING,
            customerId: null,
            subjectHash: MarketingSubject::forEmail(email: $email),
            market: $market,
            channel: $channel,
            purpose: $purpose,
            source: $source,
            textVersion: $textVersion,
            textHash: $textHash,
        );
    }

    /**
     * Records that this subject no longer wants this channel and purpose in this market.
     *
     * Returns the row in force afterwards: the withdrawal this call wrote, or the withdrawal that was already there.
     */
    public function withdraw(
        string $subjectHash,
        string $market,
        string $channel,
        string $purpose,
        string $source,
        ?int $customerId = null,
        ?string $storefront = null,
        ?int $legalEntityId = null,
    ): CustomerMarketingConsent {
        return DB::transaction(function () use ($subjectHash, $market, $channel, $purpose, $source, $customerId, $storefront, $legalEntityId): CustomerMarketingConsent {
            $latest = CustomerMarketingConsent::latestDecision(subjectHash: $subjectHash, market: $market, channel: $channel, purpose: $purpose);

            if ($latest !== null && !$latest->granted()) {
                return $latest;
            }

            return $this->record(
                decision: CustomerMarketingConsent::DECISION_WITHDRAWN,
                customerId: $customerId ?? $latest?->customer_id,
                subjectHash: $subjectHash,
                market: $market,
                channel: $channel,
                purpose: $purpose,
                source: $source,
                textVersion: $latest?->text_version,
                textHash: $latest?->text_hash,
                storefront: $storefront ?? $latest?->storefront,
                legalEntityId: $legalEntityId ?? $latest?->legal_entity_id,
            );
        });
    }

    private function record(
        string $decision,
        ?int $customerId,
        string $subjectHash,
        string $market,
        string $channel,
        string $purpose,
        string $source,
        ?string $textVersion,
        ?string $textHash,
        ?string $storefront = null,
        ?int $legalEntityId = null,
    ): CustomerMarketingConsent {
        $market = MarketingConsent::market($market);
        $storefront ??= MarketingConsentScope::storefront();

        return CustomerMarketingConsent::create([
            'customer_id' => $customerId,
            'subject_hash' => $subjectHash,
            'market' => $market,
            'storefront' => $storefront,
            'legal_entity_id' => $legalEntityId ?? MarketingConsentScope::sellingCompanyId(country: $storefront ?? $market),
            'channel' => $channel,
            'purpose' => $purpose,
            'decision' => $decision,
            'text_version' => $textVersion,
            'text_hash' => $textHash,
            'source' => $source,
            'recorded_at' => now(),
        ]);
    }
}
