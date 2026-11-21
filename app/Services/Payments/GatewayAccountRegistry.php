<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewayAccount;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Schema;

/**
 * Whose account a gateway takes a payment through (D2-07): the account registered for a storefront country, a gateway and an environment.
 *
 * Switched off by default, and it ships empty. While it is off, before its migration has run, while the gateway is not one the owner has listed, or
 * while a country has no active account, this answers null and every caller reads the one platform-wide addon_settings row exactly as today — which is
 * how the Saudi configuration keeps working with no data change at all (GCC-19).
 *
 * An account is changed in place. A payment keeps the account it was started against (App\Services\Payments\PaymentAccountBinding), so a later edit or
 * a disabling never moves a payment already taken to another account, and never rewrites what it was taken through.
 */
#[Singleton]
final class GatewayAccountRegistry
{
    /** How a payment names the account it was taken through. */
    public const COLUMN = 'gateway_account_id';

    public const ENVIRONMENT_LIVE = 'live';
    public const ENVIRONMENT_TEST = 'test';

    /** How long an answer is remembered, so a queue worker notices a change of the registry without a restart. */
    private const ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private ?array $tableAnswer = null;

    /** @var array<string, array{account: ?PaymentGatewayAccount, at: int}> */
    private array $accounts = [];

    public function isEnabled(): bool
    {
        return (bool) config('gcc.storefronts.payment_accounts.enabled') && StorefrontResolver::fromConfig()->isEnabled();
    }

    /** Whether the table exists, for a command that has to say "run the migrations" before it writes. */
    public function isMigrated(): bool
    {
        return $this->tableReady();
    }

    /** Whether this gateway may be configured per company at all. A gateway the owner has not listed is always read from the platform row. */
    public function covers(string $gateway): bool
    {
        return in_array($gateway, (array) config('gcc.storefronts.payment_accounts.gateways', []), true);
    }

    /** One of the two environment names; anything else is read as the test one, so a malformed value never reaches a live account. */
    public static function environment(?string $environment): string
    {
        return $environment === self::ENVIRONMENT_LIVE ? self::ENVIRONMENT_LIVE : self::ENVIRONMENT_TEST;
    }

    /**
     * The active account a storefront takes this gateway's payments through, or null while the registry is off, not migrated, not covering the gateway,
     * or holding no active row for that country, gateway and environment.
     */
    public function forStorefront(string $country, string $gateway, string $environment): ?PaymentGatewayAccount
    {
        if (!$this->isEnabled() || !$this->covers(gateway: $gateway) || !$this->tableReady()) {
            return null;
        }

        $country = strtoupper(trim($country));
        $environment = self::environment($environment);
        $key = $country . '|' . $gateway . '|' . $environment;
        $remembered = $this->accounts[$key] ?? null;

        if ($remembered !== null && time() - $remembered['at'] < self::ANSWER_SECONDS) {
            return $remembered['account'];
        }

        $account = PaymentGatewayAccount::query()
            ->where('storefront_country', $country)
            ->where('gateway', $gateway)
            ->where('environment', $environment)
            ->where('status', true)
            ->first();

        $this->accounts[$key] = ['account' => $account, 'at' => time()];

        return $account;
    }

    /**
     * The account of this id, whatever its status is now: a payment is verified, settled and refunded through the account it was started against, and an
     * account disabled after the customer went to the gateway must not turn a paid payment into an unsettleable one. Null while the registry is off or
     * not migrated, and for an id the table does not hold.
     */
    public function byId(int|string $id): ?PaymentGatewayAccount
    {
        if (!$this->isEnabled() || !$this->tableReady()) {
            return null;
        }

        $key = 'id:' . $id;
        $remembered = $this->accounts[$key] ?? null;

        if ($remembered !== null && time() - $remembered['at'] < self::ANSWER_SECONDS) {
            return $remembered['account'];
        }

        $account = PaymentGatewayAccount::query()->whereKey($id)->first();
        $this->accounts[$key] = ['account' => $account, 'at' => time()];

        return $account;
    }

    /** The account a webhook address names, by the unguessable token in its path. Null for an unknown or disabled one. */
    public function byWebhookToken(string $token): ?PaymentGatewayAccount
    {
        if (!$this->isEnabled() || !$this->tableReady() || trim($token) === '') {
            return null;
        }

        $key = 'hook:' . $token;
        $remembered = $this->accounts[$key] ?? null;

        if ($remembered !== null && time() - $remembered['at'] < self::ANSWER_SECONDS) {
            return $remembered['account'];
        }

        $account = PaymentGatewayAccount::query()->where('webhook_path_token', $token)->where('status', true)->first();
        $this->accounts[$key] = ['account' => $account, 'at' => time()];

        return $account;
    }

    /** The company an account belongs to: the one it names, else the one registered for its country. Null while legal entities are off or it has none. */
    public function companyOf(PaymentGatewayAccount $account): ?int
    {
        if ($account->legal_entity_id !== null) {
            return (int) $account->legal_entity_id;
        }

        $entity = app(LegalEntityRegistry::class)->forCountry((string) $account->storefront_country);

        return $entity === null ? null : (int) $entity->id;
    }

    /** Forgets what was remembered, after the registry was changed in this process. */
    public function flush(): void
    {
        $this->accounts = [];
        $this->tableAnswer = null;
    }

    private function tableReady(): bool
    {
        $answer = $this->tableAnswer;

        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $this->tableAnswer = ['value' => Schema::hasTable('payment_gateway_accounts'), 'at' => time()];

        return $this->tableAnswer['value'];
    }
}
