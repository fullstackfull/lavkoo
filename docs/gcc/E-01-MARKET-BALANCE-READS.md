# E-01: one market at a time reads its own balance

**Status:** on `gcc/development`. Ships with the list empty, which is the platform of today in every respect. No market may be put on
that list before the backfill has been rehearsed on a disposable copy of the real database and the owner has approved it (E-07, charter
section 4).

## Where this sits

GCC-15 described three stages, and the first two are merged:

1. **the accounts** — `customer_market_wallet_accounts` and `customer_market_loyalty_accounts`, one row per customer per market, behind
   `gcc.storefronts.market_accounts.enabled`;
2. **the mirror** — every movement written to the one `users` balance is also written to the account of the market that movement names, in
   the same transaction, and the two are compared, behind `.shadow_writes`;
3. **this**: *a read switch per market, so one market at a time answers from its account.*

Until now nothing read the accounts at all. A customer with a hundred dinars in Kuwait City and nothing in Riyadh was shown one number
that was neither, and a wallet payment on either storefront was judged against that same one number.

## What the switch is

`gcc.storefronts.market_accounts.answers_for`, a list of country codes, **empty as it ships**. A market on the list answers its own
account's balance; every market off it answers the one `users` column, as every market does today.

```php
'answers_for' => [],        // the platform of today
'answers_for' => ['KW'],    // Kuwait reads its account; Saudi Arabia and the rest read the column
```

Two switches above it still hold: with `market_accounts.enabled` off, or the master storefronts switch off, the list does nothing at all.

## What moved, and what deliberately did not

**Moved — six readings and one refusal**, all through `App\Services\Wallet\MarketBalanceReader`:

| Where | What it answers now |
|---|---|
| the website's wallet page | the balance of the market being served |
| `GET /api/v1/customer/wallet-transactions` (`total_wallet_balance`) | the same, for the market the app names in its header |
| the website's loyalty page, and the REST list (`total_loyalty_point`) | the points of the market being served |
| both points-to-wallet conversions | judged against the points of the market the conversion is made in |
| `CustomerWalletWriter`, inside the balance lock | a debit is judged against the balance of the market being served |
| all four statement queries (web and REST, wallet and loyalty) | the movements the market being served records (E-04) |

**Did not move — every write.** The one `users` column still takes every movement and the mirror still copies it into the market's
account. Once a second market holds money the two legitimately part company, which is what the mirror's divergence line is for. This
slice decides which of the two a reader is *shown*, and nothing else.

## Three rules that make it safe

- **An empty list changes nothing.** Proved against accounts deliberately holding different figures from the column: with nothing listed,
  every market still answers the column.
- **A reader serving no market reads the column.** A queue worker, a panel, a console command and every request while storefronts are off
  see every market at once or none; the one global balance is the only answer that is not a claim about a market they did not ask about.
- **The shape of an answer does not change.** A market that is not switched over gets the attribute back *untouched* — not cast, not
  rounded, the same value the caller would have read itself — because the apps read `wallet_balance` as a number with decimals and
  `loyalty_point` as a whole number, and those keys are under contract (E-04). On the account path the points are rounded to a whole
  number for the same reason.

## What is proved

`tests/Feature/Wallet/MarketAnswersFromItsAccountTest.php` (14), on SQLite 8.3 and MariaDB 11.4:

- with nothing listed, three markets all answer the column although two accounts hold other figures;
- a listed market answers its account while the others still answer the column — which is what "one market at a time" means;
- a listed market with no account yet answers nothing, which is why the list waits for the backfill;
- the list is read whatever case and spacing it is written in;
- a listed market answers the column while either switch above it is off;
- a reader serving no market answers the column;
- **a wallet payment of 100 in a switched-over market holding 5 is refused, and the column is untouched** — money in Riyadh is not money
  in Kuwait City;
- the same payment is taken in a market that has not been switched over, and in a switched-over market that has the money, and in both
  cases the write itself still goes to the column;
- a statement under a switched-over market shows that market's rows and no others; a market reading the one column is shown every row, the
  ones written before any market existed included; with nothing listed every statement shows every row; and a ledger whose attribution
  migration has not run narrows nothing, so no query can fail on a column that is not there.

**Teeth:** forcing the reader to always answer the column fails 4 of the 14, and dropping the narrowing fails 1. The wallet and loyalty suites (478) and the customer balance
contract (13) are green, so no surface that reads a balance moved while the list is empty.

## What is still open

- **The third criterion of E-01** — a gateway top-up crediting one legal entity's wallet — waits on the owner's wallet-key decision (per
  storefront, or per legal entity where two storefronts share one). The `sharing_key` column is already there for it.
- **The writes.** They stay as they are until a market has been read from its account long enough to trust, and the reconciler reports
  zero unexplained for it.
- **The seller app's POS customer lookup**, which still reads the one balance (E-04).
