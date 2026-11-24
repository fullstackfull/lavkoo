# C-15: the third decimal of the dinar and the rial, carried end to end

**Status:** on `gcc/development`. Saudi behaviour is unchanged, pinned by tests in the same file as the fix. It does **not** answer which
currency a provider is paid in for a non-Saudi order — that is the owner's, and it is written up with the impact of each option in
[`OPEN-FINANCIAL-DECISIONS.md`](OPEN-FINANCIAL-DECISIONS.md) section 2.

## The gap

Fulfilment was built for one country. In Saudi Arabia the smallest coin is a halala, a hundredth of a riyal, so every amount fulfilment
carried was a two-decimal amount, and that assumption was written into six places at once: the cash split worked in hundredths, the
conversion rounded to two decimals, the four columns holding a shipment's money were `decimal(14, 2)`, the model formatted them with
`decimal:2`, the admin screens printed `number_format(..., 2)` and two tolerances allowed "half a halala" and "one halala".

Three of the six markets price in thousandths: 1 KWD is 1,000 fils, 1 BHD 1,000 fils, 1 OMR 1,000 baisa. Measured before anything was
changed, a 12.345 cash-on-delivery order asked its courier for **12.350** — five fils the customer does not owe — and a single fils split
between two collectors reached **neither**.

## What changed

| Where | Before | Now |
|---|---|---|
| `CodAllocator::allocate` | multiplied by 100 | splits in the smallest coin of the currency it is told, `decimals` defaulting to 2 |
| `ProviderCurrency::fromStored` | `round($amount, 2)` | rounds to `decimals()`, the decimals of the currency a provider is paid in |
| `FulfillmentMoney`, `ProviderOrderShare` | `round(..., 2)` | round to the same decimals, which they now ask for |
| `MoneyAssessment::sameAmount` | `< 0.005` | half the smallest coin: 0.005 for a riyal, 0.0005 for a dinar |
| `TorodPointAdapter::cashMatches` | `<= 0.01` | one smallest coin: 0.01 for a riyal, 0.001 for a dinar |
| `ProviderFulfillment`, `ProviderFulfillmentItem` | `'decimal:2'` | `MoneyInItsCurrency`, which formats a row in the decimals of the currency **that row** names |
| the four shipment money columns and the line total | `decimal(14, 2)` | `decimal(14, 3)` (migration `2026_12_14_100000`) |
| three admin screens | `number_format(..., 2)` | `number_format(..., $fulfillment->money_decimals)` |

**One source for "how many decimals".** `gcc.storefronts.currency.decimals` already held the answer (`KWD`, `BHD`, `OMR` → 3) and
`StorefrontCurrency::decimalsOf()` already read it, answering 2 for every currency it is not told about — the riyal included. Nothing new
was invented: the number comes from there, through `ProviderCurrency::decimals()` for a calculation and through the row's own currency for
a stored figure.

**Why the row and not the configuration.** A shipment records the currency it is in. Reading the decimals off the row means a riyal
shipment and a dinar shipment can sit in the same table, the same screen and the same export and each be right — which is what will
happen the day the second market opens, whichever way the open decision goes.

**Why writing is not formatted.** The cast formats reading only. `create(['cod_amount' => ..., 'currency' => 'KWD'])` fills attributes in
the order the caller wrote them, so the row's currency may not be set yet when its amount is: a cast that formatted on write would answer
"two decimals" for a dinar and round the fils away. The column's own scale decides what is stored.

## What it does not change

- **The riyal.** A riyal is 100 halalas, so "the smallest coin of the currency" is the hundredth it always was: the Saudi split, the Saudi
  rounding, the Saudi round trip and the Saudi tolerances are the same figures by the same rule, and three tests in the fix's own file fail
  if any of them moves.
- **Which currency a provider is paid in.** `FulfillmentRouter` still stamps `CurrencyCode::provider()`, the configuration's currency.
  How many decimals a currency has and which currency is used are two questions; only the first is answered here.
- **The Torod payload.** It still rounds to two decimals, because Torod is a Saudi courier paid in riyals. The day a provider is paid in
  fils, its own builder is where that is stated.

## The cost of the migration

MySQL and MariaDB rewrite a table to change a decimal's scale, so `php artisan migrate` takes time proportional to the number of provider
shipments and holds a write lock while it runs. On this platform the table was created on 2026-11-05 and no non-Saudi provider account has
ever been enabled, so it is small. It is deliberately **not** behind the storefronts switch: a column that can hold a fils amount does not
change how the platform behaves while there are no fils amounts, and widening it at the moment a market opens would put a table rewrite in
the middle of opening a market.

## What is proved

`tests/Feature/Fulfillment/FulfillmentMoneyThreeDecimalsTest.php` (12), on SQLite 8.3 and MariaDB 11.4:

- a 12.345 cash-on-delivery order in KWD, BHD and OMR asks its courier for exactly 12.345;
- one fils between two collectors reaches the one shipping the most, and 12.345 between two equal shipments splits 6.173 / 6.172 and adds
  up to the fils;
- the riyal split is unchanged: half a halala rounds up, 12.34 splits exactly, the odd halala goes to the largest shipment;
- the decimals come from the currency — SAR, AED and QAR two, KWD, BHD and OMR three, an unknown code two;
- an operator setting the site to print no decimals does not change what a courier collects;
- a converted amount is rounded to the smallest coin of its currency, and a riyal amount still to the halala;
- a fils figure survives the round trip through the columns and the model, and a riyal shipment still reads back as two decimals;
- every money column of a shipment is widened, and a shipment is still stamped SAR unless the configuration says otherwise.

**Teeth.** Putting the hundredths back in the allocator fails 5 of the 12; putting `decimal:2` back on one column fails 1. The whole
fulfilment suite (1,826 tests) is green on SQLite and the fulfilment, payments and operational-cost suites on MariaDB 11.4.
