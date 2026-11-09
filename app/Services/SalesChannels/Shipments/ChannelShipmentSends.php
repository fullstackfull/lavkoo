<?php

namespace App\Services\SalesChannels\Shipments;

use App\Models\ChannelShipmentSend;
use App\Models\SalesChannelAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who owns an attempt to tell a channel that goods shipped, and whether a send for that shipment ever left this system.
 *
 * **WHY THIS IS NOT THE CHANNEL'S JOB.** The shipment reference is derived from the order and its exact units, so a retry of the same work asks about the same shipment. That
 * makes the question stable; it does not make a repeat harmless. noon publishes no idempotency rule for `integration_shipment_nr` — it only requires it to be unique — so
 * nothing says what a second send does. And asking the channel first does not close it either: two processes can both be told "no such shipment" in the same instant and both
 * send. One shipment of goods, two vans, and cancelling a noon shipment cancels every item in it.
 *
 * So the ownership lives here, in one unique row per `(account, reference)`, and the engine decides the winner rather than the order in which two reads happen to land. The
 * row also remembers something the channel cannot be asked for: whether a send ever LEFT. After an answer nobody could read, that memory is the difference between "ask
 * again" and "send again".
 *
 * **THE ONE RULE THAT MATTERS.** A send is repeated automatically only when there is PROOF the channel did not process the first one — a definitive refusal, or a switch that
 * stopped it before anything left. A 5xx, a timeout, a reset or an unreadable answer are not proof of anything: the row goes to `sent` and from then on this system will
 * ask, and only ask, until the channel shows the shipment or a person settles it.
 *
 * It is channel-agnostic on purpose. noon is the first caller because noon is where the defect was found; Amazon and Trendyol confirm shipments through the same contract and
 * can take the same claim without a second mechanism.
 */
