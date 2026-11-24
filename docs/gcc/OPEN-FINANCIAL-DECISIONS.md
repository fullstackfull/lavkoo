# The money questions that are the owner's, with what each answer costs

**Status:** nothing in this file has been implemented. It exists because three parts of the plan cannot be finished without an answer that
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

### One more thing this decision holds up

`Convert::default()` rounds every amount it converts to **two** decimals, in both currency models. Both loyalty formulas read it
(`($points / $rate) * Convert::default(1)`), so correcting that rounding to the decimals of the currency would change what points are worth
— which is this decision. On today's platform the default currency is the riyal and the rounding loses nothing; on an installation whose
default currency has three decimals it loses a tenth of a fils per unit. The commission, which read the same two decimals from a different
function, was separable and is fixed.

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

---

## How to answer

One line each is enough, e.g. *"1A with no back-credit"*, *"2B"*, *"3A"*. Each answer is written into
`docs/gcc/DECISIONS.md` with the date, and the work it unblocks is listed above it.
