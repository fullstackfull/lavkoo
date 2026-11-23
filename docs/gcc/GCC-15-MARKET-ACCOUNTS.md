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
| `customer_market_wallet_accounts` | one row per (customer, market): `currency_code`, `legal_entity`, `balance`, `sharing_group` |
| `customer_market_loyalty_accounts` | one row per (customer, market): `currency_code`, `legal_entity`, `points`, `sharing_group` |
| `wallet_transactions`, `loyalty_point_transactions` | one nullable column: `storefront_country` (hidden on both models, so a statement keeps its keys) |
| `App\Services\Wallet\CustomerMarketBalances` | the one way to read a market balance and the one way to change it |
| `App\Services\Wallet\Reconciliation\MarketAccountReconciler` | per market, what its accounts hold against what its ledger moved |

It does **not** migrate a balance, does not change where any existing debit or credit goes, and does not touch checkout, POS, refunds or withdrawals. The four wallet writers and the two loyalty writers are untouched and remain the only authority on the one global balance.

## What the switch means when it is off, which is how it ships

- A read answers from `users.wallet_balance` or `users.loyalty_point`, whatever market is asked for. There is one balance, and every market shows it, exactly as every reader saw it before.
- Not one query goes near an account table — not even the question whether the table is there. The switch is read before the schema, so a database that has never run the migration behaves identically to one that has **in what the code does**. The schema is not identical: the migration runs whatever the switch says, and a ledger gains `storefront_country`. That column is hidden on `WalletTransaction` and `LoyaltyPointTransaction`, so a customer's wallet and loyalty statement is served with exactly the keys it had (`LedgerStatementShapeTest`) and the contract with `fullstackfull/lavkooapp` is unchanged.
- A credit or a debit is **refused**, with the reason `Dormant`. It is not silently written to the legacy column: a second writer of `users.wallet_balance` would be a second truth, and the source-scan test of E-03 exists to stop exactly that. A caller falls back to the writer it uses today.
- So nothing a Saudi customer does is reached by this code while the switch is off. `MarketAccountDormancyTest` pins it.

The switch also needs `GCC_STOREFRONTS_ENABLED`: without storefronts every request is the default market, and a per-market account would have only one market to be in.

## When it is on

- A market is the two-letter country of `gcc.storefronts.countries`, never a name and never the id of another table, so an account survives a storefront being renamed or switched off. **Configured, not active**: a credit to a market an operator has switched off still opens an account, because the money exists whether or not the storefront is serving visitors. Only a market the configuration does not name at all is refused (`UnknownMarket`); a market the customer has no account in reads zero.
- The account is opened with nothing in it on the first **credit** — a debit never opens one — and stamped with the currency that market **sells** in, which `App\Services\Storefront\StorefrontCurrency` is the one authority on. It is **not** the code `gcc.storefronts.countries` carries: while `GCC_STOREFRONT_CURRENCY` is off every storefront is still selling in the platform's own money, and a storefront whose currency is missing or inactive in the `currencies` table may not sell at all. In each of those states there is nothing to label the balance with, so the write is refused (`CurrencyUnknown`) rather than stamped with a currency the money is not in — the same way `OrderStorefrontSnapshot` leaves an order's currency empty when it cannot be told. An account whose stored currency is no longer the market's is refused afterwards (`CurrencyMismatch`): money does not change currency by itself.
- `legal_entity` records **the company the platform was selling as when the account was opened**. `gcc.storefronts.legal.legal_entity` is one platform-wide value, so every market's account carries the same string until per-market entities exist; it is not a record of which company sells in that market.
- The account row is locked from the moment its amount is read until the write commits, so two overlapping credits cannot both read the same amount. The lock is on the account, not on the customer: two markets of one customer never wait for each other. (The one global balance is locked on the `users` row instead — `App\Services\Customer\CustomerBalanceLock`, branch `gcc/p0-wallet-debit-result-handling`.)
- **A credit opens the account before that transaction, not inside it**, and both of InnoDB's ways of refusing say why. A locking read that finds no row holds the *gap* of the unique key rather than a record, so two writers opening one account each insert into the other's gap and one dies with SQLSTATE 40001 instead of waiting. Doing the insert first instead only trades that for the other half: an insert that is ignored takes a *shared* lock on the row it collided with, and two writers that both hold it and then both lock the row for update deadlock on the upgrade — which is every credit after the first. Opened outside, the insert holds nothing by the time the transaction starts. `MarketAccountConcurrencyTest` races two and four processes for the same new account on a real engine; with the open moved back inside the transaction, six of its seven cases fail.
- A debit may empty an account but not take it **meaningfully** below zero (`Overdraft`). The floor is applied inside the lock and sits one millionth under zero, not at zero: the tolerance absorbs float representation error, so a debit of 1.0000009 against an account of 1 is allowed and leaves about minus nine ten-millionths. That is the same floor and the same tolerance as `App\Services\Wallet\WalletOverdraft` holds the one global balance to.
- A write answers with `MarketBalanceChange`, not a boolean. `if ($writer->debit(...))` was how an ignored `false` let an order be committed as paid while the debit that paid for it had been refused (E-10). An object is always truthy, so a caller has to read `applied`; a caller that pays for an order turns a refusal into the refusal its own transaction rolls back on.
- Turning the switch on still moves no money on its own, because nothing calls the service yet.

