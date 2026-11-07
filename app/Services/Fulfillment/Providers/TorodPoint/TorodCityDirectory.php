<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

use App\Enums\Fulfillment\CitySyncVerdict;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\AddressCity;
use App\Models\ProviderCity;
use App\Models\ProviderCitySyncRun;
use App\Services\Address\AddressText;
use App\Services\Fulfillment\ProviderCityMappingService;
use App\Services\Fulfillment\Support\ArabicText;
use App\Services\Fulfillment\Support\FieldMap;
use App\Services\Fulfillment\Support\HttpResult;
use App\Services\Fulfillment\Support\PiiRedactor;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the cached copy of Torod Point's city list, and says how much of the provider's list that copy is.
 *
 * Only the Lastmile list's `search` filter is documented. How `returnAll` behaves, whether it pages, and under which names is not,
 * and the first version of this sync stored whatever the first response held — ten cities — and called it done. So a reading is
 * judged, never assumed:
 *
 *  1. one request with `returnAll=true`, as before;
 *  2. when that response does not settle it (it states a larger total or more pages, or holds fewer than COMPLETE_FLOOR cities, which
 *     no national delivery list does), the pages are walked with `page` and `pageSize` from the first one, for as long as each page
 *     brings cities the walk has not seen, and never past the page ceiling or the time budget;
 *  3. everything is read first and written afterwards, in one transaction, in chunks — and only ever added or updated, never deleted.
 *
 * The verdict is `full` only on evidence. A list shorter than COMPLETE_FLOOR is never proven by what the provider says about itself
 * (a truncated answer states its own total and its own last page truthfully): only a page walk that ended on an empty page and
 * contained the first response proves it. From COMPLETE_FLOOR on, in this order of strength: the provider's own total is reached; or
 * the response ends the list by its own flag; or (the weakest, and not a proof) nothing says more exists. Anything else is `partial`,
 * with the reason, and a partial reading changes nothing but what it adds: no city is marked missing, no success is recorded. A
 * reading on the weakest evidence adds and updates cities too, but never marks one missing and never approves anything.
 */
class TorodCityDirectory
{
    public const PAGE_SIZE = 100;

    public const MAX_PAGES = 100;

    /**
     * Fewer distinct cities than this cannot be a country's delivery list, so a reading below it is walked page by page and is never
     * complete on its own; at least this many, with no sign of more, is accepted as complete (the weakest of the kinds of evidence).
     */
    public const COMPLETE_FLOOR = 1000;

    private const PROVIDER = ProviderCode::TorodPoint->value;

    private const EVIDENCE_TOTAL = 'envelope_total';

    private const EVIDENCE_LAST_PAGE = 'envelope_last_page';

    private const EVIDENCE_EMPTY_PAGE = 'empty_page';

    private const EVIDENCE_FLOOR = ProviderCitySyncRun::EVIDENCE_HEURISTIC;

    private const STORE_CHUNK = 200;

    /** One writer of the city list at a time (the console, the weekly schedule and the admin button all reach it), and for no longer than a sync can take. */
    private const SYNC_LOCK = 'provider-city-sync:torod_point';

    private const SYNC_LOCK_SECONDS = 600;

    private const ALREADY_RUNNING = 'sync_already_running';

    private const MAX_ID_LENGTH = 32;

    private const MAX_REASON_LENGTH = 250;

    private const TOTAL_KEYS = ['totalElements', 'totalCount', 'total', 'totalItems', 'totalRecords', 'recordsTotal', 'total_count', 'total_elements', 'total_items', 'total_records'];

    private const PAGE_COUNT_KEYS = ['totalPages', 'pageCount', 'total_pages', 'page_count', 'lastPage', 'last_page'];

    private const LAST_PAGE_KEYS = ['last', 'isLast', 'is_last'];

    private const HAS_MORE_KEYS = ['hasNext', 'hasNextPage', 'hasMore', 'has_next', 'has_next_page', 'has_more'];

    private const ENVELOPES = ['data', 'meta', 'pagination', 'paging', 'page', 'pageInfo'];

