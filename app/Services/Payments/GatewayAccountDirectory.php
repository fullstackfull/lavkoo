<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewayAccount;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\Storefront\LegalEntityRegistry;
use Illuminate\Container\Attributes\Singleton;

/**
 * What an operator may be shown about each company's gateway account (D2-08, D2-09): which market and company it belongs to, which environment it is,
 * its own webhook address, and WHETHER it holds each credential.
 *
 * Never what a credential is. A row of this directory carries the NAMES of the secrets an account holds and nothing else, so a screen, a log line or a
 * console table built from it cannot print one — the same treatment App\Services\MyFatoorah\GatewayConfig gives the platform row's display shape.
 *
 * Empty while payment accounts are off or before the migration has run, so every screen that reads it is exactly what it is today.
 */
#[Singleton]
final class GatewayAccountDirectory
{
    /**
     * The one gateway whose notifications the per-account address reads.
     *
     * POST /api/payments/account/{account}/webhook hands a delivery to ONE gateway's processor (App\Http\Controllers\Api\PaymentAccountWebhookController),
     * and refuses an account of any other gateway before the body is read at all. So that address is an address for an account of this gateway, and an
     * account of any other gateway is notified at no address of its own.
     */
    private const GATEWAY_SERVED_AT_THAT_ADDRESS = GatewayConfigRepository::KEY;

    public function __construct(
        private readonly GatewayAccountRegistry $registry,
        private readonly GatewayAccountSecrets $secrets,
        private readonly LegalEntityRegistry $companies,
    ) {
    }

    /**
     * One row per configured account, ordered so the same list reads the same way twice.
     *
     * @return list<array{id: int, market: string, gateway: string, environment: string, company: ?string, active: bool, webhook_url: ?string, secrets_held: list<string>, has_webhook_secret: bool}>
     */
    public function rows(?string $gateway = null): array
    {
        if (!$this->registry->isEnabled() || !$this->registry->isMigrated()) {
            return [];
        }

        $accounts = PaymentGatewayAccount::query()
            ->when($gateway !== null, static fn ($query) => $query->where('gateway', $gateway))
            ->orderBy('storefront_country')
            ->orderBy('gateway')
            ->orderBy('environment')
            ->orderBy('id')
            ->get();

        return $accounts->map(fn (PaymentGatewayAccount $account): array => $this->rowOf(account: $account))->all();
    }

    /**
     * The address this account's notifications are delivered to, or null for an account that is notified at no address of its own.
     *
     * Null for an account with no token, because an account without one receives nothing. Null as well for an account of a gateway that is not the one
     * served at that address: that address refuses such an account outright, so the URL would be an address at which this account is told nothing. The
     * one gateway the owner's shipped list holds is Paymob, which sends no server-to-server notification at all -- its confirmation is the customer's
     * return to a platform-wide address -- so a payment taken through a Paymob account records no address and expects the platform one (GCC-21).
     */
    public function webhookUrlOf(PaymentGatewayAccount $account): ?string
    {
        if ((string) $account->gateway !== self::GATEWAY_SERVED_AT_THAT_ADDRESS) {
            return null;
        }

        $token = (string) $account->webhook_path_token;

        return $token === '' ? null : route('payments.account.webhook', ['account' => $token]);
    }

    /** @return array{id: int, market: string, gateway: string, environment: string, company: ?string, active: bool, webhook_url: ?string, secrets_held: list<string>, has_webhook_secret: bool} */
    private function rowOf(PaymentGatewayAccount $account): array
    {
        $held = $this->secrets->namesHeld(account: $account);

        return [
            'id' => (int) $account->id,
            'market' => (string) $account->storefront_country,
            'gateway' => (string) $account->gateway,
            'environment' => (string) $account->environment,
            'company' => $this->companyNameOf(account: $account),
            'active' => (bool) $account->status,
            'webhook_url' => $this->webhookUrlOf(account: $account),
            'secrets_held' => array_values(array_diff($held, [GatewayAccountSecrets::WEBHOOK_SECRET])),
            'has_webhook_secret' => in_array(GatewayAccountSecrets::WEBHOOK_SECRET, $held, true),
        ];
    }

    private function companyNameOf(PaymentGatewayAccount $account): ?string
    {
        $entity = $account->legal_entity_id === null
            ? $this->companies->forCountry((string) $account->storefront_country)
            : $this->companies->byId($account->legal_entity_id);

        return $entity?->legal_name;
    }
}
