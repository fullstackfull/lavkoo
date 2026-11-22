<?php

namespace App\Models;

use App\Services\Address\AddressCountry;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment gateway routing rule (Phase 3, Stage E).
 */
class PaymentRoutingRule extends Model
{
    public const ACTION_HIDE = 'hide';
    public const ACTION_PREFER = 'prefer';
    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'name', 'gateway_code', 'action', 'min_amount', 'max_amount', 'country', 'priority', 'status', 'created_by',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'priority' => 'integer',
        'created_by' => 'integer',
    ];

    /** Does this rule's conditions match the given order context? */
    public function matches(float $amount, ?string $country): bool
    {
        if ($this->min_amount !== null && $amount < (float) $this->min_amount) {
            return false;
        }
        if ($this->max_amount !== null && $amount > (float) $this->max_amount) {
            return false;
        }

        return $this->countryMatches($country);
    }

    /** Does this rule's country condition cover this destination? A rule naming no country covers every destination. */
    public function countryMatches(?string $country): bool
    {
        if ($this->country === null || trim($this->country) === '') {
            return true;
        }

        /*
         * BY ISO CODE, NOT BY TEXT. The two sides are written by different people in different places:
         * the destination comes from the address book, which has always held the English name, or from
         * an older client that sends a code; the rule's country is typed by hand into an admin form
         * that accepts anything. Comparing the strings meant a rule saying "Kuwait" never matched a
         * destination of "KW", and one saying "KSA" never matched "Saudi Arabia" -- so a rule an
         * operator wrote to stop a gateway in one market quietly did nothing, which is the worst way
         * for a rule to fail.
         *
         * When EITHER side names no country that can be recognised, the codes cannot decide it and the
         * text comparison is kept, exactly as it behaved before. Treating an unrecognised rule as
         * matching nothing would switch off a rule somebody wrote; treating it as matching everything
         * would hide a gateway in every market. Keeping today's answer is the only change-free option.
         */
        $ruleCountry = AddressCountry::anyCountryIsoCode($this->country);
        $destination = AddressCountry::anyCountryIsoCode($country);

        if ($ruleCountry !== null && $destination !== null) {
            return $ruleCountry === $destination;
        }

        return mb_strtolower(trim($this->country)) === mb_strtolower(trim((string) $country));
    }

    /** A rule that names neither an amount band nor a country, so it applies wherever it is read. */
    public function appliesEverywhere(): bool
    {
        return $this->min_amount === null
            && $this->max_amount === null
            && ($this->country === null || trim($this->country) === '');
    }
}
