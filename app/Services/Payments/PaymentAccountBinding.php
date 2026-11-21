<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentRequest;
use App\Services\Fulfillment\Support\StoreCurrencyRateSource;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Container\Attributes\Singleton;

/**
 * Binds a payment to one company's gateway account when it starts, and writes down what it belongs to (D2-07, D2-10).
 *
 * The record goes into `payment_requests.additional_data`, beside the storefront App\Services\Storefront\PaymentStorefront records, because the real
 * schema of that table is not a migration of this project (it comes from the installed SQL dump) and columns on it are the owner's decision. The shape
 * is PaymentTransactionRecord, so moving it to columns later changes where it is written and not what is written.
 *
 * Nothing is recorded while payment accounts are off, before the migration has run, for a gateway the owner has not listed, or for a market with no
 * active account — which is every payment today, including every Saudi one, so a payment starts, settles and refunds exactly as it does now.
 *
 * What a payment is read as is what it was stamped with, never a fresh resolution: `accountOf()` goes to the stamped id. A payment stamped with the
 * Kuwait account is verified, settled and refunded through the Kuwait account after that account has been edited, after it has been disabled, and
 * after another market's account has become the only active one.
 */
#[Singleton]
final class PaymentAccountBinding
{
    /** The key the record is written under inside additional_data. */
    public const KEY = 'gcc_payment_account';

    public function __construct(
        private readonly GatewayAccountRegistry $registry,
        private readonly LegalEntityRegistry $companies,
        private readonly StorefrontCurrency $currencies,
        private readonly StoreCurrencyRateSource $rates,
    ) {
    }

    /**
     * The same additional data, with the market, company, account and rate this payment belongs to — or untouched, which is the default.
     *
     * @param array<string, mixed> $additionalData what a payment starts with
     *
     * @return array<string, mixed>
     */
    public function record(array $additionalData, string $gateway, string $currencyCode): array
    {
        if (isset($additionalData[self::KEY]) || !$this->registry->isEnabled() || !$this->registry->covers(gateway: $gateway)) {
            return $additionalData;
        }

        $market = StorefrontCatalog::country();

        if ($market === null) {
            return $additionalData;
        }

        $account = $this->registry->forStorefront(country: $market, gateway: $gateway, environment: $this->environmentFor(gateway: $gateway));

        if ($account === null) {
            return $additionalData;
        }

        return $additionalData + [self::KEY => $this->recordFor(account: $account, market: $market, currencyCode: $currencyCode)->toStored()];
    }

    /** What this payment was stamped with, or null for a payment that carries no stamp — which is every payment placed before the switch was thrown. */
    public function of(PaymentRequest|array|null $payment): ?PaymentTransactionRecord
    {
        $additionalData = $this->additionalDataOf(payment: $payment);
        $stored = $additionalData[self::KEY] ?? null;

        return is_array($stored) ? PaymentTransactionRecord::fromStored(stored: $stored) : null;
    }

    /**
     * The account this payment was started against, whatever the registry holds now. Null for a payment with no stamp, and for a stamp whose account
     * row is gone: an account that has been deleted answers nothing rather than letting another company's account stand in for it.
     */
    public function accountOf(PaymentRequest|array|null $payment): ?PaymentGatewayAccount
    {
        $record = $this->of(payment: $payment);

        return $record?->accountId === null ? null : $this->registry->byId(id: $record->accountId);
    }

    /** Whether this payment may be settled through that account: a payment stamped with one company's account is settled through no other. */
    public function isTakenThrough(PaymentRequest|array|null $payment, PaymentGatewayAccount $account): bool
    {
        $record = $this->of(payment: $payment);

        return $record?->accountId === null || $record->accountId === (int) $account->id;
    }

    /** The record a payment of this market through this account carries. */
    private function recordFor(PaymentGatewayAccount $account, string $market, string $currencyCode): PaymentTransactionRecord
    {
        $company = $this->companyOf(account: $account);

        return new PaymentTransactionRecord(
            market: strtoupper($market),
            companyId: $company === null ? null : (int) $company['legal_entity_id'],
            company: $company ?? [],
            accountId: (int) $account->id,
            gateway: (string) $account->gateway,
            environment: (string) $account->environment,
            currency: strtoupper($currencyCode),
            exchangeRate: $this->rateInForce(currencyCode: $currencyCode, market: $market),
            recordedAt: now()->toIso8601String(),
        );
    }

    /** @return array<string, int|string|null>|null the company's frozen identity, as an order's stamp carries it */
    private function companyOf(PaymentGatewayAccount $account): ?array
    {
        $entity = $account->legal_entity_id === null
            ? $this->companies->forCountry((string) $account->storefront_country)
            : $this->companies->byId($account->legal_entity_id);

        return $entity === null ? null : $this->companies->identityOf($entity);
    }

    /**
     * The rate that turns a stored amount into the currency this payment is taken in, as plain text.
     *
     * A storefront that trades in its own currency stores its amounts in it, so the rate is one and there is nothing to convert. Otherwise it is the
     * rate the platform's own currency table holds for the code right now, which is the rate the amount was computed with; a column that holds no
     * usable rate (see ExchangeRate) records none rather than a guess.
     */
    private function rateInForce(string $currencyCode, string $market): ?string
    {
        if ($this->currencies->isEnabled() && $this->currencies->currencyOfSales($market)?->code !== null) {
            return '1';
        }

        $rate = $this->rates->rate(strtoupper($currencyCode));

        return $rate === null ? null : rtrim(rtrim(number_format($rate, 10, '.', ''), '0'), '.');
    }

    /** Which environment a gateway is configured for right now: the mode of its platform settings row, which is what the gateway is called in today. */
    private function environmentFor(string $gateway): string
    {
        $row = app(PaymentGatewayMode::class)->of(gateway: $gateway);

        return GatewayAccountRegistry::environment($row);
    }

    /** @param PaymentRequest|array<string, mixed>|null $payment @return array<string, mixed> */
    private function additionalDataOf(PaymentRequest|array|null $payment): array
    {
        if ($payment === null) {
            return [];
        }

        $raw = is_array($payment) ? ($payment['additional_data'] ?? null) : $payment->additional_data;

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
