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
        │  ONE transaction — all three, or none of them
        ├──────────────► the change itself          (the reservation; the pool's settings; the provider's figure)
        ├──────────────► products.current_stock     (OUR OWN shop's number, recomputed: figure − promises)
        └──────────────► channel_stock_events       ("ask the channels about this pool again, because …")
        │
        │  after the COMMIT
        └──────────────► a nudge: DrainStockEvents (queued)        ── and, whatever happens to it, ──
                                                                       channels:stock-events, every minute
        │
   StockEventDrain                                   ← the CHANNELS' half only
        ├── the storefront's number, for the changes a transaction could not cover
        └── its OWN pool keys → PoolAllocationService → StockPricePublisher::publishReductions
                                                       (every gate it ever applied, unchanged)
```

## What is immediate and what is not

Being exact about this matters more than the headline:

| | When |
| --- | --- |
| **`products.current_stock`, on a channel hold or release** | **In the hold's own transaction.** No queue, no worker, no schedule: by the time the ingest returns, our own shop has stopped offering the units |
| **`products.current_stock`, on a provider figure** | In the sync's own transaction, as it always was |
| **The quantity at Amazon / Trendyol** | At the next drain: **seconds** where a queue worker runs, **up to a minute** where none does (the schedule). Never "the same second" — a marketplace has to be asked, and asking is what a worker is for |
| **The channel having APPLIED it** | Later still, and not this table's business: it is the listing's own `publish_state` (`queued` → `applied`), because a submission a channel merely took is not an applied quantity |

On a host whose queue connection is `sync` there is **no worker at all**, and nothing is nudged there on purpose (see below) — so the channels are told by the minute schedule. Our own shop's number is unaffected by any of that.

### The table is the guarantee; the job is the speed

`channel_stock_events` is written by the same transaction as the change, so either both exist or neither does. Everything after that is best-effort and may fail freely:

* the nudge is **lost** → the minute schedule works the rows;
* the nudge arrives **twice** → the second drain finds nothing due;
* a worker **dies mid-batch** → its rows stay `claimed` and another run takes them back after `claim_timeout_minutes`;
* a batch **throws** → its rows go back with a later `available_at`, and after `attempts` tries they are kept as `dead`, never dropped and never called done.

A row carries **no figure**. That is deliberate: a row worked late sends a *fresh* quantity late, never a stale quantity. It names a pool and says why.

### The four facts, kept apart

Conflating any two of these is how a quantity ends up wrong while every screen says it is fine, so the row keeps them separately:

| The fact | Where it is recorded |
| --- | --- |
| **1. Recorded** — the change happened and the channels must be asked again | the row exists; `created_at`, written in the change's own transaction |
| **2. Worked out** — the pool was really divided again, and our own number written | `recomputed_at`, `storefront_written_at` |
| **3. Asked to be sent** — the publisher was really asked, and what it answered | `outcome` (`nothing_to_do` / `recomputed_only` / `sent` / `not_sent`), `publish_result`, `publish_detail` |
| **4. Confirmed by the channel** | **not here.** The listing's own `publish_state`: `queued` until the channel confirms, then `applied` |

`state = worked` therefore means *a pass attended to this row* — never *a channel accepted a quantity*. And a row is not closed at all unless something was settled:

| The publisher answered | The row |
| --- | --- |
| `ok` | `worked`, outcome `sent` — the publisher ran. What each listing's quantity came to is the listing's business |
| nobody to ask (no listing offers the pool) | `worked`, outcome `recomputed_only` — the figures are right and there was nobody to tell |
| `skipped` (a switch, an approval, before anything left this host) | `worked`, outcome **`not_sent`**, with the reason — a settled answer, and **counted on the hub**, because an empty queue must not read as "everything is at the channels" |
| `busy` / `rate_limited` / `failed` | **stays pending**, with `publisher_<result>` on it, and is tried again. Closing it would be closing a row over a quantity that never left |

And one more thing settles nothing, the most serious of them: **a storefront write the single writer refused**. `storefront_written_at` is set only when the writer really
wrote it (or had nothing to write), never when it was merely asked — the count of products *attempted* is not the count written. A refusal keeps the row open with
`storefront_write_refused` on it.

That refusal is usually **permanent**: a whole-product figure on a product the storefront sells per variant, a variant that no longer exists. No retry can fix either — a
person has to correct the mapping or the product. So it is tried the owner's number of times and then kept as `dead` with that code, and the hub names it first, above
everything else in the section, because it is the one that means **a unit is on sale in our own shop that is gone**. Once the mapping is fixed:

```bash
php artisan channels:stock-events --retry-dead
```

Bounded, visible, and never retried for ever — which is the difference between a wrong number somebody will correct and a wrong number nobody knows about.

### The order within a pass: lowest first

The channels are told **lowest first** (`publishReductions` sends only what *lowers* a quantity): a reduction that is late is an oversell, a rise that is late is only a lost
sale. Our own shop needs no ordering at all, because its number was already written by the change itself.

### With the division switched off

`DirtyPools::mark()` does nothing while `SALES_CHANNELS_STOCK_ALLOCATION_ENABLED` is off. A worker that read the **marks** would therefore find no pools, publish nothing,
and close its rows as though the work had been done — a false "done" over a wrong quantity. So the worker hands over **its own** pool keys (`DirtyPoolRun::runFor`), which it
knows because the rows say so. The arrangement is right with the division on or off, and two tests pin it.

### The storefront's own number, without asking anybody

`StorefrontStockRefresher` delegates to **`InventorySyncService::rewriteStoreStockFromStoredFigures()`** — the one writer of `products.current_stock`, by the one method made
for re-applying a figure already stored. It takes each mapping's `provider_stock` with the age that figure really has, and **never stamps it**. So:

* **no provider is called**, and a stale figure is not made to look fresh (a shipment the provider accepted after that figure is still held back, exactly as on a real sync).
  That property is the writer's **by construction** — there is no flag a caller has to remember to pass — and a test pins it;
* **there is no second copy of the arithmetic, and no second path.** The writer's locking, its refusals (a whole-product figure on a product sold per variant, a variant with
  no price, a product no longer assigned to that provider) and its stock-movement line all apply unchanged. A second copy is the one thing that would make the two numbers
  disagree.
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
| `SALES_CHANNELS_STOCK_OUTBOX_REWRITE_STOREFRONT_INLINE` | `true` | Our own number written in the hold's own transaction. Off, the row is left for the worker instead |
| `SALES_CHANNELS_STOCK_OUTBOX_REWRITE_STOREFRONT` | `true` | The worker's own pass at it, for changes a transaction could not cover. Off as well, the storefront waits for the provider's next figure, as it did |
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

## The queue worker, for production

Nothing here starts a worker, and the arrangement is **correct without one** — only slower. Decide which you want, and the table below says what each costs.

| | Our own shop's number | The channels |
| --- | --- | --- |
| **No worker, schedule only** | immediate (it was never the worker's job) | up to a minute |
| **A worker running** | immediate | seconds |

### What to run

One worker is enough; the drain is one-at-a-time by its own lock, so a second adds nothing. The queue is the default one, because the job is tiny and must not wait behind
a long job: give it a queue of its own if the default is busy.

```bash
php artisan queue:work --queue=default --sleep=1 --tries=1 --max-time=3600 --max-jobs=500
```

* `--tries=1` matters: a job never retries itself. Whether an event may be worked again is the event's own business (its attempts, its next time, the owner's limit), and a
  blind re-run of a job whose publish call timed out would be a blind re-send to a marketplace.
* `--max-time`/`--max-jobs` let the process be recycled; nothing is lost when it is, because the rows are in the table.
* The worker needs **no new permission and no new credential**: it calls the same publisher the scheduled run calls, under the same switches.

Keep it alive with whatever the server already uses. With Supervisor:

```ini
[program:pharmacyksa-queue]
command=php /path/to/pharmacyksa/artisan queue:work --queue=default --sleep=1 --tries=1 --max-time=3600
directory=/path/to/pharmacyksa
autostart=true
autorestart=true
user=www-data
numprocs=1
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/path/to/pharmacyksa/storage/logs/queue.log
```

`stopwaitsecs` is generous on purpose: a drain mid-batch should be allowed to finish rather than be killed (though being killed is safe — its rows are reclaimed after
`claim_timeout_minutes`).

### The host must also run the scheduler

`channels:stock-events` runs every minute from the application's schedule, so the one cron entry the application already needs is the only cron entry this adds:

```bash
* * * * * cd /path/to/pharmacyksa && php artisan schedule:run >> /dev/null 2>&1
```

Without it, and without a worker, events accumulate and the hub says so (below).

### Checking it is working

```bash
php artisan channels:stock-events --status
```

`pending` near zero and `oldest_pending_seconds` in the low tens is the arrangement working. Minutes of backlog means neither a worker nor the scheduler is running.

## Rolling back safely

Every step is a switch; none needs a deployment, and **none of them touches the database's contents**. In increasing order of how much they give up:

| Step | Set | What you get back | What you lose |
| --- | --- | --- | --- |
| 1. Stop asking for workers | `SALES_CHANNELS_STOCK_OUTBOX_NUDGE_ENABLED=false` | the queue is left alone entirely | the channels hear at the next minute instead of in seconds. **Nothing else changes, and nothing is lost** |
| 2. Stop writing our own number from a hold | `SALES_CHANNELS_STOCK_OUTBOX_REWRITE_STOREFRONT_INLINE=false` | holds no longer touch `products` at all | our own shop's number waits for the worker (and then for the provider's next figure if step 3 is taken too) |
| 3. Stop writing it from the worker as well | `SALES_CHANNELS_STOCK_OUTBOX_REWRITE_STOREFRONT=false` | `products.current_stock` is written only by the provider sync, exactly as before PR-B | between two provider figures the storefront can offer units a channel has sold — the behaviour this work was done to end |
| 4. Stop the whole thing | `SALES_CHANNELS_STOCK_OUTBOX_ENABLED=false` | the module behaves precisely as it did before this work: the marks and the scheduled runs | both holes come back: a change lost when a process dies, and a change waiting a quarter of an hour |

After a rollback, run this once so nothing is left half-worked (it is safe at any time, and does nothing when the outbox is off):

```bash
php artisan channels:publish --only=allocate,stock
```

**The table stays.** Rolling a switch back never drops `channel_stock_events`: its rows are a record of what changed and are worth keeping even unworked, and
`channels:prune` clears the worked ones on its own. Dropping the table is a migration (`down()`), and it is the last thing to do, not the first.

**What a rollback cannot undo:** nothing. No step here changes a product, a listing, a reservation or a quantity at a channel — they change only who does the work and how
soon. The quantities themselves are computed the same way by the same code in every one of these states.

## Watching it

The hub's **stock changes not acted on yet** section is the figure that says the workers have stopped: how many changes are waiting, how old the oldest is, how many nobody
could act on, and how many were worked out but **not sent** because a switch or an approval refused it. A few waiting for a moment is the arrangement working. Minutes of
backlog, one dead event, or a pile of `not_sent` is somebody's to look at — a dead event is a quantity at a channel that has not been corrected, and a `not_sent` one is a
quantity that was never offered to it at all.

## What was already here, and is not rebuilt

| Already built | Where |
| --- | --- |
| One source of truth per (product, variant, location) | `Inventory/PoolNet.php`, `LocationStockReader.php` |
| Atomic reservation under concurrency (a unique key and the pool's row lock) | `Inventory/ReservationService.php`, `Allocation/PoolAllocationService.php` |
| A hold taken off the source figure once, for the storefront and the channels together | `Inventory/ChannelHoldsOnProviderStock.php` |
| The division between several listings of one shelf | `Allocation/StockAllocator.php` |
| Fail-closed on a stale figure or an incomplete order import | `Allocation/OrderFreshness.php`, `StockAvailability::isPublishable()` |
| Confirming that a channel applied an update | `Publishing/StockPricePublisher.php` |

## The two things the final review asked to be sure of

**No unit is held twice.** Three separate properties, and each has a test:

1. a reservation is one row per order line with a **unique key**, so a second attempt finds the first (and the re-read after that race is a *locking* read, or inside a
   transaction it would read a snapshot taken before the winner committed);
2. a channel order that has an internal order of its own holds **nothing** besides — its reservation is released with `internal_order_counts_it`, because the internal
   order's provider lines already count those units;
3. `products.current_stock` is **recomputed, never decremented**: the provider's figure minus every promise against it. So the inline write, the worker's write and the
   drain can all run, twice over, and the number does not drift (`test_a_unit_is_never_held_twice_however_many_times_anything_runs`).

**No old figure is pushed out.** Also three:

1. an outbox row carries **no figure at all** — it names a pool and says why, and the figures are read afresh when it is worked, so a row worked late sends a *fresh*
   quantity late;
2. re-applying a stored figure **does not re-stamp its read time** — `rewriteStoreStockFromStoredFigures()` never stamps, so a stale figure never reads as current and a
   shipment the provider accepted since it is still held back. The CI caught this one, and `test_re_applying_a_stored_figure_never_makes_it_look_newly_read` pins it;
3. the **publisher's own staleness gate** still decides what reaches a channel: a figure the source has not confirmed within `stock.stale_after_minutes` is not offered, on
   a drain exactly as on a scheduled run (`test_a_figure_too_old_to_trust_is_not_pushed_to_the_channel`).

What this does *not* claim: the freshness of the provider's figure itself is the provider sync's cadence, not this work's. Re-applying it subtracts a new promise from the
last known figure — the same formula the single writer has always used — and never invents stock.

## What is still not done

* **Returns** are recorded as a reason (`units_returned`) and the units come back through the reservations, as they did. A returned unit that is *damaged* and must not go
  back on sale is still a person's decision — nothing here judges the condition of a returned item.
* **No live run.** Every test here is against fixtures, with outbound requests forbidden, on SQLite locally and on MySQL 8 and MariaDB 11.4.2 in CI. The concurrency tests
  (six real PHP processes claiming and draining at once) need a real engine and are skipped on SQLite.
* **A queue worker is the operator's to run.** Nothing here starts one, and the schedule is written so that the arrangement is correct without one.
