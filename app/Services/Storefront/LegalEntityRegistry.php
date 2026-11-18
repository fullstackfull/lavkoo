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
    /** The fields of an entity that an order freezes, and the keys the frozen copy carries. */
    private const IDENTITY_FIELDS = ['country', 'legal_name', 'trade_name', 'tax_registration_number', 'commercial_registration_number', 'invoice_series_key', 'address'];

    /** How long an answer is remembered, so a queue worker notices a change of the registry without a restart. */
    private const ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private ?array $tableAnswer = null;

    /** @var array<string, array{entity: ?LegalEntity, at: int}> */
    private array $entities = [];

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
        $this->tableAnswer = null;
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
