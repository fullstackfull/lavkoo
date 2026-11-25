<?php

namespace App\Services\Shipping;

use App\Services\AuditLogger;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opening and closing one source-to-destination pair, with the name of whoever did it (H1-01).
 *
 * Opening a border is a commercial and legal act: the storefront begins selling into a country it has to be registered in, whose duty
 * rules apply to its parcels and whose consumer law applies to its customers. So this is not a configuration tidy-up and it is not
 * reversible in silence -- every change writes an audit line with what the pair was and what it is now, and `recorded_by` and
 * `recorded_at` stay on the row itself, so the answer the engine reads carries its own provenance.
 *
 * **Only staff of the SOURCE country may change a pair**, which is the side this platform is answerable for: an employee restricted to
 * Kuwait may open or close what Kuwait sells into and may not touch what Saudi Arabia sells into. The destination is deliberately NOT
 * judged that way -- nobody is an employee of a country the platform does not sell in, so judging it would make every pair unchangeable.
 *
 * A pair whose source or destination the platform has no storefront for is refused rather than stored: a row that will be read one day
 * and believed must not name a market nobody sells in. A source selling into its own country is refused too, for the same reason -- it is
 * not a cross-border pair, it is always allowed, and a row saying otherwise would be read as a rule that cannot be enforced.
 */
final class CrossBorderPairs
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StaffCountryScope $staff,
    ) {
    }

    public function available(): bool
    {
        return Schema::hasTable(CrossBorderPolicy::TABLE);
    }

    /** Whether this employee may change what this market sells into. An unrestricted employee may change any of them. */
    public function mayChange(string $source): bool
    {
        return $this->staff->allows(strtoupper(trim($source)));
    }

    /**
     * Records that this market may, or may not, sell into that country, and returns the row's new state.
     *
     * @throws PairNotRecorded when the pair names a market the platform does not serve, is not a cross-border pair, or belongs to a
     *                         market this employee does not work with
     */
    public function record(string $source, string $destination, bool $open, ?int $adminId = null): bool
    {
        $from = $this->marketNamed($source);
        $to = $this->marketNamed($destination);

        if ($from === $to) {
            throw new PairNotRecorded('a market always sells inside its own country, so there is no pair to open or close');
        }

        if (!$this->mayChange($from)) {
            throw new PairNotRecorded('only staff of the source market may change what it sells into');
        }

        if (!$this->available()) {
            throw new PairNotRecorded('the destination pair table has not been migrated');
        }

        $was = CrossBorderPolicy::allows($from, $to);

        // `created_at` is written only when the row is MADE. `updateOrInsert()` applies its second argument on an update too, so putting
        // it there would move the row's birthday every time somebody opened or closed the pair -- the same mistake the address book made
        // (docs/address/README.md, change 6) and the same reason it is called out here.
        $key = ['source_country' => $from, 'destination_country' => $to];
        $values = ['is_open' => $open, 'recorded_by' => $adminId, 'recorded_at' => now(), 'updated_at' => now()];
        $existing = DB::table(CrossBorderPolicy::TABLE)->where($key)->exists();

        if ($existing) {
            DB::table(CrossBorderPolicy::TABLE)->where($key)->update($values);
        } else {
            DB::table(CrossBorderPolicy::TABLE)->insert($key + $values + ['created_at' => now()]);
        }

        CrossBorderPolicy::forget();

        // The market the line concerns is the SOURCE: the storefront whose selling changed. Recorded through `recordOrFail` because a
        // border opened with nothing saying who opened it is the one state this screen may not reach.
        $this->audit->recordOrFail(
            action: $open ? 'cross_border_pair_opened' : 'cross_border_pair_closed',
            subject: ['type' => CrossBorderPolicy::TABLE, 'id' => null],
            before: ['source' => $from, 'destination' => $to, 'open' => $was],
            after: ['source' => $from, 'destination' => $to, 'open' => $open],
            country: $from,
        );

        return CrossBorderPolicy::allows($from, $to);
    }

    /**
     * Every pair a person has recorded for the markets this employee works with, newest first.
     *
     * @return list<object>
     */
    public function recorded(): array
    {
        if (!$this->available()) {
            return [];
        }

        $rows = DB::table(CrossBorderPolicy::TABLE)->orderBy('source_country')->orderBy('destination_country');
        $bound = $this->staff->boundCountries();

        if ($bound !== null) {
            $rows->whereIn('source_country', $bound === [] ? [''] : $bound);
        }

        return $rows->get()->all();
    }

    /** The code of a market this platform serves, or a refusal. */
    private function marketNamed(string $value): string
    {
        $code = DestinationCountryCode::of($value);

        if ($code === null || !in_array($code, StorefrontResolver::fromConfig()->configuredCountries(), true)) {
            throw new PairNotRecorded('this platform has no storefront for ' . trim($value));
        }

        return $code;
    }
}
