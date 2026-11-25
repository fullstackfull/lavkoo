<?php

namespace App\Services\Wallet;

use App\Contracts\Repositories\CustomerMarketLoyaltyAccountRepositoryInterface;
use App\Contracts\Repositories\CustomerMarketWalletAccountRepositoryInterface;
use App\Contracts\Repositories\MarketAccounts\MarketAccountRepositoryInterface;
use App\Enums\Wallet\MarketBalanceKind;
use App\Enums\Wallet\MarketBalanceRefusal;
use App\Models\User;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * A market here is one of `gcc.storefronts.countries`, configured, which is not the same as active: a credit to a market an operator has
 * switched off still opens an account, because the money exists whether or not the storefront is serving visitors. Only a market the
 * configuration does not name at all is refused.
 *
 * Nothing in the application calls this service yet. The writers are wired market by market in a later slice (E-07), and a caller that pays
 * for an order has to answer a refusal with the refusal its own transaction rolls back on (E-10), never ignore it.
 */
#[Singleton]
final class CustomerMarketBalances
{
    /**
     * The floor every debit is held to while the account row is locked. A debit that would leave the account below MINUS this much is
     * refused, so the floor is one millionth under zero rather than zero itself: the tolerance is there to absorb float representation
     * error, and it lets a debit of 1.0000009 against an account of 1 through, leaving about minus nine ten-millionths behind.
     * `App\Services\Wallet\WalletOverdraft` on the branch gcc/p0-wallet-debit-result-handling holds the one global balance to the same
     * floor with the same tolerance; when the branches meet, this call becomes that class's.
     */
    private const OVERDRAFT_TOLERANCE = 0.000001;

    /** How long a "no" about the tables is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array<string, array{value: bool, at: int}> */
    private array $tableAnswers = [];

