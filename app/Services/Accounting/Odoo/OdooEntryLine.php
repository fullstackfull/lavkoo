<?php

namespace App\Services\Accounting\Odoo;

/**
 * One line of a journal entry, with `debit` and `credit` as Odoo holds them: **both non-negative**.
 *
 * The direction of an amount is which of the two columns it sits in, never the sign of a number. A refund or a reversal is a
 * positive figure in the other column. `OdooMoney::debitAndCredit()` is the only thing that fills these, and it never emits a
 * negative one.
 *
 * Every optional field is nullable because whether this instance HAS it is read from the instance, not assumed: Odoo's developer
 * reference has no page for `account.move.line` at all, and the cost-centre field has had two different names.
 */
final readonly class OdooEntryLine
{
    public function __construct(
        public int $accountId,
        public string $name,
        public float $debit,
        public float $credit,
        /** @var list<int> */
        public array $taxIds = [],
        public ?int $analyticAccountId = null,
        /** For a line in a currency other than the company's: the figure in that currency, signed as Odoo expects. */
        public ?int $currencyId = null,
        public ?float $amountCurrency = null,
    ) {
    }

    /**
     * The line as Odoo's `create` takes it, carrying ONLY fields this instance was found to have.
     *
     * @param array<string, mixed> $available the instance's own `account.move.line` fields, as `fields_get` reported them
     * @return array<string, mixed>
     */
    public function toValues(array $available, ?string $analyticField): array
    {
        $values = ['account_id' => $this->accountId, 'name' => $this->name, 'debit' => $this->debit, 'credit' => $this->credit];

        if ($this->taxIds !== [] && isset($available['tax_ids'])) {
            // Odoo's many-to-many write command: 6 means "replace the set with exactly these ids".
            $values['tax_ids'] = [[6, 0, $this->taxIds]];
        }
        if ($this->currencyId !== null && $this->amountCurrency !== null && isset($available['currency_id'], $available['amount_currency'])) {
            $values['currency_id'] = $this->currencyId;
            $values['amount_currency'] = $this->amountCurrency;
        }
        if ($this->analyticAccountId !== null && $analyticField !== null) {
            // The two names Odoo has used for this, and they take different shapes: the newer field is a distribution map of
            // account id to percentage, the older one a plain id. Which one this instance has was read from it.
            $values[$analyticField] = $analyticField === 'analytic_distribution'
                ? [(string) $this->analyticAccountId => 100]
                : $this->analyticAccountId;
        }

        return $values;
    }
}
