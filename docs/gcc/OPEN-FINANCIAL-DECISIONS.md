# The money questions that are the owner's, with what each answer costs

**Status:** nothing in this file has been implemented. It exists because five parts of the plan cannot be finished without an answer that
is not a technical one: each option moves real money in a different direction, and picking one myself would be deciding what the platform
pays its customers and its couriers. Every figure below is **measured**, with the test or the line of code that produced it named, so the
decision can be taken on numbers rather than on description.

The owner's instruction of 2026-11-24: *do not assume the loyalty point price, the courier settlement currency, or change the refund
behaviour automatically; implement the independent safe parts and prepare the cases that need a decision with the impact of each option.*
The independent safe parts are done and named at the end of each section.

---

## 1. What a loyalty point is worth (E-14, findings LF-1 and LF-2)

### What the platform does today

The same 100 points buy **two different amounts of money**, depending on which client the customer converts them in. Measured in
`tests/Feature/Loyalty/PointsToWalletFormulaCharacterizationTest.php`, with the store's default currency SAR at 3.75 to the dollar and
`loyalty_point_exchange_rate` set to 100:

| Where the customer converts | Formula in the code | What 100 points credit |
|---|---|---|
| Mobile apps / REST (`WalletPosting::loyaltyPointsConvertedInTheApi`) | `(points / rate) * Convert::default(1)` | **1.00 stored unit = 1 USD = 3.75 SAR** |
| Website (`loyaltyPointToLocalCurrency`, `app/Utils/currency.php:204`) | `points / rate`, and under `multi_currency` `points * sessionRate / rate` | **0.267 stored units = 1.00 SAR** |

So a customer who converts in the app receives **3.75 times** what the same customer receives on the website. The factor is exactly the
USD rate of the store's default currency, so it is 3.75 for a Saudi store and would be 3.67 for an Emirati one, 0.30 for a Kuwaiti one.

### What the admin was promised

The field in Business Setup is labelled (`resources/views/admin-views/customer/customer-settings.blade.php:184`):

> **Equivalent Points Needed to Redeem** *{amount of 1 USD in the default currency, with its symbol}*

On a Saudi store that label reads "Equivalent Points Needed to Redeem 3.75 SAR". The number the admin types is therefore the points needed
for **one US dollar's worth** of wallet money — which is what the REST formula pays and 3.75 times what the website pays. On the evidence
of the label, the website path is the one that does not do what the setting says.

### The options

| | What it means | What it costs |
|---|---|---|
| **A. The app's reading is the rule** (recommended) | `rate` points = 1 USD = 1 stored unit, everywhere. The website's formula is corrected to the app's. | Website conversions become 3.75× more generous on a Saudi store, which is what the admin's own label promises. The cost per 100 points rises from 1.00 SAR to 3.75 SAR. **Historical question:** every customer who converted on the website was credited a quarter of what the label promised; whether to back-credit the difference is a second decision, and the ledger has the rows to compute it (`wallet_transactions` with `transaction_type = 'loyalty_point'`). |
| **B. The website's reading is the rule** | `rate` points = 1 unit of the currency in force. The app's formula is corrected to the website's, and the admin label is corrected to say "1 SAR". | App conversions become 3.75× less generous on a Saudi store. Customers who converted in the app were over-credited; clawing that back from a wallet is not something I would do automatically under any instruction. Cheaper for the platform going forward, and the harder one to explain to a customer who has already seen the app's figure. |
| **C. One new explicit setting** | A new setting states the money a point is worth in the **storefront's own currency**, per market, and both paths read it. The old rate stays only to convert existing balances. | The most work and the only option that is right for six markets at once: a point worth 0.01 SAR is not worth 0.01 KWD (about ten times as much). Needs a migration for the per-market setting, a rule for what each market starts at, and a statement of what happens to points earned before the change. |

### What is blocked until it is answered

- E-14's criterion "REST and web conversions credit the same wallet amount for the same points" — the only thing standing between the two
  formulas is which one is right.
- The per-market point value, which is part of the six-market design (a single platform-wide rate cannot be right in both Riyadh and
  Kuwait City). Option C answers both at once.

### Done already, needing no decision

The divergence is pinned by tests, so whichever answer is given, the change is one line in each formula and the tests fail loudly if a
third formula appears. `App\Services\Loyalty\PointsToWalletConversion` is the one place both paths already go through for the debit side.

