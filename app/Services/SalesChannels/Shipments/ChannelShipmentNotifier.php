<?php

namespace App\Services\SalesChannels\Shipments;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Contracts\ConfirmsShipments;
use App\Services\SalesChannels\Data\ShipmentConfirmation;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Tells the channel that an order shipped, with the tracking number of the shipment that is linked to it. A WRITE to the channel: it goes through the transport's switches
 * (environment, account, the Fulfilment Dispatch capability and its recorded approval), a dry-run account only rehearses, and a tracking number the channel has already been
 * given is not sent again. It never marks anything Shipped or Delivered by itself on a channel that decides that from the carrier.
 */
class ChannelShipmentNotifier
{
    public const SENT = 'sent';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    public function __construct(private readonly ChannelConnectorRegistry $connectors)
    {
    }

    /** @return array{outcome: string, reason: string|null} */
    public function notify(ExternalOrderMapping $mapping): array
    {
        $account = $mapping->account;
        if ($mapping->shipment_link_state !== ExternalOrderMapping::LINK_LINKED || blank($mapping->tracking_number)) {
            return $this->result(self::SKIPPED, 'no_linked_shipment_with_tracking');
        }
        if (!in_array($mapping->orderStatus(), [ChannelOrderStatus::New, ChannelOrderStatus::Processing, ChannelOrderStatus::Shipped], true)) {
            return $this->result(self::SKIPPED, 'order_' . $mapping->status);
        }
        if (($mapping->snapshot['channel_notified']['tracking'] ?? null) === $mapping->tracking_number) {
            return $this->result(self::SKIPPED, 'already_notified');
        }
        if (!$account->enabled || !$account->hasCapability(IntegrationCapability::FulfillmentDispatch) || !$this->connectors->supports($account, ConfirmsShipments::class)) {
            return $this->result(self::SKIPPED, 'fulfillment_dispatch_off');
        }

        $confirmation = new ShipmentConfirmation(
            orderId: $mapping->external_order_id,
            packageId: $mapping->external_package_id,
            carrierCode: strtolower((string) ($mapping->carrier ?? $mapping->provider)),
            carrierName: $mapping->carrier,
            trackingNumber: (string) $mapping->tracking_number,
            shippedAt: CarbonImmutable::parse($mapping->shipped_at ?? now()),
            lineQuantities: $mapping->lines->mapWithKeys(static fn (ExternalOrderLine $line): array => [$line->external_line_id => $line->activeQuantity()])->filter()->all(),
        );

        try {
            $confirmed = $this->connectors->able($account, ConfirmsShipments::class)->confirmShipment($account, $confirmation);
        } catch (ChannelUnavailableException $exception) {
            return $this->result(self::SKIPPED, $exception->reason);
        } catch (RuntimeException $exception) {
            $this->remember($mapping, ['error' => mb_substr($exception->getMessage(), 0, 300), 'at' => now()->toIso8601String()], 'channel_notify_error');

            return $this->result(self::FAILED, 'channel_notify_error');
        }

        if (!$confirmed) {
            return $this->result($account->dry_run ? self::SKIPPED : self::FAILED, $account->dry_run ? 'dry_run' : 'channel_did_not_confirm');
        }
        $this->remember($mapping, ['tracking' => $mapping->tracking_number, 'at' => now()->toIso8601String()], 'channel_notified');

        return $this->result(self::SENT, null);
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

    /** @param array<string, mixed> $value */
    private function remember(ExternalOrderMapping $mapping, array $value, string $key): void
    {
        $snapshot = (array) $mapping->snapshot;
        $snapshot[$key] = $value;
        $mapping->forceFill(['snapshot' => $snapshot])->save();
    }

    /** @return array{outcome: string, reason: string|null} */
    private function result(string $outcome, ?string $reason): array
    {
        return ['outcome' => $outcome, 'reason' => $reason];
    }
}
