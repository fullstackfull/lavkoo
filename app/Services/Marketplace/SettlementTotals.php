<?php

namespace App\Services\Marketplace;

use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the platform has settled, by the legal entity that owes it and the currency it is drawn in (E-17).
 *
 * A settlement has no market. It has a company, a currency and a period, and the shop it pays belongs to one
 * company at a time -- so a platform summary reports settlements per (company, currency) and never per market, and
 * never over two currencies: a settlement drawn in dinars and one drawn in riyals are two figures, and their sum
 * is a number that is true of neither.
 *
 * **It lives here and not in the platform-admin service for a stated reason.** `vendor_settlements` names the shop
 * it pays in a column the platform-admin area may not mention at all: `ReverseIsolationTest` scans every file of
 * that area for the seller world and stops at the first one that names it. The platform service asks this class
 * for totals, exactly as it asks `ShopIssueCounts` for counts, and the reason the indirection exists is written
 * here rather than left for the next reader to rediscover.
 *
 * `vendor_settlements` has NO MIGRATION in this repository, and `currency` and `legal_entity_id` are columns the
 * installed dump may or may not carry, so each is asked for before it is read and a missing one is answered as
 * "not recorded" rather than as a zero.
 */
#[Scoped]
final class SettlementTotals
{
    public const TABLE = 'vendor_settlements';

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /** @var array<string, bool> */
    private array $columns = [];

    /** Whether this installation keeps settlements at all. */
    public function areRecorded(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /** Whether a settlement says which currency it is drawn in, without which no figure here can be reported. */
    public function recordTheirCurrency(): bool
    {
        return $this->areRecorded() && $this->has(column: 'currency');
    }

    /**
     * The settlements calculated from `$from` up to, not including, `$before`, grouped by company, currency and status.
     *
     * The net is summed in the database and carried as text: a wide decimal read into a float and added is how a
     * financial total comes to differ from the rows it was made of.
     *
     * @return list<array{entity: ?int, currency: ?string, status: string, settlements: int, net: ?string}>
     */
    public function byCompanyAndCurrency(CarbonInterface $from, CarbonInterface $before): array
    {
        if (!$this->areRecorded()) {
            return [];
        }

        $currency = $this->has(column: 'currency') ? 'currency' : 'NULL';
        $entity = $this->has(column: 'legal_entity_id') ? 'legal_entity_id' : 'NULL';
        $moment = $this->has(column: 'calculated_at') ? 'calculated_at' : 'created_at';

        return DB::table(self::TABLE)
            ->where($moment, '>=', $from->format(self::DATE_FORMAT))
            ->where($moment, '<', $before->format(self::DATE_FORMAT))
            ->selectRaw("{$entity} AS entity, {$currency} AS currency, status AS status, COUNT(*) AS settlements, SUM(net_amount) AS net")
            ->groupBy(DB::raw("{$entity}, {$currency}, status"))
            ->get()
            ->map(static fn (object $row): array => [
                'entity' => $row->entity === null ? null : (int) $row->entity,
                'currency' => ($row->currency ?? '') === '' ? null : (string) $row->currency,
                'status' => (string) $row->status,
                'settlements' => (int) $row->settlements,
                'net' => $row->net === null ? null : (string) $row->net,
            ])
            ->all();
    }

    private function has(string $column): bool
    {
        return $this->columns[$column] ??= Schema::hasColumn(self::TABLE, $column);
    }
}
