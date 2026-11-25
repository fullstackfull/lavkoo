# Track M1 — queued work carries the market

**Worktree** `/home/user/wt/m`, branch `wt/m`, from `30a2283e`. Six commits, not pushed; the coordinator merges.
**Database** `pharmacy_testing_m` on MariaDB 10.11.14, and the default SQLite suite for every file as well.

| commit | what |
|---|---|
| `1908462c` | `MarketOfTheWork`, the six jobs and the restock listener that now read it, one new config key, the switch-off test |
| `47869d61` | the switch-on test: the record beats the request, and the event hop proved by a decoy id |
| `f949de85` | the enforced list of where every queued class gets its market, and the defect it found |
| `7631d88d` | `gcc:storefront-queues` says which of the two pauses a market is under |
| `b3fd260f` | the new test directory runs on MySQL 8 and MariaDB (**one line outside this track's paths** — see Boundaries) |
| `d6d381b1` | the stored-event fixture names the columns SQLite enforces and a non-strict MariaDB let through |

---

## 1. The count in the evidence was wrong in two different directions

All three criteria were recorded against one measurement: *"None of 27 ShouldQueue files mentions a storefront."*
Verified as asked, and both halves of it are false now and one half was already false:

- **27 is a count of FILES that name `ShouldQueue`** (10 under `app/Jobs`, 17 under `app/Listeners`). Only **26
  classes implement it**. The 27th is a defect — see §5.
- **16 of the 27 already carried or honoured a storefront before this track started.** `CarriesItsStorefront`,
  `RunsInTheDispatchingStorefront`, `ServeInItsStorefront`, `StorefrontQueues` and `OrderNotificationContext` were
  all built, and `tests/Feature/Storefront/QueuedWorkCarriesItsStorefrontTest` (11 tests) already pinned the
  mechanism. The tracker lagged the code, which is the recorded hazard of this project.
- **11 of the 27 carried nothing.** Of those, four belong to no market by design and must NOT carry one.

So the right answer was never a count. It is a classification, and this track built the one that is enforced.

## 2. What was actually missing, and what was done about it

Six classes named a record and never read it. The dispatching request is the right source for a customer's or an
operator's own work, and the wrong source — or no source at all — for these:

| class | why the dispatcher could not answer | now reads |
|---|---|---|
| `app/Jobs/Fulfillment/ProcessProviderEvent.php` | queued from `api/fulfillment-webhooks`, a cross-country path, so nothing is ever bound | event → shipment → order → market |
| `app/Jobs/Fulfillment/DispatchProviderFulfillment.php` | queued by the fulfilment machinery, the recovery sweep (a console run over every market) or a panel (every country) | shipment → order → market |
| `app/Jobs/Fulfillment/ReconcileProviderFulfillment.php` | as above | shipment → order → market |
| `app/Jobs/Fulfillment/CancelProviderFulfillment.php` | as above | shipment → order → market |
| `app/Jobs/Fulfillment/RouteOrderFulfillment.php` | queued by the checkout hook, a panel status change or the sweep | order → market |
| `app/Listeners/RestockProductNotificationListener.php` | stock returning is noticed by `ProductService` on a seller or admin request, bound to no market | product → market |

Two more now carry the dispatching request's market, which is all there is to carry:
`app/Jobs/DeliverSellerWebhook.php` (its payload was built at dispatch, and a seller is not country-owned) and
`app/Jobs/SendEmailJob.php` (a mailable renders in the worker; nothing dispatches this class — see §5).

New: **`app/Jobs/Concerns/MarketOfTheWork.php`**. It reuses `OrderStorefrontSnapshot::COLUMN` /`columnReady()`,
`StorefrontCatalog::columnReady()`, `FulfillmentSchema::ready()` and `StorefrontResolver`, and invents no second
mechanism: the answer is handed to the existing `CarriesItsStorefront::storefrontToServe()` hook, which the trait's
own docblock already described as the way for a job that knows its market from its record.

Three decisions in it worth keeping:

- **Every read goes through the query builder, never a model.** `App\Models\Order` and `App\Models\ProviderFulfillment`
  both carry `StaffCountryOrderScope`; a restricted employee reading through the model would find no row and the
  market would fall back silently. That is the recorded `StorefrontCurrency::withinOrder` trap.
- **Nothing is memoised.** A static schema answer outlives the schema on a real server, and the flush list lives in
  `tests/TestCase.php`, which this track does not own. A job runs once; two extra reads is the right side of that trade.
- **The fallback is the dispatching request's market, and never the default market.** An order that records none is
  left exactly where it was. Assuming the default would report a legacy order's money as a market's own.

`app/Jobs/Fulfillment/OneAtATimePerFulfillment.php` now hands back the one lock (`lockOnThisShipment()`) instead of a
`middleware()` list, because **two traits cannot both answer `middleware()` — PHP refuses that at class load, which is
a fatal error and not a failing test**, the exact family of fault that cost ~20,000 tests on 2026-11-25. Each of the
three shipment jobs composes its own list with the lock FIRST, where `CancellationTest` reads it (18 green).

## 3. Saudi behaviour, and the switch

One new key, added to `config/gcc.php`, inside the existing `storefronts.queues` block and touching no existing key:

```php
'market_from_record' => filter_var(env('GCC_STOREFRONT_QUEUE_MARKET_FROM_RECORD', false), FILTER_VALIDATE_BOOLEAN),
```

It is off, and dead while `gcc.storefronts.enabled` is off. **Both gates are proved apart**, because a capability
that is only off because the other switch is off is one flag away from shipping live by accident. The first test of
the unit is the off test, and with the switch off every one of the eight classes above binds no market, records none
in its payload and waits on the queue it waits on today.

**No migration was needed and none was written.** Every column the market is read from already has its own real
migration (`2026_11_26_100000_add_storefront_snapshot_to_orders_table`,
`2026_11_25_100000_add_storefront_country_to_products`, `2026_11_05_100000_create_provider_fulfillment_tables`), and
the tests run those migrations rather than building the columns by hand — so the column a test reads is `char(2)
ascii_bin` on a real engine, which is what makes a byte-for-byte market comparison mean anything. The `2027_01_05_*`
slot this track was given is unused.

## 4. Tests

| file | tests | assertions |
|---|---|---|
| `tests/Feature/Queue/QueuedWorkIsUnchangedWhileTheSwitchIsOffTest.php` | 5 | 46 |
| `tests/Feature/Queue/QueuedWorkRunsInTheMarketOfItsRecordTest.php` | 13 | 32 |
| `tests/Feature/Queue/EveryQueuedClassDeclaresItsMarketTest.php` | 6 | 90 |
| `tests/Feature/Queue/MarketPauseAndQueuePauseAreReportedApartTest.php` | 4 | 13 |
| **`tests/Feature/Queue` total** | **28** | **181** |

Green on MariaDB 10.11.14 (`phpunit.mysql.xml`, `pharmacy_testing_m`) and on the default SQLite suite.
`tests/Feature/Queue/QueuedMarketTestCase.php` is the shared world. The four tests of the pause report were also each
run alone, because one of them read as order-dependent during development (it was not — see §7).

Regressions re-run on MariaDB, all green and unchanged:
`tests/Feature/Storefront/QueuedWorkCarriesItsStorefrontTest` + `StorefrontQueuesTest` (20),
`tests/Feature/Fulfillment/CancellationTest` (18), `tests/Feature/StaffScope/StaffJobInventoryTest` (9),
`tests/Feature/Notifications/PushTopicNamesTest` (12), `tests/Unit/Architecture` (146, 60 skipped), `tests/Unit/Ci` (60).

### Teeth checks

Every break was applied to the production code, run alone, and restored. Fourteen in all.

| # | what was broken | result |
|---|---|---|
| 1 | `MarketOfTheWork::isEnabled()` made to ignore both switches | **4 of 5 off-tests fail** — "the route job binds no market while the switch is off", "Failed asserting that 'KW' is null" |
| 2 | `DispatchProviderFulfillment::storefrontToServe()` stops asking its record | **4 fail** — "a Kuwaiti shipment is worked as Kuwait even when a Saudi screen pushed it" |
| 3 | `RouteOrderFulfillment` stops asking the order | **1 fails** — "Failed asserting that null is identical to 'KW'" |
| 4 | `ProcessProviderEvent` stops following its shipment to the order | **1 fails** |
| 5 | `MarketOfTheWork::ofFulfillmentEvent()` reads the event id as a shipment id | **INERT at first — see below**; **1 fails** after the test was sharpened |
| 6 | the restock listener stops asking the product | **1 fails** |
| 7 | the `?? $this->storefrontCountry` fallback dropped | **1 fails** |
| 8 | the two-letter market shape check dropped | **1 fails** — "a record holding 'x' names no market" |
| 9 | the `orders.storefront_country` column guard dropped | **1 errors** — `Unknown column 'storefront_country' in 'SELECT'` |
| 10 | `StorefrontQueues::forMarket()` gives no market a queue | **1 fails** — "Failed asserting that null is identical to 'storefront-kw'" |
| 11 | a new undeclared queued class added to `app/Jobs` | **2 fail** — "a queued class has to say where its market comes from" |
| 12 | `CustomerRegistrationListener` stops using `RunsInTheDispatchingStorefront` | **1 fails** — a class claiming a market binds none |
| 13 | `RebuildProductSearchIndex` given `CarriesItsStorefront` | **1 fails the other way** — a class claiming no market binds one |
| 14 | `OrderPlacedListener`'s `OrderNotificationContext::within(` renamed | **1 fails** |
| 15 | `gcc:storefront-queues` stops reading each market's lifecycle | **2 fail, 2 error** |
| 16 | the printed table stops naming the mode a market is stopped in | **1 fails** |

**One tooth came back inert, and it was sharpened rather than accepted (teeth check 5).** Reading an event id as a
shipment id PASSED, because the first event and the first shipment are both id 1 on a fresh table and the confusion
found the right market by coincidence — the same shape as the recorded `whereKey()` defect that passed for the wrong
reason. `storedEventOf()` now takes an optional explicit id, and the test gives the Kuwaiti event the id of the
**Emirati** shipment, so that break answers `AE` instead of `KW`. It fails now, and the reason is written into the
fixture so the next person does not undo it.

**One process error worth passing on.** The teeth harness restored with `git checkout -- <file>`, which discarded the
*uncommitted feature* along with the deliberate break, and the test then "failed" for a reason that had nothing to do
with the break. It cost a false diagnosis of an order-dependent test. **Commit the feature before teeth-checking it**,
or restore from a copy. Teeth checks 15 and 16 were re-run against a committed tree; 1–14 were already on one.

## 5. Defects found — recorded, not fixed

**`app/Listeners/RefundListener.php:11` — the refund notification is not queued at all.** The class imports
`Illuminate\Contracts\Queue\ShouldQueue` and `Illuminate\Queue\InteractsWithQueue`, uses neither, and does not
implement `ShouldQueue`. So telling a customer or a seller about a refund sends a push *inside* the request that
recorded the refund — the exact cost every neighbouring mail listener was queued to avoid (measured at ~60 seconds
per recipient against an unreachable host). Worse, `app/Listeners/CustomerStatusUpdateListener.php:19` justifies
itself with *"Queuing follows RefundListener, which this project already runs on the queue"*, which is not true of
this tree. Not fixed: queueing it changes **when** a refund notification is sent, which is a runtime behaviour change
outside the market question. It is pinned by
`EveryQueuedClassDeclaresItsMarketTest::test_the_refund_notification_names_the_queue_contract_without_implementing_it`,
which flips when somebody fixes it and then asks for the declaration to be re-read.

**`tests/Support/StaffJobInventory.php:46-62` stated something false and nothing failed.** Five entries said the
fulfilment jobs "already carr[y] the storefront of the request that queued it (A1-14, CarriesItsStorefront)" while
not one of them did. It is true as of `1908462c`. The general lesson is why
`EveryQueuedClassDeclaresItsMarketTest` *builds each class and asks it*, instead of describing it: a prose inventory
can only be wrong quietly. That file is outside this track's paths and was not edited.

**`app/Jobs/SendEmailJob.php` is unreachable.** Nothing in the repository dispatches it; the queued listeners send
through `EmailTemplateTrait::sendingMail()`. It was wired to carry its market rather than deleted, because deleting it
changes the 27 the three criteria are measured against and `StaffJobInventory` names it. Somebody should decide
whether it goes.

**`config/database.php` sets `strict => false`, and that hid a broken fixture on MariaDB.** Three NOT NULL columns of
`provider_fulfillment_events` were left out of an insert; MariaDB supplied its own empty strings and stored the row,
SQLite refused it (4 errors). The recorded lesson in `EXECUTION_STATE.md` is that SQLite is the lenient engine. It is
not lenient about NOT NULL, and a non-strict MySQL session is, so **a fixture proved on one engine is proved on
neither**. Fixed in `d6d381b1`; the asymmetry is worth adding to the lessons.

## 6. The tracker lines, and the state each should go to

This track does not edit `docs/gcc/tracker/*.md`. **Nothing should be moved to MET on this report** — none of it has
run in CI yet, and the standing rule is that a track never moves a card to MET on its own evidence. Each line's
`[MISSING]` should become `[CODED]`, and each line's parenthesised evidence is now false and has to be replaced.

### `docs/gcc/tracker/axis-m-security-tests-and-deployment.md:504` — unit **M2-02** (line 486)

Now: `- [MISSING] Orders, wallets, loyalty, queued mail, push and console runs carry or honour the storefront (None of 27 ShouldQueue files mentions a storefront; no order column in any PR)`

→ **`[CODED]`**. Both evidence clauses are false: the order column exists
(`2026_11_26_100000_add_storefront_snapshot_to_orders_table`) and 24 of the 26 really-queued classes now carry or
honour a storefront, the other two being declared platform-wide with reasons. The **queued mail, push and console**
third of the criterion is covered and enforced by `tests/Feature/Queue` (28 tests). It stays CODED and not MET because
its own wording also names **orders, wallets and loyalty**, which are axes D and E and were not read by this track —
that is the "a criterion's own wording is only partly covered" reason, not dormancy.

### `axis-m-...:768` — unit **M2-13** (line 751)

Now: `- [MISSING] A queued e-mail, SMS, WhatsApp or push message uses its storefront's sender, language, currency and audience; push topics are per market (None of 27 queued files mentions a storefront)`

→ **`[CODED]`**, and this is the one closest to MET. Every named half exists and the queued delivery that was the
missing link now carries the market: sender (`OrderNotificationContext::asTheCompanyThatSold`, `MailAccount`,
`NotificationProfiles`), language (`MessageLanguage`, `MarketLanguage`), currency (`StorefrontCurrency::within`),
audience (`BroadcastMarkets`, `NewsletterMarkets`, `CartMarketingMarkets`), push topics per market
(`StorefrontPushTopics`). Existing evidence: `tests/Feature/Notifications/` (`MarketMailAccountTest`,
`MarketMessagingAccountTest`, `MarketPushAccountTest`, `PushMarketTest`, `PushBodyLanguageTest`,
`PushTopicNamesTest`, `BroadcastMarketTest`). New evidence: `tests/Feature/Queue` (28). **What a reader should check
before proposing MET:** no test in this track drives an SMS or WhatsApp send *from a queued class* end to end, and the
Zender gateway still logs the recipient's phone number and its own answer (the owner's recorded call).