    private const UPDATED_COLUMNS = [
        'name_ar', 'name_en', 'region_external_id', 'normalized_ar', 'normalized_en', 'raw', 'synced_at',
        'region_name_ar', 'region_name_en', 'prefix', 'is_selected', 'last_seen_at', 'missing_since',
    ];

    public function __construct(
        private readonly TorodLastmileClient $client,
        private readonly ProviderSyncRecorder $sync,
        private readonly ProviderCityMappingService $mappings,
    ) {
    }

    /**
     * @return array{ok: bool, count?: int, verdict: string, reason?: ?string, evidence?: string, pages?: int, expected_total?: ?int, approved?: int, proposed?: int, error?: string}
     */
    public function sync(): array
    {
        $result = Cache::lock(self::SYNC_LOCK, self::SYNC_LOCK_SECONDS)->get(fn (): array => $this->syncExclusively());

        return $result === false ? ['ok' => false, 'error' => self::ALREADY_RUNNING, 'verdict' => CitySyncVerdict::Failed->value] : $result;
    }

    /** @return array{ok: bool, count?: int, verdict: string, reason?: ?string, evidence?: string, pages?: int, expected_total?: ?int, approved?: int, proposed?: int, error?: string} */
    private function syncExclusively(): array
    {
        $startedAt = now();
        $this->sync->attempted(self::PROVIDER, ProviderSyncRecorder::CITIES);

        $read = $this->read();
        if ($read->distinct() === 0 && $read->failure !== null) {
            return $this->failed(read: $read, startedAt: $startedAt);
        }

        [$verdict, $reason, $evidence] = $this->judge($read);
        $stored = $this->keep(read: $read, verdict: $verdict, reason: $reason, evidence: $evidence, startedAt: $startedAt);

        $stats = [
            'cities' => $stored, 'verdict' => $verdict->value, 'evidence' => $evidence, 'pages' => $read->requests, 'fetched' => $read->fetched,
            'distinct' => $read->distinct(), 'expected_total' => $read->expectedTotal, 'regions_seen' => $read->regionsSeen(),
        ];
        $verdict === CitySyncVerdict::Full
            ? $this->sync->succeeded(self::PROVIDER, ProviderSyncRecorder::CITIES, $stats)
            : $this->sync->failed(self::PROVIDER, ProviderSyncRecorder::CITIES, 'partial: ' . $reason, $stats);

        return [
            'ok' => true, 'count' => $stored, 'verdict' => $verdict->value, 'reason' => $reason, 'evidence' => $evidence,
            'pages' => $read->requests, 'expected_total' => $read->expectedTotal,
        ] + $this->refreshMappings();
    }

    /**
     * Asks the provider for the cities named like one of ours (`search` is the one filter its documentation promises), caches what it
     * finds, and proposes the ones whose name is exactly ours as pending mappings for a person to decide.
     *
     * @param bool $apply false only asks (a read) and reports; nothing is written
     * @return array{ok: bool, error?: string, found?: int, matches?: list<string>, proposed?: ?int, applied?: bool}
     */
    public function discoverForCity(AddressCity $city, bool $apply = true): array
    {
        $found = [];
        $failures = [];
        foreach ($this->searchTerms($city) as $term) {
            $result = $this->client->cities(search: $term, returnAll: false);
            $items = $this->listOf($result);
            if ($items === null) {
                $failures[] = $this->failureOf($result);

                continue;
            }

            foreach ($items as $item) {
                if (isset($item['id']) && is_scalar($item['id'])) {
                    $found[(string) $item['id']] = $item;
                }
            }
        }

        if ($found === [] && $failures !== []) {
            return ['ok' => false, 'error' => PiiRedactor::scrub(implode('; ', array_unique($failures)), self::MAX_REASON_LENGTH)];
        }

        $proposed = null;
        if ($apply) {
            $proposed = Cache::lock(self::SYNC_LOCK, self::SYNC_LOCK_SECONDS)->get(function () use ($found, $city): int {
                $this->store(items: $found, markMissing: false);

                return $this->mappings->proposeCandidates(provider: self::PROVIDER, cities: collect([$city]), method: ProviderCityMappingService::METHOD_DISCOVERY);
            });
            if ($proposed === false) {
                return ['ok' => false, 'error' => self::ALREADY_RUNNING];
            }
        }

        return ['ok' => true, 'found' => count($found), 'matches' => $this->namedLike(city: $city, found: $found), 'proposed' => $proposed, 'applied' => $apply];
    }

