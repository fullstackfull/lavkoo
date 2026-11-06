<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Http\Controllers\Admin\SalesChannels\ChannelLabels;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\SalesChannels\Shipments\ChannelShipmentNotifier;
use App\Services\SalesChannels\Sync\ChannelSyncRunner;

/**
 * Tells the administrator what a run really came to. A run that was skipped, was not started, was told to wait or failed is never reported as done, and a rehearsal is never
 * reported as a send. Text that came from a channel or from an exception is shown through SafeToast, which escapes it.
 */
final class RunAnnouncer
{
    public function __construct(private readonly ChannelLabels $labels)
    {
    }

    /** @param array{result: string, detail: string|null, stats: array<string, mixed>} $run what ChannelSyncRunner::run() returned */
    public function announce(array $run): void
    {
        match ($run['result']) {
            ChannelSyncRunner::OK => $this->finished($run['stats']),
            ChannelSyncRunner::SKIPPED => SafeToast::warning(translate('run_skipped_nothing_asked'), $this->labels->channelReason($run['detail'])),
            ChannelSyncRunner::BUSY => SafeToast::info(translate('run_busy_explained')),
            ChannelSyncRunner::RATE_LIMITED => SafeToast::warning(translate('run_rate_limited_explained'), (string) $run['detail']),
            default => SafeToast::error(translate('run_failed_explained'), $run['detail']),
        };
    }

    /** @param array{applied: int, failed: int, unconfirmed: int, pending: int} $counts what StockPricePublisher::settle() returned */
    public function announceSettled(array $counts): void
    {
        $line = $this->labels->statsLine($counts);
        $counts['failed'] + $counts['unconfirmed'] > 0
            ? SafeToast::warning(translate('settle_needs_attention'), $line)
            : SafeToast::success(translate('settle_finished'), $line);
    }

    /** @param array<string, int> $counts what ChannelShipmentNotifier::notifyDue() returned */
    public function announceShipments(array $counts, WriteState $state): void
    {
        $line = $this->labels->statsLine($counts);
        match (true) {
            $state === WriteState::Rehearsal => SafeToast::info(translate('publish_rehearsed_nothing_sent'), $line),
            ($counts[ChannelShipmentNotifier::FAILED] ?? 0) > 0 => SafeToast::warning(translate('shipments_some_failed'), $line),
            default => SafeToast::success(translate('shipments_run_finished'), $line),
        };
    }

    /** @param array{outcome: string, reason: string|null} $result what ChannelShipmentNotifier::notify() returned */
    public function announceNotification(array $result): void
    {
        $reason = $this->labels->channelReason($result['reason']);
        match ($result['outcome']) {
            ChannelShipmentNotifier::SENT => SafeToast::success(translate('shipment_reported_to_channel')),
            ChannelShipmentNotifier::SKIPPED => SafeToast::info(translate('shipment_not_reported_nothing_sent'), $reason),
            default => SafeToast::error(translate('shipment_report_failed'), $reason),
        };
    }

    /** @param array<string, mixed> $stats */
    private function finished(array $stats): void
    {
        $line = $this->labels->statsLine($stats);
        ($stats['dry_run'] ?? false) === true
            ? SafeToast::info(translate('publish_rehearsed_nothing_sent'), $line)
            : SafeToast::success(translate('run_finished'), $line);
    }
}
