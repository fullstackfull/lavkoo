<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

/** What the database says about one column: structure only, never a value stored in it. */
final readonly class ColumnFacts
{
    public function __construct(
        public string $name,
        public string $family,
        public string $type,
        public bool $nullable,
        public ?int $length,
        public bool $unsigned,
        public bool $autoIncrement,
    ) {
    }

    /** @param array<string, mixed> $column one entry of Schema::getColumns() */
    public static function fromSchema(array $column): self
    {
        $type = strtolower((string) ($column['type'] ?? $column['type_name'] ?? ''));

        return new self(
            name: strtolower((string) $column['name']),
            family: ColumnFamily::of($column),
            type: $type,
            nullable: (bool) ($column['nullable'] ?? true),
            length: ColumnFamily::length($column),
            unsigned: str_contains($type, 'unsigned'),
            autoIncrement: (bool) ($column['auto_increment'] ?? false),
        );
    }
}
