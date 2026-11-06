<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Applies the order-package webhooks Trendyol delivers.
 *
 * A delivery is at-least-once and may arrive out of order, so each package event is stored once under (account, package, status, last-modified time) and applied through the
 * same idempotent ingestor the poller uses: a repeated or stale event changes nothing. The payload is a full package, identical to the order feed; it is accepted wrapped
 * (`content[]`) or bare. Only a minimal, personal-data-free reference is stored with the event.
 *
 * Nothing is applied unless integrations are on, the account is enabled and holds Order Import; the event is still recorded so the gap is visible, and polling (the source
 * of truth) fills it in when the capability is switched on.
 */
class TrendyolWebhookIngestor
{
    public const APPLIED = 'applied';
    public const DUPLICATE = 'duplicate';
    public const IGNORED_OFF = 'ignored_capability_off';
    public const IGNORED_INVALID = 'ignored_invalid';
    public const FAILED = 'failed';

    public function __construct(
        private readonly TrendyolOrderMapper $mapper,
        private readonly ChannelOrderIngestor $ingestor,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int> how many packages ended in each outcome
     */
    public function ingest(SalesChannelAccount $account, array $payload): array
    {
        $packages = isset($payload['content']) && is_array($payload['content']) ? $payload['content'] : [$payload];
        $counts = [self::APPLIED => 0, self::DUPLICATE => 0, self::IGNORED_OFF => 0, self::IGNORED_INVALID => 0, self::FAILED => 0];

        foreach ($packages as $package) {
            if (!is_array($package) || !isset($package['shipmentPackageId'], $package['orderNumber'])) {
                $counts[self::IGNORED_INVALID]++;
                continue;
            }
            $counts[$this->ingestPackage($account, $package)]++;
        }

        return $counts;
    }

    /** @param array<string, mixed> $package */
    private function ingestPackage(SalesChannelAccount $account, array $package): string
    {
        $status = (string) ($package['shipmentPackageStatus'] ?? $package['status'] ?? '');
        $event = $this->record($account, $package, $status);
        if ($event === null) {
            return self::DUPLICATE;
        }

        if (!$this->mayApply($account)) {
            $event->forceFill(['outcome' => self::IGNORED_OFF, 'processed_at' => now()])->save();

            return self::IGNORED_OFF;
        }

        try {
            $result = $this->ingestor->ingest($account, $this->mapper->map($package, $account));
        } catch (InvalidArgumentException $exception) {
            $event->forceFill(['outcome' => self::FAILED, 'outcome_note' => mb_substr($exception->getMessage(), 0, 250), 'processed_at' => now()])->save();

            return self::FAILED;
        }
        $event->forceFill(['outcome' => self::APPLIED, 'outcome_note' => $result->outcome, 'processed_at' => now()])->save();

        return self::APPLIED;
    }

    /** @return SalesChannelEvent|null null when this exact event was stored before */
    private function record(SalesChannelAccount $account, array $package, string $status): ?SalesChannelEvent
    {
        $packageId = (string) $package['shipmentPackageId'];
        $modified = (string) ($package['lastModifiedDate'] ?? '');

        try {
            return SalesChannelEvent::create([
                'account_id' => $account->id,
                'channel' => $account->channel,
                'source' => 'webhook',
                'event_type' => mb_substr('order_package.' . strtolower($status), 0, 60),
                'dedupe_key' => hash('sha256', implode('|', ['trendyol', $account->id, $packageId, $status, $modified])),
                'external_reference' => $package['orderNumber'] . '/' . $packageId,
                'payload' => ['order_number' => (string) $package['orderNumber'], 'shipment_package_id' => $packageId, 'status' => $status, 'last_modified' => $modified],
                'outcome' => 'received',
                'occurred_at' => is_numeric($modified) ? CarbonImmutable::createFromTimestampMsUTC((int) $modified) : null,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function mayApply(SalesChannelAccount $account): bool
    {
        return (bool) config('sales_channels.enabled', false) && $account->enabled && $account->hasCapability(IntegrationCapability::OrderImport);
    }
}
