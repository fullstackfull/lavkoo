# The six markets became reachable, and queued work stopped losing its market

**When:** 2026-11-24, after the unified `main` was repaired and its suite went green (handoff `2026-11-24-0730`).
**Branch:** `gcc/development` (one branch, as the owner asked). **Merged into `main`:** E-13.
**Repository:** `fullstackfull/lavkoo`.

## What was done, in the order it was done

### 1. E-13 merged into `main` — a refund, a return amount, a cancellation and a delivery's points are each paid once

`main` is at [`287ae061`](https://github.com/fullstackfull/lavkoo/commit/287ae061). `RefundClaims` turns the four read-then-pay handlers into
conditional UPDATEs: the row is the claim and the lock, and the money moves only when exactly one row was claimed, inside the transaction
that pays. The four races in `RefundConcurrencyTest` are un-skipped and green. A vendor's return amount reaches the customer the **order**
names, instead of the signed-in customer a seller's request never has, and what cannot be paid is left owed rather than cleared. Refund wallet
credits name the refund they pay, so the reconciliation can see one paid twice at all.

Verified on MariaDB 11.4 before the merge: **153 tests, 1369 assertions, 0 failures**. CI on `main` at the merge commit — [run 648](https://github.com/fullstackfull/lavkoo/actions/runs/45389178742) — finished **success**, so the whole suite on every engine the workflow runs holds it.

Five of E-13's six criteria are met. The sixth is a behaviour the owner decides, not a defect: **should an admin-cancelled non-POS wallet-paid
order refund itself automatically?** Today a POS cancel refunds and a non-POS cancel does not, and making it automatic moves money on a path
that never moved it (E04-F10).

### 2. A1-14 — queued work carries its market, and cannot leak it to the next job

A queue worker is a console process: nothing binds a storefront in it. So a job queued by a Kuwaiti request was run as **no market at all**
— the platform's own currency, company and settings — and a job that did bind one would have left it bound for the job after it.

The market now travels with the work. A job records it at construction, which runs in the dispatching request, and it rides in the job's own
serialised payload, so it survives the queue, a retry and a worker restart. A queued **listener** records it in the middleware the dispatcher
asks it for, because Laravel builds a listener with `newInstanceWithoutConstructor()` and serialises only the event. `ServeInItsStorefront`
then runs `handle()` inside `StorefrontContext::serve`, which puts back whatever the worker held.

Applied to `RunSellerBulkJob` and the eight queued mail listeners whose event belongs to the market of the request that raised it. Deliberately
not applied where the raising request is somebody else's than the recipient's, nor to work that sweeps many markets at once, nor to the order
listeners, which already read the order's own record. Each exclusion has its reason written down.

Each market also got **a queue of its own** (`GCC_STOREFRONT_QUEUES`, off), so one market can be worked, paced or paused alone with Laravel's
own `queue:work --queue=storefront-kw` and `queue:pause storefront-kw` — no bespoke pause machinery, no attempt spent, nothing lost. The
default storefront keeps today's queue, so a worker that is already running never stops working the live market's jobs.
`gcc:storefront-queues` prints each market's queue, whether it is paused, what is waiting on it, and the command to work it.

**All six of A1-14's acceptance criteria are met**, including the one nobody had tested: two jobs of two countries and a console job, run one
after another in one process, see KW, AE and nothing — and nothing is left bound.

### 3. A1-05 — the website answers each market under its own path

`/kw/shop-cart` answered **404**: the web routes are mounted at the root and nothing was mounted under a country, so a market could be reached
only by a header or a `?storefront=` query — neither of which a visitor can type, share or bookmark, while the sitemap and the canonical links
already named those addresses.

Rather than mount every route a second time under six prefixes, the market's segment is taken off the request **before it is routed**. A
market's pages are therefore the platform's pages: the same controllers, the same route names, the same middleware, and a test pins that the
route table is identical with the switch on and off. While a request is served under a prefix, the paths the application generates are given
that market back, so a link or a redirect on a Kuwaiti page stays Kuwaiti. The panel, the API and the assets are never given a market.

The formatter is put back after the request, failure included — the leak that test caught before the restore was added.

Behind `GCC_STOREFRONT_PATHS` (off), and dormant while storefronts are off. **All four criteria met.**

### 4. A1-06 — a visitor can choose their market, and the site remembers it

`POST /storefront` is the only thing that writes the choice; it is a POST because it changes what the site serves this visitor. The choice goes
in the session, where their currency and language choices already live, so no new cookie is set. The answer sends them to the page they were
on **inside the market they chose**, replacing any market segment already in the address rather than stacking one on it.

The resolver reads the remembered choice below the served path, the explicit query and the app's header, and above the default. Nothing infers
a market from an IP — there is no GeoIP database and no inference anywhere — so "an IP guess never replaces a stored choice" holds by
construction. The control is a dropdown in the top bar beside the currency and language pickers, rendered only where more than one market is
served. **Five of six criteria met**; the sixth is the seller APIs honouring the seller app's market, which is A1-11.

### 5. A1-18 — two countries' copies of one product keep their stock apart

Separate figures follow from each country having its own product row, but every piece of inventory machinery is keyed on something, and a
piece keyed on the **SKU** would quietly join the two countries back together — one SKU is exactly what two copies of a product share. So the
new test's subject is the keys: a channel order and a store order of one country leave the other's stock alone, each pool nets its own
promises, a reservation and an outbox row name the product they were made for, and the shared SKU is proposed only inside the channel
account's own country.

No defect found: the machinery is keyed on the product throughout. That is the finding, and it is now pinned.

### 6. A1-02 — what the operator can already do, and one question for the owner

Two of its three open criteria were already met by I2-11's lifecycle, which the old record predates: an operator pauses and resumes one market
in three modes, in the database, per market, with who, when, why, a reference and an audit line, and **a market cannot be opened until its
readiness checks pass**. What is left is that a country the configuration does not list as `active` stays unreachable however the lifecycle
records it, so opening one that was not shipped active still needs a deploy. Wiring the two switches together was implemented and backed out,
with the reason recorded: the resolver is a container-free value object built directly by several callers, and reading a record inside it costs
a lookup per market per resolve.

**The one question for the owner is below.**

## The one thing the owner has to settle

**When the master storefront flag is off, which promise wins?**

- **Dormancy** — the platform is byte-for-byte the platform of today, nothing narrowed anywhere. This is pinned deliberately and by name:
  `StorefrontByIdReadsTest::test_while_storefronts_are_off_every_product_answers_as_before` reads a Kuwaiti product through the Saudi
  storefront's API and asserts it answers.
- **A kill switch** — the platform is the default market only: its own products are shown and another country's are not. This is what A1-02's
  third criterion asks for, and it is the switch an operator would reach for in an incident.

Both were implemented on this branch; the second was backed out, because the two cannot both hold once a second country has products. For the
Saudi installation that runs today the second changes nothing (every product of it carries `SA`, the column's own default). One thing the
attempt proved on its own, and it holds whichever way the answer goes: it must **never** be extended to the cart's in-memory gate, which
compares a column on a row the caller already loaded and would refuse a row loaded without it — stopping a Saudi installation selling its own
products.

The safe remedy needs no decision and is already in place: **switch the country off, not the flag.**

## Also waiting on the owner, from the earlier stages

- **E-14, the value of a loyalty point** (LF-1): the REST path credits 1.00 USD where the web path credits 0.2667 USD for 100 points at rate
  100 — a factor of 3.75. One of them is wrong and the two cannot be unified without deciding what a point is worth.
- **E04-F10**: automatic refund of an admin-cancelled non-POS wallet-paid order.
- **E-07's backfill on real data**: the command refuses any database whose name does not match `--expect-database` and needs
  `--allow-production` deliberately; nothing has been run on real data, and a rehearsal on a disposable copy comes first.
- **Arabic** for `store` and `this_store_is_not_available`, and the customer app's four strings.

## Nothing was deployed, and nothing touched production

No migration was run anywhere but in test databases. No production data was read or written. No market was activated. Everything above is on
`gcc/development` except E-13, which is on `main`, and both branches are pushed.
