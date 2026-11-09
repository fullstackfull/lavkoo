<?php

namespace App\Services\Accounting\Odoo;

use App\Models\SalesChannelAccount;

/**
 * Asks a customer's Odoo what it actually is, before anything is mapped and long before anything is posted.
 *
 * **Everything here is READ.** No model is created, no field is written, and the run is safe with the write switches off — which
 * is the only order in which the mapping work can be done: connect, look, decide, and only then turn writing on.
 *
 * Why it exists at all: Odoo's developer reference has **no page for `account.move` or `account.move.line`**, so the field names
 * a journal entry needs are not in any documentation to be copied from. They are asked of the instance, with `fields_get`, which
 * is also what survives Odoo renaming one between versions — a rename its own documentation does not announce.
 *
 * ## The company is chosen, not assumed
 *
 * A multi-company Odoo has several, and posting into the wrong one is a mistake that reads as correct on every screen until
 * somebody consolidates. So: the company a person picked on the connection, or the only one there is, or a named refusal. Never
 * "the first row".
 *
 * ## What it does NOT claim
 *
 * It does not check whether the credentials may WRITE. Odoo documents no way to ask — no `check_access_rights`, no `has_group` —
 * and JSON-2's page says only that every operation is validated against the user's own rights when it is attempted. So this
 * reports what the models show and never reports a permission as verified. `couldBuildAnEntry()` is named for that limit.
 */
class OdooPreflightRun
{
    /** Odoo's own introspection model, which is the documented way to ask what a database holds. */
    private const MODEL_LIST = 'ir.model';

    /** How many currency rows are read. Odoo ships well under this; a list longer than it is not a currency list. */
    private const CURRENCY_CEILING = 300;

    public function __construct(private readonly OdooClient $client)
    {
    }

    public function run(SalesChannelAccount $connection): OdooPreflight
    {
        $instance = $this->client->discover($connection);
        if (!$instance->usable()) {
            return new OdooPreflight($instance, problems: $instance->problems);
        }

        $models = $this->modelsPresent($connection);
        $absent = array_keys(array_filter($models, static fn (bool $present): bool => !$present));
        if ($absent !== []) {
            // Without `account.move` there is nothing to ask about and nothing to post into: a database without accounting
            // installed is a fact to show, not a field list to attempt.
            return new OdooPreflight($instance, $models, problems: array_map(static fn (string $model): string => 'odoo_model_missing:' . $model, $absent));
        }

        $fields = [];
        foreach (array_keys(OdooPreflight::REQUIRED_FIELDS) as $model) {
            $fields[$model] = $this->fieldsOf($connection, $model);
        }

        $missing = [];
        foreach (OdooPreflight::REQUIRED_FIELDS as $model => $required) {
            foreach ($required as $field) {
                if (!isset($fields[$model][$field])) {
                    $missing[] = $model . '.' . $field;
                }
            }
        }

        [$company, $problems] = $this->company($connection);
        [$currencies, $currencyNotes] = $this->currencies($connection, $company);

        return new OdooPreflight(
            instance: $instance,
            models: $models,
            fields: $fields,
            missingRequired: $missing,
            problems: $problems,
            company: $company,
            accountsRead: $this->countOf($connection, 'account.account'),
            taxesRead: $this->countOf($connection, 'account.tax'),
            journalsRead: $this->countOf($connection, 'account.journal'),
            currenciesRead: $this->countOf($connection, 'res.currency'),
            currencies: $currencies,
            currencyNotes: $currencyNotes,
        );
    }

    /**
     * Which of the models this integration touches the instance has.
     *
     * Asked in ONE call rather than seven, because `ir.model` is a table like any other and a domain over it answers the whole
     * question. A model the answer does not mention is absent.
     *
     * @return array<string, bool>
     */
    private function modelsPresent(SalesChannelAccount $connection): array
    {
        $answer = $this->client->searchRead($connection, self::MODEL_LIST, [['model', 'in', OdooPreflight::MODELS]], ['model']);
        $found = array_values(array_filter(array_map(
            static fn (array $record): string => trim((string) ($record['model'] ?? '')),
            $answer->records(),
        )));

        $present = [];
        foreach (OdooPreflight::MODELS as $model) {
            // An answer nobody could read is NOT "the model is absent": `$answer->ok()` is false then, and every model reads as
            // absent, which is the honest outcome -- the refusal above names each one and nothing is attempted.
            $present[$model] = in_array($model, $found, true);
        }

        return $present;
    }

    /**
     * One model's fields, as the instance describes them.
     *
     * Only `string`, `help` and `type` are documented attribute names. Anything else an instance happens to return is ignored
     * rather than relied on, because a field this integration decided was "required" on the strength of an undocumented
     * attribute would refuse to post for a reason nobody could look up.
     *
     * @return array<string, array{type: string, label: string|null}>
     */
    private function fieldsOf(SalesChannelAccount $connection, string $model): array
    {
        $answer = $this->client->fieldsGet($connection, $model);
        $described = is_array($answer->value) ? $answer->value : [];

        $fields = [];
        foreach ($described as $name => $description) {
            if (!is_string($name) || !is_array($description)) {
                continue;
            }
            $fields[$name] = [
                'type' => (string) ($description['type'] ?? ''),
                'label' => isset($description['string']) ? (string) $description['string'] : null,
            ];
        }

        return $fields;
    }