## Which stream owns which ledger column

The full attribution of a ledger row — what it was for, who made it, its storefront, its currency and the selling company — belongs to **E-05**, whose migration is `2026_12_05_100000_add_attribution_to_wallet_and_loyalty_ledgers` on branch `gcc/p1-wallet-ledger-attribution` (PR 109). It owns `source_type`, `source_id`, `actor_type`, `actor_id`, `storefront_country`, `wallet_transactions.currency_code` and `legal_entity_id` on both ledgers.

This stream adds **only `storefront_country`**, which its reconciliation cannot do without, and it is timestamped `2026_12_05_120000` so that where both are pending E-05 runs first and this one finds the column already there. It adds no `currency_code` and no selling company to either ledger, so one concept never has two columns (E-05's `legal_entity_id` is the one). Its `down()` drops a ledger column **only when E-05 is not recorded in the `migrations` table**, so rolling this migration back on a database that has E-05 never takes away a column that stream's writers are filling, and never forgets a recorded currency. `MarketAccountExpandMigrationTest` pins both halves.

## The sharing key, which nothing reads (E-02, fourth criterion)

`sharing_group` is on both account tables, added by `2026_12_05_140000_add_sharing_key_to_market_balance_accounts`: one nullable 64-character string, no default, no index, appended, and **no code path reads or writes it**. Accounts that would one day share a balance would carry the same value in it; until a reader is written every account carries null and every market's balance is its own.

Turning sharing on is deliberately **not a setting**. A column alone shares nothing — `CustomerMarketBalances` reads and writes one account found by (customer, market) and would have to be changed to read a group — so it takes a code change a person writes and another reviews, which is what the criterion asks for. It is a free string and not a foreign key because the shape of a group is exactly what is undecided: the legal entity, a currency union, or a list written by hand. An empty column is cheap to replace once the shape is known.

Three things hold it there.

`MarketAccountSharingKeyTest` reads **what every table of its database is and what is in it** on both sides of the migration — the table's engine, charset and comment, every column with its type, nullability, default, collation and comment in the order the table holds them, every index, and every row — so adding the column is shown to change nothing else. The table LIST is read afresh on each side, not once before, so a table the migration created would be a key the other side has not got. It then asserts the key does nothing on the read path **and on the spend path**: two markets carrying the same key read their own balances, and a market grouped with another is refused that other's money both when it has no account of its own and, the case that matters, when it holds an open grouped account of four and tries to spend fifty — a refusal only a floor that reads one account can give.

`NothingReadsTheSharingKeyTest` reads every PHP, JavaScript, Vue, TypeScript, JSON and SQL file of `app`, `bootstrap`, `config`, `database`, `installation`, `Modules`, `public`, `resources`, `routes`, `scripts` and `tests`, every file of those kinds at the repository root, and `artisan`, which carries no extension at all — 6,331 files in a plain checkout, 1,561 of them Blade. It reads them through the platform's own `PlatformWriteScanner`, which resolves a constant, reads a name written in pieces whole whether joined with `.` or with `implode`, reads the escape sequences of a double-quoted string and a heredoc, and ignores the case the database ignores. Its finding says **whether the file merely names the key or hands it to a query as the column to read or write**, which is what lets the two models be listed for naming it without being licensed to read it. A name the scan cannot read at all is reported as such where the file reaches an account table, so the guard's list has to name it.

And the key is **hidden on both account models**, as `storefront_country` is on the two ledger models, so a key a later slice begins to fill can never appear in an API answer.

**What the scan cannot speak for**, said plainly because a guard is worth what it reads: a column name built by means the scan cannot evaluate, in a file that names no account table and no account model, is outside it — a view handed `$account` and an unreadable `$column` by a controller that itself names neither at a column position. The behaviour tests, the models' `$hidden` and review are what cover that, not the scan. Nor can the scan speak for `installation/backup/database.sql`, the dump a real installation imports before it migrates, which a checkout does not carry: the test says so and skips rather than passing, and scans the dump wherever a checkout has one.

The migration **refuses to run before `2026_12_05_120000` has created the tables** rather than skipping: Laravel records a migration that returns quietly as run and never offers it again, so a skip would leave the column permanently absent on a database where this one ran first. Its rollback drops the column while nothing carries one and refuses once any account of **either** kind carries a group.

## Rolling the migration back

It is expand-only: two empty tables and one nullable column per ledger, and `down()` drops exactly those that are its own. While it is still dormant it rolls back to the database it found, byte for byte (`MarketAccountExpandMigrationTest`). Once a customer holds a market account, or a ledger row carries a column this rollback would drop, the rollback is **refused**: dropping it would forget money that cannot be worked out again. That is the same discipline as `orders.storefront_country`.

## What the later slices still have to do (E-07)

1. A Saudi backfill command that gives each customer an account equal to `users.wallet_balance` and `users.loyalty_point`, and refuses to run unless `wallet:reconcile` reports zero unexplained (E-06, branch `gcc/p1-wallet-reconcile-command`).
2. A shadow writer: every write updates the legacy column and the account in one transaction and compares them.
3. A read switch per market, so one market at a time answers from its account.
4. A rollback that restores the exact old balances, rehearsed on a disposable copy.
5. Per-market settings: the wallet switch, the top-up limits and bonus tiers, and the loyalty earn rate, exchange rate and minimum (E-01, E-02).

None of that may touch real data without separate owner approval (charter section 4).

## Reconciliation

`MarketAccountReconciler` returns checks of the same shape as `App\Services\Wallet\Reconciliation\BalanceReconciler` (branch `gcc/p1-wallet-reconcile-command`, documented in `WALLET-RECONCILE.md`), from the same read-only probe, so `wallet:reconcile` appends them and one report covers both the global balance and the markets. **This stream depends on that branch for the command itself**; nothing of that class is duplicated here. While the switch is off the reconciler returns nothing, so the report says exactly what it says today.

Three things the per-market checks have to get right, because the backfill of E-07 may not run until this report shows zero unexplained:

- **A wallet net includes the bonus.** `CustomerManager::create_wallet_transaction` writes an add-fund as `credit = 100, admin_bonus = 10` and adds *both* to `users.wallet_balance`, so a net of `credit - debit` is short by every bonus ever given and would fail a market that is perfectly consistent. `MarketBalanceKind::ledgerCreditColumns()` says `credit + admin_bonus - debit` for a wallet and `credit - debit` for points, which is what `LedgerSpec::wallet()` on the reconcile branch says, so the two halves of one report cannot disagree about the same rows.
- **A market whose accounts are not all in one currency never passes.** The per-market sum is currency-blind by construction, so a hundred dinars and a hundred dollars would otherwise read as "200 held, 200 moved", PASS. The check counts the accounts of a market that are kept in another currency and FAILs while there is one. (Such an account is reachable: the service refuses to *write* to it but leaves it in place.)
- **A printed sum is the amount, not a float's representation.** Sums are printed to ten decimals — far beyond any currency's scale, far short of where a float's own representation begins to show. Printed to twenty, 12.30 reads `12.30000000000000071054`. The PASS or FAIL itself is decided on the floats against the tolerance, which is ten thousand times the last decimal printed.

## Where the tests run

`tests/Feature/Wallet` has its own group in the CI engine matrix (MySQL 8.0 and MariaDB 11.4.2) and runs last in the strict-mode job. It needs a real engine: the unique key on (customer, market), the row lock, the ascii_bin market, `DECIMAL(40,20)` and the `ALTER TABLE` of the expand migration all mean nothing on SQLite. Its own group rather than an append, because `BuildsMarketAccountSchema` starts from an empty database and would take another suite's tables away. The races are in `tests/Feature/Concurrency`, which the sales-channel job already runs on both engines.
