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
            // this attempt may not send either.
            $sendMayHaveLeft = $row->mayHaveReachedTheChannel();
            $token = (string) Str::uuid();
            $row->forceFill([
                'state' => ChannelShipmentSend::CLAIMED, 'claim_token' => $token, 'claimed_at' => now(), 'attempts' => $row->attempts + 1,
            ])->save();

            return ShipmentSendPermission::go($token, $sendMayHaveLeft, $sendMayHaveLeft ? $row->reason_code : null);
        }, 3);
    }

    /**
     * A send has gone out, or may have: from here the reference is never sent again without a person.
     *
     * The claim is KEPT, because the attempt is not over -- it still has to read the shipment back, and that read needs the
     * token to settle the row afterwards. A `sent` row is not a live claim, so a process that dies here does not hold the
     * reference: the next attempt takes it over and finds that a send may already have left.
     */
    public function sent(string $token): void
    {
        $this->settle($token, ChannelShipmentSend::SENT, null, ['sent_at' => now()], release: false);
    }

    /** The channel reported the shipment. Terminal. */
    public function confirmed(string $token): void
    {
        $this->settle($token, ChannelShipmentSend::CONFIRMED, null, ['confirmed_at' => now()]);
    }

    /**
     * Nothing left, and that is PROVEN: a definitive refusal from the channel, a missing field, a switch that stopped it. The
     * reference may be claimed and sent again.
     */
    public function nothingWasSent(string $token, ?string $reason = null): void
    {
        $this->settle($token, ChannelShipmentSend::NOT_SENT, $reason);
    }

    /** A send may have left and the channel does not show the shipment. Automation stops here; reads may still resolve it. */
    public function needsReview(string $token, string $reason): void
    {
        $this->settle($token, ChannelShipmentSend::NEEDS_REVIEW, $reason);
    }

    /** What this system knows about one shipment reference, for a screen or a report. */
    public function of(SalesChannelAccount $account, string $reference): ?ChannelShipmentSend
    {
        return ChannelShipmentSend::where('account_id', $account->id)->where('reference', $reference)->first();
    }

    /** @param array<string, mixed> $extra */
    private function settle(string $token, string $state, ?string $reason, array $extra = [], bool $release = true): void
    {
        // Keyed on the token, so an attempt whose lease expired and whose reference another process has since claimed cannot
        // write over that process's work on its way out.
        ChannelShipmentSend::where('claim_token', $token)->update(array_merge([
            'state' => $state, 'reason_code' => $reason === null ? null : mb_substr($reason, 0, 80), 'updated_at' => now(),
        ], $release ? ['claim_token' => null, 'claimed_at' => null] : [], $extra));
    }
}
