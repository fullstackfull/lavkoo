# A1-14: queued work carries its market, never leaks it, and can be worked or paused per market

**Status:** on `gcc/development`. No migration. Nothing is recorded and no queue payload changes while `GCC_STOREFRONTS_ENABLED` is off, and
the per-market queues are a second switch that ships off as well. Saudi behaviour is untouched in both states.

Decision behind it: **queue workers are market-scoped** (`docs/gcc/DECISIONS.md`), requirement **A1-14** — "a queued job, a scheduled task or a
console command either runs deliberately across every country or in the storefront it names, and no job inherits the storefront of the job or
request before it".

## The defect

A queue worker is a console process. `StorefrontCatalog` deliberately narrows nothing for one (`contextOfUnboundRequest()` answers null while
`runningInConsole()`), and nothing binds a `StorefrontContext` in a worker, so **a job queued by a Kuwaiti request was run as no market at
all**: the platform's own currency, company name, logo and settings. The mail about a Kuwaiti customer's wallet top-up was written in the
platform's money; so were registration, verification and password-reset mails.

The leak runs the other way too. A worker runs one job after another in **one process**, so a job that did bind a storefront — as the order
notifications correctly do — would leave it bound for the job after it, and the next market's mail would be written as the previous market's.
`StorefrontContext::serve()` already existed to prevent exactly that, and queued work was the one caller that never used it.

Reading the market inside `handle()` cannot fix either half: by then the dispatching request is gone.

## What carries it

| | Job (`App\Jobs\Concerns\CarriesItsStorefront`) | Queued listener (`App\Listeners\Concerns\RunsInTheDispatchingStorefront`) |
|---|---|---|
| Captured | in the constructor, which runs in the dispatching request | in `middleware()`, which the dispatcher calls on the listener while the request is still being served |
| Travels | in the job's own serialised payload (`storefrontCountry`), so it survives the queue, a retry and a worker restart | inside the `ServeInItsStorefront` middleware object the dispatcher serialises into the `CallQueuedListener` |
| Why not the other way | — | Laravel builds a queued listener with `newInstanceWithoutConstructor()` and serialises only the **event**: a property set in a constructor would neither be set nor arrive |

Both record a **two-letter country code**, never a context object: the worker rebuilds the context from the settings in force when it runs,
instead of restoring a copy of the settings of the moment the job was queued. `App\Jobs\Middleware\ServeInItsStorefront` then runs `handle()`
inside `StorefrontContext::serve()`, which puts back whatever the process held before — after a failure as well.

`ServeInItsStorefront::countryOfTheDispatchingProcess()` is the only reader of "which market is this process serving", and it answers null
while storefronts are off, so no payload changes until the owner switches them on.

## Where it is applied

| Applied to | Why |
|---|---|
| `RunSellerBulkJob` | a bulk price change is entered in the money of the panel the seller used |
| `AddFundToWalletListener` | the mail states an amount the customer topped up on one market |
| `CustomerRegistrationListener`, `EmailVerificationListener`, `PasswordResetListener`, `DigitalProductOtpVerificationListener`, `RequestProductRestockListener` | the event is raised by the customer's own request on one storefront |
| `DeliverymanPasswordResetListener`, `VendorRegistrationListener` | likewise, for a delivery man and a vendor |

| Deliberately **not** applied to | Why |
|---|---|
| `CustomerStatusUpdateListener`, `MaintenanceModeNotificationListener` | the raising request is somebody else's than the recipient's: an administrator's market is not the customer's, and a maintenance notice goes to everybody |
| `RestockProductNotificationListener` | raised by a seller's restock, read by customers of whichever markets asked |
| `DrainStockEvents`, `RebuildProductSearchIndex`, `fulfillment:*`, `channels:*` | work that sweeps many markets at once belongs to no one market; binding the dispatcher's would narrow what it exists to sweep |
| `OrderPlacedListener`, `OrderStatusListener`, `OrderEdit*`, `RefundListener`, `DigitalProductDownloadListener` | already derive the market from the order's own `storefront_country` (`OrderNotificationContext`, I1-06). The record is the truth; the request that queued the job is a guess |
| `DeliverSellerWebhook`, `Fulfillment/*` jobs | each carries a record id and should **derive** from it (`storefrontToServe()`), which is the next slice; the fulfilment provider connection already scopes its own catalogue country (B-05) |

A job that works many markets must not use the trait, and a job that knows its market from the record it carries should override
`storefrontToServe()` rather than trust the dispatcher's market.

## Worked or paused per market

`gcc.storefronts.queues.per_market` (env `GCC_STOREFRONT_QUEUES`, default `false`). While it is off, every job and queued listener waits on the
queue it waits on today. While it is on, work that carries a market **other than the default storefront's** waits on that market's own queue —
`storefront-kw`, `storefront-ae`, … (`App\Services\Storefront\StorefrontQueues`, prefix `GCC_STOREFRONT_QUEUE_PREFIX`).

That hands the operational half to the framework instead of building anything:

```bash
php artisan gcc:storefront-queues
```

```bash
php artisan queue:work database --queue=storefront-kw
```

```bash
php artisan queue:pause storefront-kw
```

```bash
php artisan queue:resume storefront-kw
```

`queue:pause` is Laravel's own: it keeps a cache flag the worker reads before reserving, so a paused market loses nothing, retries nothing and
spends no attempt. `gcc:storefront-queues` only reads — it prints each market's queue, whether it is paused, how many jobs are waiting and have
failed on it (with the database driver), and the exact command to work it. It survives a database it cannot reach, because the names and the
paused state are the point and need no database.

**The default storefront keeps today's queue.** That is the rule the cache keys already follow (`StorefrontContext::scopedCacheKey`), and here it
is also what makes the switch safe to turn on: a worker that is already running goes on working the live market's jobs whatever its `--queue`
list says. The worst a half-finished rollout can do is leave another market's jobs **waiting** for the worker that market needs — they are not
lost, and switching the flag off again sends the next of them back to today's queue.

Before turning it on: start a worker for each market `gcc:storefront-queues` lists, then check that command again and see the waiting count fall.

## What is proved

`tests/Feature/Storefront/QueuedWorkCarriesItsStorefrontTest.php` (10):

- a job is run in the storefront it was dispatched from, after being rebuilt from its serialised payload;
- the country travels in the payload, so a retry and a worker restart read the same one;
- **two jobs of two countries and a console job, run one after another in one process, see KW, AE and nothing — and nothing is bound afterwards**;
- a job dispatched outside any storefront is run unbound;
- a process that was itself serving a storefront gets it back;
- a payload naming no country, an empty one or something that is not a country binds nothing;
- the bulk job carries the seller's market, and none when it is started from the console;
- a queued listener is run in the market the event was raised in, and unbound for a console event;
- nothing is recorded at all while storefronts are off.

Three of those fail if `ServeInItsStorefront` is removed from the middleware list.

`tests/Feature/Storefront/StorefrontQueuesTest.php` (10): the queue of another market, the default storefront keeping today's queue, both
switches off, a listener's `viaQueue`, work that carries no market, every configured market's queue, and the report naming each queue, saying
which one is paused and printing the commands to work and to pause it.

## Still open in A1-14

- The jobs that should **derive** their market from the record they carry (`DeliverSellerWebhook`, the five fulfilment jobs) still run unbound.
- No **scheduled task** runs per market: `ScheduleDefinition` has one entry per task for the whole platform. The queue half of the criterion is
  met; a per-market schedule needs the commands themselves to take a country first.
