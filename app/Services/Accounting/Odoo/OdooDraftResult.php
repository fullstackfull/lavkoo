<?php

namespace App\Services\Accounting\Odoo;

/**
 * Either a journal entry ready to be queued, or the named reasons it is not.
 *
 * Never both and never neither. A refusal is a list of stable codes a person can act on — "this component has no account in your
 * chart", "this instance records no cost centre", "this cost is still an estimate" — because the alternative is an entry posted
 * on a guess, and a guess in somebody's ledger is indistinguishable from a fact.
 */
final readonly class OdooDraftResult
{
    /** @param list<string> $refusals */
    private function __construct(
        public ?OdooEntryDraft $draft,
        public ?OdooPostingKey $key,
        public array $refusals,
    ) {
    }

    public static function ready(OdooEntryDraft $draft, OdooPostingKey $key): self
    {
        return new self($draft, $key, []);
    }

    /** @param list<string> $refusals */
    public static function refused(array $refusals): self
    {
        return new self(null, null, array_values(array_unique($refusals)));
    }

    public function isReady(): bool
    {
        return $this->draft !== null;
    }

    /** The first reason, for a one-line message. The whole list is what a person fixing it needs. */
    public function firstRefusal(): ?string
    {
        return $this->refusals[0] ?? null;
    }
}
