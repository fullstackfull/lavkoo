<?php

namespace App\Services\SalesChannels\Finance;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds the charge a statement row repeats, so that one charge is one cost however many sources report it.
 *
 * A manual statement row meets what the API already gave, in this order:
 *  1. it names what the charge is about (an order and its package, or a settlement) and an API event of the same account has the same shape (fee type, currency, amount to six
 *     decimals) and the same reference: the row is a DUPLICATE of it, recorded as such and not counted. A row that names only an order matches any package of it.
 *  2. it names nothing: it is a duplicate when exactly ONE API event of the account has the same shape on the same day (a day either side); with several it is imported and
 *     marked POSSIBLE_OVERLAP, and so it is with none (the API may simply not have read that day yet), for a person to look at.
 * An API event that a row was recognised as a repeat of is claimed by it: a second identical row finds nothing left to repeat, so two charges of the same amount (a fee per unit
 * of a two-unit order) are two costs while the API only reported one.
 *
 * The other way round (a statement imported first, the API reading the same period later) is the same match from the API event's side: a row that names the same thing stops
 * counting; one that names nothing is only marked.
 */
class ChargeOverlapMatcher
{
    public const NONE = 'none';
    public const DUPLICATE = 'duplicate';
    public const POSSIBLE_OVERLAP = 'possible_overlap';

    /** The statement events that count as a cost: the same fee type can arrive as a fee or as an adjustment. */
    private const COST_EVENT_TYPES = ['finance.fee.%', 'finance.adjustment.%'];

    /** @return array{0: string, 1: SalesChannelEvent|null} what the row is, and the API event it repeats */
    public function forManualRow(SalesChannelAccount $account, ChargeIdentity $identity, CarbonInterface $postedAt): array
    {
        $api = $this->unclaimed($this->costEvents($account, $identity)->where('source', '!=', 'manual'));

        if ($identity->reference !== null) {
            $found = (clone $api)->where(fn (Builder $query) => $identity->namesOnlyAnOrder()
                ? $query->whereRaw('substr(external_reference, 1, ?) = ?', [mb_strlen((string) $identity->reference), $identity->reference])
                : $query->where('external_reference', $identity->reference))->orderBy('id')->first();

            return $found === null ? [self::NONE, null] : [self::DUPLICATE, $found];
        }

        $sameDay = (clone $api)->whereBetween('occurred_at', $this->window($postedAt))->orderBy('id')->limit(2)->get();

        return $sameDay->count() === 1 ? [self::DUPLICATE, $sameDay->first()] : [self::POSSIBLE_OVERLAP, null];
    }

    /**
     * A new API event meets the statement rows already stored. A row that names the same thing is the repeat of this event (DUPLICATE). A row that names nothing and was posted on
     * the same day may be the same charge: it is only marked (POSSIBLE_OVERLAP), because the charge it was posted as (a fee of no order) has to be taken back by a person.
     *
     * @return array{0: string, 1: Collection<int, SalesChannelEvent>} the match, and the statement rows it is about
     */
    public function forApiEvent(SalesChannelAccount $account, SalesChannelEvent $event): array
    {
        $manual = $this->costEvents($account, null, shape: (string) $event->charge_key)->where('source', 'manual')->whereNull('duplicate_of_id');

        if ($event->external_reference !== null) {
            $order = explode('|', $event->external_reference, 2)[0];
            $namedAlike = str_starts_with($event->external_reference, 'settlement:') ? [$event->external_reference] : [$event->external_reference, $order . '|'];
            $found = (clone $manual)->whereIn('external_reference', $namedAlike)->orderBy('id')->first();
            if ($found !== null) {
                return [self::DUPLICATE, new Collection([$found])];
            }
        }
        $sameDay = (clone $manual)->whereNull('external_reference')->whereBetween('occurred_at', $this->window($event->occurred_at))->orderBy('id')->get();

        return $sameDay->isEmpty() ? [self::NONE, new Collection()] : [self::POSSIBLE_OVERLAP, $sameDay];
    }

    /** @return Builder<SalesChannelEvent> the account's fee and adjustment events of one shape */
    private function costEvents(SalesChannelAccount $account, ?ChargeIdentity $identity, ?string $shape = null): Builder
    {
        return SalesChannelEvent::query()
            ->where('account_id', $account->id)
            ->where('charge_key', $shape ?? $identity?->shape)
            ->where('outcome', '!=', SalesChannelEvent::DUPLICATE)
            ->where(static function (Builder $query): void {
                foreach (self::COST_EVENT_TYPES as $type) {
                    $query->orWhere('event_type', 'like', $type);
                }
            });
    }

    /** @param Builder<SalesChannelEvent> $events @return Builder<SalesChannelEvent> */
    private function unclaimed(Builder $events): Builder
    {
        return $events->whereNotIn('id', SalesChannelEvent::query()->whereNotNull('duplicate_of_id')->select('duplicate_of_id'));
    }

    /** @return array{0: CarbonInterface, 1: CarbonInterface} the day and the day either side of it */
    private function window(CarbonInterface $day): array
    {
        return [$day->copy()->startOfDay()->subDay(), $day->copy()->endOfDay()->addDay()];
    }
}