    private function read(): CityListRead
    {
        $read = new CityListRead();
        $deadline = microtime(true) + $this->setting('max_seconds', app()->runningInConsole() ? 600 : 45);

        $first = $this->client->cities();
        $items = $this->listOf($first);
        if ($items === null) {
            $read->noteEmptyRequest(page: null, status: $first->status);
            $read->failure = $this->failureOf($first);

            return $read;
        }

        $read->addFirstResponse(items: $items, envelope: $this->envelope($first->json), status: $first->status);
        if ($read->moreIsExpected() || $read->distinct() < $this->completeFloor()) {
            $this->readPages(read: $read, deadline: $deadline);
        }

        return $read;
    }

    /** Walks the pages from the first, stopping on whatever proves there is nothing more to learn from asking again. */
    private function readPages(CityListRead $read, float $deadline): void
    {
        $maxPages = $this->setting('max_pages', self::MAX_PAGES);
        for ($page = 1; $page <= $maxPages; $page++) {
            if (microtime(true) >= $deadline) {
                $read->deadline = true;

                return;
            }

            $result = $this->client->cities(page: $page, pageSize: self::PAGE_SIZE);
            $items = $this->listOf($result);
            if ($items === null) {
                $read->noteEmptyRequest(page: $page, status: $result->status);
                $read->failure = "page {$page} failed: " . $this->failureOf($result);

                return;
            }
            if ($items === []) {
                $read->noteEmptyRequest(page: $page, status: $result->status);
                $read->emptyPage = true;

                return;
            }
            if ($read->addPage(page: $page, items: $items, envelope: $this->envelope($result->json), status: $result->status) === 0) {
                $read->repeated = true;

                return;
            }
        }

        $read->ceiling = true;
    }

    /** @return array{0: CitySyncVerdict, 1: ?string, 2: string} verdict, the reason it is not full, and the evidence it rests on */
    private function judge(CityListRead $read): array
    {
        $distinct = $read->distinct();
        $total = $read->expectedTotal;

        return match (true) {
            $distinct === 0 => [CitySyncVerdict::Partial, 'the provider returned an empty list', 'none'],
            $read->failure !== null => [CitySyncVerdict::Partial, "stopped early, {$distinct} cities read: {$read->failure}", 'none'],
            $read->ceiling => [CitySyncVerdict::Partial, "stopped at the {$this->setting('max_pages', self::MAX_PAGES)}-page ceiling with {$distinct} cities; more may exist", 'none'],
            $read->deadline => [CitySyncVerdict::Partial, "stopped on the time budget after {$read->requests} requests with {$distinct} cities", 'none'],
            $total !== null && $distinct < $total => [CitySyncVerdict::Partial, "{$distinct} of {$total} returned", 'none'],
            $read->emptyPage && !$read->pagesCoverFirstResponse() => [CitySyncVerdict::Partial, "{$distinct} returned; the pages do not contain the first response, so they cannot confirm it is complete", 'none'],
            $read->emptyPage => [CitySyncVerdict::Full, null, self::EVIDENCE_EMPTY_PAGE],
            $distinct < $this->completeFloor() => [CitySyncVerdict::Partial, "{$distinct} returned, paging not honoured", 'none'],
            $total !== null => [CitySyncVerdict::Full, null, self::EVIDENCE_TOTAL],
            $read->endIsStated() => [CitySyncVerdict::Full, null, self::EVIDENCE_LAST_PAGE],
            $read->moreIsExpected() => [CitySyncVerdict::Partial, "{$distinct} returned; the provider reports more pages than were read", 'none'],
            default => [CitySyncVerdict::Full, null, self::EVIDENCE_FLOOR],
        };
    }

    private function completeFloor(): int
    {
        return $this->setting('complete_floor', self::COMPLETE_FLOOR);
    }

