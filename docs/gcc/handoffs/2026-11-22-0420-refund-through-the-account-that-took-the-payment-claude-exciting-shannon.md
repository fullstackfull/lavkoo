# Handoff — a refund names the account and the company that took the payment (D1-10)

- **When:** 2026-11-22, ~04:20 UTC
- **Account / branch:** `claude/exciting-shannon-4mh2ok` (docs and tracker)
- **Tracker commit:** `7548370d`
- **Pooled after this checkpoint:** **822 MET / 399 CODED / 532 MISSING — 70% implemented**, 0 evidence failures
- **Previous handoff:** `2026-11-22-0110-security-published-webhook-fixed-order-currency-on-every-surface-claude-exciting-shannon.md`

## 1. What was done

One development lane, as the owner asked ("استخدم حارة تطوير واحدة أو توازيًا محدودًا"). D1-10: a refund gives back what the buyer paid, in the currency, through the merchant account and the company that took the payment.

**[PR 154](https://github.com/fullstackfull/lavkoo/pull/154)** — draft, base `main`, head **`06fcfaf5`**, branch `gcc/p1-refund-through-original-account`. It is the merge of PR 101's `gcc/p1-refund-from-original-payment` (`d100dd01`) with `gcc/core-payment-accounts` (`450f5940`). **Neither parent is merged into main**, so the PR's diff against main carries #101, #103 and #152 as well; the work of this slice alone is `git diff edfefa0b..06fcfaf5`. The PR body carries a "What to read" list so a reviewer is not sent through the parents' diff.

Both independent reviewers read the pre-fix head `1b99cc29` in their own worktrees on both engines and **both ruled it defective**. Thirteen findings: eleven repaired, one refuted with the measurement that refutes it, two recorded unchanged. **Five of the six resulting behaviour changes can move a money figure.**

CI on `06fcfaf5`: 23 check runs, every one read to completion, **22 green**; the single red is `Deployment overlay (artifact, deploys nothing)`, red by design on every run and every base.

## 2. Criteria rulings (commit `7548370d`)

D1-10 went from **0 MET / 3 CODED / 4 MISSING** to **0 MET / 6 CODED / 1 MISSING**. Nothing was raised to MET.

| # | Criterion | Was | Now |
|---|---|---|---|
| 1 | a refund request or transaction stores the merchant account of the original payment | MISSING | **CODED** |
| 3 | the refund flow uses the computation, so a refund is not re-converted at the refund-time rate | MISSING | **CODED** |
| 4 | a refund goes back through the merchant account and company that took the payment | MISSING | **MISSING** |
| 6 | a refund after an exchange-rate or settings change returns exactly the amount paid | MISSING | **CODED** |

Why none reached MET:

- **(1)** Both surfaces are driven by named passing tests on both engines and in CI. But `orders.transaction_ref` is **varchar(30)** against the payment's **varchar(100)**, and the shared fixture `tests/Support/BuildsApiAddressSchema.php` declared it **varchar(255) — wider than production**, so no test in the repository could see it. Narrowed to 30 and driven with a 32-character OPP/HyperPay-shaped reference, MariaDB kept 30 of 32 characters in the order and all 32 in the payment, the join found nothing, and all four columns came back NULL while the buyer was paid the base figure. The mechanism **fails safe** — silent, not wrong. **A criterion whose coverage depended on a fixture being wider than production is not MET.**
- **(3)** Off by default behind **two** switches; **2 of the 6** money paths that return money are wired; four reachable shapes keep the old figure; and the refund-time rate still reaches what the buyer *receives* through `currencyConverter` in the wallet sink.
- **(4)** Two of the three things it names hold. **The money does not leave through that account and no change on this branch can make it** — verified independently twice: Paymob, the one gateway wired end to end for per-company accounts, has no outbound refund call anywhere in the codebase, and the one real outbound refund, `App\Services\MyFatoorah\RefundService::request`, reads its credentials from the single platform settings row through `GatewayConfigRepository::forMode`, with `config/gcc.php` deliberately excluding MyFatoorah from `payment_accounts.gateways`. One reviewer ruled **1 of 3** rather than 2 of 3; **the lower reading is what is recorded.**
- **(6)** Exact for a **single** payout after a rate change, a decimals-and-rate change and a settings change down to one currency. Off by default; a payment in a currency the wallet cannot reach is declined by design; and a refund paid **in parts** is exact per part and not in composite.

## 3. Two claims of our own that were withdrawn

**The three-part dinar claim.** The branch advertised that a 10.000 KWD payment refunded in thirds gives back `3.333 + 3.334 + 3.333 = 10.000 KWD` exactly. **That was read off a record column.** Both reviewers read the **wallet** instead: the buyer holds `1.3332 + 1.8522222222222 + 0.606 = 3.7914222222222` base units, worth **20.853 KWD** at the last rate against the **10.000 KWD** paid. The claim was wrong and the code was not — each part *is* exactly its share at the rate in force when it is paid, and the base is further out still (3.0 base units, 16.5 KWD). The row is struck through where it stood and the table rewritten with two columns, **"Recorded as owed"** and **"Handed to the buyer"**.

**The dormancy claim.** "The only statements this change adds are six read-only schema questions" held while the accounts switch is off, and the fix round found it adds **one** statement to each route while the switch is on. Named, counted on its own, and corrected in place.

## 4. A reviewer refuted, with the measurement

One reviewer measured a **3.75x overpayment** after a `currency_model` change. **Refuted:** under `single_currency`, `currencyConverter` and `usdToDefaultCurrency` are both the **identity**, so a stored unit *is* a unit of the default currency — which is exactly how `wallet_balance` is compared against cart `order_amounts` in `placeOrderByWallet`. The review compared raw base units across a settings change that redefines the unit. With the switch off the same buyer is **275.00 SAR short**. The **coverage gap the review found was real** — no test on the branch ran the mechanism under `single_currency` at all — and two new tests close it.

## 5. What the real engine caught that SQLite could not

A first draft selected `additional_data` from `payment_requests` unconditionally. **SQLite passed fifteen tests for the wrong reason:** a double-quoted identifier it cannot resolve is reinterpreted as a **string literal**, so the stamp decoded to nothing and no market was ever compared. MariaDB refused the column outright. Had the round been run on SQLite alone, the market guard would have shipped silently dead.

**This is now the third time the real engine has caught something SQLite could not.** Any lane running refund, payment or stock work on SQLite alone should be treated as unmeasured.

## 6. Mutations

Nine applied by a harness that reverts automatically. **Eight caught on the first run.** The ninth **survived because the lane's own test was the defect** — a sibling refund's share is linear, so counting one that moved no money moves the window and never its width. Fixed in a commit that says so; caught afterwards.

## 7. For the next agent — the trap that nearly cost two published rulings

Reported in the previous handoff and repeated because it is cheap to fall into: **`/home/user/lavkoo` can sit behind `origin/claude/exciting-shannon-4mh2ok`.** Regenerating the tracker from a stale checkout printed `811 MET / 360 CODED` and would have silently reverted two published rulings. **Before you regenerate: `git rev-parse HEAD` and `git ls-remote origin <branch>` must agree. After you regenerate: diff the generated files against the committed ones and confirm only the criteria you meant to change actually moved.** This checkpoint did both and the diff was exactly four criteria, one PR link and one next-task line.

Second trap, new: **a leftover unregistered worktree directory.** One reviewer found `scratchpad/wt/refundrev` already present and unregistered, left by an earlier lane. They did not reuse it and did not write into it. **`ls` your intended worktree path before `git worktree add`.**

## 8. Open owner decisions (D1-10)

Six, written into PR 154's body as the owner's and unmade. None has been decided on anyone's behalf.

1. Does a refund return the **original amount paid** or the amount at the **current rate**?
2. Do credit notes exist, and how are they treated?
3. May a refund **only ever** return to the original merchant account?
4. For money that left a **wallet** rather than a card (a POS wallet sale cancelled), is "what the buyer paid" the stored units or their purchasing power on the day? *Two money paths wait on this.*
5. Is an order edit's **return amount** a refund at all for D1-10's purposes? *Two money paths wait on this.*
6. **New, surfaced by this round.** A refund paid in **parts** is exact at each payout and the parts do not add up to the payment afterwards, because a wallet balance is held in the platform's base unit and every rate move revalues it. Is a refund owed the money **at the moment it is paid**, or the **purchasing power** of the original payment? Until balances are per market (GCC-15) there is no third answer.

## 9. Recorded obstacles, not fixed

- **`orders.transaction_ref` is narrower than the reference a gateway hands back.** Widening it is a change to a core column of the installed SQL dump that every order path writes; matching on a truncated prefix would risk binding a refund to the **wrong** payment, which is the defect this round closed. The mechanism fails safe and says nothing.
- **What a payment covered is re-derived at refund time** rather than recorded when the payment is taken. Recording it at the source would also let an **edited** order and an order **paid twice** be refunded exactly, which they now are not — they fall back to the old figure. A new column and a change to the payment flow; not this round's.
- **A refund of a payment that carries no stamp** — every payment taken before per-company accounts were switched on — still changes the money while naming no account. Refusing those would deny the fix to exactly the historical orders it was built for. The owner's.
- **MyFatoorah cannot be routed per account**: its outbound refund reads the one platform settings row.

## 10. Still outstanding across the board

- **[PR 1](https://github.com/fullstackfull/lavkoo/pull/1) remains unmerged.** It is the **one-line, test-only** fix for the calendar-dependent `TorodShipmentDiscoveryTest` fixture. Every branch cut from main inherits that red, so **every PR's CI reads red on a failure none of them caused.** It is the cheapest decision on the list.
- **D2-09's three criteria are held back from MET by a definition, not by a missing test.** PR 152's CI on `450f5940` is 22/23 green and the SQLite jobs run the whole suite unfiltered, so all seventeen tests passed. What blocks MET is the phrase "a draft on main, dormant by default" — and **the tracker contradicts itself**: D1-04 index 2 and D1-05 index 4 were ruled MET on exactly that footing. Not resolved in our own favour; referred to the owner.
- Three export documents whose rows are transaction rows still do not read the order (`order-transaction-report-export`, `refund-request-list`, `refund-transaction-report`).
- `order_item_commissions.currency` writes the system default over an amount in the storefront's currency.

## 11. In flight now

**C-11, "Idempotency, concurrency and oversell protection of stock writers"** — two slices in one lane:

- **The tills charge for stock they floor at zero.** Confirmed on main (`git blame`: upstream vendor code, not a GCC lane's). Both `app/Http/Controllers/Admin/POS/POSOrderController.php` and `app/Http/Controllers/Vendor/POS/POSOrderController.php`: when the conditional decrement's `current_stock >= qty` guard does not hold, the code writes `current_stock = 0` **and carries on** — the line is written and the customer is charged. `InventoryService::record` is then called with `qtyChange: -N` and `balanceAfter: 0`, so the **movement ledger is self-inconsistent and cannot be replayed** — a detectable signature in `stock_movements` that is wrong under any stock policy. **There is no `DB::transaction` anywhere in either place-order method.** Bears on criterion 1, which `GenerateOrderStockTest` satisfies for the **web checkout** only.
- **Criterion 5 (MISSING): three returns restock more than left the shelf.** The defects are already pinned as `..._currently_...` tests by draft PR 24 (`gcc/p0-stock-holds-characterization`, `eebb5884`), stacked on PR 17 (`gcc/p0-rma-receive-idempotency`, `5cbc384`). **The tracker records that the owner has accepted these fixes.**
