# Handoff — the third decimal of the dinar carried through fulfilment, and six criteria re-read after the merge

**Session:** `claude/intelligent-franklin-jq3d4h` · **Branch:** `gcc/development` · **Date:** 2026-11-24

## What this checkpoint contains

### 1. C-15: fulfilment carries the third decimal (real code, Saudi unchanged)

Kuwait, Bahrain and Oman price in thousandths. Fulfilment was built for the halala, so a 12.345 cash-on-delivery order asked its courier
for **12.350** and a single fils between two collectors reached **neither** — measured before anything was touched, in the characterization
test this checkpoint turns into a proof of the fix.

The money now moves in the smallest coin of the currency it is in, which for a riyal is the hundredth it always was: the splitter, the
conversion, the two tolerances, the columns, the model casts and three admin screens. Written up in
[`C-15-THREE-DECIMAL-MONEY.md`](../C-15-THREE-DECIMAL-MONEY.md), with the table of what changed and why the riyal cannot move.

It deliberately does **not** answer which currency a provider is paid in for a non-Saudi order. That is the owner's, and it is written up
with the impact of each option.

### 2. The three money questions that are the owner's, with measured impact

[`OPEN-FINANCIAL-DECISIONS.md`](../OPEN-FINANCIAL-DECISIONS.md) — what a loyalty point is worth, which currency a courier settles in, and
whether an admin-cancelled wallet-paid order refunds itself. Each with what the code does today, the options, what each option costs, and
what it unblocks. The loyalty one carries a finding: the admin's own label in Business Setup reads *"Equivalent Points Needed to Redeem
{1 USD in the default currency}"*, which is what the app pays and **3.75 times** what the website pays on a Saudi store, so the website
formula is the one that does not do what the setting says. Nothing is implemented from that file.

### 3. Six criteria that were not missing, only unread since the merge

Each was marked missing or coded because its evidence described a draft PR. Each is now re-verified by running the tests it names:

| Requirement | Criterion | Proof |
|---|---|---|
| D1-03 | the invoice prints the currency the order was sold in | OrderCurrencyOnEverySurfaceTest (25): six invoice surfaces, the order e-mail a worker sends, four exports |
| F-14 | renaming the brand does not change the legal name on existing invoices | **the one test that was missing**, now written, with a control in the same test |
| M2-07 | an order records its storefront, currency and rate; invoices, e-mails, exports and refunds show them | OrderStorefrontSnapshotTest, OrderCurrencyOnEverySurfaceTest, RefundFromOriginalPaymentTest |
| M2-07 | a storefront's currency reaches the converters and no amount is converted twice | StorefrontCurrencyTest (3 named cases) |
| M2-11 | gateway account, currency, method and refund rules per legal entity and market | 95 tests across the payment-account and refund files |
| A2-10 | a customer cannot read another customer's order by id | ApiCustomerOrderOwnershipTest, WebCustomerOrderOwnershipTest (15) |

The seventh line of that work is the one that stayed missing: three-decimal **prices** are proved on four surfaces (display, refund,
wallet debit, fulfilment) and not on the fifth — no test saves a product price while a dinar market is in force. The criterion says so now
instead of saying "no test beyond wallet debit".

## The numbers after this checkpoint

Of 359 requirements and 1,753 acceptance criteria: **903 met (52%)**, 376 coded but unproven (21%), 474 not implemented (27%).
Development **73%**. Launch readiness **18 of 359 (5%)**, which G0 gates whatever lands.

## What is verified, and how

- `tests/Unit/Fulfillment tests/Feature/Fulfillment` — **1,826 tests green on SQLite 8.3**.
- the same plus payments and operational costs — 2,518 tests on **MariaDB 11.4**.
- `FulfillmentMoneyThreeDecimalsTest` (12), and the teeth: putting the hundredths back in the allocator fails 5, putting `decimal:2` back
  on one column fails 1.
- the six re-verified criteria: 215 tests across the files named above, run on SQLite 8.3 today.

## What remains, in the owner's order

1. **The three answers above.** Each unblocks a named criterion and nothing else is in the way.
2. **C-15's destination country and phone** — `SaudiPhone::normalize` and a hardcoded `country_code` SA in the adapters. Needs no decision.
3. **E-01's three criteria** (a top-up or a checkout touching one market's balance only) wait on the wallet-key decision: per storefront, or
   per legal entity where two storefronts share one.
4. **E-07's backfill rehearsal** on a disposable copy of the real database — not code; the command is built for it and refuses any database
   whose name does not match.
5. **The apps.** A1-03's last criterion, A1-06's seller APIs, A1-17's push topics. No Flutter toolchain in this container, so only a
   pushed branch's CI can verify them.
