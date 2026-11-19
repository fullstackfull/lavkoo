# GCC-15: a wallet and a loyalty account per market

Status: the foundation only, behind one switch that is off by default (`GCC_MARKET_ACCOUNTS`). Nothing in the application calls it yet, no balance has been migrated, and nothing here has been run on the pilot server or on production.

## The rule

One login holds one independent wallet, and one independent loyalty account, per market (`DECISIONS.md`: wallet balances and ledgers separate by store, legal entity and currency). A credit, a debit, a top-up, a refund or a reward touches the account of the market where it happens and no other, and no code path moves or converts money or points between two markets. The Saudi market stays the default one and keeps its balance.

Today the platform has one global balance per customer (`users.wallet_balance`, `users.loyalty_point`) and one ledger per balance with no market, no currency and no selling company. That is the largest functional gap of the programme (master tracker, gap 2: requirements E-01, E-02, E-03, E-07).

## What this slice does, and what it deliberately does not

It adds the structure and the one seam, dormant:

| | |
|---|---|
| `config/gcc.php` → `storefronts.market_accounts.enabled` | the switch, from `GCC_MARKET_ACCOUNTS`, off by default |
| `customer_market_wallet_accounts` | one row per (customer, market): `currency_code`, `legal_entity`, `balance` |
| `customer_market_loyalty_accounts` | one row per (customer, market): `currency_code`, `legal_entity`, `points` |
| `wallet_transactions`, `loyalty_point_transactions` | three nullable columns: `storefront_country`, `currency_code`, `legal_entity` |
| `App\Services\Wallet\CustomerMarketBalances` | the one way to read a market balance and the one way to change it |
| `App\Services\Wallet\Reconciliation\MarketAccountReconciler` | per market, what its accounts hold against what its ledger moved |

It does **not** migrate a balance, does not change where any existing debit or credit goes, and does not touch checkout, POS, refunds or withdrawals. The four wallet writers and the two loyalty writers are untouched and remain the only authority on the one global balance.

## What the switch means when it is off, which is how it ships

- A read answers from `users.wallet_balance` or `users.loyalty_point`, whatever market is asked for. There is one balance, and every market shows it, exactly as every reader saw it before.
- Not one query goes near an account table — not even the question whether the table is there. The switch is read before the schema, so a database that has never run the migration behaves identically to one that has.
- A credit or a debit is **refused**, with the reason `Dormant`. It is not silently written to the legacy column: a second writer of `users.wallet_balance` would be a second truth, and the source-scan test of E-03 exists to stop exactly that. A caller falls back to the writer it uses today.
- So nothing a Saudi customer does is reached by this code while the switch is off. `MarketAccountDormancyTest` pins it.

The switch also needs `GCC_STOREFRONTS_ENABLED`: without storefronts every request is the default market, and a per-market account would have only one market to be in.

## When it is on

- A market is the two-letter country of `gcc.storefronts.countries`, never a name and never the id of another table, so an account survives a storefront being renamed or switched off. A market that is not configured is refused (`UnknownMarket`); a market the customer has no account in reads zero.
- The account is opened with nothing in it on the first credit or debit, stamped with the currency the market is configured to trade in and with `gcc.storefronts.legal.legal_entity`. An account whose stored currency is no longer the market's is refused (`CurrencyMismatch`): money does not change currency by itself.
- The account row is locked from the moment its amount is read until the write commits, so two overlapping credits cannot both read the same amount. The lock is on the account, not on the customer: two markets of one customer never wait for each other. (The one global balance is locked on the `users` row instead — `App\Services\Customer\CustomerBalanceLock`, branch `gcc/p0-wallet-debit-result-handling`.)
- A debit may empty an account but not take it below zero (`Overdraft`), and the floor is applied inside the lock.
- A write answers with `MarketBalanceChange`, not a boolean. `if ($writer->debit(...))` was how an ignored `false` let an order be committed as paid while the debit that paid for it had been refused (E-10). An object is always truthy, so a caller has to read `applied`; a caller that pays for an order turns a refusal into the refusal its own transaction rolls back on.
- Turning the switch on still moves no money on its own, because nothing calls the service yet.

## Rolling the migration back

It is expand-only: two empty tables and six nullable columns, and `down()` drops exactly those. While it is still dormant it rolls back to the database it found, byte for byte (`MarketAccountExpandMigrationTest`). Once a customer holds a market account, or a ledger row names its market, the rollback is **refused**: dropping the tables would forget money that cannot be worked out again. That is the same discipline as `orders.storefront_country`.

## What the later slices still have to do (E-07)

1. A Saudi backfill command that gives each customer an account equal to `users.wallet_balance` and `users.loyalty_point`, and refuses to run unless `wallet:reconcile` reports zero unexplained (E-06, branch `gcc/p1-wallet-reconcile-command`).
2. A shadow writer: every write updates the legacy column and the account in one transaction and compares them.
3. A read switch per market, so one market at a time answers from its account.
4. A rollback that restores the exact old balances, rehearsed on a disposable copy.
5. Per-market settings: the wallet switch, the top-up limits and bonus tiers, and the loyalty earn rate, exchange rate and minimum (E-01, E-02).
6. The disabled sharing key of E-02, which no code path reads.

None of that may touch real data without separate owner approval (charter section 4).

## Reconciliation

`MarketAccountReconciler` returns checks of the same shape as `App\Services\Wallet\Reconciliation\BalanceReconciler` (branch `gcc/p1-wallet-reconcile-command`, documented in `WALLET-RECONCILE.md`), from the same read-only probe, so `wallet:reconcile` appends them and one report covers both the global balance and the markets. **This stream depends on that branch for the command itself**; nothing of that class is duplicated here. While the switch is off the reconciler returns nothing, so the report says exactly what it says today.
