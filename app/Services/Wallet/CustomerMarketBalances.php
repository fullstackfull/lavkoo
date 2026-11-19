<?php

namespace App\Services\Wallet;

use App\Contracts\Repositories\CustomerMarketLoyaltyAccountRepositoryInterface;
use App\Contracts\Repositories\CustomerMarketWalletAccountRepositoryInterface;
use App\Contracts\Repositories\MarketAccounts\MarketAccountRepositoryInterface;
use App\Enums\Wallet\MarketBalanceKind;
use App\Enums\Wallet\MarketBalanceRefusal;
use App\Models\User;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one place the application asks what a customer holds in a market, and the one place that changes it (GCC-15; E-01, E-02, E-03).
 *
 * While `gcc.storefronts.market_accounts.enabled` is off, which is how it ships, a read answers from the one balance the `users` row has
 * always held (`wallet_balance`, `loyalty_point`) whatever market is asked for, and no account table is touched — not even to ask whether
 * it is there. A write is refused as `Dormant`, because the four wallet writers and the two point writers are still the authority on that
 * one balance and a second writer of the same column would be a second truth; a caller falls back to the writer it uses today. So nothing a
 * Saudi customer does is reached by this service while the key is off.
 *
 * While it is on, and once the expand migration has run, each market has its own account: a balance is that market's own, a credit or a
 * debit touches the account of the market it names and no other, and no path here moves or converts money between two markets. A market
 * with no account yet reads zero; a credit opens the account before it adds to it, and a debit never opens one, so a refusal writes nothing.
 *
 * Nothing in the application calls this service yet. The writers are wired market by market in a later slice (E-07), and a caller that pays
 * for an order has to answer a refusal with the refusal its own transaction rolls back on (E-10), never ignore it.
 */
#[Singleton]
final class CustomerMarketBalances
{
    /**
     * The floor every debit is held to while the account row is locked: a debit may not take the account below zero. The tolerance absorbs
     * float representation error only. `App\Services\Wallet\WalletOverdraft` on the branch gcc/p0-wallet-debit-result-handling is the same
     * rule for the one global balance; when the branches meet, this call becomes that class's.
     */
    private const OVERDRAFT_TOLERANCE = 0.000001;

    /** How long a "no" about the tables is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array<string, array{value: bool, at: int}> */
    private array $tableAnswers = [];

    public function __construct(
        private readonly CustomerMarketWalletAccountRepositoryInterface $walletAccounts,
        private readonly CustomerMarketLoyaltyAccountRepositoryInterface $loyaltyAccounts,
    ) {
    }

    /** Whether market accounts are the authority on a balance. Storefronts have to be on too: without them every request is the default market. */
    public function isEnabled(): bool
    {
        return (bool) config('gcc.storefronts.market_accounts.enabled') && StorefrontResolver::fromConfig()->isEnabled();
    }

    /**
     * What the customer holds in this market. Zero for a market they have no account in, and zero for a market that is not configured, so a
     * reader never fails over a balance that simply is not there. While the key is off this is the one global balance, for every market.
     */
    public function amount(MarketBalanceKind $kind, int|string $customerId, string $market): float
    {
        if (!$this->answersFromAccounts($kind)) {
            return $this->legacyAmount(kind: $kind, customerId: $customerId);
        }

        $code = $this->configuredMarket($market);

        if ($code === null) {
            return 0.0;
        }

        return $this->accounts($kind)->amountOf(customerId: $customerId, market: $code) ?? 0.0;
    }

    public function credit(MarketBalanceKind $kind, int|string $customerId, string $market, float $amount): MarketBalanceChange
    {
        return $this->change(kind: $kind, customerId: $customerId, market: $market, amount: $amount, isDebit: false);
    }

    public function debit(MarketBalanceKind $kind, int|string $customerId, string $market, float $amount): MarketBalanceChange
    {
        return $this->change(kind: $kind, customerId: $customerId, market: $market, amount: $amount, isDebit: true);
    }

