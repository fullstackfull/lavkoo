<?php

namespace App\Services\SalesChannels\Dispatch;

use App\Enums\SalesChannels\DispatchState;
use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderMapping;
use App\Services\AuditLogger;
use App\Services\Fulfillment\FulfillmentHooks;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Contracts\ReadsRecipient;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Exceptions\RecipientUnavailable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns one channel order into a provider shipment, once.
 *
 *  1. the gate (no buyer detail read, nothing written when it is closed);
 *  2. CLAIM the attempt in the database, before anything is read from the channel: one row per order, claimed under a lock, so that two runs, two servers or a double click can never both go on;
 *  3. read the recipient from the channel (gated like no other read), outside any transaction;
 *  4. in ONE transaction make the internal order (ChannelOrderMaterializer), or write nothing;
 *  5. release the claim with a reason (a code) and the time of the next attempt when something was missing.
 *
 * The provider is never called from here. From step 4 on the existing fulfilment owns the shipment: it claims before it sends, never retries a write inside the client, and an answer it cannot
 * read becomes a reconciliation by our reference, never a second send.
 */
class ChannelOrderDispatcher
{
    public function __construct(
        private readonly DispatchGate $gate,
        private readonly ChannelConnectorRegistry $connectors,
        private readonly ChannelOrderMaterializer $materializer,
        private readonly FulfillmentHooks $hooks,
        private readonly AuditLogger $audit,
    ) {
    }

    public function dispatch(ExternalOrderMapping $mapping, string $trigger = 'manual', ?int $adminId = null): DispatchOutcome
    {
        if ($mapping->order_id !== null) {
            return DispatchOutcome::alreadyDone((int) $mapping->order_id);
        }

        $verdict = $this->gate->evaluate($mapping);
        if (!$verdict->open()) {
            return DispatchOutcome::blocked($verdict->blockers[0], $verdict->blockers);
        }

        $claimed = $this->claim($mapping, (string) $verdict->provider, $trigger, $adminId);
        if ($claimed instanceof DispatchOutcome) {
            return $claimed;
        }

        $connector = $this->connectors->for($mapping->account);
        if (!$connector instanceof ReadsRecipient) {
            return $this->release($mapping, $claimed, 'channel_cannot_give_the_recipient', retry: false);
        }

        try {
            $recipient = $connector->recipient($mapping->account, $mapping);
        } catch (RecipientUnavailable $exception) {
            return $this->release($mapping, $claimed, $exception->reason . ($exception->problems === [] ? '' : ':' . implode(',', $exception->problems)), retry: $exception->reason !== RecipientUnavailable::NOT_SHIPPABLE_BY_US);
        } catch (ChannelUnavailableException $exception) {
            return $this->release($mapping, $claimed, 'channel_' . $exception->reason, retry: false);
        }

        try {
            $orderId = $this->materializer->materialize($mapping, (string) $verdict->provider, $recipient, $claimed);
        } catch (DispatchRefused $refused) {
            return $this->release($mapping, $claimed, $refused->reason, retry: false);
        }

        $this->hooks->ordersPlaced([$orderId]);

        return DispatchOutcome::materialized($orderId, (string) $verdict->provider);
    }

    /** @return string|DispatchOutcome the claim token, or why the attempt does not go on */
    private function claim(ExternalOrderMapping $mapping, string $provider, string $trigger, ?int $adminId): string|DispatchOutcome
    {
        return DB::transaction(function () use ($mapping, $provider, $trigger, $adminId): string|DispatchOutcome {
            $locked = ExternalOrderMapping::whereKey($mapping->id)->lockForUpdate()->firstOrFail();
            if ($locked->order_id !== null) {
                return DispatchOutcome::alreadyDone((int) $locked->order_id);
            }

            $row = ChannelOrderDispatch::where('mapping_id', $locked->id)->lockForUpdate()->first();
            if ($row === null) {
                try {
                    $row = ChannelOrderDispatch::create(['mapping_id' => $locked->id, 'account_id' => $locked->account_id, 'state' => DispatchState::Blocked->value, 'attempts' => 0]);
                } catch (UniqueConstraintViolationException) {
                    $row = ChannelOrderDispatch::where('mapping_id', $locked->id)->lockForUpdate()->firstOrFail();
                }
            }
            if ($row->internal_order_id !== null) {
                return DispatchOutcome::alreadyDone((int) $row->internal_order_id);
            }
            if ($row->claimIsLive()) {
                return DispatchOutcome::busy();
            }
            // A person asking overrides the waiting time and the attempt cap; the scheduled run honours both.
            if ($trigger === 'auto') {
                if ($row->next_attempt_at !== null && $row->next_attempt_at->isFuture()) {
                    return DispatchOutcome::waiting((string) $row->reason_code);
                }
                if ($row->attempts >= (int) config('sales_channels.dispatch.max_attempts', 5)) {
                    return DispatchOutcome::blocked('attempts_exhausted');
                }
            }

            $token = (string) Str::uuid();
            $row->forceFill([
                'state' => DispatchState::Claimed->value, 'provider' => $provider, 'claim_token' => $token, 'claimed_at' => now(), 'attempts' => $row->attempts + 1,
                'last_attempt_at' => now(), 'next_attempt_at' => null, 'reason_code' => null, 'trigger' => $trigger, 'requested_by' => $adminId,
            ])->save();

            return $token;
        }, 3);
    }

    private function release(ExternalOrderMapping $mapping, string $token, string $reason, bool $retry): DispatchOutcome
    {
        $row = ChannelOrderDispatch::where('mapping_id', $mapping->id)->where('claim_token', $token)->first();
        if ($row === null) {
            return DispatchOutcome::busy();
        }

        $delays = array_values((array) config('sales_channels.dispatch.retry_minutes', [5, 15, 60]));
        $waitsMinutes = $retry && $delays !== [] && $row->attempts < (int) config('sales_channels.dispatch.max_attempts', 5) ? $delays[min($row->attempts - 1, count($delays) - 1)] : null;
        ChannelOrderDispatch::where('id', $row->id)->where('claim_token', $token)->update([
            'state' => DispatchState::Blocked->value, 'reason_code' => mb_substr($reason, 0, 80), 'claim_token' => null, 'claimed_at' => null,
            'next_attempt_at' => $waitsMinutes === null ? null : now()->addMinutes($waitsMinutes),
        ]);
        $this->audit->record(action: 'sales_channel.dispatch_blocked', subject: $mapping, context: ['mapping_id' => $mapping->id, 'reason' => mb_substr($reason, 0, 80), 'attempt' => $row->attempts]);

        return DispatchOutcome::blocked($reason);
    }
}
