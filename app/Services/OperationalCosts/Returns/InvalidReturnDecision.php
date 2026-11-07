<?php

namespace App\Services\OperationalCosts\Returns;

use DomainException;

/** A return decision that cannot be made, with the code the screens turn into words (`return_refused_<reason>`). Nothing was recorded. */
final class InvalidReturnDecision extends DomainException
{
    public const NOTHING_TO_DECIDE = 'nothing_to_decide';
    public const UNKNOWN_RETURN_EVENT = 'unknown_return_event';
    public const CLAIM_UNITS_MUST_ALL_BE_DECIDED = 'claim_units_must_all_be_decided';
    public const USE_THE_CLAIM = 'use_the_claim';
    public const MORE_THAN_SOLD = 'more_than_sold';
    public const NOT_LINKED = 'not_linked';
    public const NO_LOCATION = 'no_location';
    public const LOCATION_UNKNOWN = 'location_unknown';
    public const NO_COST_RECOGNISED = 'no_cost_recognised';
    public const MORE_THAN_TAKEN_FROM_STOCK = 'more_than_taken_from_stock';

    /** @return list<string> */
    public static function reasons(): array
    {
        return [
            self::NOTHING_TO_DECIDE, self::UNKNOWN_RETURN_EVENT, self::CLAIM_UNITS_MUST_ALL_BE_DECIDED, self::USE_THE_CLAIM, self::MORE_THAN_SOLD, self::NOT_LINKED,
            self::NO_LOCATION, self::LOCATION_UNKNOWN, self::NO_COST_RECOGNISED, self::MORE_THAN_TAKEN_FROM_STOCK,
        ];
    }

    public function __construct(public readonly string $reason)
    {
        parent::__construct('A return decision was refused: ' . $reason . '.');
    }
}
