<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company that sells in a market, as `legal_entities` records it (D1-03, GCC-13).
 *
 * Read from the registry and never from `gcc.storefronts.legal.legal_entity`, which is ONE value for the whole platform: repeating it under every
 * market would say that six markets are sold by one company, which is the opposite of the approved plan that each country has a selling company of
 * its own. A market with no company recorded answers null, and so does a row nobody attributed -- rows that belong to no market belong to no company.
 *
 * A company is read by its country for "who sells here now", which is the active row, and by its id for a figure of the past, whatever that row's
 * state is today: an order sold by a company that has since been closed was still sold by it.
 *
 * One instance serves a whole request, so the markets list, the health answer and the sales figures ask the table once.
 */
#[Scoped]
final class PlatformLegalEntities
{
    /** @var array<string, array{id: int, name: string, trade_name: ?string}>|null the active company of each country */
    private ?array $activeByCountry = null;

    /** @var array<int, array{id: int, country: ?string, name: string, trade_name: ?string, active: bool}>|null every company by id */
    private ?array $byId = null;

    /** @return array{id: int, name: string, trade_name: ?string}|null */
    public function of(?string $market): ?array
    {
        if ($market === null) {
            return null;
        }

        return $this->active()[strtoupper(trim($market))] ?? null;
    }

    public function nameOf(?string $market): ?string
    {
        return $this->of(market: $market)['name'] ?? null;
    }

    public function idOf(?string $market): ?int
    {
        return $this->of(market: $market)['id'] ?? null;
    }

    /**
     * The company of an id, whether it still sells or not, because a figure of the past names the company that made it.
     *
     * @return array{id: int, country: ?string, name: string, trade_name: ?string, active: bool}|null
     */
    public function byId(int|string|null $id): ?array
    {
        if ($id === null || !is_numeric($id)) {
            return null;
        }

        return $this->all()[(int) $id] ?? null;
    }

    /** Whether the registry the figures name their companies from is there to be read at all. */
    public function recorded(): bool
    {
        return Schema::hasTable('legal_entities');
    }

    /** @return array<string, array{id: int, name: string, trade_name: ?string}> */
    private function active(): array
    {
        if ($this->activeByCountry !== null) {
            return $this->activeByCountry;
        }

        $this->activeByCountry = [];

        foreach ($this->all() as $entity) {
            $country = $entity['country'];

            if ($country === null || !$entity['active'] || isset($this->activeByCountry[$country])) {
                continue;
            }

            $this->activeByCountry[$country] = ['id' => $entity['id'], 'name' => $entity['name'], 'trade_name' => $entity['trade_name']];
        }

        return $this->activeByCountry;
    }

    /** @return array<int, array{id: int, country: ?string, name: string, trade_name: ?string, active: bool}> */
    private function all(): array
    {
        if ($this->byId !== null) {
            return $this->byId;
        }

        $this->byId = [];

        if (!$this->recorded()) {
            return $this->byId;
        }

        foreach (DB::table('legal_entities')->orderBy('id')->get(['id', 'country', 'legal_name', 'trade_name', 'status']) as $row) {
            $name = trim((string) $row->legal_name);

            if ($name === '') {
                continue;
            }

            $country = strtoupper(trim((string) $row->country));
            $trade = trim((string) ($row->trade_name ?? ''));

            $this->byId[(int) $row->id] = [
                'id' => (int) $row->id,
                'country' => $country === '' ? null : $country,
                'name' => $name,
                'trade_name' => $trade === '' ? null : $trade,
                'active' => (bool) $row->status,
            ];
        }

        return $this->byId;
    }
}