Since 2026-11-25 the credit side goes through one place too, and the REST writer reads the exchange rate the way every other reader of the
four loyalty settings reads it — through `getWebConfig`, so a market with its own rate converts at ITS rate rather than at the platform's.
That was a straight defect and needed no decision: on a market with no rate of its own, and on every market while storefronts are off, the
figure is the one that line read before.

**One thing this uncovered, and it is not a decision either — it is a gap.** A per-market exchange rate cannot yet be observed through a
conversion at all, because the switch that makes a market answer its own loyalty settings (`gcc.storefronts.currency.enabled`) is the same
switch that CLOSES THE WALLET in a market trading in its own currency (GCC-05 slice 5b). Both loyalty controllers read `wallet_status`
through the storefront seam, which answers 0 there, so they refuse with 422 before any rate is read. Measured at 0 for Kuwait. Nothing about
this decision depends on it; it is why the per-market half of E-02 is tested at the writer and not at the endpoint.

### One more thing this decision holds up

`Convert::default()` rounds every amount it converts to **two** decimals, in both currency models. Both loyalty formulas read it
(`($points / $rate) * Convert::default(1)`), so correcting that rounding to the decimals of the currency would change what points are worth
— which is this decision. On today's platform the default currency is the riyal and the rounding loses nothing; on an installation whose
default currency has three decimals it loses a tenth of a fils per unit. The commission, which read the same two decimals from a different
function, was separable and is fixed.

---

### Prepared, and waiting only on the answer — `4f349593`, **and a correction of 2026-11-25 that matters to the owner**

`App\Services\Loyalty\WhatAPointIsWorth` holds both formulas and hands each path its own. The setting
`gcc.loyalty.point_value` (`GCC_LOYALTY_POINT_VALUE`) names the three answers, and an unrecognised value is read as today's
behaviour, so a typo in an environment file cannot move money.

> **CORRECTION.** Until 2026-11-25 this section said the answer was one setting away. **It was not: the setting reached no
> customer.** `WhatAPointIsWorth` was read only by `App\Services\Customer\WalletPosting`, and nothing in the application
> calls that class — both loyalty controllers even import it and then use the older writers. The REST conversion went to
> `CustomerManager::create_wallet_transaction` and the website's to `CustomerTrait::createWalletTransaction`, each carrying its
> own copy of a formula. Answering LF-1 and setting the value would have changed nothing anybody was paid.
>
> Both writers now ask the one class, with the default rule reproducing each path's own formula to the digit — measured, not
> asserted: `PointsToWalletFormulaCharacterizationTest` still reads the same two figures through the real controllers.
> `TheLoyaltyDecisionReachesTheRealEndpointsTest` (9 tests) drives both endpoints and shows that option A makes both credit one
> dollar's worth, option B makes both credit one unit in force, and the two agree under either answer — which is the criterion
> this decision unblocks. So the answer IS now one setting, and the claim can be relied on.

- **Default, as it ships:** `as_each_path_does_today` — the apps keep the app formula, the website keeps the website's, to the digit.
  `PointsToWalletFormulaCharacterizationTest` still measures the same two figures, which is the proof that nothing was adopted.
- **To adopt option A:** set it to `one_dollar_per_rate`. Every path then credits `rate` points as one US dollar. On a Saudi store
  the website starts paying 3.75× what it pays today for the same points.
- **To adopt option B:** set it to `one_unit_in_force_per_rate`. Every path credits `rate` points as one unit of the currency in
  force. The apps then pay a third of what they pay today.

Either answer changes what a customer is paid for points they already hold, which is why nothing switches itself. **Not done, and
part of adopting either option:** deciding what happens to points already earned, and flipping the two characterization figures on
purpose in the same change.

---

## 2. Which currency a courier is paid in for a non-Saudi order (C-15, finding E11-F02)

### What the platform does today

Every provider shipment is stamped with the currency the **configuration** names, never the market the order was sold in:

```php
// app/Services/Fulfillment/FulfillmentRouter.php:153
'currency' => CurrencyCode::provider(),   // reads FULFILLMENT_CURRENCY, 'SAR' unless deployed otherwise
```