    /**
     * The company this connection posts into.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function company(SalesChannelAccount $connection): array
    {
        $chosen = (int) $connection->setting('company_id', 0);
        $fields = ['id', 'name', 'currency_id'];

        if ($chosen > 0) {
            $answer = $this->client->searchRead($connection, 'res.company', [['id', '=', $chosen]], $fields, limit: 1);
            $record = $answer->records()[0] ?? null;

            return $record === null
                ? [[], ['odoo_chosen_company_is_not_in_this_instance:' . $chosen]]
                : [$this->readable($record), []];
        }

        $answer = $this->client->searchRead($connection, 'res.company', [], $fields, limit: 5);
        $companies = $answer->records();
        if (!$answer->ok()) {
            return [[], ['odoo_companies_could_not_be_read']];
        }
        if ($companies === []) {
            return [[], ['odoo_instance_has_no_company']];
        }
        if (count($companies) > 1) {
            // Posting into the wrong company reads as correct on every screen until somebody consolidates. A person picks.
            return [[], ['odoo_several_companies_and_none_chosen']];
        }

        return [$this->readable($companies[0]), []];
    }

    /**
     * Odoo returns a many-to-one as `[id, label]`. The id is what a later call needs and the label is what a person reads, so
     * both are kept and neither is guessed from the other.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function readable(array $record): array
    {
        $currency = $record['currency_id'] ?? null;

        return [
            'id' => (int) ($record['id'] ?? 0),
            'name' => (string) ($record['name'] ?? ''),
            'currency_id' => is_array($currency) ? (int) ($currency[0] ?? 0) : (int) $currency,
            'currency_name' => is_array($currency) ? (string) ($currency[1] ?? '') : '',
        ];
    }

    /**
     * Odoo's own currency ids, by the code the instance calls each one — **read, and then CHECKED against something already
     * known.**
     *
     * A foreign-currency cost has to carry its original figure, that figure's currency and the rate, or the entry cannot be
     * checked against the invoice it came from. Carrying it needs Odoo's `res.currency` id, and an id is not something to
     * invent. Odoo's reference has no `res.currency` page, so which field holds the ISO code is not documented — and a map built
     * on the wrong field would post every foreign figure against the wrong currency, which is worse than refusing it.
     *
     * So the list is proved against a fact read separately: the company's own `currency_id` and the label Odoo returned with it.
     * A many-to-one comes back as `[id, label]`, so the company read already knows BOTH the id of the currency the books are
     * kept in and what Odoo displays for it. If the row with that id does not carry that same text, the field read here is not
     * the one the label comes from, and the whole list is dropped — the builder then refuses foreign figures by name, exactly as
     * it did before any list was read.
     *
     * @param array<string, mixed> $company
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private function currencies(SalesChannelAccount $connection, array $company): array
    {
        $answer = $this->client->searchRead($connection, 'res.currency', [], ['id', 'name'], limit: self::CURRENCY_CEILING);
        if (!$answer->ok()) {
            return [[], ['odoo_currencies_could_not_be_read']];
        }

        $map = [];
        foreach ($answer->records() as $record) {
            $code = mb_strtoupper(trim((string) ($record['name'] ?? '')));
            $id = (int) ($record['id'] ?? 0);
            if ($code === '' || $id < 1) {
                continue;
            }
            // First row wins for a repeated code, and a repeat is itself a reason not to trust the list: two ids for one code
            // means this field is not the identifier it looks like.
            if (isset($map[$code]) && $map[$code] !== $id) {
                return [[], ['odoo_currency_codes_are_not_unique']];
            }
            $map[$code] = $id;
        }

        $companyCurrencyId = (int) ($company['currency_id'] ?? 0);
        $companyCurrencyCode = mb_strtoupper(trim((string) ($company['currency_name'] ?? '')));
        if ($companyCurrencyId < 1 || $companyCurrencyCode === '') {
            // Nothing to prove the list against. It may be perfectly good; it is simply unverified, and this integration does
            // not post money on an unverified identifier.
            return [[], ['odoo_currency_list_could_not_be_checked']];
        }
        if (($map[$companyCurrencyCode] ?? null) !== $companyCurrencyId) {
            return [[], ['odoo_currency_list_does_not_match_the_company_currency']];
        }

        return [$map, []];
    }

    /** How many of something the instance holds, for a person deciding whether they are looking at the right database. */
    private function countOf(SalesChannelAccount $connection, string $model): int
    {
        $answer = $this->client->searchCount($connection, $model, []);

        return $answer->ok() && is_numeric($answer->value) ? (int) $answer->value : 0;
    }
}
