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
 * Kuwait account is started, verified and settled through the Kuwait account after that account has been edited, after it has been disabled, and after
 * another market's account has become the only active one.
 *
 * The stamp also records the ADDRESS this payment's confirmation is expected at (GCC-21): the company account's own notification address, or none,
 * which is read as the platform-wide address. It is the address and not the account id, because an account row can be deleted and `accountOf()` then
 * answers null — see PaymentTransactionRecord. Initiation sends that address to the gateway, and a gateway's platform-wide address reads it through
 * App\Services\Payments\ConfirmationAddresses to decide whether the payment in front of it is one it is expected to confirm.
 *
 * The gateway refund path does NOT read this yet (D2-16): it still goes to the one platform settings row, so a refund of a company's payment is not
 * drawn on that company's account. This is the single reader of the stamp that is missing, and it is named here rather than implied.
 */
#[Singleton]
final class PaymentAccountBinding
{
    /** The key the record is written under inside additional_data. */
    public const KEY = 'gcc_payment_account';

    public function __construct(
        private readonly GatewayAccountRegistry $registry,
        private readonly GatewayAccountDirectory $addresses,
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

    /**
     * Was this payment taken through this account?
     *
     * The stamp, never a fresh resolution: an account edited, disabled or deleted since the payment started is still the account the payment was taken
     * through. A payment carrying no stamp was taken through no company account and so belongs to none of them.
     */
    public function isStampedWith(PaymentRequest|array|null $payment, PaymentGatewayAccount $account): bool
    {
        $stamped = $this->of(payment: $payment)?->accountId;

        return $stamped !== null && $stamped === (int) $account->id;
    }

    /**
     * The address this payment's confirmation is expected at (GCC-21): the one it RECORDED when it started, or the platform-wide address given here
     * for a payment that recorded none.
     *
     * Read as stored and never re-derived, which is the whole point: an account edited, disabled or deleted since the payment started does not move the
     * address the payment began under. A payment with no stamp — every payment placed today, every legacy payment and every Saudi payment — records
     * none and so expects the platform address, which is where its confirmation actually goes.
     *
     * The platform address is the caller's, because it is the gateway's own: each gateway has its own public notification address, or none at all, and
     * this class knows about accounts and not about gateways' addresses.
     */
    public function confirmationAddressOf(PaymentRequest|array|null $payment, string $platformAddress): string
    {
        return $this->of(payment: $payment)?->confirmationAddress ?? $platformAddress;
    }

    /**
     * Does this payment positively expect its confirmation at an address OTHER than this one (GCC-21)?
     *
     * Keyed on a POSITIVE fact — a RECORDED address, and one that is not the address asking — and never on the absence of a record. That direction is
     * the whole care of this method. A payment stamped before the address was ever recorded has nothing stored, and for that payment "the platform
     * address" is not a guess to be refused but the correct answer: it is where its confirmation was asked for and where it will arrive. Reading an
     * absence as "not the platform's" would strand it.
     *
     * So three stored shapes answer FALSE here, and each is a payment whose confirmation really is expected at the platform address:
     *
     *   NO STAMP AT ALL — every payment placed before any of this existed, every payment while accounts are off, and every payment of a market with no
     *   account of its own, the Saudi ones included.
     *
     *   A STAMP WITH NO ADDRESS ON IT — a payment taken through a company account of a gateway that is notified at no address of its own, which is
     *   every payment of the one gateway the owner's shipped list holds. It belongs to a company account and still expects the platform address,
     *   because its gateway has no other to be told about. `toStored()` leaves the key out rather than storing a null.
     *
     *   A STAMP WHOSE ADDRESS IS NOT USABLE TEXT — an empty string, a stored null, a list or a boolean under the key, which
     *   `PaymentTransactionRecord` reads as no address. `additional_data` is a JSON column this application is not the only writer of, so the shape is
     *   reachable without this class producing it, and it means the same thing: no address was recorded, so the platform address is what the payment
     *   expects. A stored NUMBER is NOT one of these — see below.
     *
     * A stored NUMBER is the one shape that goes the other way: `PaymentTransactionRecord::text()` casts an int or a float to a string, which the
     * exchange rate it shares that helper with needs, so a stored `0` reads as the address "0" and this answers TRUE for a platform address. Nothing
     * writes it, and it costs nothing: such a payment is treated exactly as one carrying a real company address, so its own account's address still
     * confirms it and it is not stranded. Pinned in both directions by a test rather than left to be worked out.
     *
     * The comparison is the address text as recorded against the address text now. It cannot turn a company address into the platform one — a recorded
     * value only ever came from a per-account route and the platform addresses are routes of their own — so a change of host or scheme can only make a
     * company-bound payment answer TRUE, which is the safe direction. The caller gives the address because each gateway has a public address of its
     * own, or none at all, and this class knows accounts and not gateways' addresses.
     */
    public function expectsAnAddressOtherThan(PaymentRequest|array|null $payment, string $address): bool
    {
        $recorded = $this->of(payment: $payment)?->confirmationAddress;

        return $recorded !== null && $recorded !== $address;
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
            confirmationAddress: $this->addresses->webhookUrlOf(account: $account),
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