So a Kuwaiti order routed to a provider today would be sent a **SAR** figure and a SAR label, and the cash the courier collected would be
reconciled against a SAR amount (`TorodPointAdapter::cashMatches`). No non-Saudi provider account has ever been enabled, which is why this
has cost nothing so far; it is a precondition of the first non-Saudi shipment, not a live defect.

### The options

| | What it means | What it costs |
|---|---|---|
| **A. Keep the configured currency** | One currency for every provider, as today; a non-Saudi order's amount is converted into it. | No code changes and no new settings. But the courier collects cash from a Kuwaiti customer in dinars and is accounted in riyals, so every reconciliation carries an exchange-rate difference that somebody has to absorb, and the invoice the courier issues will not match the figure the platform sent. Workable only while all providers really are paid in one currency. |
| **B. The order's storefront currency** (recommended for the six markets) | The shipment carries the currency the customer was charged in: a Kuwaiti order is a KWD shipment. | Each provider account must be able to accept its market's currency, and three of those currencies have three decimals — which is now carried end to end (see below). Needs the order to record its currency, which the storefront snapshot already gives, and a per-provider-account statement of which currencies that account settles in. |
| **C. A currency per provider account** | Each provider account names its own settlement currency; the platform converts into it and records the rate used. | The most faithful to how courier contracts are actually written, and the most machinery: a conversion and a recorded rate per shipment, and a reconciliation that compares two currencies. Also the only option that handles a provider operating across two markets in one currency. |

### What is blocked until it is answered

- C-15's first criterion ("the currency stamped on a provider fulfilment is the order's storefront currency") and its last
  ("the Saudi account keeps today's values as explicit per-account defaults").
- Enabling any non-Saudi provider account. E11-F02 rates this P0 if that happens before the question is answered.

### Done already, needing no decision — landed

How many decimals a currency has is not the same question as which currency is used, so this part is finished and Saudi behaviour is
unchanged: the cash split now works in the smallest coin of the currency it is splitting (a halala for a riyal, a fils for the dinar and
the rial), the conversion rounds to that coin, the columns hold a third decimal, the model and the admin screens print the decimals the
row's own currency has, and the reconciliation tolerance is one smallest coin rather than one halala. Proved in
`tests/Feature/Fulfillment/FulfillmentMoneyThreeDecimalsTest.php`.

---

### Prepared, and waiting only on the answer — `8614ca13`

`App\Services\Fulfillment\Support\WhatACourierIsPaidIn` answers which currency a shipment is stamped with, for an order and a
provider. The setting `fulfillment.settlement_currency` (`FULFILLMENT_SETTLEMENT_CURRENCY`) names the three answers; today's is the
default, and an unrecognised value reads as the default.

- **Default, as it ships:** `as_configured_today` — one currency for every provider, exactly as now.
- **Option B:** `the_orders_market` — the currency the customer was charged in, from the order's own storefront snapshot. An order
  that names none (every order placed before the snapshot existed) keeps the configured currency.
- **Option C:** `the_provider_account` — each provider account states its currency in
  `fulfillment.providers.<code>.settlement_currency`; an account told nothing keeps the configured one.

**What is deliberately NOT done:** converting an order's money into a currency other than the configured one. So while option B or C
is in force, a shipment whose stamp is not the currency its figures were converted into is **held**, not sent — the existing
`hold_currency_unconvertible`, which an operator already knows and which releases itself. A right label over a wrong figure would be
worse than no shipment, because that figure is the cash the courier collects. Adopting an option therefore means one more step:
making `ProviderCurrency` convert per order, and giving each account the list of currencies it settles in.

---

## 3. Whether an admin-cancelled wallet-paid order refunds itself (E-13, finding E04-F10)

### What the platform does today

Two cancellations behave differently, and the difference is deliberate rather than accidental:

- a **POS** order cancelled by an admin **does** return the wallet debit;
- a **non-POS** order cancelled by an admin **does not**: the customer's wallet keeps the debit until somebody refunds it by hand.

Five of E-13's six criteria are met and merged (`287ae061`); this is the sixth.

### The options