class ChannelShipmentSends
{
    /**
     * Takes ownership of one shipment reference, or says who else has it.
     *
     * The row is created outside the locking read and the duplicate-key race is caught, because a locking read of a row that
     * does not exist yet locks the gap, and two first attempts on different references then deadlock on it as they insert.
     * `ChannelOrderDispatcher::claim()` does the same for the same reason.
     */
    public function claim(SalesChannelAccount $account, string $reference, string $externalOrderId): ShipmentSendPermission
    {
        if (!ChannelShipmentSend::where('account_id', $account->id)->where('reference', $reference)->exists()) {
            try {
                ChannelShipmentSend::create([
                    'account_id' => $account->id, 'channel' => $account->channel, 'reference' => $reference,
                    'external_order_id' => $externalOrderId, 'state' => ChannelShipmentSend::NOT_SENT, 'attempts' => 0,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another process made the row first; the locking read below finds it.
            }
        }

        return DB::transaction(function () use ($account, $reference): ShipmentSendPermission {
            $row = ChannelShipmentSend::where('account_id', $account->id)->where('reference', $reference)->lockForUpdate()->firstOrFail();
            if ($row->state === ChannelShipmentSend::CONFIRMED) {
                return ShipmentSendPermission::confirmed();
            }
            if ($row->claimIsLive()) {
                return ShipmentSendPermission::busy();
            }

            // A claim taken over from a dead process keeps whatever that process had established: if its send may have left,
            // this attempt may not send either. **THE STATE IS NOT TOUCHED HERE.** Taking ownership is not news about the goods,
            // and a claim that overwrote the state is exactly how a crashed worker's send came to be forgotten.
            $sendMayHaveLeft = $row->mayHaveReachedTheChannel();
            $token = (string) Str::uuid();
            $row->forceFill([
                'claim_token' => $token, 'claimed_at' => now(), 'attempts' => $row->attempts + 1,
            ])->save();

            return ShipmentSendPermission::go($token, $sendMayHaveLeft, $sendMayHaveLeft ? $row->reason_code : null);
        }, 3);
    }

    /**
     * **CALLED BEFORE THE REQUEST LEAVES, never after it returns.**
     *
     * This is the fence. From the instant it commits, the row says a create may be at the channel — so a process that dies at
     * ANY later point, including between the socket write and the next PHP statement, leaves a row that forbids a second send.
     * Marking it afterwards left a window of exactly that shape: the goods were on their way and the database did not know.
     *
     * It is deliberately pessimistic. A request that turns out to have been refused before processing is corrected by
     * `nothingWasSent()` on PROOF; being wrong in this direction costs a person a look, and being wrong in the other costs a
     * second van.
     *
     * The claim is KEPT: the attempt is not over, and the read-back needs the token to settle the row.
     */
    public function aboutToSend(string $token, ?string $reason = null): bool
    {
        // **A COMPARE-AND-SET THAT FAILS CLOSED, and the return value is not optional to check.**
        //
        // It affects zero rows when this attempt no longer owns the reference: either another worker has taken it over and the
        // token has moved on, or this worker's own lease has lapsed while it was paused -- garbage collection, a starved CPU, a
        // slow existence check. A worker in that position cannot fence anything, so it must not send anything: its create would
        // leave the host with NO durable record that it had, which is the whole failure this class exists to prevent.
        //
        // The lease is part of the condition, not just the token. A worker whose lease has run out cannot promise exclusivity
        // for the length of a network call even if nobody has taken the row yet, so it steps back rather than racing.
        //
        // And the lease is RENEWED here, so the fence covers the call that follows it rather than expiring halfway through.
        $lease = now()->subMinutes(max(1, (int) config('sales_channels.shipment_sends.claim_minutes', 10)));
        $affected = ChannelShipmentSend::where('claim_token', $token)
            ->where('claimed_at', '>', $lease)
            ->update([
                'state' => ChannelShipmentSend::MAY_HAVE_SENT,
                'reason_code' => $reason === null ? null : mb_substr($reason, 0, 80),
                'sent_at' => now(),
                'claimed_at' => now(),
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * The attempt is over and the outcome is still unknown: the claim goes back, and the row keeps saying a send may have left.
     *
     * Releasing ownership and clearing the send state are two different acts, and only the first belongs here. The next attempt
     * may ASK the channel; it may not send.
     */
    public function releaseAfterUncertainSend(string $token, string $reason): bool
    {
        return $this->settle($token, ChannelShipmentSend::MAY_HAVE_SENT, $reason);
    }

    /** The channel reported the shipment. Terminal. */
    public function confirmed(string $token): bool
    {
        return $this->settle($token, ChannelShipmentSend::CONFIRMED, null, ['confirmed_at' => now()]);
    }

    /**
     * Nothing left, and that is PROVEN: a definitive refusal, a rate limit, a credential refusal, a request that never left the
     * host, a rehearsal, or a gate that stopped the call before it was made. The reference may be claimed and sent again.
     *
     * **It is the only road back from `may_have_sent`**, and it takes proof rather than an absence of bad news. `ChannelResponse`
     * is what supplies that proof: it documents a 401, a 403, a 429 and any other 4xx as refused BEFORE processing, and a 5xx or
     * a lost answer as cases where the channel may have acted. Only the first group comes here.
     */
    public function nothingWasSent(string $token, ?string $reason = null): bool
    {
        return $this->settle($token, ChannelShipmentSend::NOT_SENT, $reason, ['sent_at' => null]);
    }

    /** A send may have left and the channel does not show the shipment. Automation stops here; reads may still resolve it. */
    public function needsReview(string $token, string $reason): bool
    {
        return $this->settle($token, ChannelShipmentSend::NEEDS_REVIEW, $reason);
    }

    /** What this system knows about one shipment reference, for a screen or a report. */
    public function of(SalesChannelAccount $account, string $reference): ?ChannelShipmentSend
    {
        return ChannelShipmentSend::where('account_id', $account->id)->where('reference', $reference)->first();
    }

    /**
     * @param array<string, mixed> $extra
     * @return bool whether it landed: false means this attempt no longer owns the row, and the caller has not changed anything
     */
    private function settle(string $token, string $state, ?string $reason, array $extra = [], bool $release = true): bool
    {
        // **KEYED ON THE TOKEN**, so a worker whose lease expired -- a slow request that was overtaken, a process that came back
        // from the dead -- cannot write over the row the new owner holds. Its late "nothing was sent" is a no-op, which is the
        // only safe outcome: it does not know what happened after it stopped looking.
        return ChannelShipmentSend::where('claim_token', $token)->update(array_merge([
            'state' => $state, 'reason_code' => $reason === null ? null : mb_substr($reason, 0, 80), 'updated_at' => now(),
        ], $release ? ['claim_token' => null, 'claimed_at' => null] : [], $extra)) === 1;
    }
}
