<?php

namespace App\Services\Address;

use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives old addresses their region and city where that is certain, and nowhere else.
 *
 * Certain means what LegacyCityMatcher calls Matched: an alias an administrator confirmed, or exactly one registered city of that name.
 * A small place that merely has the name, an ambiguous name, a state text that points at another region, or a city that is not in the list are counted and left alone for a
 * person to decide (see LegacyReview), as is a match with a city that has been switched off. Only region_id and city_id are written,
 * only to addresses that have no city yet, so a second run finds nothing to do and nothing else about an address ever changes.
 */
class LegacyBackfill
{
    public const STATUSES = ['matched', 'unconfirmed', 'ambiguous', 'conflict', 'none', 'inactive'];

    /** @var array<string, CityMatch> */
    private array $readings = [];

    public function __construct(
        private readonly SavedSaudiAddresses $addresses,
        private readonly LegacyCityMatcher $matcher,
        private readonly AuditLogger $audit,
    ) {
    }

    /** What the matcher says about this typed city and state; asked once for every pair of texts that read the same. */
    public function matchOf(LegacyPair $pair): CityMatch
    {
        return $this->readings[$pair->readingKey()] ??= $this->matcher->match(cityText: $pair->city, regionText: $pair->state);
    }

    /**
     * What a dry run finds, unless there are more distinct typed texts than the limit: a page must not read thousands of them on every
     * visit, so it gets null and points at the command instead.
     *
     * @return array{applied: bool, rows: array<string, int>, texts: array<string, int>, updated: int}|null
     */
    public function plan(?int $textLimit = null): ?array
    {
        $pairs = $this->addresses->pairs();

        return $textLimit !== null && $pairs->count() > $textLimit ? null : $this->run(apply: false, pairs: $pairs);
    }

    /**
     * Reads every old Saudi address and, when asked to apply, writes the certain matches.
     *
     * @param Collection<int, LegacyPair>|null $pairs the typed texts to read, when the caller has them already
     * @return array{applied: bool, rows: array<string, int>, texts: array<string, int>, updated: int} addresses and distinct typed texts per outcome
     */
    public function run(bool $apply, ?Collection $pairs = null): array
    {
        $report = ['applied' => $apply, 'rows' => array_fill_keys(self::STATUSES, 0), 'texts' => array_fill_keys(self::STATUSES, 0), 'updated' => 0];
        if (!AddressSchema::ready()) {
            return $report;
        }

        $texts = array_fill_keys(self::STATUSES, []);
        foreach ($pairs ?? $this->addresses->pairs() as $pair) {
            $match = $this->matchOf($pair);
            $status = $this->statusOf($match);
            $report['rows'][$status] += $pair->rows;
            $texts[$status][$pair->readingKey()] = true;

            if ($apply && $status === 'matched') {
                $report['updated'] += DB::transaction(fn (): int => $this->addresses->assignTypedAs(city: $pair->city, state: $pair->state, target: $match->city));
            }
        }
        $report['texts'] = array_map('count', $texts);

        if ($apply && $report['updated'] > 0) {
            $this->audit->record(action: 'address.legacy_backfilled', after: ['updated' => $report['updated'], 'rows' => $report['rows']]);
        }

        return $report;
    }

    /** The outcome the report counts a match under: a unique match with a city or region that is switched off is not applied. */
    public function statusOf(CityMatch $match): string
    {
        if (!$match->matched()) {
            return $match->status->value;
        }

        return $match->city->is_active && $match->city->region?->is_active === true ? 'matched' : 'inactive';
    }
}