### `axis-m-...:888` — unit **M2-18** (line 871)

Now: `- [MISSING] Queued jobs, mail, push, scheduled commands and search reindex carry or honour the market; a market can be paused (None of 27 queued files mentions a storefront; ScheduleDefinition unchanged by the drafts)`

→ **`[CODED]`**. Of the five things it names:

- **queued jobs, mail, push** — done and enforced (`tests/Feature/Queue`, 28).
- **search reindex** — answered, in the lawful negative: `RebuildProductSearchIndex` and
  `SalesChannels\DrainStockEvents` are declared `NO_MARKET_OF_ITS_OWN` with reasons, and the declaration is enforced
  in both directions, so giving the rebuild the dispatcher's market fails a test (teeth 13).
- **a market can be paused** — three different pauses exist (selling: `StorefrontLifecycle::pause`; marketing:
  `StorefrontMarketingStop`; queue: `StorefrontQueues` + Laravel's `queue:pause`), and
  `gcc:storefront-queues` now prints the first and the third side by side and says which is which, because neither
  implies the other and each looks exactly like the other from outside.
- **scheduled commands** — **NOT closed. See §7.**

## 7. What this track did NOT close, and why

1. **`ScheduleDefinition` has no market classification, and that is the open half of M-888.** 45 scheduled entries,
   none of them classified. What *is* now true: a scheduled run is a console process bound to no market, and the
   market-owned work it queues each carries its own market, so `fulfillment:recover` sweeping every market dispatches
   shipment jobs that are each worked in their own
   (`QueuedWorkRunsInTheMarketOfItsRecordTest::test_two_shipments_of_two_markets_in_one_worker_do_not_share_a_market`);
   and `cart:remind-abandoned` already skips a market that may not sell, through
   `AbandonedCartService::withoutStoppedStorefronts()` and `StorefrontMarketingStop::stoppedCartGroups()`, pinned by
   `tests/Feature/Storefront/MarketingStopAbandonedCartTest`. What is missing is the same enforced list for the
   schedule that `EveryQueuedClassDeclaresItsMarketTest` is for the queue. **Deliberately not written here**, because
   classifying `theme:publish-due` (axis F), `commerce:campaigns-tick` (axis I), `myfatoorah:reconcile` (axis D) and
   `monitoring:evaluate` means asserting things about four axes this track did not read, and a declaration written
   from a reading of what the code *meant* is a hypothesis dressed as a record. The shape to copy is in
   `tests/Feature/Queue/EveryQueuedClassDeclaresItsMarketTest.php`; the enumeration helper already exists at
   `app/Services/Monitoring/Collectors/SchedulerCollector::scheduledEvents()`.
2. **No test runs a real worker against a market's own queue.** `tests/Feature/Security/SharedStoreAndRealWorkerTest`
   drives `queue:work --once` on the database queue for the *listener* path; nothing does it for
   `--queue=storefront-kw`, and nothing proves that pausing that queue leaves its jobs waiting rather than lost. Both
   are one process, so nothing here says anything about two workers.
3. **`CustomerStatusUpdateListener` and `MaintenanceModeNotificationListener` bind no market, on purpose, and that is
   a recorded judgement rather than a proof.** An administrator blocking a customer is not acting in the customer's
   market, and a customer is one identity across every country (GCC-13), so their account holds no market to read. If
   the owner decides a blocked customer should be written to in the market of their last order, that is a new read of
   a new record and a new decision.
4. **`DeliverSellerWebhook` carries the dispatching market and in practice that is always null** — the seller API is a
   cross-country path and the retry sweep is a console run. It is honest and it is not useful. It becomes useful only
   when somebody decides whether a seller webhook belongs to a market (G-12's company dimension).
5. **Nothing in `app/Services/Notification/**` was changed.** `MessageLanguage` and the per-market account services
   were read and reused as they stand; the gap was never there.
6. **The `2027_01_05_*` migration slot is unused.** No schema change was needed.

## 8. Boundaries

Paths written, all within this track's own: `app/Jobs/**` (9 files), `app/Listeners/**` (1),
`app/Console/Commands/StorefrontQueuesCommand.php`, `tests/Feature/Queue/**` (5 new files), and one added key in
`config/gcc.php`.

**One exception, flagged deliberately: `.github/workflows/ci.yml`, one line** (`b3fd260f`).
`CiEveryTestDirectoryRunsOnARealEngineTest` was RED on this branch until `tests/Feature/Queue` was named in a
real-engine group, and that guard exists precisely to force this step. `tests/Feature/Queue` was added to the
"Notification and localization tests" group; no other job, path or gate was touched. **The coordinator should check
that one line for a conflict** — the group list is a single YAML key, and the comment at "Marketplace and ledger
tests" records a previous merge silently losing one side of exactly such a key.

Not touched, as instructed: `docs/gcc/tracker/*.md`, `app/Services/Checkout/**`, `app/Services/SalesChannels/**`,
`resources/lang/**`, every existing key of `config/gcc.php`, and `tests/Support/StaffJobInventory.php` and
`tests/Feature/Storefront/StorefrontQueuesTest.php` even where this work gave reason to (the latter pins the exact
shape of a market row, which is why the lifecycle states are a dimension of the report rather than a column of it).
