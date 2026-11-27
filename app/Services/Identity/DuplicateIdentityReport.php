<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many customer accounts hold one identity, counted on the live table (A2-01).
 *
 * `users.phone` and `users.email` carry NO unique key in the installation dump, and the dump is not in this repository, so nothing here can
 * prove the key exists. What can be proved is the other half of the criterion: whether a duplicate exists at all. This counts them, and a
 * platform it reports zero for is one where a key could be added without an upgrade aborting -- which is the question A2-01, A2-06 and
 * M2-04 all wait on, and which is the owner's to decide once the figures are in front of them.
 *
 * Two questions per identity, because they have different answers and different remedies:
 *
 *  - the EXACT STRING held twice. A key on the raw column would refuse this, and a platform with any of these cannot have one added until
 *    somebody merges the accounts. The comparison is the DATABASE's, through a `GROUP BY` on the column, so what is counted is what the
 *    engine's own collation would have refused -- the answer a unique key would give, on the engine that would enforce it, and not PHP's
 *    opinion of it;
 *  - the same IDENTITY held twice across spellings. `05XX`, `+9665XX` and `00966 5X` are three strings and one number;
 *    `layla@example.test` and `layla@example.test.` are two strings and one mailbox. No key on the raw column refuses these, which is why
 *    `canonical_phone` and `canonical_email` exist, and the figure is what turning either of those switches on would start refusing. The
 *    comparison is PHP's, because the canonical rule is PHP's: `CustomerPhone::canonical()` and `CustomerEmail::canonical()`, read and not
 *    restated, so the report and the gate cannot disagree about what one identity is.
 *
 * **NOTHING IT RETURNS IS AN IDENTITY.** A duplicate group is reported as a count and the row ids that hold it, never as the number or the
 * address: the report is meant to be run on a live platform and read in a terminal, pasted into a ticket and kept in a log, and a list of
 * customers' telephone numbers is not a thing to leave in any of those places. The row ids are enough to act on, because merging two
 * accounts is done by id.
 *
 * It reads and writes nothing. Every count is a SELECT.
 */
class DuplicateIdentityReport
{
    /** How many duplicate groups are listed per question. A platform with more than this has a merge project, not a list to read. */
    public const GROUPS_LISTED = 25;

    private const CHUNK = 2000;

    /**
     * @return array{
     *     migrated: bool,
     *     accounts: int,
     *     phone: array{column: string, unique_key: ?string, filled: int, exact: array{groups: int, accounts: int, listed: list<list<int>>}, canonical: array{groups: int, accounts: int, listed: list<list<int>>, unjudged: int}},
     *     email: array{column: string, unique_key: ?string, filled: int, exact: array{groups: int, accounts: int, listed: list<list<int>>}, canonical: array{groups: int, accounts: int, listed: list<list<int>>, unjudged: int}},
     *     clean: bool
     * }
     */
    public function report(?string $servedMarket = null): array
    {
        if (!Schema::hasTable('users')) {
            return ['migrated' => false, 'accounts' => 0, 'phone' => $this->emptySide('phone'), 'email' => $this->emptySide('email'), 'clean' => false];
        }

        $phone = $this->side(column: 'phone', canonical: static fn (string $raw): ?string => CustomerPhone::canonical($raw, $servedMarket));
        $email = $this->side(column: 'email', canonical: static fn (string $raw): ?string => CustomerEmail::canonical($raw));

        return [
            'migrated' => true,
            'accounts' => (int) DB::table('users')->count(),
            'phone' => $phone,
            'email' => $email,
            'clean' => $this->isClean($phone) && $this->isClean($email),
        ];
    }

    /** @param array<string, mixed> $side */
    private function isClean(array $side): bool
    {
        return $side['exact']['groups'] === 0 && $side['canonical']['groups'] === 0;
    }

    /**
     * @param callable(string): ?string $canonical
     * @return array<string, mixed>
     */
    private function side(string $column, callable $canonical): array
    {
        return [
            'column' => $column,
            'unique_key' => $this->uniqueKeyOn($column),
            'filled' => (int) DB::table('users')->whereNotNull($column)->where($column, '<>', '')->count(),
            'exact' => $this->exactDuplicates($column),
            'canonical' => $this->canonicalDuplicates($column, $canonical),
        ];
    }

    /** The name of a unique key that covers this column alone, or null when the table has none. */
    private function uniqueKeyOn(string $column): ?string
    {
        foreach (Schema::getIndexes('users') as $index) {
            if (($index['unique'] ?? false) && array_map('strtolower', (array) ($index['columns'] ?? [])) === [$column]) {
                return (string) $index['name'];
            }
        }

        return null;
    }

    /**
     * The groups the ENGINE sees as one value. Compared in SQL on purpose: a unique key would be enforced by the engine's collation, so
     * what a key would refuse is what the engine groups, whatever PHP thinks of the same two strings.
     *
     * @return array{groups: int, accounts: int, listed: list<list<int>>}
     */
    private function exactDuplicates(string $column): array
    {
        $rows = DB::table('users')
            ->select($column)
            ->selectRaw('count(*) as accounts')
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->groupBy($column)
            ->havingRaw('count(*) > 1')
            ->get();

        $listed = [];
        foreach ($rows->take(self::GROUPS_LISTED) as $row) {
            $listed[] = DB::table('users')->where($column, $row->{$column})->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        }

        return [
            'groups' => $rows->count(),
            'accounts' => (int) $rows->sum(static fn (object $row): int => (int) $row->accounts),
            'listed' => $listed,
        ];
    }

    /**
     * The groups that are one identity across spellings, judged by the same canonical rule the gates use.
     *
     * `unjudged` is the accounts whose value the rule cannot reduce to an identity at all -- a number of a country the platform has no
     * numbering plan for, a value that is not an address. They are reported rather than counted as unique: nothing proves they are not
     * duplicates of each other, and a key would compare their raw strings, which `exact` already covers.
     *
     * @param callable(string): ?string $canonical
     * @return array{groups: int, accounts: int, listed: list<list<int>>, unjudged: int}
     */
    private function canonicalDuplicates(string $column, callable $canonical): array
    {
        /** @var array<string, list<int>> $byIdentity */
        $byIdentity = [];
        $unjudged = 0;

        DB::table('users')
            ->select('id', $column)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($rows) use ($column, $canonical, &$byIdentity, &$unjudged): void {
                foreach ($rows as $row) {
                    $key = $canonical((string) $row->{$column});
                    if ($key === null) {
                        $unjudged++;

                        continue;
                    }
                    $byIdentity[$key][] = (int) $row->id;
                }
            });

        $groups = array_values(array_filter($byIdentity, static fn (array $ids): bool => count($ids) > 1));

        return [
            'groups' => count($groups),
            'accounts' => array_sum(array_map('count', $groups)),
            'listed' => array_slice($groups, 0, self::GROUPS_LISTED),
            'unjudged' => $unjudged,
        ];
    }

    /** @return array<string, mixed> */
    private function emptySide(string $column): array
    {
        return [
            'column' => $column,
            'unique_key' => null,
            'filled' => 0,
            'exact' => ['groups' => 0, 'accounts' => 0, 'listed' => []],
            'canonical' => ['groups' => 0, 'accounts' => 0, 'listed' => [], 'unjudged' => 0],
        ];
    }
}
