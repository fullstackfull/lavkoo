# Refunds, loyalty formulas and idempotency: findings and proposals

Status: **proposals only.** Nothing in this document is implemented. The change that adds it contains tests and this file, and no
application code. No refund rule, earn rule or points-to-wallet formula changes. No historical row in `wallet_transactions` or
`loyalty_point_transactions` and no balance is touched. No idempotency key, unique index or new reference format is added to a writer.

Each section below names one finding, the code it comes from, the test that pins today's behaviour (if any), a proposal, and the
**owner decision** the proposal waits for.

The finding IDs are the ones the audit plan gave this workstream. The audit's own text is not on this branch, so each description
below was written again from the code on `gcc/p0-loyalty-balance-lock`. If the audit's wording for an ID differs, the audit wins, and
this file should be corrected to match it.

## How to read the tests

- `tests/Feature/Refund/RefundSequentialGuardCharacterizationTest.php` (SQLite). Each test name starts with `test_current_behaviour`.
  These tests pin what the code does **today**. A passing test **does not approve** the behaviour it pins.
- `tests/Feature/Loyalty/PointsToWalletFormulaCharacterizationTest.php` (SQLite). Records the wallet credit that the REST and web
  conversions give today for the same points. The expected values describe a divergence. They are not correct values.
- `tests/Feature/Concurrency/RefundConcurrencyTest.php` (MySQL or MariaDB). Asserts the outcome a fix must give. **Every test is
  skipped**, and each skip reason names its finding and says that an owner decision is pending. Remove the skip when the matching fix
  is approved and merged.
- Shared fixtures: `tests/Support/Refund/RefundTables.php`, `tests/Support/Refund/RefundFlows.php`,
  `tests/Support/Concurrency/RefundOperations.php`.

A mutation check was run by hand: each sequential guard (refund status, return amount, `reward_delivered`, POS cancel reference) was
removed in turn, and the matching characterization test failed. The application code was then restored.

---

## RF-01

**Two "refunded" updates that overlap both pay.** `Admin\Order\RefundController::updateRefundStatus` reads the refund, checks
`status == 'refunded'`, then credits the wallet (`order_refund`), debits the line's points (`refund_order`), reduces the seller or
in-house earnings and writes a refund transaction. Nothing holds the check while these writes run. A second request that is already
past the check pays again.

- Pinned (sequential half): `test_current_behaviour_a_second_refunded_status_is_rejected_so_the_wallet_is_credited_and_the_points_debited_once`.
  A repeat that arrives after the first commit is rejected.
- Skipped race: `test_simultaneous_refunded_statuses_credit_the_wallet_and_debit_the_points_once`.
- Proposal: claim the refund before paying it, with a conditional update such as
  `UPDATE refund_requests SET status = 'refunded' WHERE id = ? AND status <> 'refunded'`. Only the request that changes one row pays.
  The payouts then run in the same transaction as the claim.
- **Owner decision:** approve the conditional-update claim. Decide whether the claim covers the vendor path too (see IDEM-09).

## RF-02

**Refund ledger rows do not name the refund.** The wallet credit uses the literal `order_refund` as its reference
(`RefundStatusService::getRefundStatusProcessData`). The points debit uses the order id (`RefundController.php:162`). The
return-amount credit uses the literal `add_wallet_amount`. A ledger row cannot be traced back to the refund request that caused it, and
no writer can recognise a refund that it has already paid.

- Pinned: `test_current_behaviour_the_refund_credit_is_referenced_by_a_literal_and_the_points_debit_by_the_order_id`.
- Proposal: use the refund id as the reference for new rows, for example `refund_{refund_request_id}`. Existing rows stay as they are.
- **Owner decision:** approve the new reference format, and confirm that reports or apps that filter on `reference = 'order_refund'`
  can change. This is a new reference format on a wallet writer, so it is not made here.

## RF-03

**Two return-amount submissions that overlap both credit.** `Admin\Order\OrderController::orderReturnAmountToCustomer` (the vendor
copy has the same check and order of writes) compares the posted amount to `edit_return_amount`, credits the wallet, then sets `edit_return_amount` to 0. The read
is not locked. A request that read the amount before the first one committed credits it a second time.