| | What it means | What it costs |
|---|---|---|
| **A. Refund automatically** | An admin cancelling a wallet-paid order credits the wallet back in the same transaction as the cancellation. | The customer gets their money back without asking, which is what most customers expect. It also means an operator's click moves money, so it needs the same idempotency the rest of the refund path has (one credit per cancellation, proved under concurrent clicks) and an audit line naming the operator. The POS path already does exactly this, so there is a shape to copy. |
| **B. Keep it manual, and make it visible** | Nothing moves automatically; the panel shows an outstanding wallet debit on every cancelled wallet-paid order until it is refunded, and a report lists them. | No new money movement at all, which is the safest change to make without an owner's signature. The money stays with the platform until a person acts, and a cancelled order whose refund is forgotten stays forgotten unless somebody reads the report. |
| **C. Automatic only where the payment was the wallet itself** | Automatic for a wallet payment, manual for a card or a gateway payment (where the money has to come back the way it came in). | Splits the difference and matches where the money actually is. Two paths to keep correct instead of one. |

### What is blocked until it is answered

E-13's last criterion, and nothing else: the refund machinery, its idempotency and its audit trail are implemented and merged.

### Done already, needing no decision

The behaviour as it stands is pinned by a test, so whichever way it is answered the change is visible: the test says a POS cancel refunds
and a non-POS cancel does not, and it fails the moment either one moves.

### Prepared, and waiting only on the answer — `00f7670c`

`App\Services\Refund\WhenACancellationRefundsTheWallet` holds the rule, the amount and the ledger reference, which the admin and
vendor panels asked separately and identically before it existed, so the answer lands in both at once. The setting is
`gcc.refunds.when_a_cancellation_refunds_the_wallet` (`GCC_CANCELLATION_REFUNDS_THE_WALLET`), and an unrecognised value reads as
today's behaviour.

- **Default, as it ships:** `manual_as_today` — only a POS sale paid with the wallet refunds itself.
- **Option A:** `every_paid_cancellation` — any cancelled order with money on it pays the wallet back, whatever it was paid with.
- **Option C:** `wallet_payments_only` — automatic where the money came from the wallet, by hand where it has to go back the way it
  came in.

Three things make an option safe to hold ready. A POS wallet sale keeps the figure cancelling it has always credited, and every
order an option newly covers is credited **what was actually paid**, capped at what it cost. The reference is keyed on what the
order is and never on the rule, so changing the rule cannot make one cancellation look like two. And paying once per cancellation is
the status claim's doing (RF-04), not the setting's, which the test proves under an option as well as under today's rule.

**Not done, and part of adopting an option:** the audit line naming the operator who cancelled, a race test alongside the POS one,
and deciding what happens to orders already cancelled and never refunded.

---

---

## 4. What a payment a gateway took is worth in the unit the platform stores money in (E-V1)

### What the platform does today

Under `multi_currency`, a customer who pays **100 riyals** into the wallet has **100** stored, and every screen reads that account back as
**375 riyals**. Measured end to end in `tests/Feature/Payments/WhatAPaymentIsWorthTest.php` and
`tests/Feature/Wallet/ATopUpCreditsWhatWasPaidTest.php`, with the riyal at 3.75 to the dollar.

The stored unit is the dollar: `usdToDefaultCurrency()` multiplies a stored figure by `default_rate / usd_rate` to show it, and
`usdToAnotherCurrencyConverter()` multiplies it by `code_rate / usd_rate` to charge it. The inverse of both is
`amount * usd_rate / rate_of_the_currency_paid`, and **26.67** is what 100 riyals is worth.

`Convert::usdPaymentModule()` does not compute that. It converts the payment into the store's **default currency** and stops:

```php
// app/Utils/Convert.php, before this work; now App\Services\Payments\WhatAPaymentIsWorth::asTheModuleHasAlwaysDoneIt()
$defaultAmount = ... ;                       // the amount in the DEFAULT currency, which this part gets right
$value = $defaultAmount * $usdRate;          // and here the division by the default rate is missing
```

So its answer is `default_rate` times the figure that should be stored — 3.75 times on a Saudi store, 3.67 in the Emirates, 0.31 in Kuwait.
A store whose default currency **is** the dollar takes an earlier branch and is correct, which is the shape of a missing final division
rather than of a deliberate rule.

The remaining two conversions of the top-up cancel each other exactly (`usdToDefaultCurrency` multiplies by `default/usd`,
`Convert::usd` inside `create_wallet_transaction` divides by it again), so the stored figure is `usdPaymentModule`'s answer and nothing
else.

