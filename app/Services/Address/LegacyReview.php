<?php

namespace App\Services\Address;

use App\Enums\Address\CityMatchStatus;
use App\Models\AddressCity;
use App\Models\AddressCityAlias;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The old free-text cities of saved Saudi addresses, grouped by what they say, for a person to settle.
 *
 * A decision names ONE city for ONE typed text: it is remembered as an alias (so the same spelling is understood from then on) and
 * given to every saved address typed that way, in one step. What the customer typed (city, state), the postal code and the National
 * Address are never touched, and nothing is guessed: a text nobody has decided on stays as it was.
 */
class LegacyReview
{
    public const ALIAS_SOURCE = 'legacy_review';

    private const ALIAS_MAX_LENGTH = 191;

    public function __construct(
        private readonly SavedSaudiAddresses $addresses,
        private readonly LegacyBackfill $backfill,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param string|null $search part of a city text, in the spelling people type it
     * @return Collection<int, LegacyCityGroup> the texts with the most addresses first, without the matcher's reading
     */
    public function groups(?string $search = null): Collection
    {
        $wanted = AddressText::key($search);

        return $this->addresses->pairs()
            ->reject(static fn (LegacyPair $pair): bool => $pair->cityKey === '')
            ->groupBy(static fn (LegacyPair $pair): string => $pair->cityKey)
            ->map(static fn (Collection $pairs, string $key): LegacyCityGroup => new LegacyCityGroup(key: $key, pairs: $pairs->all()))
            ->when($wanted !== '', static fn (Collection $groups): Collection => $groups->filter(static fn (LegacyCityGroup $group): bool => str_contains($group->key, $wanted)))
            ->sortBy([['rows', 'desc'], ['key', 'asc']])
            ->values();
    }

    /** Old Saudi addresses with no city text at all: there is nothing to decide on, so the customer completes them. */
    public function blankRows(): int
    {
        return (int) $this->addresses->pairs()->filter(static fn (LegacyPair $pair): bool => $pair->cityKey === '')->sum('rows');
    }

    /** The group together with what the matcher says about it (asked of the texts on the page, not of the whole list). */
    public function read(LegacyCityGroup $group): LegacyCityGroup
    {
        return $group->withMatch($this->summarise(collect($group->pairs)->map(fn (LegacyPair $pair): CityMatch => $this->backfill->matchOf($pair))));
    }

    /**
     * Confirms a city for a typed text: remembers the spelling and gives the city to every saved address typed that way.
     *
     * @return int addresses that were given the city
     * @throws ValidationException when the text, the city or an earlier decision about this spelling does not allow it
     */
    public function confirm(string $typedText, int $cityId, ?int $adminId = null): int
    {
        $key = AddressText::key($typedText);
        if ($key === '' || mb_strlen($key) > self::ALIAS_MAX_LENGTH) {
            throw ValidationException::withMessages(['text' => [translate('address_legacy_text_invalid')]]);
        }

        $city = AddressCity::query()->with('region')->find($cityId);
        if ($city === null || !$city->is_active || $city->region?->is_active !== true) {
            throw ValidationException::withMessages(['city_id' => [translate('address_legacy_city_unavailable')]]);
        }

        $alias = AddressCityAlias::query()->where('normalized_alias', $key)->first();
        if ($alias !== null && (int) $alias->city_id !== $city->id) {
            throw ValidationException::withMessages(['city_id' => [translate('address_legacy_alias_elsewhere')]]);
        }

        return DB::transaction(function () use ($typedText, $key, $city, $alias, $adminId): int {
            $ids = $this->addresses->idsWithCityKey($key);
            if ($ids === []) {
                throw ValidationException::withMessages(['text' => [translate('address_legacy_text_gone')]]);
            }

            $created = $alias === null;
            $alias ??= AddressCityAlias::create([
                'city_id' => $city->id,
                'alias' => mb_substr(trim($typedText), 0, self::ALIAS_MAX_LENGTH),
                'normalized_alias' => $key,
                'source' => self::ALIAS_SOURCE,
                'created_by' => $adminId,
            ]);

            $updated = $this->addresses->assign(ids: $ids, city: $city);
            $this->audit->record(action: 'address.legacy_city_confirmed', subject: $city, after: ['alias_id' => $alias->id, 'alias_created' => $created, 'addresses' => $updated]);

            return $updated;
        });
    }

    /**
     * One reading for a whole text: the most cautious of the readings of its state variants, with every city any of them found.
     *
     * @param Collection<int, CityMatch> $matches
     */
    private function summarise(Collection $matches): CityMatch
    {
        foreach ([CityMatchStatus::Conflict, CityMatchStatus::Ambiguous] as $status) {
            $found = $matches->filter(static fn (CityMatch $match): bool => $match->status === $status);
            if ($found->isNotEmpty()) {
                return new CityMatch(status: $status, city: null, candidates: $this->cities($found), reason: $found->first()->reason);
            }
        }

        $cities = $this->cities($matches);
        $matched = $matches->every(static fn (CityMatch $match): bool => $match->matched());

        return new CityMatch(
            status: $matched ? CityMatchStatus::Matched : CityMatchStatus::None,
            city: $matched && $cities->count() === 1 ? $cities->first() : null,
            candidates: $cities,
            reason: $matches->first()->reason,
        );
    }

    /**
     * @param Collection<int, CityMatch> $matches
     * @return Collection<int, AddressCity>
     */
    private function cities(Collection $matches): Collection
    {
        return $matches
            ->flatMap(static fn (CityMatch $match): Collection => $match->matched() ? collect([$match->city]) : $match->candidates)
            ->unique('id')
            ->values();
    }
}
