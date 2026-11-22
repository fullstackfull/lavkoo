<?php

namespace App\Console\Commands;

use App\Models\PaymentGatewayAccount;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\GatewayAccountSecrets;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Registers or changes one company's account at one gateway, in one environment (D2-07, D2-08).
 *
 * The credentials are asked for at the prompt and HIDDEN as they are typed. They are deliberately not options: an option is written to the shell
 * history, to the process list of every user on the machine, and to whatever records the command line of a deploy. Nothing here prints a credential
 * back, and a value that is not typed keeps the one stored.
 *
 * It writes the registry only. A payment that was already stamped with an account keeps that stamp, so changing or disabling an account never moves a
 * payment already taken; and nothing is stamped at all until GCC_PAYMENT_ACCOUNTS is on.
 */
class PaymentGatewayAccountUpsertCommand extends Command
{
    protected $signature = 'gcc:payment-account:upsert
        {country : the storefront country whose company this account belongs to (for example KW)}
        {gateway : the gateway key (one of the keys listed in gcc.storefronts.payment_accounts.gateways)}
        {environment : test or live}
        {--label= : a name for the account, for the operator}
        {--company= : the id of the company in legal_entities; the country\'s registered company when omitted}
        {--secrets= : which credentials to be prompted for, comma separated (for example secret_key,public_key,hmac,webhook_secret)}
        {--new-webhook-token : issue a new unguessable token for this account\'s own webhook address}
        {--activate : make the account active, so new payments of the country are taken through it}
        {--deactivate : make the account inactive: new payments are taken through the platform settings row, payments already stamped keep this account}';

    protected $description = "Register or change one company's gateway account and its credentials";

    public function handle(GatewayAccountRegistry $registry, GatewayAccountSecrets $secrets, LegalEntityRegistry $companies): int
    {
        $country = strtoupper(trim((string) $this->argument('country')));
        $gateway = trim((string) $this->argument('gateway'));
        $environment = GatewayAccountRegistry::environment(trim((string) $this->argument('environment')));

        $refusal = $this->refusal(registry: $registry, country: $country, gateway: $gateway, environment: $environment);

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $account = PaymentGatewayAccount::query()
            ->where('storefront_country', $country)
            ->where('gateway', $gateway)
            ->where('environment', $environment)
            ->first() ?? new PaymentGatewayAccount([
                'storefront_country' => $country,
                'gateway' => $gateway,
                'environment' => $environment,
                'status' => false,
            ]);

        $account->storefront_country = $country;
        $account->gateway = $gateway;
        $account->environment = $environment;

        $this->applyGivenValues(account: $account, companies: $companies);
        $account->save();

        $asked = $this->askedSecrets();

        if ($asked !== []) {
            $secrets->put(account: $account, secrets: $this->prompted(names: $asked));
        }

        $registry->flush();

        $this->info(sprintf(
            '%s account %d for %s (%s), %s. Credentials held: %s.',
            $gateway,
            (int) $account->id,
            $country,
            $environment,
            $account->status ? 'active' : 'inactive',
            implode(', ', $secrets->namesHeld(account: $account->refresh())) ?: 'none',
        ));

        return self::SUCCESS;
    }

    private function refusal(GatewayAccountRegistry $registry, string $country, string $gateway, string $environment): ?string
    {
        if (!$registry->isMigrated()) {
            return 'The payment_gateway_accounts table is not there yet. Run the migrations first.';
        }

        if (!in_array($country, StorefrontResolver::fromConfig()->configuredCountries(), true)) {
            return sprintf('%s is not one of the configured storefront countries.', $country);
        }

        if (!$registry->covers(gateway: $gateway)) {
            return sprintf('%s is not one of the gateways that may be configured per company (gcc.storefronts.payment_accounts.gateways).', $gateway);
        }

        if ($this->argument('environment') !== $environment) {
            return 'The environment is test or live.';
        }

        return null;
    }

    private function applyGivenValues(PaymentGatewayAccount $account, LegalEntityRegistry $companies): void
    {
        $label = $this->option('label');

        if (is_string($label) && trim($label) !== '') {
            $account->label = Str::limit(trim($label), 191, '');
        }

        $company = $this->option('company');

        if (is_numeric($company)) {
            $account->legal_entity_id = (int) $company;
        } elseif ($account->legal_entity_id === null) {
            $account->legal_entity_id = $companies->forCountry($account->storefront_country)?->id;
        }

        if ($this->option('new-webhook-token') || ($account->webhook_path_token === null && !$account->exists)) {
            $account->webhook_path_token = Str::random(48);
        }

        if ($this->option('activate')) {
            $account->status = true;
        }

        if ($this->option('deactivate')) {
            $account->status = false;
        }
    }

    /** @return list<string> */
    private function askedSecrets(): array
    {
        $given = (string) ($this->option('secrets') ?? '');
        $allowed = array_merge(GatewayAccountSecrets::NAMES, [GatewayAccountSecrets::WEBHOOK_SECRET]);

        return array_values(array_intersect(array_map('trim', explode(',', $given)), $allowed));
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, string|null>
     */
    private function prompted(array $names): array
    {
        $values = [];

        foreach ($names as $name) {
            // Hidden, and blank keeps what is stored: GatewayAccountSecrets ignores a blank value.
            $values[$name] = $this->secret(sprintf('%s (hidden; leave empty to keep the stored one)', $name));
        }

        return $values;
    }
}
