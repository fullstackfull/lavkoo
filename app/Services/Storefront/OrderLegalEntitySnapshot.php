<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who sold an order (GCC-13, D1-03): the id of the legal entity of the order's storefront and a frozen copy of its identity, written once. It is
 * asked from OrderStorefrontSnapshot, which knows the storefront from the lines the order was saved with, so every path that creates an order records
 * the same thing. Nothing is recorded while legal entities are off, before the orders migration has run, or for a country without an active entity;
 * an order with no record is read as an order of the Saudi company in the platform's own settings, as every order was before.
 */
#[Singleton]
final class OrderLegalEntitySnapshot
{
    private const ENTITY_COLUMN = 'legal_entity_id';

    private const SNAPSHOT_COLUMN = 'entity_snapshot';

    /** How long a "no" is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private ?array $columnsAnswer = null;

    public function __construct(private readonly LegalEntityRegistry $registry)
    {
    }

    /** Whether an order is stamped at all right now: the registry is on and the orders migration has run. */
    public function recordsOrders(): bool
    {
        return $this->registry->isEnabled() && $this->columnsReady();
    }

    public function record(int|string $orderId, string $country): void
    {
        $entity = $this->recordsOrders() ? $this->registry->forCountry($country) : null;

        if ($entity === null) {
            return;
        }

        DB::table('orders')->where('id', $orderId)->whereNull(self::ENTITY_COLUMN)->update([
            self::ENTITY_COLUMN => $entity->id,
            self::SNAPSHOT_COLUMN => json_encode($this->registry->identityOf($entity), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function columnsReady(): bool
    {
        $answer = $this->columnsAnswer;

        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::NEGATIVE_ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $ready = Schema::hasColumn('orders', self::ENTITY_COLUMN) && Schema::hasColumn('orders', self::SNAPSHOT_COLUMN);
        $this->columnsAnswer = ['value' => $ready, 'at' => time()];

        return $ready;
    }
}
