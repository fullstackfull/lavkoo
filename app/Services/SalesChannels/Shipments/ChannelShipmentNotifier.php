<?php

namespace App\Services\SalesChannels\Shipments;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Contracts\ConfirmsShipments;
use App\Services\SalesChannels\Contracts\ListsOrderParcels;
use App\Services\SalesChannels\Data\OrderParcel;
use App\Services\SalesChannels\Data\ShipmentConfirmation;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Tells the channel that an order shipped — ONE CONFIRMATION PER PARCEL, each naming only the units that are really in it.
 *
 * Per parcel, because an order is not always one box. `FulfillmentRouter` groups a customer's items BY PROVIDER, so an order with some items at Torod Point and some at M5AZN
 * leaves as two parcels with two tracking numbers. Only one of them is mirrored onto the channel order's own `tracking_number`. This used to send EVERY active line of the
 * order under that one number, which told the marketplace something false about every unit in the other box, and showed the buyer a tracking number that would never move for
 * half of their order. `ListsOrderParcels` answers what each box holds; this sends one confirmation for each.
 *
 * A WRITE to the channel: it goes through the transport's switches (environment, account, the Fulfilment Dispatch capability and its recorded approval), and a dry-run account
 * only rehearses. It never marks anything Shipped or Delivered by itself on a channel that decides that from the carrier.
 *
 * WHAT IS REMEMBERED, AND PER WHAT. A confirmation is recorded against the PARCEL's own reference, not against the order, so:
 *
 *  - the second parcel is still sent after the first (the old record kept one tracking number for the whole order, so whichever arrived second looked like a repeat);
 *  - neither is sent twice, however often this runs;
 *  - and a parcel the channel refused is counted on its own, so one bad box does not stop the other being confirmed.
 *
 * A channel that answers "no" to the same parcel three times is left alone until a person presses the button (or its tracking number changes), instead of being asked every cycle.
 */
class ChannelShipmentNotifier
{
    public const SENT = 'sent';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';
    public const MAX_REFUSALS = 3;

    public function __construct(
        private readonly ChannelConnectorRegistry $connectors,
        private readonly ListsOrderParcels $parcels,
    ) {
    }

    /**
     * @param bool $byAPerson a person asked for it: an earlier run of refusals does not stop it
     * @return array{outcome: string, reason: string|null}
     */
    public function notify(ExternalOrderMapping $mapping, bool $byAPerson = false): array
    {
        $account = $mapping->account;
        if ($mapping->shipment_link_state !== ExternalOrderMapping::LINK_LINKED || blank($mapping->tracking_number)) {
            return $this->result(self::SKIPPED, 'no_linked_shipment_with_tracking');
        }
        if (!in_array($mapping->orderStatus(), [ChannelOrderStatus::New, ChannelOrderStatus::Processing, ChannelOrderStatus::Shipped], true)) {
            return $this->result(self::SKIPPED, 'order_' . $mapping->status);
        }
        if (!$account->enabled || !$account->hasCapability(IntegrationCapability::FulfillmentDispatch) || !$this->connectors->supports($account, ConfirmsShipments::class)) {
            return $this->result(self::SKIPPED, 'fulfillment_dispatch_off');
        }

        $parcels = array_values(array_filter($this->parcels->parcelsFor($mapping), static fn (OrderParcel $parcel): bool => $parcel->isConfirmable()));
        if ($parcels === []) {
            return $this->result(self::SKIPPED, 'no_linked_shipment_with_tracking');
        }

        // Each box on its own. One refused parcel must not stop the next being confirmed, and one already-confirmed parcel must not make the pass look like it did nothing:
        // the worst outcome of the batch is what the pass reports, so a caller still sees a failure, while every parcel that could be sent has been.
        $outcomes = [];
        foreach ($parcels as $parcel) {
            $outcomes[] = $this->notifyParcel($mapping, $account, $parcel, $byAPerson);
        }

        return $this->worstOf($outcomes);
    }

    /**
     * One parcel: sent, skipped or failed, remembered under the parcel's OWN reference.
     *
     * @return array{outcome: string, reason: string|null}
     */
    private function notifyParcel(ExternalOrderMapping $mapping, SalesChannelAccount $account, OrderParcel $parcel, bool $byAPerson): array
    {
        if (($this->confirmed($mapping)[$parcel->reference] ?? null) === $parcel->trackingNumber) {
            return $this->result(self::SKIPPED, 'already_notified');
        }
        if (!$byAPerson && $this->refusalsOf($mapping, $parcel) >= self::MAX_REFUSALS) {
            return $this->result(self::SKIPPED, 'channel_refused_repeatedly');
        }

        $confirmation = new ShipmentConfirmation(
            orderId: $mapping->external_order_id,
            // The channel's own package id where it named one, so a channel that keys a confirmation by package gets the id it knows; our parcel reference is what WE record it under.
            packageId: $mapping->external_package_id,
            carrierCode: strtolower((string) ($mapping->carrier ?? $parcel->provider)),
            carrierName: $mapping->carrier ?? $parcel->provider,
            trackingNumber: (string) $parcel->trackingNumber,
            shippedAt: CarbonImmutable::parse($parcel->shippedAt ?? $mapping->shipped_at ?? now()),
            // Only what is in THIS box.
            lineQuantities: $parcel->lineQuantities,
        );

        try {
            $confirmed = $this->connectors->able($account, ConfirmsShipments::class)->confirmShipment($account, $confirmation);
        } catch (ChannelUnavailableException $exception) {
            return $this->result(self::SKIPPED, $exception->reason);
        } catch (RuntimeException $exception) {
            $this->rememberRefusal($mapping, $parcel, mb_substr($exception->getMessage(), 0, 300));

            return $this->result(self::FAILED, 'channel_notify_error');
        }

        if (!$confirmed) {
            if ($account->dry_run) {
                return $this->result(self::SKIPPED, 'dry_run');
            }
            $this->rememberRefusal($mapping, $parcel, 'channel_did_not_confirm');

            return $this->result(self::FAILED, 'channel_did_not_confirm');
        }

        $this->rememberConfirmed($mapping, $parcel);

        return $this->result(self::SENT, null);
    }