- Pinned (sequential half): `test_current_behaviour_a_resubmitted_return_amount_is_rejected_so_the_wallet_is_credited_once`.
- Skipped race: `test_simultaneous_return_amount_submissions_credit_the_wallet_once`.
- Proposal: claim the amount with
  `UPDATE orders SET edit_return_amount = 0 WHERE id = ? AND edit_return_amount = ?`, and credit only when one row changed.
- **Owner decision:** approve the claim on the admin and vendor copies.

## RF-04

**Two POS cancels that overlap both refund.** The POS wallet refund on cancel looks for an existing `order_refund` row with the
reference `pos_order_cancel_{id}` and writes one if none is found (`Admin\Order\OrderController.php:700`, vendor `:659`). The lookup
and the insert are separate, so two cancels that both miss the lookup both credit.

- Pinned (sequential half): `test_current_behaviour_a_second_pos_cancel_finds_the_pos_order_cancel_reference_and_credits_once`.
- Skipped race: `test_simultaneous_pos_cancels_credit_the_wallet_once`.
- Proposal: do the lookup inside the customer lock that the wallet writer already takes, or claim the order status with a conditional
  update first. See IDEM-07 for the index option.
- **Owner decision:** choose the lock-scoped lookup or the unique key in IDEM-07.

## RF-07

**The return-amount check depends on the database driver.** The check is `$validated['amount'] !== $order['edit_return_amount']`.
`edit_return_amount` has no cast on `Order`. MySQL returns the decimal as a string such as `"40.00"`, so the check passes only when the
form posts exactly that string. A driver that returns a number (SQLite here) refuses even the exact amount.

- Pinned: `test_current_behaviour_the_exact_return_amount_is_refused_when_the_driver_returns_a_number`.
- Proposal: compare as numbers, rounded to the store's decimals.
- **Owner decision:** approve a numeric comparison. It changes which requests are accepted, so it is a refund rule change.

## RF-08

**The return-amount flow can leave a transaction open.** It calls `DB::beginTransaction()` and then returns early when
`wallet_status != 1` without rolling back (`Admin\Order\OrderController.php:261-266`). The vendor copy has no such early return.

- Not pinned: an open transaction at the end of a request is rolled back by the connection close, so the observable effect is a held
  lock until then, which a sequential test cannot show.
- Proposal: check `wallet_status` before opening the transaction.
- **Owner decision:** approve the reorder (no rule change, only the order of the check).

## RF-09

**The return-amount flow reads one admin wallet and writes another.** It reads `admin_wallets` where `admin_id = 1` and writes where
`admin_id = order.seller_id` (`Admin\Order\OrderController.php:270-274`). For a vendor order the write targets the vendor's id.

- Not pinned in this change. The vendor copy reads and writes by `seller_is` and `seller_id` consistently.
- Proposal: read and write the same row, chosen by `seller_is`, as the vendor copy does.
- **Owner decision:** confirm which wallet a return amount should reduce for a vendor order.

## RF-10

**Refund credits round twice.** The refund credit is `usdToDefaultCurrency(refund.amount)`, which floors to the configured decimals,
and the writer converts it back to dollars with `currencyConverter`. Under `multi_currency` the stored credit can be a fraction of a
cent below the refund amount.

- Not pinned in this change (the sequential test runs under `single_currency`, where both conversions are the identity).
- Proposal: credit the refund amount in dollars directly, through the canonical helper of LF-1.
- **Owner decision:** approve the rounding rule for refund credits.

## RF-11

**One refund is several unrelated writes.** `updateRefundStatus` writes the seller or in-house wallet, the refund transaction, the
vendor ledger reversal, the customer wallet, the points, the order line and the refund request without one enclosing transaction. A
failure part way leaves money paid while the refund still reads as not refunded, and a retry then pays again.

- Not pinned in this change.
- Proposal: run the claim of RF-01 and all payouts in one transaction.
- **Owner decision:** approve the transaction boundary together with RF-01.

## LO-04

**A refund can take loyalty points below zero.** `refund_order` debits are not checked against the balance in either writer. The
balance lock work kept this on purpose.

