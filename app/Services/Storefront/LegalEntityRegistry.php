<?php

namespace App\Services\Storefront;

use App\Models\LegalEntity;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Schema;

/**
 * Who sells in a storefront (GCC-13, D1-03): the one legal entity of each country. Switched off by default, and it ships empty: while it is off, or
 * before its migrations have run, or while a country has no active entity, nothing reads it and nothing is recorded, so every order keeps being
 * read as an order of the Saudi company in the platform's own settings. The entities themselves are facts the owner and counsel supply.
 *
 * An entity is changed in place; an order keeps the frozen copy it was stamped with (see identityOf()), so a later change never rewrites it.
 */
#[Singleton]
final class LegalEntityRegistry
{
    /** How a row of another table names the company it belongs to. The same name on products, on orders and on the seller ledger, so one company reads one way everywhere. */
    public const COLUMN = 'legal_entity_id';

    /** The fields of an entity that an order freezes, and the keys the frozen copy carries. */
    private const IDENTITY_FIELDS = ['country', 'legal_name', 'trade_name', 'tax_registration_number', 'commercial_registration_number', 'invoice_series_key', 'address'];

    /** How long an answer is remembered, so a queue worker notices a change of the registry without a restart. */
    private const ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private ?array $tableAnswer = null;

    /** @var array<string, array{entity: ?LegalEntity, at: int}> */
    private array $entities = [];

    /** @var array<string, array{entity: ?LegalEntity, at: int}> */
    private array $entitiesById = [];

    /** @var array{value: bool, at: int}|null */
    private ?array $productColumnAnswer = null;

    public function isEnabled(): bool
    {
        return (bool) config('gcc.storefronts.legal_entities.enabled') && StorefrontResolver::fromConfig()->isEnabled();
    }

    /** Whether a storefront that has no active entity may not sell (the owner's choice, off by default). */
    public function isRequiredToSell(): bool
    {
        return $this->isEnabled() && (bool) config('gcc.storefronts.legal_entities.required_to_sell');
    }

    /** The active entity of a country, or null while the registry is off, not migrated, or has none for that country. */
    public function forCountry(string $country): ?LegalEntity
    {
        if (!$this->isEnabled() || !$this->tableReady()) {
            return null;
        }

        $code = strtoupper(trim($country));
        $remembered = $this->entities[$code] ?? null;

        if ($remembered !== null && time() - $remembered['at'] < self::ANSWER_SECONDS) {
            return $remembered['entity'];
        }

        $entity = LegalEntity::query()->where('country', $code)->where('status', true)->first();
        $this->entities[$code] = ['entity' => $entity, 'at' => time()];

        return $entity;
    }

    /** The active entity of this id, or null while the registry is off, not migrated, or holds no active row with it. */
    public function byId(int|string $id): ?LegalEntity
    {
        if (!$this->isEnabled() || !$this->tableReady()) {
            return null;
        }

        $key = (string) $id;
        $remembered = $this->entitiesById[$key] ?? null;

        if ($remembered !== null && time() - $remembered['at'] < self::ANSWER_SECONDS) {
            return $remembered['entity'];
        }

        $entity = LegalEntity::query()->whereKey($id)->where('status', true)->first();
        $this->entitiesById[$key] = ['entity' => $entity, 'at' => time()];

        return $entity;
    }

    /**
     * The company that sells this product in this storefront: the one the product itself names, and the country's own company for a product that names
     * none — which is every product before anybody registers a company, and half of them while a backfill runs.
     *
     * The row is whatever the caller already holds: a Product the admin screens or the checkout loaded, or the plain products row the channel
     * materializer reads with the query builder. Both are asked for the column by name and neither is asked for a column it does not carry, so a row
     * loaded without it says nothing rather than failing (the same way StorefrontCatalog reads a product's selling scope).
     *
     * Null, never an exception, while legal entities are off, before the migrations have run, when the registry is empty, when the country has no active
     * company, and when the company a product names is not an active row of the registry: a missing company must not stop a sale, and the default Saudi
     * storefront keeps selling with an empty registry. A product whose company is gone answers nothing rather than the country's, because selling it
     * under a company it does not name would be a worse answer than none.
     */
    public function forProduct(?object $product, string $country): ?LegalEntity
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $named = $product === null ? null : ($product->{self::COLUMN} ?? null);

        return $named === null ? $this->forCountry($country) : $this->byId($named);
    }

    /** Whether a product carries the company that sells it: the registry is on and the products migration has run. */
    public function tracksProducts(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $answer = $this->productColumnAnswer;

        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $this->productColumnAnswer = ['value' => Schema::hasColumn('products', self::COLUMN), 'at' => time()];

        return $this->productColumnAnswer['value'];
    }

    /**
     * What a new product of this country is saved with: the company registered for it, so an operator who chose the country does not type the company
     * too. Nothing while the registry is off, before the migration has run, or while the country has no active company.
     *
     * @return array<string, int>
     */
    public function newProductAttributes(?string $country): array
    {
        if (!$this->tracksProducts() || $country === null) {
            return [];
        }

        $entity = $this->forCountry($country);

        return $entity === null ? [] : [self::COLUMN => (int) $entity->id];
    }

    /**
     * Whether a country may sell as far as its company is concerned: always, unless the owner required an entity and the country has none. The default
     * storefront is never refused for lack of one (GCC-19): it keeps selling as it always has.
     */
    public function isReadyToSell(string $country): bool
    {
        return !$this->isRequiredToSell()
            || strtoupper($country) === StorefrontResolver::fromConfig()->defaultCountry()
            || $this->forCountry($country) !== null;
    }

    /**
     * The frozen identity an order carries: the fields of the entity as they are now, and its id.
     *
     * @return array<string, int|string|null>
     */
    public function identityOf(LegalEntity $entity): array
    {
        $identity = ['legal_entity_id' => (int) $entity->id];

        foreach (self::IDENTITY_FIELDS as $field) {
            $identity[$field] = $entity->{$field};
        }

        return $identity;
    }

    /**
     * One row for each configured country, whatever the switch says: what the registry holds for it. Read by the report command before the switch is turned on.
     *
     * @return list<array{country: string, storefront_active: bool, is_default: bool, status: string, legal_name: ?string, tax_registration_number: ?string}>
     */
    public function report(): array
    {
        $entities = $this->tableReady() ? LegalEntity::query()->get()->keyBy('country') : collect();
        $default = StorefrontResolver::fromConfig()->defaultCountry();
        $rows = [];

        foreach ((array) config('gcc.storefronts.countries') as $country => $storefront) {
            $entity = $entities->get($country);

            $rows[] = [
                'country' => (string) $country,
                'storefront_active' => ($storefront['active'] ?? false) === true,
                'is_default' => $country === $default,
                'status' => $entity === null ? 'missing' : ($entity->status ? 'active' : 'inactive'),
                'legal_name' => $entity?->legal_name,
                'tax_registration_number' => $entity?->tax_registration_number,
            ];
        }

        return $rows;
    }

    /** Whether the table exists, for a command that has to say "run the migrations" before it writes. */
    public function isMigrated(): bool
    {
        return $this->tableReady();
    }

    /** Forgets what was remembered, after the registry was changed in this process. */
    public function flush(): void
    {
        $this->entities = [];
        $this->entitiesById = [];
        $this->tableAnswer = null;
        $this->productColumnAnswer = null;
    }

    private function tableReady(): bool
    {
        $answer = $this->tableAnswer;

        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $this->tableAnswer = ['value' => Schema::hasTable('legal_entities'), 'at' => time()];

        return $this->tableAnswer['value'];
    }
}
