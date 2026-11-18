# `wallet:reconcile` — do the balances equal their ledgers?

Requirement E-06 of the master tracker, and sections 3.1, 3.2 and 3.7 of the discovery migration plan. **Read-only.** It changes nothing, it moves no balance, and it is not a repair tool: it proves, on a restored copy, that the wallet balance and the loyalty points every customer carries equal the sum of the ledger rows behind them, and it puts every difference in a class. A migration of balances into per-market accounts (E-07) may not start while class F is not empty.

## Run it

On a restored **copy**, never on the live database:

```bash
php artisan wallet:reconcile --expect-database=<name of the restored database>
php artisan wallet:reconcile --ledger=wallet --list-unexplained=50 --json > wallet-reconcile.json
php artisan wallet:reconcile --explained=/secure/path/user-ids-with-evidence.txt
```

| Option | Meaning |
|---|---|
| `--ledger=both` | `wallet` (`users.wallet_balance` against `wallet_transactions`), `loyalty` (`users.loyalty_point` against `loyalty_point_transactions`) or `both` |
| `--tolerance=0.000001` | the largest difference, in the unit the balance is stored in, that is called float residue (class D). The default is the one value the plan accepts without evidence. A wider value is written into the report |
| `--explained=FILE` | one user id per line, a `#` starts a comment: the users whose difference an operator has explained with evidence (class E). At most 10,000 ids |
| `--list-unexplained=N` | list the ids (never names) of up to N users with an unexplained difference, largest first (at most 200) |
| `--skip-order-match` | do not match wallet debits to the orders they paid |
| `--match-tolerance=0.02`, `--match-window=600` | the largest gap between a debit and the total of the orders it paid, and the largest time, in seconds, between them |
| `--connection=`, `--expect-database=` | which connection to read, and the name the database must have or nothing is read |
| `--allow-production-read-only` | the run is refused when `APP_ENV=production` unless this is given. It still only reads |
| `--json` | print the report as JSON |

Exit code `0`: no check failed. Exit code `1`: a difference is unexplained (class F), a table or column the comparison needs is missing, an option is wrong, or the run was refused. Warnings (unmatched debits, repeated refunds, a second balance in the legacy tables) do not change the exit code; they belong in the go/no-go review.

## It only reads

The database session is locked read-only for the whole run (`SET SESSION TRANSACTION READ ONLY` on MySQL and MariaDB, `PRAGMA query_only` on SQLite), the probe accepts SELECT statements only, and every statement is recorded; the last check of the report (`nothing was written`) fails the run if even one was not a read. Sums are made by the database over decimal columns up to the highest ledger row that existed when the run began, so rows written later do not move the result. The report holds counts and sums: no name, no contact detail, no order, no ledger row. `--list-unexplained` prints user ids only; treat the output as internal.

## The classes

| Class | What it is | What the report does |
|---|---|---|
| A | ledger rows with no owner (`user_id` is NULL) | counts them and their net amount; they cannot be compared with any balance |
| B | ledger rows of a user that no longer exists (a hard delete) | counts rows, users and net amount; the money of a deleted user is a finance decision |
| C | a NULL balance | counts users with no movement (nothing to explain) and users with movements (compared as zero, so they appear in F if the ledger is not zero) |
| D | float residue: a difference no larger than the tolerance | counts users and the sum, with the tolerance written next to it |
| E | a difference an operator explained with evidence | counts the users with a difference that are named in the evidence file |
| F | any other difference | **fails the run**. Count, net amount and, if asked, the ids |

The comparison per customer is `balance (NULL as zero) − Σ(credit + admin_bonus − debit)` for the wallet and `Σ(credit − debit)` for the loyalty points, every user of `users` counted, a user without ledger rows as zero.

## Besides the balances (wallet only)

- **POS cancellations refunded more than once.** The only order-linked references in the ledger are `pos_order_cancel_<id>` (admin and vendor panels). Two refund rows with one reference mean the customer was paid twice. Counted, warning.
- **Wallet debits matched to the orders they paid.** A checkout debits the total of all the orders of its group at once, in a separate step after the orders exist, and the ledger holds no order id; so each `order_place` debit is matched to the orders of one customer and one group (`order_group_id`, else the order) whose total is within the amount tolerance and whose first order is within the time window, one debit to one group, the closest in time first. Debits without a group and wallet-paid groups without a debit are counted. This is evidence, not proof: an amount converted by a rate, or a payment edited afterwards, will not match, and that is what the count is for.
- **The legacy tables** `customer_wallets` and `customer_wallet_histories` hold a second balance outside the ledger. The report says whether they exist and holds rows, with their sums. They are not part of the comparison; they must be migrated or declared frozen.

## What a clean report does not say

- That the ledger is right. It proves the column equals its own history, not that every payment was written to it.
- Anything about currency. Balances have no currency until GCC-15; the report is in the unit they are stored in, and that unit (E04-F02) is still an owner question.
- Anything about the wallets of the admin and of sellers (plan 3.4), the paid totals of orders (3.3), the conversion rate of loyalty points used in the past (3.2, step 3), or referral rewards.
- Anything about traffic after the snapshot: run it on a copy, and run it again on the copy the migration will really use.

## Tests

`tests/Feature/Fulfillment/WalletReconcileCommandTest.php` (30 tests): every balance equal; a second run gives the same report; a difference above the tolerance fails; residue at the default and at a widened and a narrowed tolerance; a negative difference; the bonus and the debit; the evidence file (valid, malformed, absent, too long); rows with no owner and of a missing user; NULL balances; the loyalty ledger alone and both; repeated POS refunds; the legacy tables absent, empty and holding rows; debit matching (a multi-order checkout, an unmatched debit, an unmatched order, the window and the amount tolerance, a gap of exactly the tolerance, skipping, 405 customers over three pages); the listed ids (order, limit, clamp, explained ones left out); no name, contact detail or note in the output; a missing column or table is a failed check and not an exception; the options are validated before any read; the probe refuses anything that is not a read; refusal in production and for another database name; the connection is writable again afterwards. The tests sit in `tests/Feature/Fulfillment`, so CI runs them on MySQL 8 and MariaDB 11.4 as well as SQLite. On SQLite a decimal is a binary float and a bound tolerance is text, which SQLite ranks above every number; the tolerance is therefore cast to a number in the SQL, and a test fails without the cast.
