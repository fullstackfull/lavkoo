<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

/** One thing the integration assumes about a core column, and what is lost when the real column differs. */
final readonly class ColumnExpectation
{
    /** An identifier the integration stores as a plain integer. */
    public const ID = 'id';
    public const INTEGER = 'integer';
    /** A 0/1 switch (tinyint, boolean). */
    public const FLAG = 'flag';
    /** An integer id or, for guests, a string. */
    public const REFERENCE = 'reference';
    /** An amount of money: a decimal or float type. A whole-number type cannot hold cents. */
    public const MONEY = 'money';
    public const STRING = 'string';
    /** Free text or JSON: a short varchar would truncate it. */
    public const TEXT = 'text';
    public const DATETIME = 'datetime';

    /** A varchar this long or longer holds the JSON documents the integration reads, so it is accepted for TEXT. */
    private const JSON_CAPABLE_LENGTH = 1000;

    public function __construct(
        public string $table,
        public string $column,
        public string $kind,
        public string $severity,
        public string $reason,
        public ?int $minLength = null,
        public bool $optionalTable = false,
    ) {
    }

    /** @return string|null what is wrong with this column, or null when it fits */
    public function problem(ColumnFacts $facts): ?string
    {
        $accepted = $this->acceptedFamilies();

        if (in_array($facts->family, $accepted, true)) {
            return $this->tooShort($facts);
        }
        // SQLite reports no length for a varchar (and does not enforce one), so a string of unknown length is given the benefit of the doubt.
        if ($this->kind === self::TEXT && $facts->family === ColumnFamily::STRING && ($facts->length === null || $facts->length >= self::JSON_CAPABLE_LENGTH)) {
            return null;
        }
        if ($this->kind === self::MONEY && $facts->family === ColumnFamily::INTEGER) {
            return "is {$facts->type}, a whole-number type: it cannot hold cents, so amounts would be rounded. {$this->reason}";
        }

        return $this->kind === self::TEXT && $facts->family === ColumnFamily::STRING
            ? "is {$facts->type}, too short for the JSON it carries ({$this->minimumDescription()}). {$this->reason}"
            : "is {$facts->type} ({$facts->family}), expected {$this->expectedDescription()}. {$this->reason}";
    }

    /** @return list<string> */
    private function acceptedFamilies(): array
    {
        return match ($this->kind) {
            self::ID, self::INTEGER, self::FLAG => [ColumnFamily::INTEGER],
            self::REFERENCE => [ColumnFamily::INTEGER, ColumnFamily::STRING],
            self::MONEY => [ColumnFamily::DECIMAL, ColumnFamily::FLOAT],
            self::STRING => [ColumnFamily::STRING, ColumnFamily::TEXT],
            self::TEXT => [ColumnFamily::TEXT, ColumnFamily::JSON],
            self::DATETIME => [ColumnFamily::DATETIME],
        };
    }

    private function tooShort(ColumnFacts $facts): ?string
    {
        return $this->minLength !== null && $facts->length !== null && $facts->length < $this->minLength
            ? "is {$facts->type}, shorter than the {$this->minLength} characters the integration may store. {$this->reason}"
            : null;
    }

    private function expectedDescription(): string
    {
        return implode(' or ', $this->acceptedFamilies());
    }

    private function minimumDescription(): string
    {
        return 'text, json or varchar(' . self::JSON_CAPABLE_LENGTH . ') or longer';
    }
}
