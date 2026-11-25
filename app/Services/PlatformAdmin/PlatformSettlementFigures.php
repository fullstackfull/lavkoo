<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What was settled with the shops of a market, by the company that settled it and the currency it was settled in (L-14).
 *
 * **How a settlement is put in a market.** By the COMPANY that settled it (`vendor_settlements.legal_entity_id` against the country
 * `legal_entities` records for it) and by nothing else. A settlement carries no market of its own, and the company is the fact that decides which
 * country's books it belongs in; a settlement that names no company belongs to no market and is never read as the default one.
 *
 * **Two currencies are never added.** One row per company and currency, each summed by the engine, as the sales figures are. A settlement that
 * records no currency is counted apart with no amount.
 *
 * **The table may not exist.** `vendor_settlements` has no migration anywhere in this repository -- the live table came from the installation dump
 * -- so this asks the schema first and answers "not recorded" rather than failing. An installation without it reads a reason, which is the truth
 * about it, and the same is true of the two columns the grouping needs.
 */
#[Scoped]
final class PlatformSettlementFigures
{
    public const TABLE = 'vendor_settlements';

    public const NOT_RECORDED = 'settlement_ledger_not_present';

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /** The states in which money has actually been settled. A draft is a calculation and not a settlement. */
    private const SETTLED_STATES = ['approved', 'paid'];

    private ?bool $readable = null;

    public function __construct(private readonly PlatformLegalEntities $entities)
    {
    }

    /** Whether the ledger of settlements is there to be read, with the two columns a figure of one market and one currency needs. */
    public function recorded(): bool
    {
        return $this->readable ??= Schema::hasTable(self::TABLE)
            && Schema::hasColumn(self::TABLE, 'legal_entity_id')
            && Schema::hasColumn(self::TABLE, 'currency')
            && Schema::hasColumn(self::TABLE, 'net_amount');
    }

    /**
     * The settlements of one market over a window, by company and currency.
     *
     * @return list<array{legal_entity_id: ?int, legal_entity: ?string, currency: ?string, settlements: int, net: ?string}>
     */
    public function forMarket(string $market, PlatformWindow $window): array
    {
        if (!$this->recorded()) {
            return [];
        }

        $companies = $this->companiesOf(market: $market);

        if ($companies === []) {
            return [];
        }

        $priced = "CASE WHEN currency IS NOT NULL AND TRIM(currency) <> '' THEN 1 ELSE 0 END";
        $currency = "CASE WHEN {$priced} = 1 THEN UPPER(TRIM(currency)) END";

        $rows = DB::table(self::TABLE)
            ->whereIn('legal_entity_id', $companies)
            ->whereIn('status', self::SETTLED_STATES)
            ->where('created_at', '>=', $window->startsAt()->format(self::DATE_FORMAT))
            ->where('created_at', '<', $window->endsBefore()->format(self::DATE_FORMAT))
            ->selectRaw("legal_entity_id AS entity_id, {$currency} AS currency, COUNT(*) AS settlements, SUM(CASE WHEN {$priced} = 1 THEN net_amount ELSE 0 END) AS net")
            ->groupBy('legal_entity_id', DB::raw($currency))
            ->get();

        return $this->settlementRows(rows: $rows);
    }

    /**
     * The companies whose settlements belong to this market: the one the registry records for the country. A country with no company recorded has no
     * settlements of its own, which is why an empty list answers no rows rather than every row.
     *
     * @return list<int>
     */
    private function companiesOf(string $market): array
    {
        $company = $this->entities->idOf(market: $market);

        return $company === null ? [] : [$company];
    }

    /**
     * @param iterable<object> $rows
     *
     * @return list<array{legal_entity_id: ?int, legal_entity: ?string, currency: ?string, settlements: int, net: ?string}>
     */
    private function settlementRows(iterable $rows): array
    {
        $reported = [];

        foreach ($rows as $row) {
            $currency = $row->currency === null ? null : (string) $row->currency;
            $entityId = $row->entity_id === null ? null : (int) $row->entity_id;

            $reported[] = [
                'legal_entity_id' => $entityId,
                'legal_entity' => $this->entities->byId($entityId)['name'] ?? null,
                'currency' => $currency,
                'settlements' => (int) $row->settlements,
                'net' => $currency === null ? null : PlatformAdminPresenter::amount(sum: $row->net),
            ];
        }

        usort($reported, static fn (array $one, array $other): int => [$one['currency'], $one['legal_entity_id']] <=> [$other['currency'], $other['legal_entity_id']]);

        return $reported;
    }
}
