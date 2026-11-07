<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Http\Controllers\Admin\SalesChannels\ChannelLabels;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use App\Models\SalesChannelProductMapping;
use App\Models\SalesChannelSyncState;
use Illuminate\Support\Collection;

/**
 * What the hub shows: one card of facts per account, and the things that need a person, each with the link that resolves it.
 *
 * Every number is counted by the database in one query for all accounts (never one query per card). A fact that does not exist is absent (null), never zero.
 */
final class HubSummary
{
    /** How many entries of each kind the attention list spells out; the rest are one click away. */
    private const ATTENTION_LIMIT = 8;

    public function __construct(
        private readonly ChannelLabels $labels,
        private readonly SyncResources $resources,
    ) {
    }

    /**
     * @param Collection<int, SalesChannelAccount> $accounts
     * @return list<array<string, mixed>>
     */
    public function cards(Collection $accounts): array
    {
        $ids = $accounts->pluck('id')->all();
        $states = SalesChannelSyncState::whereIn('account_id', $ids)->get()->groupBy('account_id');
        $unlinked = SalesChannelProductMapping::whereIn('account_id', $ids)->unlinked()->selectRaw('account_id, count(*) as total')->groupBy('account_id')->pluck('total', 'account_id');
        $review = ExternalOrderMapping::whereIn('account_id', $ids)->whereNotNull('review_reason')->selectRaw('account_id, count(*) as total')->groupBy('account_id')->pluck('total', 'account_id');
        $held = ExternalOrderMapping::whereIn('account_id', $ids)->where('execution_policy', ExecutionPolicy::ManualReview->value)->whereNotIn('status', $this->terminalStatuses())
            ->selectRaw('account_id, count(*) as total')->groupBy('account_id')->pluck('total', 'account_id');
        $modes = ExternalOrderMapping::whereIn('account_id', $ids)->selectRaw('account_id, fulfillment_mode, count(*) as total')->groupBy('account_id', 'fulfillment_mode')->get()->groupBy('account_id');

        return $accounts->map(fn (SalesChannelAccount $account): array => [
            'account' => $account,
            'writeState' => WriteState::of($account),
            'capabilities' => array_values(array_filter(IntegrationCapability::cases(), static fn (IntegrationCapability $capability): bool => $account->hasCapability($capability))),
            'resources' => $this->resources->supportedBy($account),
            'states' => ($states->get($account->id) ?? collect())->keyBy('resource'),
            'unlinkedListings' => (int) ($unlinked[$account->id] ?? 0),
            'ordersNeedingReview' => (int) ($review[$account->id] ?? 0),
            'ordersHeld' => (int) ($held[$account->id] ?? 0),
            'modes' => $this->modeCounts($modes->get($account->id) ?? collect()),
        ])->all();
    }

    /**
     * @param Collection<int, SalesChannelAccount> $accounts
     * @return list<array{title: string, total: int, items: list<array{title: string, detail: string, url: string}>, moreUrl: string|null}>
     */
    public function attention(Collection $accounts): array
    {
        return array_values(array_filter([
            $this->authorisation($accounts),
            $this->backingOff($accounts),
            $this->ordersToReview(),
            $this->failedPublishes(),
            $this->financeToReview(),
            $this->statementRowsThatMayRepeatTheApi(),
        ], static fn (array $section): bool => $section['total'] > 0));
    }

    /** @return array{channel: int, merchant: int, unknown: int} orders the channel ships itself, orders we or the seller ship, and orders whose mode the channel did not state */
    private function modeCounts(Collection $rows): array
    {
        $counts = ['channel' => 0, 'merchant' => 0, 'unknown' => 0];
        foreach ($rows as $row) {
            $mode = FulfillmentMode::tryFrom((string) $row->fulfillment_mode) ?? FulfillmentMode::Unknown;
            $counts[match (true) {
                $mode === FulfillmentMode::Unknown => 'unknown',
                $mode->isFulfilledByChannel() => 'channel',
                default => 'merchant',
            }] += (int) $row->total;
        }

        return $counts;
    }

    /** @return list<string> */
    private function terminalStatuses(): array
    {
        return array_map(
            static fn (ChannelOrderStatus $status): string => $status->value,
            array_values(array_filter(ChannelOrderStatus::cases(), static fn (ChannelOrderStatus $status): bool => $status->isTerminal())),
        );
    }

    /** @param Collection<int, SalesChannelAccount> $accounts */
    private function authorisation(Collection $accounts): array
    {
        $lost = $accounts->filter(static fn (SalesChannelAccount $account): bool => in_array($account->connectionStatus(), [ConnectionStatus::AuthExpired, ConnectionStatus::Revoked], true));

        return $this->section(
            title: translate('attention_authorisation_lost'),
            total: $lost->count(),
            items: $lost->take(self::ATTENTION_LIMIT)->map(fn (SalesChannelAccount $account): array => [
                'title' => $account->name,
                'detail' => translate($account->connectionStatus()->labelKey()) . ($account->connection_message ? ': ' . $account->connection_message : ''),
                'url' => route('admin.sales-channels.accounts.show', $account),
            ])->values()->all(),
        );
    }