- Pinned: `LoyaltyPointCharacterizationTest::test_current_behaviour_a_refund_can_take_the_points_below_zero`.
- Proposal: a `refund_order` floor, decided by the owner: refuse, clamp at zero, or allow negative and report it.
- **Owner decision:** choose refuse, clamp or allow. Existing negative balances are not changed by any option.

## LO-05

**The "enough points" refund check reads outside the lock.** Admin and vendor refund flows refuse a refund when
`user.loyalty_point < reward_amount`, read before the debit and without the customer lock. A conversion that runs between the check
and the debit spends the same points, and the refund then takes the balance negative (LO-04).

- Not pinned separately: it is the race window of LO-04 and RF-01.
- Proposal: move the check into the locked `refund_order` debit, as the `point_to_wallet` check already is.
- **Owner decision:** depends on LO-04. If the floor is "refuse", the check moves into the writer.

## LO-07

**Only the first reward row of an order is marked delivered.** The delivered flow reads
`order_details_rewards` with `getFirstWhere(order_id, 'loyalty_point')` and sets `reward_delivered = 1` on that row only
(`Admin\Order\OrderController.php:729-733`, vendor `:682-686`). For an order with several lines, the other rows stay at 0, and the
order-edit check in `OrderEditManager`, which sums delivered rows only, undercounts the points already credited.

- Not pinned in this change.
- Proposal: mark every loyalty reward row of the order in the same claim as IDEM-08.
- **Owner decision:** approve marking all rows. Historical rows stay as they are unless a separate backfill is approved.

## LO-08

**Admin and vendor earn points under different conditions.** The vendor delivered flow also requires `wallet_status == 1` and
`seller_id != null` (`Vendor\Order\OrderController.php:684`). The admin flow does not. The same order earns points when an admin
delivers it and none when a vendor does, if the wallet is off.

- Not pinned in this change.
- Proposal: one shared condition, in one service both controllers call.
- **Owner decision:** state whether loyalty points depend on the wallet being on.

## LF-1

**Under `multi_currency`, REST and web convert the same points to different credits.** With SAR as default at 3.75 per dollar and
100 points per unit, 100 points give:

| Client | Formula | Stored credit (USD) |
|---|---|---|
| REST (`CustomerManager::create_wallet_transaction`) | `points / rate * Convert::default(1)`, then `Convert::usd` | 1.0000 (3.75 SAR) |
| Web (`CustomerTrait::createWalletTransaction`) | `loyaltyPointToLocalCurrency`, `localToDefaultCurrency`, then `currencyConverter` | 0.2667 (1 SAR) |

- Pinned: `PointsToWalletFormulaCharacterizationTest::test_current_behaviour_under_multi_currency_the_api_credits_one_dollar_for_one_hundred_points`
  and `..._the_web_credits_one_riyal_in_dollars_for_one_hundred_points`.
- Proposal: one canonical points-to-USD helper, used by both writers and by the preview (LF-3).
- **Owner decision:** state what `loyalty_point_exchange_rate` means (points per dollar, or points per unit of the default
  currency), and so which of the two values is right. Past conversions are not recalculated.

## LF-2

**Under `single_currency` with a stored rate, the web still divides by it.** With the default currency stored at 3.75, REST credits
1.0 and web credits 0.2667 for 100 points, because `localToDefaultCurrency` divides by the session rate whatever the currency model.

- Pinned: `..._under_single_currency_the_api_credits_one_unit_for_one_hundred_points` and
  `..._under_single_currency_the_web_divides_by_the_stored_rate`.
- Proposal: the helper of LF-1, which ignores rates under `single_currency`.
- **Owner decision:** the same as LF-1.

## LF-3

**The web preview uses a third formula.** `Web\UserLoyaltyController::getLoyaltyCurrencyAmount` shows
`session rate / exchange rate * points` in the session currency. It does not use either writer's formula, so the preview can differ
from the credit.

- Not pinned in this change.
- Proposal: the preview calls the LF-1 helper.
- **Owner decision:** the same as LF-1.

## LF-4

