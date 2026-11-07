<?php

namespace App\Services\Fulfillment\Data;

use App\Enums\Fulfillment\CityResolutionStatus;
use App\Models\ProviderCity;

/**
 * The provider's city for an order's address, or the reason there is none yet.
 *
 * A held order keeps this as its detail: a machine-readable code, a bar, and the city NAME — never address text:
 *
 *   no_mapping|الرياض                      no approved mapping and no candidate known
 *   pending_review|الرياض                  one candidate waits for a person
 *   ambiguous:36189,230194|الرياض          several candidates; a person chooses
 *   provider_city_missing|الرياض           the approved city is no longer in the provider's list
 *
 * A store that has not migrated the mapping tables keeps the old detail: the city as the customer typed it.
 */
final readonly class CityResolution
{
    public const NO_MAPPING = 'no_mapping';

    public const PENDING_REVIEW = 'pending_review';

    public const AMBIGUOUS = 'ambiguous';

    public const PROVIDER_CITY_MISSING = 'provider_city_missing';

    private const MAX_NAME_LENGTH = 80;

    private const MAX_LISTED_CANDIDATES = 10;

    /** @param list<string> $candidateIds */
    private function __construct(
        public CityResolutionStatus $status,
        public ?ProviderCity $city,
        public array $candidateIds,
        public ?int $addressCityId,
        private string $detail,
    ) {
    }

    public static function resolved(ProviderCity $city, ?int $addressCityId = null): self
    {
        return new self(status: CityResolutionStatus::Resolved, city: $city, candidateIds: [], addressCityId: $addressCityId, detail: '');
    }

    public static function unmapped(string $cityName, ?int $addressCityId = null): self
    {
        return self::held(status: CityResolutionStatus::Unmapped, code: self::NO_MAPPING, cityName: $cityName, addressCityId: $addressCityId);
    }

    public static function providerCityMissing(string $cityName, int $addressCityId): self
    {
        return self::held(status: CityResolutionStatus::Unmapped, code: self::PROVIDER_CITY_MISSING, cityName: $cityName, addressCityId: $addressCityId);
    }

    public static function pendingReview(string $cityName, int $addressCityId, string $candidateId): self
    {
        return self::held(status: CityResolutionStatus::PendingReview, code: self::PENDING_REVIEW, cityName: $cityName, addressCityId: $addressCityId, candidateIds: [$candidateId]);
    }

    /** @param list<string> $candidateIds */
    public static function ambiguous(string $cityName, int $addressCityId, array $candidateIds): self
    {
        $ids = array_map(static fn (string $id): string => (string) preg_replace('/[,|\s]+/u', '', $id), $candidateIds);
        $listed = implode(',', array_slice($ids, 0, self::MAX_LISTED_CANDIDATES));

        return self::held(status: CityResolutionStatus::Ambiguous, code: self::AMBIGUOUS . ':' . $listed, cityName: $cityName, addressCityId: $addressCityId, candidateIds: $ids);
    }

    /** The store has not migrated the mapping tables: nothing changes, including what a hold says. */
    public static function unmappedAsBefore(string $typedCity): self
    {
        return new self(status: CityResolutionStatus::Unmapped, city: null, candidateIds: [], addressCityId: null, detail: trim($typedCity));
    }

    public function isResolved(): bool
    {
        return $this->status === CityResolutionStatus::Resolved;
    }

    public function holdDetail(): string
    {
        return $this->detail;
    }

    /**
     * @return array{code: string, candidate_ids: list<string>, city: string}|null null for a detail in the old form (the city as typed)
     */
    public static function parseDetail(?string $detail): ?array
    {
        if ($detail === null || preg_match('/^(no_mapping|pending_review|provider_city_missing|ambiguous:([^|,]+(?:,[^|,]+)*))\|(.*)$/su', $detail, $match) !== 1) {
            return null;
        }

        $code = str_starts_with($match[1], self::AMBIGUOUS . ':') ? self::AMBIGUOUS : $match[1];

        return ['code' => $code, 'candidate_ids' => ($match[2] ?? '') === '' ? [] : explode(',', $match[2]), 'city' => $match[3]];
    }

    /** @param list<string> $candidateIds */
    private static function held(CityResolutionStatus $status, string $code, string $cityName, ?int $addressCityId, array $candidateIds = []): self
    {
        return new self(status: $status, city: null, candidateIds: $candidateIds, addressCityId: $addressCityId, detail: $code . '|' . self::plainName($cityName));
    }

    /** A name is one short line without the separator: whatever a customer typed as a "city" is cut down to that. */
    private static function plainName(string $name): string
    {
        $line = trim((string) preg_replace('/[\x00-\x1F\x7F|\s]+/u', ' ', $name));

        return mb_substr($line, 0, self::MAX_NAME_LENGTH);
    }
}