### Where that conversion is actually read — a correction to the earlier record

An earlier note in `docs/gcc/tracker/coverage-gaps.md` and in E-V1 said this conversion is on the path of **every** gateway payment. It is
not, and the difference matters to the size of the decision. Every caller, measured:

| caller | what it converts |
|---|---|
| `app/Utils/module-helper.php:258` | the wallet top-up's credit — the figure above |
| `app/Http/Controllers/Customer/PaymentController.php:577` | the amount a wallet top-up hands the gateway |
| `app/Http/Controllers/Customer/PaymentController.php:587` | the minimum and maximum top-up checks |
| `app/Utils/ProductManager.php:2390`, `:2394` | the website catalogue's price-range filter bounds |
| `app/Http/Controllers/RestAPI/v3/seller/ProductController.php:314`, `:318`, `:569`, `:573` | the same filter in the seller API |

An **order** payment does not go through it: `PaymentController::payment()` converts the already-stored order total with
`usdToAnotherCurrencyConverter()` (line 478), which is the correct arithmetic. So the money at stake is the wallet top-up; the filter
bounds are a search range and not a figure anybody is charged.

### The options

| | What it means | What it costs |
|---|---|---|
| **A. Keep today's reading** | A payment is stored as its value in the default currency, under a function named for dollars. | No code changes. But a top-up credits `default_rate` times what the customer paid — 3.75× on a Saudi store — so the day a market is switched to `multi_currency` the wallet becomes a money printer, and the price filter asks for a range 3.75× wider than the one that was typed. Workable only while the platform stays single-currency, where both readings are the identity. |
| **B. Correct the conversion** (recommended) | `amount * usd_rate / rate_paid`, the exact inverse of the conversion that charged the gateway. A 100 riyal top-up stores 26.67 and reads back as 100 riyals. | One setting, already built and tested. It changes **what a stored unit is worth to a payment**, which is the same question as section 1 (what a loyalty point is worth), so the two should be answered together. **Historical question:** a platform that has already run `multi_currency` holds balances written at the old reading, and whether those are restated is a second decision; `wallet_transactions` carries `credit`, `balance` and, for rows written since E-05, the payment behind each one, so the difference is computable. |
| **C. Correct it and stop calling it dollars** | B, plus renaming the stored unit in the code so the next reader is not told a figure is in dollars when it is in the default currency. | The clearest, and the largest diff: `usd`, `usdPaymentModule`, `usdToDefaultCurrency`, `usdAmountInDefaultCurrency` and `bdtTousd` all carry the same assumption, and four of them are read from Blade. Not worth doing before B is answered, and not worth doing as part of it. |

### What is blocked until it is answered

- E-V1's criterion "a conversion credits what was paid" — its floor half is closed and measured; this is the other half.
- Switching any market to `multi_currency`. While the platform is single-currency both readings are the identity, which is why this has
  cost nothing so far; it is a precondition of the first multi-currency market, not a live defect.

### Done already, needing no decision

The defect is pinned in two places and the dormant default is proved rather than assumed: a matrix of 60 cases — five amounts × four paid
currencies × three default currencies — compares the conversion in force against a frozen copy of the arithmetic as it stood, with
`assertSame` on the floats, so a figure differing in its last bit fails. The same matrix asserts that the two readings agree **only** where
the dollar is the default or the dollar was paid, so it is measuring the difference it claims to measure.

### Prepared, and waiting only on the answer

`App\Services\Payments\WhatAPaymentIsWorth` holds both readings and `Convert::usdPaymentModule()` is one line into it, so all nine call
sites move together or not at all. The setting is `gcc.payments.what_a_payment_is_worth` (`GCC_WHAT_A_PAYMENT_IS_WORTH`), and an
unrecognised value reads as today's behaviour.

- **Default, as it ships:** `as_today` — the module's own arithmetic, branch for branch, to the digit.
- **Option B:** `the_rate_of_the_currency_paid` — the correction. On a Saudi store a 100 riyal top-up then stores 26.67 and reads back as
  100 riyals.

**What is deliberately NOT done:** nothing restates a balance, and nothing is converted on a schedule. Turning the setting on changes new
payments only; existing balances keep the figure they were written with, and deciding what happens to those is part of adopting it.