    /**
     * The account row is locked from the moment its amount is read until the write commits, so two overlapping credits cannot both read the
     * same amount and the later one replace the earlier. The lock is on the account, not on the customer: two markets of one customer are
     * two independent accounts and never wait for each other.
     *
     * A credit opens the account when it is not there; a debit never does, because there is nothing in an account that does not exist to
     * take. So a refusal writes nothing at all, not even an empty account.
     */
    private function change(MarketBalanceKind $kind, int|string $customerId, string $market, float $amount, bool $isDebit): MarketBalanceChange
    {
        $code = strtoupper(trim($market));

        if (!$this->answersFromAccounts($kind)) {
            return MarketBalanceChange::refused(refusal: MarketBalanceRefusal::Dormant, market: $code);
        }

        if ($this->configuredMarket($code) === null) {
            return MarketBalanceChange::refused(refusal: MarketBalanceRefusal::UnknownMarket, market: $code);
        }

        if (!($amount > 0)) {
            return MarketBalanceChange::refused(refusal: MarketBalanceRefusal::NotAnAmount, market: $code);
        }

        $currencyCode = StorefrontResolver::fromConfig()->currencyOf($code);
        $accounts = $this->accounts($kind);

        return DB::transaction(function () use ($accounts, $customerId, $code, $currencyCode, $amount, $isDebit): MarketBalanceChange {
            $account = $isDebit
                ? $accounts->locked(customerId: $customerId, market: $code)
                : $accounts->lockedOrOpened(customerId: $customerId, market: $code, currencyCode: $currencyCode, legalEntity: $this->legalEntity());

            if ($account === null) {
                return MarketBalanceChange::refused(refusal: MarketBalanceRefusal::Overdraft, market: $code, currencyCode: $currencyCode);
            }

            if (strcasecmp($account->currencyCode, $currencyCode) !== 0) {
                return MarketBalanceChange::refused(refusal: MarketBalanceRefusal::CurrencyMismatch, market: $code, currencyCode: $account->currencyCode, amount: $account->amount);
            }

            if ($isDebit && $account->amount - $amount < -self::OVERDRAFT_TOLERANCE) {
                return MarketBalanceChange::refused(refusal: MarketBalanceRefusal::Overdraft, market: $code, currencyCode: $currencyCode, amount: $account->amount);
            }

            $held = $isDebit ? $account->amount - $amount : $account->amount + $amount;
            $accounts->writeAmount(accountId: $account->id, amount: $held);

            return MarketBalanceChange::succeeded(amount: $held, market: $code, currencyCode: $currencyCode);
        });
    }

    /** The one global balance, read the way every reader read it before the accounts existed. Null for a customer who is not there, read as zero. */
    private function legacyAmount(MarketBalanceKind $kind, int|string $customerId): float
    {
        return (float) User::query()->whereKey($customerId)->value($kind->legacyColumn());
    }

    private function accounts(MarketBalanceKind $kind): MarketAccountRepositoryInterface
    {
        return match ($kind) {
            MarketBalanceKind::Wallet => $this->walletAccounts,
            MarketBalanceKind::Loyalty => $this->loyaltyAccounts,
        };
    }

    /** The market as `gcc.storefronts.countries` names it, or null when it names no such market. */
    private function configuredMarket(string $market): ?string
    {
        $code = strtoupper(trim($market));

        return in_array($code, StorefrontResolver::fromConfig()->configuredCountries(), true) ? $code : null;
    }

    /** The company that sells in these markets, recorded on an account as it is opened. Null while none is configured. */
    private function legalEntity(): ?string
    {
        $entity = config('gcc.storefronts.legal.legal_entity');

        return is_string($entity) && trim($entity) !== '' ? trim($entity) : null;
    }

    /**
     * Whether this balance is answered from its account table: the key is on and the table is there. The key is read first, so while it is
     * off not even the question "is the table there" reaches the database.
     */
    private function answersFromAccounts(MarketBalanceKind $kind): bool
    {
        return $this->isEnabled() && $this->tableReady($kind->accountsTable());
    }

    private function tableReady(string $table): bool
    {
        $answer = $this->tableAnswers[$table] ?? null;

        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::NEGATIVE_ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $this->tableAnswers[$table] = ['value' => $ready = Schema::hasTable($table), 'at' => time()];

        return $ready;
    }
}