    /**
     * The outcome of a whole order: a failure anywhere outranks a success, and a success outranks a skip.
     *
     * A pass that confirmed one box and was refused the other is a FAILURE, because something still has to happen. Reporting it as a success because one call worked is how a
     * parcel stays unconfirmed with nobody looking.
     *
     * @param list<array{outcome: string, reason: string|null}> $outcomes
     * @return array{outcome: string, reason: string|null}
     */
    private function worstOf(array $outcomes): array
    {
        foreach ([self::FAILED, self::SENT] as $wins) {
            foreach ($outcomes as $outcome) {
                if ($outcome['outcome'] === $wins) {
                    return $outcome;
                }
            }
        }

        return $outcomes[0] ?? $this->result(self::SKIPPED, 'no_linked_shipment_with_tracking');
    }

    /**
     * Every linked shipment of the account that the channel has not been told about.
     *
     * @return array<string, int>
     */
    public function notifyDue(SalesChannelAccount $account): array
    {
        $counts = [self::SENT => 0, self::SKIPPED => 0, self::FAILED => 0];
        $due = ExternalOrderMapping::with('lines', 'account')->where('account_id', $account->id)->where('shipment_link_state', ExternalOrderMapping::LINK_LINKED)
            ->whereNotNull('tracking_number')->whereIn('status', [ChannelOrderStatus::New->value, ChannelOrderStatus::Processing->value, ChannelOrderStatus::Shipped->value])->orderBy('id')->cursor();
        foreach ($due as $mapping) {
            $counts[$this->notify($mapping)['outcome']]++;
        }

        return $counts;
    }

    /**
     * Which parcels the channel has confirmed, as reference => the tracking number it was confirmed with.
     *
     * Keyed by the PARCEL, which is the whole point: the old record kept one tracking number for the entire order, so the second box of a split order looked like a repeat of
     * the first and was never sent. Comparing the tracking number too means a parcel whose number CHANGED (a provider re-labelled it) is confirmed again, which is right.
     *
     * @return array<string, string>
     */
    private function confirmed(ExternalOrderMapping $mapping): array
    {
        $record = (array) ($mapping->snapshot['channel_notified'] ?? []);
        $parcels = (array) ($record['parcels'] ?? []);
        if ($parcels === [] && isset($record['tracking'])) {
            // Written before parcels were counted: one order, one tracking number. Kept under the reference the one-parcel answer uses, so an order confirmed by the old code
            // is not confirmed a second time by the new.
            $reference = $mapping->external_package_id !== '' ? $mapping->external_package_id : (string) $mapping->external_order_id;
            $parcels = [$reference => (string) $record['tracking']];
        }

        return array_map('strval', array_filter($parcels, static fn ($value): bool => is_scalar($value)));
    }

    /** How many times the channel has said no to THIS parcel with the tracking number it carries now. */
    private function refusalsOf(ExternalOrderMapping $mapping, OrderParcel $parcel): int
    {
        $refused = (array) (($mapping->snapshot['channel_notify_error']['parcels'] ?? [])[$parcel->reference] ?? []);

        return ($refused['tracking'] ?? null) === $parcel->trackingNumber ? (int) ($refused['refusals'] ?? 0) : 0;
    }

    private function rememberConfirmed(ExternalOrderMapping $mapping, OrderParcel $parcel): void
    {
        $snapshot = (array) $mapping->snapshot;
        $record = (array) ($snapshot['channel_notified'] ?? []);
        $record['parcels'] = $this->confirmed($mapping);
        $record['parcels'][$parcel->reference] = (string) $parcel->trackingNumber;
        $record['at'] = now()->toIso8601String();
        // Still written for anything that reads the old shape, and it is the LAST parcel confirmed rather than a claim about the order.
        $record['tracking'] = (string) $parcel->trackingNumber;
        $snapshot['channel_notified'] = $record;

        // A refusal of this parcel is settled by its confirmation; the other parcels' refusals stand.
        $errors = (array) ($snapshot['channel_notify_error'] ?? []);
        unset($errors['parcels'][$parcel->reference]);
        $snapshot['channel_notify_error'] = $errors;

        $mapping->forceFill(['snapshot' => $snapshot])->save();
    }

    private function rememberRefusal(ExternalOrderMapping $mapping, OrderParcel $parcel, string $error): void
    {
        $snapshot = (array) $mapping->snapshot;
        $errors = (array) ($snapshot['channel_notify_error'] ?? []);
        $errors['parcels'][$parcel->reference] = [
            'error' => $error,
            'tracking' => $parcel->trackingNumber,
            'refusals' => $this->refusalsOf($mapping, $parcel) + 1,
            'at' => now()->toIso8601String(),
        ];
        $errors['error'] = $error;
        $snapshot['channel_notify_error'] = $errors;

        $mapping->forceFill(['snapshot' => $snapshot])->save();
    }

    /** @return array{outcome: string, reason: string|null} */
    private function result(string $outcome, ?string $reason): array
    {
        return ['outcome' => $outcome, 'reason' => $reason];
    }
}