---

## 5. What happens when a refund takes back more loyalty points than the customer still holds (E-14)

### What the platform does today

The balance goes **negative**. A customer holding 10 points whose order is refunded for 30 points ends on **-20**, and the next
order they place starts by paying that off. Pinned since this work began, in
`tests/Feature/Loyalty/LoyaltyPointCharacterizationTest::test_current_behaviour_a_refund_can_take_the_points_below_zero`.

Both writers of a point balance refuse a debit above the balance for a **conversion** -- a customer cannot convert points they
have not got -- and neither checks a **refund** debit at all:

```php
// app/Repositories/LoyaltyPointTransactionRepository.php and app/Utils/CustomerManager.php, the same two lines in each
$currentBalance = $user['loyalty_point'] + $credit - $debit;
if ($transactionType == 'point_to_wallet' && $currentBalance < 0) {
    return false;        // and `refund_order` falls straight through
}
```

It is a reachable state and not a theoretical one: a customer earns points on an order, converts them to wallet money, and the
order is then refunded. The points are already spent and the refund reclaims them anyway.

The criterion itself was written with the three answers in it -- *"refuse, clamp or allow and report: owner choice"* -- so this
section is the costing of those three rather than a new question.

### The options

| | What it means | What it costs |
|---|---|---|
| **A. Allow and report** | Today's behaviour, kept on purpose, with the negative balance made visible to an operator rather than left to be discovered. | No money and no points move. But a customer with a negative balance earns nothing from their next orders until it is paid off, which no screen explains to them, and nothing distinguishes a balance that went negative from one that was never earned. |
| **B. Refuse** | The points row is refused and nothing is written. The refund's **money** is unaffected -- only the points side is refused. | The customer keeps points they were paid for goods they returned, which is a cost to the platform and the one case where it cannot reclaim them. It is also the shape the conversion guard already has, so the code is consistent with itself. The refund's money still moves, so an operator never sees a refund blocked by this. |
| **C. Clamp to zero** (recommended) | The refund takes what is there and no more; the balance lands on zero and the row records the debit that was **actually taken**. | The platform reclaims everything it still can, no customer is ever in debt for points, and the ledger still adds up to the balance -- which a negative balance does too, but only if every reader expects one. The cost is that the figure on the points row is no longer the figure the refund asked for, so a reconciliation between a refund and its points has to read the row rather than recompute it. |

### What is blocked until it is answered

E-14's criterion "a refund cannot take points below zero", and nothing else. No money figure depends on it: the wallet credit of
a refund is computed and paid separately.

### Done already, needing no decision

The one question is asked in one place for both writers. Before this, the admin and vendor refund screens (the repository) and
the seller API (`CustomerManager`) each carried their own copy of the guard, and a repair applied to one and not the other
would have been a worse defect than the one it fixed -- which the teeth check measures: removing the call from one writer turns
exactly that writer's three cases red and no others.

### Prepared, and waiting only on the answer

`App\Services\Loyalty\WhenARefundWouldTakePointsBelowZero` holds all three answers and both writers ask it, inside the
customer's balance lock and with the balance that lock read, so two refunds of one customer cannot both clamp against the same
figure. The setting is `gcc.loyalty.when_a_refund_would_take_points_below_zero` (`GCC_REFUND_POINTS_BELOW_ZERO`), and an
unrecognised value reads as today's behaviour.

- **Default, as it ships:** `allow_as_today` -- the debit is written in full and the balance goes negative, to the point. The
  characterization test still measures -20, which is the proof that nothing was adopted.
- **Option B:** `refuse` -- no points row at all.
- **Option C:** `clamp_to_zero` -- the balance lands on zero and the row carries the debit that was taken.

A refund the customer **can** afford behaves identically under all three, which is asserted rather than assumed, and the
conversion guard is unreachable from this setting, which is asserted too.

**What is deliberately NOT done, and is part of adopting an option:** the "report" half of option A -- no screen shows an
operator that a balance is negative -- and nothing restates a balance that is negative already.

## How to answer

One line each is enough, e.g. *"1A with no back-credit"*, *"2B"*, *"3A"*, *"4B, new payments only"*, *"5C"*. Each answer is written into
`docs/gcc/DECISIONS.md` with the date, and the work it unblocks is listed above it.
