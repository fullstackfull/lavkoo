# Shared stock, in seconds rather than in sweeps

One physical shelf is sold from by pharmacyksa.com and by the marketplaces at the same time. The arithmetic that keeps that honest was already here (`PoolNet`, the division,
`ReportsChannelHolds`); what was missing was **speed and durability**: a change could wait a quarter of an hour, and a change could be lost altogether.

This is what PR-B adds, and nothing else. It builds no second stock engine, repeats no arithmetic, and takes no decision away from the publisher or the transport.

## The two holes it closes

**A change could be lost.** A change used to mark its pools by writing `inventory_pools.dirty_at` — in a statement of its **own**, after the change had been written. A process
that died between the two left the unit held and the quantity still on offer until the next full sweep. Worse, `DirtyPools::mark()` is a no-op while the division is switched
off, so on such a host the mark never happened at all and the gap was permanent.

**A change could wait fifteen minutes.** Everything was driven by the schedule: the quick run every five minutes, the full publish every fifteen. In between, a unit Amazon had
sold stayed on offer in our own shop and on Trendyol.

## How it works

```
a change (an order, a hold, a return, a provider figure, a setting)
        │
        │  ONE transaction
        ├──────────────► the change itself      (the reservation, the pool's settings, products.current_stock)
        └──────────────► channel_stock_events   ("work this pool out again, because …")
        │
        │  after the COMMIT
        └──────────────► a nudge: DrainStockEvents (queued)        ── and, whatever happens to it, ──
                                                                       channels:stock-events, every minute
        │
   StockEventDrain
        ├── 1. the storefront's own stock, at once, from the figure already stored   (StorefrontStockRefresher)
        └── 2. the pools → DirtyPools → PoolAllocationService → StockPricePublisher::publishReductions
                                                                 (every gate it ever applied, unchanged)
```

### The table is the guarantee; the job is the speed

`channel_stock_events` is written by the same transaction as the change, so either both exist or neither does. Everything after that is best-effort and may fail freely:

* the nudge is **lost** → the minute schedule works the rows;
* the nudge arrives **twice** → the second drain finds nothing due;
* a worker **dies mid-batch** → its rows stay `claimed` and another run takes them back after `claim_timeout_minutes`;
* a batch **throws** → its rows go back with a later `available_at`, and after `attempts` tries they are kept as `dead`, never dropped and never called done.

A row carries **no figure**. That is deliberate: a row worked late sends a *fresh* quantity late, never a stale quantity. It names a pool and says why.

### The order within a pass: the storefront first

The storefront is the shop we control. A unit Amazon has sold must stop being offered on pharmacyksa.com in the same second, not when a marketplace gets round to
acknowledging a push. The channels come second, and **lowest first** (`publishReductions` sends only what *lowers* a quantity): a reduction that is late is an oversell, a rise
that is late is only a lost sale.

### The storefront's own number, without asking anybody

`StorefrontStockRefresher` re-applies `provider_product_mappings.provider_stock` — the figure the last sync stored — with the time it was read, through
`InventorySyncService::applyToAssignedProducts()`, the one writer of `products.current_stock`. So:

* **no provider is called**, and a stale figure is not made to look fresh (a shipment the provider accepted after that figure is still held back, exactly as on a real sync);
* **there is no second copy of the arithmetic.** The writer's locking, its refusals (a whole-product figure on a product sold per variant, a variant with no price, a product no
  longer assigned to that provider) and its stock-movement line all apply unchanged. A second copy is the one thing that would make the two numbers disagree.
* a product the store fulfils itself has no provider figure to re-apply, and its stock stays a person's to set.

### What it does not touch

**Amazon FBA and Noon FBN stock is never part of this.** That stock is the marketplace's own, not a provider's: a location whose `source_of_truth` is `channel` is not named
when a provider figure moves, and a drain that is handed such a pool publishes nothing, because the publisher refuses it exactly as it always did
(`publish_skip_fba_listing`). A test pins both.

**Torod Point and M5AZN dispatch is untouched.** Nothing here changes how an order reaches a provider, what it is told, or how its shipment comes back. What changed inside
Fulfillment is one thing: `InventorySyncService` now *says* when a figure moved (`NotesStockChanged`), and where the sales-channel module is absent the note goes nowhere.

