# Handoff — a market reads its own balance, the commission keeps its fils, and the seller app follows the server

**Session:** `claude/intelligent-franklin-jq3d4h` · **Branches:** `gcc/development` in all three repositories · **Date:** 2026-11-24

## What this checkpoint contains

### 1. E-01 and E-04: one market at a time reads its own balance

The accounts and the mirror were merged (E-07) and **nothing read them**. A customer with a hundred dinars in Kuwait City and nothing in
Riyadh saw one number that was neither, and a wallet payment on either storefront was judged against that same number.

`gcc.storefronts.market_accounts.answers_for` is a list of markets, **empty as it ships**. A market on it answers its own account where a
customer is shown their wallet or points, where a statement lists their movements, where a till looks them up, and where a debit is judged.
Everything off the list, and every reader serving no market, answers the one `users` column. **No write moved.** Written up in
[`E-01-MARKET-BALANCE-READS.md`](../E-01-MARKET-BALANCE-READS.md).

The refusal the plan asked for is measured: a payment of 100 in a market holding 5 is refused with the column untouched, while the same
payment is taken in a market that has not been switched over.

**Nothing may go on that list before the backfill is rehearsed on a disposable copy and the owner approves it.** A market switched over
before its accounts were filled would read as a customer with nothing, and the test says so.

### 2. The seller app's till stops being told a customer's private business

`RestAPI/v3/seller/POSController::customers` answered `User::get()->toArray()`: every column the model does not hide — street address,
city, postcode, referral codes, verification flags, temporary token, loyalty points — to a seller who typed three letters of a name into a
till. `PosCustomerResource` answers the **seven fields the app actually reads**
(`lib/features/pos/domain/models/customer_model.dart`), plus the wallet of the market the seller works in. Nothing the app uses is taken
away; everything else stops being sent. Whether a till should be told a customer's e-mail at all is the owner's decision, and the test pins
today's answer so that decision lands in one place.

### 3. C-15: the dinar and the rial are carried, and the commission keeps its fils

- the cash a courier collects is counted in the smallest coin of its currency — splitter, conversion, both tolerances, the columns, the
  model casts and three admin screens ([`C-15-THREE-DECIMAL-MONEY.md`](../C-15-THREE-DECIMAL-MONEY.md));
- the country and the phone a provider is sent follow the market the **order** records, with one rule per market and the prefix pattern left
  to configuration rather than guessed from memory;
- `Helpers::seller_sales_commission()` rounded every commission to two decimals, charging 1.23 on a 12.345 dinar order instead of 1.235 —
  four fils of every dinar the platform is owed — while `CommissionEngine` already kept four decimals and the column holds twenty.

### 4. The apps: the seller app follows the server's default market

The customer app already read `default_country`; the seller app fell back to a constant. It now reads the same public endpoint, keeps "no
answer" apart from "an answer naming no market", and its request header follows what its screens show. **That repository's CI is green**
(`analyze-and-test` and `build-android`, run 51) — this container has no Flutter toolchain, so its CI is the proof. Its workflow now runs on
`gcc/**` too, so the branch is verified on every push.

### 5. Payments: one home for what a gateway is called

`GatewayCurrencyPolicy` kept its own copy of the `my_fatoorah → fatoorah` alias — the drift `PaymentGatewayCatalog` exists to prevent,
inside the very check that refuses a payment. One home now, with a source scan proving it.

## The numbers

Of 359 requirements and 1,753 acceptance criteria: **911 met (52%)**, 375 coded but unproven (21%), 467 not implemented (27%).
Development **73%**. Launch readiness **18 of 359 (5%)**, which G0 gates whatever lands.

Twelve criteria moved to met by reading the code rather than the pre-merge notes, and three more now say precisely what is done and what is
left. One test I started writing was deleted: everything it asserted was already asserted better in the file that owns the subject.

## Still the owner's

[`OPEN-FINANCIAL-DECISIONS.md`](../OPEN-FINANCIAL-DECISIONS.md), with the measured impact of every option:

1. **what a loyalty point is worth** — the app pays **3.75×** what the website pays for the same points on a Saudi store, and the admin's
   own field label says the app is right. `Convert::default()`'s two-decimal rounding waits on the same answer;
2. **which currency a courier settles in** for a non-Saudi order;
3. **whether an admin-cancelled wallet-paid order refunds itself.**

Also waiting: the **wallet key** (per storefront, or per legal entity where two share one), the **backfill approval**, the **invoice number
format per company**, **what a restricted employee may see** of customer wallet reports, and **whether a till may see an e-mail address**.

## One thing found and not fixed

**Several tests leave `resources/lang/en/new-messages.php` dirty.** `translate()` writes a key it cannot find, so a test that renders a
refusal adds it to the tracked language file; `Tests\Support\RestoresLanguageFiles` exists for exactly this and most suites use it, but a
handful under `tests/Feature/Security` do not. Measured on this branch: a run of that group added seven keys
(`file_delete_fail`, `Code_is_required!`, `The_minimum_order_quantity_is_required!`, `module`, `OTP_is_not_matched!`,
`reset_your_password`, `please_provide_verification_token_sent_in_your_email`) — the password-reset, coupon and file-manager tests by the
look of them. Nothing in the plan requires a test to leave the tree clean, which is why nobody noticed; the keys were reverted here, and
the market balance test was given the trait. Worth a criterion of its own in axis M rather than a patch per file.

## What is verified, and how

Every slice above: its own tests on SQLite 8.3 **and** MariaDB 11.4, each with a teeth check (remove the production code, watch the named
tests fail). Group runs: the fulfilment suite (1,871), fulfilment with payments and operational costs on MariaDB (2,518), the storefront
group (5,673), wallet and loyalty (491), the commission-touching suites (813), security and the seller API. Backend CI 651 on `main` had 28
of 30 jobs green with no failures when this was written, its two whole-suite jobs still running.
