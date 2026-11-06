<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

/**
 * A column's storage type reduced to the few families the integration cares about, whatever the driver calls them
 * (MySQL `bigint unsigned`, SQLite `integer`, `numeric` and `varchar` all map to the same few words).
 */
final class ColumnFamily
{
    public const INTEGER = 'integer';
    public const DECIMAL = 'decimal';
    public const FLOAT = 'float';
    public const STRING = 'string';
    public const TEXT = 'text';
    public const JSON = 'json';
    public const DATETIME = 'datetime';
    public const OTHER = 'other';

    /** @param array<string, mixed> $column one entry of Schema::getColumns() */
    public static function of(array $column): string
    {
        $type = strtolower((string) ($column['type_name'] ?? ''));

        return match (true) {
            preg_match('/^(tiny|small|medium|big)?int(eger)?$/', $type) === 1, in_array($type, ['bool', 'boolean'], true) => self::INTEGER,
            in_array($type, ['decimal', 'numeric', 'dec', 'fixed'], true) => self::DECIMAL,
            in_array($type, ['float', 'double', 'real', 'double precision'], true) => self::FLOAT,
            in_array($type, ['char', 'varchar', 'string', 'enum', 'set', 'character varying', 'nvarchar', 'nchar'], true) => self::STRING,
            in_array($type, ['tinytext', 'text', 'mediumtext', 'longtext', 'clob'], true) => self::TEXT,
            in_array($type, ['json', 'jsonb'], true) => self::JSON,
            in_array($type, ['datetime', 'timestamp', 'date', 'time'], true) => self::DATETIME,
            default => self::OTHER,
        };
    }

    /** The declared length of a char/varchar column, or null for every other type. */
    public static function length(array $column): ?int
    {
        return self::of($column) === self::STRING && preg_match('/\((\d+)\)/', (string) ($column['type'] ?? ''), $match) === 1
            ? (int) $match[1]
            : null;
    }
}