    /** Writes what was read and the run that read it, together: both happen or neither does. */
    private function keep(CityListRead $read, CitySyncVerdict $verdict, ?string $reason, string $evidence, CarbonInterface $startedAt): int
    {
        return DB::transaction(function () use ($read, $verdict, $reason, $evidence, $startedAt): int {
            $stored = $this->store(items: $read->items(), markMissing: $verdict === CitySyncVerdict::Full && $evidence !== self::EVIDENCE_FLOOR);
            $this->recordRun(read: $read, verdict: $verdict, reason: $reason, evidence: $evidence, startedAt: $startedAt);

            return $stored;
        });
    }

    /** @return array{ok: false, error: string, verdict: string} */
    private function failed(CityListRead $read, CarbonInterface $startedAt): array
    {
        $message = PiiRedactor::scrub((string) $read->failure, self::MAX_REASON_LENGTH);
        $this->recordRun(read: $read, verdict: CitySyncVerdict::Failed, reason: $message, evidence: 'none', startedAt: $startedAt);
        $this->sync->failed(self::PROVIDER, ProviderSyncRecorder::CITIES, $message);

        return ['ok' => false, 'error' => $message, 'verdict' => CitySyncVerdict::Failed->value];
    }

    private function recordRun(CityListRead $read, CitySyncVerdict $verdict, ?string $reason, string $evidence, CarbonInterface $startedAt): void
    {
        ProviderCitySyncRun::create([
            'provider' => self::PROVIDER,
            'verdict' => $verdict->value,
            'pages' => $read->requests,
            'fetched' => $read->fetched,
            'distinct_ids' => $read->distinct(),
            'expected_total' => $read->expectedTotal,
            'regions_seen' => $read->regionsSeen(),
            'request' => ['strategy' => 'return_all_then_pages', 'page_size' => self::PAGE_SIZE, 'evidence' => $evidence, 'requests' => $read->trace],
            'reason' => $reason === null ? null : mb_substr($reason, 0, self::MAX_REASON_LENGTH),
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }

    /**
     * After a reading is kept: approve the cities that now have exactly one candidate (only a reading whose completeness is proven allows
     * it), and propose the candidates of the rest for a person.
     *
     * @return array{approved: int, proposed: int}
     */
    private function refreshMappings(): array
    {
        return [
            'approved' => $this->mappings->autoApproveUnique(self::PROVIDER)['approved'],
            'proposed' => $this->mappings->proposeCandidates(self::PROVIDER),
        ];
    }

    /**
     * Adds and updates cities, in one transaction and in chunks. A reading whose completeness is proven first marks every city the
     * provider lists no longer as missing (the ones this reading saw have the mark cleared by the update that follows); nothing is
     * ever deleted.
     *
     * @param array<string, array<array-key, mixed>> $items the provider's records by id
     * @return int the cities written
     */
    private function store(array $items, bool $markMissing): int
    {
        $now = now();
        ksort($items, SORT_STRING);
        $rows = array_values(array_filter(array_map(fn (array $item): ?array => $this->row(item: $item, now: $now), $items)));

        DB::transaction(function () use ($rows, $markMissing, $now): void {
            if ($markMissing) {
                ProviderCity::query()->where('provider', self::PROVIDER)->whereNull('missing_since')->update(['missing_since' => $now]);
            }
            foreach (array_chunk($rows, self::STORE_CHUNK) as $chunk) {
                ProviderCity::upsert($chunk, ['provider', 'external_id'], self::UPDATED_COLUMNS);
            }
        });

        return count($rows);
    }

    /**
     * @param array<array-key, mixed> $item
     * @return array<string, mixed>|null
     */
    private function row(array $item, CarbonInterface $now): ?array
    {
        $id = isset($item['id']) && is_scalar($item['id']) ? trim((string) $item['id']) : '';
        if ($id === '' || strlen($id) > self::MAX_ID_LENGTH) {
            return null;
        }

        $arabic = $this->text($item['arabicName'] ?? null);
        $english = $this->text($item['englishName'] ?? $item['name'] ?? null);

        return [
            'provider' => self::PROVIDER,
            'external_id' => $id,
            'name_ar' => $arabic,
            'name_en' => $english,
            'region_external_id' => $this->text($item['regionId'] ?? null, self::MAX_ID_LENGTH),
            'normalized_ar' => $arabic === null ? null : ArabicText::normalize($arabic),
            'normalized_en' => $english === null ? null : ArabicText::normalize($english),
            'region_name_ar' => $this->text($item['arabicRegionName'] ?? null),
            'region_name_en' => $this->text($item['regionName'] ?? null),
            'prefix' => $this->text($item['prefix'] ?? null, 32),
            'is_selected' => $this->flag($item['isSelected'] ?? null),
            'raw' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'synced_at' => $now,
            'last_seen_at' => $now,
            'missing_since' => null,
        ];
    }

    /** @return list<array<array-key, mixed>>|null the records of a response, or null when it is not a successful list */
    private function listOf(HttpResult $result): ?array
    {
        return $result->ok() && $result->json !== null ? FieldMap::extractItems($result->json) : null;
    }

    private function failureOf(HttpResult $result): string
    {
        return $result->ok() ? 'The city list response contains no list.' : PiiRedactor::scrub($result->message(), self::MAX_REASON_LENGTH);
    }

    /**
     * What a response says about its own paging, wherever the usual envelopes put it. Anything it does not say stays null.
     *
     * @param array<array-key, mixed>|null $json
     * @return array{total: ?int, pages: ?int, last: ?bool}
     */
    private function envelope(?array $json): array
    {
        $facts = ['total' => null, 'pages' => null, 'last' => null];
        foreach ($this->containers($json ?? []) as $container) {
            $facts['total'] ??= $this->firstCount($container, self::TOTAL_KEYS);
            $facts['pages'] ??= $this->firstCount($container, self::PAGE_COUNT_KEYS);
            $facts['last'] ??= $this->lastFlag($container);
        }

        return $facts;
    }

    /**
     * @param array<array-key, mixed> $json
     * @return list<array<array-key, mixed>> the response itself, and the objects inside it that conventionally carry paging facts
     */
    private function containers(array $json): array
    {
        $containers = [$json];
        foreach (self::ENVELOPES as $key) {
            if (isset($json[$key]) && is_array($json[$key]) && !array_is_list($json[$key])) {
                $containers[] = $json[$key];
            }
        }

        return $containers;
    }

    /**
     * @param array<array-key, mixed> $container
     * @param list<string> $keys
     */
    private function firstCount(array $container, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($container[$key]) && is_numeric($container[$key]) && (int) $container[$key] >= 0) {
                return (int) $container[$key];
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $container */
    private function lastFlag(array $container): ?bool
    {
        foreach (self::LAST_PAGE_KEYS as $key) {
            if (isset($container[$key]) && is_bool($container[$key])) {
                return $container[$key];
            }
        }
        foreach (self::HAS_MORE_KEYS as $key) {
            if (isset($container[$key]) && is_bool($container[$key])) {
                return !$container[$key];
            }
        }

        return null;
    }

    /** @return list<string> the names to ask the provider about: English first, then Arabic */
    private function searchTerms(AddressCity $city): array
    {
        return array_values(array_unique(array_filter(
            [trim((string) $city->name_en), trim((string) $city->name_ar)],
            static fn (string $term): bool => $term !== '',
        )));
    }

    /**
     * @param array<string, array<array-key, mixed>> $found
     * @return list<string> the provider's ids of the found cities whose name is exactly one of the city's names
     */
    private function namedLike(AddressCity $city, array $found): array
    {
        $wanted = array_filter([AddressText::key($city->name_ar), AddressText::key($city->name_en)], static fn (string $key): bool => $key !== '');
        $matches = [];
        foreach ($found as $id => $item) {
            $names = [$this->text($item['arabicName'] ?? null), $this->text($item['englishName'] ?? $item['name'] ?? null)];
            if (array_intersect($wanted, array_map(static fn (?string $name): string => AddressText::key($name), $names)) !== []) {
                $matches[] = (string) $id;
            }
        }

        return $matches;
    }

    private function text(mixed $value, int $maxLength = 191): ?string
    {
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $maxLength);
    }

    private function flag(mixed $value): ?bool
    {
        return is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
    }

    private function setting(string $key, int $default): int
    {
        return (int) config('fulfillment.providers.' . self::PROVIDER . '.cities.' . $key, $default);
    }
}