**No gate moved.** The drain hands over pool keys. Whether one quantity leaves this host is still decided by `SALES_CHANNELS_ENABLED`, the account being enabled, its
`inventory_publishing` capability, its recorded approval, `SALES_CHANNELS_EXTERNAL_WRITES_ENABLED`, the account's own write switch, a figure too old to trust, and an order
sweep too old to raise a quantity on.

## The settings

| Setting | Ships as | What it does |
| --- | --- | --- |
| `SALES_CHANNELS_STOCK_OUTBOX_ENABLED` | `true` | Off, the module behaves exactly as it did: the marks and the scheduled runs, with both holes above |
| `SALES_CHANNELS_STOCK_OUTBOX_NUDGE_ENABLED` | `true` | Off, nothing is lost — the minute schedule does the work and only the seconds are lost. Turn it off on a host with no queue worker |
| `SALES_CHANNELS_STOCK_OUTBOX_NUDGE_SECONDS` | `5` | At most one nudge per this many seconds: a drain takes everything due, so four hundred changed orders do not need four hundred jobs |
| `SALES_CHANNELS_STOCK_OUTBOX_BATCH` | `200` | Events per pass |
| `SALES_CHANNELS_STOCK_OUTBOX_ATTEMPTS` | `5` | Tries before an event is kept as dead |
| `SALES_CHANNELS_STOCK_OUTBOX_RETRY_SECONDS` | `60` | Multiplied by the number of tries so far |
| `SALES_CHANNELS_STOCK_OUTBOX_CLAIM_TIMEOUT_MINUTES` | `10` | After this, a dead run's batch is taken back |
| `SALES_CHANNELS_STOCK_OUTBOX_REWRITE_STOREFRONT` | `true` | Off, the storefront waits for the provider's next figure, as it did |
| `SALES_CHANNELS_STOCK_OUTBOX_BEHIND_SECONDS` | `180` | When the hub says the workers are behind |
| `SALES_CHANNELS_STOCK_OUTBOX_DONE_EVENT_DAYS` | `7` | `channels:prune` deletes worked events after this; **dead ones are kept** |

## Running it

```bash
php artisan channels:stock-events --status
```

```bash
php artisan channels:stock-events
```

```bash
php artisan channels:stock-events --retry-dead --status
```

A queue worker gives the seconds; the schedule (`channels:stock-events`, every minute) is the net beneath it, and the whole of it where no worker runs. Neither can
double-send: the publisher compares every quantity with what it last wrote.

## Watching it

The hub's **stock changes not acted on yet** section is the figure that says the workers have stopped: how many changes are waiting, how old the oldest is, and how many
nobody could act on. A few waiting for a moment is the arrangement working. Minutes of backlog, or one dead event, is somebody's to look at — a dead event is a quantity at a
channel that has not been corrected.

## What was already here, and is not rebuilt

| Already built | Where |
| --- | --- |
| One source of truth per (product, variant, location) | `Inventory/PoolNet.php`, `LocationStockReader.php` |
| Atomic reservation under concurrency (a unique key and the pool's row lock) | `Inventory/ReservationService.php`, `Allocation/PoolAllocationService.php` |
| A hold taken off the source figure once, for the storefront and the channels together | `Inventory/ChannelHoldsOnProviderStock.php` |
| The division between several listings of one shelf | `Allocation/StockAllocator.php` |
| Fail-closed on a stale figure or an incomplete order import | `Allocation/OrderFreshness.php`, `StockAvailability::isPublishable()` |
| Confirming that a channel applied an update | `Publishing/StockPricePublisher.php` |

## What is still not done

* **Returns** are recorded as a reason (`units_returned`) and the units come back through the reservations, as they did. A returned unit that is *damaged* and must not go
  back on sale is still a person's decision — nothing here judges the condition of a returned item.
* **No live run.** Every test here is against fixtures, with outbound requests forbidden, on SQLite locally and on MySQL 8 and MariaDB 11.4.2 in CI. The concurrency tests
  (six real PHP processes claiming and draining at once) need a real engine and are skipped on SQLite.
* **A queue worker is the operator's to run.** Nothing here starts one, and the schedule is written so that the arrangement is correct without one.