    public function __construct(
        private readonly CustomerMarketWalletAccountRepositoryInterface $walletAccounts,
        private readonly CustomerMarketLoyaltyAccountRepositoryInterface $loyaltyAccounts,
        private readonly StorefrontCurrency $storefrontCurrency,
        private readonly LegalEntityRegistry $legalEntities,
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

    /**
     * Whether the customer has an account in this market at all, which an amount of zero does not answer: an account opened and emptied
     * holds zero, and so does a market the customer has never held anything in. A backfill has to tell them apart, because filling an
     * account that is already open would add to a balance that may already carry it. False while the accounts are dormant, where there is
     * no account to speak of.
     */
    public function hasAccount(MarketBalanceKind $kind, int|string $customerId, string $market): bool
    {
        if (!$this->answersFromAccounts($kind)) {
            return false;
        }

        $code = $this->configuredMarket($market);

        return $code !== null && $this->accounts($kind)->amountOf(customerId: $customerId, market: $code) !== null;
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
     * Moves an account that is already open by a signed amount, and never opens one: a credit of what is positive, a debit of what is
     * negative, and `NoAccount` for a customer who has no account in that market.
     *
     * It exists for the shadow write (E-07), which runs inside the transaction of the writer it mirrors. Opening an account from inside
     * that transaction is the one thing `MarketAccountRepository::open` says must not be done — both of InnoDB's orders deadlock — so a
     * mirror writes only where the backfill has already been, and says so where it has not.
     */
    public function moveOpenAccount(MarketBalanceKind $kind, int|string $customerId, string $market, float $delta): MarketBalanceChange
    {
        return $this->change(
            kind: $kind,
            customerId: $customerId,
            market: $market,
            amount: abs($delta),
            isDebit: $delta < 0,
            openWhenMissing: false,
        );
    }

    /**
     * The account row is locked from the moment its amount is read until the write commits, so two overlapping credits cannot both read the
     * same amount and the later one replace the earlier. The lock is on the account, not on the customer: two markets of one customer are
     * two independent accounts and never wait for each other.
     *
     * A credit opens the account when it is not there, BEFORE the transaction that locks it rather than inside it, so that the lock is
     * always a plain record lock on a row that exists and two writers racing for one new account queue instead of deadlocking (see
     * `MarketAccountRepository::open`). A debit never opens one, because there is nothing in an account that does not exist to take. Every
     * refusal a caller can reach is decided before the open — dormant, an unknown market, an amount that is not positive, a currency that
     * cannot be told, and a debit of what is not there — or by an account that was already open, so a refusal still leaves no empty
     * account behind.
     *
     * The currency is the one the market SELLS in, asked of `StorefrontCurrency`, not the code the country map is configured with: while the
     * storefront currency is off, or the configured currency is missing or inactive, a storefront's money is still the platform's own and
     * stamping the market's code on it would label the balance with a currency it is not in. There is no account to open then, so the write
     * is refused rather than guessed at.
     */
    private function change(MarketBalanceKind $kind, int|string $customerId, string $market, float $amount, bool $isDebit, bool $openWhenMissing = true): MarketBalanceChange
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

        $currencyCode = $this->currencyOfSales($code);

        if ($currencyCode === null) {
            return MarketBalanceChange::refused(refusal: MarketBalanceRefusal::CurrencyUnknown, market: $code);
        }

        $accounts = $this->accounts($kind);

        if (!$openWhenMissing && $accounts->amountOf(customerId: $customerId, market: $code) === null) {
            return MarketBalanceChange::refused(refusal: MarketBalanceRefusal::NoAccount, market: $code, currencyCode: $currencyCode);
        }

        if (!$isDebit && $openWhenMissing) {
            $accounts->open(customerId: $customerId, market: $code, currencyCode: $currencyCode, legalEntity: $this->legalEntity(market: $code));
        }

        return DB::transaction(function () use ($accounts, $customerId, $code, $currencyCode, $amount, $isDebit, $openWhenMissing): MarketBalanceChange {
            $account = $accounts->locked(customerId: $customerId, market: $code);

            // A debit arrives here with nothing to take, and so does a caller that may not open one: a credit that may has just opened the
            // account, and nothing ever deletes one.
            if ($account === null) {
                return MarketBalanceChange::refused(
                    refusal: $openWhenMissing ? MarketBalanceRefusal::Overdraft : MarketBalanceRefusal::NoAccount,
                    market: $code,
                    currencyCode: $currencyCode,
                );
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

    /**
     * The currency this market's money is in, as the one authority on it answers, or null when it cannot be told. A storefront whose
     * currency is missing or inactive may not sell at all, and while `gcc.storefronts.currency.enabled` is off every storefront reads the
     * platform's own currency settings, so neither state has a currency of its own to open an account in.
     * `App\Services\Storefront\OrderStorefrontSnapshot` stamps an order the same way, and is likewise silent when it cannot be told.
     */
    private function currencyOfSales(string $market): ?string
    {
        return $this->storefrontCurrency->isEnabled() ? $this->storefrontCurrency->currencyOfSales($market)?->code : null;
    }

    /** The column an account's company is recorded in is 64 characters wide, where `legal_entities.legal_name` is 191. */
    public const COMPANY_NAME_LIMIT = 64;

    /**
     * The company that sells in THIS market, recorded on an account as it is opened (GCC-13).
     *
     * It used to be ONE platform-wide value (`gcc.storefronts.legal.legal_entity`) for every market, above a comment saying it would be
     * until per-market entities existed. They exist: `legal_entities` holds one row per country, `country` is UNIQUE on it, and the
     * platform-admin market list, the health answer and the per-market reports all read it that way. The company on a money account is
     * who the customer's balance is a liability OF, so stamping one company onto six markets' accounts is a false statement about whose
     * money it is -- and a Kuwaiti balance labelled with the Saudi company is the kind of record a regulator reads.
     *
     * Three answers, and the middle one is the one worth stating:
     *
     *  - **the registry is in force and names this market's company**: that company's legal name;
     *  - **the registry is in force and this market has NO row**: NOTHING is recorded. Not the platform-wide value -- labelling this
     *    market's account with another company would look correct and be wrong, and an absent answer is better than a guessed one. The
     *    account still opens: refusing a customer's credit over a missing company record would move a bookkeeping gap onto their money,
     *    and whether a market may trade without a registered company is already asked, once, by `StorefrontPreflight`;
     *  - **the registry is off or not migrated**: the one platform-wide value, exactly as before. That is today's platform, so nothing
     *    about a Saudi account changes.
     *
     * NOTHING IS BACK-FILLED. Accounts already open keep the company they were stamped with, right or wrong: changing a stored legal
     * entity on live balance rows has legal meaning and is the owner's, not a code fix. {@see companiesThatDoNotMatchTheirMarket()} counts
     * them without touching one.
     */
    private function legalEntity(string $market): ?string
    {
        $entity = $this->legalEntities->forCountry($market);

        if ($entity !== null) {
            return $this->companyNameThatFits(market: $market, name: (string) $entity->legal_name);
        }

        if ($this->legalEntities->isEnabled() && $this->legalEntities->isMigrated()) {
            return null;
        }

        return $this->theOnePlatformWideCompany();
    }

    /**
     * The company's name, or nothing when it does not fit the column.
     *
     * A truncated company name is a DIFFERENT COMPANY as far as any reader is concerned, and MySQL truncates silently while the
     * application's connection is not strict -- so a 70-character company would be recorded as a 64-character one that does not exist.
     * Recording nothing and saying so is the honest answer. The width itself is a schema question: the account column is 64 and
     * `legal_entities.legal_name` is 191.
     */
    private function companyNameThatFits(string $market, string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        if (mb_strlen($name) > self::COMPANY_NAME_LIMIT) {
            Log::warning('wallet.market_account_company_too_long_to_record', [
                'market' => $market,
                'characters' => mb_strlen($name),
                'limit' => self::COMPANY_NAME_LIMIT,
            ]);

            return null;
        }

        return $name;
    }

    /** The one value every market's account used to carry, which is still the answer while the per-market registry is off. */
    private function theOnePlatformWideCompany(): ?string
    {
        $entity = config('gcc.storefronts.legal.legal_entity');

        return is_string($entity) && trim($entity) !== '' ? trim($entity) : null;
    }

    /**
     * How many accounts are stamped with a company that is not the one their market's row names, per market and per kind -- read only.
     *
     * The count the owner needs to decide what happens to rows opened before the registry was read. It writes nothing and it never
     * compares against the platform-wide value: an account whose market HAS no row is counted apart, because nothing says what it should
     * have carried.
     *
     * @return array<string, array<string, array{expected: ?string, accounts: int, mismatched: int, market_has_no_company: bool}>>
     */
    public function companiesThatDoNotMatchTheirMarket(): array
    {
        $found = [];

        foreach (MarketBalanceKind::cases() as $kind) {
            if (!$this->tableReady($kind->accountsTable())) {
                continue;
            }

            foreach (DB::table($kind->accountsTable())->select('market')->distinct()->pluck('market') as $market) {
                $code = strtoupper(trim((string) $market));
                $entity = $this->legalEntities->forCountry($code);
                $expected = $entity === null ? null : trim((string) $entity->legal_name);
                $rows = DB::table($kind->accountsTable())->where('market', $market);

                $found[$kind->value][$code] = [
                    'expected' => $expected,
                    'accounts' => (clone $rows)->count(),
                    'mismatched' => $expected === null ? 0 : (clone $rows)->where(function ($query) use ($expected): void {
                        $query->whereNull('legal_entity')->orWhere('legal_entity', '!=', $expected);
                    })->count(),
                    'market_has_no_company' => $expected === null,
                ];
            }
        }

        return $found;
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
