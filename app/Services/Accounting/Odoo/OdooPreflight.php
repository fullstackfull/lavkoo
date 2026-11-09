<?php

namespace App\Services\Accounting\Odoo;

/**
 * What one customer's Odoo actually offers, read from the instance before anything is posted into it.
 *
 * This exists because of a gap in Odoo's documentation rather than a preference of ours. Its developer reference has **no page
 * for `account.move` or `account.move.line`** -- the Accounting reference lists Account Tag, Account, Fiscal Position, Account
 * Group, Report, Report Line, Taxes and Tax Repartitions, and no journal entry among them -- and it states no field renames
 * between versions. So the field names cannot be written into this code from the documentation, and writing them from memory of
 * some other Odoo would be the worst of both: wrong on half the instances and silent about it.
 *
 * The instance is therefore the authority. `fields_get` is documented, `ir.model` is documented as queryable for installed
 * models, and between them they answer what exists and what type it has. Every field this integration needs is declared below as
 * a REQUIREMENT, matched against what came back, and a missing one is a named refusal a person can act on.
 *
 * ## What this deliberately does NOT claim
 *
 * **It does not verify that the user may write.** Odoo documents no way to ask: there is no `check_access_rights` and no
 * `has_group` in the external-API reference, and the JSON-2 page says only that every operation is validated against the user's
 * access rights when it runs. So a preflight that reported "writes permitted" would be inventing a check nobody performed. What
 * it reports instead is what it could READ, which is real evidence and stops well short of a promise -- and a write that is
 * going to be refused is refused by Odoo at the moment it happens, with a fault that says so.
 *
 * Nothing here writes anything. Every call is a read, so a preflight can be run against a production instance by a person who is
 * still deciding whether to connect it at all.
 */
final readonly class OdooPreflight
{
    /** The models this integration touches. Each is checked for existence through `ir.model` before its fields are asked for. */
    public const MODELS = [
        'account.move',
        'account.move.line',
        'account.account',
        'account.journal',
        'account.tax',
        'res.company',
        'res.currency',
    ];

    /**
     * The fields a journal entry needs, per model.
     *
     * These are the names Odoo's own ACCOUNTING pages do not document, which is why they are checked rather than trusted. A name
     * absent from an instance is reported with the model it was expected on, so a person reading the screen can see whether the
     * instance is older, newer, or simply missing a module.
     */
    public const REQUIRED_FIELDS = [
        'account.move' => ['move_type', 'journal_id', 'date', 'ref', 'line_ids', 'company_id', 'currency_id', 'state'],
        'account.move.line' => ['account_id', 'name', 'debit', 'credit'],
    ];

    /**
     * Fields worth having and not worth refusing over: an entry can be posted without them, less richly.
     *
     * `analytic_distribution` is the clearest case. It is the cost-centre field on recent Odoo and replaced an earlier
     * `analytic_account_id`; which one an instance has is not documented anywhere, so both are looked for and whichever is found
     * is what the mapping offers. Finding neither means this instance records no cost centre, which is a fact to show rather than
     * an error.
     */
    public const OPTIONAL_FIELDS = [
        'account.move' => ['partner_id', 'invoice_date', 'narration'],
        'account.move.line' => ['partner_id', 'tax_ids', 'currency_id', 'amount_currency', 'analytic_distribution', 'analytic_account_id'],
    ];

    /**
     * @param array<string, bool> $models model name => whether the instance has it
     * @param array<string, array<string, array{type: string, label: string|null}>> $fields model => field => what the instance said
     * @param list<string> $missingRequired "model.field" for each requirement the instance does not have
     * @param list<string> $problems stable codes for what a person must fix
     * @param array<string, mixed> $company what was read of the company, where it could be read
     */
    public function __construct(
        public OdooInstance $instance,
        public array $models = [],
        public array $fields = [],
        public array $missingRequired = [],
        public array $problems = [],
        public array $company = [],
        public int $accountsRead = 0,
        public int $taxesRead = 0,
        public int $journalsRead = 0,
        public int $currenciesRead = 0,
    ) {
    }

    /**
     * Could an entry be BUILT for this instance? Not whether it may be posted — see the class note on access rights.
     *
     * Deliberately named for what it checks. Everything required exists, every model is there, and the instance is usable; what
     * nobody can know from here is whether Odoo will accept the write when it comes.
     */
    public function couldBuildAnEntry(): bool
    {
        return $this->instance->usable() && $this->missingRequired === [] && $this->problems === [];
    }

    /**
     * The cost-centre field this instance actually has, or null where it has neither.
     *
     * Odoo renamed it and documents neither name, so this is read rather than assumed and the answer is allowed to be "neither".
     */
    public function analyticField(): ?string
    {
        foreach (['analytic_distribution', 'analytic_account_id'] as $candidate) {
            if (isset($this->fields['account.move.line'][$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /** What a person is told, in stable codes rather than Odoo's own prose. @return list<string> */
    public function readinessCodes(): array
    {
        $codes = $this->problems;
        foreach ($this->missingRequired as $missing) {
            $codes[] = 'odoo_field_missing:' . $missing;
        }
        if ($this->analyticField() === null) {
            // Not a problem: an instance without analytic accounting records no cost centre, and the mapping simply will not
            // offer one.
            $codes[] = 'odoo_records_no_cost_centre';
        }

        return array_values(array_unique($codes));
    }
}