**Earned points are computed with different helpers.** The per-line reward recorded at checkout uses `Convert::default` on the line
subtotal (`CustomerManager::countLoyaltyPointForAmount`). The credit on delivery uses `usdToDefaultCurrency`, which floors, on the
order total. Both writers then truncate to whole points with `(int)`.

- Not pinned separately (LF-6 shows the effect).
- Proposal: one canonical earn helper, with one rounding rule.
- **Owner decision:** choose the rounding rule (truncate, round, or keep fractions).

## LF-5

**The two writers disagree on a zero-point credit.** The repository refuses an order credit worth less than one point; CustomerManager
writes a zero-point row for the same order.

- Pinned: `LoyaltyPointCharacterizationTest::test_current_behaviour_customer_manager_writes_a_zero_point_row_for_the_same_order`.
- Proposal: one writer behaviour, through the shared helper.
- **Owner decision:** whether zero-point rows are wanted in the ledger.

## LF-6

**The delivery credit ignores the points recorded per line.** On delivered, the admin flow credits points recomputed from
`order_amount - shipping_cost`, not the sum of `order_details_rewards.reward_amount`. In the fixture, the line recorded 20 points and
delivery credited 10.

- Pinned: `test_current_behaviour_the_delivery_credit_is_recomputed_from_the_order_total_not_the_recorded_line_points`.
- Proposal: per-line recorded points. Credit the sum of the recorded rewards, and debit the recorded reward of the refunded line.
- **Owner decision:** whether the recorded per-line points are the source of truth.

## LF-7

**A refund can debit more points than delivery credited.** The refund debit uses the line's recorded `reward_amount` (20 in the
fixture), while delivery credited the recomputed amount (10). Refunding the whole order takes back more points than it gave.

- Pinned indirectly: LF-6 shows the credit, and the RF-01 sequential test shows the 20-point debit.
- Proposal: per-line recorded points (LF-6).
- **Owner decision:** the same as LF-6.

## IDEM-06

**The wallet writers have no idempotency key.** `order_refund`, `return_order_amount_by_admin` and `loyalty_point` credits write a
new row every time they are called. Every guard against a second credit lives in the caller (RF-01, RF-03, RF-04).

- Pinned: `WalletWriteAtomicityTest` already characterizes retries as unguarded.
- Proposal: an optional idempotency key on the writer, used by refund callers with the RF-02 reference.
- **Owner decision:** approve a writer-level key. That is a schema and writer change, so it is not made here.

## IDEM-07

**`pos_order_cancel_{id}` is looked up, not enforced.** No unique index covers `(transaction_type, reference)` on
`wallet_transactions`, so the POS cancel key protects only against a repeat that arrives after the first commit.

- Pinned: the RF-04 sequential test.
- Proposal: a unique index on a new nullable idempotency column (not on `reference`, which holds repeated literals such as
  `order_refund` today).
- **Owner decision:** approve the column and index. That is a schema change and a migration.

## IDEM-08

**Two "delivered" updates that overlap both credit the order's points.** The admin and vendor delivered flows check
`reward_delivered != 1`, credit `order_place` points, then set `reward_delivered = 1`. Nothing holds the check.

- Pinned (sequential half): `test_current_behaviour_a_second_delivered_status_after_reward_delivered_writes_one_order_place_row`.
- Skipped race: `test_simultaneous_delivered_statuses_credit_the_order_points_once`.
- Proposal: claim with
  `UPDATE order_details_rewards SET reward_delivered = 1 WHERE order_id = ? AND reward_type = 'loyalty_point' AND reward_delivered = 0`,
  and credit only when rows changed (this also covers LO-07).
- **Owner decision:** approve the claim on the admin and vendor flows.

## IDEM-09

**The vendor refund flow repeats the admin guards separately.** `Vendor\RefundController::updateStatus` has its own
`status != 'refunded'` check and its own `refund_order` debit with the order id as the reference. A fix to RF-01 in the admin
controller alone would leave the vendor path open.

- Not pinned in this change.
- Proposal: one refund service with the claim, called by both controllers.
- **Owner decision:** approve the shared service as part of RF-01.

---

## Out of scope here

LO-02, LO-03 and LO-06 belong to other workstreams and are not covered by this document or its tests.