    /** @param Collection<int, SalesChannelAccount> $accounts */
    private function backingOff(Collection $accounts): array
    {
        $waiting = SalesChannelSyncState::whereIn('account_id', $accounts->pluck('id'))->where('backoff_until', '>', now())->orderBy('backoff_until')->get();

        return $this->section(
            title: translate('attention_sync_backing_off'),
            total: $waiting->count(),
            items: $waiting->take(self::ATTENTION_LIMIT)->map(fn (SalesChannelSyncState $state): array => [
                'title' => ($accounts->firstWhere('id', $state->account_id)?->name ?? '#' . $state->account_id) . ' · ' . $this->labels->syncResource($state->resource),
                'detail' => $state->backoff_until->format('Y-m-d H:i') . ($state->last_error ? ': ' . mb_substr($state->last_error, 0, 160) : ''),
                'url' => route('admin.sales-channels.accounts.show', $state->account_id),
            ])->values()->all(),
        );
    }

    private function ordersToReview(): array
    {
        $orders = ExternalOrderMapping::whereNotNull('review_reason');

        return $this->section(
            title: translate('attention_orders_need_review'),
            total: (clone $orders)->count(),
            items: $orders->latest('id')->limit(self::ATTENTION_LIMIT)->get()->map(fn (ExternalOrderMapping $order): array => [
                'title' => $order->external_order_id . ($order->external_package_id !== '' ? ' / ' . $order->external_package_id : ''),
                'detail' => $this->labels->orderReason($order->review_reason),
                'url' => route('admin.sales-channels.orders.show', $order),
            ])->all(),
            moreUrl: route('admin.sales-channels.orders.index', ['review' => 1]),
        );
    }

    private function failedPublishes(): array
    {
        $listings = SalesChannelProductMapping::where(static fn ($query) => $query->where('snapshot->publish->stock->state', 'failed')->orWhere('snapshot->publish->price->state', 'failed'));

        return $this->section(
            title: translate('attention_publishes_failed'),
            total: (clone $listings)->count(),
            items: $listings->latest('id')->limit(self::ATTENTION_LIMIT)->get()->map(fn (SalesChannelProductMapping $listing): array => [
                'title' => $listing->listing_key,
                'detail' => (string) ($listing->snapshot['publish']['stock']['error'] ?? $listing->snapshot['publish']['price']['error'] ?? ''),
                'url' => route('admin.sales-channels.products.index', ['account' => $listing->account_id, 'q' => $listing->listing_key]),
            ])->all(),
        );
    }

    private function financeToReview(): array
    {
        $events = SalesChannelEvent::where('outcome', 'needs_review');

        return $this->section(
            title: translate('attention_finance_needs_review'),
            total: (clone $events)->count(),
            items: $events->latest('id')->limit(self::ATTENTION_LIMIT)->get()->map(fn (SalesChannelEvent $event): array => [
                'title' => $event->event_type,
                'detail' => (string) ($event->external_reference ?? ''),
                'url' => route('admin.sales-channels.logs.index', ['tab' => 'events', 'outcome' => 'needs_review']),
            ])->all(),
            moreUrl: route('admin.sales-channels.logs.index', ['tab' => 'events', 'outcome' => 'needs_review']),
        );
    }

    /** Statement rows a person imported that were counted but may repeat a charge the channel API gave. */
    private function statementRowsThatMayRepeatTheApi(): array
    {
        $events = SalesChannelEvent::where('outcome_note', SalesChannelEvent::NOTE_POSSIBLE_OVERLAP);
        $url = route('admin.sales-channels.logs.index', ['tab' => 'events', 'note' => SalesChannelEvent::NOTE_POSSIBLE_OVERLAP]);

        return $this->section(
            title: translate('attention_possible_overlap'),
            total: (clone $events)->count(),
            items: $events->latest('id')->limit(self::ATTENTION_LIMIT)->get()->map(fn (SalesChannelEvent $event): array => [
                'title' => $event->event_type,
                'detail' => $event->occurred_at?->format('Y-m-d') . ' · ' . ($event->payload['amount'] ?? '') . ' ' . ($event->payload['currency'] ?? ''),
                'url' => $url,
            ])->all(),
            moreUrl: $url,
        );
    }

    /**
     * @param list<array{title: string, detail: string, url: string}> $items
     * @return array{title: string, total: int, items: list<array{title: string, detail: string, url: string}>, moreUrl: string|null}
     */
    private function section(string $title, int $total, array $items, ?string $moreUrl = null): array
    {
        return ['title' => $title, 'total' => $total, 'items' => $items, 'moreUrl' => $moreUrl];
    }
}
