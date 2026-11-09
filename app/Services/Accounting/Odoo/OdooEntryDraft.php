<?php

namespace App\Services\Accounting\Odoo;

use App\Services\OperationalCosts\Support\Money;

/**
 * One journal entry, built and not yet sent — and **a draft is all this integration ever creates**.
 *
 * It carries no `state`. Odoo documents no method that moves an entry from draft to posted, and the final posting is behind its
 * own switch and a recorded approval, so this never writes that field: a new `account.move` is a draft because Odoo makes it one,
 * not because we asked.
 *
 * It also carries exactly ONE channel. Two channels are never combined, because a figure that mixes them cannot be reconciled
 * against either channel's own statement afterwards.
 */
final readonly class OdooEntryDraft
{
    /**
     * @param list<OdooEntryLine> $lines
     */
    public function __construct(
        public string $reference,
        public int $companyId,
        public int $journalId,
        public string $date,
        public int $currencyId,
        public string $salesChannel,
        public array $lines,
        public ?string $narration = null,
    ) {
    }

    /**
     * The `account.move` values Odoo's `create` takes, carrying ONLY fields this instance was found to have.
     *
     * `move_type` is `entry` -- a miscellaneous journal entry, not an invoice or a bill. `line_ids` uses Odoo's one-to-many
     * write command: 0 means "create a new line from these values".
     *
     * @return array<string, mixed>
     */
    public function toValues(OdooPreflight $preflight): array
    {
        $moveFields = $preflight->fields['account.move'] ?? [];
        $lineFields = $preflight->fields['account.move.line'] ?? [];
        $analytic = $preflight->analyticField();

        $values = [
            'move_type' => 'entry',
            'journal_id' => $this->journalId,
            'date' => $this->date,
            'ref' => $this->reference,
            'company_id' => $this->companyId,
            'currency_id' => $this->currencyId,
            'line_ids' => array_map(static fn (OdooEntryLine $line): array => [0, 0, $line->toValues($lineFields, $analytic)], $this->lines),
        ];
        if ($this->narration !== null && isset($moveFields['narration'])) {
            $values['narration'] = $this->narration;
        }

        return $values;
    }

    /** Does it balance? An unbalanced entry is refused by Odoo, and a refusal we can name beats a fault we cannot. */
    public function balances(): bool
    {
        $debits = Money::zero();
        $credits = Money::zero();
        foreach ($this->lines as $line) {
            $debits = $debits->plus(Money::of((string) $line->debit));
            $credits = $credits->plus(Money::of((string) $line->credit));
        }

        return $debits->round(2)->equals($credits->round(2));
    }

    /** @return list<float> every debit and credit, for a test or a screen that wants to show the two columns. */
    public function amounts(): array
    {
        return array_merge(
            array_map(static fn (OdooEntryLine $line): float => $line->debit, $this->lines),
            array_map(static fn (OdooEntryLine $line): float => $line->credit, $this->lines),
        );
    }
}
