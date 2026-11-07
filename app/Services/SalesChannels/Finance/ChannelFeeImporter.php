<?php

namespace App\Services\SalesChannels\Finance;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;
use App\Services\OperationalCosts\Support\OperationalCostsSwitch;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Turns what a channel's statements say into the cost ledger, without ever counting a fact twice and without ever treating a payout as revenue.
 *
 *  - Every fact the channel reports is stored once as an event under its identity (a redelivered statement row changes nothing). Sales, refunds, payouts and
 *    facts reported again elsewhere are kept for reconciliation and are never costs; facts nobody could classify are kept flagged for a person.
 *  - Fees and adjustments become ACTUAL costs. A channel posts several amounts for one order (a commission, then part of it returned with a refund), so the cost of an
 *    order's service is the NET of everything posted for it, recorded as one actual in the same slot as the estimate, so it supersedes the estimate and any earlier net
 *    (a posting in a currency other than the order's gets a slot of its own). A fee that belongs to no order we hold
 *    (a subscription, a storage charge) is its own entry on the account, marked unallocated, and is never spread over orders by guesswork.
 *  - One charge is one cost however many sources report it. Each fee event carries its canonical identity (ChargeIdentity: the fee type, currency and amount to six decimals, and
 *    what it is about) and a manual statement row that repeats a charge the API already gave is recorded as `duplicate` and not counted (ChargeOverlapMatcher: the same
 *    references, or the same day and exactly one candidate); one that may overlap is counted and marked `possible_overlap` for a person. The same match is made from the API's side
 *    when the statement came first. Refunds are applied to the revenue of the order lines (ChannelRefundApplier).
 *  - The stored event IS the queue: a fee or adjustment event says `received` until its cost is in the ledger and `applied` afterwards, and the ledger is written from
 *    the events that are still `received`, whenever this runs. So a posting is made exactly once however it came about: a statement read twice, a write the database
 *    refused once, a statement read while the cost module was switched off (OPERATIONAL_COSTS_ENABLED, off by default: the events are stored and nothing is posted) and
 *    posted when it was switched on. `postPending()` is the catch-up, run by the next import and by `costs:post-pending-fees`; running it again posts nothing twice.
 */
class ChannelFeeImporter
{
    private const COST_CATEGORIES = [RemoteFinancialEntry::FEE, RemoteFinancialEntry::ADJUSTMENT];

    /** Where a fact came from: the channel's API, or a statement a person imported. */
    public const SOURCE_API = 'poll';
    public const SOURCE_MANUAL = 'manual';

    /** A fact's event is stored before its cost is recorded; until the cost is in the ledger the event says `received`, afterwards `applied`. */
    private const PENDING = SalesChannelEvent::RECEIVED;
    private const POSTED = SalesChannelEvent::APPLIED;

    /** The most stored events one pass over the pending postings holds in memory. */
    private const POST_CHUNK = 500;

    private const STORED_NOW = 'stored_now';
    private const ALREADY_HANDLED = 'already_handled';
    private const COST_NOT_RECORDED_YET = 'cost_not_recorded_yet';

    public function __construct(
        private readonly CostLedger $ledger,
        private readonly FxConverter $fx,
        private readonly ChargeOverlapMatcher $overlaps,
        private readonly ChannelRefundApplier $refunds,
    ) {
    }

    /**
     * Stores what the channel reported (or what a person's statement says: `$source`) and posts the costs that are waiting, this statement's and any an earlier run left
     * behind, then takes the refunds that are waiting off the order lines they are about.
     *
     * @param iterable<RemoteFinancialEntry> $entries
     * @return array{new: int, duplicates: int, costs_recorded: int, needs_review: int}
     */
    public function import(SalesChannelAccount $account, iterable $entries, string $source = self::SOURCE_API): array
    {
        $stats = ['new' => 0, 'duplicates' => 0, 'costs_recorded' => 0, 'needs_review' => 0];

        foreach ($entries as $entry) {
            $state = $this->remember($account, $entry, $source);
            if ($state === self::ALREADY_HANDLED) {
                $stats['duplicates']++;
            } elseif ($state === self::STORED_NOW) {
                $stats['new']++;
                $stats['needs_review'] += $entry->category === RemoteFinancialEntry::UNCLASSIFIED ? 1 : 0;
            }
        }
        $stats['costs_recorded'] = $this->postPending($account);
        $this->refunds->applyPending($account);

        return $stats;
    }

    /**
     * Posts the stored fee and adjustment events of an account whose cost is not in the ledger yet. Nothing while OPERATIONAL_COSTS_ENABLED is off: they wait. Safe to repeat.
     *
     * @return int the cost entries written
     */
    public function postPending(SalesChannelAccount $account): int
    {
        if (!OperationalCostsSwitch::enabled()) {
            return 0;
        }

        $recorded = 0;
        $this->pendingEvents($account)->chunkById(self::POST_CHUNK, function (Collection $events) use ($account, &$recorded): void {
            $slots = [];
            foreach ($events as $event) {
                $entry = $this->entryOf($event);
                $slots[$this->slotKey($entry)] = $entry;
            }
            foreach ($slots as $entry) {
                $recorded += $this->recordSlot($account, $entry) ? 1 : 0;
            }
        });

        return $recorded;
    }

    /** How many fee and adjustment events wait to be posted (of one account, or of all). */
    public function pendingPostings(?SalesChannelAccount $account = null): int
    {
        return $this->pendingEvents($account)->count();
    }

    /** @return list<int> the accounts that have postings waiting */
    public function accountsWithPendingPostings(): array
    {
        return $this->pendingEvents()->distinct()->orderBy('account_id')->pluck('account_id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @return Builder<SalesChannelEvent> */
    private function pendingEvents(?SalesChannelAccount $account = null): Builder
    {
        return SalesChannelEvent::query()
            ->when($account !== null, static fn (Builder $query) => $query->where('account_id', $account->id))
            ->whereNotNull('account_id')
            ->where('outcome', self::PENDING)
            ->where(static function (Builder $query): void {
                foreach (self::COST_CATEGORIES as $category) {
                    $query->orWhere('event_type', 'like', 'finance.' . $category . '.%');
                }
            });
    }

    /** The fact an event was stored from. */
    private function entryOf(SalesChannelEvent $event): RemoteFinancialEntry
    {
        $fact = (array) $event->payload;

        return new RemoteFinancialEntry(
            identity: (string) $event->dedupe_key,
            category: (string) $fact['category'],
            component: CostComponent::from((string) $fact['component']),
            amount: Money::of((string) $fact['amount']),
            currency: (string) $fact['currency'],
            orderId: $fact['order_id'] ?? null,
            packageId: $fact['package_id'] ?? null,
            lineId: null,
            sku: $fact['sku'] ?? null,
            postedAt: $event->occurred_at,
            rawType: (string) ($fact['raw_type'] ?? ''),
            meta: (array) ($fact['meta'] ?? []),
        );
    }

    private function slotKey(RemoteFinancialEntry $entry): string
    {
        return $entry->orderId === null ? 'fact:' . $entry->identity : implode('|', [$entry->orderId, $entry->packageId ?? '', $entry->component->value, $entry->currency]);
    }

    /**
     * A fact that was stored before is not counted again, unless its cost never reached the ledger (the database refused the write once): then it is recorded now,
     * because the channel will not send it a third time.
     *
     * @return self::STORED_NOW|self::ALREADY_HANDLED|self::COST_NOT_RECORDED_YET
     */
    private function remember(SalesChannelAccount $account, RemoteFinancialEntry $entry, string $source): string
    {
        $identity = $this->identityOf($account, $entry);
        [$overlap, $repeated] = $identity !== null && $source === self::SOURCE_MANUAL
            ? $this->overlaps->forManualRow($account, $identity, $entry->postedAt)
            : [ChargeOverlapMatcher::NONE, null];

        try {
            $event = SalesChannelEvent::create([
                'account_id' => $account->id,
                'channel' => $account->channel,
                'source' => $source,
                'event_type' => 'finance.' . $entry->category . '.' . $entry->component->value,
                'dedupe_key' => $entry->identity,
                'external_reference' => ChargeIdentity::referenceOf($entry->orderId, $entry->packageId, $entry->meta['settlement_id'] ?? null),
                'charge_key' => $identity?->shape,
                'duplicate_of_id' => $repeated?->id,
                'payload' => [
                    'category' => $entry->category, 'component' => $entry->component->value, 'amount' => $entry->amount->toStorage(), 'currency' => $entry->currency,
                    'order_id' => $entry->orderId, 'package_id' => $entry->packageId, 'sku' => $entry->sku, 'raw_type' => $entry->rawType, 'meta' => $entry->meta,
                ],
                'outcome' => $this->outcomeOf($entry, repeatsAChargeTheApiGave: $repeated !== null),
                'outcome_note' => $overlap === ChargeOverlapMatcher::POSSIBLE_OVERLAP ? SalesChannelEvent::NOTE_POSSIBLE_OVERLAP : null,
                'occurred_at' => $entry->postedAt,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A fact whose cost is still waiting is posted by this very run when the module is on (it is neither new nor a duplicate); with the module off it is only held.
            $costPending = OperationalCostsSwitch::enabled() && SalesChannelEvent::where('account_id', $account->id)->where('dedupe_key', $entry->identity)->where('outcome', self::PENDING)->exists();

            return $costPending ? self::COST_NOT_RECORDED_YET : self::ALREADY_HANDLED;
        }
        if ($identity !== null && $source !== self::SOURCE_MANUAL) {
            $this->recogniseStatementRowsRepeatedBy($account, $event);
        }

        return self::STORED_NOW;
    }

    /** The canonical identity of a fee or an adjustment; any other fact is not a charge. */
    private function identityOf(SalesChannelAccount $account, RemoteFinancialEntry $entry): ?ChargeIdentity
    {
        return in_array($entry->category, self::COST_CATEGORIES, true)
            ? ChargeIdentity::of((int) $account->id, $entry->orderId, $entry->packageId, $entry->meta['settlement_id'] ?? null, $entry->component, $entry->currency, $entry->amount)
            : null;
    }

    private function outcomeOf(RemoteFinancialEntry $entry, bool $repeatsAChargeTheApiGave): string
    {
        return match (true) {
            $entry->category === RemoteFinancialEntry::UNCLASSIFIED => SalesChannelEvent::NEEDS_REVIEW,
            $repeatsAChargeTheApiGave => SalesChannelEvent::DUPLICATE,
            in_array($entry->category, self::COST_CATEGORIES, true) => self::PENDING,
            // A refund of an order line waits to be taken off its revenue; any other refund only says what was given back.
            $entry->category === RemoteFinancialEntry::REFUND && isset($entry->meta['refund_part']) => self::PENDING,
            default => SalesChannelEvent::RECORDED_ONLY,
        };
    }

    /**
     * An API event arrives after a statement a person imported: the row that repeats it stops counting (its order's net is made again when this event is posted), and rows that
     * only may repeat it are marked for a person. A repeat that was already posted as a fee of no order we hold is taken back with an equal and opposite entry.
     */
    private function recogniseStatementRowsRepeatedBy(SalesChannelAccount $account, SalesChannelEvent $event): void
    {
        [$kind, $rows] = $this->overlaps->forApiEvent($account, $event);
        if ($kind === ChargeOverlapMatcher::DUPLICATE) {
            $row = $rows->first();
            $this->takeBackPostedFactOfNoOrder($account, $row);
            $row->forceFill(['outcome' => SalesChannelEvent::DUPLICATE, 'duplicate_of_id' => $event->id, 'outcome_note' => null])->save();
        } elseif ($kind === ChargeOverlapMatcher::POSSIBLE_OVERLAP) {
            SalesChannelEvent::whereIn('id', $rows->pluck('id'))->update(['outcome_note' => SalesChannelEvent::NOTE_POSSIBLE_OVERLAP]);
        }
    }

    private function takeBackPostedFactOfNoOrder(SalesChannelAccount $account, SalesChannelEvent $row): void
    {
        $entry = $this->entryOf($row);
        if ($row->outcome !== self::POSTED || ($entry->orderId !== null && $this->mappingFor($account, $entry) !== null)) {
            return;
        }
        $slot = new CostDraft(
            subjectType: 'channel_account', subjectId: (int) $account->id, component: $entry->component, state: CostState::Actual, amount: Money::zero(), currency: $entry->currency,
            periodDate: CarbonImmutable::parse($entry->postedAt), source: 'channel_api', identity: $entry->identity, serviceRef: 'fact:' . $entry->identity, sellerId: (int) $account->seller_id,
        );
        $posted = OperationalCostEntry::where('slot_key', $slot->slotKey())->where('is_effective', true)->first();
        if ($posted !== null) {
            $this->ledger->reverse($posted, null, 'The same charge was given by the channel API.');
        }
    }

    private function recordSlot(SalesChannelAccount $account, RemoteFinancialEntry $entry): bool
    {
        $mapping = $entry->orderId === null ? null : $this->mappingFor($account, $entry);

        if ($mapping === null) {
            return $this->record($account, $entry, subjectType: 'channel_account', subjectId: (int) $account->id, mapping: null, net: $entry->amount, identity: $entry->identity, serviceRef: 'fact:' . $entry->identity, postedAt: CarbonImmutable::parse($entry->postedAt), identities: [$entry->identity]);
        }

        // A report that names only the order and one that names its package are about the same order: every posting of it, whichever way it was written, is netted.
        $references = array_values(array_unique(array_filter([
            ChargeIdentity::referenceOf($entry->orderId, $entry->packageId, null),
            ChargeIdentity::referenceOf($entry->orderId, null, null),
            ChargeIdentity::referenceOf($entry->orderId, $mapping->external_package_id, null),
        ])));
        $postings = SalesChannelEvent::where('account_id', $account->id)
            ->whereIn('external_reference', $references)
            ->whereIn('event_type', array_map(fn (string $category): string => 'finance.' . $category . '.' . $entry->component->value, self::COST_CATEGORIES))
            ->where('outcome', '!=', SalesChannelEvent::DUPLICATE)
            ->orderBy('dedupe_key')
            ->get()
            ->filter(static fn (SalesChannelEvent $event): bool => ($event->payload['currency'] ?? null) === $entry->currency);

        $net = Money::sum($postings->map(static fn (SalesChannelEvent $event): Money => Money::of($event->payload['amount'])));
        $identities = $postings->pluck('dedupe_key')->all();
        $latest = $postings->max('occurred_at') ?? $entry->postedAt;

        return $this->record($account, $entry, subjectType: SaleDocument::SUBJECT_CHANNEL_ORDER, subjectId: (int) $mapping->id, mapping: $mapping, net: $net, identity: 'net:' . hash('sha256', implode(',', $identities)), serviceRef: $entry->currency === $mapping->currency ? '' : 'channel-net:' . $entry->currency, postedAt: CarbonImmutable::parse($latest), identities: $identities);
    }

    private function mappingFor(SalesChannelAccount $account, RemoteFinancialEntry $entry): ?ExternalOrderMapping
    {
        $found = ExternalOrderMapping::where('account_id', $account->id)->where('external_order_id', $entry->orderId)
            ->when($entry->packageId !== null, fn ($query) => $query->where('external_package_id', $entry->packageId))
            ->limit(2)->get();

        // An order held as several packages and a fee that names none of them cannot be pinned to one: it stays on the account for a person.
        return $found->count() === 1 ? $found->first() : null;
    }

    /** @param list<string> $identities */
    private function record(SalesChannelAccount $account, RemoteFinancialEntry $entry, string $subjectType, int $subjectId, ?ExternalOrderMapping $mapping, Money $net, string $identity, string $serviceRef, CarbonImmutable $postedAt, array $identities): bool
    {
        $converted = $this->fx->convert($net, $entry->currency, $this->fx->baseCurrency());

        $outcome = $this->ledger->record(new CostDraft(
            subjectType: $subjectType,
            subjectId: $subjectId,
            component: $entry->component,
            state: CostState::Actual,
            amount: $net,
            currency: $entry->currency,
            periodDate: $postedAt,
            source: 'channel_api',
            identity: $identity,
            serviceRef: $serviceRef,
            sellerId: (int) $account->seller_id,
            salesChannel: $account->channel,
            accountId: (int) $account->id,
            orderId: $mapping?->order_id,
            externalOrderMappingId: $mapping?->id,
            externalReference: $entry->orderId === null ? null : $entry->orderId . ($entry->packageId === null ? '' : '/' . $entry->packageId),
            calcInputs: ['postings' => count($identities), 'raw_type' => $entry->rawType],
            note: $mapping === null ? 'not_linked_to_an_order' : null,
            occurredAt: $postedAt,
            fxRate: $converted === null ? null : Money::of($converted['rate']),
            baseAmount: $converted === null ? null : Money::of($converted['amount']),
            baseCurrency: $converted === null ? null : $this->fx->baseCurrency(),
            allocationStatus: $mapping === null ? 'unallocated' : 'not_applicable',
        ));
        SalesChannelEvent::where('account_id', $account->id)->whereIn('dedupe_key', $identities)->where('outcome', self::PENDING)->update(['outcome' => self::POSTED]);

        return $outcome->wroteSomething();
    }
}
