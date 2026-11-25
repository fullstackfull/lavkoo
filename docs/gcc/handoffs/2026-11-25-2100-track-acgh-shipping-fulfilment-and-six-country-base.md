# Track `wt/acgh` (axes H, C, A), second batch — handoff

**Branch.** `wt/acgh`, cut from `e96c8485`, eight commits, **not pushed and no pull request** (the track's own instruction).
Database: `pharmacy_testing_acgh`. Every figure below is printed by
`php docs/gcc/audits/2026-11-24-post-merge-assessment/count.php`, never typed.

## What moved

| | before | after |
|---|---|---|
| axis H missing | 27 | 18 |
| axis A missing | 23 | 17 |
| axis C missing | 8 | 4 |
| ALL missing | 272 | 253 |
| in code | 84% | 86% |
| proven | 62% | 63% |

Nineteen criteria. Five of them were already satisfied in code and the cards were behind it; the other fourteen were built.

## The two defects the previous round reported and did not fix

1. **A declared carrier's line got no ownership snapshot, so the parcel was packed in-house.**
   `OrderItemOwnershipSnapshotter::capture()` asked the CLOSED `ProviderCode` enum, so every carrier added the way H1-11 says
   carriers are added -- one adapter class, one configuration entry, no enum case, which is every carrier a second country needs --
   was read at checkout as "not a provider". Inside the checkout transaction, on an immutable row: no later repair is possible,
   because nothing afterwards says what the line was supposed to be. Fixed through `ProviderRegistry::identity()`, with
   `mappingFor()` widened to `ProviderIdentity`. Proved the whole way, not at the unit: the consequence lives in the router, so
   the test runs the real `FulfillmentRouter` and watches a declared carrier's snapshot become a shipment.
2. **A restricted employee saw every warehouse.** The shelf list was the one thing on the slice-5 stock screens that was never
   narrowed, and the existing test said so in a comment. `warehouses` is NOT given a market column, because that column would
   answer the owner's open A1-18 question by forbidding two markets on one shelf. `WarehouseMarkets` derives a warehouse's
   markets from the stock ON it, which presupposes neither answer; an empty shelf names no market and is shown to everybody.

## What else was built

- **H1-01, all three criteria.** `CrossBorderPolicy`, asked FIRST by the one engine of H1-06 -- a question about the SHOP's right
  to trade, before the product's permission and the carrier's route. Ships closed. A pair is one DIRECTION. A kill switch that
  fails CLOSED, separate from the engine's own switch because turning the ENGINE off stops the refusals with it.
  `storefront_destination_pairs` records what a person changed, with `is_open` as a COLUMN so closing a pair the declared list
  opens is recordable; only staff of the SOURCE market may change one; every change is audited through `recordOrFail`.
- **A2-12.** The checkout guard returned early for any address that does not need the structured Saudi form -- which is every
  address in the other five countries, and so exactly the addresses this requirement is about. Now it asks H1-01's rule first, and
  the address list carries `storefront_can_deliver` on the web prefill and on the app's list.
- **H2-12 and C-16.** A paused market made no difference to its marketplace accounts or to its tills.
  `ChannelAccountMarketStop` among the WRITE gates of `ChannelHttp::assertAllowed()` and `PosSaleGate` in all three place-order actions. The
  first version gated every call and the sales-channel group caught what that cost: the dispatch of a marketplace order READS the buyer's
  address from the channel, so gating reads blocked that dispatch -- and whether a stopped market stops it is the owner's own open question.
  A guard's position must not answer a question nobody has asked. It is a write gate now, which is also what the owner already wrote down:
  orders and webhooks must keep being received while a market is paused (GCC-12).
- **H2-07.** noon's country was validated against NOTHING. The markets each channel serves are data now, each entry carrying the
  SOURCE of its list and `verified` false, because no vendor document has been read here.
- **H2-02 (coded).** noon was connectable in the enum and unreachable in the panel: no `SECRET_FIELDS` entry, so the screens
  could not be opened; Trendyol's rules reached it, so the form could not be submitted; the chooser printed Trendyol's words.
- **H2-11.** Every amount reached Odoo at TWO decimals whatever currency the books were kept in. `OdooCurrencyDecimals`
  reconciles this platform's answer with the instance's own `decimal_places` and refuses by name where they cannot be reconciled.
- **C-04.** The ownership snapshot records the market and the carrier account it was placed under; `OwnershipHistory` is the
  written rule for a row that names neither, and nothing backfills, because that emptiness is what tells a pre-market order from
  a Saudi one.
- **H2-18 and C-17**: `INTEGRATION_MAP.md` and `INVENTORY_FLOW_AND_COUNTRY_OVERLAP.md`, plus five documentation lines that said
  the opposite of what the code does.

## What is owed, and by whom

**One test is red and it is not mine to fix.** `tests/Unit/Architecture/SaudiLiteralsAllowListTest` belongs to the CI track, and
two inventory entries are owed to it. The 33 of `test_the_two_money_categories...` does NOT change, because a `market-list` entry
and a count rising from 2 to 3 both leave `$toMove` as it was:

```php
'app/Services/SalesChannels/Support/ChannelSettings.php' => [
    'SA' => ['count' => 3, ...],   // was 2: NOON_COUNTRIES (SA, AE, EG) is the third
],
'config/sales_channels.php' => [
    'SA' => ['count' => 3, 'category' => self::MARKET_LIST, 'owner' => 'H2-07',
             'why' => 'one row of each channel\'s market list (trendyol, noon, amazon), beside the other markets'],
],
```

**Language keys.** `resources/lang` belongs to another track, so nothing was written there and one shipping refusal borrows an
existing key rather than carrying a constant with no text. The full list is in this track's report; the shortest summary is: one
dedicated shipping refusal, twelve words for the noon account screens (which is why one test of them SKIPS and names them), one
POS refusal, and nineteen for the cross-border pair screen.

## What was left, and why

- H1-05's and H1-10's licensed place data, H1-09's tax regime (Q-11), A1-10's promotion owner (GCC-17), A2-07's mobile release,
  C-15 and C-04's in-house marker: owner decisions, named on their cards.
- H2-02's listing read is **blocked by what noon publishes**: it has no operation that enumerates an account's listings, so
  implementing `ReadsListings` could only feed the sync service our own product codes, which would make every code noon has never
  heard of look like a listing that had gone away. Recorded as a decision rather than as a missing class declaration.
- H2-06 (three criteria), H2-10, H2-V2, A1-13, A2-10 and the rest of A2's identity work: not attempted here.
